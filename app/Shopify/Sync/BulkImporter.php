<?php

namespace App\Shopify\Sync;

use App\Events\IntegrationProgress;
use App\Models\ShopifySyncRun;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Jobs\RunBulkImportStage;
use App\Shopify\Sync\Mappers\Payload;
use App\Shopify\Sync\Mappers\ShippingZoneMapper;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Initial Shopify import (spec §4.1). Stages run in order, one queued job each:
 * `shipping` through a paginated query, the others through Bulk Operations
 * (start → poll by job re-dispatch → stream the JSONL → map in chunks of 500).
 *
 * `import_state`: {"stages": {stage: {status, total, processed, failed,
 * bulk_operation_id, run_id}}, "orders_since": "Y-m-d"}.
 */
final class BulkImporter
{
    public const STAGES = ['shipping', 'products', 'customers', 'orders'];

    public const CHUNK = 500;

    /** Bulk operation statuses that mean "poll again later". */
    private const PENDING_STATUSES = ['CREATED', 'RUNNING', 'CANCELING'];

    public function __construct(
        private readonly ShopifyClient $client,
        private readonly IntegrationRepository $integrations,
        private readonly ShippingZoneMapper $zones,
        private readonly ResourceRowMapper $rows,
        private readonly JsonlReader $reader,
        private readonly SyncRunRecorder $recorder,
    ) {}

    public function start(): void
    {
        $integration = $this->integrations->requireConnected();

        $state = [
            'stages' => array_fill_keys(self::STAGES, self::freshStage()),
            'orders_since' => $this->defaultOrdersSince(),
        ];

        $integration->forceFill(['import_state' => $state])->save();
        SafeBroadcast::send(new IntegrationProgress($state));

        RunBulkImportStage::dispatch(self::STAGES[0]);
    }

    public function resume(): void
    {
        $state = $this->integrations->requireConnected()->import_state;

        if (empty($state['stages'])) {
            $this->start();

            return;
        }

        foreach (self::STAGES as $stage) {
            if ($this->statusIn($state, $stage) !== 'completed') {
                RunBulkImportStage::dispatch($stage);

                return;
            }
        }
    }

    /**
     * Runs one step of a stage. Returns with the stage `running` while its bulk
     * operation is still pending (the job re-dispatches itself), or `completed`.
     * A fatal failure marks the stage `failed` and rethrows; row errors are
     * recorded on the run and never stop the stage.
     */
    public function runStage(string $stage): void
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException("Unknown import stage [{$stage}].");
        }

        $current = $this->integrations->current()?->import_state['stages'][$stage] ?? [];
        $resuming = ($current['status'] ?? null) === 'running' && ! empty($current['bulk_operation_id']);
        $run = ($resuming && isset($current['run_id'])) ? ShopifySyncRun::find($current['run_id']) : null;
        $run ??= $this->recorder->open('initial', $stage);

        try {
            $this->integrations->requireConnected();

            if ($stage === 'shipping') {
                $this->importShipping($run);

                return;
            }

            $operationId = $resuming ? (string) $current['bulk_operation_id'] : $this->startBulkOperation($stage, $run);
            $operation = $this->pollBulkOperation($operationId);
            $status = strtoupper((string) ($operation['status'] ?? ''));
            $total = isset($operation['objectCount']) ? (int) $operation['objectCount'] : null;

            if (in_array($status, self::PENDING_STATUSES, true)) {
                $this->updateStage($stage, ['status' => 'running', 'total' => $total]);

                return;
            }

            if ($status !== 'COMPLETED') {
                throw new RuntimeException(trim("Shopify bulk operation {$status} ".($operation['errorCode'] ?? '')));
            }

            $this->updateStage($stage, ['total' => $total]);
            $summary = $this->importFile($stage, $operation['url'] ?? null, $operationId, $run);

            $this->recorder->close($run, $summary, 'completed');
            $this->updateStage($stage, ['status' => 'completed', 'processed' => $summary->processed, 'failed' => $summary->failed]);
        } catch (Throwable $e) {
            $run->refresh();
            $this->recorder->recordError($run, $stage, $e->getMessage());
            $this->recorder->close($run, SyncRunSummary::fromRun($run), 'failed');
            $this->updateStage($stage, ['status' => 'failed']);

            throw $e;
        }
    }

    public function stageStatus(string $stage): string
    {
        return $this->statusIn($this->integrations->current()?->import_state ?? [], $stage);
    }

    public static function nextStage(string $stage): ?string
    {
        $index = array_search($stage, self::STAGES, true);

        return $index === false ? null : (self::STAGES[$index + 1] ?? null);
    }

    private function importShipping(ShopifySyncRun $run): void
    {
        $this->updateStage('shipping', ['status' => 'running', 'total' => null, 'processed' => 0, 'failed' => 0, 'run_id' => $run->id]);

        $zones = [];
        $cursor = null;
        $first = true;

        do {
            $page = $this->client->query(SyncQueries::SHIPPING, ['cursor' => $cursor])['deliveryProfiles'] ?? null;

            if (! is_array($page)) {
                // Never wipe the local zones because a response came back empty.
                if ($first) {
                    throw new RuntimeException('Shopify returned no deliveryProfiles.');
                }
                break;
            }

            foreach (Payload::list($page) as $profile) {
                foreach ($profile['profileLocationGroups'] ?? [] as $group) {
                    array_push($zones, ...Payload::list($group['locationGroupZones'] ?? []));
                }
            }

            $first = false;
            $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        $count = $this->zones->replaceAll($zones);

        $this->recorder->close($run, SyncRunSummary::empty()->withProcessed($count), 'completed');
        $this->updateStage('shipping', ['status' => 'completed', 'total' => $count, 'processed' => $count]);
    }

    private function startBulkOperation(string $stage, ShopifySyncRun $run): string
    {
        $since = null;

        if ($stage === 'orders') {
            $since = $this->integrations->current()?->import_state['orders_since'] ?? $this->defaultOrdersSince();
        }

        $data = $this->client->mutate(SyncQueries::bulkRun($stage, $since), [], 'bulkOperationRunQuery');
        $id = $data['bulkOperationRunQuery']['bulkOperation']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Shopify did not return a bulk operation id.');
        }

        $this->updateStage($stage, [
            'status' => 'running',
            'total' => null,
            'processed' => 0,
            'failed' => 0,
            'bulk_operation_id' => $id,
            'run_id' => $run->id,
        ] + ($since !== null ? ['orders_since' => $since] : []));

        return $id;
    }

    /** @return array<string, mixed> */
    private function pollBulkOperation(string $operationId): array
    {
        $data = $this->client->query(SyncQueries::BULK_OPERATION, ['id' => $operationId]);
        $operation = $data['node'] ?? $data['currentBulkOperation'] ?? null;

        if (! is_array($operation)) {
            throw new RuntimeException("Shopify bulk operation {$operationId} not found.");
        }

        return $operation;
    }

    private function importFile(string $stage, ?string $url, string $operationId, ShopifySyncRun $run): SyncRunSummary
    {
        if ($url === null || $url === '') {
            return SyncRunSummary::empty(); // COMPLETED with no objects: Shopify returns no file.
        }

        [$path, $temporary] = $this->localCopy($stage, $url, $operationId);

        try {
            return $this->mapFile($stage, $path, $run);
        } finally {
            if ($temporary) {
                File::delete($path);
            }
        }
    }

    /**
     * `file://` URLs (fake driver) are read in place; anything else is streamed to
     * storage/app/shopify/{stage}-{operationId}.jsonl.
     *
     * @return array{0: string, 1: bool} path and whether it must be deleted after mapping
     */
    private function localCopy(string $stage, string $url, string $operationId): array
    {
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, strlen('file://'));

            return [preg_match('~^/[A-Za-z]:[/\\\\]~', $path) === 1 ? substr($path, 1) : $path, false];
        }

        $directory = storage_path('app/shopify');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$stage.'-'.(Payload::id($operationId) ?? md5($operationId)).'.jsonl';

        $response = Http::withOptions(['stream' => true])->timeout(600)->get($url)->throw();
        $body = $response->toPsrResponse()->getBody();
        $out = fopen($path, 'wb');

        try {
            while (! $body->eof()) {
                fwrite($out, $body->read(1 << 16));
            }
        } finally {
            fclose($out);
            $body->close();
        }

        return [$path, true];
    }

    private function mapFile(string $stage, string $path, ShopifySyncRun $run): SyncRunSummary
    {
        $summary = SyncRunSummary::empty();
        $buffer = [];
        $orders = $this->rows->orders();
        $orders->beginBulk();

        try {
            foreach ($this->reader->objects($path) as [$row]) {
                $buffer[] = $row;

                if (count($buffer) >= self::CHUNK) {
                    $summary = $this->mapChunk($stage, $buffer, $run, $summary);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $summary = $this->mapChunk($stage, $buffer, $run, $summary);
            }
        } finally {
            $orders->endBulk();
        }

        return $summary;
    }

    /**
     * One transaction per chunk, one savepoint per row: a failing row rolls back
     * alone, is recorded, and the chunk goes on. Customer flags are recomputed
     * once per touched customer after the chunk commits.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function mapChunk(string $stage, array $rows, ShopifySyncRun $run, SyncRunSummary $summary): SyncRunSummary
    {
        DB::transaction(function () use ($stage, $rows, $run, &$summary) {
            foreach ($rows as $row) {
                try {
                    $result = DB::transaction(fn () => $this->rows->map($stage, $row));
                    $summary = $summary->withResult($result);
                } catch (Throwable $e) {
                    $summary = $summary->withFailure();
                    $this->recorder->recordError($run, (string) ($row['id'] ?? '?'), $e->getMessage());
                }
            }
        });

        $this->rows->orders()->flushDeferredFlags();
        $this->recorder->progress($run, $summary);
        $this->updateStage($stage, ['processed' => $summary->processed, 'failed' => $summary->failed]);

        return $summary;
    }

    /** Re-reads the integration so nested/sequential jobs never overwrite each other's state. */
    private function updateStage(string $stage, array $changes): void
    {
        $integration = $this->integrations->current();

        if ($integration === null) {
            return;
        }

        $state = $integration->import_state ?? [];

        if (array_key_exists('orders_since', $changes)) {
            $state['orders_since'] ??= $changes['orders_since'];
            unset($changes['orders_since']);
        }

        foreach (self::STAGES as $name) {
            $state['stages'][$name] = array_merge(self::freshStage(), $state['stages'][$name] ?? []);
        }

        $state['stages'][$stage] = array_merge($state['stages'][$stage], $changes);

        $integration->forceFill(['import_state' => $state])->save();
        SafeBroadcast::send(new IntegrationProgress($state));
    }

    private function statusIn(array $state, string $stage): string
    {
        return (string) ($state['stages'][$stage]['status'] ?? 'pending');
    }

    private function defaultOrdersSince(): string
    {
        return now()->subMonths(max(1, (int) config('crm.shopify.import_orders_months', 12)))->toDateString();
    }

    /** @return array{status: string, total: ?int, processed: int, failed: int, bulk_operation_id: ?string} */
    private static function freshStage(): array
    {
        return ['status' => 'pending', 'total' => null, 'processed' => 0, 'failed' => 0, 'bulk_operation_id' => null];
    }
}
