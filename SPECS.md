# Specifiche del sistema attuale — Gallery Photo System (`foto`)

> Documento di **ricognizione dello stato attuale**, ricavato dalla lettura diretta di `index.html`, `gallery.html`, `bash/scan.sh`, `bash/all.sh`, `README.md`, `.gitignore` e dei file generati (`gallery.txt`, `photo.txt`, `song.txt`).
> Data analisi: 2026-10-29. Le parti descrittive (sezioni 1-11) riportano ciò che il codice fa realmente; discrepanze e punti critici sono raccolti nelle appendici A e B come input per la fase successiva di analisi/rifacimento.

---

## 1. Panoramica

Sito statico di gallerie fotografiche di eventi (cortei, presidi, iniziative), con musica di sottofondo opzionale per galleria, ospitato su **GitHub Pages**.

- Nessun backend, nessun build step, nessun framework JS: solo HTML + Bootstrap 5.3 + JavaScript vanilla.
- Le gallerie non sono descritte da un database o da un config: sono **cartelle** nel repository. Uno script bash (`scan.sh`) le scansiona e genera file di testo (`gallery.txt`, `photo.txt`, `song.txt`) che il frontend legge via `fetch`.
- Il deploy è uno script bash (`all.sh`) che esegue la scansione, crea un commit `rev N` e fa `git push`.
- Tema scuro forzato (`data-bs-theme="dark"`), lingua `it`.

## 2. Stack e dipendenze

| Componente | Versione / origine | Note |
|---|---|---|
| Bootstrap CSS + JS bundle | 5.3.2, CDN jsDelivr | Solo classi/utility Bootstrap, nessun CSS personalizzato |
| Font Awesome | 6.4.0, CDN cdnjs | Icone |
| JSZip | 3.10.1, CDN jsDelivr | Caricato lazy solo in `index.html` per lo ZIP lato client |
| Audio | `HTMLAudioElement` (`<audio id="audioPlayer">`) | Non Web Audio API |
| Script | Bash (Git Bash su Windows) | `zip` oppure `python3`/`python` come fallback per gli ZIP |
| Hosting | GitHub Pages | Pubblicazione tramite push sul branch principale |

Nessuna dipendenza è locale: il sito non funziona offline e richiede server HTTP (non `file://`) perché usa `fetch`.

## 3. Struttura del repository

```
foto/
├── index.html                 # Homepage: elenco gallerie
├── gallery.html               # Visualizzatore (carosello, griglia, audio)
├── gallery.txt                # Elenco gallerie (generato)
├── README.md                  # Documentazione esistente
├── .gitignore                 # contiene solo: *.png
├── bash/
│   ├── scan.sh                # Scansione + generazione file di testo e ZIP
│   └── all.sh                 # scan.sh + commit "rev N" + push
└── <YYYY MM DD - descrizione>/    # una cartella per galleria
    ├── *.JPG (e altri formati immagine)
    ├── *.mp3 / *.aac ...      # audio opzionale
    ├── photo.txt              # generato
    ├── song.txt               # generato (se ci sono audio)
    ├── photos.zip             # generato solo se foto <= 95 MB totali
    └── photos.zip.hash        # generato insieme a photos.zip
```

## 4. Modello dati (file di testo generati)

Non esiste un modello dati strutturato: tutto è una lista di righe, una voce per riga, ordinata.

| File | Posizione | Contenuto | Generato da |
|---|---|---|---|
| `gallery.txt` | radice | Nomi delle cartelle-galleria che contengono almeno una foto, **ordine decrescente** (`sort -r`), quindi dalla più recente grazie al prefisso data | `scan.sh` |
| `photo.txt` | cartella galleria | Nomi file immagine, ordinati alfabeticamente. Rimosso se non ci sono immagini | `scan.sh` |
| `song.txt` | cartella galleria | Nomi file audio, ordinati alfabeticamente. Rimosso se non ci sono audio | `scan.sh` |
| `photos.zip` | cartella galleria | ZIP (modalità STORE) delle sole foto, solo se totale <= 95 MB | `scan.sh` |
| `photos.zip.hash` | cartella galleria | sha1 troncato a 16 caratteri di `nome\|size\|mtime` di ogni foto, per rigenerare lo ZIP solo se cambiato | `scan.sh` |

Estensioni riconosciute:

- **Immagini**: jpg, jpeg, png, gif, webp (solo nelle varianti tutto minuscolo o tutto maiuscolo).
- **Audio**: mp3, wav, ogg, m4a, aac (stesso vincolo sul maiuscolo/minuscolo).

Non vengono memorizzati metadati: nessuna didascalia, data di scatto, EXIF, tag, ordine personalizzato o titolo personalizzato.

## 5. Convenzioni

- **Nome cartella galleria**: `YYYY MM DD - descrizione` (esempio reale: `2025 10 14 - corteo udine`). Il frontend lo interpreta con la regex `^(\d{4})[\s_-]+(\d{2})[\s_-]+(\d{2})\s*-\s*(.+)$`; se non combacia, mostra il nome intero senza data.
- **Foto**: file originali a piena risoluzione, nome `IMGPnnnn.JPG` (circa 3 MB ciascuno; misurato su una foto: 3.035.850 byte). Nessuna miniatura, nessuna versione ridimensionata.
- **Audio**: file nella stessa cartella delle foto.
- **Versioning**: messaggio di commit `rev N`; `all.sh` ricava N cercando l'ultimo commit il cui messaggio contiene "rev" e incrementando.

## 6. Pipeline di build e deploy

### 6.1 `bash/scan.sh`

Eseguibile dalla radice o da `bash/` (in quel caso si sposta nella cartella padre; verifica la presenza di `gallery.html` o `index.html`).

1. Itera **solo le cartelle di primo livello** (`*/`, non ricorsivo, include anche `bash/`).
2. Per ogni cartella: genera `photo.txt` e `song.txt` (li elimina se vuoti), ordinati.
3. Se ci sono foto, valuta lo ZIP:
   - somma le dimensioni delle foto elencate in `photo.txt`;
   - se > `MAX_ZIP_SIZE_MB` (95): non genera lo ZIP, elimina eventuali `photos.zip`/`photos.zip.hash` e conta una "skip";
   - altrimenti calcola l'hash di stato; se invariato e lo ZIP esiste lo riusa; se diverso o assente lo rigenera con `zip -q -0 photos.zip -@ < photo.txt`, in fallback `python3`/`python` con `zipfile.ZIP_STORED`; senza nessuno dei due logga errore.
4. Genera `gallery.txt` con le cartelle che hanno almeno una foto (ordine inverso); lo elimina se nessuna galleria è valida.
5. Stampa statistiche (cartelle, immagini, audio, gallerie, ZIP disponibili/saltati).

Output colorato con emoji. Compatibile Linux/macOS/Git Bash (`stat -c` con fallback `stat -f`).

### 6.2 `bash/all.sh`

1. Verifica che la directory corrente contenga `.git`, altrimenti esce con errore.
2. Esegue `scan.sh` (stessa cartella dello script); se fallisce interrompe il deploy.
3. Se non ci sono modifiche (`git diff --quiet && git diff --staged --quiet`) esce senza fare nulla.
4. Calcola la nuova versione (ultima `rev N` + 1), esegue `git add .`, `git commit -m "rev N"`, `git push`.
5. Mostra riepilogo (commit, branch, remote, ultimi 5 commit).

Flusso operativo di chi gestisce il sito: creare la cartella con foto/audio → lanciare `bash/all.sh` (su Windows tramite Git Bash) → la galleria è online.

## 7. Frontend — `index.html` (homepage)

**Layout**: navbar con titolo "Gallery Photo System" e contatore gallerie; sotto, griglia di card `row-cols-1 / md-2 / lg-3`.

**Flusso di caricamento**
1. `fetch('gallery.txt')` (con `cache: 'no-cache'`); se vuoto/assente mostra alert "Nessuna galleria trovata. Esegui `bash/scan.sh`…".
2. Per ogni galleria, in parallelo, `fetch` di `<galleria>/photo.txt` e `<galleria>/song.txt`.
3. Scarta le gallerie senza foto; se nessuna resta, alert "Nessuna galleria contiene foto".
4. Renderizza le card e aggiorna il contatore.
5. In background, per ogni card, risolve il bottone ZIP.

**Card galleria**
- **Cover**: prima foto della galleria (originale a piena risoluzione), rapporto 16:9, `object-fit-cover`, `loading="lazy"`, link a `gallery.html?g=<nome>`. Se non ci fosse la foto, icona segnaposto.
- **Titolo**: parte descrittiva del nome cartella (con `text-capitalize`), anch'esso link.
- **Data**: `DD/MM/YYYY` ricavata dal prefisso del nome.
- **Badge**: numero di foto; numero di brani audio solo se > 0.
- **Slot ZIP** (vedi sotto).
- Il nome viene sempre passato a `escapeHtml` prima dell'inserimento nel DOM.

**Bottone ZIP**
- `HEAD` su `<galleria>/photos.zip`:
  - **OK** → link `download` "Scarica ZIP (dimensione da `Content-Length`)", stile `btn-outline-info`.
  - **Non OK / errore** → bottone `btn-outline-warning` "Genera ZIP (N foto)".
- **Generazione lato client** (al click sul bottone arancione):
  1. carica JSZip 3.10.1 da CDN solo alla prima richiesta;
  2. rilegge `photo.txt`; stima la dimensione con `HEAD` sulla prima foto × numero foto;
  3. se la stima supera 500 MB chiede conferma con `confirm()`;
  4. scarica le foto con **concorrenza 6**, aggiungendole allo ZIP in memoria; una foto che fallisce viene saltata in silenzio; il bottone mostra spinner e avanzamento `n/totale`;
  5. genera il blob in modalità `STORE` e lo scarica come `<nome galleria>.zip`;
  6. in caso di errore mostra "Errore" per 3 secondi e ripristina il bottone.

## 8. Frontend — `gallery.html` (visualizzatore)

**Parametri URL**
- `g` (obbligatorio): nome cartella galleria. Se manca → errore con link alla home.
- `p` (opzionale): nome file della foto su cui aprire il carosello (deep link). Se non esiste nella lista parte dalla prima.

**Caricamento**: `fetch` di `<galleria>/photo.txt` e `song.txt` in parallelo; se non ci sono foto mostra errore con link alla home. Il titolo del documento e quello in toolbar sono il **nome cartella grezzo** (data inclusa, non formattato).

**Toolbar fissa in alto** (`sticky-top`, singola riga), da sinistra a destra:
1. Pulsante indietro → `index.html`.
2. Titolo galleria (troncato).
3. Toggle vista **Carosello / Griglia**.
4. Controlli autoplay (visibili solo in vista carosello): toggle on/off (icona pausa/play), input numerico secondi (default 4, min 1, max 60 come attributo HTML), pulsante **download foto corrente** (link `download` aggiornato a ogni cambio slide, punta al file originale).
5. Controlli audio (visibili solo se la galleria ha brani): play/pausa, brano successivo, slider volume, titolo del brano (nascosto sotto il breakpoint `lg`).

**Vista Carosello** (default, occupa tutta l'altezza sotto la toolbar, sfondo nero)
- Carosello Bootstrap con `interval: false`, `wrap: true`, `touch: true`; frecce prev/next; swipe nativo.
- Immagini `object-fit-contain` a tutta altezza; `loading="eager"` per le immagini con indice entro ±1 dalla foto iniziale, `lazy` per le altre.
- Contatore `n/totale` in overlay in alto a destra.
- A ogni `slid.bs.carousel`: aggiorna contatore, **URL** (`history.replaceState` con `p=<nome file>`, senza nuove voci di cronologia) e link di download.
- **Autoplay** custom: `setInterval` che chiama `next()` ogni N secondi (legge l'input a ogni avvio). Si ferma/riparte quando: cambia il toggle o l'intervallo; si passa a vista griglia (stop) e si torna al carosello (riparte se abilitato). Si **mette in pausa** su `mouseenter` sul carosello e durante `touchstart`; riprende su `mouseleave` o 1,5 s dopo `touchend`.

**Vista Griglia**
- `row-cols-2 / sm-3 / md-4 / lg-5`, celle quadrate (`ratio-1x1`, `object-fit-cover`), `loading="lazy"`, usando le stesse immagini originali.
- Click su una cella → torna al carosello e va a quella foto (`bsCarousel.to(idx)`).
- L'autoplay resta fermo finché si è in griglia.

**Audio** (solo se `song.txt` ha voci)
- La coda è una copia dei brani mescolata con `sort(() => Math.random() - 0.5)`.
- Carica il primo brano all'apertura ma **non lo avvia**: la riproduzione parte solo dal pulsante play.
- A fine brano passa al successivo (ciclico); su `error` salta al successivo, con contatore `errorStreak` che interrompe il tentativo se tutta la coda fallisce in sequenza (il contatore si azzera su `playing` e sul pulsante "successivo").
- Volume iniziale 0,6; titolo mostrato = nome file senza estensione.
- Nessuna persistenza tra pagine (ogni galleria è una pagina separata, lo stato audio si perde navigando).

## 9. Flusso end-to-end

```
cartella con foto/audio  ──► bash/all.sh
                                │
                                ├─ scan.sh → photo.txt, song.txt, gallery.txt, photos.zip(+hash)
                                └─ git add . → commit "rev N" → git push
                                                    │
                                              GitHub Pages
                                                    │
 browser ► index.html ► fetch gallery.txt + photo.txt/song.txt per galleria ► card
        └► gallery.html?g=<cartella>[&p=<foto>] ► fetch photo.txt/song.txt ► carosello/griglia/audio
```

## 10. Stato attuale dei contenuti

Otto gallerie, tutte di eventi di piazza, ordinate dalla più recente:

| Cartella | Audio |
|---|---|
| `2026 10 16 - corteo studentesco` | 2 mp3 |
| `2026 10 10 - iniziativa noinc davanti eni` | 1 aac |
| `2026 04 25 - corteo liberazione` | 2 mp3 |
| `2025 10 14 - corteo udine` | 1 mp3 |
| `2025 10 03 - corteo per gaza (blocco del ponte della libertà)` | 2 mp3 |
| `2025 10 02 - presidio piazzetta coin per la flottilla` | 2 mp3 |
| `2025 09 26 - corteo studentesco` | 2 mp3 |
| `2025 09 20 - corteo per gaza (blocco della vempa)` | 2 mp3 |

- Le gallerie contengono da 34 foto (la più piccola, `…iniziativa noinc davanti eni`) fino a diverse centinaia (la più grande è `2025 09 20 …`).
- Nessun `photos.zip` è attualmente presente nelle cartelle: con foto da ~3 MB anche la galleria più piccola supera la soglia di 95 MB, quindi per tutte le gallerie la homepage mostra il bottone "Genera ZIP" (generazione lato client).
- Il repository contiene anche i file audio e gli originali delle foto.

## 11. Vincoli e limiti operativi (da come è costruito oggi)

- Pubblicazione legata a uno script locale bash (su Windows richiede Git Bash) e a un push manuale.
- Limiti GitHub: file singolo max 100 MB (da qui la soglia di 95 MB per lo ZIP) e dimensione complessiva del repository/sito da tenere sotto controllo; gli originali pesano nel repo.
- L'elenco foto e audio dipende dall'ultima esecuzione di `scan.sh`: aggiungere file senza rigenerare i `.txt` non li rende visibili.
- Pagine e script presuppongono che i nomi cartella/file siano codificabili con `encodeURIComponent` (spazi, accenti e parentesi nei nomi sono già presenti e funzionano).

---

## Appendice A — Discrepanze tra `README.md` e codice

1. **Audio all'apertura**: il README dice che la musica parte all'apertura della galleria; il codice carica il primo brano ma non chiama `play()` (serve il click su play).
2. **API audio**: il README cita "Web Audio API"; il codice usa `HTMLAudioElement`.
3. **Naming cartelle**: l'esempio del README (`2025-09-23 mio evento`) non rispetta il pattern `YYYY MM DD - descrizione` richiesto da `formatTitle`: in quel caso non verrebbe mostrata la data e il titolo sarebbe il nome intero.
4. **Formati audio**: il README elenca MP3, WAV, OGG, M4A; `scan.sh` riconosce anche **AAC** (ed è usato, vedi `coro eni.aac`).
5. **Lazy loading**: il README dice "solo le prime 2 immagini sono eager"; il codice rende eager le immagini con indice entro ±1 dalla foto iniziale (fino a 3).
6. **Intervallo autoplay**: il limite massimo 60 è solo un attributo HTML dell'input; il codice applica un minimo di 1 secondo ma nessun massimo.
7. **Gestione errori**: il README parla di "recovery automatico da problemi di rete"; il codice ignora silenziosamente gli errori di `fetch` (`fetchLines` restituisce lista vuota) senza retry.
8. **`photos.zip` nella struttura**: il README lo presenta come parte normale di ogni galleria; nello stato attuale non ne esiste nessuno.

## Appendice B — Punti critici emersi (preliminari, per la fase di analisi)

**Performance e peso**
- Copertine, griglia e carosello usano tutti gli **originali da ~3 MB**: nessuna miniatura, nessun `srcset`, nessun formato moderno (WebP/AVIF). La griglia di una galleria da centinaia di foto scarica (in lazy) originali a piena risoluzione.
- Il repository ospita originali e audio: crescita continua del repo e rischio di avvicinarsi ai limiti di GitHub Pages.
- Lo ZIP precompilato è di fatto inutilizzato (soglia 95 MB sempre superata); lo ZIP lato client carica tutto in RAM nel browser.

**Gestione contenuti**
- Nessun metadato: ordine alfabetico per nome file (non per data di scatto), nessun EXIF, didascalie, tag, titolo personalizzato, foto di copertina scelta (è sempre la prima).
- Il titolo in `gallery.html` mostra il nome cartella grezzo, mentre la home lo formatta: incoerenza.
- Il flusso dipende da una convenzione di naming rigida e da una scansione manuale.

**Frontend**
- `alt` delle immagini = nome file; nessun tag Open Graph per l'anteprima dei link condivisi, nonostante il deep link per foto.
- Mescolamento audio con `sort(() => Math.random() - 0.5)` (distribuzione non uniforme); lo stato audio non persiste tra pagine.
- L'autoplay non si resetta dopo una navigazione manuale (il timer continua con la sua cadenza); su desktop si ferma ogni volta che il mouse è sopra il carosello.
- Nessuno zoom/fullscreen dedicato (solo pinch-to-zoom del browser su mobile).
- Dipendenze solo da CDN; nessun build, nessun test, nessuna CI.

**Script**
- `all.sh` rileva le modifiche con `git diff --quiet` (non vede file solo non tracciati; oggi funziona perché `scan.sh` modifica file già tracciati) e usa `git add .` (aggiunge qualunque cosa presente).
- `all.sh` va lanciato dalla radice del repo (controllo `.git` sul percorso corrente), a differenza di `scan.sh` che accetta anche `bash/`.
- `scan.sh` non è ricorsivo, tratta come "galleria candidata" anche la cartella `bash/`, e riconosce solo estensioni tutte minuscole o tutte maiuscole.
- La versione `rev N` è dedotta dai messaggi di commit.

**Contenuti sensibili da valutare**
- Gli audio sono brani commerciali (alcuni con nome file `SpotiDown.App - …`) pubblicati in un sito/repo pubblico: possibile problema di diritti.
- Le foto ritraggono eventi di piazza con persone riconoscibili (inclusi cortei studenteschi): da valutare sul piano della riservatezza e del consenso.
