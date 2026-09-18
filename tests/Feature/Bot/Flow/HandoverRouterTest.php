<?php

use App\Bot\Flow\HandoverRouter;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserNotification;

it('sets priority, queue and category and notifies only seniors for senior queue', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->create(['platform' => Platform::Facebook, 'handler' => Handler::Bot]);

    app(HandoverRouter::class)->route($c, ['priority' => 'high', 'queue' => 'senior', 'category' => 'store_complaint', 'reason' => 'intent'], 'الفرع اتعامل وحش');

    $c->refresh();
    expect($c->priority_level)->toBe('high')->and($c->queue)->toBe('senior')->and($c->handover_category)->toBe('store_complaint')->and($c->handler)->toBe(Handler::Human);
    expect(UserNotification::where('user_id', $sup->id)->where('type', 'conversation.handover_urgent')->exists())->toBeTrue()
        ->and(UserNotification::where('user_id', $mod->id)->exists())->toBeFalse();
});

it('notifies every active user with the platform for the agents queue at medium priority', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')->create(['handler' => Handler::Bot]);

    app(HandoverRouter::class)->route($c, ['priority' => 'medium', 'queue' => 'agents', 'category' => 'cancel_order', 'reason' => 'intent'], 'عايزة الغي الاوردر');

    $c->refresh();
    expect($c->priority_level)->toBe('medium')->and($c->queue)->toBe('agents')->and($c->handover_category)->toBe('cancel_order');
    expect(UserNotification::where('user_id', $mod->id)->where('type', 'conversation.handover')->exists())->toBeTrue()
        ->and(UserNotification::where('user_id', $sup->id)->where('type', 'conversation.handover')->exists())->toBeTrue();
});

it('does not notify an inactive supervisor for a senior queue handover', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => false]);
    $c = Conversation::factory()->create(['platform' => Platform::Facebook, 'handler' => Handler::Bot]);

    app(HandoverRouter::class)->route($c, ['priority' => 'high', 'queue' => 'senior', 'category' => 'store_complaint', 'reason' => 'intent'], 'شكوى فرع');

    expect(UserNotification::where('user_id', $sup->id)->exists())->toBeFalse();
});

it('puts the summary extra lines, category label and priority in the handover note', function () {
    $c = Conversation::factory()->create(['platform' => Platform::Facebook, 'handler' => Handler::Bot]);

    app(HandoverRouter::class)->route(
        $c,
        ['priority' => 'medium', 'queue' => 'agents', 'category' => 'cancel_order', 'reason' => 'intent'],
        'عايزة الغي الاوردر',
        ['البيانات المجمعة: رقم الأوردر: 7777', 'باقي على مهلة الإلغاء/التعديل: 90 دقيقة'],
    );

    $body = (string) $c->notes()->latest('id')->value('body');
    expect($body)->toContain('رقم الأوردر: 7777')
        ->and($body)->toContain('باقي على مهلة الإلغاء/التعديل: 90 دقيقة')
        ->and($body)->toContain('عايزة الغي الاوردر')
        ->and($body)->toContain('متوسطة');
});

it('the note never carries a stale customer text once moved to the actual burst text', function () {
    $c = Conversation::factory()->create(['platform' => Platform::Facebook, 'handler' => Handler::Bot]);

    app(HandoverRouter::class)->route($c, ['priority' => 'low', 'queue' => 'agents', 'category' => 'price', 'reason' => 'intent'], 'بكام الفستان', []);

    $body = (string) $c->notes()->latest('id')->value('body');
    expect($body)->toContain('بكام الفستان');
});
