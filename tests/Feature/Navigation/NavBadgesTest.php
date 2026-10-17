<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\UserRole;
use App\Models\BotLearningReport;
use App\Models\BotSuggestion;
use App\Models\OrderConfirmation;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Navigation\NavBadges;
use Illuminate\Support\Carbon;

// Redesign 2026-10-17 (IA §3.1.6): badges only on waiting work, red only past an SLA.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-17 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function nbSuggestions(int $n, string $status = 'pending'): void
{
    $report = BotLearningReport::firstOrCreate(['report_date' => '2026-10-16'], [
        'summary' => 'ملخص', 'stats' => [], 'model' => 'fake', 'input_tokens' => 1, 'output_tokens' => 1,
    ]);
    for ($i = 0; $i < $n; $i++) {
        BotSuggestion::create(['report_id' => $report->id, 'type' => 'new_faq', 'target' => 'x'.$i, 'proposed' => ['key' => 'x'], 'status' => $status]);
    }
}

function nbUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

it('counts confirmations to call, open cases and pending bot suggestions for a supervisor', function () {
    OrderConfirmation::factory()->count(2)->create(['status' => OrderConfirmationStatus::NoResponse]);
    OrderConfirmation::factory()->create(['status' => OrderConfirmationStatus::Confirmed]);
    SupportCase::factory()->create(['status' => 'new', 'sla_due_at' => now()->addHour()]);
    SupportCase::factory()->create(['status' => 'in_progress', 'sla_due_at' => now()->addHour()]);
    SupportCase::factory()->create(['status' => 'resolved']);
    nbSuggestions(3);
    nbSuggestions(1, 'approved');

    $b = app(NavBadges::class)->compute(nbUser(UserRole::Supervisor));

    expect($b)->toMatchArray(['confirmations' => 2, 'cases' => 2, 'cases_urgent' => false, 'learning' => 3]);
});

it('turns the cases badge red once an open case is past its SLA', function () {
    SupportCase::factory()->create(['status' => 'new', 'sla_due_at' => now()->subMinute()]);

    expect(app(NavBadges::class)->compute(nbUser(UserRole::Admin)))->toMatchArray(['cases' => 1, 'cases_urgent' => true]);
});

it('counts the room for those who see the board, red when a window is overdue', function () {
    $e = QueueEntry::factory()->create();
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true]);

    $b = app(NavBadges::class)->compute(nbUser(UserRole::Admin));

    expect($b['board'])->toBe(1)
        ->and($b['board_urgent'])->toBeFalse()
        ->and(app(NavBadges::class)->compute(nbUser(UserRole::Moderator)))->not->toHaveKey('board');
});

it('keeps the bot learning count from moderators', function () {
    nbSuggestions(1);

    expect(app(NavBadges::class)->compute(nbUser(UserRole::Moderator)))->not->toHaveKey('learning');
});

it('gives the ads roles nothing (their counts come from the Ads Hub)', function () {
    expect(app(NavBadges::class)->compute(nbUser(UserRole::MediaBuyer)))->toBe([])
        ->and(app(NavBadges::class)->compute(nbUser(UserRole::Content)))->toBe([]);
});

it('is shared with every page as navBadges', function () {
    OrderConfirmation::factory()->create(['status' => OrderConfirmationStatus::NoResponse]);

    $this->actingAs(nbUser(UserRole::Moderator))->get('/inbox')
        ->assertInertia(fn ($page) => $page->where('navBadges.confirmations', 1));
});
