<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\ConversationQuery;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/*
 * Task 4b carried fix A: the MariaDB search (name prefix, FULLTEXT word prefix, phone prefix/suffix)
 * misses a substring inside a word and middle phone digits. When it finds nothing on the first
 * page, ConversationQuery re-runs the plain %term% LIKE once. sqlite has no indexed path (it is
 * LIKE already), so these tests stand in for MariaDB with a subclass whose "indexed" search always
 * misses: that proves the fallback is taken, once, on the first page only.
 */
function indexedMissQuery(): ConversationQuery
{
    return new class extends ConversationQuery
    {
        public int $indexedRuns = 0;

        protected function usesIndexedSearch(): bool
        {
            return true;
        }

        protected function searchIndexed(Builder $q, string $term): void
        {
            $this->indexedRuns++;
            $q->whereRaw('1 = 0'); // the prefix / FULLTEXT / phone-range lookup found nothing
        }
    };
}

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $this->make = fn (array $c, $at = null) => Conversation::factory()->for($account, 'channelAccount')
        ->create(['customer_id' => Customer::factory()->create($c)->id, 'last_message_at' => $at]);
});

it('falls back to a substring LIKE when the indexed name search finds nothing on the first page', function () {
    $hit = ($this->make)(['name' => 'عبدالله محمود', 'phone' => null]);
    ($this->make)(['name' => 'منى سمير', 'phone' => null]);

    $q = indexedMissQuery();
    DB::enableQueryLog();
    $page = $q->paginate($this->admin, ['q' => 'الله']);
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect($page->getCollection()->pluck('id')->all())->toBe([$hit->id])
        ->and($q->indexedRuns)->toBe(1)                       // the indexed path ran first...
        // ...then the LIKE ran once (sqlite quotes "name", MariaDB `name`: match either).
        ->and(preg_match_all('/\(["`]name["`] like \? or ["`]phone["`] like \?\)/', $sql))->toBe(1);
});

it('finds middle phone digits through the fallback', function () {
    $hit = ($this->make)(['name' => 'Sara', 'phone' => '201012345678']);
    ($this->make)(['name' => 'Omar', 'phone' => '201099999999']);

    $page = indexedMissQuery()->paginate($this->admin, ['q' => '1234']);

    expect($page->getCollection()->pluck('id')->all())->toBe([$hit->id]);
});

it('does not run the fallback when the indexed search found rows', function () {
    $q = new class extends ConversationQuery
    {
        protected function usesIndexedSearch(): bool
        {
            return true;
        }

        protected function searchIndexed(Builder $q, string $term): void
        {
            $q->whereHas('customer', fn (Builder $c) => $c->where('name', 'like', $term.'%'));
        }
    };
    $hit = ($this->make)(['name' => 'محمد علي', 'phone' => null]);
    ($this->make)(['name' => 'عبدالله محمد', 'phone' => null]); // a substring-only match: must NOT be pulled in

    expect($q->paginate($this->admin, ['q' => 'محمد'])->getCollection()->pluck('id')->all())->toBe([$hit->id]);
});

it('pages through every substring-only hit: page 1 reports search_mode=like and later pages keep the LIKE with qmode=like', function () {
    $expected = [];
    for ($i = 0; $i < 70; $i++) {
        $expected[] = ($this->make)(['name' => "عبدالله {$i}", 'phone' => null], now()->subMinutes($i))->id;
    }
    // The indexed path (a name prefix) can never match "الله": stand in for MariaDB.
    app()->bind(ConversationQuery::class, fn () => indexedMissQuery());

    $url = '/inbox/conversations?q='.urlencode('الله');
    $seen = [];
    $next = $url;
    $pages = 0;
    do {
        $res = $this->actingAs($this->admin)->getJson($next)->assertOk();
        $res->assertJsonPath('search_mode', 'like');
        $seen = array_merge($seen, $res->json('data.*.id'));
        $cursor = $res->json('meta.next_cursor');
        $next = $url.'&qmode=like&cursor='.$cursor;
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)
        ->and(count($seen))->toBe(70)
        ->and(count(array_unique($seen)))->toBe(70)
        ->and(array_diff($expected, $seen))->toBe([]);

    // Without qmode a later page takes the indexed path again and finds nothing (why the client sends it).
    $first = $this->actingAs($this->admin)->getJson($url)->json('meta.next_cursor');
    $this->actingAs($this->admin)->getJson($url.'&cursor='.$first)->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->admin)->getJson($url.'&qmode=bogus')->assertUnprocessable();
});

it('keeps status and queue counts in the same LIKE window', function () {
    QueueSetting::current()->update(['enabled' => true]);
    $conv = ($this->make)(['name' => 'عبدالله محمود', 'phone' => null]);
    $entry = QueueEntry::factory()->create(['conversation_id' => $conv->id, 'status' => 'waiting']);
    $conv->forceFill(['queue_entry_id' => $entry->id])->save();

    $counts = indexedMissQuery()->counts($this->admin, ['q' => 'الله']);

    expect($counts['status']['open'])->toBe(1)->and($counts['queue']['waiting'])->toBe(1);
});

it('lists substring matches through the real endpoint on every driver', function () {
    $hit = ($this->make)(['name' => 'عبدالله محمود', 'phone' => '201012345678']);
    ($this->make)(['name' => 'منى سمير', 'phone' => '201099999999']);

    $this->actingAs($this->admin)->getJson('/inbox/conversations?q='.urlencode('الله'))->assertOk()->assertJsonPath('data.0.id', $hit->id)->assertJsonCount(1, 'data');
    $this->actingAs($this->admin)->getJson('/inbox/conversations?q=1234')->assertOk()->assertJsonCount(1, 'data');
});

it('counts substring matches too when the indexed search finds nothing', function () {
    ($this->make)(['name' => 'عبدالله محمود', 'phone' => null]);

    $counts = indexedMissQuery()->counts($this->admin, ['q' => 'الله']);

    expect($counts['status']['open'])->toBe(1);
});

it('resolves the substring match on customers first, not as a per-conversation EXISTS', function () {
    $hit = ($this->make)(['name' => 'عبدالله محمود', 'phone' => null]);

    DB::enableQueryLog();
    $page = indexedMissQuery()->paginate($this->admin, ['q' => 'الله']);
    $sql = collect(DB::getQueryLog())->pluck('query');

    expect($page->getCollection()->pluck('id')->all())->toBe([$hit->id])
        ->and($sql->filter(fn (string $s) => preg_match('/exists\s*\(select \* from ["`]customers["`]/i', $s) === 1)->all())->toBe([])
        ->and($sql->filter(fn (string $s) => preg_match('/^select ["`]id["`] from ["`]customers["`]/', $s) === 1)->count())->toBe(1);
});

it('flags a too-broad substring search and keeps only the most recently contacted customers', function () {
    $cap = ConversationQuery::LIKE_CUSTOMER_CAP;
    $account = ChannelAccount::first();
    // cap + 1 matching customers: the oldest contact falls outside the window.
    $customers = Customer::factory()->count($cap + 1)->sequence(fn ($s) => [
        'name' => 'عبدالله '.$s->index, 'phone' => null, 'last_contact_at' => now()->subMinutes($s->index),
    ])->create();
    foreach ($customers as $c) {
        Conversation::factory()->for($account, 'channelAccount')->create(['customer_id' => $c->id, 'last_message_at' => now()]);
    }
    $oldest = $customers->last();

    $q = indexedMissQuery();
    $q->paginate($this->admin, ['q' => 'الله']);
    expect($q->searchTruncated())->toBeTrue();
    $counts = $q->counts($this->admin, ['q' => 'الله']);
    expect($counts['status']['open'])->toBe($cap);

    app()->bind(ConversationQuery::class, fn () => indexedMissQuery());
    $res = $this->actingAs($this->admin)->getJson('/inbox/conversations?q='.urlencode('الله'))->assertOk()
        ->assertJsonPath('meta.search_truncated', true)->assertJsonPath('search_mode', 'like');
    expect($res->json('data.*.customer.id'))->not->toContain($oldest->id);

    // A narrow search is not flagged.
    $this->actingAs($this->admin)->getJson('/inbox/conversations?q='.urlencode('عبدالله 12'))->assertOk()
        ->assertJsonPath('meta.search_truncated', false);
});
