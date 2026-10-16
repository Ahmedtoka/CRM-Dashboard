<?php

use App\Confirmations\WhatsApp\TemplateDefinitions;
use App\Confirmations\WhatsApp\TemplateParams;
use App\Support\Emoji;

it('defines the confirm and reminder templates under the names the settings use', function () {
    expect(array_keys(TemplateDefinitions::all()))->toBe(['order_confirm_ar', 'order_reminder_ar'])
        ->and(TemplateDefinitions::find('order_confirm_ar'))->not->toBeNull()
        ->and(TemplateDefinitions::find('nope'))->toBeNull();
});

it('builds a Meta message_templates payload per template', function (string $name) {
    $t = TemplateDefinitions::find($name);
    $components = collect($t['components'])->keyBy('type');

    expect($t['name'])->toBe($name)
        ->and($t['category'])->toBe('UTILITY')
        ->and($t['language'])->toBe('ar')
        ->and($components->keys()->all())->toBe(['BODY', 'FOOTER', 'BUTTONS'])
        ->and($components['FOOTER']['text'])->toBe('Le Voile');

    $body = $components['BODY'];
    preg_match_all('/\{\{(\d+)\}\}/', $body['text'], $m);

    // One numbered variable per TemplateParams::BODY entry, in order, each used once.
    expect(TemplateParams::BODY)->toBe(['first_name', 'order_number', 'items', 'total', 'address', 'payment'])
        ->and(array_map('intval', $m[1]))->toBe(range(1, count(TemplateParams::BODY)))
        ->and($body['text'])->toContain('العنوان: {{5}}')
        // Neutral about payment: the variable alone carries «الدفع عند الاستلام» / «اتأكدنا من الدفع».
        ->and($body['text'])->toContain("\n{{6}}\n")
        ->and($body['text'])->not->toContain('الدفع')
        ->and($body['text'])->not->toContain('مدفوع')
        ->and($body['example']['body_text'][0])->toBe(array_map(fn ($p) => TemplateParams::EXAMPLES[$p], TemplateParams::BODY))
        ->and($body['example']['body_text'][0])->toHaveCount(count(TemplateParams::BODY))
        // Meta rejects a body that starts or ends with a variable.
        ->and($body['text'])->not->toStartWith('{{')
        ->and($body['text'])->not->toEndWith('}}')
        ->and(Emoji::contains($body['text']))->toBeFalse();

    $buttons = $components['BUTTONS']['buttons'];
    expect(array_column($buttons, 'type'))->toBe(['QUICK_REPLY', 'QUICK_REPLY', 'QUICK_REPLY'])
        ->and(array_column($buttons, 'text'))->toBe(['تأكيد الأوردر', 'تعديل', 'إلغاء']);
})->with(['order_confirm_ar', 'order_reminder_ar']);

it('keeps the button order aligned with the confirmation answers', function () {
    expect(TemplateDefinitions::BUTTON_ACTIONS)->toBe(['confirm', 'edit', 'cancel']);
});

it('keeps the copy free of promotional words so Meta files it as utility', function (string $name) {
    $text = collect(TemplateDefinitions::find($name)['components'])->firstWhere('type', 'BODY')['text'];

    foreach (['خصم', 'عرض', 'عروض', 'تخفيض', 'كوبون', 'مجانا', 'مجاني', 'اشتري', 'جديد'] as $word) {
        expect($text)->not->toContain($word);
    }
})->with(['order_confirm_ar', 'order_reminder_ar']);
