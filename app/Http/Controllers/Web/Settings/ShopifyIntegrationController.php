<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\ShopifySyncRun;
use App\Models\ShopifyWebhookSubscription;
use App\Shopify\Connection\ConnectShopify;
use App\Shopify\Connection\ConnectionTester;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunManualSync;
use App\Shopify\Sync\BulkImporter;
use App\Shopify\Sync\ImportAlreadyRunningException;
use App\Shopify\Webhooks\WebhookRegistrar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Settings → Shopify connection screen (spec §7): guided connect, live import
 * progress, manual sync, webhook health and disconnect. Admin only
 * (routes/crm.php). Never sends `access_token`/`api_secret` back to the
 * browser — every payload goes through `present()`.
 */
class ShopifyIntegrationController extends Controller
{
    use RespondsWithData;

    private const IMPORT_RUNNING_MESSAGE = 'فيه استيراد شغال بالفعل — استنى لما يخلص';

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly ConnectionTester $tester,
        private readonly ConnectShopify $connector,
        private readonly WebhookRegistrar $webhooks,
        private readonly BulkImporter $importer,
    ) {}

    public function index(): InertiaResponse
    {
        $integration = $this->integrations->current();

        return Inertia::render('settings/Shopify', [
            'integration' => $integration ? $this->present($integration) : null,
            'requiredScopes' => config('crm.shopify.required_scopes', []),
            'webhooks' => $this->webhooksPayload(),
            'runs' => $this->runsPayload(),
            'lastSync' => $this->lastSync(),
        ]);
    }

    /**
     * Live status for the 5 s polling fallback (Rulings): same shape as the
     * page props, still admin-only, still secret-free.
     */
    public function status(): HttpResponse
    {
        $integration = $this->integrations->current();

        return response()->json([
            'integration' => $integration ? $this->present($integration) : null,
            'runs' => $this->runsPayload(),
            'webhooks' => $this->webhooksPayload(),
        ]);
    }

    public function test(Request $request): HttpResponse
    {
        $data = $request->validate([
            'shop_domain' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
        ]);

        $result = $this->tester->test($data['shop_domain'], $data['access_token']);

        return response()->json([
            'ok' => $result->ok,
            'shop_name' => $result->shopName,
            'currency' => $result->currency,
            'missing_scopes' => $result->missingScopes,
            'optional_scopes' => config('crm.shopify.optional_scopes', []),
            'error' => $result->error,
        ]);
    }

    public function connect(Request $request): HttpResponse
    {
        $data = $request->validate([
            'shop_domain' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
            // Optional: some owners only have the Admin API token. Without a secret
            // the webhook HMAC falls back to crm.shopify.webhook_secret.
            'api_secret' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->connector->connect($data['shop_domain'], $data['access_token'], $data['api_secret'] ?? null);
        } catch (ImportAlreadyRunningException) {
            return $this->importConflict($request);
        }

        if (! $result['ok']) {
            return $this->failure($request, $result, 422);
        }

        return $this->done($request, ['ok' => true]);
    }

    public function resumeImport(Request $request): HttpResponse
    {
        try {
            $this->importer->resume();
        } catch (ImportAlreadyRunningException) {
            return $this->importConflict($request);
        }

        return $this->done($request, ['ok' => true]);
    }

    public function sync(Request $request): HttpResponse
    {
        $data = $request->validate([
            'resource' => ['required', Rule::in(['shipping', 'products', 'customers', 'orders'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($data['resource'] === 'orders') {
            $error = $this->validateOrdersRange($data['from'] ?? null, $data['to'] ?? null);

            if ($error !== null) {
                return $this->failure($request, ['ok' => false, 'error' => $error], 422);
            }
        }

        try {
            RunManualSync::dispatch($data['resource'], $data['from'] ?? null, $data['to'] ?? null);
        } catch (ImportAlreadyRunningException) {
            return $this->importConflict($request);
        }

        return $this->done($request, ['ok' => true]);
    }

    public function reregisterWebhooks(Request $request): HttpResponse
    {
        $this->webhooks->check();

        return $this->done($request, ['ok' => true]);
    }

    public function updateSettings(Request $request): HttpResponse
    {
        $data = $request->validate([
            'default_shipping_fee' => ['required', 'numeric', 'min:0', 'max:10000'],
            'auto_create_shipment' => ['required', 'boolean'],
            'stuck_order_days' => ['required', 'integer', 'min:1', 'max:60'],
            'mismatch_alerts' => ['required', 'boolean'],
            'order_creation_enabled' => ['required', 'boolean'],
        ]);

        $integration = $this->integrations->current();

        if ($integration === null) {
            return $this->failure($request, ['ok' => false, 'error' => 'No Shopify integration on file'], 404);
        }

        $integration->forceFill(['settings' => $data])->save();

        return $this->done($request, ['ok' => true]);
    }

    /**
     * Disconnects locally regardless of what Shopify says (Rulings): clears
     * secrets, flips to `disconnected`, keeps every imported record. Webhook
     * removal is best-effort — WebhookRegistrar already swallows per-topic
     * Shopify failures, so only an unexpected local error surfaces here.
     */
    public function destroy(Request $request): HttpResponse
    {
        $integration = $this->integrations->current();

        if ($integration === null) {
            return $this->done($request, ['ok' => true]);
        }

        $warning = null;

        try {
            $this->webhooks->removeAll();
        } catch (Throwable $e) {
            $warning = $e->getMessage();
        }

        $integration->forceFill([
            'access_token' => null,
            'api_secret' => null,
            'status' => 'disconnected',
        ])->save();

        return $this->done($request, ['ok' => true, 'warning' => $warning]);
    }

    /**
     * @return array{ok: false, error: ?string}
     */
    private function validateOrdersRange(?string $from, ?string $to): ?string
    {
        if ($from === null || $to === null) {
            return 'مزامنة الطلبات محتاجة تاريخ من وإلى';
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            return 'أقصى مدى للمزامنة سنة واحدة (٣٦٦ يوم)';
        }

        return null;
    }

    private function importConflict(Request $request): HttpResponse
    {
        return $this->failure($request, ['ok' => false, 'error' => self::IMPORT_RUNNING_MESSAGE], 409);
    }

    /**
     * Failures never go through RespondsWithData::done() (JSON always carries
     * the payload — e.g. `missing_scopes` — at the top level, not nested
     * under `errors`, per the brief's contract).
     */
    private function failure(Request $request, array $payload, int $status): HttpResponse
    {
        if ($request->expectsJson()) {
            return response()->json($payload, $status);
        }

        return back(303)->with('shopify_error', $payload['error'] ?? null);
    }

    private function present(ShopifyIntegration $integration): array
    {
        $required = config('crm.shopify.required_scopes', []);
        $granted = $integration->granted_scopes ?? [];

        return [
            'shop_domain' => $integration->shop_domain,
            'shop_name' => $integration->shop_name,
            'currency' => $integration->currency,
            'status' => $integration->status,
            'last_error' => $integration->last_error,
            'connected_at' => $integration->connected_at?->toIso8601String(),
            'settings' => $integration->settingsWithDefaults(),
            'import_state' => $integration->import_state,
            'granted_scopes' => $granted,
            'missing_scopes' => array_values(array_diff($required, $granted)),
        ];
    }

    private function webhooksPayload(): array
    {
        return ShopifyWebhookSubscription::query()
            ->orderBy('topic')
            ->get(['topic', 'last_received_at', 'registered_at'])
            ->map(fn (ShopifyWebhookSubscription $w) => [
                'topic' => $w->topic,
                'last_received_at' => $w->last_received_at?->toIso8601String(),
                'registered_at' => $w->registered_at?->toIso8601String(),
            ])->all();
    }

    private function runsPayload(): array
    {
        return ShopifySyncRun::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ShopifySyncRun $run) => [
                'id' => $run->id,
                'type' => $run->type,
                'resource' => $run->resource,
                'range_from' => $run->range_from?->toIso8601String(),
                'range_to' => $run->range_to?->toIso8601String(),
                'status' => $run->status,
                'processed' => $run->processed,
                'created' => $run->created,
                'updated' => $run->updated,
                'skipped_stale' => $run->skipped_stale,
                'failed' => $run->failed,
                'errors' => $run->errors ?? [],
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->all();
    }

    /**
     * @return array{shipping: ?string, products: ?string, customers: ?string, orders: ?string}
     */
    private function lastSync(): array
    {
        $result = [];

        foreach (['shipping', 'products', 'customers', 'orders'] as $resource) {
            $run = ShopifySyncRun::query()
                ->where('resource', $resource)
                ->where('status', 'completed')
                ->orderByDesc('finished_at')
                ->first();

            $result[$resource] = $run?->finished_at?->toIso8601String();
        }

        return $result;
    }
}
