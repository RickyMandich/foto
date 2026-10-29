<?php

use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;

beforeEach(function () {
    $this->authorA = Author::create([
        'folder' => 'anna',
        'slug' => 'anna',
        'name' => 'Anna',
        'bio' => 'Biografia di prova con [sito sicuro](https://example.com) e <script>alert("xss")</script>',
        'contacts' => [
            'instagram' => '@anna_foto',
            'sito' => 'https://anna.photos',
            'email' => 'anna@example.com',
            'telefono' => '+39 333 1234567',
        ],
    ]);

    $this->authorEmpty = Author::create([
        'folder' => 'senza-foto',
        'slug' => 'senza-foto',
        'name' => 'Nessuna Foto',
    ]);

    $this->event1 = Event::create(['date' => '2025-09-20', 'title' => 'Evento Uno']);
    $this->gallery1 = Gallery::create([
        'author_id' => $this->authorA->id,
        'event_id' => $this->event1->id,
        'folder' => '2025 09 20 - uno',
    ]);
    Photo::create(['gallery_id' => $this->gallery1->id, 'filename' => 'A1.JPG', 'size' => 100, 'mtime' => 1]);
});

test('/autori mostra solo autori con foto', function () {
    $response = $this->get('/autori');

    $response->assertOk()
        ->assertSee('Anna')
        ->assertDontSee('Nessuna Foto');
});

test('/autori/{slug} profilo e sicurezza XSS nel markdown', function () {
    $response = $this->get('/autori/anna');

    $response->assertOk()
        ->assertSee('Anna')
        ->assertSee('Instagram: @anna_foto')
        ->assertSee('Sito web: anna.photos')
        ->assertSee('Email: anna@example.com')
        ->assertDontSee('<script>alert', false);
});

test('/autori/{slug-senza-foto} restituisce 404', function () {
    $response = $this->get('/autori/senza-foto');
    $response->assertNotFound();
});

test('contactLinks scarta valori non validi o pericolosi', function () {
    $badAuthor = Author::create([
        'folder' => 'hacker',
        'slug' => 'hacker',
        'name' => 'Hacker',
        'contacts' => [
            'sito' => 'javascript:alert(1)',
            'email' => 'non-una-mail',
            'instagram' => 'non valido spazi',
        ],
    ]);

    expect($badAuthor->contactLinks())->toBeEmpty();
});

