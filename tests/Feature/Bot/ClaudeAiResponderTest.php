<?php

use App\Bot\Ai\ClaudeAiResponder;
use App\Enums\CommentIntent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function claudeResponder(): ClaudeAiResponder
{
    return new ClaudeAiResponder('sk-test-key', 'claude-haiku-4-5-20251001', 'claude-sonnet-5', 10);
}

it('sends the anthropic auth headers and the classifier model for classify()', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"intent":"buy","confidence":0.8,"needs_human":false}']],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
        ], 200),
    ]);

    $classification = claudeResponder()->classify('عايز اطلب الفستان');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-haiku-4-5-20251001';
    });

    expect($classification->intent)->toBe(CommentIntent::Buy)
        ->and($classification->confidence)->toBe(0.8)
        ->and($classification->needsHuman)->toBeFalse()
        ->and($classification->model)->toBe('claude-haiku-4-5-20251001')
        ->and($classification->inputTokens)->toBe(12)
        ->and($classification->outputTokens)->toBe(3);
});

it('parses the first balanced json object in classify() even with trailing prose', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"intent":"complaint","confidence":0.95,"needs_human":true} — ده رد لعميل غاضب، محتاج يتحول لموظف']],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
        ], 200),
    ]);

    $classification = claudeResponder()->classify('الاوردر اتاخر جدا ومحدش رد عليا');

    expect($classification->intent)->toBe(CommentIntent::Complaint)
        ->and($classification->confidence)->toBe(0.95)
        ->and($classification->needsHuman)->toBeTrue();
});

it('sends the reply model and maps token usage for reply()', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"action":"reply","text":"الفستان متاح بسعر 1250 جنيه"}']],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 40],
        ], 200),
    ]);

    $reply = claudeResponder()->reply(
        [['role' => 'customer', 'text' => 'بكام الفستان']],
        ['فستان ستان | SKU DR-101 | 1250 جنيه | متاح 7'],
        'System prompt',
    );

    Http::assertSent(fn ($request) => $request['model'] === 'claude-sonnet-5');

    expect($reply->action)->toBe('reply')
        ->and($reply->text)->toBe('الفستان متاح بسعر 1250 جنيه')
        ->and($reply->model)->toBe('claude-sonnet-5')
        ->and($reply->inputTokens)->toBe(200)
        ->and($reply->outputTokens)->toBe(40);
});

it('throws when the anthropic api responds with a server error', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['message' => 'boom']], 500),
    ]);

    expect(fn () => claudeResponder()->classify('نص عشوائي'))->toThrow(RequestException::class);
    expect(fn () => claudeResponder()->reply([], [], 'prompt'))->toThrow(RequestException::class);
});
