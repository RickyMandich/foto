# Implementation plan 09 — sicurezza HTTP e performance di rete

Stato: **da eseguire**. Dipende dai piani 04 (payload JSON in `<script type="application/json">`) e 08 (proxy fidati, `robots.txt`).  
Quando è completato va rinominato `implementationPlan-V-09-sicurezzaEPerformance.md`.

## Obiettivo e ambito

Irrigidire gli header HTTP e migliorare le prestazioni di rete senza toccare la logica applicativa:

1. **Content Security Policy (CSP)** — niente CDN, niente `eval`, blocca XSS;
2. **Security header** — `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`;
3. **Compressione** — gzip/Brotli per HTML, CSS, JS, JSON;
4. **Cache** — header `Cache-Control` coerenti con le versioni degli asset;
5. **HSTS** — da abilitare dopo il passaggio definitivo a HTTPS.

**Fuori ambito**: autenticazione (nessuna pagina protetta), rate limiting (già su `download.zip`), ottimizzazioni del database.

## Decisioni

- Nessuna risorsa esterna: font, icone, script e stili sono tutti locali (scelto nel piano 01). La CSP può quindi essere stretta senza `unsafe-inline` né host di terze parti.
- Il payload JSON della galleria sta in `<script type="application/json">` (non eseguibile): non rompe la CSP. La lista degli hash `script-src` non è necessaria.
- `unsafe-inline` per gli stili: Tailwind 4 con Vite produce un foglio CSS esterno (`/build/assets/app-*.css`), nessuno stile inline — la CSP non ha bisogno di `unsafe-inline` per gli stili.
- `<noscript><style>…</style></noscript>` del piano 04 (nasconde il carosello senza JS): è l'unico caso di stile inline. Due opzioni in ordine di preferenza:
  1. Usare una classe CSS nel foglio (`[data-module="gallery"]:not(.js-ready) #carousel-view { display: none }` con JS che aggiunge `js-ready`) — elimina completamente lo stile inline.
  2. Calcolare l'hash SHA-256 dello stile e inserirlo in `style-src`.
  Si preferisce l'opzione 1 (nessun hash da aggiornare se cambia il contenuto).
- Gli header di sicurezza vengono impostati su nginx (non come middleware PHP): così si applicano anche alle risorse statiche (`/storage/`, `/build/`).

## Fase 1 — Content Security Policy

### 1.1 Eliminare lo stile inline del noscript (piano 04)

Nel piano 04 il markup è:

```html
<noscript><style>#carousel-view{display:none}#grid-view{display:block!important}</style></noscript>
```

Sostituire con una classe CSS nel foglio (`resources/css/app.css`):

```css
/* gallery senza JS: mostra la griglia, nasconde il carosello */
.gallery-noscript #carousel-view { display: none; }
.gallery-noscript #grid-view     { display: block !important; }
```

Nel Blade del piano 04, applicare la classe `gallery-noscript` tramite `<noscript>` + `<style>`:

In realtà l'approccio più pulito è la classe controllata da JS:

```blade
{{-- il div riceve la classe gallery-noscript finché JS non la rimuove --}}
<div data-module="gallery" class="gallery-noscript flex h-full flex-col" data-view="…">
```

In `resources/js/gallery/index.js` all'avvio:

```js
export default function init(root) {
    root.classList.remove('gallery-noscript');
    // … resto del codice
}
```

Così senza JS la classe resta e nasconde il carosello; con JS viene rimossa prima del primo render.

### 1.2 Direttive CSP

Valori per la produzione (aggiungere `'self'` dove serve):

```
Content-Security-Policy:
  default-src 'none';
  script-src  'self';
  style-src   'self';
  img-src     'self' data:;
  media-src   'self';
  font-src    'self';
  connect-src 'none';
  frame-ancestors 'none';
  base-uri    'self';
  form-action 'self';
```

- `img-src data:` è necessario per i placeholder SVG inline usati come fallback (se presenti); rimuovere se non usati.
- `frame-ancestors 'none'` sostituisce `X-Frame-Options: DENY` (stesso effetto, un solo header).
- `connect-src 'none'` perché l'app non fa richieste XHR/fetch (rimosso Axios nel piano 01).
- In sviluppo (`docker-compose.dev.yml`) aggiungere la direttiva `report-uri` o `report-to` verso un endpoint di test (es. `report-uri /csp-report`) per individuare violazioni durante lo sviluppo. Oppure usare `Content-Security-Policy-Report-Only` in sviluppo.

### 1.3 Posizione degli header — nginx

In `docker/nginx/default.conf`, all'interno del blocco `server`, aggiungere prima dei blocchi `location`:

```nginx
# ── Security headers ─────────────────────────────────────────────
add_header X-Content-Type-Options   "nosniff"                  always;
add_header X-Frame-Options          "DENY"                     always;
add_header Referrer-Policy          "strict-origin-when-cross-origin" always;
add_header Permissions-Policy       "camera=(), microphone=(), geolocation=()" always;
add_header Content-Security-Policy
    "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; font-src 'self'; connect-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';"
    always;
```

`always` fa sì che nginx aggiunga l'header anche alle risposte di errore.

**Nota**: nginx non supporta le direttive `add_header` multiriga native; usare la forma su una riga (come sopra) o una variabile nginx.

### 1.4 HSTS

**Non attivare prima del passaggio definitivo a HTTPS** (se un host è raggiunto in HTTP con HSTS, i browser lo bloccano fino alla scadenza). Da aggiungere in `docker/nginx/default.conf` solo dopo che `foto.mandich.dev` risponde su HTTPS senza eccezioni:

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

Documentare nel README che questa riga è commentata finché il passaggio non è completato.

## Fase 2 — Compressione

### 2.1 gzip in nginx

In `docker/nginx/default.conf` (fuori dai blocchi `location`, in `http` o `server`):

```nginx
gzip            on;
gzip_vary       on;
gzip_proxied    any;
gzip_comp_level 6;
gzip_min_length 1024;
gzip_types
    text/plain
    text/css
    text/javascript
    application/javascript
    application/json
    application/xml
    image/svg+xml;
```

- `text/html` è compresso di default quando `gzip on` è attivo, senza bisogno di listarlo in `gzip_types`.
- Non comprimere le immagini WebP (`image/webp`): sono già compresse; il ciclo CPU è sprecato.
- Verificare che `Accept-Ranges` sia presente sulle risposte dei file audio (necessario per il seek): nginx lo aggiunge di default per i file statici. Testare con `curl -I -H "Range: bytes=0-1" http://localhost:88/storage/…mp3` (deve rispondere `206 Partial Content`).

### 2.2 Brotli (opzionale)

Brotli offre una compressione del 10-20% migliore di gzip per HTML, CSS e JS. Richiede il modulo `ngx_brotli`, non incluso nell'immagine nginx di base. Se si usa l'immagine `nginx:alpine` si può aggiungere il modulo compilato; altrimenti usare solo gzip.

Se si decide di aggiungerlo (decisione da prendere in fase di esecuzione):

1. Usare `fholzer/nginx-brotli` come immagine base nel `Dockerfile` del nginx, oppure compilare il modulo nello stage di build;
2. In `nginx.conf`: `brotli on; brotli_comp_level 6; brotli_types …` (stessi tipi di gzip).

Annotare la scelta nel README.

## Fase 3 — Cache degli asset

### 3.1 Asset Vite (fingerprinted)

Vite genera nomi con hash (`/build/assets/app-Abc123.css`): si possono tenere in cache a lungo.

In `docker/nginx/default.conf`:

```nginx
location /build/ {
    alias /var/www/html/public/build/;
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}
```

### 3.2 Versioni delle foto (`_derived`)

Già gestito nel piano `gestioneFotoFuoriDaGit` (Fase 7.3). Verificare che il blocco sia presente:

```nginx
location /storage/_derived/ {
    alias /var/www/html/storage/app/public/_derived/;
    expires 30d;
    add_header Cache-Control "public, immutable";
    access_log off;
}
```

### 3.3 Originali e pagine PHP

Le pagine HTML di Laravel non hanno cache (default nginx: `Cache-Control: no-store` per le risposte PHP, oppure lasciare che Laravel/nginx non aggiunga nulla e il browser decida). Gli originali delle foto cambiano raramente ma non hanno fingerprint: usare `Last-Modified` e `ETag` (già abilitati di default da nginx) senza un `max-age` lungo.

## Fase 4 — Test

### 4.1 Test automatici PHP (`tests/Feature/SecurityHeadersTest.php`)

- Le risposte HTML (home, evento, autore) contengono gli header `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy` — **Nota**: questi header vengono impostati da nginx, non da Laravel; i test PHP possono verificarli solo se il middleware li aggiunge. Valutare un middleware Laravel che aggiunga gli header anche in test, oppure accettare che la verifica avvenga solo via `curl` sull'ambiente Docker.

Approccio alternativo: creare un middleware `App\Http\Middleware\SecurityHeaders` che aggiunge gli stessi header delle risposte HTML (il middleware serve sia per i test sia come documentazione vivente della policy), e lasciare che nginx li ripeta per le risorse statiche. Con questo approccio:

```php
// app/Http/Middleware/SecurityHeaders.php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; font-src 'self'; connect-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
    return $response;
}
```

Registrare nel gruppo `web` in `bootstrap/app.php`. I test Pest verificano gli header nelle risposte HTTP interne.

Test:
- Home, evento e autore rispondono con tutti gli header di sicurezza;
- Nessuna risorsa esterna nel markup HTML (già nel piano 01, `LayoutTest`);
- `Content-Security-Policy` è presente e contiene `frame-ancestors 'none'`;
- Con JS attivo nella prova manuale: DevTools → Console, nessuna violazione CSP;
- La classe `gallery-noscript` viene rimossa da JS all'avvio e il carosello è visibile.

### 4.2 Prova manuale con Docker

```zsh
# verifica header
curl -I http://localhost:88/

# verifica gzip
curl -I -H "Accept-Encoding: gzip" http://localhost:88/ | grep -i content-encoding

# verifica Accept-Ranges sugli audio
curl -I -H "Range: bytes=0-1" http://localhost:88/storage/<autore>/<evento>/<brano>.mp3

# verifica CSP senza violazioni: apri DevTools → Console
# nessuna voce "Refused to load..."
```

Usare [securityheaders.com](https://securityheaders.com) sull'URL di `foto-test.mandich.dev` dopo il deploy (piano `gestioneFotoFuoriDaGit`, Fase 9).

## Fase 5 — Documentazione

- `README.md`: sezione "Sicurezza e performance" (CSP, header, gzip, cache; cosa cambiare per accendere HSTS; come verificare con `curl` e securityheaders.com).
- `todo.md`: spuntare il piano 09.

## Criteri di accettazione

1. Nessuna violazione CSP nelle DevTools di Chrome, Firefox e Safari (verificato con `media:demo`).
2. `curl -I http://localhost:88/` mostra tutti gli header di sicurezza e `Content-Encoding: gzip`.
3. `curl -I -H "Range: bytes=0-1" http://localhost:88/storage/…mp3` risponde `206`.
4. La classe `gallery-noscript` è rimossa da JS e il carosello funziona normalmente.
5. `composer test` passa.

