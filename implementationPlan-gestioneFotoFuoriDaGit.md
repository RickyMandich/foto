# Implementation plan — gestione foto fuori da git (punto 1)

Stato: **da eseguire**. Contesto e decisioni: `C:\Users\RickyMandich\PROJECT\oldFoto\AnalyzeForRefactor.md` (d'ora in poi "l'analisi") e `oldFoto\SPECS.md` (vecchio sistema).
Quando il piano è completato va rinominato `implementationPlan-V-gestioneFotoFuoriDaGit.md`.

## Obiettivo e ambito

Far vivere foto e audio **fuori da git**, sul filesystem del server (`storage/app/public`), con questo flusso:

1. L'utente invia la cartella di un evento sul server con `rsync`.
2. Il comando `php artisan media:sync` scansiona il filesystem (source of truth) e allinea il database.
3. Per ogni foto un job genera due versioni leggere (web e miniatura) accanto all'originale.
4. nginx serve originali e versioni; gli originali si scaricano singolarmente o in ZIP (streaming).

**Fuori ambito** (altri punti dell'analisi): pagine pubbliche di galleria e filtri per autore/evento (punti 2-3), pagina autore (5), scelta definitiva dello stack (6), cambio DNS di `foto.mandich.dev`. Il punto 1 si distribuisce su un host di prova (`foto-test.mandich.dev`, come `unlimiteddb-test`) senza toccare il sito attuale.

## Decisioni di riferimento

- Struttura su disco: `storage/app/public/<autore>/<AAAA MM GG - descrizione>/{foto, audio}` e `<autore>/autore.md` (vedi analisi, sezione 3). Cartelle riservate, mai trattate come autori: nomi che iniziano con `_` o `.` (`_derived` contiene le versioni generate).
- Tre versioni per foto: originale (download), web (visualizzazione), miniatura (griglia). Proposta di parametri: web 1920 px sul lato lungo, miniatura 480 px, WebP qualità 80. Le foto campione sono 4288x2848 (Pentax K-x, 12 MP): una decodifica con GD occupa circa 50 MB di RAM.
- Gli eventi si identificano dalla **data** (un evento al giorno); un evento può avere più gallerie (una per autore). Il titolo dell'evento è quello della prima galleria in ordine alfabetico di cartella autore; avviso se i titoli differiscono.
- Se un file, una galleria, un autore o un evento spariscono dal disco, `media:sync` li toglie dal DB (e cancella le versioni generate; **mai** gli originali).
- Lo ZIP contiene le foto della galleria visualizzata (selezione per evento e/o autore) ed è generato al volo in streaming (modalità STORE).
- Nomi su disco: restano quelli originali (così `rsync` resta incrementale e il titolo dell'evento mantiene accenti e parentesi). La normalizzazione è **negli URL e nel DB** (chiave evento = data, slug autore). Il titolo mostrato è quello della cartella con la prima lettera maiuscola.
- Stack: Laravel 12 + Pest (già nel progetto).
- Database: **MariaDB dedicato** (servizio `db` nel compose, come `unlimiteddb`). Decisione presa: SQLite e lo schema nel MariaDB di un altro sito non si valutano più.
- Branch del nuovo progetto: **`laravel`**. Un push su `laravel` costruisce l'immagine `ghcr.io/rickymandich/foto:laravel` e la deploya.
- Autore delle foto del vecchio progetto: **`rickymandich`** (cartella `storage/app/public/rickymandich/`).
- Porta web locale (Docker di sviluppo): **88**.

## Fase 0 — Verifiche e decisioni prima di scrivere codice

0.1 **Memoria della VM.** Il database è deciso (MariaDB dedicato, vedi sopra). La VM ha 956 MiB di RAM e lo swap al 72%: misura quanto pesano oggi i container, per sapere se ci sta anche il MariaDB di `foto`:

```zsh
ssh mandich 'docker stats --no-stream --format "table {{.Name}}\t{{.MemUsage}}"'
```

Annota l'esito nell'analisi (sezione 5). Se la RAM non bastasse si ripensa il database; fino ad allora si procede con MariaDB.

0.2 **rsync sul server.**

```zsh
ssh mandich 'command -v rsync && rsync --version | head -1'
```

Se manca: `ssh mandich 'sudo apt-get install -y rsync'`.

0.3 **Dati per CI/CD**: branch da deployare **`laravel`** (deciso) e immagine ghcr `ghcr.io/rickymandich/foto` con tag `laravel`. Resta da fornire l'URL del repository GitHub del nuovo progetto.

0.4 **Cartella autore** per le 8 gallerie esistenti (le foto di `oldFoto` non hanno autore: oggi sono tutte nella cartella evento): deciso, è `rickymandich`, quindi `storage/app/public/rickymandich/`, con un `autore.md` (`nome` e `recapiti` a piacere).

## Fase 1 — Configurazione base

1.1 `config/app.php`: `'timezone' => 'Europe/Rome'`. In `.env.example` e `.env`: `APP_NAME=Foto`, `APP_LOCALE=it`, `APP_FALLBACK_LOCALE=it`, `APP_FAKER_LOCALE=it_IT`, `FILESYSTEM_DISK=public`. In `.env.example` il database resta MariaDB (`DB_CONNECTION=mariadb` è già il default del progetto).

1.2 Dipendenze (dove usi composer, poi si rigenera `composer.lock`):

```zsh
composer require intervention/image maennchen/zipstream-php symfony/yaml
```

1.3 Crea `config/photos.php`:

```php
<?php

return [
    // disk Laravel che contiene autori/eventi (storage/app/public)
    'disk' => 'public',

    // cartella (dentro il disk) con le versioni generate; mai scansionata come autore
    'derived_dir' => '_derived',

    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    'audio_extensions' => ['mp3', 'aac', 'm4a', 'ogg', 'wav'],

    // ordine importante: dalla più grande alla più piccola (la seconda parte dalla prima)
    'versions' => [
        'web' => ['max_side' => 1920, 'quality' => 80],
        'thumb' => ['max_side' => 480, 'quality' => 80],
    ],

    // "2025 09 20 - corteo ..." (anche "2025-09-20 - ..." o solo la data)
    'event_folder_regex' => '/^(\d{4})[ \-](\d{2})[ \-](\d{2})(?:\s+-\s+(.+))?$/u',
];
```

## Fase 2 — Schema del database

Quattro migration (nomi indicativi, date successive alle esistenti):

`create_authors_table`:
```php
Schema::create('authors', function (Blueprint $table) {
    $table->id();
    $table->string('folder')->unique();   // nome esatto della cartella
    $table->string('slug')->unique();     // Str::slug(folder), per gli URL
    $table->string('name');               // da autore.md, altrimenti = folder
    $table->text('bio')->nullable();
    $table->json('contacts')->nullable(); // recapiti facoltativi
    $table->string('seen_token', 36)->nullable()->index();
    $table->timestamps();
});
```

`create_events_table`:
```php
Schema::create('events', function (Blueprint $table) {
    $table->id();
    $table->date('date')->unique();       // chiave dell'evento (un evento al giorno)
    $table->string('title')->nullable();  // dalla prima galleria in ordine di autore
    $table->timestamps();
});
```

`create_galleries_table` (la cartella di un autore per un evento):
```php
Schema::create('galleries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('author_id')->constrained()->cascadeOnDelete();
    $table->foreignId('event_id')->constrained()->cascadeOnDelete();
    $table->string('folder');             // nome esatto della cartella evento di quell'autore
    $table->string('title')->nullable();  // descrizione estratta dal nome cartella
    $table->string('seen_token', 36)->nullable()->index();
    $table->timestamps();
    $table->unique(['author_id', 'event_id']);
});
```

`create_photos_table` e `create_tracks_table`:
```php
Schema::create('photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
    $table->string('filename');
    $table->unsignedBigInteger('size');
    $table->unsignedInteger('mtime');
    $table->unsignedSmallInteger('width')->nullable();
    $table->unsignedSmallInteger('height')->nullable();
    $table->dateTime('taken_at')->nullable();      // EXIF DateTimeOriginal
    $table->boolean('versions_ready')->default(false);
    $table->string('seen_token', 36)->nullable()->index();
    $table->timestamps();
    $table->unique(['gallery_id', 'filename']);
});

Schema::create('tracks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
    $table->string('filename');
    $table->unsignedBigInteger('size');
    $table->unsignedInteger('mtime');
    $table->string('seen_token', 36)->nullable()->index();
    $table->timestamps();
    $table->unique(['gallery_id', 'filename']);
});
```

Lunghezza degli indici unici su `filename` e `folder`: con `utf8mb4` va bene (255 caratteri).

## Fase 3 — Modelli

Crea in `app/Models`: `Author`, `Event`, `Gallery`, `Photo`, `Track` con `$guarded = []` e relazioni (`Author hasMany Gallery`, `Event hasMany Gallery`, `Gallery belongsTo Author/Event e hasMany Photo/Track`, `Photo belongsTo Gallery`). Cast: `Event::date` → `date`, `Author::contacts` → `array`, `Photo::taken_at` → `datetime`, `Photo::versions_ready` → `boolean`.

Metodi di utilità su `Photo` (richiedono `gallery.author` caricato):

```php
public function relativePath(): string
{
    return $this->gallery->author->folder.'/'.$this->gallery->folder.'/'.$this->filename;
}

public function absolutePath(): string
{
    return Storage::disk(config('photos.disk'))->path($this->relativePath());
}

public function versionRelativePath(string $version): string
{
    return config('photos.derived_dir')."/{$version}/{$this->id}.webp";
}

public function versionAbsolutePath(string $version): string
{
    return Storage::disk(config('photos.disk'))->path($this->versionRelativePath($version));
}

/** URL pubblico (nginx serve /storage/ da storage/app/public); i segmenti vanno codificati. */
public function url(string $variant = 'original'): string
{
    $path = $variant === 'original' ? $this->relativePath() : $this->versionRelativePath($variant);
    $encoded = collect(explode('/', $path))->map(fn ($s) => rawurlencode($s))->implode('/');

    return '/storage/'.$encoded.($variant === 'original' ? '' : '?v='.$this->mtime);
}
```

Su `Event`: accessor `displayTitle()` → `Str::ucfirst($this->title)` oppure `Evento del dd/mm/aaaa` se il titolo è nullo.

## Fase 4 — Sincronizzazione: `media:sync`

File: `app/Services/Media/MediaSynchronizer.php` e `app/Console/Commands/SyncMedia.php`.

### Algoritmo (idempotente)

1. Genera un `$token` (UUID) per la run. Lock `Cache::lock('media-sync', 3600)`: se è già preso, esce con errore.
2. Elenca le sottocartelle di `storage/app/public` escludendo quelle che iniziano con `_` o `.`. **Sicurezza**: se non ne trova nessuna ma il DB ha autori, abortisce (storage non montato?) a meno di `--allow-empty`.
3. Per ogni cartella autore: slug = `Str::slug(folder)`; se vuoto o già usato da un'altra cartella → avviso e salta. Legge `autore.md` se esiste (front matter YAML + corpo): `nome` (default: nome cartella), `recapiti` (array, voci vuote scartate), corpo → `bio`. Un YAML non valido → avviso e si usano i default. `autore.md` non è mai trattato come foto.
4. Per ogni sottocartella dell'autore che combacia con `photos.event_folder_regex` (altrimenti avviso e salta): data `AAAA-MM-GG` (controllo `checkdate`), titolo = gruppo 4 oppure null. `Event::firstOrCreate(['date'])`, `Gallery::updateOrCreate(['author_id','event_id'], ['folder','title'])`. Se lo stesso autore ha due cartelle con la stessa data: avviso e si tiene la prima in ordine alfabetico.
5. Per ogni file (non ricorsivo, ignorati i nascosti) della galleria, in base all'estensione:
   - **immagine** → `Photo` (chiave `gallery_id`+`filename`). Se nuova oppure `size`/`mtime` cambiati: aggiorna `size`, `mtime`, `width`/`height` (`getimagesize`), `taken_at` (`exif_read_data`, in try/catch), `versions_ready=false` e mette in coda `GeneratePhotoVersionsJob`. Se invariata ma `versions_ready=false` o manca un file in `_derived`, rimette in coda il job.
   - **audio** → `Track` (stessa logica, senza job).
   - altro → ignorato.
   Ogni riga visitata riceve `seen_token = $token`.
6. Titolo evento: per ogni evento toccato, il titolo è quello della prima galleria (ordine alfabetico per `authors.folder`) con titolo non nullo. Se tra le gallerie ci sono titoli diversi (confronto senza maiuscole/spazi doppi) → **avviso** con evento e cartelle coinvolte.
7. Rimozioni: foto, tracce, gallerie e autori con `seen_token != $token` vengono eliminati; prima si raccolgono gli id delle foto eliminate per cancellare `_derived/web/{id}.webp` e `_derived/thumb/{id}.webp`. Poi `Event::doesntHave('galleries')->delete()`.
8. `--dry-run`: tutto dentro una transazione che si annulla; niente job, niente cancellazioni di file; stampa quanto cambierebbe.
9. Output: contatori (foto nuove/aggiornate/rimosse, tracce, gallerie, eventi) e tutti gli avvisi.

### Codice di partenza (da rifinire con i test della Fase 8)

`app/Console/Commands/SyncMedia.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Media\MediaSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncMedia extends Command
{
    protected $signature = 'media:sync {--dry-run : Mostra cosa cambierebbe senza scrivere} {--allow-empty : Consente la sincronizzazione anche se non ci sono cartelle autore}';

    protected $description = 'Allinea il database con le cartelle in storage/app/public';

    public function handle(MediaSynchronizer $synchronizer): int
    {
        $lock = Cache::lock('media-sync', 3600);

        if (! $lock->get()) {
            $this->error('Una sincronizzazione è già in corso.');

            return self::FAILURE;
        }

        try {
            $synchronizer->run((bool) $this->option('dry-run'), (bool) $this->option('allow-empty'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        foreach ($synchronizer->stats as $name => $value) {
            $this->line(str_pad($name, 20).$value);
        }

        foreach ($synchronizer->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
```

`app/Services/Media/MediaSynchronizer.php` (struttura; i punti 3-7 dell'algoritmo sono i metodi privati indicati):

```php
<?php

namespace App\Services\Media;

use App\Jobs\GeneratePhotoVersionsJob;
use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class MediaSynchronizer
{
    /** @var list<string> */
    public array $warnings = [];

    public array $stats = ['photos_new' => 0, 'photos_updated' => 0, 'photos_removed' => 0, 'tracks' => 0, 'galleries' => 0, 'events_removed' => 0];

    private string $token;

    /** @var list<int> */
    private array $jobs = [];

    /** @var list<int> */
    private array $touchedEvents = [];

    public function run(bool $dryRun = false, bool $allowEmpty = false): void
    {
        $this->token = (string) Str::uuid();
        $root = Storage::disk(config('photos.disk'))->path('');

        $authorFolders = $this->subdirs($root);
        if ($authorFolders === [] && Author::exists() && ! $allowEmpty) {
            throw new \RuntimeException('Nessuna cartella autore trovata ma il database contiene dati: lo storage è montato? Usa --allow-empty per forzare.');
        }

        $removedPhotoIds = [];

        DB::beginTransaction();
        try {
            foreach ($authorFolders as $folder) {
                $this->syncAuthor($root, $folder);
            }
            $this->syncEventTitles();
            $removedPhotoIds = $this->removeMissing();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($dryRun) {
            return;
        }

        foreach ($removedPhotoIds as $id) {
            foreach (array_keys(config('photos.versions')) as $version) {
                File::delete(Storage::disk(config('photos.disk'))->path(config('photos.derived_dir')."/{$version}/{$id}.webp"));
            }
        }

        foreach (array_unique($this->jobs) as $photoId) {
            GeneratePhotoVersionsJob::dispatch($photoId);
        }
    }

    /** @return list<string> nomi (non percorsi) delle sottocartelle visibili, ordinate */
    private function subdirs(string $path): array
    {
        return collect(File::directories($path))
            ->map(fn ($p) => basename($p))
            ->reject(fn ($n) => str_starts_with($n, '_') || str_starts_with($n, '.'))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    // private function syncAuthor(string $root, string $folder): void   // punti 3-5
    // private function readAuthorMeta(string $path): array              // front matter di autore.md
    // private function syncPhoto(Gallery $gallery, \SplFileInfo $file): void
    // private function syncTrack(Gallery $gallery, \SplFileInfo $file): void
    // private function takenAt(string $path): ?string                    // exif_read_data, try/catch
    // private function syncEventTitles(): void                           // punto 6, con avviso titoli diversi
    // private function removeMissing(): array                            // punto 7: ritorna gli id delle foto eliminate
}
```

Note di implementazione: usare `File::directories` / `File::files` (non ricorsivi); in `syncAuthor` scartare gli slug duplicati; in `readAuthorMeta` usare la regex `/\A---\R(.*?)\R---\R?(.*)\z/s` e catturare `ParseException`; ricordare che i job vanno in coda **dopo** il commit.

## Fase 5 — Generazione delle versioni

`app/Services/Media/PhotoVersionGenerator.php`:

```php
<?php

namespace App\Services\Media;

use App\Models\Photo;
use Illuminate\Support\Facades\File;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class PhotoVersionGenerator
{
    public function generate(Photo $photo): void
    {
        $photo->loadMissing('gallery.author');
        $source = $photo->absolutePath();

        if (! is_file($source)) {
            return; // sparita dal disco: ci penserà media:sync
        }

        $image = (new ImageManager(new Driver()))->read($source); // l'orientamento EXIF va verificato (vedi note)

        // dalla più grande alla più piccola: la seconda parte dall'immagine già ridotta
        foreach (config('photos.versions') as $name => $cfg) {
            $image->scaleDown($cfg['max_side'], $cfg['max_side']);

            $target = $photo->versionAbsolutePath($name);
            File::ensureDirectoryExists(dirname($target));
            $tmp = $target.'.tmp';
            $image->toWebp($cfg['quality'])->save($tmp);
            rename($tmp, $target);
        }

        $photo->forceFill(['versions_ready' => true])->save();
        unset($image);
        gc_collect_cycles();
    }
}
```

`app/Jobs/GeneratePhotoVersionsJob.php`: `ShouldQueue`, `public int $tries = 3; public int $timeout = 120;`, costruttore `public int $photoId`, `handle(PhotoVersionGenerator $generator)` che carica la foto (`Photo::find`) e, se esiste, chiama `generate`.

Note:
- Una foto alla volta: un solo processo `queue:work` (Fase 7), `--memory=200`, `memory_limit=256M` per PHP.
- Verificare con una foto reale che l'orientamento EXIF sia applicato (Intervention v3 lo fa in lettura per default con il driver GD; serve l'estensione `exif`, Fase 7). Se non fosse così, ruotare esplicitamente.
- `width`/`height` salvate dallo scanner sono quelle del file (prima dell'orientamento); da rivedere se compaiono foto verticali.

## Fase 6 — Download (singolo e ZIP)

6.1 **Foto singola**: nessun codice PHP. L'originale è un link a `$photo->url()` con l'attributo `download="{{ $photo->filename }}"` (stessa origine, nginx lo serve).

6.2 **ZIP**: `app/Http/Controllers/DownloadController.php` con rotta `GET /download/zip` (nome `download.zip`, `throttle:6,1`).

Parametri (almeno uno obbligatorio, altrimenti 422): `eventi[]` (date `Y-m-d`) e/o `autori[]` (slug). Le due selezioni combinano in AND. Così coprono: evento con tutti gli autori, solo alcuni autori di un evento, più eventi di un autore.

```php
<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class DownloadController extends Controller
{
    public function zip(Request $request)
    {
        $data = $request->validate([
            'eventi' => ['array'],
            'eventi.*' => ['date_format:Y-m-d'],
            'autori' => ['array'],
            'autori.*' => ['string'],
        ]);

        abort_if(empty($data['eventi'] ?? []) && empty($data['autori'] ?? []), 422);

        $photos = Photo::query()
            ->with('gallery.author', 'gallery.event')
            ->whereHas('gallery', function ($gallery) use ($data) {
                if (! empty($data['eventi'])) {
                    $gallery->whereHas('event', fn ($q) => $q->whereIn('date', $data['eventi']));
                }
                if (! empty($data['autori'])) {
                    $gallery->whereHas('author', fn ($q) => $q->whereIn('slug', $data['autori']));
                }
            })
            ->orderBy('taken_at')->orderBy('filename')
            ->get();

        abort_if($photos->isEmpty(), 404);

        $name = count($data['eventi'] ?? []) === 1 && empty($data['autori'])
            ? 'foto-'.$data['eventi'][0].'.zip'
            : 'foto.zip';

        return response()->streamDownload(function () use ($photos, $name) {
            set_time_limit(0);
            $zip = new ZipStream(
                outputName: $name,
                sendHttpHeaders: false,
                defaultCompressionMethod: CompressionMethod::STORE,
                flushOutput: true,
            );
            foreach ($photos as $photo) {
                $event = $photo->gallery->event;
                $folder = $event->date->format('Y-m-d').($event->title ? ' '.$this->safe($event->title) : '');
                $zip->addFileFromPath(
                    fileName: $folder.'/'.$this->safe($photo->gallery->author->folder).'/'.$photo->filename,
                    path: $photo->absolutePath(),
                );
            }
            $zip->finish();
        }, $name, [
            'Content-Type' => 'application/zip',
            'X-Accel-Buffering' => 'no', // nginx non deve bufferizzare lo stream
        ]);
    }

    private function safe(string $name): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]/u', ' ', $name));
    }
}
```

Da verificare con la versione installata di `maennchen/zipstream-php`: nomi e firma dei parametri del costruttore e di `addFileFromPath`; supporto alla simulazione per calcolare `Content-Length` (opzionale: darebbe la barra di avanzamento al browser).

6.3 `routes/web.php`: `Route::get('/download/zip', [DownloadController::class, 'zip'])->name('download.zip')->middleware('throttle:6,1');`

## Fase 7 — Docker e nginx

Riferimento: i file omonimi in `C:\Users\RickyMandich\PROJECT\unlimiteddb`. Copia e adatta, poi applica le modifiche qui sotto.

7.1 `Dockerfile` (da `unlimiteddb`; si omette lo stage `icon-builder` finché non c'è un'icona). Nello stage finale:

```dockerfile
RUN apk add --no-cache \
    libpng-dev libjpeg-turbo-dev libwebp-dev libzip-dev libxml2-dev oniguruma-dev supervisor \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring bcmath xml gd zip exif \
    && echo "memory_limit=256M" > /usr/local/etc/php/conf.d/memory.ini
```

(`pdo_mysql` serve per MariaDB.)

7.2 `docker/entrypoint.sh`: non fare più `chown -R` su tutto `storage` (con migliaia di file e ownership data da `rsync` darebbe problemi). Sostituire con:

```sh
mkdir -p /var/www/html/storage/app/public/_derived
chown -R www-data:www-data \
    /var/www/html/storage/framework \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    /var/www/html/storage/app/public/_derived
```

Gli originali restano dell'utente host (`ubuntu`, file 644 e cartelle 755): il container li legge ma non li scrive; scrive solo in `_derived`. Questo chiude il punto sui permessi senza `sudo rsync`.

7.3 `docker/nginx/default.conf` (da `unlimiteddb`): `server_name foto-test.mandich.dev;` e, oltre al `location /storage/` esistente, prima di esso:

```nginx
    # versioni generate: l'URL include ?v=<mtime>, quindi si possono tenere in cache a lungo
    location /storage/_derived/ {
        alias /var/www/html/storage/app/public/_derived/;
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
```

Nel blocco `location ~ \.php$` aggiungere `fastcgi_read_timeout 600;` (gli ZIP in streaming verso client lenti).

7.4 `docker-compose.yml` (produzione, modellato su `unlimiteddb`): servizi `app`, `nginx`, `worker` e `db` (MariaDB dedicato). Differenze rispetto a `unlimiteddb`:
- `container_name`: `foto_app`, `foto_nginx`, `foto_worker`, `foto_db`; immagine `ghcr.io/rickymandich/foto:laravel` (da 0.3); volumi `foto_db_data`.
- Host Traefik `foto-test.mandich.dev`; router/service Traefik chiamati `foto`.
- `app` e `worker` montano `./storage:/var/www/html/storage` (scrittura); `nginx` monta `./storage/app/public:/var/www/html/storage/app/public:ro`.
- Rete interna senza subnet fissa (niente rischio di sovrapposizione con le altre); in `docker/mysql/init.sql` l'utente `laravel` va autorizzato da `'%'` invece che da `172.22.0.%` (il DB non è esposto fuori dalla rete interna).
- Variabili `DB_*` del servizio coerenti con il nome del database (`foto`).

7.5 `docker-compose.dev.yml` (sviluppo locale, **http://localhost:88**). È lo schema di `unlimiteddb` (stessi quattro servizi) adattato a questo progetto; il file ha in testa un commento con i comandi principali.

| Servizio | Container | Cosa fa |
|---|---|---|
| `app` | `foto_app_dev` | PHP-FPM, immagine `foto:dev` costruita dal `Dockerfile`. Monta `./storage` (scrittura), `./.env` (sola lettura) e `./public/build`: a ogni avvio l'entrypoint ci copia gli asset compilati nell'immagine, così il bind mount non serve asset vecchi. |
| `nginx` | `foto_nginx_dev` | Pubblica la porta **88** (`88:80`). Serve `./public` e, in sola lettura, `./storage/app/public` (originali e `_derived`); le richieste PHP vanno a `app:9000`. Usa lo stesso `docker/nginx/default.conf` della produzione. |
| `db` | `foto_db_dev` | MariaDB 11, database `foto`, volume `db_data_dev`. Porta **3307** sull'host (per i client SQL) verso la 3306 del container. `docker/mysql/init.dev.sql` crea l'utente `foto` senza password, autorizzato da `'%'`. L'healthcheck fa partire `app` e `worker` solo quando il database è pronto. |
| `worker` | `foto_worker_dev` | Stessa immagine di `app`. Esegue `php artisan queue:work --memory=200 --timeout=150 --tries=3 --max-time=3600`: genera le versioni (web e miniatura) una foto alla volta. |

Differenze rispetto al compose di sviluppo di `unlimiteddb`, cioè quelle che questo progetto ha in più o di diverso:

- **Porte**: web 88 (in `unlimiteddb` è 66) e database 3307 (là 3306), così i due progetti possono essere accesi insieme.
- **Rete**: nessuna subnet né IP fissi (in `unlimiteddb` `172.30.0.x`). La rete `internal` la assegna Docker e l'utente del database è creato per `'%'` invece che per `172.30.0.%`; l'accesso resta limitato dalla rete interna (la 3307 sull'host serve solo ai client SQL).
- **Foto fuori da git**: `./storage` è montato in `app` e `worker` (scrittura) e `./storage/app/public` in `nginx` (sola lettura). Le foto di prova vanno in `storage/app/public/<autore>/<AAAA MM GG - descrizione>/` (per le foto esistenti l'autore è `rickymandich`), esattamente come sul server; `.dockerignore` esclude `storage/app`, quindi non entrano mai nell'immagine. I container scrivono solo in `storage/app/public/_derived` (versioni generate), `storage/framework` e `storage/logs`.
- **Worker**: stessi parametri della produzione (un processo, `--memory=200`, `--timeout=150`, `--tries=3`) invece di quelli di `unlimiteddb` (`--memory=100 --timeout=240`), perché si decodificano foto da 12 MP (l'immagine ha `memory_limit=256M` e le estensioni `gd` con jpeg/webp ed `exif`). In dev gira direttamente, senza `supervisord` (`docker/supervisor/worker.conf` serve solo in produzione).
- **`APP_URL=http://localhost:88`** è impostato nell'`environment` di `app` e `worker`, insieme alle variabili `DB_*`: le variabili del compose hanno la precedenza sul `.env`, che può restare quello di `composer run dev` (database su `127.0.0.1`, `APP_URL` su 8000).
- **Niente Traefik**: nessuna rete `proxy` né volume `build_assets`, perché in locale non c'è esposizione pubblica.

Uso:

```zsh
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec app php artisan migrate
# metti le foto di prova in storage/app/public/rickymandich/<AAAA MM GG - descrizione>/
docker compose -f docker-compose.dev.yml exec app php artisan media:sync --dry-run
docker compose -f docker-compose.dev.yml exec app php artisan media:sync
docker compose -f docker-compose.dev.yml logs -f worker   # generazione delle versioni
```

Note:

- Il codice è nell'immagine (il compose non monta la cartella del progetto): ogni modifica a codice, `composer.json` o `package.json` richiede `up -d --build`. Dopo una modifica al codice dei job, `exec app php artisan queue:restart` fa ricaricare il worker.
- `docker compose -f docker-compose.dev.yml down` ferma tutto e conserva database e foto; con `-v` cancella anche il database (poi rilanciare `migrate` e `media:sync`: le foto restano su disco).
- I test (`composer test`) girano fuori da Docker, su SQLite in memoria.

7.6 `docker/supervisor/worker.conf`: copia da `unlimiteddb`, **togli** il programma `schedule:work` (non serve scheduler) e imposta il worker come `php artisan queue:work --memory=200 --timeout=150 --tries=3 --max-time=3600`, un solo processo.

7.7 `deploy/pre-deploy.sh` e `deploy/post-deploy.sh`: copia da `unlimiteddb`; in `post-deploy.sh` restano `migrate --force`, `queue:restart` e il reload di nginx.

7.8 `.github/workflows/deploy-foto.yml`: copia di `deploy-unlimiteddb.yml` con immagine `ghcr.io/rickymandich/foto`, branch `laravel`, tag `:laravel`, chiamata finale `~/scripts/deploy-image.sh foto laravel`, secret `SSH_PRIVATE_KEY_FOTO` (nuova chiave, con *forced command* in `~/.ssh/authorized_keys` che permette solo quel comando). Descrizione completa del meccanismo: sezione "Deploy" del README di `unlimiteddb`.

## Fase 8 — Test (Pest)

Usare una cartella temporanea come disk `public` (`config(['filesystems.disks.public.root' => $tmp])`) e popolarla con immagini piccole generate con GD.

- `tests/Feature/Media/MediaSyncTest.php`: sync crea autore/evento/galleria/foto/tracce; seconda esecuzione senza modifiche non cambia nulla (idempotenza) e non rimette in coda job; file modificato → job in coda; file aggiunto/rimosso; galleria e autore rimossi; evento senza gallerie eliminato; `--dry-run` non scrive; abort con storage vuoto e DB pieno (e `--allow-empty` lo consente); cartelle `_derived` e `.nascoste` ignorate; cartelle evento non conformi → avviso; titoli diversi per la stessa data → avviso e vince l'autore alfabeticamente primo; `autore.md` valido, assente e con YAML rotto.
- `tests/Feature/Media/PhotoVersionGeneratorTest.php`: crea `web` e `thumb`, rispetta `max_side`, non ingrandisce, `versions_ready=true`; file mancante non genera errori.
- `tests/Feature/Media/ZipDownloadTest.php`: 422 senza parametri, 404 senza risultati, ZIP con le voci attese per evento / autori / più eventi di un autore, nomi senza caratteri non validi.
- Eseguire con `composer test`.

## Fase 9 — Deploy di prova sulla VM

1. Sul server: `mkdir -p ~/sites/foto` e clone del repository nel branch `laravel`.
2. Crea `~/sites/foto/.env` (modello: `.env.example`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://foto-test.mandich.dev`, `APP_KEY` generata, DB: MariaDB del servizio `db`, utente `laravel` senza password).
3. Chiave SSH dedicata, secret GitHub `SSH_PRIVATE_KEY_FOTO` e *forced command* come in 7.8.
4. Push sul branch: la pipeline costruisce e deploya. Verifica `docker compose ps` (worker `Up`), `failed_jobs` vuota.
5. DNS: nessun record nuovo; `foto-test.mandich.dev` ricade nel wildcard `*.mandich.dev` (proxato). `foto.mandich.dev` resta sulle GitHub Pages.
6. Verifica del proxy Cloudflare con uno ZIP grande (vedi punto aperto 1 dell'analisi); se dà problemi, rendere `foto-test` non proxato.

## Fase 10 — Migrazione dei contenuti esistenti

10.1 Prepara in locale (WSL) una cartella di staging con la struttura finale, senza duplicare i 4 GB (usa spostamenti o `ln` dove possibile):

```
staging/rickymandich/2025 09 20 - corteo per gaza (blocco della vempa)/IMGP....JPG
...
```

con `<autore>` = `rickymandich` (deciso in 0.4). Aggiungi `autore.md` con i placeholder dell'analisi.

10.2 Script `bash/upload-event.sh` (nel nuovo progetto) per inviare una cartella evento (per le foto esistenti l'autore è `rickymandich`):

```zsh
#!/bin/zsh
# uso: bash/upload-event.sh <cartella-evento-locale> <autore>
set -euo pipefail
src="${1%/}"; author="$2"
rsync -avs --partial --progress -e ssh "$src/" "mandich:~/sites/foto/storage/app/public/${author}/$(basename "$src")/"
```

`-s` (protect-args) evita problemi con spazi e parentesi nei nomi. Niente `-z` (i JPG non si comprimono) e niente `--delete`. Se il trasferimento si interrompe, rilanciare lo stesso comando: riprende da dove era.

10.3 Invia anche `autore.md` (una volta per autore) e lancia la sincronizzazione:

```zsh
ssh mandich 'cd ~/sites/foto && docker compose exec -T app php artisan media:sync --dry-run'
ssh mandich 'cd ~/sites/foto && docker compose exec -T app php artisan media:sync'
```

10.4 Verifiche (valori attesi dal vecchio sito: 8 eventi, 1017 foto, 14 audio):

```zsh
ssh mandich 'cd ~/sites/foto && docker compose exec -T app php artisan tinker --execute="echo App\Models\Photo::count().\" foto, \".App\Models\Track::count().\" tracce, \".App\Models\Event::count().\" eventi, \".App\Models\Photo::where(\"versions_ready\", true)->count().\" con versioni\";"'
ssh mandich 'du -sh ~/sites/foto/storage/app/public/_derived; find ~/sites/foto/storage/app/public/_derived -type f | wc -l'
```

Attesi: 1017 foto, 14 tracce, 8 eventi, 1017 con versioni, 2034 file in `_derived`, e `_derived` sotto 1 GB (la stima va confrontata con il dato reale: se sfora, rivedere `photos.versions`). Controllare a mano un originale, una versione web e una miniatura (orientamento corretto), un download singolo e uno ZIP di un evento.

## Fase 11 — Documentazione

- `README.md`: descrizione del progetto, stack, struttura delle cartelle su disco, comandi (`media:sync`, upload, test), setup locale con Docker, deploy; aggiornato **a ogni fase completata** (non descrive ciò che è solo pianificato).
- `todo.md`: elenco a specchio delle fasi di questo piano (spuntare man mano).
- `AnalyzeForRefactor.md` (in `oldFoto`): annotare le decisioni prese durante l'esecuzione.

## Fase 12 — Vecchio repository: reset della storia

Da fare **solo dopo** che la Fase 10 è verificata (il vecchio sito resta online finché non c'è il nuovo). L'obiettivo è che un clone non scarichi più le foto; non è necessario liberare spazio su GitHub.

```zsh
cd /mnt/c/Users/RickyMandich/PROJECT/oldFoto
git status                                   # deve essere pulito
git clone --mirror . ../oldFoto-backup.git   # backup della storia completa (da cancellare quando non serve più)
git checkout --orphan nuova-storia
# verifica che .gitignore escluda foto e audio (cartelle evento e relative estensioni)
git rm -r -q --cached .
git add -A
git status --short | head -50                # niente foto né audio
git commit -m "Reset della storia: solo la struttura del vecchio sito"
git branch -D master
git branch -m master
git push --force origin master
git branch -r; git tag -l                    # ogni altro branch o tag con le foto va riscritto o eliminato
git push origin --delete <branch>            # per ciascun branch inutile
```

Verifica: `git clone <url> /tmp/prova && du -sh /tmp/prova/.git` (deve essere piccolo). Limiti: i vecchi clone locali restano pesanti e GitHub può tenere per un po' gli oggetti non più raggiungibili.

Nuova versione in un nuovo branch (dal progetto `foto`, verificando prima se è già un repository con `git status`): `git remote add origin <url-del-repo>`, `git checkout -b laravel`, `git add -A`, `git commit`, `git push -u origin laravel`. Il branch di deploy deve coincidere con quello deciso in 0.3 (`laravel`).

## Rischi e note

- **RAM**: la generazione delle versioni gira sulla VM con meno di 1 GB di RAM: un solo worker, una foto alla volta. Il primo import (1017 foto) può richiedere diverso tempo; controllare `docker stats` durante la prima esecuzione.
- **Cancellazioni**: `media:sync` cancella dal DB tutto ciò che non trova sul disco. La protezione contro lo storage vuoto copre solo il caso "nessuna cartella autore"; se si monta per errore uno storage parziale, le rimozioni parziali avvengono comunque. Usare `--dry-run` quando si è in dubbio.
- **Disco**: ~4 GB di originali (+~4 GB/anno) più meno di 1 GB di versioni (stima). Con 21 GB liberi condivisi con gli altri siti, controllare `df -h /` periodicamente.
- **Backup**: affidato al volume Oracle (decisione presa); finché l'utente conserva gli originali sul PC esiste una seconda copia.
- **Cloudflare**: comportamento dei download grandi in streaming dietro il proxy da verificare (Fase 9, punto 6).

## Criteri di accettazione

1. Nessuna foto o audio è tracciato da git; il repository del nuovo progetto contiene solo codice.
2. `media:sync` è idempotente e il `--dry-run` non scrive nulla.
3. Dopo la Fase 10: 1017 foto, 14 tracce, 8 eventi, tutte con versioni; `_derived` sotto 1 GB.
4. Gli originali sono scaricabili singolarmente e in ZIP (streaming senza picchi di RAM sul container `app`).
5. I test passano (`composer test`) e README/todo sono aggiornati.
