<?php

use App\Bot\Flow\ClaudeTurnUnderstanding;
use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\TurnUnderstanding;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('sends one structured-output request and parses all intents and entities', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode(['intents' => [['key' => 'price', 'confidence' => 0.92], ['key' => 'delivery_time', 'confidence' => 0.88]], 'entities' => ['order_ref' => null, 'phone' => null, 'email' => null, 'governorate' => 'الجيزة', 'product' => 'طقم اسود', 'size' => null, 'color' => 'اسود'], 'sentiment' => 'neutral', 'urgent' => false, 'unclear' => false, 'language' => 'ar'])]],
        'usage' => ['input_tokens' => 400, 'output_tokens' => 60],
    ])]);

    $u = (new ClaudeTurnUnderstanding('k', 'claude-haiku-4-5-20251001', 10))->understand(
        [['role' => 'agent', 'text' => 'أهلا'], ['role' => 'customer', 'text' => 'عايزة اسأل']],
        ['بكام الطقم الاسود', 'والتوصيل للجيزة كام يوم'],
        [['key' => 'price', 'label' => 'سعر', 'hints' => ['بكام']], ['key' => 'delivery_time', 'label' => 'التوصيل', 'hints' => []]],
    );

    expect($u->keys())->toBe(['price', 'delivery_time'])
        ->and($u->entities['governorate'])->toBe('الجيزة')
        ->and($u->entities['phone'])->toBeNull()
        ->and($u->inputTokens)->toBe(400)
        ->and($u->model)->toBe('claude-haiku-4-5-20251001');

    Http::assertSent(function ($r) {
        $messages = $r['messages'];

        return $r['model'] === 'claude-haiku-4-5-20251001'
            && $r->hasHeader('x-api-key', 'k')
            && ($r['output_config']['format']['type'] ?? null) === 'json_schema'
            && $r['output_config']['format']['schema']['properties']['intents']['items']['properties']['key']['enum'] === ['price', 'delivery_time']
            && str_contains($r['system'], 'price: سعر (examples: بكام)')
            // history starts with the customer (leading agent line dropped) and the
            // customer line merges with the final burst message: one user turn.
            && count($messages) === 1
            && $messages[0]['role'] === 'user'
            && str_contains($messages[0]['content'], 'والتوصيل للجيزة');
    });
    Http::assertSentCount(1);
});

it('retries without output_config when the model rejects it', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'output_config not supported']], 400)
        ->push(['content' => [['type' => 'text', 'text' => 'نتيجة: {"intents":[{"key":"price","confidence":0.8}],"entities":{},"sentiment":"neutral","urgent":false,"unclear":false,"language":"franco"}']], 'usage' => []]);

    $u = (new ClaudeTurnUnderstanding('k', 'm', 10))->understand([], ['bkam'], [['key' => 'price', 'label' => 'سعر', 'hints' => []]]);

    expect($u->keys())->toBe(['price'])->and($u->language)->toBe('franco');
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => ! isset($r['output_config']) && str_contains($r['system'], 'Respond with ONLY the JSON object'));
});

it('does not retry other http errors', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error'], 500)]);

    expect(fn () => (new ClaudeTurnUnderstanding('k', 'm', 10))->understand([], ['hi'], []))->toThrow(RequestException::class);
    Http::assertSentCount(1);
});

it('returns unclear on garbage', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'no json']], 'usage' => []])]);
    expect((new ClaudeTurnUnderstanding('k', 'm', 10))->understand([], ['؟'], [])->unclear)->toBeTrue();
});

it('binds the fake understanding unless the claude driver has a key', function () {
    config(['crm.drivers.ai' => 'fake']);
    expect(app(TurnUnderstanding::class))->toBeInstanceOf(FakeTurnUnderstanding::class);

    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => null]);
    expect(app(TurnUnderstanding::class))->toBeInstanceOf(FakeTurnUnderstanding::class);

    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'sk-test']);
    expect(app(TurnUnderstanding::class))->toBeInstanceOf(ClaudeTurnUnderstanding::class);
});

it('understands a burst offline by keywords, entities and mood', function () {
    $u = app(FakeTurnUnderstanding::class)->understand([], ['عايزة الغي الاوردر 12345 بسرعة', 'رقمي 01012345678 والخدمة وحشة'], [
        ['key' => 'cancel_order', 'label' => 'إلغاء', 'hints' => ['الغي']],
        ['key' => 'greeting', 'label' => 'ترحيب', 'hints' => ['hi']],
    ]);

    expect($u->keys())->toBe(['cancel_order'])
        ->and($u->entities['order_ref'])->toBe('12345')
        ->and($u->entities['phone'])->toBe('01012345678')
        ->and($u->urgent)->toBeTrue()
        ->and($u->sentiment)->toBe('negative')
        ->and($u->unclear)->toBeFalse();

    expect(app(FakeTurnUnderstanding::class)->understand([], ['this is it'], [['key' => 'greeting', 'label' => 'ترحيب', 'hints' => ['hi']]])->unclear)->toBeTrue();
});
