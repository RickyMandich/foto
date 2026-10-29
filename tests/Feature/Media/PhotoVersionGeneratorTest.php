<?php

use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;
use App\Services\Media\PhotoVersionGenerator;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->root = makeMediaRoot();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function makePhoto(string $root, string $filename, int $width, int $height): Photo
{
    $author = Author::create(['folder' => 'anna', 'slug' => 'anna', 'name' => 'anna']);
    $event = Event::create(['date' => '2025-09-20']);
    $gallery = Gallery::create([
        'author_id' => $author->id,
        'event_id' => $event->id,
        'folder' => '2025 09 20 - corteo',
    ]);

    putImage("{$root}/anna/2025 09 20 - corteo/{$filename}", $width, $height);

    return Photo::create([
        'gallery_id' => $gallery->id,
        'filename' => $filename,
        'size' => 1,
        'mtime' => 1,
    ]);
}

it('genera la versione web e la miniatura rispettando il lato lungo massimo', function () {
    $photo = makePhoto($this->root, 'a.jpg', 2400, 1600);

    app(PhotoVersionGenerator::class)->generate($photo);

    [$webW, $webH] = getimagesize($photo->versionAbsolutePath('web'));
    [$thumbW, $thumbH] = getimagesize($photo->versionAbsolutePath('thumb'));

    expect(max($webW, $webH))->toBe(config('photos.versions.web.max_side'))
        ->and(max($thumbW, $thumbH))->toBe(config('photos.versions.thumb.max_side'))
        ->and($photo->fresh()->versions_ready)->toBeTrue()
        ->and($photo->versionAbsolutePath('web').'.tmp')->not->toBeFile();
});

it('non ingrandisce mai le immagini piccole', function () {
    $photo = makePhoto($this->root, 'piccola.jpg', 300, 200);

    app(PhotoVersionGenerator::class)->generate($photo);

    expect(getimagesize($photo->versionAbsolutePath('web'))[0])->toBe(300)
        ->and(getimagesize($photo->versionAbsolutePath('thumb'))[0])->toBe(300);
});

it('non fallisce se l\'originale è sparito dal disco', function () {
    $photo = makePhoto($this->root, 'a.jpg', 100, 100);
    File::delete($photo->absolutePath());

    app(PhotoVersionGenerator::class)->generate($photo);

    expect($photo->fresh()->versions_ready)->toBeFalse()
        ->and($photo->versionAbsolutePath('web'))->not->toBeFile();
});

it('usa le versioni negli URL solo quando sono pronte', function () {
    $photo = makePhoto($this->root, 'a b.jpg', 100, 100);
    $photo->load('gallery.author');

    expect($photo->url('thumb'))->toBe('/storage/anna/2025%2009%2020%20-%20corteo/a%20b.jpg');

    app(PhotoVersionGenerator::class)->generate($photo);

    expect($photo->fresh()->load('gallery.author')->url('thumb'))
        ->toBe("/storage/_derived/thumb/{$photo->id}.webp?v=1");
});
