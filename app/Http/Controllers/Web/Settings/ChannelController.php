<?php

namespace App\Http\Controllers\Web\Settings;

use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Jobs\ProcessWebhookEvent;
use App\Commerce\Jobs\ProcessShopifyWebhook;
use App\Enums\Platform;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ChannelController extends Controller
{
    use RespondsWithData;

    public function __construct(private readonly MetaGraphClient $graph) {}

    public function index(): Response
    {
        return Inertia::render('settings/Channels', [
            'accounts' => ChannelAccount::orderBy('platform')->orderBy('id')->get()->map(fn (ChannelAccount $a) => $this->present($a)),
            'failedEvents' => WebhookEvent::where('status', 'failed')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'provider', 'event_type', 'status', 'attempts', 'error', 'payload', 'created_at']),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        $account = ChannelAccount::create($this->validated($request, null));

        return $this->done($request, $this->present($account), 201);
    }

    public function update(Request $request, ChannelAccount $channel): HttpResponse
    {
        $data = $this->validated($request, $channel, partial: true);

        if (array_key_exists('credentials', $data)) {
            $data['credentials'] = $this->mergedCredentials($channel, $data['credentials'] ?? []);

            // Nothing left to store (no incoming fields, nothing stored before) — drop the key.
            if (empty($data['credentials'])) {
                unset($data['credentials']);
            }
        }

        $channel->update($data);

        return $this->done($request, $this->present($channel));
    }

    /**
     * Test the live credentials without changing anything. A Facebook page fetches its
     * own name via `GET /{page-id}?fields=name`. An Instagram account has no token of
     * its own, so it fetches `GET /{ig-id}?fields=username,name` using its linked
     * Facebook page's token — a misconfigured (unlinked) account fails fast with 422
     * rather than attempting (and misreporting) a Graph call.
     */
    public function test(Request $request, ChannelAccount $channel): HttpResponse
    {
        if ($channel->platform === Platform::Instagram) {
            return $this->testInstagram($channel);
        }

        if (empty($channel->external_id)) {
            return response()->json(['ok' => false, 'error' => 'missing_external_id']);
        }

        $pageResponse = $this->graph->get($channel, $channel->external_id, ['fields' => 'name']);

        if ($pageResponse->failed()) {
            return response()->json(['ok' => false, 'error' => $pageResponse->json('error.message') ?? 'graph_api_error']);
        }

        return response()->json(['ok' => true, 'page_name' => $pageResponse->json('name')]);
    }

    private function testInstagram(ChannelAccount $channel): HttpResponse
    {
        if (! $channel->linkedFacebookAccount()) {
            return response()->json(['ok' => false, 'error' => 'الحساب ده لسه مش مربوط بصفحة فيسبوك.'], 422);
        }

        if (empty($channel->external_id)) {
            return response()->json(['ok' => false, 'error' => 'missing_external_id'], 422);
        }

        // `client()` resolves the linked page's token via ChannelAccount::graphToken().
        $response = $this->graph->get($channel, $channel->external_id, ['fields' => 'username,name']);

        if ($response->failed()) {
            return response()->json(['ok' => false, 'error' => $response->json('error.message') ?? 'graph_api_error']);
        }

        return response()->json(['ok' => true, 'ig_username' => $response->json('username')]);
    }

    /**
     * Subscribe the page to the webhook fields the live adapters rely on. From the
     * Instagram card this subscribes the *linked* Facebook page instead (Instagram
     * accounts have no `subscribed_apps` edge of their own) — note this only wires up
     * message/comment delivery for the linked page; the Instagram object's own webhook
     * fields (`messages`, `comments`, ...) still need enabling once in the Meta App
     * Dashboard, see README §6 / docs/deploy/cloudways-staging.md.
     */
    public function subscribe(Request $request, ChannelAccount $channel): HttpResponse
    {
        $pageId = $channel->external_id;

        if ($channel->platform === Platform::Instagram) {
            $linked = $channel->linkedFacebookAccount();

            if (! $linked) {
                return response()->json(['ok' => false, 'error' => 'الحساب ده لسه مش مربوط بصفحة فيسبوك.'], 422);
            }

            $pageId = $linked->external_id;
        }

        if (empty($pageId)) {
            return response()->json(['ok' => false, 'error' => 'missing_external_id']);
        }

        $result = $this->graph->post($channel, "{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'messages,messaging_postbacks,message_deliveries,message_reads,feed',
        ]);

        if (! $result->success) {
            return response()->json(['ok' => false, 'error' => $result->error]);
        }

        $response = ['ok' => true];

        if ($channel->platform === Platform::Instagram) {
            $response['note'] = 'تم تسجيل صفحة فيسبوك المربوطة. متنساش تفعّل حقول '
                .'messages, comments مرة واحدة من App Dashboard ← Webhooks ← Instagram '
                .'(التفاصيل في README §6 أو docs/deploy/cloudways-staging.md)، وإلا '
                .'رسائل وتعليقات إنستجرام مش هتوصل.';
        }

        return response()->json($response);
    }

    /**
     * Accounts own conversations and posts, so "delete" disconnects instead of dropping history.
     */
    public function destroy(Request $request, ChannelAccount $channel): HttpResponse
    {
        $channel->update(['status' => 'disconnected']);

        return $this->done($request, $this->present($channel));
    }

    public function reprocess(Request $request, WebhookEvent $event): HttpResponse
    {
        $event->update(['status' => 'received', 'error' => null]);

        if ($event->provider === 'shopify') {
            ProcessShopifyWebhook::dispatch($event->id)->onQueue('commerce');
        } else {
            ProcessWebhookEvent::dispatch($event->id)->onQueue('webhooks');
        }

        $event->refresh();

        return $this->done($request, ['id' => $event->id, 'status' => $event->status, 'attempts' => $event->attempts, 'error' => $event->error]);
    }

    /**
     * An Instagram account never has its own access token — it always sends/tests
     * through its linked Facebook page's token (`ChannelAccount::graphToken()`) — so
     * `credentials.access_token` is rejected for it, and a link to an existing
     * Facebook account is required instead.
     */
    private function validated(Request $request, ?ChannelAccount $channel, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $platform = $request->input('platform', $channel?->platform?->value);

        $rules = [
            'platform' => [$required, Rule::enum(Platform::class)],
            'name' => [$required, 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'driver' => [$required, Rule::in(['fake', 'live'])],
            'status' => ['sometimes', Rule::in(['connected', 'error', 'disconnected'])],
            'credentials' => ['nullable', 'array'],
        ];

        if ($platform === Platform::Instagram->value) {
            $rules['credentials.access_token'] = ['prohibited'];
            $rules['credentials.linked_facebook_account_id'] = [
                'required_with:credentials',
                Rule::exists('channel_accounts', 'id')->where('platform', Platform::Facebook->value),
            ];
        } else {
            $rules['credentials.access_token'] = ['nullable', 'string'];
        }

        return $request->validate($rules);
    }

    /**
     * The access token field is write-only: an empty value on an update means "keep
     * the stored token" rather than clearing it. Other credential keys (e.g. the
     * linked Facebook account id) always take the submitted value.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergedCredentials(ChannelAccount $channel, array $incoming): array
    {
        if (empty($incoming['access_token'])) {
            unset($incoming['access_token']);
        }

        return array_merge($channel->credentials ?? [], $incoming);
    }

    private function present(ChannelAccount $a): array
    {
        return [
            'id' => $a->id,
            'platform' => $a->platform?->value,
            'name' => $a->name,
            'external_id' => $a->external_id,
            'driver' => $a->driver,
            'status' => $a->status,
            'last_webhook_at' => $a->last_webhook_at?->toIso8601String(),
            'last_error' => $a->last_error,
            'has_token' => ! empty($a->credentials['access_token'] ?? null),
            'linked_facebook_account_id' => $a->credentials['linked_facebook_account_id'] ?? null,
            // The public address the platform posts to: always APP_URL, never the host this page
            // happened to be opened on (e.g. http://127.0.0.1:8000 locally behind a tunnel).
            'webhook_url' => rtrim((string) config('app.url'), '/').'/webhooks/'.$a->platform?->value,
            'verify_token' => config('crm.meta.verify_token'),
        ];
    }
}
