# Implementation plan 05 — visualizzatore delle foto (carosello, griglia, autoplay)

Stato: **da eseguire**. Dipende dal piano 04 (markup e `#gallery-data`).
Quando è completato va rinominato `implementationPlan-V-05-visualizzatoreFoto.md`.

## Obiettivo e ambito

Il JavaScript della pagina galleria: sostituisce il carosello Bootstrap del vecchio `gallery.html` con un carosello scritto a mano (CSS scroll-snap), con griglia, autoplay, tastiera, schermo intero, link diretti e download della foto corrente. Nessuna libreria.

**Fuori ambito**: audio (06) e condivisione (08), che si innestano negli stessi `index.js` e barra.

## Comportamento (confermato dal vecchio sito, con i difetti noti corretti)

| Aspetto | Vecchio sito | Nuovo |
|---|---|---|
| Vista iniziale | carosello | carosello (griglia con `?vista=griglia`) |
| Navigazione | frecce, swipe | frecce, swipe, tastiera (`←` `→` `Home` `Fine`), giro continuo (dall'ultima alla prima) |
| Autoplay | acceso, 4 s, pausa al passaggio del mouse e durante il tocco (ripresa dopo 1,5 s) | stesso, ma **spento di default se `prefers-reduced-motion`**; intervallo limitato a 1-60 s anche nel codice (prima solo nell'HTML); il timer **riparte da zero dopo ogni cambio manuale** (prima continuava la sua cadenza); in pausa anche con la scheda nascosta |
| URL | `?p=<nome file>` con `replaceState` | `?f=<id foto>` con `replaceState`, senza toccare gli altri parametri |
| Download | link all'originale della foto corrente | uguale |
| Griglia → foto | click su una cella apre il carosello su quella foto | uguale; il ritorno alla griglia porta la cella corrente al centro |
| Immagini | originali da ~3 MB | versione `web` (1920 px WebP); gli originali solo per il download |
| Schermo intero | assente | pulsante e tasto `F` (Fullscreen API, nascosto se non supportata) |

## Struttura dei file

```
resources/js/gallery/
├── index.js        # default export init(root): legge #gallery-data, collega i moduli
├── carousel.js     # carosello a scroll-snap
├── grid.js         # click sulle miniature, scroll alla cella corrente
├── autoplay.js     # timer, pause, intervallo
└── url-state.js    # lettura/scrittura di ?f= e ?vista=
resources/js/lib/
├── nav.js          # funzioni pure: nextIndex, prevIndex, clampInterval
└── query.js        # funzioni pure: setParam, removeParam su URL
```

Le funzioni pure (senza DOM) sono in `lib/` per poterle testare (Fase 6).

## Fase 1 — `index.js`

```js
import { Carousel } from './carousel.js';
import { initGrid } from './grid.js';
import { Autoplay } from './autoplay.js';
import { replaceParams } from './url-state.js';

export default function init(root) {
    const data = JSON.parse(document.getElementById('gallery-data').textContent);
    const state = { index: data.start ?? 0, view: root.dataset.view ?? 'carousel' };

    const carousel = new Carousel(root, data.photos, { onChange: onSlideChange });
    const autoplay = new Autoplay(root, { next: () => carousel.next(), isActive: () => state.view === 'carousel' });
    initGrid(root, data.photos, { openAt: (i) => { setView('carousel'); carousel.goTo(i, { smooth: false }); } });

    function onSlideChange(i) {
        state.index = i;
        root.querySelector('#counter').textContent = `${i + 1}/${data.photos.length}`;
        const a = root.querySelector('#download-photo');
        a.href = data.photos[i].orig;
        a.download = data.photos[i].name;
        replaceParams({ f: data.photos[i].id });
        autoplay.restart();   // il timer riparte da zero a ogni cambio, manuale o automatico
        root.dispatchEvent(new CustomEvent('gallery:change', { detail: { index: i, photo: data.photos[i] } }));
    }

    function setView(view) { /* mostra/nasconde #carousel-view e #grid-view, aggiorna aria-pressed e ?vista, ferma o riavvia l'autoplay */ }

    /* tastiera, schermo intero, toggle vista */
    carousel.goTo(state.index, { smooth: false });
    setView(state.view);
}
```

`#download-photo` e `#counter` esistono già nel markup (piano 04); il JS li aggiorna.

## Fase 2 — `carousel.js` (scroll-snap)

- `#carousel-track` contiene **una slide per foto** (`<div class="h-full w-full shrink-0 snap-center snap-always">`), vuote (solo lo scheletro): 850 `<div>` sono leggeri. L'`<img>` (versione `web`, `object-contain`, `draggable="false"`, `alt` come nel piano 04) viene creata solo per la foto corrente e per quelle a distanza ≤ 1; per distanza 2 si precarica con `new Image().src = …` senza inserirle nel DOM; le immagini oltre distanza 2 vengono rimosse (liberano memoria su mobile).
- Posizione corrente: `IntersectionObserver` sulle slide con `threshold: 0.6` (più affidabile di ascoltare `scroll`); quando una slide diventa visibile → `onChange(index)`.
- `goTo(i, {smooth})`: `track.scrollTo({ left: i * track.clientWidth, behavior })` con `behavior: 'auto'` se `prefers-reduced-motion` o `smooth === false`. Su `resize` si riallinea alla slide corrente senza animazione.
- `next()`/`prev()` con giro continuo (`nextIndex(i, n)` in `lib/nav.js`).
- Errore di caricamento (`img.onerror`): ritenta una volta con l'originale (`orig`); se fallisce di nuovo mostra un riquadro "Foto non disponibile" con link all'originale.
- Pulsanti `#prev` / `#next` e le frecce da tastiera; con il tasto `Spazio` si attiva/disattiva l'autoplay.
- Il tocco e lo zoom a due dita del browser restano liberi (nessun `touch-action: none`); l'autoplay si mette in pausa durante il tocco (vedi Fase 3).
- Accessibilità: il contenitore ha `role="region"`, `aria-roledescription="carosello"`, `aria-label` con il titolo; le slide `aria-label="12 di 340"`; un `aria-live="polite"` fuori schermo annuncia il cambio solo quando l'autoplay è spento.

## Fase 3 — `autoplay.js`

- Stato: `enabled` (iniziale: vero, salvo `prefers-reduced-motion`), `paused` (hover o tocco), intervallo letto da `#autoplay-interval` con `clampInterval(value)` → intero tra 1 e 60 (non numerico → 4).
- `restart()`: `clearTimeout` e, se `enabled && !paused && isActive() && !document.hidden`, `setTimeout(next, interval * 1000)`. Catena di `setTimeout`, non `setInterval`: ogni cambio, anche manuale, riparte da zero.
- Pausa: `pointerenter`/`pointerleave` sul carosello **solo per `pointerType === 'mouse'`** (come il vecchio `mouseenter`); `touchstart` mette in pausa, `touchend` riprende dopo 1500 ms (annullando il timer precedente se si tocca di nuovo); `visibilitychange` ferma e riavvia.
- Toggle `#autoplay-toggle`: aggiorna `aria-pressed` e l'icona (pausa quando acceso, play quando spento); cambio dell'input → `restart()`.
- I controlli dell'autoplay sono nascosti in vista griglia (dove l'autoplay è fermo).

## Fase 4 — `grid.js` e vista

- Click (o `Invio`) su una miniatura: `event.preventDefault()`, `openAt(index)`. Senza JS il link apre l'originale (piano 04).
- Passando da carosello a griglia: `grid.querySelector('[data-index="i"]').scrollIntoView({ block: 'center' })`.
- Il toggle aggiorna `?vista=griglia` (lo toglie tornando al carosello) con `replaceParams`.

## Fase 5 — Schermo intero e tastiera

- `#fullscreen`: `root.requestFullscreen()` / `document.exitFullscreen()`; il pulsante è `hidden` se `!document.fullscreenEnabled`. Tasto `F` equivalente (ignorato quando il focus è in un campo di testo, per esempio l'intervallo).
- Tasti: `←` `→` (precedente/successiva), `Home` `Fine`, `Spazio` (autoplay), `G` (alterna carosello/griglia), `Esc` (esce dallo schermo intero, già nativo). I gestori ignorano i tasti con modificatori (Ctrl, Alt, Meta).

## Fase 6 — Test e prove

6.1 **Test automatici delle funzioni pure** con Vitest (dipendenza di sviluppo, nessun impatto sull'immagine di produzione): `npm install -D vitest`, script `"test:js": "vitest run"`, file in `resources/js/lib/*.test.js`:
- `nextIndex`/`prevIndex`: giro continuo, lista di un solo elemento;
- `clampInterval`: `0 → 1`, `100 → 60`, `'abc' → 4`, `'7.9' → 7`;
- `setParam`/`removeParam`: preservano `eventi[]` e `autori[]`, non duplicano `f`, non producono `?` finale.

6.2 **Prova manuale** (checklist da spuntare nel piano a fine esecuzione), con `media:demo` e con foto reali da 12 MP:
- Chrome e Firefox su desktop, Safari su iOS, Chrome su Android;
- swipe fluido con 340+ foto, nessun scatto allo scorrimento rapido; memoria stabile scorrendo tutto l'evento (Task Manager del browser);
- autoplay: parte, si ferma al passaggio del mouse, riparte, si azzera cambiando foto a mano, si ferma in griglia e con la scheda in background;
- `?f=` apre la foto giusta; incollare l'URL in una nuova scheda riapre la stessa foto;
- foto verticali con orientamento EXIF corretto (a schermo e in miniatura);
- schermo intero e tastiera;
- con JavaScript disattivato: griglia con link agli originali.

## Fase 7 — Documentazione

- `README.md`: sezione "Visualizzatore" (comportamento, tasti, parametri, struttura di `resources/js/`), comando `npm run test:js`.
- `todo.md`: spuntare il piano 05.

## Criteri di accettazione

1. Carosello, griglia, autoplay, tastiera, schermo intero e download della foto corrente funzionano come da tabella.
2. Una pagina con 850 foto non carica più di 3 immagini `web` per volta oltre alle miniature visibili.
3. I difetti del vecchio sito elencati nella tabella sono corretti.
4. `npm run test:js` e `composer test` passano; checklist manuale completata.
