<?php

namespace App\Ads\Commands;

use App\Models\AdPlatformConnection;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Local tool: copies the newest valid Meta token from the ArenaReports database into a CRM Meta connection,
 * so the live driver can be checked against Arena's figures. Read-only on Arena (one SELECT). Arena stores
 * tokens encrypted with its own APP_KEY (legacy rows in plain text), so both are handled. Never prints the
 * token, only a masked form. Refuses to run in production.
 */
class ImportArenaTokenCommand extends Command
{
    public const CONNECTION_NAME = 'Meta — Arena token';

    protected $signature = 'ads:import-arena-token
        {--connection=arena : DB connection to read from (defined at runtime from Arena\'s .env unless it already exists)}
        {--database= : Arena database name (default: DB_DATABASE from Arena\'s .env)}
        {--arena-path=C:\xampp\htdocs\ArenaReports : ArenaReports folder (its .env gives the DB settings and APP_KEY)}
        {--client=Levoile : Arena client whose token to use (empty = any client)}';

    protected $description = 'Local only: import the Meta token from ArenaReports into a CRM Meta connection';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('ads:import-arena-token is a local tool and does not run in production.');

            return self::FAILURE;
        }

        $env = $this->arenaEnv();
        $connection = (string) $this->option('connection');
        $defined = config("database.connections.{$connection}") !== null;
        if (! $defined) {
            config(["database.connections.{$connection}" => [
                'driver' => 'mysql',
                'host' => $env['DB_HOST'] ?? '127.0.0.1',
                'port' => $env['DB_PORT'] ?? '3306',
                'database' => $this->option('database') ?: ($env['DB_DATABASE'] ?? 'arena_reports'),
                'username' => $env['DB_USERNAME'] ?? 'root',
                'password' => $env['DB_PASSWORD'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]]);
        }

        try {
            $row = $this->newestToken($connection);
        } catch (Throwable $e) {
            $this->error('Could not read Arena tokens: '.mb_substr($e->getMessage(), 0, 200));

            return self::FAILURE;
        } finally {
            if (! $defined) {
                DB::purge($connection);
            }
        }

        if ($row === null) {
            $this->error('No valid Meta (facebook) token found in Arena'.($this->option('client') ? ' for client «'.$this->option('client').'»' : '').'.');

            return self::FAILURE;
        }

        $token = $this->decrypt((string) $row->token_value, $env['APP_KEY'] ?? null);
        if ($token === null) {
            $this->error("The Arena token #{$row->id} is encrypted but could not be decrypted: check APP_KEY in Arena's .env (--arena-path).");

            return self::FAILURE;
        }
        if ($token === '') {
            $this->error('The Arena token is empty.');

            return self::FAILURE;
        }

        $c = AdPlatformConnection::updateOrCreate(
            ['platform' => 'meta', 'name' => self::CONNECTION_NAME],
            ['credentials' => ['access_token' => $token], 'status' => 'connected', 'last_error' => null],
        );

        $this->info("Imported Arena token #{$row->id} (".self::mask($token).") into connection #{$c->id} «".self::CONNECTION_NAME.'».');

        return self::SUCCESS;
    }

    public static function mask(string $token): string
    {
        return strlen($token) <= 10 ? str_repeat('*', strlen($token)) : substr($token, 0, 6).'…'.substr($token, -4);
    }

    /** @return array<string, string|null> */
    private function arenaEnv(): array
    {
        $file = rtrim((string) $this->option('arena-path'), '\\/').DIRECTORY_SEPARATOR.'.env';

        return is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
    }

    private function newestToken(string $connection): ?object
    {
        $client = trim((string) $this->option('client'));

        return DB::connection($connection)->table('api_tokens')
            ->select(['api_tokens.id', 'api_tokens.token_value'])
            ->whereIn('api_tokens.provider', ['facebook', 'meta'])
            ->where('api_tokens.is_valid', true)
            ->where(fn (Builder $q) => $q->whereNull('api_tokens.expires_at')->orWhere('api_tokens.expires_at', '>', now()))
            ->when($client !== '', fn (Builder $q) => $q->join('clients', 'clients.id', '=', 'api_tokens.client_id')->where('clients.name', $client))
            ->orderByDesc('api_tokens.updated_at')->orderByDesc('api_tokens.id')
            ->first();
    }

    /**
     * Arena's EncryptedWithFallback: encryptString with its APP_KEY, or legacy plain text. Null when the value is a
     * Laravel encrypted payload (base64 JSON, "eyJ...") that this key cannot open: never store ciphertext as a token.
     */
    private function decrypt(string $value, ?string $appKey): ?string
    {
        $looksEncrypted = str_starts_with($value, 'eyJ');
        if ($appKey === null || $appKey === '') {
            return $looksEncrypted ? null : $value;
        }
        $key = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;
        try {
            return (new Encrypter($key, 'AES-256-CBC'))->decryptString($value);
        } catch (Throwable) { // not encrypted with this key (legacy plain text), or an unusable key
            return $looksEncrypted ? null : $value;
        }
    }
}
