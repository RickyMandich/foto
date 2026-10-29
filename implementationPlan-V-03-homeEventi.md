# Implementation plan 03 — home: elenco degli eventi

Stato: **da eseguire**. Dipende dai piani 01 (layout) e 02 (`GallerySelection`).
Quando è completato va rinominato `implementationPlan-V-03-homeEventi.md`.

## Obiettivo e ambito

Sostituire la pagina di benvenuto di Laravel con la home del sito: la griglia degli eventi, dal più recente, come la vecchia `index.html` ma con miniature leggere, dati veri dal database e senza il bottone "Genera ZIP" lato client (lo ZIP si scarica dalla pagina dell'evento, piano 04).

**Fuori ambito**: la pagina dell'evento (04), i filtri per autore (la pagina autore, 07).

## Decisioni

- Un evento = una card. Contenuto: copertina, titolo (`Event::displayTitle()`), data estesa (`1 ottobre 2026`), badge con numero di foto, di brani (solo se > 0) e di autori (solo se > 1; con un solo autore se ne mostra il nome nella card).
- **Copertina**: la prima foto dell'evento secondo l'ordine di `GallerySelection` (la più vecchia per data di scatto). È la stessa scelta del vecchio sito ("sempre la prima"); la scelta manuale della copertina resta un miglioramento futuro, annotato in `todo.md` (andrebbe espressa su disco, per esempio con un file `copertina.txt` nella cartella dell'evento, perché il filesystem è la source of truth).
- Immagine della card: versione `thumb` (480 px) con `srcset` che offre anche `web` per gli schermi ad alta densità; se le versioni non sono ancora pronte (`versions_ready = false`) `Photo::url()` ricade sull'originale: è accettato, capita solo subito dopo un `media:sync`.
- Paginazione: 12 eventi per pagina (oggi sono 8, il sito crescerà di circa 8-10 eventi all'anno), con la vista di paginazione di Laravel stilata con Tailwind (le classi sono già nel CSS grazie al `@source` di `app.css`).
- Nessuna cache applicativa: poche query e contenuti che cambiano solo dopo `media:sync`.

## Fase 1 — Modello

1.1 `Event`: relazioni `photos(): HasManyThrough` (Photo attraverso Gallery) e `tracks(): HasManyThrough`, e `authors()` come `belongsToMany`-like tramite le gallerie (oppure si usa `galleries.author`). Conteggi con `withCount(['photos', 'tracks', 'galleries as authors_count'])`.

1.2 `Photo::srcset(): string`: `"<thumb> 480w, <web> <N>w"` dove N = `min(config('photos.versions.web.max_side'), max($this->width, $this->height))` (il lato lungo è indifferente all'orientamento EXIF; `width` e `height` del database sono quelli del file prima dell'orientamento e **non vanno usati per l'aspect ratio**). Se `versions_ready` è falso, la stringa è vuota e basta `src`.

## Fase 2 — Controller e rotta

`app/Http/Controllers/HomeController.php`:

```php
public function __invoke(): View
{
    $events = Event::query()
        ->withCount(['photos', 'tracks', 'galleries as authors_count'])
        ->whereHas('galleries.photos')
        ->orderByDesc('date')
        ->paginate(12);

    $covers = $events->getCollection()->mapWithKeys(fn (Event $event) => [
        $event->id => GallerySelection::forEvent($event)->photosQuery()->with('gallery.author')->first(),
    ]);

    return view('home', ['events' => $events, 'covers' => $covers]);
}
```

(Al massimo 12 query piccole per pagina: sufficiente. Se in futuro pesassero, si sostituiscono con una sola query con `row_number() over (partition by event_id …)`, supportata da MariaDB 11 e da SQLite.)

`routes/web.php`: `Route::get('/', HomeController::class)->name('home');` al posto della closure con `welcome`. Rimuovere `resources/views/welcome.blade.php`.

## Fase 3 — Viste

3.1 `resources/views/components/event-card.blade.php` (props `event`, `cover`, `href`, `authors` opzionale): `<article>` con immagine 16:9 (`aspect-video object-cover`, `loading="lazy"`, `decoding="async"`, `alt` descrittivo, per esempio "Copertina: <titolo>"), titolo, data, badge. Se manca la copertina, un riquadro con l'icona `photo`. Tutta la card è un solo link (`<a>` sul titolo con `after:absolute after:inset-0`), così il tocco è comodo e il lettore di schermo legge un solo link.

3.2 `resources/views/home.blade.php`: `<x-layout title="Eventi" description="Gallerie fotografiche di eventi, cortei e presìdi">`, titolo `<h1>`, griglia `grid gap-4 sm:grid-cols-2 lg:grid-cols-3`, `{{ $events->links() }}`; stato vuoto ("Non ci sono ancora eventi.") se non ci sono eventi; il link dell'evento è `route('events.show', $event->date)` (la rotta nasce nel piano 04: fino ad allora usare `url('/eventi/'.$event->date)`).

## Fase 4 — Test (Pest)

`tests/Feature/HomeTest.php`:
- elenco in ordine di data decrescente, titolo e data in italiano;
- conteggi corretti di foto, brani, autori nei badge (brani e autori nascosti quando non servono);
- gli eventi senza foto non compaiono;
- copertina = prima foto per `taken_at`; senza versioni pronte la card usa l'originale;
- paginazione: 13 eventi → 2 pagine, `?page=2` mostra il tredicesimo;
- stato vuoto;
- numero di query costante (`DB::enableQueryLog()`: non cresce con il numero di eventi oltre la dimensione della pagina).

Il vecchio `tests/Feature/ExampleTest.php` (che chiede `/`) va adeguato: la home risponde 200 anche con il database vuoto.

## Fase 5 — Documentazione

- `README.md`: aggiornare lo stato (home implementata) e la tabella delle rotte.
- `todo.md`: spuntare il piano 03; aggiungere in "Miglioramenti futuri": scelta manuale della copertina.

## Criteri di accettazione

1. `/` mostra gli eventi veri (provare con `media:demo` e con foto reali dopo `media:sync`), senza pagina di benvenuto di Laravel.
2. Le card pesano poco: su 12 eventi si scaricano solo miniature (verificare nella scheda Rete).
3. Nessun errore su database vuoto.
4. `composer test` passa.
