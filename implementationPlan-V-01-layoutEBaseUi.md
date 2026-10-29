# Implementation plan 01 — layout, tema e componenti di base

Stato: **da eseguire**. Primo dei piani per finire il sito (dopo il punto 1, "foto fuori da git"). Ordine completo e dipendenze: sezione "Piani per finire il sito" in `todo.md`.
Quando è completato va rinominato `implementationPlan-V-01-layoutEBaseUi.md`.

## Obiettivo e ambito

Dare al sito lo scheletro grafico su cui poggiano tutte le pagine successive: layout Blade, tema scuro, icone, componenti piccoli, pagine di errore, flusso di sviluppo dell'interfaccia e un comando che genera foto finte per lavorarci senza i 4 GB di originali.

**Fuori ambito**: le pagine vere (home, evento, autore: piani 03-07), OpenGraph e SEO (piano 08).

## Decisioni (valgono per tutti i piani 01-09)

- **Stack confermato (punto 6 dell'analisi)**: Laravel 12 + Blade + Tailwind CSS 4 + JavaScript vanilla (moduli ES con Vite). Niente Bootstrap, Font Awesome, JSZip, Alpine o Livewire, e **niente risorse da CDN**: la VM ha meno di 1 GB di RAM e il sito deve funzionare senza host esterni (utile anche per una CSP stretta, piano 09). Annotare la conferma in `AnalyzeForRefactor.md` (sezione 5) a fine esecuzione.
- **Tema**: scuro, forzato (come il vecchio `data-bs-theme="dark"`), `color-scheme: dark`. Palette Tailwind `neutral` per i fondi, `sky` per gli accenti.
- **Font**: stack di sistema (niente font web). Si toglie `Instrument Sans` da `resources/css/app.css`, che non viene mai caricato.
- **Icone**: SVG inline (Heroicons, licenza MIT, copiati a mano nei partial Blade; nessun pacchetto).
- **Lingua**: italiano (`APP_LOCALE=it`); date con `translatedFormat('j F Y')`.
- **URL e nomi di rotta** (fissati qui, usati da tutti i piani):

| URL | Nome | Piano |
|---|---|---|
| `/` | `home` | 03 |
| `/eventi/{data}` (data `AAAA-MM-GG`) | `events.show` | 04 |
| `/galleria?eventi[]=…&autori[]=…` | `gallery.show` | 04 |
| `/autori` | `authors.index` | 07 |
| `/autori/{slug}` | `authors.show` | 07 |
| `/download/zip?eventi[]=…&autori[]=…` | `download.zip` | già fatto |
| `/sitemap.xml` | `sitemap` | 08 |

- **Parametri di query comuni**: `eventi[]` (date), `autori[]` (slug), `f` (id della foto aperta), `vista=griglia`.
- **Struttura JS**: `resources/js/app.js` carica i moduli in base all'attributo `data-module` dell'elemento (code splitting di Vite); i moduli stanno in `resources/js/gallery/` e le funzioni pure (testabili) in `resources/js/lib/`.

## Fase 1 — Allineamento della configurazione

1.1 `.env.example` e `.env`: `APP_NAME=Foto`, `APP_LOCALE=it`, `APP_FALLBACK_LOCALE=it`, `APP_FAKER_LOCALE=it_IT`, `FILESYSTEM_DISK=public` (erano rimasti `Laravel`, `en`, `local`: il punto 1.1 del piano precedente non era stato applicato al `.env`). `APP_URL` nel `.env` locale: `http://localhost:88` se si lavora con il compose di sviluppo.

1.2 Eseguire `php artisan config:clear` e controllare che `php artisan route:list` non mostri più le route `storage.local` (compaiono solo con il disk `local`).

## Fase 2 — Toolchain frontend

2.1 `resources/css/app.css`:

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';

@theme {
    --font-sans: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

@layer base {
    html { color-scheme: dark; }
    body { @apply bg-neutral-950 text-neutral-100 antialiased; }
    a { @apply text-sky-300 hover:text-sky-200; }
    :focus-visible { @apply outline-2 outline-offset-2 outline-sky-400; }
}
```

2.2 `resources/js/app.js` (carica solo i moduli presenti nella pagina):

```js
const modules = {
    gallery: () => import('./gallery/index.js'),
};

document.querySelectorAll('[data-module]').forEach((el) => {
    modules[el.dataset.module]?.().then((m) => m.default(el));
});
```

2.3 Togliere `axios` e `resources/js/bootstrap.js` (nessuna richiesta XHR nel sito): `npm uninstall axios`, cancellare `bootstrap.js`, committare `package-lock.json`.

## Fase 3 — Layout Blade

3.1 `resources/views/components/layout.blade.php` (componente anonimo `<x-layout>`):

```blade
@props([
    'title' => null,
    'description' => null,
    'app' => false,   // true: pagina "a tutto schermo" senza header e footer (pagina galleria)
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name') }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    @isset($head){{ $head }}@endisset
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full {{ $app ? 'h-dvh overflow-hidden flex flex-col' : '' }}">
    <a href="#contenuto" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-neutral-900 focus:p-3">Vai al contenuto</a>

    @unless ($app)
        <header class="border-b border-neutral-800 bg-neutral-900/60">
            <nav class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-3" aria-label="Principale">
                <a href="{{ route('home') }}" class="text-lg font-semibold text-neutral-100">{{ config('app.name') }}</a>
                <a href="{{ route('home') }}" class="text-sm">Eventi</a>
                <a href="{{ route('authors.index') }}" class="text-sm">Autori</a>
            </nav>
        </header>
    @endunless

    <main id="contenuto" class="{{ $app ? 'min-h-0 flex-1' : 'mx-auto max-w-7xl px-4 py-6' }}">
        {{ $slot }}
    </main>

    @unless ($app)
        <footer class="mx-auto max-w-7xl px-4 py-8 text-sm text-neutral-500">
            © {{ now()->year }} {{ config('app.name') }}
        </footer>
    @endunless
</body>
</html>
```

Note: la rotta `authors.index` esiste solo dal piano 07; finché non c'è, il link va nascosto con `@if (Route::has('authors.index'))` (rimuovere il controllo a fine piano 07).

3.2 Componenti piccoli (anonimi, in `resources/views/components/`):
- `icon.blade.php`: `<svg {{ $attributes->merge(['class' => 'size-5', 'aria-hidden' => 'true', 'fill' => 'none', 'viewBox' => '0 0 24 24', 'stroke' => 'currentColor', 'stroke-width' => '1.5']) }}>@include('icons.'.$name)</svg>`.
- `badge.blade.php`: `<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-neutral-800 px-2 py-0.5 text-xs text-neutral-300']) }}>{{ $slot }}</span>`.
- `button.blade.php`: `<a>` o `<button>` (prop `href`) con le varianti `primary`, `outline` (bordo `neutral-600`), `ghost`; dimensione minima 40 px sul lato corto per il tocco.
- Partial delle icone in `resources/views/icons/`, dai path di Heroicons v2 (outline): `arrow-left`, `photo`, `squares-2x2`, `play`, `pause`, `forward`, `arrow-down-tray`, `speaker-wave`, `arrows-pointing-out`, `chevron-left`, `chevron-right`, `user`, `calendar`, `musical-note`, `share`, `x-mark`, `archive-box-arrow-down`.

## Fase 4 — Pagine di errore

`resources/views/errors/{404,419,429,500,503}.blade.php`, tutte con `<x-layout>`, titolo e frase in italiano ("Pagina non trovata", "Troppe richieste, riprova tra un minuto", ecc.) e un link alla home. Nessun dettaglio tecnico (Laravel mostra già la pagina di debug con `APP_DEBUG=true`).

## Fase 5 — Foto finte per sviluppare l'interfaccia

Comando `php artisan media:demo` (`app/Console/Commands/MediaDemo.php`), attivo **solo** con `APP_ENV=local` o `testing`:

- Crea in `storage/app/public` due autori con prefisso `demo-` (`demo-anna`, `demo-luca`, ciascuno con `autore.md`: uno con descrizione e recapiti, l'altro senza), e tre eventi: uno solo di Anna, uno solo di Luca, uno **della stessa data per entrambi** con titoli che differiscono per le maiuscole.
- Genera le foto con GD (rettangoli colorati con il numero della foto scritto sopra, formati orizzontali e verticali, 4000x2667 per provare davvero le versioni) e nomi `IMGP0001.JPG`…; opzione `--photos=30` per galleria (default 30).
- Non crea audio (servono file veri): copiare a mano 2 mp3 piccoli in una cartella demo per provare il player (piano 06).
- `--clean` cancella le cartelle `demo-*` e lancia `media:sync` per toglierle dal database.
- Dopo la generazione il comando suggerisce `php artisan media:sync`.

Test (`tests/Feature/Media/MediaDemoTest.php`): usa `makeMediaRoot()`, verifica cartelle e conteggi, `--clean`, rifiuto con `APP_ENV=production`.

## Fase 6 — Flusso di sviluppo dell'interfaccia con Docker

Nel compose di sviluppo il codice sta nell'immagine, quindi ogni modifica richiederebbe `up -d --build`. Per lavorare sulle viste:

6.1 In `docker-compose.dev.yml`, servizio `app`, aggiungere `- ./resources/views:/var/www/html/resources/views:ro` (le viste Blade si ricompilano da sole in base alla data di modifica).

6.2 Per CSS e JS, sul PC (Node 20): `npm install` e poi `npm run build -- --watch`. Scrive in `public/build`, che è già montato in `app` e servito da `nginx`: il browser vede le modifiche con un refresh. Nota: l'entrypoint ricopia gli asset dell'immagine in `public/build` a ogni **avvio** del container, quindi dopo un `restart` va rilanciato il build.

6.3 Documentare il flusso nel README (sezione "How to use"). Le modifiche a PHP (controller, modelli) richiedono ancora `up -d --build`.

## Fase 7 — Test

`tests/Feature/LayoutTest.php`:
- una pagina qualunque ha `lang="it"`, il titolo `… — Foto` e il link "Vai al contenuto";
- l'HTML di una pagina non contiene riferimenti a host esterni (`https://cdn`, `googleapis`, `cloudflare`) né a `<script src="http`: protegge la scelta "niente CDN";
- `/pagina-che-non-esiste` risponde 404 con il testo italiano;
- `<x-layout :app="true">` non produce `<header>` né `<footer>`.

## Fase 8 — Documentazione

- `README.md`: sezione "Interfaccia" (stack, tema, struttura di `resources/`, rotte della tabella sopra con lo stato di ciascuna), `media:demo` nei Comandi, flusso della Fase 6 in "How to use".
- `todo.md`: spuntare il piano 01.

## Criteri di accettazione

1. `docker compose -f docker-compose.dev.yml up -d --build` e `npm run build` senza errori; `route:list` senza `storage.local`.
2. Layout con header, footer e skip link; versione `app` senza chrome.
3. Nessuna richiesta a host esterni (verificato dal test e dalla scheda Rete del browser).
4. `php artisan media:demo` + `media:sync` popolano il database con 3 eventi e 2 autori di prova.
5. `composer test` passa.
