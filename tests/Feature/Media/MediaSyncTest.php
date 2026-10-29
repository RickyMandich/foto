<?php

use App\Jobs\GeneratePhotoVersionsJob;
use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Track;
use App\Services\Media\MediaSynchronizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->root = makeMediaRoot();
    $this->event = '2025 09 20 - corteo per gaza';
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** Simula la generazione delle versioni: marca le foto pronte e crea i file derivati. */
function pretendVersionsAreReady(): void
{
    foreach (Photo::all() as $photo) {
        foreach (array_keys(config('photos.versions')) as $version) {
            putFile($photo->versionAbsolutePath($version));
        }
        $photo->update(['versions_ready' => true]);
    }
}

function sync(bool $dryRun = false): MediaSynchronizer
{
    $synchronizer = app(MediaSynchronizer::class);
    $synchronizer->run(dryRun: $dryRun);

    return $synchronizer;
}

it('crea autori, eventi, gallerie, foto e tracce dal filesystem', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");
    putImage("{$this->root}/anna/{$this->event}/b.JPG");
    putFile("{$this->root}/anna/{$this->event}/brano.mp3");
    putFile("{$this->root}/anna/{$this->event}/note.txt"); // ignorato

    $sync = sync();

    expect(Author::count())->toBe(1)
        ->and(Author::first())->folder->toBe('anna')->slug->toBe('anna')->name->toBe('anna')
        ->and(Event::count())->toBe(1)
        ->and(Event::first())->date->toBe('2025-09-20')->title->toBe('corteo per gaza')
        ->and(Gallery::count())->toBe(1)
        ->and(Photo::count())->toBe(2)
        ->and(Track::count())->toBe(1)
        ->and($sync->warnings)->toBe([]);

    expect(Photo::where('filename', 'a.jpg')->first())->width->toBe(64)->height->toBe(48);

    Queue::assertPushed(GeneratePhotoVersionsJob::class, 2);
});

it('è idempotente: una seconda esecuzione senza modifiche non cambia nulla né mette in coda job', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");
    putFile("{$this->root}/anna/{$this->event}/brano.mp3");

    sync();
    pretendVersionsAreReady();
    $before = [Photo::first()->updated_at, Photo::count(), Track::count(), Gallery::count()];

    Queue::fake();
    sync();

    expect([Photo::first()->updated_at, Photo::count(), Track::count(), Gallery::count()])->toEqual($before);
    Queue::assertNothingPushed();
});

it('rimette in coda la foto se le versioni mancano su disco', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");

    sync();
    pretendVersionsAreReady();
    File::delete(Photo::first()->versionAbsolutePath('thumb'));

    Queue::fake();
    sync();

    Queue::assertPushed(GeneratePhotoVersionsJob::class, 1);
});

it('rigenera le versioni di una foto modificata', function () {
    $file = "{$this->root}/anna/{$this->event}/a.jpg";
    putImage($file);

    sync();
    pretendVersionsAreReady();

    putImage($file, 100, 80);
    touch($file, time() + 100);

    Queue::fake();
    $sync = sync();

    expect($sync->stats['photos_updated'])->toBe(1)
        ->and(Photo::first())->width->toBe(100)->versions_ready->toBeFalse();
    Queue::assertPushed(GeneratePhotoVersionsJob::class, 1);
});

it('toglie dal DB ciò che sparisce dal disco e cancella le versioni generate, mai gli originali', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");
    putImage("{$this->root}/anna/{$this->event}/b.jpg");
    putImage("{$this->root}/bruno/2025 10 01 - presidio/c.jpg");
    putImage("{$this->root}/carla/2025 10 05 - assemblea/d.jpg");

    sync();
    pretendVersionsAreReady();

    $removedPhoto = Photo::where('filename', 'a.jpg')->first();
    $derived = $removedPhoto->versionAbsolutePath('web');
    expect($derived)->toBeFile();

    File::delete("{$this->root}/anna/{$this->event}/a.jpg");            // una foto
    File::deleteDirectory("{$this->root}/bruno/2025 10 01 - presidio"); // una galleria
    File::deleteDirectory("{$this->root}/carla");                       // un autore

    $sync = sync();

    expect(Photo::pluck('filename')->all())->toBe(['b.jpg'])
        ->and(Gallery::count())->toBe(1)
        ->and(Author::pluck('folder')->sort()->values()->all())->toBe(['anna', 'bruno'])
        ->and(Event::count())->toBe(1) // gli eventi senza gallerie sono eliminati
        ->and($sync->stats['photos_removed'])->toBe(3)
        ->and($derived)->not->toBeFile()
        ->and("{$this->root}/anna/{$this->event}/b.jpg")->toBeFile();
});

it('con --dry-run non scrive nulla e non mette in coda job', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");

    $sync = sync(dryRun: true);

    expect(Author::count())->toBe(0)->and(Photo::count())->toBe(0)
        ->and($sync->stats['photos_new'])->toBe(1);
    Queue::assertNothingPushed();
});

it('si rifiuta di sincronizzare uno storage senza autori se il DB ha dati, a meno di --allow-empty', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");
    sync();

    File::deleteDirectory("{$this->root}/anna");

    $this->artisan('media:sync')->assertFailed();
    expect(Photo::count())->toBe(1);

    $this->artisan('media:sync', ['--allow-empty' => true])->assertSuccessful();
    expect(Photo::count())->toBe(0)->and(Author::count())->toBe(0);
});

it('ignora le cartelle riservate (_derived) e nascoste', function () {
    putImage("{$this->root}/_derived/web/1.webp.jpg");
    putImage("{$this->root}/.git/x.jpg");
    putImage("{$this->root}/anna/{$this->event}/a.jpg");
    putImage("{$this->root}/anna/{$this->event}/.nascosta.jpg");

    sync();

    expect(Author::pluck('folder')->all())->toBe(['anna'])
        ->and(Photo::pluck('filename')->all())->toBe(['a.jpg']);
});

it('avvisa e salta le cartelle evento con nome non conforme o data non valida', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");
    putImage("{$this->root}/anna/vecchie foto/b.jpg");
    putImage("{$this->root}/anna/2025 13 45 - impossibile/c.jpg");

    $sync = sync();

    expect(Gallery::count())->toBe(1)->and($sync->warnings)->toHaveCount(2);
});

it('con due cartelle dello stesso autore nella stessa data tiene la prima e avvisa', function () {
    putImage("{$this->root}/anna/2025 09 20 - prima/a.jpg");
    putImage("{$this->root}/anna/2025 09 20 - seconda/b.jpg");

    $sync = sync();

    expect(Gallery::count())->toBe(1)
        ->and(Gallery::first()->folder)->toBe('2025 09 20 - prima')
        ->and($sync->warnings)->toHaveCount(1);
});

it('per la stessa data vince il titolo del primo autore in ordine alfabetico e avvisa se differiscono', function () {
    putImage("{$this->root}/bruno/2025 09 20 - Corteo per Gaza/b.jpg");
    putImage("{$this->root}/anna/2025 09 20 - corteo per gaza (blocco)/a.jpg");

    $sync = sync();

    expect(Event::count())->toBe(1)
        ->and(Gallery::count())->toBe(2)
        ->and(Event::first()->title)->toBe('corteo per gaza (blocco)')
        ->and($sync->warnings)->toHaveCount(1);
});

it('non avvisa se i titoli differiscono solo per maiuscole o spazi', function () {
    putImage("{$this->root}/anna/2025 09 20 - corteo per gaza/a.jpg");
    putImage("{$this->root}/bruno/2025 09 20 - Corteo  per Gaza/b.jpg");

    expect(sync()->warnings)->toBe([]);
});

it('legge autore.md con nome, recapiti e bio, scartando i recapiti vuoti', function () {
    putFile("{$this->root}/anna/autore.md", "---\nnome: Anna Rossi\nrecapiti:\n  email: anna@example.com\n  instagram: ''\n---\n\nFotografa di strada.\n");
    putImage("{$this->root}/anna/{$this->event}/a.jpg");

    sync();

    $author = Author::first();
    expect($author->name)->toBe('Anna Rossi')
        ->and($author->contacts)->toBe(['email' => 'anna@example.com'])
        ->and($author->bio)->toBe('Fotografa di strada.')
        ->and(Photo::count())->toBe(1); // autore.md non è una foto
});

it('senza autore.md usa i valori predefiniti', function () {
    putImage("{$this->root}/anna/{$this->event}/a.jpg");

    sync();

    expect(Author::first())->name->toBe('anna')->bio->toBeNull()->contacts->toBeNull();
});

it('con il front matter di autore.md non valido avvisa e usa i valori predefiniti', function () {
    putFile("{$this->root}/anna/autore.md", "---\nnome: [rotto\n---\ntesto\n");
    putImage("{$this->root}/anna/{$this->event}/a.jpg");

    $sync = sync();

    expect(Author::first())->name->toBe('anna')
        ->and($sync->warnings)->toHaveCount(1);
});

it('segnala gli autori con slug duplicato e ne salta il secondo', function () {
    putImage("{$this->root}/Anna Rossi/{$this->event}/a.jpg");
    putImage("{$this->root}/anna-rossi/{$this->event}/b.jpg");

    $sync = sync();

    expect(Author::count())->toBe(1)->and($sync->warnings)->toHaveCount(1);
});
