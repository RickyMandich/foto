# Implementation plan 08 — condivisione, indicizzazione e rifiniture di rete

Stato: **da eseguire**. Dipende dai piani 04 (pagina galleria) e 05 (barra e `?f=`); il layout è del piano 01.
Quando è completato va rinominato `implementationPlan-V-08-condivisioneESeo.md`.

## Obiettivo e ambito

1. **URL corretti dietro Traefik e Cloudflare** (HTTPS, host giusto): senza, i link assoluti e i redirect di Laravel escono con `http://`.
2. **Anteprime dei link condivisi** (Open Graph e Twitter), anche per una foto specifica (`?f=`): il vecchio sito aveva i link diretti alla foto ma nessuna anteprima.
3. **Pulsante "Condividi"** nella barra della galleria.
4. **Indicizzazione dei motori di ricerca**, spenta di default (decisione di privacy, vedi sotto), e `sitemap.xml` quando si accende.
5. **Icona del sito**.

## Decisioni

- **Indicizzazione: spenta di default.** Le foto ritraggono persone riconoscibili in eventi di piazza, anche cortei studenteschi (analisi, appendice B). Finché l'utente non decide diversamente il sito è raggiungibile da chi ha il link ma non si offre ai motori di ricerca. Il controllo è `config('photos.indexable')` (`PHOTOS_INDEXABLE=false` nel `.env`). **È una decisione dell'utente**: se la si vuole accesa, cambiare il valore e, a mano, `public/robots.txt` (vedi Fase 4).
- L'immagine dell'anteprima è la versione `web` (1920 px, abbastanza grande per Facebook, WhatsApp, Telegram, Mastodon); per la home e per le pagine senza foto si usa l'icona del sito.
- La canonical di una pagina galleria **non** contiene `f` (tutte le foto dell'evento sono la stessa pagina); `og:url` invece sì, così il link condiviso riapre la foto giusta.
- Nessuna analytics né script di terze parti.

## Fase 1 — Proxy fidati (URL e redirect corretti)

In `bootstrap/app.php`, dentro `->withMiddleware(...)`:

```php
$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
    | Request::HEADER_X_FORWARDED_HOST
    | Request::HEADER_X_FORWARDED_PORT
    | Request::HEADER_X_FORWARDED_PROTO);
```

`at: '*'` è accettabile perché il container `app` è raggiungibile solo dalla rete interna di Docker (nginx → php-fpm), non da Internet. Verificare prima se `unlimiteddb` ha già una configurazione equivalente e copiarla.

Test: una richiesta con `X-Forwarded-Proto: https` e `X-Forwarded-Host: foto.mandich.dev` genera `url('/')` = `https://foto.mandich.dev` e `route('home')` assoluto con lo schema giusto.

## Fase 2 — Meta tag

2.1 `<x-layout>` (piano 01) accetta in più: `image` (URL assoluto), `ogUrl`, `ogType` (default `website`), `robots`. Nel `<head>`:

```blade
<link rel="canonical" href="{{ $canonical ?? url()->current() }}">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:type" content="{{ $ogType ?? 'website' }}">
<meta property="og:title" content="{{ $title ?? config('app.name') }}">
<meta property="og:description" content="{{ $description ?? '' }}">
<meta property="og:url" content="{{ $ogUrl ?? url()->current() }}">
<meta property="og:locale" content="it_IT">
@isset($image)<meta property="og:image" content="{{ $image }}">@endisset
<meta name="twitter:card" content="{{ isset($image) ? 'summary_large_image' : 'summary' }}">
```

`url()` e `route()` producono già URL assoluti; per `image` usare `url($photo->url('web'))`.

2.2 Controller della galleria (piano 04): calcolare `$sharedPhoto` = la foto `?f=` valida oppure la prima della selezione; passare alla vista `image`, `ogUrl` (con `f` solo se presente e valido) e `description` (`GallerySelection::description()`, piano 02). Home e autori: `description` fissa o ricavata dal profilo; autori senza foto di profilo usano l'icona.

2.3 `og:image` richiede che la versione `web` esista: se `versions_ready` è falso `Photo::url()` ricade sull'originale (grande, ma funziona); documentato.

## Fase 3 — Pulsante "Condividi"

`resources/js/gallery/share.js` (`#share-photo` della barra, piano 04):

```js
export function initShare(root) {
    const button = root.querySelector('#share-photo');
    if (!button) return;
    let current = { id: null, title: document.title };

    root.addEventListener('gallery:change', (e) => { current = { id: e.detail.photo.id, title: document.title }; });

    button.addEventListener('click', async () => {
        const url = location.href;   // contiene già ?f=<id> grazie a replaceState
        if (navigator.share) {
            try { await navigator.share({ title: current.title, url }); } catch { /* annullato */ }
        } else if (navigator.clipboard) {
            await navigator.clipboard.writeText(url);
            /* feedback: cambia per 2 s l'etichetta in "Link copiato" (aria-live) */
        }
    });
}
```

Il pulsante resta nascosto se non c'è né `navigator.share` né `navigator.clipboard` (per esempio HTTP non sicuro in sviluppo). In `index.js` (piano 05): `initShare(root)`.

## Fase 4 — Indicizzazione

4.1 `config/photos.php`: `'indexable' => env('PHOTOS_INDEXABLE', false)`; `.env.example`: `PHOTOS_INDEXABLE=false`.

4.2 Quando **non** indicizzabile (default):
- `<meta name="robots" content="noindex, nofollow">` in `<x-layout>` (variabile `$robots`);
- middleware `App\Http\Middleware\NoIndex` (alias nella `withMiddleware`, applicato al gruppo `web`) che aggiunge `X-Robots-Tag: noindex, nofollow` a tutte le risposte HTML;
- `public/robots.txt` con `User-agent: *` / `Disallow: /`. Non diventa dinamico: nginx risponde a `/robots.txt` come file statico (blocco `location = /robots.txt`), quindi il file va cambiato a mano quando si accende l'indicizzazione.

4.3 Quando indicizzabile: niente `noindex`, `public/robots.txt` con `Allow: /` e `Sitemap: https://<host>/sitemap.xml`, e la rotta `/sitemap.xml` (`SitemapController`, nome `sitemap`): una `<url>` per la home, per ogni evento (`events.show`, `lastmod` = `max(photos.updated_at)` dell'evento) e per ogni autore con foto. Quando non è indicizzabile la rotta risponde 404. Una sola pagina (nessuna sitemap index): bastano poche decine di URL.

## Fase 5 — Icona del sito

- `public/icon.svg`: un'icona semplice e coerente col tema (per esempio il glifo "photo" di Heroicons su fondo `neutral-900` con accento `sky-400`), e `public/apple-touch-icon.png` (180x180) generata da quell'SVG; `public/favicon.ico` esistente resta come ripiego.
- Nel layout: `<link rel="icon" href="/icon.svg" type="image/svg+xml">`, `<link rel="icon" href="/favicon.ico" sizes="any">`, `<link rel="apple-touch-icon" href="/apple-touch-icon.png">`, `<meta name="theme-color" content="#0a0a0a">`.
- L'icona di `unlimiteddb` viene generata in un apposito stage del Dockerfile da `icon-mine.svg`: qui non serve, i due file sono statici e versionati (sono piccoli).

## Fase 6 — Test (Pest)

`tests/Feature/ShareAndSeoTest.php`:
- le meta `og:*` e `twitter:card` sono presenti nella pagina dell'evento; `og:image` è assoluto e punta alla versione `web`; con `?f=<id>` l'immagine e `og:url` sono quelli della foto; con `f` estraneo si usa la prima foto;
- `canonical` senza `f`;
- con `X-Forwarded-Proto: https` gli URL assoluti usano `https`;
- indicizzazione spenta (default): `<meta name="robots" content="noindex, nofollow">`, header `X-Robots-Tag`, `/sitemap.xml` → 404;
- indicizzazione accesa (`config(['photos.indexable' => true])`): niente `noindex`; `/sitemap.xml` è XML valido, contiene home, un evento e un autore, e nessuna URL con `f`;
- titolo ed estratto con caratteri speciali (`"`, `<`, `&`) sono escapati nelle meta.

Prova manuale: incollare un link di evento e uno `?f=` in WhatsApp/Telegram e nel validatore di anteprime (facoltativo, richiede il sito online); verificare con `curl -I` l'header `X-Robots-Tag`.

## Fase 7 — Documentazione

- `README.md`: sezione "Condivisione e indicizzazione" (flag `PHOTOS_INDEXABLE`, cosa cambiare a mano per accenderla, proxy fidati); aggiornare la tabella delle rotte con `sitemap`.
- `todo.md`: spuntare il piano 08; lasciare in "Decisioni dell'utente" la voce sull'indicizzazione.

## Criteri di accettazione

1. Dietro un proxy HTTPS gli URL assoluti sono corretti.
2. Anteprime Open Graph per home, eventi, selezioni, autori e foto singole.
3. Di default il sito non si offre ai motori di ricerca (meta, header, `robots.txt`); accenderlo è una scelta esplicita e documentata.
4. `composer test` passa.
