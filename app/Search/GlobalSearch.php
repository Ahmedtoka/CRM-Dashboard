<?php

namespace App\Search;

use App\Bot\ArabicNormalizer;
use App\Enums\SenderType;
use App\Http\Support\ModeratorScope;
use App\Inbox\ConversationQuery;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Shopify\Customers\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Global search across customers, orders, conversations (message text) and
 * products (spec §5.3, Dashboard Experience Task 13). Every group reuses the
 * codebase's existing scoping authorities (`ModeratorScope`,
 * `ConversationQuery::visibleTo`) rather than re-implementing platform
 * conditions inline, and is capped at `LIMIT` results.
 *
 * Fix round 1: a purely-numeric 1-character query is a special case (an
 * exact `orders.id` lookup only, everything else empty — see `search()`);
 * every other LIKE is escaped and Arabic-normalised (see `escapeLike()` /
 * `arabicVariants()`); message-body search uses MariaDB FULLTEXT BOOLEAN
 * MODE where possible, LIKE only as a fallback (SQLite / short terms).
 */
final class GlobalSearch
{
    public const TYPES = ['customers', 'orders', 'conversations', 'products'];

    public const LIMIT = 8;

    /**
     * Arabic letter-form equivalence classes used to build search-query
     * variants (fix round 1, ruling 4): alif forms, ha/ta-marbuta, ya/alif-maksura.
     * Not a substitute for a normalised column — see the report's limitation note.
     */
    private const AR_CLASSES = [['ا', 'أ', 'إ', 'آ'], ['ه', 'ة'], ['ي', 'ى']];

    private const MAX_VARIANTS = 8;

    /**
     * @param  list<string>  $types
     * @return array{customers: list<array>, orders: list<array>, conversations: list<array>, products: list<array>}
     */
    public function search(User $user, string $q, array $types = self::TYPES): array
    {
        $q = trim($q);
        $out = array_fill_keys(self::TYPES, []);
        $requested = array_intersect(self::TYPES, $types);

        // A single digit is below the normal 2-character minimum. It's still
        // allowed through (controller validation), but only for an exact,
        // indexed `orders.id` lookup — never a wildcard scan of any kind.
        if (mb_strlen($q) === 1 && ctype_digit($q)) {
            if (in_array('orders', $requested, true)) {
                $out['orders'] = $this->ordersById((int) $q, $user);
            }

            return $out;
        }

        foreach ($requested as $type) {
            $out[$type] = $this->{$type}($user, $q);
        }

        return $out;
    }

    private function customers(User $user, string $q): array
    {
        $digits = preg_replace('/\D/', '', app(ArabicNormalizer::class)->digitsToLatin($q)) ?? '';
        $e164 = $this->phoneE164($digits);

        $query = Customer::query()->where(function (Builder $w) use ($q, $digits, $e164) {
            foreach ($this->arabicVariants($q) as $variant) {
                $w->orWhereRaw($this->likeSql('name'), ['%'.$this->escapeLike($variant).'%']);
            }
            if ($e164 !== null) {
                $w->orWhere('normalized_phone', $e164);
            } elseif (strlen($digits) >= 5) {
                $w->orWhereRaw($this->likeSql('normalized_phone'), ['%'.$this->escapeLike($digits).'%']);
            }
        });

        return ModeratorScope::customers($query, $user)
            ->orderByDesc('last_contact_at')->limit(self::LIMIT)->get(['id', 'name', 'phone'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'title' => $c->name ?: '—', 'subtitle' => $c->phone, 'href' => "/customers/{$c->id}"])->all();
    }

    /**
     * E.164 for a digit-only phone query, or null when it's not recognisable.
     * A bare 10-digit number starting with "1" (no leading "0", no country
     * code — e.g. a moderator pasting the number without its trunk zero) is
     * treated the same as the local "01…" format (fix round 1, ruling 5).
     * `$digits` has already been through `ArabicNormalizer::digitsToLatin()`
     * by the caller, so Arabic-Indic input works the same as Latin digits.
     */
    private function phoneE164(string $digits): ?string
    {
        if (strlen($digits) === 10 && $digits[0] === '1') {
            return '+20'.$digits;
        }

        return strlen($digits) >= 10 ? PhoneNormalizer::toE164($digits) : null;
    }

    private function orders(User $user, string $q): array
    {
        $ids = $this->orderIds($q);

        if ($ids->isEmpty()) {
            return [];
        }

        return ModeratorScope::orders(Order::query()->with('customer:id,name'), $user)
            ->whereIn('id', $ids)->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (Order $o) => $this->orderItem($o))->all();
    }

    private function ordersById(int $id, User $user): array
    {
        return ModeratorScope::orders(Order::query()->with('customer:id,name'), $user)
            ->where('id', $id)->limit(self::LIMIT)->get()
            ->map(fn (Order $o) => $this->orderItem($o))->all();
    }

    /**
     * Candidate order ids from five separate, individually-indexed exact
     * lookups combined with UNION ALL (fix round 1, ruling 6) rather than one
     * OR'd query spanning differently-indexed columns, which most engines
     * can't use more than one index for at a time.
     *
     * @return Collection<int, int>
     */
    private function orderIds(string $q): Collection
    {
        $name = str_starts_with($q, '#') ? $q : '#'.$q;

        $union = DB::table('orders')->select('id')->where('shopify_order_name', $name);
        $union->unionAll(DB::table('orders')->select('id')->where('order_number', $q));
        if (ctype_digit($q)) {
            $union->unionAll(DB::table('orders')->select('id')->where('id', (int) $q));
        }
        $union->unionAll(DB::table('shipments')->select('order_id as id')->where('tracking_number', $q));
        $union->unionAll(DB::table('fulfillments')->select('order_id as id')->where('tracking_number', $q));

        return $union->pluck('id')->unique()->values();
    }

    private function orderItem(Order $o): array
    {
        return [
            'id' => $o->id,
            'title' => $o->shopify_order_name ?: ($o->order_number ?: '#'.$o->id),
            'subtitle' => trim(($o->customer?->name ?? '').' · '.number_format((float) $o->total, 2).' EGP', ' ·'),
            'status' => $o->status->value,
            'href' => "/orders/{$o->id}",
        ];
    }

    private function conversations(User $user, string $q): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/u', $q) ?: [], fn ($t) => mb_strlen($t) > 0));
        // Verified empirically against this app's own MariaDB 10.4/InnoDB/utf8mb4_unicode_ci
        // stack (see the report's "critical finding" for the exact repro): the built-in
        // FULLTEXT parser's word-boundary detection does not treat Arabic script as word
        // characters at all — an Arabic-only message body indexes zero tokens, so BOOLEAN
        // MODE against it always returns nothing, silently. Until that's fixed server-side
        // (e.g. an ngram-capable parser, not available in this environment either — see the
        // report), an Arabic-containing query keeps using the LIKE+variant path below, which
        // is the only one proven to actually find Arabic content; a Latin/numeric query (an
        // order number embedded in a message, a brand name, …) still uses real FULLTEXT.
        $hasArabicScript = (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $q);
        $canFulltext = ! $hasArabicScript && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && $terms !== [] && collect($terms)->every(fn ($t) => mb_strlen($t) >= 3);

        // Scan bound (final fix wave I3): the Arabic LIKE path can't use an index on
        // body, so it gets a shorter window, and both paths carry a `messages.id >=`
        // lower bound resolved once via the created_at index — the backward id walk
        // behind `orderByDesc('messages.id')` then stops at the window edge instead
        // of scanning the whole table when a term is rare or has no match at all.
        $days = $hasArabicScript ? (int) config('crm.search.arabic_window_days', 90) : (int) config('crm.search.window_days', 180);
        $minId = $this->minMessageId($days);

        if ($minId === null) {
            return [];
        }

        // `ConversationQuery::visibleTo()` is the single authority for which
        // conversations this user may see; used as an IN-subquery so it's
        // never re-implemented inline here (fix round 1, ruling 3).
        $visibleConversationIds = ConversationQuery::visibleTo($user)->select('conversations.id');

        $query = Message::query()->select(['messages.id', 'messages.conversation_id', 'messages.body', 'messages.created_at'])
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('messages.id', '>=', $minId)
            ->where('messages.created_at', '>=', now()->subDays($days))
            // Never surface system messages ("bot handed over", "order created", …) as a search hit.
            ->where('messages.sender_type', '!=', SenderType::System->value)
            ->whereIn('messages.conversation_id', $visibleConversationIds);

        if ($canFulltext) {
            $query->whereRaw('MATCH(messages.body) AGAINST (? IN BOOLEAN MODE)', [$this->booleanQuery($terms)]);
        } else {
            // SQLite (tests), terms shorter than the FULLTEXT minimum token size, or an
            // Arabic-containing query on MariaDB (see the note above `$canFulltext`).
            $query->where(function (Builder $w) use ($q) {
                foreach ($this->arabicVariants($q) as $variant) {
                    $w->orWhereRaw($this->likeSql('messages.body'), ['%'.$this->escapeLike($variant).'%']);
                }
            });
        }

        $matches = $query->orderByDesc('messages.id')->limit(60)->get()->unique('conversation_id')->take(self::LIMIT);

        $conversations = Conversation::with('customer:id,name')->whereIn('id', $matches->pluck('conversation_id'))->get()->keyBy('id');

        return $matches->filter(fn (Message $m) => $conversations->has($m->conversation_id))->map(fn (Message $m) => [
            'id' => $m->conversation_id,
            'title' => $conversations[$m->conversation_id]->customer?->name ?: '#'.$m->conversation_id,
            'subtitle' => $this->snippetFor((string) $m->body, $terms, $q),
            'platform' => $conversations[$m->conversation_id]->platform->value,
            'at' => $m->created_at?->toIso8601String(),
            'href' => "/inbox?c={$m->conversation_id}",
        ])->values()->all();
    }

    /**
     * Smallest `messages.id` created within the last `$days` days (null when the
     * window holds no messages), resolved through the `created_at` index and
     * cached for 5 minutes per window. Ids only grow, so a stale value is an
     * older (lower) bound: it can only make the scan looser, never hide a result.
     * A null (empty window) is not cached by `remember()`, so it's re-checked.
     */
    private function minMessageId(int $days): ?int
    {
        $min = Cache::remember("search.min_msg_id.{$days}", 300,
            fn () => DB::table('messages')->where('created_at', '>=', now()->subDays($days))->min('id'));

        return $min === null ? null : (int) $min;
    }

    /**
     * A MariaDB BOOLEAN MODE query string: each search term becomes a
     * required (`+`) group of its Arabic-normalised variants, each suffixed
     * with `*` for a prefix match (e.g. `+فستان*`, or `+(فستان* استان*)` when
     * a variant exists) — variant count is capped at `MAX_VARIANTS` total
     * across every term, same as the LIKE-path cap (fix round 1, ruling 4).
     * A leading "ال" (the definite article) is stripped per term first so
     * "الفستان" also matches a body containing bare "فستان".
     *
     * @param  list<string>  $terms
     */
    private function booleanQuery(array $terms): string
    {
        $budget = self::MAX_VARIANTS;
        $groups = [];

        foreach ($terms as $term) {
            if ($budget <= 0) {
                break;
            }

            // Boolean-mode operators are meaningless (and unsafe to leave) inside a user-supplied term.
            $clean = preg_replace('/[+\-><()~*"@]/u', '', $term) ?? $term;
            $stripped = mb_substr($clean, 0, 2) === 'ال' ? mb_substr($clean, 2) : $clean;

            if ($stripped === '') {
                continue;
            }

            $variants = array_slice($this->arabicVariants($stripped), 0, max(1, $budget));
            $budget -= count($variants);

            $tokens = array_map(fn ($v) => $v.'*', $variants);
            $groups[] = count($tokens) > 1 ? '+('.implode(' ', $tokens).')' : '+'.$tokens[0];
        }

        return implode(' ', $groups);
    }

    /**
     * A plain-text snippet around the first term that yields one (falling
     * back to a plain limit) — radius 60 either side, ~120 characters total
     * (fix round 1, ruling minor-b).
     *
     * @param  list<string>  $terms
     */
    private function snippetFor(string $body, array $terms, string $q): string
    {
        foreach (($terms !== [] ? $terms : [$q]) as $term) {
            $excerpt = Str::excerpt($body, $term, ['radius' => 60]);
            if ($excerpt !== null) {
                return $excerpt;
            }
        }

        return Str::limit($body, 120);
    }

    private function products(User $user, string $q): array
    {
        return Product::query()->with(['variants' => fn ($v) => $v->orderBy('price')])
            ->where(function (Builder $w) use ($q) {
                foreach ($this->arabicVariants($q) as $variant) {
                    $w->orWhereRaw($this->likeSql('title'), ['%'.$this->escapeLike($variant).'%']);
                }
                $w->orWhereHas('variants', fn (Builder $v) => $v->whereRaw($this->likeSql('sku'), [$this->escapeLike($q).'%']));
            })
            ->limit(self::LIMIT)->get()
            ->map(fn (Product $p) => ['id' => $p->id, 'title' => $p->title,
                'subtitle' => trim(($p->variants->first() ? rtrim(rtrim(number_format((float) $p->variants->first()->price, 2, '.', ''), '0'), '.').' جنيه' : '').' · '.($p->variants->first()?->sku ?? ''), ' ·'),
                'href' => null])->all();
    }

    /**
     * Arabic letter-form variants of `$q` (fix round 1, ruling 4): every
     * combination of the alif/ha/ya equivalence classes actually present in
     * the string, capped at `MAX_VARIANTS` (including the original). A
     * bounded, table-side workaround — not a substitute for a normalised
     * search column; see the report's limitation note.
     *
     * @return list<string>
     */
    private function arabicVariants(string $q): array
    {
        $variants = [$q];

        foreach (self::AR_CLASSES as $letters) {
            $next = [];
            foreach ($variants as $v) {
                foreach ($letters as $letter) {
                    $next[] = str_replace($letters, $letter, $v);
                }
            }
            $variants = array_values(array_unique($next));
        }

        if (! in_array($q, $variants, true)) {
            array_unshift($variants, $q);
        }

        return array_slice(array_unique($variants), 0, self::MAX_VARIANTS);
    }

    /**
     * A `LIKE` fragment with an explicit `ESCAPE` clause on SQLite, which
     * (unlike MariaDB, whose default LIKE escape character is already `\`)
     * does not treat `\` as an escape character unless told to (fix round 1,
     * ruling 2). Pair with `escapeLike()` on the bound value.
     */
    private function likeSql(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "{$column} LIKE ? ESCAPE '\\'" : "{$column} LIKE ?";
    }

    /** Escapes `%`, `_` and `\` in user input before it's wrapped in `%...%`/`...%` for a LIKE. */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
