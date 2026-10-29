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
use SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Allinea il database con lo stato del filesystem (source of truth).
 *
 * Struttura attesa dentro il disk configurato in config/photos.php:
 *   <autore>/autore.md
 *   <autore>/<AAAA MM GG - descrizione>/{foto, audio}
 *
 * Idempotente: rilanciarlo senza modifiche sul disco non cambia nulla.
 * Le rimozioni cancellano righe del DB e versioni generate, mai gli originali.
 */
class MediaSynchronizer
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, int> */
    public array $stats = [];

    /** @var list<int> id delle foto per cui serve (ri)generare le versioni */
    private array $jobs = [];

    /** @var list<int> id delle foto eliminate: le versioni generate vanno cancellate */
    private array $removedPhotoIds = [];

    /** @var list<int> */
    private array $touchedEvents = [];

    public function run(bool $dryRun = false, bool $allowEmpty = false): void
    {
        $this->reset();

        $root = $this->root();
        $authorFolders = $this->subdirs($root);

        if ($authorFolders === [] && Author::query()->exists() && ! $allowEmpty) {
            throw new \RuntimeException(
                'Nessuna cartella autore trovata ma il database contiene dati: lo storage è montato? Usa --allow-empty per forzare.'
            );
        }

        $validFolders = $this->validAuthorFolders($authorFolders);

        DB::beginTransaction();

        try {
            $this->removeVanishedAuthors($validFolders);

            foreach ($validFolders as $folder) {
                $this->syncAuthor($root, $folder);
            }

            $this->syncEventTitles();
            $this->removeEmptyEvents();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($dryRun) {
            return;
        }

        $this->deleteDerivedVersions($this->removedPhotoIds);

        $queue = array_values(array_unique($this->jobs));
        foreach ($queue as $photoId) {
            GeneratePhotoVersionsJob::dispatch($photoId);
        }
        $this->stats['jobs_queued'] = count($queue);
    }

    private function reset(): void
    {
        $this->warnings = [];
        $this->jobs = [];
        $this->removedPhotoIds = [];
        $this->touchedEvents = [];
        $this->stats = [
            'galleries_new' => 0,
            'galleries_removed' => 0,
            'photos_new' => 0,
            'photos_updated' => 0,
            'photos_removed' => 0,
            'tracks_new' => 0,
            'tracks_updated' => 0,
            'tracks_removed' => 0,
            'events_removed' => 0,
            'jobs_queued' => 0,
        ];
    }

    private function root(): string
    {
        return rtrim(Storage::disk(config('photos.disk'))->path(''), '/\\');
    }

    /**
     * Nomi (non percorsi) delle sottocartelle visibili, in ordine alfabetico naturale.
     * Le cartelle che iniziano con "_" o "." sono riservate (es. _derived).
     *
     * @return list<string>
     */
    private function subdirs(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        return collect(File::directories($path))
            ->map(fn (string $p) => basename($p))
            ->reject(fn (string $n) => str_starts_with($n, '_') || str_starts_with($n, '.'))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Scarta le cartelle autore il cui slug è vuoto o già usato da un'altra cartella.
     *
     * @param  list<string>  $folders
     * @return list<string>
     */
    private function validAuthorFolders(array $folders): array
    {
        $usedSlugs = [];
        $valid = [];

        foreach ($folders as $folder) {
            $slug = Str::slug($folder);

            if ($slug === '') {
                $this->warnings[] = "Autore ignorato: dal nome della cartella \"{$folder}\" non si ricava uno slug.";

                continue;
            }

            if (isset($usedSlugs[$slug])) {
                $this->warnings[] = "Autore ignorato: la cartella \"{$folder}\" ha lo stesso slug (\"{$slug}\") di \"{$usedSlugs[$slug]}\".";

                continue;
            }

            $usedSlugs[$slug] = $folder;
            $valid[] = $folder;
        }

        return $valid;
    }

    /** @param  list<string>  $validFolders */
    private function removeVanishedAuthors(array $validFolders): void
    {
        $gone = Author::query()->whereNotIn('folder', $validFolders)->get();

        foreach ($gone as $author) {
            foreach (Gallery::query()->where('author_id', $author->id)->get() as $gallery) {
                $this->forgetGallery($gallery, deleteRow: false);
            }

            $author->delete(); // le gallerie e i loro file (righe) vanno a cascata
        }
    }

    private function syncAuthor(string $root, string $folder): void
    {
        $authorPath = $root.DIRECTORY_SEPARATOR.$folder;
        $meta = $this->readAuthorMeta($authorPath, $folder);

        $author = Author::query()->updateOrCreate(
            ['folder' => $folder],
            [
                'slug' => Str::slug($folder),
                'name' => $meta['name'] ?? $folder,
                'bio' => $meta['bio'],
                'contacts' => $meta['contacts'],
            ],
        );

        $seenGalleryIds = [];
        $datesSeen = [];

        foreach ($this->subdirs($authorPath) as $eventFolder) {
            if (! preg_match(config('photos.event_folder_regex'), $eventFolder, $m)) {
                $this->warnings[] = "Cartella ignorata (nome non conforme a \"AAAA MM GG - descrizione\"): {$folder}/{$eventFolder}";

                continue;
            }

            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $this->warnings[] = "Cartella ignorata (data non valida): {$folder}/{$eventFolder}";

                continue;
            }

            $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);

            if (isset($datesSeen[$date])) {
                $this->warnings[] = "Autore \"{$folder}\": due cartelle con la stessa data {$date} (\"{$datesSeen[$date]}\" e \"{$eventFolder}\"); si usa la prima, la seconda è ignorata.";

                continue;
            }
            $datesSeen[$date] = $eventFolder;

            $title = isset($m[4]) && trim($m[4]) !== '' ? trim($m[4]) : null;

            $event = Event::query()->firstOrCreate(['date' => $date]);
            $this->touchedEvents[] = $event->id;

            $gallery = Gallery::query()->updateOrCreate(
                ['author_id' => $author->id, 'event_id' => $event->id],
                ['folder' => $eventFolder, 'title' => $title],
            );

            if ($gallery->wasRecentlyCreated) {
                $this->stats['galleries_new']++;
            }

            $seenGalleryIds[] = $gallery->id;
            $this->syncGalleryFiles($gallery, $authorPath.DIRECTORY_SEPARATOR.$eventFolder);
        }

        $stale = Gallery::query()
            ->where('author_id', $author->id)
            ->whereNotIn('id', $seenGalleryIds)
            ->get();

        foreach ($stale as $gallery) {
            $this->forgetGallery($gallery);
        }
    }

    private function syncGalleryFiles(Gallery $gallery, string $path): void
    {
        $seenPhotoIds = [];
        $seenTrackIds = [];

        $files = collect(File::files($path)) // non ricorsivo, file nascosti esclusi
            ->sortBy(fn (SplFileInfo $f) => $f->getFilename(), SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($files as $file) {
            $extension = strtolower($file->getExtension());

            if (in_array($extension, config('photos.image_extensions'), true)) {
                $seenPhotoIds[] = $this->syncPhoto($gallery, $file)->id;
            } elseif (in_array($extension, config('photos.audio_extensions'), true)) {
                $seenTrackIds[] = $this->syncTrack($gallery, $file)->id;
            }
        }

        $gonePhotos = Photo::query()->where('gallery_id', $gallery->id)->whereNotIn('id', $seenPhotoIds)->pluck('id')->all();
        if ($gonePhotos !== []) {
            Photo::query()->whereIn('id', $gonePhotos)->delete();
            $this->removedPhotoIds = [...$this->removedPhotoIds, ...$gonePhotos];
            $this->stats['photos_removed'] += count($gonePhotos);
        }

        $goneTracks = Track::query()->where('gallery_id', $gallery->id)->whereNotIn('id', $seenTrackIds)->pluck('id')->all();
        if ($goneTracks !== []) {
            Track::query()->whereIn('id', $goneTracks)->delete();
            $this->stats['tracks_removed'] += count($goneTracks);
        }
    }

    private function syncPhoto(Gallery $gallery, SplFileInfo $file): Photo
    {
        $photo = Photo::query()->firstOrNew([
            'gallery_id' => $gallery->id,
            'filename' => $file->getFilename(),
        ]);

        $size = (int) $file->getSize();
        $mtime = (int) $file->getMTime();
        $isNew = ! $photo->exists;

        if ($isNew || (int) $photo->size !== $size || (int) $photo->mtime !== $mtime) {
            $dimensions = @getimagesize($file->getPathname());

            $photo->fill([
                'size' => $size,
                'mtime' => $mtime,
                'width' => $dimensions[0] ?? null,
                'height' => $dimensions[1] ?? null,
                'taken_at' => $this->takenAt($file),
                'versions_ready' => false,
            ])->save();

            $this->stats[$isNew ? 'photos_new' : 'photos_updated']++;
            $this->jobs[] = $photo->id;

            return $photo;
        }

        if (! $photo->versions_ready || ! $this->versionsExist($photo)) {
            $this->jobs[] = $photo->id;
        }

        return $photo;
    }

    private function syncTrack(Gallery $gallery, SplFileInfo $file): Track
    {
        $track = Track::query()->firstOrNew([
            'gallery_id' => $gallery->id,
            'filename' => $file->getFilename(),
        ]);

        $size = (int) $file->getSize();
        $mtime = (int) $file->getMTime();
        $isNew = ! $track->exists;

        if ($isNew || (int) $track->size !== $size || (int) $track->mtime !== $mtime) {
            $track->fill(['size' => $size, 'mtime' => $mtime])->save();
            $this->stats[$isNew ? 'tracks_new' : 'tracks_updated']++;
        }

        return $track;
    }

    private function versionsExist(Photo $photo): bool
    {
        foreach (array_keys(config('photos.versions')) as $version) {
            if (! is_file($photo->versionAbsolutePath($version))) {
                return false;
            }
        }

        return true;
    }

    /** Data di scatto dall'EXIF (solo JPEG), oppure null. */
    private function takenAt(SplFileInfo $file): ?string
    {
        if (! function_exists('exif_read_data') || ! in_array(strtolower($file->getExtension()), ['jpg', 'jpeg'], true)) {
            return null;
        }

        try {
            $exif = @exif_read_data($file->getPathname());
        } catch (Throwable) {
            return null;
        }

        $raw = is_array($exif) ? ($exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null) : null;

        if (! is_string($raw)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', trim($raw));

        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    /**
     * Legge autore.md (front matter YAML + corpo markdown).
     * Chiavi del front matter: "nome" e "recapiti" (voci vuote scartate).
     *
     * @return array{name: ?string, bio: ?string, contacts: ?array<string, string>}
     */
    private function readAuthorMeta(string $authorPath, string $folder): array
    {
        $meta = ['name' => null, 'bio' => null, 'contacts' => null];
        $file = $authorPath.DIRECTORY_SEPARATOR.config('photos.author_file');

        if (! is_file($file)) {
            return $meta;
        }

        $raw = (string) file_get_contents($file);
        $raw = preg_replace('/\A\xEF\xBB\xBF/', '', $raw); // BOM

        if (! preg_match('/\A---\R(?:(.*?)\R)?---[ \t]*(?:\R|\z)(.*)\z/s', $raw, $m)) {
            $meta['bio'] = trim($raw) !== '' ? trim($raw) : null;

            return $meta;
        }

        try {
            $data = Yaml::parse($m[1] ?? '');
        } catch (ParseException $e) {
            $this->warnings[] = "Autore \"{$folder}\": front matter di ".config('photos.author_file').' non valido ('.$e->getMessage().'); si usano i valori predefiniti.';

            return $meta;
        }

        $data = is_array($data) ? $data : [];

        if (isset($data['nome']) && is_scalar($data['nome']) && trim((string) $data['nome']) !== '') {
            $meta['name'] = trim((string) $data['nome']);
        }

        if (isset($data['recapiti']) && is_array($data['recapiti'])) {
            $contacts = [];
            foreach ($data['recapiti'] as $key => $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $contacts[(string) $key] = trim((string) $value);
                }
            }
            $meta['contacts'] = $contacts !== [] ? $contacts : null;
        }

        $body = trim($m[2] ?? '');
        $meta['bio'] = $body !== '' ? $body : null;

        return $meta;
    }

    /**
     * Il titolo di un evento è quello della prima galleria (ordine alfabetico di cartella autore)
     * che ne ha uno; se i titoli differiscono si segnala un avviso.
     */
    private function syncEventTitles(): void
    {
        foreach (array_unique($this->touchedEvents) as $eventId) {
            $galleries = Gallery::query()
                ->with('author')
                ->where('event_id', $eventId)
                ->get()
                ->sortBy(fn (Gallery $g) => mb_strtolower($g->author->folder), SORT_NATURAL)
                ->values();

            $withTitle = $galleries->filter(fn (Gallery $g) => $g->title !== null && trim($g->title) !== '');

            $distinct = $withTitle
                ->map(fn (Gallery $g) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($g->title))))
                ->unique();

            if ($distinct->count() > 1) {
                $event = Event::query()->find($eventId);
                $list = $withTitle->map(fn (Gallery $g) => "{$g->author->folder}/{$g->folder}")->implode(', ');
                $this->warnings[] = "Evento del {$event->date}: titoli diversi tra gli autori ({$list}); vince il primo in ordine alfabetico.";
            }

            Event::query()->whereKey($eventId)->update([
                'title' => $withTitle->first()?->title,
            ]);
        }
    }

    private function removeEmptyEvents(): void
    {
        $empty = Event::query()->doesntHave('galleries')->pluck('id')->all();

        if ($empty !== []) {
            Event::query()->whereIn('id', $empty)->delete();
            $this->stats['events_removed'] += count($empty);
        }
    }

    /** Toglie una galleria dal DB, ricordando le foto da cui cancellare le versioni generate. */
    private function forgetGallery(Gallery $gallery, bool $deleteRow = true): void
    {
        $photoIds = Photo::query()->where('gallery_id', $gallery->id)->pluck('id')->all();

        $this->removedPhotoIds = [...$this->removedPhotoIds, ...$photoIds];
        $this->stats['photos_removed'] += count($photoIds);
        $this->stats['tracks_removed'] += Track::query()->where('gallery_id', $gallery->id)->count();
        $this->stats['galleries_removed']++;

        if ($deleteRow) {
            $gallery->delete();
        }
    }

    /** @param  list<int>  $photoIds */
    private function deleteDerivedVersions(array $photoIds): void
    {
        $disk = Storage::disk(config('photos.disk'));

        foreach ($photoIds as $id) {
            foreach (array_keys(config('photos.versions')) as $version) {
                File::delete($disk->path(config('photos.derived_dir')."/{$version}/{$id}.webp"));
            }
        }
    }
}
