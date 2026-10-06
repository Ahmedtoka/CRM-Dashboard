<?php

use App\Maintenance\BackupFailed;
use App\Maintenance\DatabaseBackup;
use App\Models\Tag;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/backups-'.getmypid().'-'.random_int(1000, 999999));
    config(['crm.fresh_start.backup_dir' => $this->dir]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('writes a gzip copy of the sqlite database that reads back to a database file', function () {
    Tag::create(['name' => 'vip']);

    $backup = app(DatabaseBackup::class)->take();

    expect($backup['path'])->toStartWith($this->dir)->toEndWith('.gz')
        ->and(filesize($backup['path']))->toBeGreaterThan(0)
        ->and($backup['bytes'])->toBe(filesize($backup['path']));
    $gz = gzopen($backup['path'], 'rb');
    $head = gzread($gz, 4096);
    gzclose($gz);
    expect($head)->toStartWith('-- SQLite dump')->toContain('CREATE TABLE');
});

it('fails when the mysqldump binary cannot run', function () {
    config(['crm.fresh_start.backup_driver' => 'mysqldump', 'crm.fresh_start.mysqldump_binary' => base_path('no-such-mysqldump-binary')]);

    expect(fn () => app(DatabaseBackup::class)->take())->toThrow(BackupFailed::class);
    expect(File::glob($this->dir.'/*.gz'))->toBe([]);
});

it('fails when the backup folder cannot be created', function () {
    File::ensureDirectoryExists(dirname($this->dir));
    file_put_contents($this->dir, 'a file where the folder should be');

    try {
        expect(fn () => app(DatabaseBackup::class)->take())->toThrow(BackupFailed::class);
    } finally {
        @unlink($this->dir);
    }
});

it('verifies a backup: rejects a missing, empty or non-gzip file', function () {
    File::ensureDirectoryExists($this->dir);
    $empty = $this->dir.'/empty.sql.gz';
    file_put_contents($empty, '');
    $plain = $this->dir.'/plain.sql.gz';
    file_put_contents($plain, 'not gzip at all');

    $verify = fn ($p) => app(DatabaseBackup::class)->verify($p);
    expect(fn () => $verify($this->dir.'/missing.sql.gz'))->toThrow(BackupFailed::class)
        ->and(fn () => $verify($empty))->toThrow(BackupFailed::class)
        ->and(fn () => $verify($plain))->toThrow(BackupFailed::class);
});
