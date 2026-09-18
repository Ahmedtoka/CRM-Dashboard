<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\Flow\DetailsCollector;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The bot's intent catalog (human bot flow spec §2.3/§5, Task 5): how each
 * intent routes, its priority and queue, the `script.*` knowledge entries it
 * answers with, the details it collects first and its offline keywords.
 * Script text itself is edited on the knowledge page; labels and keys are
 * seeded and never edited here.
 */
class BotIntentController extends Controller
{
    use RespondsWithData;

    public const ROUTES = ['answer', 'lookup', 'collect_then_handover', 'handover'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    public const QUEUES = ['agents', 'senior'];

    private const SCRIPT_PREFIX = 'script.';

    public function index(): Response
    {
        return Inertia::render('settings/BotIntents', [
            'intents' => BotIntent::query()->orderBy('sort')->orderBy('id')->get(),
            'scripts' => BotKnowledgeEntry::query()
                ->where('key', 'like', self::SCRIPT_PREFIX.'%')
                ->orderBy('sort')->orderBy('id')
                ->get(['id', 'key', 'title', 'is_active']),
            'detailTokens' => DetailsCollector::KNOWN_TOKENS,
            'flows' => BotFlow::query()->orderBy('id')->get(['key', 'title_ar', 'is_active']),
        ]);
    }

    public function update(Request $request, BotIntent $intent): HttpResponse
    {
        $data = $request->validate([
            'route' => ['sometimes', 'required', Rule::in(self::ROUTES)],
            'flow_key' => ['sometimes', 'nullable', 'string', Rule::exists('bot_flows', 'key')],
            'priority' => ['sometimes', 'required', Rule::in(self::PRIORITIES)],
            'queue' => ['sometimes', 'nullable', Rule::in(self::QUEUES)],
            'script_keys' => ['sometimes', 'array'],
            'script_keys.*' => ['string', 'distinct', Rule::in($this->scriptSuffixes())],
            'required_details' => ['sometimes', 'array'],
            'required_details.*' => ['bail', 'string', 'distinct', 'max:100', $this->detailTokenRule()],
            'keywords' => ['sometimes', 'array'],
            'keywords.*' => ['string', 'distinct', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        foreach (['script_keys', 'required_details', 'keywords'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_filter(array_map('trim', $data[$list]), fn (string $v) => $v !== ''));
            }
        }

        $intent->update($data);

        return $this->done($request, $intent->fresh());
    }

    /** @return list<string> existing `script.*` keys without the prefix, as stored in bot_intents.script_keys */
    private function scriptSuffixes(): array
    {
        return BotKnowledgeEntry::query()
            ->where('key', 'like', self::SCRIPT_PREFIX.'%')
            ->pluck('key')
            ->map(fn (string $key) => substr($key, strlen(self::SCRIPT_PREFIX)))
            ->all();
    }

    /** A token is "a" or "a|b|c" of known tokens, optionally suffixed "?" (optional detail), as DetailsCollector reads it. */
    private function detailTokenRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            // The 'string' rule reports non-strings; never cast an array here (500).
            if (! is_string($value)) {
                return;
            }

            $token = rtrim(trim($value), '?');
            $alternatives = array_map('trim', explode('|', $token));

            foreach ($alternatives as $alt) {
                if (! in_array($alt, DetailsCollector::KNOWN_TOKENS, true)) {
                    $fail(__('validation.in', ['attribute' => $attribute]));

                    return;
                }
            }
        };
    }
}
