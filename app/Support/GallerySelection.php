<?php

namespace App\Support;

use App\Models\Author;
use App\Models\Event;
use App\Models\Photo;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;

final class GallerySelection
{
    public const MAX_ITEMS = 20;

    /** @param list<string> $dates  AAAA-MM-GG  @param list<string> $authors  slug */
    private function __construct(
        public readonly array $dates,
        public readonly array $authors,
    ) {}

    public static function make(array $dates = [], array $authors = []): self
    {
        return new self(self::normalize($dates), self::normalize($authors));
    }

    public static function forEvent(Event $event, array $authors = []): self
    {
        return self::make([$event->date], $authors);
    }

    public static function forAuthor(Author $author, array $dates = []): self
    {
        return self::make($dates, [$author->slug]);
    }

    /**
     * Legge eventi[] e autori[] dalla richiesta.
     * $failStatus è lo stato HTTP restituito se i valori non sono validi.
     */
    public static function fromRequest(Request $request, int $failStatus = 404): self
    {
        $validator = Validator::make($request->query(), [
            'eventi'    => ['array', 'max:' . self::MAX_ITEMS],
            'eventi.*'  => ['string', 'date_format:Y-m-d'],
            'autori'    => ['array', 'max:' . self::MAX_ITEMS],
            'autori.*'  => ['string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
        ]);

        abort_if($validator->fails(), $failStatus);

        return self::make($request->query('eventi', []), $request->query('autori', []));
    }

    /** Vero se non è stata specificata nessuna data e nessun autore. */
    public function isEmpty(): bool
    {
        return $this->dates === [] && $this->authors === [];
    }

    /**
     * Parametri di query canonici: ['eventi' => [...], 'autori' => [...]],
     * senza le chiavi vuote.
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->dates !== []) {
            $query['eventi'] = $this->dates;
        }

        if ($this->authors !== []) {
            $query['autori'] = $this->authors;
        }

        return $query;
    }

    /** URL dello ZIP per questa selezione. */
    public function zipUrl(): string
    {
        return route('download.zip', $this->toQuery());
    }

    /**
     * URL della pagina galleria:
     * - 1 data e 0 autori → pagina evento (rotta events.show, piano 04)
     * - altrimenti → pagina galleria generica (rotta gallery.show, piano 04)
     */
    public function url(): string
    {
        if (count($this->dates) === 1 && $this->authors === []) {
            return route('events.show', ['date' => $this->dates[0]]);
        }

        return route('gallery.show', $this->toQuery());
    }

    /**
     * Query base per le foto della selezione, nell'ordine canonico:
     * data evento → taken_at (null in fondo) → cartella autore → filename → id.
     */
    public function photosQuery(): Builder
    {
        return Photo::query()
            ->select('photos.*')
            ->join('galleries', 'galleries.id', '=', 'photos.gallery_id')
            ->join('events',    'events.id',    '=', 'galleries.event_id')
            ->join('authors',   'authors.id',   '=', 'galleries.author_id')
            ->when($this->dates   !== [], fn ($q) => $q->whereIn('events.date',    $this->dates))
            ->when($this->authors !== [], fn ($q) => $q->whereIn('authors.slug',   $this->authors))
            ->orderBy('events.date')
            ->orderByRaw('photos.taken_at is null')
            ->orderBy('photos.taken_at')
            ->orderBy('authors.folder')
            ->orderBy('photos.filename')
            ->orderBy('photos.id');
    }

    /** @return Collection<int, Photo>  con gallery.author e gallery.event caricati */
    public function photos(): Collection
    {
        return $this->photosQuery()->with('gallery.author', 'gallery.event')->get();
    }

    /**
     * Brani audio della selezione, nello stesso ordine delle foto
     * (data evento → autore → filename → id).
     *
     * @return Collection<int, Track>
     */
    public function tracks(): Collection
    {
        return Track::query()
            ->select('tracks.*')
            ->join('galleries', 'galleries.id', '=', 'tracks.gallery_id')
            ->join('events',    'events.id',    '=', 'galleries.event_id')
            ->join('authors',   'authors.id',   '=', 'galleries.author_id')
            ->when($this->dates   !== [], fn ($q) => $q->whereIn('events.date',  $this->dates))
            ->when($this->authors !== [], fn ($q) => $q->whereIn('authors.slug', $this->authors))
            ->orderBy('events.date')
            ->orderBy('authors.folder')
            ->orderBy('tracks.filename')
            ->orderBy('tracks.id')
            ->with('gallery.author', 'gallery.event')
            ->get();
    }

    /** Somma dei byte degli originali (≈ dimensione dello ZIP in modalità STORE). */
    public function totalBytes(): int
    {
        return (int) $this->photosQuery()->sum('photos.size');
    }

    /** Dimensione formattata (es. "1,2 GB"). */
    public function formattedSize(): string
    {
        if (extension_loaded('intl')) {
            return Number::fileSize($this->totalBytes(), precision: 0);
        }

        $bytes = $this->totalBytes();
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $size = $bytes / pow(1024, $power);

        return round($size) . ' ' . ($units[$power] ?? 'B');
    }

    /**
     * Eventi che hanno almeno una foto nella selezione, ordinati per data.
     *
     * @return Collection<int, Event>
     */
    public function events(): Collection
    {
        return Event::query()
            ->whereHas('galleries.photos', function ($q) {
                $q->select('photos.*')
                    ->join('galleries as g2', 'g2.id', '=', 'photos.gallery_id')
                    ->join('events as e2',    'e2.id', '=', 'g2.event_id')
                    ->join('authors as a2',   'a2.id', '=', 'g2.author_id')
                    ->when($this->dates   !== [], fn ($q) => $q->whereIn('e2.date',  $this->dates))
                    ->when($this->authors !== [], fn ($q) => $q->whereIn('a2.slug',  $this->authors));
            })
            ->orderBy('date')
            ->get();
    }

    /**
     * Autori con almeno una foto nella selezione, ordinati per cartella.
     *
     * @return Collection<int, Author>
     */
    public function authorsInvolved(): Collection
    {
        return Author::query()
            ->whereHas('galleries.photos', function ($q) {
                $q->select('photos.*')
                    ->join('galleries as g2', 'g2.id', '=', 'photos.gallery_id')
                    ->join('events as e2',    'e2.id', '=', 'g2.event_id')
                    ->join('authors as a2',   'a2.id', '=', 'g2.author_id')
                    ->when($this->dates   !== [], fn ($q) => $q->whereIn('e2.date',  $this->dates))
                    ->when($this->authors !== [], fn ($q) => $q->whereIn('a2.slug',  $this->authors));
            })
            ->orderBy('folder')
            ->get();
    }

    /**
     * Titolo della selezione:
     * - 1 data, 0 autori              → titolo dell'evento
     * - 1 data, ≥1 autori             → "Titolo evento — Nome1, Nome2"
     * - 1 autore, 0 date              → "Foto di <nome autore>"
     * - 1 autore, ≥2 date             → "<nome autore> — N eventi"
     * - altrimenti                    → "Selezione di foto"
     */
    public function title(): string
    {
        $dateCount   = count($this->dates);
        $authorCount = count($this->authors);

        if ($dateCount === 1 && $authorCount === 0) {
            $event = Event::where('date', $this->dates[0])->first();

            return $event ? $event->displayTitle() : 'Evento del ' . $this->dates[0];
        }

        if ($dateCount === 1 && $authorCount >= 1) {
            $event = Event::where('date', $this->dates[0])->first();
            $title = $event ? $event->displayTitle() : 'Evento del ' . $this->dates[0];

            $names = Author::whereIn('slug', $this->authors)
                ->orderBy('folder')
                ->pluck('name')
                ->map(fn ($n) => \Illuminate\Support\Str::ucfirst($n))
                ->implode(', ');

            return $names !== '' ? $title . ' — ' . $names : $title;
        }

        if ($authorCount === 1 && $dateCount === 0) {
            $author = Author::where('slug', $this->authors[0])->first();
            $name   = $author ? \Illuminate\Support\Str::ucfirst($author->name) : $this->authors[0];

            return 'Foto di ' . $name;
        }

        if ($authorCount === 1 && $dateCount >= 2) {
            $author = Author::where('slug', $this->authors[0])->first();
            $name   = $author ? \Illuminate\Support\Str::ucfirst($author->name) : $this->authors[0];
            $n      = $dateCount;

            return $name . ' — ' . $n . ' ' . ($n === 1 ? 'evento' : 'eventi');
        }

        return 'Selezione di foto';
    }

    /**
     * Descrizione breve per i meta tag (piano 08):
     * "N foto, M brani — data" (senza i brani se zero, senza la data se più date o nessuna).
     */
    public function description(): string
    {
        $photoCount = $this->photosQuery()->count();
        $trackCount = $this->tracks()->count();

        $parts = [];
        $parts[] = $photoCount . ' ' . ($photoCount === 1 ? 'foto' : 'foto');

        if ($trackCount > 0) {
            $parts[] = $trackCount . ' ' . ($trackCount === 1 ? 'brano' : 'brani');
        }

        $text = implode(', ', $parts);

        if (count($this->dates) === 1) {
            $text .= ' — ' . $this->dates[0];
        }

        return $text;
    }

    /**
     * Nome del file ZIP da scaricare:
     * - 1 data e 0 autori → foto-AAAA-MM-GG.zip
     * - 1 autore e 0 date → foto-<slug>.zip
     * - altrimenti        → foto.zip
     */
    public function zipFileName(): string
    {
        if (count($this->dates) === 1 && $this->authors === []) {
            return 'foto-' . $this->dates[0] . '.zip';
        }

        if (count($this->authors) === 1 && $this->dates === []) {
            return 'foto-' . $this->authors[0] . '.zip';
        }

        return 'foto.zip';
    }

    /** Valori unici, ordinati, senza stringhe vuote. */
    private static function normalize(array $values): array
    {
        return collect($values)
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
