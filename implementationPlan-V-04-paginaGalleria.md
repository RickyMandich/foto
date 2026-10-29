# Implementation plan 04 — pagina della galleria (evento e selezione)

Stato: **da eseguire**. Dipende dai piani 01 e 02; la home (03) la collega. Il comportamento nel browser (carosello, autoplay, tastiera) è nel piano 05, l'audio nel 06: qui si costruisce la pagina lato server, con una griglia che funziona anche senza JavaScript.
Quando è completato va rinominato `implementationPlan-V-04-paginaGalleria.md`.

## Obiettivo e ambito

La pagina che sostituisce `gallery.html`:

- `GET /eventi/{data}`: un evento con tutti i suoi autori, con filtro facoltativo `?autori[]=slug`;
- `GET /galleria?eventi[]=…&autori[]=…`: una selezione qualsiasi (per esempio più eventi di un autore).

Entrambe mostrano la stessa vista, costruita da un `GallerySelection` (piano 02): barra degli strumenti, area principale con carosello e griglia, bottone ZIP della selezione, dati per audio e carosello.

**Fuori ambito**: JavaScript del carosello (05), player audio (06), meta tag di condivisione (08).

## Decisioni

- **Pagina a tutto schermo** (`<x-layout :app="true">`, come il vecchio `gallery.html`): barra in alto fissa, sotto l'area che occupa il resto dell'altezza (`h-dvh` con `min-h-0 flex-1`).
- **Vista predefinita: carosello**, come prima; la griglia si apre con il toggle o con `?vista=griglia`.
- **Funziona senza JavaScript**: la griglia è HTML vero (ogni miniatura è un link all'originale); il carosello è disegnato dal JavaScript e, senza JavaScript, un `<noscript><style>` nasconde il carosello e mostra la griglia.
- **Foto aperta**: `?f=<id della foto>`. Si usa l'id (stabile finché la riga resta in database, cioè finché il file non viene cancellato o rinominato) perché il nome file non è unico tra autori diversi. Un `f` che non appartiene alla selezione si ignora (si parte dalla prima foto).
- **Testi alternativi**: niente nomi di file ("IMGP5215.JPG"); `alt` = "Foto 12 di 340 — <titolo> (<autore>)".
- Niente `width`/`height` del database nelle immagini (sono pre-orientamento EXIF, vedi piano 03): le immagini si adattano con `object-contain` e `object-cover`.
- Se la selezione è vuota o non valida: 404.

## Fase 1 — Rotte e controller

`routes/web.php`:

```php
Route::get('/eventi/{date}', [GalleryController::class, 'event'])
    ->where('date', '\d{4}-\d{2}-\d{2}')->name('events.show');
Route::get('/galleria', [GalleryController::class, 'selection'])->name('gallery.show');
```

`app/Http/Controllers/GalleryController.php`:

```php
public function event(Request $request, string $date): View
{
    $event = Event::where('date', $date)->firstOrFail();
    $filter = GallerySelection::fromRequest($request)->authors;   // solo autori[] ha senso qui

    return $this->render($request, GallerySelection::forEvent($event, $filter));
}

public function selection(Request $request): View
{
    $selection = GallerySelection::fromRequest($request);
    abort_if($selection->isEmpty(), 404);

    return $this->render($request, $selection);
}

private function render(Request $request, GallerySelection $selection): View
{
    $photos = $selection->photos();
    abort_if($photos->isEmpty(), 404);

    $startId = (int) $request->query('f');
    $startIndex = max(0, $photos->search(fn (Photo $p) => $p->id === $startId) ?: 0);

    return view('gallery.show', [
        'selection' => $selection,
        'photos' => $photos,
        'tracks' => $selection->tracks(),
        'startIndex' => $startIndex,
        'grid' => $request->query('vista') === 'griglia',
    ]);
}
```

(`event()` ignora `eventi[]` della query: per `/eventi/{data}` la data è quella del percorso. `GallerySelection::forEvent` ricava solo la data dall'evento.)

## Fase 2 — Dati per JavaScript

Nella vista, un blocco JSON (non eseguibile, quindi compatibile con una CSP stretta):

```blade
<script type="application/json" id="gallery-data">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
```

`$payload` costruito nel controller (o in una classe `GalleryPayload`):

```php
[
  'start' => $startIndex,
  'photos' => [['id' => 123, 'name' => 'IMGP5215.JPG', 'author' => 'Anna', 'thumb' => '/storage/_derived/thumb/123.webp?v=…', 'web' => '…', 'orig' => '/storage/anna/…/IMGP5215.JPG'], …],
  'tracks' => [['id' => 7, 'title' => 'coro eni', 'url' => '/storage/…'], …],
]
```

Per 850 foto sono circa 170 KB di JSON non compresso (circa 25 KB con gzip: verificare che nginx comprima `application/json`/`text/html`; vedi piano 09). Chiavi corte solo se il peso diventasse un problema.

## Fase 3 — Vista `gallery/show.blade.php`

Struttura (tutta dentro `<x-layout :title="$selection->title()" :app="true">`):

```
<div data-module="gallery" class="flex h-full flex-col" data-view="carousel|grid">
  <header>  barra degli strumenti (sticky, una riga, scorre in orizzontale su schermi stretti)
  <section id="carousel-view">  carosello (riempito dal JS, piano 05)
  <section id="grid-view" hidden>  griglia server-side
  <audio id="audio-player" preload="metadata" hidden>  (piano 06)
  <script type="application/json" id="gallery-data">
</div>
<noscript><style>#carousel-view{display:none}#grid-view{display:block!important}</style></noscript>
```

3.1 **Barra degli strumenti** (da sinistra a destra; nomi e `id` sono il contratto con i piani 05 e 06):
1. link indietro: all'home per un evento, altrimenti `url()->previous()` solo se della stessa origine, sennò la home (`<a href="{{ route('home') }}" aria-label="Torna agli eventi">`);
2. titolo (`<h1>` troncato) con, sotto o a fianco, data e autori;
3. toggle vista carosello/griglia (`role="radiogroup"` con due `<button aria-pressed>`; senza JS la griglia è già visibile);
4. controlli dell'autoplay `id="autoplay-toggle"` (icona pausa/play, `aria-pressed`) e `id="autoplay-interval"` (`type="number" min="1" max="60" value="4"`), visibili solo in vista carosello;
5. `id="download-photo"`: `<a download>` verso l'originale della foto corrente (il JS lo aggiorna; il valore iniziale è la foto `startIndex`);
6. `id="share-photo"` (piano 08);
7. `id="fullscreen"` (piano 05);
8. controlli audio `id="audio-controls"` (play/pausa, successivo, volume, titolo), presenti solo se `$tracks->isNotEmpty()` (piano 06);
9. **bottone ZIP**: link `href="{{ $selection->zipUrl() }}"` con testo "Scarica tutte (ZIP, <N> foto, circa <dimensione>)", dimensione da `$selection->formattedSize()`. È un normale link: il browser mostra il suo avanzamento.

3.2 **Griglia** (`<ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 …">`): per ogni foto un `<li><a href="{{ $photo->url() }}" data-index="{{ $i }}">` con `<img src="thumb" loading="lazy" decoding="async" class="aspect-square w-full object-cover" alt="…">`; `content-visibility: auto` sulle celle per alleggerire pagine con centinaia di foto. Per un evento con più autori, sopra la griglia i filtri (Fase 4).

3.3 **Carosello** (solo lo scheletro, lo riempie il JS): contenitore `id="carousel"` con `class="relative h-full bg-black"`, `aria-roledescription="carosello"`, un contatore `id="counter"` in alto a destra ("12/340"), i pulsanti `id="prev"` e `id="next"` (`aria-label="Foto precedente/successiva"`), e un `<div id="carousel-track" class="flex h-full snap-x snap-mandatory overflow-x-auto">`. La prima immagine (`startIndex`) è già nel markup (`<img src="web" fetchpriority="high">`) per un primo disegno senza attendere il JS.

## Fase 4 — Filtro per autore nella pagina di un evento

Se l'evento ha più di un autore, sopra la griglia (e nella barra, in forma compatta) una riga di "chip": "Tutti" e un chip per autore. Sono link normali: `route('events.show', [$date, 'autori' => [...]])`, uno per autore (chip attivo = autore incluso nel filtro; il click lo aggiunge o lo toglie, "Tutti" azzera). Il filtro cambia la selezione, quindi anche il bottone ZIP e il conteggio.

## Fase 5 — Test (Pest)

`tests/Feature/GalleryPageTest.php`:
- `/eventi/2025-09-20` risponde 200 con titolo, data e un `<img>` per foto nella griglia; data inesistente → 404;
- `?autori[]=anna` mostra solo le foto di Anna e il bottone ZIP ha lo stesso filtro; slug non valido → 404;
- `/galleria?autori[]=anna&eventi[]=…&eventi[]=…` mostra le foto dei due eventi in ordine cronologico; senza parametri → 404;
- il JSON in `#gallery-data` è valido, ha tanti elementi quante foto, `start` corrisponde a `?f=` e un `f` estraneo viene ignorato;
- `href` del bottone ZIP == `$selection->zipUrl()` e, seguendolo, lo ZIP contiene le stesse foto (stesso ordine) della pagina;
- i controlli audio compaiono solo se ci sono brani;
- i nomi dei file non compaiono negli `alt`; il titolo non permette HTML iniettato (un titolo evento con `<script>` viene escapato);
- numero di query costante rispetto al numero di foto.

## Fase 6 — Documentazione

- `README.md`: rotte, parametri di query (`eventi[]`, `autori[]`, `f`, `vista`), struttura della pagina e contratto con il JavaScript.
- `todo.md`: spuntare il piano 04.

## Criteri di accettazione

1. Evento, evento filtrato e selezione multipla si aprono e mostrano le foto giuste, anche con JavaScript disabilitato (griglia con link agli originali).
2. Il bottone ZIP scarica esattamente le foto mostrate.
3. Nessun uso di `width`/`height` del database; `alt` senza nomi di file.
4. `composer test` passa.
