<?php

use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Track;
use App\Support\GallerySelection;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->authorA = Author::create([
        'folder' => 'anna',
        'slug' => 'anna',
        'name' => 'Anna',
    ]);

    $this->authorB = Author::create([
        'folder' => 'luca',
        'slug' => 'luca',
        'name' => 'Luca',
    ]);

    $this->event1 = Event::create([
        'date' => '2025-09-20',
        'title' => 'Corteo per Gaza',
    ]);

    $this->event2 = Event::create([
        'date' => '2025-10-15',
        'title' => 'Assemblea Studentesca',
    ]);

    $this->galleryA1 = Gallery::create([
        'author_id' => $this->authorA->id,
        'event_id' => $this->event1->id,
        'folder' => '2025 09 20 - corteo per gaza',
    ]);

    $this->galleryB1 = Gallery::create([
        'author_id' => $this->authorB->id,
        'event_id' => $this->event1->id,
        'folder' => '2025 09 20 - Corteo per Gaza',
    ]);

    $this->galleryA2 = Gallery::create([
        'author_id' => $this->authorA->id,
        'event_id' => $this->event2->id,
        'folder' => '2025 10 15 - assemblea studentesca',
    ]);
});

test('photosQuery rispetta ordine canonico con data scatto e null in fondo', function () {
    // Foto di Luca scattata prima
    $p1 = Photo::create([
        'gallery_id' => $this->galleryB1->id,
        'filename' => 'IMG_0001.JPG',
        'size' => 1000,
        'mtime' => 1,
        'taken_at' => '2025-09-20 10:00:00',
    ]);

    // Foto di Anna scattata dopo
    $p2 = Photo::create([
        'gallery_id' => $this->galleryA1->id,
        'filename' => 'IMG_0002.JPG',
        'size' => 1000,
        'mtime' => 1,
        'taken_at' => '2025-09-20 11:00:00',
    ]);

    // Foto senza taken_at (va in fondo)
    $p3 = Photo::create([
        'gallery_id' => $this->galleryA1->id,
        'filename' => 'IMG_0000.JPG',
        'size' => 1000,
        'mtime' => 1,
        'taken_at' => null,
    ]);

    $selection = GallerySelection::forEvent($this->event1);
    $photos = $selection->photos();

    expect($photos->pluck('id')->all())->toBe([$p1->id, $p2->id, $p3->id]);
});

test('normalizzazione elimina duplicati e ordina', function () {
    $selection = GallerySelection::make(['2025-10-15', '2025-09-20', '2025-09-20'], ['luca', 'anna', 'anna']);

    expect($selection->dates)->toBe(['2025-09-20', '2025-10-15'])
        ->and($selection->authors)->toBe(['anna', 'luca']);
});

test('fromRequest valida i parametri e gestisce abort', function () {
    $request = Request::create('/galleria', 'GET', [
        'eventi' => ['2025-09-20'],
        'autori' => ['anna'],
    ]);

    $selection = GallerySelection::fromRequest($request);
    expect($selection->dates)->toBe(['2025-09-20'])
        ->and($selection->authors)->toBe(['anna']);

    $invalidRequest = Request::create('/galleria', 'GET', [
        'eventi' => ['data-non-valida'],
    ]);

    expect(fn () => GallerySelection::fromRequest($invalidRequest, 422))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('title genera il titolo appropriato per ciascun caso', function () {
    // 1 data, 0 autori
    $s1 = GallerySelection::forEvent($this->event1);
    expect($s1->title())->toBe('Corteo per Gaza');

    // 1 data, 1 autore
    $s2 = GallerySelection::forEvent($this->event1, ['anna']);
    expect($s2->title())->toBe('Corteo per Gaza — Anna');

    // 1 data, 2 autori
    $s2b = GallerySelection::forEvent($this->event1, ['anna', 'luca']);
    expect($s2b->title())->toBe('Corteo per Gaza — Anna, Luca');

    // 1 autore, 0 date
    $s3 = GallerySelection::forAuthor($this->authorA);
    expect($s3->title())->toBe('Foto di Anna');

    // 1 autore, 2 date
    $s4 = GallerySelection::forAuthor($this->authorA, ['2025-09-20', '2025-10-15']);
    expect($s4->title())->toBe('Anna — 2 eventi');

    // altro
    $s5 = GallerySelection::make(['2025-09-20', '2025-10-15'], ['anna', 'luca']);
    expect($s5->title())->toBe('Selezione di foto');
});

test('zipFileName restituisce il nome coerente', function () {
    expect(GallerySelection::forEvent($this->event1)->zipFileName())->toBe('foto-2025-09-20.zip')
        ->and(GallerySelection::forAuthor($this->authorA)->zipFileName())->toBe('foto-anna.zip')
        ->and(GallerySelection::make(['2025-09-20', '2025-10-15'])->zipFileName())->toBe('foto.zip');
});

test('totalBytes e formattedSize', function () {
    Photo::create([
        'gallery_id' => $this->galleryA1->id,
        'filename' => 'F1.JPG',
        'size' => 1024 * 1024 * 5, // 5 MB
        'mtime' => 1,
    ]);

    $selection = GallerySelection::forEvent($this->event1);
    expect($selection->totalBytes())->toBe(1024 * 1024 * 5)
        ->and($selection->formattedSize())->toContain('MB');
});

