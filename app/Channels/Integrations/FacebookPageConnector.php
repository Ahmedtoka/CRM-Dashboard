<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Data\SendResult;
use App\Channels\MetaPageSubscriber;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

/**
 * Lists the Facebook Pages a token can manage and saves the chosen one as the live
 * Messenger account. Shared by "Connect with Facebook" (a user token from Facebook
 * Login) and the System User token option on Settings → Integrations.
 *
 * Page access tokens only ever live in the returned arrays (kept server-side by the
 * callers, encrypted in the session) and in the account's encrypted credentials.
 */
final class FacebookPageConnector
{
    /** Page tasks needed to answer messages (MESSAGING) and comments (MODERATE). */
    public const REQUIRED_TASKS = ['MESSAGING', 'MODERATE'];

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly MetaPageSubscriber $subscriber,
    ) {}

    /**
     * Every page of `GET /me/accounts`, following the `after` cursor (not the
     * `paging.next` URL, which embeds the token). For a System User token this is the
     * list of Pages assigned to that system user in Business Manager.
     *
     * @return list<array{id: string, name: string, category: ?string, picture: ?string, tasks: ?list<string>, access_token: string}>
     *
     * @throws IntegrationException
     */
    public function pagesForToken(string $token): array
    {
        $pages = [];
        $after = null;

        for ($i = 0; $i < 20; $i++) {
            $query = ['fields' => 'id,name,category,picture{url},tasks,access_token', 'limit' => 100];

            if ($after !== null) {
                $query['after'] = $after;
            }

            try {
                $response = $this->graph->getWithToken($token, 'me/accounts', $query);
            } catch (ConnectionException) {
                throw new IntegrationException('graph_unreachable');
            }

            if ($response->failed()) {
                throw IntegrationException::graph($this->isTokenError($response->json('error.code')) ? 'token_invalid' : 'graph_error', $response->json('error.message'));
            }

            foreach ((array) $response->json('data', []) as $row) {
                if (empty($row['id']) || empty($row['access_token'])) {
                    continue;
                }

                $pages[] = $this->pageRow($row);
            }

            $after = $response->json('paging.cursors.after');

            if (blank($response->json('paging.next')) || blank($after)) {
                break;
            }
        }

        return $pages;
    }

    /**
     * One Page by id — the fallback when `/me/accounts` comes back empty for a System
     * User token (e.g. the Page was shared with the agency's business but the token
     * lists nothing): `GET /{page-id}?fields=…,access_token` derives the Page token.
     *
     * @return array{id: string, name: string, category: ?string, picture: ?string, tasks: ?list<string>, access_token: string}
     *
     * @throws IntegrationException
     */
    public function pageForToken(string $token, string $pageId): array
    {
        try {
            $response = $this->graph->getWithToken($token, $pageId, ['fields' => 'id,name,category,picture{url},access_token']);
        } catch (ConnectionException) {
            throw new IntegrationException('graph_unreachable');
        }

        if ($response->failed()) {
            throw IntegrationException::graph($this->isTokenError($response->json('error.code')) ? 'token_invalid' : 'page_not_accessible', $response->json('error.message'));
        }

        if (blank($response->json('access_token'))) {
            // The token can see the Page but not manage it (no MANAGE/MESSAGING task).
            throw new IntegrationException('page_token_unavailable');
        }

        return $this->pageRow((array) $response->json());
    }

    /**
     * Saves $page on the live Messenger account (reusing the one on file) and subscribes
     * the Page's webhooks. The account is saved even when the subscription fails — the
     * card then shows the problem with a one-click "subscribe again".
     *
     * @param  array{id: string, name: string, category?: ?string, picture?: ?string, access_token: string}  $page
     * @return array{0: ChannelAccount, 1: SendResult}
     */
    public function connect(array $page, string $method): array
    {
        $account = DB::transaction(function () use ($page, $method) {
            $account = $this->liveAccount(includeDisconnected: true) ?? new ChannelAccount([
                'platform' => Platform::Facebook,
                'driver' => 'live',
            ]);

            $account->fill([
                'name' => $page['name'],
                'external_id' => $page['id'],
                'credentials' => array_merge($account->credentials ?? [], ['access_token' => $page['access_token']]),
                'profile' => array_filter([
                    'picture' => $page['picture'] ?? null,
                    'category' => $page['category'] ?? null,
                    'method' => $method,
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

        return [$account, $this->subscriber->subscribe($account, $page['id'])];
    }

    /**
     * The live Messenger account: the one in use, else (when reconnecting) the most
     * recent disconnected one so its conversations stay attached. Fake/demo accounts
     * are never touched.
     */
    public function liveAccount(bool $includeDisconnected = false): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('platform', Platform::Facebook)
            ->where('driver', 'live')
            ->when(! $includeDisconnected, fn ($q) => $q->where('status', '!=', 'disconnected'))
            ->orderByRaw("case when status = 'disconnected' then 1 else 0 end")
            ->orderBy('id')
            ->first();
    }

    /**
     * Tasks the token's person/system user lacks on the page. A null list (Meta may
     * omit `tasks`, e.g. for business-granted pages) is treated as unknown and allowed.
     *
     * @param  list<string>|null  $tasks
     * @return list<string>
     */
    public static function missingTasks(?array $tasks): array
    {
        if ($tasks === null) {
            return [];
        }

        return array_values(array_diff(self::REQUIRED_TASKS, $tasks));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, name: string, category: ?string, picture: ?string, tasks: ?list<string>, access_token: string}
     */
    private function pageRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? $row['id']),
            'category' => $row['category'] ?? null,
            'picture' => $row['picture']['data']['url'] ?? null,
            'tasks' => isset($row['tasks']) && is_array($row['tasks']) ? array_values(array_map('strval', $row['tasks'])) : null,
            'access_token' => (string) ($row['access_token'] ?? ''),
        ];
    }

    private function isTokenError(mixed $code): bool
    {
        return in_array((int) $code, [190, 102, 463, 467], true);
    }
}
