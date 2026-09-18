<?php

use App\Enums\{Platform, UserRole};
use App\Inbox\QuickReplyCatalog;
use App\Models\{QuickReply, QuickReplyCategory, User};

it('returns shared plus own personal replies, personal first, filtered by platform', function () {
    $me = User::factory()->create(['role' => UserRole::Moderator]);
    $me->userPlatforms()->create(['platform' => Platform::Facebook]);
    $cat = QuickReplyCategory::factory()->create(['name' => 'الشحن والتوصيل']);
    QuickReply::factory()->create(['shortcut' => 'ship', 'category_id' => $cat->id]);
    QuickReply::factory()->personal($me)->create(['shortcut' => 'ship']);
    QuickReply::factory()->personal(User::factory()->create())->create(['shortcut' => 'other']);
    QuickReply::factory()->create(['shortcut' => 'wa', 'platforms' => ['whatsapp']]);

    $rows = app(QuickReplyCatalog::class)->toArray($me, Platform::Facebook);

    expect(array_column($rows, 'shortcut'))->toBe(['ship', 'ship'])
        ->and($rows[0]['scope'])->toBe('personal')
        ->and($rows[1]['category'])->toBe(['id' => $cat->id, 'name' => 'الشحن والتوصيل']);
});

it('seeds the clothing store categories', function () {
    expect(QuickReplyCategory::orderBy('sort')->pluck('name')->all())->toBe(
        ['الترحيب', 'الأسعار والمنتجات', 'المقاسات', 'الشحن والتوصيل', 'الاستبدال والاسترجاع', 'الدفع', 'متابعة الأوردر', 'الشكاوى']);
});
