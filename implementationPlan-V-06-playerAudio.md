# Implementation plan 06 — player audio della galleria

Stato: **da eseguire**. Dipende dal piano 04 (controlli `#audio-controls`, elemento `<audio>` e `tracks` in `#gallery-data`); si innesta in `resources/js/gallery/index.js` del piano 05.
Quando è completato va rinominato `implementationPlan-V-06-playerAudio.md`.

## Obiettivo e ambito

Riprodurre come sottofondo i brani della galleria visualizzata. Decisione dell'analisi (punto 5): **in una galleria si riproducono tutti i brani di quell'evento, di tutti gli autori coinvolti** (nella selezione corrente). In futuro solo musica non commerciale.

**Fuori ambito**: una pagina per gestire i brani; per ora sono file nella cartella dell'evento, come le foto, e `media:sync` li registra in `tracks` (già fatto nel punto 1).

## Comportamento (dal vecchio sito, con i difetti noti corretti)

| Aspetto | Vecchio sito | Nuovo |
|---|---|---|
| Coda | tutti i brani della galleria, mescolati con `sort(() => Math.random() - 0.5)` (distribuzione non uniforme) | tutti i brani della **selezione**, mescolati con Fisher-Yates |
| Avvio | il primo brano è caricato ma parte solo dal pulsante play (i browser vietano l'avvio automatico dell'audio) | uguale; nel README si corregge la frase sbagliata del vecchio README ("parte all'apertura") |
| Fine brano | passa al successivo, in giro continuo | uguale |
| Errore | salta al successivo; `errorStreak` ferma il giro se falliscono tutti di fila | uguale |
| Volume | 0,6 a ogni apertura | 0,6 la prima volta; poi **ricordato** (`localStorage`, in `try/catch`) |
| Titolo | nome file senza estensione, nascosto sotto `lg` | uguale (`Track::title()`), con `title` e testo completo in `aria-label` |
| Stato tra pagine | perso | perso (ogni galleria è una pagina): accettato |

Nuovo rispetto a prima: **Media Session API** (comandi e titolo del brano nella schermata di blocco di telefoni e nei tasti multimediali), pausa con i tasti `M`/`K`? **No**: nessun tasto aggiunto oltre a quelli del piano 05, per non sovrapporsi alla navigazione delle foto.

## Server (già pronto dal piano 04)

`#gallery-data` contiene `tracks`: `[{ "id": 7, "title": "coro eni", "url": "/storage/…" }]`, già ordinati per evento, autore e nome. Senza brani la barra non mostra i controlli audio e il modulo non viene inizializzato.

nginx serve i file statici con `Accept-Ranges` (necessario per il seek nei brani lunghi): verificarlo in Fase 4.

## Fase 1 — Funzioni pure (`resources/js/lib/audio-queue.js`)

```js
export function shuffle(items, random = Math.random) {
    const a = [...items];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

export function wrap(index, length) {
    return ((index % length) + length) % length;
}

/** Ha senso continuare a saltare dopo un errore? Falso quando tutti i brani sono falliti di fila. */
export function shouldSkipAfterError(errorStreak, queueLength) {
    return errorStreak < queueLength;
}
```

`random` iniettabile per i test.

## Fase 2 — `resources/js/gallery/audio.js`

```js
import { shuffle, wrap, shouldSkipAfterError } from '../lib/audio-queue.js';

export function initAudio(root, tracks) {
    if (!tracks.length) return;
    const audio = root.querySelector('#audio-player');
    const controls = root.querySelector('#audio-controls');
    /* riferimenti a #audio-play, #audio-next, #audio-volume, #audio-title */

    const queue = shuffle(tracks);
    let idx = 0;
    let errorStreak = 0;

    function load(i) {
        idx = wrap(i, queue.length);
        audio.src = queue[idx].url;
        titleEl.textContent = queue[idx].title;
        updateMediaSession(queue[idx]);
    }
    /* play/pausa, successivo (azzera errorStreak), ended → load(idx+1) + play, error → se shouldSkipAfterError salta, volume da localStorage */
    load(0);
}
```

Dettagli:
- `audio.play()` restituisce una Promise che può essere rifiutata (permessi del browser): sempre `.catch(() => {})`.
- Icona del pulsante legata agli eventi `play`/`pause` dell'elemento (non allo stato calcolato), con `aria-pressed` e `aria-label` ("Riproduci musica" / "Metti in pausa la musica").
- `playing` azzera `errorStreak`.
- Volume: slider 0-1 passo 0,05; salvato in `localStorage['foto.volume']` (lettura e scrittura in `try/catch`: in navigazione privata può lanciare eccezioni).
- Media Session: `navigator.mediaSession.metadata = new MediaMetadata({ title, artist: <titolo galleria>, album: 'Foto' })` e azioni `play`, `pause`, `nexttrack`, solo se `'mediaSession' in navigator`.
- Quando l'utente passa a una foto dell'evento di un altro autore non cambia niente: la coda è unica per tutta la selezione.
- Pausa dell'audio quando la pagina viene scaricata non serve (il browser lo fa).

In `index.js` (piano 05): `import { initAudio } from './audio.js'; initAudio(root, data.tracks ?? []);` dopo l'inizializzazione del carosello.

## Fase 3 — Markup dei controlli (concordato nel piano 04)

Dentro `#audio-controls`: `#audio-play` (`<button>`), `#audio-next` (`<button>`), `#audio-volume` (`<input type="range" min="0" max="1" step="0.05">`, larghezza 5rem, `aria-label="Volume"`), `#audio-title` (`<span class="hidden max-w-[14vw] truncate text-sky-300 lg:inline">`). Se il piano 04 ha usato altri nomi, adeguare qui senza cambiare il contratto "id nel markup, JS che li legge".

## Fase 4 — Test e prove

4.1 Vitest (`resources/js/lib/audio-queue.test.js`):
- `shuffle` non modifica l'input, contiene gli stessi elementi, con `random` deterministico produce l'ordine atteso; su 10 000 ripetizioni ogni elemento compare in ogni posizione con frequenza vicina a quella attesa (tolleranza ampia, serve a evitare la distribuzione non uniforme del vecchio metodo);
- `wrap`: negativi, multipli della lunghezza;
- `shouldSkipAfterError`: falso quando `errorStreak == queueLength`.

4.2 Test PHP (già nel piano 04): i controlli audio compaiono solo se ci sono brani, `tracks` nel JSON in ordine corretto; aggiungere un caso "brani di due autori nella stessa selezione, entrambi nella coda".

4.3 Prova manuale:
- copiare 2-3 mp3 piccoli in una cartella evento di `media:demo`, `media:sync`, aprire la galleria: play parte solo dal pulsante; al termine passa al brano successivo; "successivo" funziona; il volume resta alla riapertura;
- un file `.mp3` rotto (testo rinominato) viene saltato; se sono tutti rotti il tentativo si ferma senza loop infinito;
- seek in un brano lungo (richiede `Accept-Ranges`: `curl -I -H "Range: bytes=0-1" http://localhost:88/storage/…mp3` deve rispondere `206`);
- Safari su iOS: la riproduzione parte dopo il tocco e i comandi compaiono nella schermata di blocco.

## Fase 5 — Contenuti e diritti (promemoria, non codice)

I brani attuali sono per lo più commerciali (alcuni con nome `SpotiDown.App - …`, vedi analisi, appendice B): rendere pubblico un sito con quei file è un problema di diritti. La decisione dell'analisi è "in futuro solo musica non commerciale". Prima del passaggio di `foto.mandich.dev` alla VM (Fase 10 del piano 1) decidere se migrare i 14 brani esistenti o sostituirli; il sito funziona uguale senza audio. Annotato anche in `todo.md`.

## Fase 6 — Documentazione

- `README.md`: sezione "Audio" (coda mescolata, comportamento dell'avvio, volume ricordato, formati: mp3, aac, m4a, ogg, wav; nota sul vecchio README).
- `todo.md`: spuntare il piano 06.

## Criteri di accettazione

1. I brani di tutti gli autori della selezione finiscono in un'unica coda mescolata in modo uniforme.
2. Nessun avvio automatico; nessun loop su una coda tutta corrotta.
3. Volume ricordato; Media Session attiva dove supportata.
4. `npm run test:js` e `composer test` passano.
