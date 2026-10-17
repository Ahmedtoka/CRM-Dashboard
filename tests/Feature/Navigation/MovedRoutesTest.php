<?php

use App\Enums\UserRole;
use App\Models\User;

// IA §5 (redesign 2026-10-17): moved GET pages answer 301 at the old URL with the query kept, and the old route
// name still builds the old URL (notifications, emails, bookmarks). POST/PUT/DELETE never move.

function mvAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

dataset('moved', [
    'bot run' => ['/settings/bot', '/bot'],
    'bot flows' => ['/settings/bot-flows', '/bot/flows'],
    'bot intents' => ['/settings/bot-intents', '/bot/intents'],
    'bot replies' => ['/settings/bot-replies', '/bot/replies'],
    'bot knowledge' => ['/settings/bot-knowledge', '/bot/knowledge'],
    'bot translations' => ['/settings/bot-translations', '/bot/translations'],
    'bot test links' => ['/settings/bot-test-links', '/bot/test-links'],
    'bot learning' => ['/settings/bot-learning', '/bot/learning'],
    'bot report' => ['/reports/bot', '/bot/performance'],
    'team test' => ['/reports/team-test', '/bot/test-sessions'],
    'team test export' => ['/reports/team-test/export', '/bot/test-sessions/export'],
    'ads today' => ['/ads', '/today/ads'],
    'ads setup' => ['/ads/setup', '/settings/ads/accounts'],
    'ads accounts' => ['/ads/accounts', '/settings/ads/accounts'],
    'ads sync' => ['/ads/sync', '/settings/ads/sync'],
    'ads buyers setup' => ['/ads/setup/buyers', '/settings/ads/buyers'],
    'ads rules' => ['/ads/setup/rules', '/settings/ads/rules'],
]);

it('moves the page with a 301 and keeps the query', function (string $old, string $new) {
    $this->actingAs(mvAdmin())->get($old.'?tab=notes&from=2026-10-01')
        ->assertStatus(301)
        ->assertRedirect($new.'?tab=notes&from=2026-10-01');
})->with('moved');

it('moves the page without a query', function (string $old, string $new) {
    $this->actingAs(mvAdmin())->get($old)->assertStatus(301)->assertRedirect($new);
})->with('moved');

it('keeps the old route names on the redirects', function (string $name, string $old) {
    expect(route($name, absolute: false))->toBe($old);
})->with([
    ['settings.bot.index', '/settings/bot'],
    ['settings.bot-flows.index', '/settings/bot-flows'],
    ['settings.bot-intents.index', '/settings/bot-intents'],
    ['settings.bot-replies.index', '/settings/bot-replies'],
    ['settings.bot-knowledge.index', '/settings/bot-knowledge'],
    ['settings.bot-translations.index', '/settings/bot-translations'],
    ['settings.bot-test-links.index', '/settings/bot-test-links'],
    ['settings.bot-learning.index', '/settings/bot-learning'],
    ['reports.bot', '/reports/bot'],
    ['reports.team-test', '/reports/team-test'],
    ['ads.today', '/ads'],
    ['ads.accounts.index', '/ads/accounts'],
    ['ads.sync', '/ads/sync'],
    ['ads.setup.buyers', '/ads/setup/buyers'],
    ['ads.setup.rules', '/ads/setup/rules'],
    ['settings.channels.index', '/settings/channels'],
]);

it('keeps array filters exactly as sent (the ads filter bar reads accounts[])', function () {
    $this->actingAs(mvAdmin())->get('/ads?accounts[]=5&accounts[]=6&range=7d')
        ->assertStatus(301)
        ->assertRedirect('/today/ads?accounts[]=5&accounts[]=6&range=7d');
});

it('carries route parameters into the new URL', function () {
    $admin = mvAdmin();

    $this->actingAs($admin)->get('/settings/bot-flows/7?v=2')->assertStatus(301)->assertRedirect('/bot/flows/7?v=2');
    $this->actingAs($admin)->get('/settings/bot-test-links/9/sessions')->assertStatus(301)->assertRedirect('/bot/test-links/9/sessions');
    $this->actingAs($admin)->get('/reports/team-test/sessions/11')->assertStatus(301)->assertRedirect('/bot/test-sessions/11');
});

it('sends the old channels page to the webhooks tab of the integrations page', function () {
    $this->actingAs(mvAdmin())->get('/settings/channels?x=1')->assertStatus(301)->assertRedirect('/settings/integrations?tab=webhooks&x=1');
    $this->actingAs(mvAdmin())->get('/settings/integrations?tab=webhooks')->assertOk()->assertInertia(fn ($p) => $p->component('settings/Channels'));
});

it('serves the same pages at the new URLs', function (string $url, string $component) {
    $this->actingAs(mvAdmin())->get($url)->assertOk()->assertInertia(fn ($p) => $p->component($component));
})->with([
    ['/bot', 'settings/Bot'],
    ['/bot/flows', 'settings/BotFlows'],
    ['/bot/intents', 'settings/BotIntents'],
    ['/bot/replies', 'settings/BotReplies'],
    ['/bot/knowledge', 'settings/BotKnowledge'],
    ['/bot/translations', 'settings/BotTranslations'],
    ['/bot/test-links', 'settings/BotTestLinks'],
    ['/bot/learning', 'settings/BotLearning'],
    ['/bot/performance', 'Reports/Bot'],
    ['/bot/test-sessions', 'Reports/TeamTest'],
    ['/today/ads', 'Ads/Today'],
    ['/settings/ads/accounts', 'Ads/Accounts'],
    ['/settings/ads/sync', 'Ads/Sync'],
    ['/settings/ads/buyers', 'Ads/BuyersSetup'],
    ['/settings/ads/rules', 'Ads/SetupRules'],
    ['/settings/integrations', 'settings/Integrations'],
]);

it('still shares the ads access with the ads pages served from their new homes', function (string $url) {
    $this->actingAs(mvAdmin())->get($url)->assertInertia(fn ($p) => $p->where('ads.canManage', true));
})->with(['/today/ads', '/settings/ads/accounts', '/settings/ads/rules']);

it('opens /reports on the first report the person may see', function () {
    $this->actingAs(mvAdmin())->get('/reports')->assertRedirect('/reports/team');
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/reports')->assertRedirect('/reports/me');
});

it('keeps the bot pages to supervisors and up', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/bot/flows')->assertForbidden();
});

it('never moves the Facebook login routes', function () {
    expect(route('settings.channels.facebook.connect', absolute: false))->toBe('/settings/channels/facebook/connect')
        ->and(route('settings.channels.facebook.callback', absolute: false))->toBe('/settings/channels/facebook/callback')
        ->and(route('settings.channels.facebook.pages', absolute: false))->toBe('/settings/channels/facebook/pages');
});
