# Implementation plan 07 — pagine degli autori

Stato: **da eseguire**. Dipende dai piani 01, 02, 03 (componente `event-card`) e 04 (rotte della galleria).
Quando è completato va rinominato `implementationPlan-V-07-paginaAutore.md`.

## Obiettivo e ambito

Punto 5 dell'analisi: la pagina di un autore, con i suoi dati (nome, descrizione, recapiti facoltativi da `autore.md`), i suoi eventi e la possibilità di guardare o scaricare **più eventi dello stesso autore** (punto 3).

- `GET /autori`: elenco degli autori;
- `GET /autori/{slug}`: profilo e eventi dell'autore.

**Fuori ambito**: modifica dei dati dell'autore dal sito (restano nel file `autore.md` sul disco, come deciso: nessuna pagina admin), foto del profilo (non esiste un campo: se servirà, `autore.jpg` nella cartella dell'autore e una colonna in più, in un piano a parte).

## Decisioni

- **I recapiti sono pubblicati solo se l'autore li ha scritti in `autore.md`**: l'assenza di un campo significa che non si mostra nulla. Campi ammessi: `email`, `instagram`, `sito`, `telefono` (quelli di `docs/autore.example.md`); campi sconosciuti si ignorano.
- **Sicurezza dei link**: i recapiti vengono trasformati in link dal codice, mai usati come HTML o come URL grezzi:
  - `email` → `mailto:` solo se supera `filter_var(..., FILTER_VALIDATE_EMAIL)`;
  - `instagram` → `https://www.instagram.com/<profilo>/` dopo aver tolto la `@` e solo se il profilo combacia con `^[A-Za-z0-9._]{1,30}$`;
  - `sito` → solo schemi `http` e `https`, altrimenti scartato (niente `javascript:`);
  - `telefono` → `tel:` con soli cifre e `+`, mostrato com'è scritto.
  Tutti i link esterni hanno `rel="noopener noreferrer nofollow"`.
- **Descrizione in markdown** (`bio`): resa con `Str::markdown($bio, ['html_input' => 'strip', 'allow_unsafe_links' => false])`. Lunghezza massima mostrata: 5000 caratteri (il resto si taglia).
- **Un autore senza foto non compare** negli elenchi (può succedere tra la rimozione dei file e la sincronizzazione).
- Ordine dell'elenco autori: per nome, senza distinzione di maiuscole.

## Fase 1 — Modello

`Author`:
- `events()`: eventi dell'autore con almeno una foto, per data decrescente (`Event::whereHas('galleries', fn ($g) => $g->where('author_id', $this->id)->whereHas('photos'))`), con `withCount` delle sole foto e dei soli brani **di quell'autore** (conteggi sulla relazione `galleries` filtrata per `author_id`);
- `contactLinks(): array` → lista di `['label' => 'Instagram', 'text' => '@profilo', 'href' => 'https://…', 'external' => true]` con le regole sopra; voci non valide scartate;
- `bioHtml(): ?HtmlString` con il markdown reso e tagliato.

Attributo calcolato `photos_count` per l'elenco (`withCount` tramite `galleries.photos`).

## Fase 2 — Controller e rotte

```php
Route::get('/autori', [AuthorController::class, 'index'])->name('authors.index');
Route::get('/autori/{slug}', [AuthorController::class, 'show'])
    ->where('slug', '[a-z0-9\-]+')->name('authors.show');
```

`AuthorController@index`: autori con `withCount` di foto ed eventi, filtrati per `photos_count > 0`, ordinati per nome.
`AuthorController@show`: `Author::where('slug', $slug)->firstOrFail()` (404 se non ha foto), eventi dell'autore (paginati a 12 come la home), e per ciascun evento la copertina presa **dalle foto di quell'autore** (`GallerySelection::forEvent($event, [$author->slug])->photosQuery()->first()`).

## Fase 3 — Viste

3.1 `authors/index.blade.php`: griglia di card (nome, descrizione ridotta a una riga senza markdown — `Str::limit(strip_tags(...), 140)` — numero di eventi e foto).

3.2 `authors/show.blade.php`:
- intestazione: nome (`<h1>`), descrizione, elenco dei recapiti (icone `user`, ecc.);
- due azioni principali: "Guarda tutte le foto" → `route('gallery.show', ['autori' => [$slug]])` e "Scarica tutte (ZIP, N foto, circa X)" → `GallerySelection::forAuthor($author)->zipUrl()`;
- **eventi dell'autore** come card (`x-event-card`) con link a `route('events.show', [$event->date, 'autori' => [$slug]])` (l'evento, filtrato per questo autore);
- **selezione di più eventi** (punto 3): sopra l'elenco un `<form method="get" action="{{ route('gallery.show') }}">` con un campo nascosto `autori[]=<slug>` e, su ogni card, una casella `<input type="checkbox" name="eventi[]" value="AAAA-MM-GG">` (etichetta "Seleziona"); due pulsanti "Guarda la selezione" (submit verso `gallery.show`) e "Scarica la selezione (ZIP)" (`formaction="{{ route('download.zip') }}"`). Funziona senza JavaScript. Senza caselle selezionate la richiesta equivale a "tutte le foto dell'autore" (stessa selezione di `forAuthor`).
- paginazione sotto l'elenco; **le caselle selezionate non sopravvivono al cambio pagina**: documentato nel README (con 8-10 eventi all'anno per pagina da 12 non è un problema per anni).

## Fase 4 — Collegamenti

- Link ai profili: nel titolo della galleria (piano 04) accanto ai nomi degli autori; nelle card della home, il nome dell'autore quando è uno solo.
- Nell'header (piano 01) togliere il controllo `Route::has('authors.index')`.

## Fase 5 — Test (Pest)

`tests/Feature/AuthorPagesTest.php`:
- elenco: autori con foto, ordinati per nome, autore senza foto escluso;
- profilo: nome, descrizione in HTML, conteggi; 404 per slug sconosciuto o senza foto;
- sicurezza: `<script>` e `<img onerror>` nel markdown vengono eliminati; link `javascript:` nel markdown non diventa un link; `sito: javascript:alert(1)` scartato; `instagram: "@a b"` scartato; email non valida scartata; `rel` presente sui link esterni;
- recapiti assenti → nessuna sezione recapiti;
- `contactLinks()`: tutti i campi validi, normalizzazione di `@profilo` e telefono;
- eventi dell'autore: solo i suoi, con conteggi che escludono le foto di altri autori nello stesso evento e copertina presa dalle sue foto;
- il form multi-evento: gli `href`/`action` portano a `gallery.show` e `download.zip` con `autori[]`; aprendo la richiesta equivalente si ottengono solo le foto selezionate.

## Fase 6 — Documentazione

- `README.md`: sezione "Autori" (campi di `autore.md`, link generati, limiti di sicurezza, selezione di più eventi); aggiornare la tabella delle rotte.
- `todo.md`: spuntare il piano 07.

## Criteri di accettazione

1. Ogni autore con foto ha la sua pagina con descrizione e recapiti solo se presenti in `autore.md`.
2. Da un autore si possono guardare o scaricare tutte le sue foto oppure più eventi a scelta.
3. Nessun contenuto di `autore.md` può inserire HTML o link con schemi pericolosi.
4. `composer test` passa.
