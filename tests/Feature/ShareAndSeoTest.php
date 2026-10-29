<?php

use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;

beforeEach(function () {
    $this->author = Author::create([
        'folder' => 'anna',
        'slug' => 'anna',
        'name' => 'Anna',
    ]);

    $this->event = Event::create([
        'date' => '2025-09-20',
        'title' => 'Corteo per Gaza',
    ]);

    $this->gallery = Gallery::create([
        'author_id' => $this->author->id,
        'event_id' => $this->event->id,
        'folder' => '2025 09 20 - gaza',
    ]);

    $this->photo = Photo::create([
        'gallery_id' => $this->gallery->id,
        'filename' => 'P1.JPG',
        'size' => 100,
        'mtime' => 1,
    ]);
});

test('la pagina ha meta robots noindex e header X-Robots-Tag per default', function () {
    config(['photos.indexable' => false]);

    $response = $this->get('/eventi/2025-09-20');

    $response->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('/sitemap.xml restituisce 404 se indexable è false', function () {
    config(['photos.indexable' => false]);

    $response = $this->get('/sitemap.xml');
    $response->assertNotFound();
});

test('/sitemap.xml restituisce XML valido se indexable è true', function () {
    config(['photos.indexable' => true]);

    $response = $this->get('/sitemap.xml');
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<urlset', false)
        ->assertSee('2025-09-20')
        ->assertSee('anna');
});

test('i proxy fidati gestiscono correttamente gli header X-Forwarded-Proto e X-Forwarded-Host', function () {
    $response = $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'foto.mandich.dev',
    ])->get('/');

    $response->assertOk();
    expect(url('/'))->toBe('https://foto.mandich.dev');
});

