<?php

use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->author = Author::create([
        'folder' => 'ricky',
        'slug' => 'ricky',
        'name' => 'Ricky',
    ]);
});

test('la home mostra stato vuoto se non ci sono eventi', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Non ci sono ancora eventi.');
});

test('gli eventi senza foto non compaiono in home', function () {
    Event::create([
        'date' => '2025-01-01',
        'title' => 'Evento Senza Foto',
    ]);

    $response = $this->get('/');
    $response->assertDontSee('Evento Senza Foto');
});

test('gli eventi compaiono in ordine di data decrescente con titolo e copertina', function () {
    $e1 = Event::create(['date' => '2025-05-10', 'title' => 'Primo Evento']);
    $e2 = Event::create(['date' => '2025-08-20', 'title' => 'Secondo Evento']);

    $g1 = Gallery::create(['author_id' => $this->author->id, 'event_id' => $e1->id, 'folder' => '2025 05 10']);
    $g2 = Gallery::create(['author_id' => $this->author->id, 'event_id' => $e2->id, 'folder' => '2025 08 20']);

    Photo::create(['gallery_id' => $g1->id, 'filename' => 'F1.JPG', 'size' => 100, 'mtime' => 1]);
    Photo::create(['gallery_id' => $g2->id, 'filename' => 'F2.JPG', 'size' => 100, 'mtime' => 1]);

    $response = $this->get('/');
    $content = $response->getContent();

    $pos2 = strpos($content, 'Secondo Evento');
    $pos1 = strpos($content, 'Primo Evento');

    expect($pos2)->toBeLessThan($pos1);
});

test('paginazione a 12 eventi', function () {
    for ($i = 1; $i <= 13; $i++) {
        $date = sprintf('2025-01-%02d', $i);
        $e = Event::create(['date' => $date, 'title' => "Evento $i"]);
        $g = Gallery::create(['author_id' => $this->author->id, 'event_id' => $e->id, 'folder' => $date]);
        Photo::create(['gallery_id' => $g->id, 'filename' => 'F.JPG', 'size' => 100, 'mtime' => 1]);
    }

    $responseP1 = $this->get('/');
    $responseP1->assertOk()
        ->assertSee('Evento 13')
        ->assertDontSee('Evento 1<', false); // il primo in ordine cronologico è nella pag 2

    $responseP2 = $this->get('/?page=2');
    $responseP2->assertOk()
        ->assertSee('Evento 1');
});

