<?php

namespace App\Shopify\Sync;

use App\Events\IntegrationProgress;
use App\Models\ShopifySyncRun;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunBulkImportStage;
use App\Shopify\Sync\Mappers\Payload;
use App\Shopify\Sync\Mappers\ShippingZoneMapper;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Initial Shopify import (spec §4.1). Stages run in order, one queued job chain:
 * `shipping` through paginated queries, the others through Bulk Operations
 * (start → poll by job re-dispatch → stream the JSONL → map in chunks).
 *
 * Every job step is short (queue `retry_after` is 90 s): downloads resume with
 * HTTP Range, mapping stops after `import_chunks_per_job` chunks or the time
 * budget and re-dispatches from the byte offset of the last complete parent.
 *
 * `import_state`: {"stages": {stage: {status, phase, total, processed, failed,
 * bulk_operation_id, run_id, poll_attempts, offset, file, file_temporary, error,
 * updated_at}}, "orders_since": "Y-m-d"}.
 */
final class BulkImporter
{
    public const STAGES = ['shipping', 'products', 'customers', 'orders'];

    public const CHUNK = 500;

    public const CHUNKS_PER_JOB = 20;

    public const MAX_POLLS = 720;

    /** A `running` stage updated this recently belongs to a live chain. */
    public const ACTIVE_MINUTES = 15;

    public const LOCK_SECONDS = 900;

    /** runStage() outcomes. */
    public const OUTCOME_LOCKED = 'locked';

    public const OUTCOME_POLLING = 'polling';

    public const OUTCOME_WORKING = 'working';

    public const OUTCOME_COMPLETED = 'completed';

    /** Bulk operation statuses that mean "poll again later". */
    private const PENDING_STATUSES = ['CREATED', 'RUNNING', 'CANCELING'];

    /** Result URLs expire after 7 days; an older COMPLETED operation is not reused. */
    private const REUSE_MAX_AGE_DAYS = 6;

    private float $startedAt = 0.0;

    public function __construct(
        private readonly ShopifyClient $client,
        private readonly IntegrationRepository $integrations,
        private readonly ShippingZoneMapper $zones,
        private readonly ResourceRowMapper $rows,
        private readonly JsonlReader $reader,
        private readonly SyncRunRecorder $recorder,
    ) {}

    /**
     * @throws ImportAlreadyRunningException while a stage of a live chain is running
     */
    public function start(): void
    {
        $this->integrations->requireConnected();

        $state = DB::transaction(function () {
            $integration = $this->lockedIntegration()
                ?? throw new ShopifyException('not_connected', 'No connected Shopify integration');

            foreach (self::STAGES as $stage) {
                if ($this->isActive($integration->import_state['stages'][$stage] ?? [])) {
                    throw new ImportAlreadyRunningException($stage);
                }
            }

            $stages = array_fill_keys(self::STAGES, self::freshStage());
            // Running from the moment it is queued, so a second start() cannot slip in before the first job runs.
            $stages[self::STAGES[0]]['status'] = 'running';
            $stages[self::STAGES[0]]['updated_at'] = now()->toIso8601String();

            $state = ['stages' => $stages, 'orders_since' => $this->defaultOrdersSince()];
            $integration->forceFill(['import_state' => $state])->save();

            return $state;
        });

        SafeBroadcast::send(new IntegrationProgress($state));
        RunBulkImportStage::dispatch(self::STAGES[0]);
    }

    /** Dispatches the first incomplete stage, unless a live chain is already working on it. */
    public function resume(): void
    {
        $state = $this->integrations->requireConnected()->import_state;

        if (empty($state['stages'])) {
            $this->start();

            return;
        }

        foreach (self::STAGES as $stage) {
            $current = $state['stages'][$stage] ?? [];

            if (($current['status'] ?? 'pending') === 'completed') {
                continue;
            }

            if (! $this->isActive($current)) {
                RunBulkImportStage::dispatch($stage);
            }

            return;
        }
    }

    /**
     * Runs one short step of a stage under a per-stage lock and returns an
     * OUTCOME_*: `locked` (another worker owns the stage — do nothing), `polling`
     * (bulk operation pending — retry with delay), `working` (more to download or
     * map — continue now) or `completed`. A fatal failure marks the stage failed
     * and rethrows; row errors are recorded on the run and never stop the stage.
     */
    public function runStage(string $stage): string
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException("Unknown import stage [{$stage}].");
        }

        $lock = Cache::lock("shopify-import-{$stage}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return self::OUTCOME_LOCKED;
        }

        try {
            $this->startedAt = microtime(true);

            return $this->step($stage);
        } finally {
            $lock->release();
        }
    }

    public function stageStatus(string $stage): string
    {
        return (string) ($this->stageState($stage)['status'] ?? 'pending');
    }

    public static function nextStage(string $stage): ?string
    {
        $index = array_search($stage, self::STAGES, true);

        return $index === false ? null : (self::STAGES[$index + 1] ?? null);
    }

    private function step(string $stage): string
    {
        $current = $this->stageState($stage);
        $continuing = ($current['status'] ?? null) === 'running' && ! empty($current['bulk_operation_id']);
        $run = ($continuing && isset($current['run_id'])) ? ShopifySyncRun::find($current['run_id']) : null;
        $run ??= $this->recorder->open('initial', $stage);
        $file = $continuing && is_string($current['file'] ?? null) ? $current['file'] : null;
        $temporary = $continuing && (bool) ($current['file_temporary'] ?? false);

        try {
            $this->integrations->requireConnected();

            if ($stage === 'shipping') {
                $this->importShipping($run);

                return self::OUTCOME_COMPLETED;
            }

            if (! $continuing) {
                $operationId = $this->reusableOperation($current) ?? $this->startBulkOperation($stage);
                $current = $this->updateStage($stage, [
                    'status' => 'running',
                    'phase' => 'polling',
                    'total' => null,
                    'processed' => 0,
                    'failed' => 0,
                    'bulk_operation_id' => $operationId,
                    'run_id' => $run->id,
                    'poll_attempts' => 0,
                    'offset' => 0,
                    'file' => null,
                    'file_temporary' => false,
                    'error' => null,
                ])['stages'][$stage] ?? $current;
            }

            $operationId = (string) $current['bulk_operation_id'];
            $phase = $current['phase'] ?? 'polling';

            if ($phase !== 'mapping' || $file === null || ! is_file($file)) {
                $operation = $this->pollBulkOperation($operationId);
                $status = strtoupper((string) ($operation['status'] ?? ''));
                $total = isset($operation['objectCount']) ? (int) $operation['objectCount'] : null;

                if (in_array($status, self::PENDING_STATUSES, true)) {
                    $polls = (int) ($current['poll_attempts'] ?? 0) + 1;

                    if ($polls > self::MAX_POLLS) {
                        throw new RuntimeException("Shopify bulk operation {$operationId} is still {$status} after ".self::MAX_POLLS.' polls; giving up.');
                    }

                    $this->updateStage($stage, ['phase' => 'polling', 'total' => $total, 'poll_attempts' => $polls]);

                    return self::OUTCOME_POLLING;
                }

                if ($status !== 'COMPLETED') {
                    throw new RuntimeException(trim("Shopify bulk operation {$status} ".($operation['errorCode'] ?? '')));
                }

                $url = $operation['url'] ?? null;

                if (! is_string($url) || $url === '') {
                    return $this->complete($stage, $run, SyncRunSummary::fromRun($run), null, false); // No objects: no file.
                }

                [$file, $temporary, $downloaded] = $this->download($stage, $url, $operationId, $phase === 'downloading');

                if (! $downloaded) {
                    $this->updateStage($stage, ['phase' => 'downloading', 'total' => $total, 'file' => $file, 'file_temporary' => $temporary]);

                    return self::OUTCOME_WORKING;
                }

                $this->updateStage($stage, ['phase' => 'mapping', 'total' => $total, 'file' => $file, 'file_temporary' => $temporary]);
            }

            [$summary, $finished] = $this->mapFile($stage, $file, (int) ($this->stageState($stage)['offset'] ?? 0), $run);

            return $finished
                ? $this->complete($stage, $run, $summary, $file, $temporary)
                : self::OUTCOME_WORKING;
        } catch (Throwable $e) {
            $message = $this->describe($e);
            $run->refresh();
            $this->recorder->recordError($run, $stage, $message);
            $this->recorder->close($run, SyncRunSummary::fromRun($run), 'failed');
            // bulk_operation_id is kept: resume polls it before starting a new export.
            $this->updateStage($stage, ['status' => 'failed', 'phase' => null, 'offset' => null, 'file' => null, 'file_temporary' => false, 'error' => Str::limit($message, 250)]);

            if ($temporary && $file !== null) {
                File::delete($file);
            }

            throw $e;
        }
    }

    private function complete(string $stage, ShopifySyncRun $run, SyncRunSummary $summary, ?string $file, bool $temporary): string
    {
        $this->recorder->close($run, $summary, 'completed');
        $this->updateStage($stage, [
            'status' => 'completed',
            'phase' => null,
            'processed' => $summary->processed,
            'failed' => $summary->failed,
            'offset' => null,
            'file' => null,
            'file_temporary' => false,
        ]);

        if ($temporary && $file !== null) {
            File::delete($file);
        }

        return self::OUTCOME_COMPLETED;
    }

    private function importShipping(ShopifySyncRun $run): void
    {
        $this->updateStage('shipping', ['status' => 'running', 'total' => null, 'processed' => 0, 'failed' => 0, 'run_id' => $run->id]);

        $zones = [];
        $cursor = null;
        $first = true;

        do {
            $page = $this->client->query(SyncQueries::shipping(), ['cursor' => $cursor])['deliveryProfiles'] ?? null;

            if (! is_array($page)) {
                // Never wipe the local zones because a response came back empty.
                if ($first) {
                    throw new RuntimeException('Shopify returned no deliveryProfiles.');
                }
                break;
            }

            foreach (Payload::list($page) as $profile) {
                foreach ($profile['profileLocationGroups'] ?? [] as $group) {
                    array_push($zones, ...$this->allZones($profile, $group));
                }
            }

            $first = false;
            $cursor = $this->nextCursor($page);
        } while ($cursor !== null);

        foreach ($zones as $zone) {
            // replaceAll deletes rates missing from the list: never feed it a truncated one.
            if ($zone['methodDefinitions']['pageInfo']['hasNextPage'] ?? false) {
                throw new RuntimeException('Shipping zone "'.($zone['zone']['name'] ?? '?').'" has more than 10 shipping methods; not importing a partial list.');
            }
        }

        $count = $this->zones->replaceAll($zones);

        $this->recorder->close($run, SyncRunSummary::empty()->withProcessed($count), 'completed');
        $this->updateStage('shipping', ['status' => 'completed', 'total' => $count, 'processed' => $count]);
    }

    /** @return list<array<string, mixed>> every zone of one profile location group, following zone pages */
    private function allZones(array $profile, array $group): array
    {
        $connection = $group['locationGroupZones'] ?? [];
        $zones = Payload::list($connection);
        $cursor = $this->nextCursor($connection);

        while ($cursor !== null) {
            $profileId = $profile['id'] ?? null;
            $groupId = $group['locationGroup']['id'] ?? null;

            if (! is_string($profileId) || ! is_string($groupId)) {
                throw new RuntimeException('Cannot page shipping zones without the profile and location group ids.');
            }

            $data = $this->client->query(SyncQueries::shippingZones(), ['profileId' => $profileId, 'locationGroupId' => $groupId, 'cursor' => $cursor]);
            $more = $data['deliveryProfile']['profileLocationGroups'][0]['locationGroupZones'] ?? null;

            if (! is_array($more)) {
                throw new RuntimeException('Shopify returned no further shipping zones.');
            }

            array_push($zones, ...Payload::list($more));
            $cursor = $this->nextCursor($more);
        }

        return $zones;
    }

    private function nextCursor(array $connection): ?string
    {
        return ($connection['pageInfo']['hasNextPage'] ?? false) && is_string($connection['pageInfo']['endCursor'] ?? null)
            ? $connection['pageInfo']['endCursor']
            : null;
    }

    /** A failed stage's operation is reused while it is still pending or recently COMPLETED. */
    private function reusableOperation(array $current): ?string
    {
        $operationId = $current['bulk_operation_id'] ?? null;

        if (($current['status'] ?? null) !== 'failed' || ! is_string($operationId) || $operationId === '') {
            return null;
        }

        try {
            $operation = $this->pollBulkOperation($operationId);
        } catch (ShopifyException $e) {
            throw $e;
        } catch (RuntimeException) {
            return null; // Unknown to Shopify now.
        }

        $status = strtoupper((string) ($operation['status'] ?? ''));

        if (in_array($status, self::PENDING_STATUSES, true)) {
            return $operationId;
        }

        if ($status !== 'COMPLETED') {
            return null;
        }

        try {
            $completedAt = is_string($operation['completedAt'] ?? null) ? CarbonImmutable::parse($operation['completedAt']) : null;
        } catch (Throwable) {
            $completedAt = null;
        }

        return $completedAt === null || $completedAt->greaterThan(now()->subDays(self::REUSE_MAX_AGE_DAYS)) ? $operationId : null;
    }

    private function startBulkOperation(string $stage): string
    {
        $since = null;

        if ($stage === 'orders') {
            $since = $this->integrations->current()?->import_state['orders_since'] ?? $this->defaultOrdersSince();
            $this->updateStage($stage, ['orders_since' => $since]);
        }

        $data = $this->client->mutate(SyncQueries::bulkRun($stage, $since), [], 'bulkOperationRunQuery');
        $id = $data['bulkOperationRunQuery']['bulkOperation']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Shopify did not return a bulk operation id.');
        }

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

    /**
     * `file://` URLs (fake driver) are read in place. Anything else is streamed to
     * storage/app/shopify/{stage}-{operationId}.jsonl within the job time budget;
     * a partial file is continued with an HTTP Range request by the next job.
     *
     * @return array{0: string, 1: bool, 2: bool} path, delete-after-import, fully downloaded
     */
    private function download(string $stage, string $url, string $operationId, bool $resume): array
    {
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, strlen('file://'));

            return [preg_match('~^/[A-Za-z]:[/\\\\]~', $path) === 1 ? substr($path, 1) : $path, false, true];
        }

        $directory = storage_path('app/shopify');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$stage.'-'.(Payload::id($operationId) ?? md5($operationId)).'.jsonl';
        $from = $resume && is_file($path) ? (int) filesize($path) : 0;
        $out = null;
        $body = null;

        try {
            $request = Http::withOptions(['stream' => true])->timeout(75);
            $response = ($from > 0 ? $request->withHeaders(['Range' => "bytes={$from}-"]) : $request)->get($url);

            if ($from > 0 && $response->status() === 416) {
                return [$path, true, true]; // Nothing left to fetch.
            }

            $response->throw();

            if ($from > 0 && $response->status() !== 206) {
                $from = 0; // Range ignored: start over.
            }

            $out = @fopen($path, $from > 0 ? 'ab' : 'wb');

            if ($out === false) {
                $out = null;

                throw new RuntimeException("Cannot write bulk JSONL file: {$path}");
            }

            $body = $response->toPsrResponse()->getBody();

            while (! $body->eof()) {
                if (fwrite($out, $body->read(1 << 16)) === false) {
                    throw new RuntimeException("Cannot write bulk JSONL file: {$path}");
                }

                if ($this->outOfTime()) {
                    return [$path, true, $body->eof()];
                }
            }

            return [$path, true, true];
        } catch (Throwable $e) {
            if ($out !== null) {
                fclose($out);
                $out = null;
            }
            File::delete($path);

            throw $e;
        } finally {
            if ($out !== null) {
                fclose($out);
            }
            $body?->close();
        }
    }

    /**
     * Maps parents from $offset until EOF, the chunk budget or the time budget.
     *
     * @return array{0: SyncRunSummary, 1: bool} running totals and whether EOF was reached
     */
    private function mapFile(string $stage, string $path, int $offset, ShopifySyncRun $run): array
    {
        $summary = SyncRunSummary::fromRun($run);
        $chunkSize = max(1, (int) config('crm.shopify.import_chunk_size', self::CHUNK));
        $maxChunks = max(1, (int) config('crm.shopify.import_chunks_per_job', self::CHUNKS_PER_JOB));
        $buffer = [];
        $end = $offset;
        $chunks = 0;
        $orders = $this->rows->orders();
        $orders->beginBulk();

        try {
            foreach ($this->reader->objects($path, $offset) as [$row, , $next]) {
                $buffer[] = $row;

                if ($next === null) {
                    continue; // Malformed-line marker: not a resume point.
                }

                $end = $next;

                if (count($buffer) < $chunkSize) {
                    continue;
                }

                $summary = $this->mapChunk($stage, $buffer, $run, $summary, $end);
                $buffer = [];

                if (++$chunks >= $maxChunks || $this->outOfTime()) {
                    return [$summary, false];
                }
            }

            if ($buffer !== []) {
                $summary = $this->mapChunk($stage, $buffer, $run, $summary, $end);
            }
        } finally {
            $orders->endBulk();
        }

        return [$summary, true];
    }

    /**
     * One transaction per chunk, one savepoint per row: a failing row rolls back
     * alone, is recorded, and the chunk goes on. The run counters and the resume
     * offset commit with the chunk, so a crash never double-counts or skips rows.
     * Customer flags are recomputed once per touched customer after the commit.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function mapChunk(string $stage, array $rows, ShopifySyncRun $run, SyncRunSummary $summary, int $offset): SyncRunSummary
    {
        $state = DB::transaction(function () use ($stage, $rows, $run, $offset, &$summary) {
            foreach ($rows as $row) {
                if (isset($row['__malformed'])) {
                    $summary = $summary->withFailure();
                    $this->recorder->recordError($run, 'jsonl', (string) $row['__malformed']);

                    continue;
                }

                try {
                    $result = DB::transaction(fn () => $this->rows->map($stage, $row));
                    $summary = $summary->withResult($result);
                } catch (Throwable $e) {
                    $summary = $summary->withFailure();
                    $this->recorder->recordError($run, (string) ($row['id'] ?? '?'), $e->getMessage());
                }
            }

            $this->recorder->progress($run, $summary);

            return $this->writeStage($stage, ['processed' => $summary->processed, 'failed' => $summary->failed, 'offset' => $offset]);
        });

        $this->rows->orders()->flushDeferredFlags();

        if ($state !== null) {
            SafeBroadcast::send(new IntegrationProgress($state));
        }

        return $summary;
    }

    /** @return array<string, mixed> the new import_state ([] without an integration) */
    private function updateStage(string $stage, array $changes): array
    {
        $state = $this->writeStage($stage, $changes);

        if ($state === null) {
            return [];
        }

        SafeBroadcast::send(new IntegrationProgress($state));

        return $state;
    }

    /** Row-locked read-modify-write of one stage, so concurrent writers never lose each other's changes. */
    private function writeStage(string $stage, array $changes): ?array
    {
        return DB::transaction(function () use ($stage, $changes) {
            $integration = $this->lockedIntegration();

            if ($integration === null) {
                return null;
            }

            $state = $integration->import_state ?? [];

            if (array_key_exists('orders_since', $changes)) {
                $state['orders_since'] ??= $changes['orders_since'];
                unset($changes['orders_since']);
            }

            foreach (self::STAGES as $name) {
                $state['stages'][$name] = array_merge(self::freshStage(), $state['stages'][$name] ?? []);
            }

            $state['stages'][$stage] = array_merge($state['stages'][$stage], $changes, ['updated_at' => now()->toIso8601String()]);

            $integration->forceFill(['import_state' => $state])->save();

            return $state;
        });
    }

    private function lockedIntegration(): ?ShopifyIntegration
    {
        return ShopifyIntegration::query()->oldest('id')->lockForUpdate()->first();
    }

    /** @return array<string, mixed> */
    private function stageState(string $stage): array
    {
        return $this->integrations->current()?->import_state['stages'][$stage] ?? [];
    }

    private function isActive(array $stage): bool
    {
        if (($stage['status'] ?? null) !== 'running' || ! is_string($stage['updated_at'] ?? null)) {
            return false;
        }

        try {
            return CarbonImmutable::parse($stage['updated_at'])->greaterThan(now()->subMinutes(self::ACTIVE_MINUTES));
        } catch (Throwable) {
            return false;
        }
    }

    private function outOfTime(): bool
    {
        return microtime(true) - $this->startedAt >= (float) config('crm.shopify.import_job_seconds', 45);
    }

    private function describe(Throwable $e): string
    {
        $message = $e->getMessage();

        if ($e instanceof ShopifyException && $e->userErrors !== []) {
            $message .= ' userErrors: '.json_encode($e->userErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $message;
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
