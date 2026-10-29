<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->tmpDisk = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'foto-demo-test-' . uniqid();
    File::ensureDirectoryExists($this->tmpDisk);
    config(['photos.disk' => 'demo_test']);
    config(['filesystems.disks.demo_test' => [
        'driver' => 'local',
        'root' => $this->tmpDisk,
    ]]);
});

afterEach(function () {
    if (File::isDirectory($this->tmpDisk)) {
        File::deleteDirectory($this->tmpDisk);
    }
});

test('media:demo crea cartelle demo e foto con GD', function () {
    $this->artisan('media:demo --photos=2')
        ->assertSuccessful();

    expect(File::isDirectory($this->tmpDisk . '/demo-anna'))->toBeTrue()
        ->and(File::isDirectory($this->tmpDisk . '/demo-luca'))->toBeTrue()
        ->and(File::exists($this->tmpDisk . '/demo-anna/autore.md'))->toBeTrue()
        ->and(File::exists($this->tmpDisk . '/demo-luca/autore.md'))->toBeTrue();

    $annaFiles = File::files($this->tmpDisk . '/demo-anna/2025 09 20 - corteo per gaza');
    expect(count($annaFiles))->toBe(2);
});

test('media:demo --clean rimuove le cartelle demo-*', function () {
    $this->artisan('media:demo --photos=1')->assertSuccessful();

    expect(File::isDirectory($this->tmpDisk . '/demo-anna'))->toBeTrue();

    $this->artisan('media:demo --clean')->assertSuccessful();

    expect(File::isDirectory($this->tmpDisk . '/demo-anna'))->toBeFalse()
        ->and(File::isDirectory($this->tmpDisk . '/demo-luca'))->toBeFalse();
});

