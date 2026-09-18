<?php

use App\Bot\HandoverSummary;
use App\Enums\Platform;
use App\Http\Resources\ConversationResource;
use App\Models\ChannelAccount;
use App\Models\Conversation;

it('labels an intent-driven handover reason in Arabic instead of the raw word', function () {
    expect(HandoverSummary::reasonLabel('intent'))->toBe('طلب العميل');

    $c = Conversation::factory()->for(ChannelAccount::factory()->state(['platform' => Platform::Facebook]), 'channelAccount')->create([
        'platform' => Platform::Facebook,
        'handover_category' => 'cancel_order',
        'priority_level' => 'medium',
    ]);

    $note = app(HandoverSummary::class)->note($c, 'intent', 'عايزة الغي الاوردر', null, null);

    expect($note->body)->toContain('السبب: طلب العميل')
        ->and($note->body)->not->toContain('السبب: intent')
        ->and($note->body)->toContain('التصنيف: إلغاء أوردر');
});

it('exposes the handover category label on the conversation resource and the broadcast', function () {
    $c = Conversation::factory()->for(ChannelAccount::factory()->state(['platform' => Platform::Facebook]), 'channelAccount')->create([
        'platform' => Platform::Facebook,
        'needs_human' => true,
        'handover_category' => 'order_details_missing',
        'priority_level' => 'high',
        'queue' => 'senior',
    ]);

    $data = (new ConversationResource($c))->resolve(request());
    expect($data['handover_category_label'])->toBe('بيانات الأوردر ناقصة');

    $broadcast = (new App\Events\ConversationUpdated($c))->broadcastWith();
    expect($broadcast['priority_level'])->toBe('high')
        ->and($broadcast['queue'])->toBe('senior')
        ->and($broadcast['handover_category'])->toBe('order_details_missing')
        ->and($broadcast['handover_category_label'])->toBe('بيانات الأوردر ناقصة');

    $c->forceFill(['handover_category' => null])->save();
    expect((new ConversationResource($c))->resolve(request())['handover_category_label'])->toBeNull();
});
