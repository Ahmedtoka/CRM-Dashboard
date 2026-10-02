<?php

use App\Ads\Commands\ImportArenaTokenCommand;
use App\Models\AdPlatformConnection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A fake Arena: an sqlite `arena` connection with clients + api_tokens, and a folder holding its .env (APP_KEY). */
beforeEach(function () {
    config(['database.connections.arena' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    Schema::connection('arena')->create('clients', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::connection('arena')->create('api_tokens', function ($t) {
        $t->id();
        $t->unsignedBigInteger('client_id');
        $t->string('provider');
        $t->text('token_value');
        $t->timestamp('expires_at')->nullable();
        $t->boolean('is_valid')->default(true);
        $t->timestamps();
    });
    DB::connection('arena')->table('clients')->insert([['id' => 1, 'name' => 'Levoile'], ['id' => 2, 'name' => 'Pistage']]);

    $this->key = Encrypter::generateKey('AES-256-CBC');
    $this->arenaPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arena-'.uniqid();
    mkdir($this->arenaPath);
    file_put_contents($this->arenaPath.'/.env', "APP_NAME=Arena\nAPP_KEY=base64:".base64_encode($this->key)."\nDB_DATABASE=report\n");
});

afterEach(function () {
    @unlink($this->arenaPath.'/.env');
    @rmdir($this->arenaPath);
    DB::purge('arena');
});

function arenaToken(array $row): void
{
    DB::connection('arena')->table('api_tokens')->insert($row + ['is_valid' => true, 'expires_at' => null, 'created_at' => now(), 'updated_at' => now()]);
}

it('imports the newest valid Levoile token, decrypted with Arena key, and prints only a masked token', function () {
    $enc = new Encrypter($this->key, 'AES-256-CBC');
    $token = 'EAAGreal'.str_repeat('x', 40).'9876';
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => $enc->encryptString('EAAOLDER-token-0000'), 'updated_at' => now()->subDays(9)]);
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => $enc->encryptString($token), 'updated_at' => now()->subDays(2)]);
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => 'EAAinvalid-newest-1111', 'is_valid' => false, 'updated_at' => now()]);
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => 'EAAexpired-newest-2222', 'expires_at' => now()->subDay(), 'updated_at' => now()]);
    arenaToken(['client_id' => 1, 'provider' => 'shopify', 'token_value' => 'shpat_newest', 'updated_at' => now()]);
    arenaToken(['client_id' => 2, 'provider' => 'facebook', 'token_value' => 'EAAother-client-3333', 'updated_at' => now()]);

    $this->artisan('ads:import-arena-token', ['--arena-path' => $this->arenaPath])
        ->expectsOutputToContain('EAAGre…9876')
        ->doesntExpectOutputToContain($token)
        ->assertSuccessful();

    $c = AdPlatformConnection::where('platform', 'meta')->where('name', ImportArenaTokenCommand::CONNECTION_NAME)->sole();
    expect($c->credentials['access_token'])->toBe($token)->and($c->status)->toBe('connected');

    // Re-running updates the same connection.
    $this->artisan('ads:import-arena-token', ['--arena-path' => $this->arenaPath])->assertSuccessful();
    expect(AdPlatformConnection::count())->toBe(1);
});

it('falls back to a plain-text (legacy) token and can take any client', function () {
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => 'EAAlevoile-plain-4444', 'updated_at' => now()->subDay()]);
    arenaToken(['client_id' => 2, 'provider' => 'meta', 'token_value' => 'EAApistage-plain-5555', 'updated_at' => now()]);

    $this->artisan('ads:import-arena-token', ['--arena-path' => $this->arenaPath])->assertSuccessful();
    expect(AdPlatformConnection::sole()->credentials['access_token'])->toBe('EAAlevoile-plain-4444');

    $this->artisan('ads:import-arena-token', ['--arena-path' => $this->arenaPath, '--client' => ''])->assertSuccessful();
    expect(AdPlatformConnection::sole()->credentials['access_token'])->toBe('EAApistage-plain-5555');
});

it('fails cleanly when Arena has no usable token', function () {
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => 'EAAinvalid', 'is_valid' => false]);

    $this->artisan('ads:import-arena-token', ['--arena-path' => $this->arenaPath])->assertFailed();
    expect(AdPlatformConnection::count())->toBe(0);
});

it('refuses to run in production', function () {
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => 'EAAlevoile-plain-4444']);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('ads:import-arena-token', ['--arena-path' => $this->arenaPath])->assertFailed();
    expect(AdPlatformConnection::count())->toBe(0);
});

it('refuses to store an encrypted token it cannot decrypt', function () {
    $other = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'); // not Arena's key
    arenaToken(['client_id' => 1, 'provider' => 'facebook', 'token_value' => $other->encryptString('EAAsecret-token-7777')]);

    $this->artisan('ads:import-arena-token', ['--arena-path' => $this->arenaPath])
        ->expectsOutputToContain('could not be decrypted')
        ->assertFailed();
    expect(AdPlatformConnection::count())->toBe(0);
});
