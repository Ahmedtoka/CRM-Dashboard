<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\MetaGraphClient;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\UserNotifier;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health of one live Meta connection (Settings → Integrations "Test" button and the
 * daily `channels:health` run):
 *
 *  - token     `GET /debug_token` with the app token: valid, expiry, granted scopes vs
 *              the ones this platform needs (missing required → problem, missing
 *              recommended → warning).
 *  - webhooks  the app is subscribed to the Page (`/{page-id}/subscribed_apps`, with the
 *              `messages` field) or to the WABA (`/{waba-id}/subscribed_apps`).
 *  - link      Instagram only: the Page still links the same Instagram account.
 *  - phone     WhatsApp only: the number's quality rating (refreshes the card too).
 *  - inbound   age of the last customer message (warning after 48 h while connected).
 *
 * The result is stored on the account (`health`, `health_status`). Entering "problem"
 * flips the account to `error` (the admin alert strip) and notifies admins once;
 * leaving it clears the error again.
 */
final class ConnectionHealthCheck
{
    public const STALE_HOURS = 48;

    /** Scopes a connection cannot work without / features that degrade without. */
    public const SCOPES = [
        'facebook' => [
            'required' => ['pages_messaging', 'pages_manage_metadata'],
            'recommended' => ['pages_read_engagement', 'pages_manage_engagement', 'pages_read_user_content'],
        ],
        'instagram' => [
            'required' => ['instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata'],
            'recommended' => ['instagram_manage_comments', 'pages_read_engagement'],
        ],
        'whatsapp' => [
            'required' => ['whatsapp_business_messaging', 'whatsapp_business_management'],
            'recommended' => [],
        ],
    ];

    /**
     * Prefix of the stable code stored in `ChannelAccount.last_error`, e.g.
     * `problem:token_invalid`. The check also runs from the scheduler, in the
     * default locale, so nothing readable is written at that point — the text
     * comes from {@see self::problemText()} when a page renders the row.
     */
    public const PROBLEM_CODE_PREFIX = 'problem:';

    /** Check codes that have a `labels.channel_problem.*` sentence. */
    private const PROBLEM_CODES = [
        'token_missing',
        'token_invalid',
        'missing_scopes',
        'not_subscribed',
        'missing_fields',
        'instagram_unlinked',
        'instagram_changed',
        'facebook_disconnected',
    ];

    /**
     * Renders a stored `last_error` in the viewer's language. Anything that is not
     * one of our own `problem:<code>` values — a raw Graph/Shopify message, or a row
     * written before the codes existed — comes back untouched.
     */
    public static function problemText(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }

        $parts = array_map(
            function (string $piece) {
                $code = str_starts_with($piece, self::PROBLEM_CODE_PREFIX)
                    ? substr($piece, strlen(self::PROBLEM_CODE_PREFIX))
                    : null;

                return $code !== null && in_array($code, self::PROBLEM_CODES, true)
                    ? __('labels.channel_problem.'.$code)
                    : $piece;
            },
            explode(' · ', $stored),
        );

        return implode(' · ', $parts);
    }

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly InstagramConnector $instagram,
        private readonly UserNotifier $notifier,
    ) {}

    /**
     * @return array{status: string, checked_at: string, checks: list<array<string, mixed>>}
     */
    public function run(ChannelAccount $account): array
    {
        $checks = [];

        $checks[] = $this->safely('token', fn () => $this->tokenCheck($account));

        if ($account->platform === Platform::WhatsApp) {
            $checks[] = $this->safely('webhooks', fn () => $this->wabaSubscriptionCheck($account));
            $checks[] = $this->safely('phone', fn () => $this->phoneCheck($account));
        } else {
            $checks[] = $this->safely('webhooks', fn () => $this->pageSubscriptionCheck($account));
        }

        if ($account->platform === Platform::Instagram) {
            $checks[] = $this->safely('link', fn () => $this->instagramLinkCheck($account));
        }

        $checks[] = $this->inboundCheck($account);

        $statuses = array_column($checks, 'status');
        $status = in_array('problem', $statuses, true) ? 'problem' : (in_array('warning', $statuses, true) ? 'warning' : 'ok');

        $result = ['status' => $status, 'checked_at' => now()->toIso8601String(), 'checks' => $checks];

        $this->store($account, $result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenCheck(ChannelAccount $account): array
    {
        if ($account->platform === Platform::Instagram && $account->linkedFacebookAccount()?->status === 'disconnected') {
            return $this->check('token', 'problem', 'facebook_disconnected', fix: 'reconnect_facebook');
        }

        $token = $account->graphToken();

        if (blank($token)) {
            return $this->check('token', 'problem', 'token_missing', fix: 'reconnect');
        }

        $response = $this->graph->debugToken((string) $token);

        if ($response === null) {
            return $this->check('token', 'warning', 'app_not_configured');
        }

        if ($response->failed()) {
            return $this->check('token', 'warning', 'check_failed', detail: $response->json('error.message'));
        }

        $data = (array) $response->json('data', []);

        if (! ($data['is_valid'] ?? false)) {
            return $this->check('token', 'problem', 'token_invalid', detail: $data['error']['message'] ?? null, fix: 'reconnect');
        }

        $scopes = array_map('strval', (array) ($data['scopes'] ?? []));
        $required = self::SCOPES[$account->platform->value]['required'] ?? [];
        $recommended = self::SCOPES[$account->platform->value]['recommended'] ?? [];
        $expiresAt = (int) ($data['expires_at'] ?? 0);

        // debug_token omits `scopes` for some token types; only judge a list we got.
        if ($scopes !== [] && ($missing = array_values(array_diff($required, $scopes))) !== []) {
            return $this->check('token', 'problem', 'missing_scopes', missing: $missing, fix: 'reconnect');
        }

        if ($expiresAt > 0 && $expiresAt < now()->addDays(7)->getTimestamp()) {
            return $this->check('token', 'warning', 'token_expiring', detail: CarbonImmutable::createFromTimestamp($expiresAt)->toIso8601String(), fix: 'reconnect');
        }

        if ($scopes !== [] && ($missing = array_values(array_diff($recommended, $scopes))) !== []) {
            return $this->check('token', 'warning', 'missing_recommended_scopes', missing: $missing);
        }

        return $this->check('token', 'ok', $expiresAt === 0 ? 'token_valid_forever' : 'token_valid', detail: $expiresAt > 0 ? CarbonImmutable::createFromTimestamp($expiresAt)->toIso8601String() : null);
    }

    /**
     * Messenger and Instagram both depend on the app being subscribed to the Page.
     *
     * @return array<string, mixed>
     */
    private function pageSubscriptionCheck(ChannelAccount $account): array
    {
        $pageAccount = $account->platform === Platform::Instagram ? $account->linkedFacebookAccount() : $account;
        $pageId = $pageAccount?->external_id;

        if ($pageAccount === null || blank($pageId) || blank($pageAccount->graphToken())) {
            return $this->check('webhooks', 'problem', 'not_subscribed', fix: $account->platform === Platform::Instagram ? 'reconnect_facebook' : 'reconnect');
        }

        $response = $this->graph->get($pageAccount, "{$pageId}/subscribed_apps");

        if ($response->failed()) {
            return $this->check('webhooks', 'warning', 'check_failed', detail: $response->json('error.message'));
        }

        $app = $this->ourApp((array) $response->json('data', []), fn (array $row) => (string) ($row['id'] ?? ''));

        if ($app === null) {
            return $this->check('webhooks', 'problem', 'not_subscribed', fix: 'resubscribe');
        }

        $fields = array_map('strval', (array) ($app['subscribed_fields'] ?? []));

        if ($fields !== [] && ! in_array('messages', $fields, true)) {
            return $this->check('webhooks', 'problem', 'missing_fields', missing: ['messages'], fix: 'resubscribe');
        }

        if ($account->platform === Platform::Facebook && $fields !== [] && ! in_array('feed', $fields, true)) {
            return $this->check('webhooks', 'warning', 'missing_fields', missing: ['feed'], fix: 'resubscribe');
        }

        return $this->check('webhooks', 'ok', 'subscribed');
    }

    /**
     * @return array<string, mixed>
     */
    private function wabaSubscriptionCheck(ChannelAccount $account): array
    {
        $wabaId = $account->wabaId();

        if ($wabaId === null) {
            return $this->check('webhooks', 'problem', 'not_subscribed', fix: 'reconnect');
        }

        $response = $this->graph->get($account, "{$wabaId}/subscribed_apps");

        if ($response->failed()) {
            return $this->check('webhooks', 'warning', 'check_failed', detail: $response->json('error.message'));
        }

        $app = $this->ourApp((array) $response->json('data', []), fn (array $row) => (string) ($row['whatsapp_business_api_data']['id'] ?? $row['id'] ?? ''));

        if ($app === null) {
            return $this->check('webhooks', 'problem', 'not_subscribed', fix: 'resubscribe');
        }

        if (($account->profile['override_callback'] ?? false) && WhatsAppConnector::canOverrideCallback()
            && (string) ($app['override_callback_uri'] ?? '') !== WhatsAppConnector::callbackUrl()) {
            return $this->check('webhooks', 'warning', 'callback_mismatch', detail: $app['override_callback_uri'] ?? null, fix: 'resubscribe');
        }

        return $this->check('webhooks', 'ok', 'subscribed');
    }

    /**
     * @return array<string, mixed>
     */
    private function phoneCheck(ChannelAccount $account): array
    {
        $response = $this->graph->get($account, (string) $account->external_id, ['fields' => WhatsAppConnector::PHONE_FIELDS]);

        if ($response->failed()) {
            return $this->check('phone', 'warning', 'check_failed', detail: $response->json('error.message'));
        }

        $quality = $response->json('quality_rating');

        $account->forceFill(['profile' => array_merge($account->profile ?? [], array_filter([
            'display_phone_number' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
            'quality_rating' => $quality,
            'code_verification_status' => $response->json('code_verification_status'),
        ], fn ($v) => $v !== null))])->save();

        return match (strtoupper((string) $quality)) {
            'RED' => $this->check('phone', 'warning', 'quality_low', detail: 'RED'),
            'YELLOW' => $this->check('phone', 'warning', 'quality_medium', detail: 'YELLOW'),
            default => $this->check('phone', 'ok', 'quality_ok', detail: $quality ? (string) $quality : null),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function instagramLinkCheck(ChannelAccount $account): array
    {
        $facebook = $account->linkedFacebookAccount();

        if ($facebook === null) {
            return $this->check('link', 'problem', 'facebook_disconnected', fix: 'reconnect_facebook');
        }

        $ig = $this->instagram->discover($facebook);

        if ($ig === null) {
            return $this->check('link', 'problem', 'instagram_unlinked', fix: 'reconnect');
        }

        if ($ig['id'] !== (string) $account->external_id) {
            return $this->check('link', 'problem', 'instagram_changed', detail: $ig['username'] ? '@'.$ig['username'] : $ig['id'], fix: 'reconnect');
        }

        // Keep the card's name/picture fresh (Instagram picture URLs expire).
        $account->forceFill(['profile' => array_merge($account->profile ?? [], array_filter([
            'username' => $ig['username'], 'display_name' => $ig['name'], 'picture' => $ig['picture'],
        ], fn ($v) => $v !== null))])->save();

        return $this->check('link', 'ok', 'linked');
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCheck(ChannelAccount $account): array
    {
        $last = self::lastInboundAt($account);
        $staleBefore = now()->subHours(self::STALE_HOURS);

        if ($last === null) {
            $connectedLongAgo = $account->connected_at !== null && $account->connected_at->lt($staleBefore);

            return $this->check('inbound', $connectedLongAgo ? 'warning' : 'ok', $connectedLongAgo ? 'no_messages_yet' : 'waiting_first_message');
        }

        return $last->lt($staleBefore)
            ? $this->check('inbound', 'warning', 'stale', detail: $last->toIso8601String())
            : $this->check('inbound', 'ok', 'recent', detail: $last->toIso8601String());
    }

    public static function lastInboundAt(ChannelAccount $account): ?CarbonImmutable
    {
        $value = Conversation::query()->where('channel_account_id', $account->id)->max('last_customer_message_at');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  callable(array<string, mixed>): string  $idOf
     * @return array<string, mixed>|null
     */
    private function ourApp(array $rows, callable $idOf): ?array
    {
        $appId = (string) config('crm.meta.app_id');
        $rows = array_values(array_filter($rows, 'is_array'));

        if ($appId === '') {
            return $rows[0] ?? null; // cannot tell which app is ours: any subscription counts
        }

        foreach ($rows as $row) {
            if ($idOf($row) === $appId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A Graph call that could not be made (timeout, DNS) is a warning, never a problem:
     * a flaky network must not page the admins at night.
     *
     * @param  callable(): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function safely(string $key, callable $run): array
    {
        try {
            return $run();
        } catch (ConnectionException) {
            return $this->check($key, 'warning', 'check_failed', detail: 'graph_unreachable');
        } catch (IntegrationException $e) {
            return $this->check($key, 'warning', 'check_failed', detail: $e->detail ?? $e->errorCode);
        } catch (Throwable $e) {
            report($e);

            return $this->check($key, 'warning', 'check_failed', detail: class_basename($e));
        }
    }

    /**
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private function check(string $key, string $status, string $code, mixed $detail = null, array $missing = [], ?string $fix = null): array
    {
        return array_filter([
            'key' => $key,
            'status' => $status,
            'code' => $code,
            'detail' => is_string($detail) && $detail !== '' ? Str::limit(MetaGraphClient::redact($detail), 300) : null,
            'missing' => $missing ?: null,
            'fix' => $fix,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array{status: string, checked_at: string, checks: list<array<string, mixed>>}  $result
     */
    private function store(ChannelAccount $account, array $result): void
    {
        $previous = $account->health_status;
        $problems = array_values(array_filter($result['checks'], fn (array $c) => $c['status'] === 'problem'));

        $attributes = [
            'health' => $result,
            'health_status' => $result['status'],
            'health_checked_at' => now(),
        ];

        if ($problems !== []) {
            $attributes['status'] = 'error';
            // Stable codes, not sentences: this runs from the scheduler too, so the
            // reader's locale — not the writer's — must decide the wording.
            $attributes['last_error'] = Str::limit(implode(' · ', array_map(
                fn (array $c) => in_array($c['code'], self::PROBLEM_CODES, true)
                    ? self::PROBLEM_CODE_PREFIX.$c['code']
                    : $c['code'],
                $problems,
            )), 250);
        } elseif ($account->status === 'error') {
            // Healthy again (e.g. after a reconnect or once Meta recovered).
            $attributes['status'] = 'connected';
            $attributes['last_error'] = null;
        }

        $account->forceFill($attributes)->save();

        if ($result['status'] === 'problem' && $previous !== 'problem') {
            $this->notifyAdmins($account, array_column($problems, 'code'));
        }
    }

    /**
     * @param  list<string>  $codes
     */
    private function notifyAdmins(ChannelAccount $account, array $codes): void
    {
        User::query()
            ->where('is_active', true)
            ->where('role', UserRole::Admin->value)
            ->get()
            ->each(fn (User $admin) => rescue(fn () => $this->notifier->notify($admin, 'channel.problem', [
                'channel_account_id' => $account->id,
                'platform' => $account->platform?->value,
                'name' => $account->name,
                'codes' => $codes,
                // The notification payload is persisted, so this stays what it always was:
                // the sentence in the app's default locale. Making the bell locale-aware
                // means rendering `codes` on the frontend instead (not this slice).
                'excerpt' => self::problemText($account->last_error),
            ]), report: true));
    }
}
