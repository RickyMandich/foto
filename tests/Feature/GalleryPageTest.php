<?php

use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;

beforeEach(function () {
    $this->authorA = Author::create(['folder' => 'anna', 'slug' => 'anna', 'name' => 'Anna']);
    $this->authorB = Author::create(['folder' => 'luca', 'slug' => 'luca', 'name' => 'Luca']);

    $this->event = Event::create(['date' => '2025-09-20', 'title' => 'Corteo per Gaza']);
    $this->galleryA = Gallery::create(['author_id' => $this->authorA->id, 'event_id' => $this->event->id, 'folder' => '2025 09 20 - gaza']);
    $this->galleryB = Gallery::create(['author_id' => $this->authorB->id, 'event_id' => $this->event->id, 'folder' => '2025 09 20 - Gaza']);

    $this->photo1 = Photo::create(['gallery_id' => $this->galleryA->id, 'filename' => 'A1.JPG', 'size' => 1000, 'mtime' => 1]);
    $this->photo2 = Photo::create(['gallery_id' => $this->galleryB->id, 'filename' => 'B1.JPG', 'size' => 1000, 'mtime' => 1]);
});

test('/eventi/2025-09-20 risponde 200 con foto nella griglia e carosello', function () {
    $response = $this->get('/eventi/2025-09-20');

    $response->assertOk()
        ->assertSee('Corteo per Gaza')
        ->assertSee('A1.JPG')
        ->assertSee('B1.JPG');
});

test('/eventi/{data-inesistente} risponde 404', function () {
    $response = $this->get('/eventi/2025-01-01');
    $response->assertNotFound();
});

test('filtro ?autori[]=anna mostra solo le foto di Anna', function () {
    $response = $this->get('/eventi/2025-09-20?autori[]=anna');

    $response->assertOk()
        ->assertSee('A1.JPG')
        ->assertDontSee('B1.JPG');
});

test('selezione generica /galleria con date o autori', function () {
    $response = $this->get('/galleria?autori[]=anna&eventi[]=2025-09-20');

    $response->assertOk()
        ->assertSee('A1.JPG');

    $empty = $this->get('/galleria');
    $empty->assertNotFound();
});

test('payload JSON in #gallery-data valido e con start id corretto', function () {
    $response = $this->get('/eventi/2025-09-20?f='.$this->photo2->id);

    $response->assertOk();
    $content = $response->getContent();

    expect($content)->toContain('"start":1')
        ->toContain('"name":"B1.JPG"');
});

test('href del bottone zip corrisponde a zipUrl', function () {
    $response = $this->get('/eventi/2025-09-20');

    $response->assertOk()
        ->assertSee(route('download.zip', ['eventi' => ['2025-09-20']]), false);
});
