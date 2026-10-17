<?php

/*
 * shopify-app/shopify.app.toml declares the app's webhooks (round 2026-10-17): one subscription per
 * topic in crm.shopify.webhook_topics, each to its existing /webhooks/shopify/{topic} route, plus
 * the three compliance topics to /webhooks/shopify/compliance. The no-webhooks variant (install
 * first, rollback) has only the compliance subscription. Both request exactly today's scopes.
 */

function tomlFile(string $name): string
{
    return (string) file_get_contents(base_path("shopify-app/{$name}"));
}

/** @return array<string, string> topic => uri */
function tomlTopicSubscriptions(string $toml): array
{
    preg_match_all('/topics = \["([^"]+)"\]\s*\n\s*uri = "([^"]+)"/', $toml, $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($match) => [$match[1] => $match[2]])->all();
}

it('subscribes every configured topic once, each to its own route, plus the compliance topics', function () {
    $toml = tomlFile('shopify.app.toml');
    $subscriptions = tomlTopicSubscriptions($toml);
    $topics = config('crm.shopify.webhook_topics');

    expect(array_keys($subscriptions))->toEqualCanonicalizing($topics)
        ->and(substr_count($toml, '[[webhooks.subscriptions]]'))->toBe(count($topics) + 1);

    foreach ($subscriptions as $topic => $uri) {
        expect($uri)->toBe('https://crm.levoilestores.com/webhooks/shopify/'.str_replace('/', '-', $topic));
    }

    expect($toml)->toContain('compliance_topics = ["customers/data_request", "customers/redact", "shop/redact"]')
        ->toContain('uri = "https://crm.levoilestores.com/webhooks/shopify/compliance"')
        ->toContain('use_legacy_install_flow = false')
        ->toContain('application_url = "https://crm.levoilestores.com/shopify/app"');
});

it('keeps the no-webhooks variant to the compliance subscription only', function () {
    $toml = tomlFile('shopify.app.no-webhooks.toml');

    expect(tomlTopicSubscriptions($toml))->toBe([])
        ->and(substr_count($toml, '[[webhooks.subscriptions]]'))->toBe(1)
        ->and($toml)->toContain('compliance_topics = ["customers/data_request", "customers/redact", "shop/redact"]');
});

it('asks for exactly the scopes the CRM uses today', function (string $file) {
    preg_match('/^scopes = "([^"]+)"/m', tomlFile($file), $m);

    expect(explode(',', $m[1]))->toEqualCanonicalizing(config('crm.shopify.required_scopes'));
})->with(['shopify.app.toml', 'shopify.app.no-webhooks.toml']);

it('maps every topic route back to its Shopify topic', function () {
    \Illuminate\Support\Facades\Queue::fake();
    foreach (config('crm.shopify.webhook_topics') as $topic) {
        \App\Models\WebhookEvent::query()->delete();
        $body = json_encode(['id' => 1]);

        $this->call('POST', '/webhooks/shopify/'.str_replace('/', '-', $topic), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        expect(\App\Models\WebhookEvent::value('event_type'))->toBe($topic);
    }
});
