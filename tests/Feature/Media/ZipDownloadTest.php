<?php

use App\Services\Media\MediaSynchronizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->root = makeMediaRoot();
    Queue::fake();

    putImage("{$this->root}/anna/2025 09 20 - corteo (gaza)/a.jpg");
    putImage("{$this->root}/bruno/2025 09 20 - corteo (gaza)/b.jpg");
    putImage("{$this->root}/anna/2025 10 01 - presidio/c.jpg");
    app(MediaSynchronizer::class)->run();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** @return list<string> nomi (ordinati) delle voci dello ZIP restituito dalla risposta */
function zipEntries($response): array
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    unlink($path);
    sort($names);

    return $names;
}

it('risponde 422 senza parametri', function () {
    $this->getJson('/download/zip')->assertStatus(422);
});

it('risponde 422 con una data non valida', function () {
    $this->getJson('/download/zip?eventi[]=ieri')->assertStatus(422);
});

it('risponde 404 se la selezione non trova foto', function () {
    $this->get('/download/zip?eventi[]=2020-01-01')->assertNotFound();
});

it('scarica uno ZIP con tutte le foto di un evento, di tutti gli autori', function () {
    $response = $this->get(route('download.zip', ['eventi' => ['2025-09-20']]));

    $response->assertOk()->assertHeader('Content-Type', 'application/zip');

    expect(zipEntries($response))->toBe([
        '2025-09-20 corteo (gaza)/anna/a.jpg',
        '2025-09-20 corteo (gaza)/bruno/b.jpg',
    ]);
});

it('scarica solo gli autori scelti di un evento', function () {
    $response = $this->get(route('download.zip', ['eventi' => ['2025-09-20'], 'autori' => ['bruno']]));

    expect(zipEntries($response))->toBe(['2025-09-20 corteo (gaza)/bruno/b.jpg']);
});

it('scarica più eventi di uno stesso autore', function () {
    $response = $this->get(route('download.zip', ['autori' => ['anna']]));

    expect(zipEntries($response))->toBe([
        '2025-09-20 corteo (gaza)/anna/a.jpg',
        '2025-10-01 presidio/anna/c.jpg',
    ]);
});
