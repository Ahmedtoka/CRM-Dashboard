<?php

use App\Enums\{Platform, UserRole};
use App\Models\{QuickReply, QuickReplyCategory, QuickReplyUsage, User};

beforeEach(function () {
    $this->travelTo(now()->setTimezone('Africa/Cairo')->setTime(12, 0)->utc());
    $this->sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->a = User::factory()->create(['name' => 'Aya']);
    $this->b = User::factory()->create(['name' => 'Bassant']);
    $this->ship = QuickReply::factory()->create(['shortcut' => 'ship', 'title' => 'الشحن']);
    $this->size = QuickReply::factory()->create(['shortcut' => 'size', 'title' => 'المقاسات']);
    $this->idle = QuickReply::factory()->create(['shortcut' => 'idle', 'last_used_at' => now()->subDays(40)]);
    QuickReplyUsage::factory()->create(['quick_reply_id' => $this->ship->id, 'user_id' => $this->a->id, 'platform' => Platform::WhatsApp, 'used_at' => now()]);
    QuickReplyUsage::factory()->create(['quick_reply_id' => $this->ship->id, 'user_id' => $this->b->id, 'platform' => Platform::Facebook, 'used_at' => now()]);
    QuickReplyUsage::factory()->create(['quick_reply_id' => $this->size->id, 'user_id' => $this->a->id, 'platform' => Platform::WhatsApp, 'used_at' => now()]);
    QuickReplyUsage::factory()->create(['quick_reply_id' => $this->idle->id, 'user_id' => $this->a->id, 'platform' => Platform::WhatsApp, 'used_at' => now()->subDays(40)]);
});

it('ranks replies, groups by agent and lists unused replies', function () {
    $this->actingAs($this->sup)->get('/reports/quick-replies')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/QuickReplies')
            ->where('top.0.shortcut', 'ship')->where('top.0.uses', 2)->where('top.0.users', 2)
            ->where('top.0.platforms', ['facebook' => 1, 'whatsapp' => 1])
            ->where('perAgent.0.user.name', 'Aya')->where('perAgent.0.uses', 2)->where('perAgent.0.replies', 2)
            ->where('unused', fn ($rows) => collect($rows)->pluck('shortcut')->all() === ['idle']));
});

it('filters by platform and exports csv', function () {
    $this->actingAs($this->sup)->get('/reports/quick-replies?platform=facebook')
        ->assertInertia(fn ($page) => $page->has('top', 1)->where('top.0.uses', 1));

    $raw = $this->actingAs($this->sup)->get('/reports/quick-replies/export')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    expect(str_starts_with($raw, "\u{FEFF}"))->toBeTrue();

    $csv = ltrim($raw, "\u{FEFF}");
    $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    expect($lines[0])->toBe(['shortcut', 'title', 'scope', 'category', 'uses', 'users', 'platforms', 'last_used_at'])
        ->and($lines[1][0])->toBe('ship')->and($lines[1][6])->toBe('facebook:1|whatsapp:1');
});

it('guards csv cells against formula injection and round-trips special characters', function () {
    $category = QuickReplyCategory::factory()->create(['name' => '-drop']);
    $injected = QuickReply::factory()->create(['shortcut' => 'inj', 'title' => '=SUM(A1)', 'category_id' => $category->id]);
    $special = QuickReply::factory()->create(['shortcut' => 'csv', 'title' => 'Hello, "World"']);
    QuickReplyUsage::factory()->create(['quick_reply_id' => $injected->id, 'user_id' => $this->a->id, 'platform' => Platform::WhatsApp, 'used_at' => now()]);
    QuickReplyUsage::factory()->create(['quick_reply_id' => $special->id, 'user_id' => $this->a->id, 'platform' => Platform::WhatsApp, 'used_at' => now()]);

    $raw = $this->actingAs($this->sup)->get('/reports/quick-replies/export')->assertOk()->streamedContent();
    $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim($raw, "\u{FEFF}"))));
    $rows = collect($lines)->skip(1)->mapWithKeys(fn ($row) => [$row[0] => $row]);

    expect($rows['inj'][1])->toBe("'=SUM(A1)")
        ->and($rows['inj'][3])->toBe("'-drop")
        ->and($rows['csv'][1])->toBe('Hello, "World"');
});

it('includes personal replies in the report, labelled by their owner scope', function () {
    $personalUsed = QuickReply::factory()->personal($this->a)->create(['shortcut' => 'mine']);
    $personalUnused = QuickReply::factory()->personal($this->a)->create(['shortcut' => 'mine-idle', 'last_used_at' => now()->subDays(40)]);
    QuickReplyUsage::factory()->create(['quick_reply_id' => $personalUsed->id, 'user_id' => $this->a->id, 'platform' => Platform::WhatsApp, 'used_at' => now()]);

    $this->actingAs($this->sup)->get('/reports/quick-replies')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('top', fn ($rows) => collect($rows)->firstWhere('shortcut', 'mine')['scope'] === 'personal')
            ->where('unused', fn ($rows) => collect($rows)->firstWhere('shortcut', 'mine-idle')['scope'] === 'personal'));
});

it('is supervisor only', function () {
    $this->actingAs($this->a)->get('/reports/quick-replies')->assertForbidden();
    $this->actingAs($this->a)->get('/reports/quick-replies/export')->assertForbidden();
});
