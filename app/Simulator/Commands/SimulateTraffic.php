<?php

namespace App\Simulator\Commands;

use App\Simulator\Simulator;
use Illuminate\Console\Command;

/**
 * Live/manual demo traffic generator (spec §10). Forces the queue to run
 * inline for the duration of the burst so the reported throughput reflects
 * full ingestion (webhook -> inbox/comment ingestion -> bot decision), not
 * just how fast jobs can be enqueued.
 *
 * Broadcasting is left on its real configured driver by default, so a
 * running Reverb server pushes live updates to the UI exactly like a real
 * request would. A one-off, sub-second TCP preflight against the broadcaster
 * host:port decides whether that's actually reachable: unreachable (e.g. no
 * Reverb server running) falls back to a non-broadcasting driver for the run
 * automatically, so hundreds of events don't each pay a multi-second
 * per-request connection-failure cost — `Simulator::burst()` itself also
 * swallows any broadcast failure that still slips through, so this command
 * never crashes. Pass --no-broadcast to force that fallback outright instead
 * of probing.
 */
class SimulateTraffic extends Command
{
    protected $signature = 'crm:simulate {--count=100} {--seconds=60} {--platforms=facebook,instagram,whatsapp,tiktok} {--no-broadcast : Disable broadcasting for this run instead of using the live driver}';

    protected $description = 'Fire simulated customer messages through the real webhook pipeline and report throughput';

    /** Drivers with nothing to preflight — they never make a network call. */
    private const NON_NETWORK_DRIVERS = ['null', 'log'];

    public function handle(Simulator $simulator): int
    {
        $count = max(0, (int) $this->option('count'));
        $seconds = max(0, (int) $this->option('seconds'));
        $platforms = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('platforms')))));

        if ($count === 0 || $platforms === []) {
            $this->warn('Nothing to simulate: --count must be > 0 and --platforms must not be empty.');

            return self::SUCCESS;
        }

        $originalQueue = config('queue.default');
        $originalBroadcast = config('broadcasting.default');
        $forcedOff = (bool) $this->option('no-broadcast');
        $autoFallback = ! $forcedOff && ! $this->broadcastLikelyReachable();

        config(array_filter([
            'queue.default' => 'sync',
            // 'null' (not 'log'): hundreds of events must not flood laravel.log with payloads.
            'broadcasting.default' => ($forcedOff || $autoFallback) ? 'null' : null,
        ], fn ($v) => $v !== null));

        if ($autoFallback) {
            $this->warn('Broadcaster ('.$originalBroadcast.") isn't reachable — disabling broadcasting for this run so it doesn't crawl through per-event connection failures. Data is unaffected; start Reverb and rerun to see it live.");
        }

        $start = microtime(true);

        try {
            $sent = $simulator->burst($count, $seconds, $platforms);
        } finally {
            config(['queue.default' => $originalQueue, 'broadcasting.default' => $originalBroadcast]);
        }

        $elapsed = microtime(true) - $start;
        $perSecond = $elapsed > 0 ? round($sent / $elapsed, 2) : (float) $sent;
        $avgMs = $sent > 0 ? round(($elapsed * 1000) / $sent, 2) : 0.0;

        $this->info(sprintf(
            'Simulated %d events across [%s] in %.2fs — %s events/sec, avg ingest %s ms/event.',
            $sent,
            implode(', ', $platforms),
            $elapsed,
            $perSecond,
            $avgMs,
        ));

        $broadcastFailures = $simulator->lastBurstBroadcastFailures();

        if ($broadcastFailures > 0) {
            $this->warn("{$broadcastFailures} event(s) ingested fine but couldn't broadcast live (e.g. Reverb not running) — data is unaffected. Pass --no-broadcast to silence this.");
        }

        return self::SUCCESS;
    }

    /**
     * A single sub-second TCP connect attempt against the currently
     * configured broadcaster's host:port — not a guarantee (the socket could
     * accept and the app-level auth still fail), just enough to avoid paying
     * a multi-second connection-refused/timeout cost on every one of
     * potentially hundreds of events when nothing is listening at all.
     */
    private function broadcastLikelyReachable(): bool
    {
        $driver = (string) config('broadcasting.default');

        if (in_array($driver, self::NON_NETWORK_DRIVERS, true)) {
            return true;
        }

        $options = config("broadcasting.connections.{$driver}.options", []);
        $host = $options['host'] ?? null;
        $port = $options['port'] ?? null;

        if (! $host || ! $port) {
            // Unknown shape (e.g. a custom/queue-based driver) — don't guess, just try for real.
            return true;
        }

        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
