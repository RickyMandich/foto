# Implementation plan 02 — selezione di gallerie (evento, autore, più eventi)

Stato: **da eseguire** (può partire in parallelo al piano 01: non tocca l'interfaccia).
Quando è completato va rinominato `implementationPlan-V-02-selezioneGalleria.md`.

## Obiettivo e ambito

Punti 2-3 dell'analisi: una "galleria" visualizzata è sempre una **selezione** di foto definita da due liste, `eventi[]` (date) e `autori[]` (slug), combinate in AND. Coprono:

- un evento con tutti gli autori: `eventi=[2025-09-20]`;
- un evento con solo alcuni autori: `eventi=[2025-09-20]`, `autori=[anna]`;
- più eventi di un autore (e tutte le sue foto): `autori=[anna]`, con o senza `eventi`.

Vincolo dell'analisi (sezione 4): **lo ZIP contiene esattamente la selezione visualizzata**. Per garantirlo, pagina e ZIP usano la stessa classe, con lo stesso ordine delle foto.

Questo piano crea solo la logica (nessuna pagina) e fa usare la classe a `DownloadController`.

## Decisioni

- Ordine delle foto in una selezione: data dell'evento crescente, poi data di scatto (EXIF `taken_at`, le foto senza data in fondo), poi cartella autore, nome file, id. Così in un evento con più autori le foto si fondono in ordine cronologico, e con più eventi si legge una "storia" dal più vecchio al più recente. (Gli orologi delle macchine fotografiche di autori diversi possono non essere allineati: è accettato.)
- Limiti: al massimo 20 date e 20 autori per richiesta (protezione da richieste enormi).
- Una selezione senza foto è 404 (nelle pagine) o 404 (ZIP, come oggi); parametri non validi sono 404 nelle pagine e 422 per lo ZIP (comportamento attuale, i test esistenti restano invariati).
- Le liste sono normalizzate (valori unici, ordinati), così a selezioni uguali corrispondono URL uguali.

## Fase 1 — Classe `App\Support\GallerySelection`

File `app/Support/GallerySelection.php`. Interfaccia:

```php
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

    /** @param list<string> $dates AAAA-MM-GG  @param list<string> $authors slug */
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

    /** Legge eventi[] e autori[] dalla richiesta; $failStatus è lo stato HTTP se i valori non sono validi. */
    public static function fromRequest(Request $request, int $failStatus = 404): self
    {
        $validator = Validator::make($request->query(), [
            'eventi' => ['array', 'max:'.self::MAX_ITEMS],
            'eventi.*' => ['string', 'date_format:Y-m-d'],
            'autori' => ['array', 'max:'.self::MAX_ITEMS],
            'autori.*' => ['string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
        ]);

        abort_if($validator->fails(), $failStatus);

        return self::make($request->query('eventi', []), $request->query('autori', []));
    }

    public function isEmpty(): bool { /* nessuna data e nessun autore */ }

    /** Parametri di query canonici: ['eventi' => [...], 'autori' => [...]], senza le chiavi vuote. */
    public function toQuery(): array { /* ... */ }

    public function zipUrl(): string { return route('download.zip', $this->toQuery()); }

    public function url(): string { /* events.show se 1 data e nessun autore (rotta del piano 04), altrimenti gallery.show */ }

    public function photosQuery(): Builder { /* vedi sotto */ }

    /** @return Collection<int, Photo> con gallery.author e gallery.event caricati */
    public function photos(): Collection { return $this->photosQuery()->with('gallery.author', 'gallery.event')->get(); }

    /** @return Collection<int, Track> stessa selezione, ordinate per evento, autore, nome file */
    public function tracks(): Collection { /* ... */ }

    /** Somma delle dimensioni degli originali (= dimensione dello ZIP, che è in modalità STORE, più qualche KB). */
    public function totalBytes(): int { /* sum('photos.size') */ }

    public function formattedSize(): string { return Number::fileSize($this->totalBytes(), precision: 0); }

    /** @return Collection<int, Event> eventi che hanno almeno una foto nella selezione, per data */
    public function events(): Collection { /* ... */ }

    /** @return Collection<int, Author> autori con almeno una foto nella selezione, per cartella */
    public function authorsInvolved(): Collection { /* ... */ }

    public function title(): string { /* vedi Fase 2 */ }

    public function zipFileName(): string { /* vedi Fase 3 */ }

    private static function normalize(array $values): array
    {
        return collect($values)->filter(fn ($v) => is_string($v) && $v !== '')->unique()->sort()->values()->all();
    }
}
```

`photosQuery()` (la stessa per pagina e ZIP):

```php
public function photosQuery(): Builder
{
    return Photo::query()
        ->select('photos.*')
        ->join('galleries', 'galleries.id', '=', 'photos.gallery_id')
        ->join('events', 'events.id', '=', 'galleries.event_id')
        ->join('authors', 'authors.id', '=', 'galleries.author_id')
        ->when($this->dates !== [], fn ($q) => $q->whereIn('events.date', $this->dates))
        ->when($this->authors !== [], fn ($q) => $q->whereIn('authors.slug', $this->authors))
        ->orderBy('events.date')
        ->orderByRaw('photos.taken_at is null')
        ->orderBy('photos.taken_at')
        ->orderBy('authors.folder')
        ->orderBy('photos.filename')
        ->orderBy('photos.id');
}
```

`orderByRaw('photos.taken_at is null')` funziona uguale su MariaDB e su SQLite (usato dai test). `tracks()` usa gli stessi join e filtri su `tracks`.

## Fase 2 — Titolo e descrizione

`title()`:
- una data (con o senza filtro autori): titolo dell'evento (`Event::displayTitle()`); con il filtro, `"<titolo> — Anna, Luca"` (nomi degli autori);
- un solo autore e nessuna data: `"Foto di <nome autore>"`;
- un solo autore e più date: `"<nome autore> — N eventi"`;
- altrimenti: `"Selezione di foto"`.

`description()` (per i meta tag del piano 08): `"<N> foto, <M> brani — <data o intervallo di date>"`, senza i brani se zero.

## Fase 3 — `DownloadController` sulla stessa classe

In `DownloadController::zip`: `$selection = GallerySelection::fromRequest($request, 422); abort_if($selection->isEmpty(), 422, ...); $photos = $selection->photos(); abort_if($photos->isEmpty(), 404);`. Il resto (streaming, STORE, `safe()`) non cambia.

Nome del file (`zipFileName()`): una data e nessun autore → `foto-AAAA-MM-GG.zip` (come oggi); un solo autore e nessuna data → `foto-<slug>.zip`; altro → `foto.zip`.

Attenzione: il comportamento attuale per parametri non validi deve restare 422 anche per le richieste non JSON (oggi con `validate()` una richiesta web riceverebbe un redirect): `abort_if($validator->fails(), 422)` lo garantisce.

## Fase 4 — Test (Pest)

`tests/Feature/Media/GallerySelectionTest.php` (usa `makeMediaRoot()`, `putImage()` e `MediaSynchronizer`, oppure crea le righe con `Author`/`Event`/`Gallery`/`Photo::create` come negli altri test):

- ordine: due autori nello stesso evento con `taken_at` intercalate → foto fuse per data di scatto; foto senza `taken_at` in fondo; con più eventi, prima l'evento più vecchio;
- filtri in AND: evento + autore, solo autore su più eventi, solo date;
- normalizzazione: `['b','a','a']` → `['a','b']`; `toQuery()` omette le liste vuote; `zipUrl()` contiene `eventi%5B0%5D=…`;
- `fromRequest`: valori non validi → 404 (e 422 con `$failStatus`), più di 20 valori → errore;
- `title()` per tutti i casi della Fase 2;
- `totalBytes()` e `formattedSize()`;
- `events()`/`authorsInvolved()` non includono eventi o autori senza foto nella selezione.

`ZipDownloadTest` (esistente): resta verde; aggiungere un caso "solo autore su due eventi" (nomi e ordine delle voci dello ZIP) e un caso "l'ordine dello ZIP coincide con `GallerySelection::photos()`".

## Fase 5 — Documentazione

- `README.md`: nel paragrafo sul download ZIP, descrivere la selezione (`eventi[]`, `autori[]`, AND) e il nome del file.
- `todo.md`: spuntare il piano 02.

## Criteri di accettazione

1. `GallerySelection` è l'unico posto in cui si decide quali foto fanno parte di una galleria e in che ordine.
2. L'ordine dello ZIP coincide con quello che mostreranno le pagine.
3. Stato HTTP dello ZIP invariato (422 senza parametri o con valori non validi, 404 senza foto).
4. `composer test` passa.
