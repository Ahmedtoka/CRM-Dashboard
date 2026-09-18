<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp Cloud API (directly on Meta): a phone number of a WhatsApp Business
 * Account (WABA) shared with the agency's business, driven by a System User token
 * with whatsapp_business_messaging + whatsapp_business_management.
 *
 * Connecting validates the number against the WABA, saves the live account
 * (external_id = phone number id — webhooks carry it as metadata.phone_number_id)
 * and subscribes the app to the WABA (`POST /{waba-id}/subscribed_apps`), optionally
 * overriding the callback URL for this WABA only.
 */
final class WhatsAppConnector
{
    public const PHONE_FIELDS = 'id,display_phone_number,verified_name,quality_rating,code_verification_status,name_status,status';

    public function __construct(private readonly MetaGraphClient $graph) {}

    public static function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/whatsapp';
    }

    /** Meta only accepts an https callback override. */
    public static function canOverrideCallback(): bool
    {
        return str_starts_with(self::callbackUrl(), 'https://') && filled(config('crm.meta.verify_token'));
    }

    /**
     * `GET /{waba-id}/phone_numbers`.
     *
     * @return list<array{id: string, display_phone_number: ?string, verified_name: ?string, quality_rating: ?string, code_verification_status: ?string}>
     *
     * @throws IntegrationException
     */
    public function phoneNumbers(string $wabaId, string $token): array
    {
        $response = $this->call(fn () => $this->graph->getWithToken($token, "{$wabaId}/phone_numbers", ['fields' => self::PHONE_FIELDS, 'limit' => 100]));

        if ($response->failed()) {
            throw IntegrationException::graph($this->errorCode($response, 'waba_not_accessible'), $response->json('error.message'));
        }

        return array_values(array_map(fn (array $row) => $this->phoneRow($row), array_filter(
            (array) $response->json('data', []),
            fn ($row) => is_array($row) && filled($row['id'] ?? null),
        )));
    }

    /**
     * `GET /{phone-number-id}?fields=…`.
     *
     * @return array{id: string, display_phone_number: ?string, verified_name: ?string, quality_rating: ?string, code_verification_status: ?string}
     *
     * @throws IntegrationException
     */
    public function phoneNumber(string $phoneNumberId, string $token): array
    {
        $response = $this->call(fn () => $this->graph->getWithToken($token, $phoneNumberId, ['fields' => self::PHONE_FIELDS]));

        if ($response->failed()) {
            throw IntegrationException::graph($this->errorCode($response, 'phone_not_accessible'), $response->json('error.message'));
        }

        return $this->phoneRow((array) $response->json());
    }

    /**
     * Validates, saves and subscribes. The account is saved even when the WABA
     * subscription fails (the card then offers "subscribe again").
     *
     * @return array{0: ChannelAccount, 1: SendResult}
     *
     * @throws IntegrationException
     */
    public function connect(string $wabaId, string $phoneNumberId, string $token, bool $overrideCallback): array
    {
        $phone = $this->phoneNumber($phoneNumberId, $token);
        $numbers = $this->phoneNumbers($wabaId, $token);

        if (! collect($numbers)->contains(fn (array $n) => $n['id'] === $phone['id'])) {
            throw new IntegrationException('phone_not_in_waba');
        }

        $overrideCallback = $overrideCallback && self::canOverrideCallback();

        $account = DB::transaction(function () use ($wabaId, $phone, $token, $overrideCallback) {
            $account = $this->liveAccount(includeDisconnected: true) ?? new ChannelAccount([
                'platform' => Platform::WhatsApp,
                'driver' => 'live',
            ]);

            $account->fill([
                'name' => trim(($phone['verified_name'] ?? '').' '.($phone['display_phone_number'] ?? '')) ?: $phone['id'],
                'external_id' => $phone['id'],
                'credentials' => ['access_token' => $token],
                'profile' => array_filter([
                    'waba_id' => $wabaId,
                    'display_phone_number' => $phone['display_phone_number'],
                    'verified_name' => $phone['verified_name'],
                    'quality_rating' => $phone['quality_rating'],
                    'code_verification_status' => $phone['code_verification_status'],
                    'override_callback' => $overrideCallback,
                ], fn ($v) => $v !== null),
                'status' => 'connected',
                'connected_at' => now(),
                'last_error' => null,
                'health' => null,
                'health_status' => null,
                'health_checked_at' => null,
            ])->save();

            return $account;
        });

        return [$account, $this->subscribe($account)];
    }

    /**
     * `POST /{waba-id}/subscribed_apps` — with `override_callback_uri` + `verify_token`
     * when this account was connected with the override (Meta verifies the URL with a
     * GET handshake against /webhooks/whatsapp before accepting it).
     */
    public function subscribe(ChannelAccount $account): SendResult
    {
        $wabaId = $account->wabaId();

        if ($wabaId === null) {
            return SendResult::fail('missing_waba_id');
        }

        $payload = [];

        if (($account->profile['override_callback'] ?? false) && self::canOverrideCallback()) {
            $payload = [
                'override_callback_uri' => self::callbackUrl(),
                'verify_token' => (string) config('crm.meta.verify_token'),
            ];
        }

        try {
            return $this->graph->post($account, "{$wabaId}/subscribed_apps", $payload);
        } catch (ConnectionException) {
            return SendResult::fail('graph_unreachable', retryable: true);
        }
    }

    public function liveAccount(bool $includeDisconnected = false): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('platform', Platform::WhatsApp)
            ->where('driver', 'live')
            ->when(! $includeDisconnected, fn ($q) => $q->where('status', '!=', 'disconnected'))
            ->orderByRaw("case when status = 'disconnected' then 1 else 0 end")
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, display_phone_number: ?string, verified_name: ?string, quality_rating: ?string, code_verification_status: ?string}
     */
    private function phoneRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'display_phone_number' => $row['display_phone_number'] ?? null,
            'verified_name' => $row['verified_name'] ?? null,
            'quality_rating' => $row['quality_rating'] ?? null,
            'code_verification_status' => $row['code_verification_status'] ?? null,
        ];
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws IntegrationException
     */
    private function call(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw new IntegrationException('graph_unreachable');
        }
    }

    private function errorCode(Response $response, string $fallback): string
    {
        return in_array((int) $response->json('error.code'), [190, 102], true) ? 'token_invalid' : $fallback;
    }
}
