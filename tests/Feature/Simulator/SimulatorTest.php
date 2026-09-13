<?php

use App\Simulator\Simulator;
use App\Enums\{Platform, Handler};
use App\Models\{ChannelAccount, BotRule, BotSetting, Conversation, Comment};
use Illuminate\Support\Facades\Event;

it('drives a message and a comment through the real pipeline', function () {
    Event::fake();
    foreach (Platform::cases() as $p) {
        ChannelAccount::factory()->create(['platform' => $p, 'external_id' => 'demo-'.$p->value, 'driver' => 'fake']);
    }
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    BotRule::factory()->create(['keywords' => ['بكام'], 'private_reply' => 'السعر في الكتالوج', 'public_replies' => ['ردينا في الخاص'], 'scope' => 'both', 'platforms' => [], 'action' => 'reply', 'is_active' => true]);

    $sim = app(Simulator::class);
    $m = $sim->customerMessage(Platform::WhatsApp, 'cust-9', 'Hala', 'بكام الشنطة؟');
    expect($m->conversation->messages()->where('sender_type', 'bot')->count())->toBe(1);

    $c = $sim->comment(Platform::Facebook, 'post-1', 'cust-10', 'Omar', 'بكام؟', true);
    expect($c->fresh()->conversation_id)->not->toBeNull();

    expect($sim->burst(20, 0, ['facebook', 'instagram']))->toBe(20);
});

it('seeds demo data', function () {
    $this->seed(Database\Seeders\DemoSeeder::class);

    expect(Conversation::count())->toBeGreaterThan(300)
        ->and(Comment::count())->toBeGreaterThan(500)
        ->and(\App\Models\Order::count())->toBeGreaterThan(50)
        ->and(\App\Models\ActivityLog::count())->toBeGreaterThan(1000);
})->group('slow');
