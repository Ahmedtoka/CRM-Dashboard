<?php

// Audit A2: the Meta subscription handshake echoes hub_challenge back. It must
// never be rendered as HTML (reflected XSS) and must refuse a missing or
// well-known verify token.

beforeEach(function () {
    config(['crm.drivers.channels' => 'live', 'crm.meta.verify_token' => 'a-long-random-token']);
});

function handshake(string $token = 'a-long-random-token', string $challenge = '1158201444'): \Illuminate\Testing\TestResponse
{
    return test()->get('/webhooks/facebook?'.http_build_query([
        'hub_mode' => 'subscribe',
        'hub_verify_token' => $token,
        'hub_challenge' => $challenge,
    ]));
}

it('echoes a valid challenge as plain text with nosniff', function () {
    $response = handshake();

    $response->assertOk();
    expect($response->getContent())->toBe('1158201444')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('refuses a challenge that is not a plain token', function (string $challenge) {
    handshake(challenge: $challenge)->assertForbidden();
})->with([
    'script tag' => '<script>alert(1)</script>',
    'empty' => '',
    'too long' => str_repeat('1', 129),
    'spaces' => '12 34',
]);

it('accepts letters, digits, dash and underscore up to 128 chars', function () {
    $challenge = 'aZ09_-'.str_repeat('x', 122);

    expect(handshake(challenge: $challenge)->assertOk()->getContent())->toBe($challenge);
});

it('refuses a wrong verify token', function () {
    handshake(token: 'nope')->assertForbidden();
});

it('refuses when no verify token is configured', function () {
    config(['crm.meta.verify_token' => null]);

    handshake(token: '')->assertForbidden();
});

it('refuses the old default token in production', function () {
    config(['crm.meta.verify_token' => 'crm-verify']);
    app()->detectEnvironment(fn () => 'production');

    handshake(token: 'crm-verify')->assertForbidden();
});

it('still accepts the old default token outside production', function () {
    config(['crm.meta.verify_token' => 'crm-verify']);

    handshake(token: 'crm-verify')->assertOk();
});

it('has no default verify token in config', function () {
    expect(file_get_contents(base_path('config/crm.php')))
        ->toContain("'verify_token' => env('META_VERIFY_TOKEN'),")
        ->not->toContain('crm-verify');
});
