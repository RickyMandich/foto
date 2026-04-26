# 📸 Gallery Photo System

Sistema di gallerie fotografiche completamente automatizzato con supporto audio, carosello interattivo, vista griglia e deployment automatico su GitHub Pages.

## ✨ Caratteristiche Principali

- 🎯 **Rilevamento automatico gallerie** - Zero configurazione manuale
- 🎵 **Supporto audio integrato** - Musica di sottofondo con controlli
- 🎠 **Carosello interattivo** - Autoplay, swipe, zoom su mobile
- 🔲 **Vista griglia** - Anteprima rapida di tutte le foto
- 📱 **Responsive design** - Ottimizzato per mobile e desktop
- 🚀 **Deploy automatico** - Versioning incrementale su GitHub Pages
- 📊 **Statistiche complete** - Conteggio foto e audio per galleria

## 🏗️ Struttura del Progetto

```
/
├── index.html              # Homepage con rilevamento automatico gallerie
├── gallery.html            # Visualizzatore galleria (carosello + griglia + audio)
├── gallery.txt             # Lista gallerie (generato automaticamente)
├── bash/
│   ├── scan.sh             # Script scansione automatica
│   └── all.sh              # Script deploy automatico (commit + push con versioning)
└── [nome-galleria]/
    ├── *.JPG               # File foto (JPG, PNG, WEBP, etc.)
    ├── *.mp3               # File audio opzionali
    ├── photo.txt           # Lista foto (generato automaticamente)
    ├── song.txt            # Lista audio (generato automaticamente)
    ├── photos.zip          # ZIP delle sole foto (generato se ≤ 95 MB)
    └── photos.zip.hash     # Hash di stato per rigenerare lo zip solo se necessario
```

## 🧠 Logica del Sito

Il sito è una **SPA statica a due pagine** scritta solo con HTML + Bootstrap 5.3 + vanilla JS, senza backend e senza classi CSS personalizzate. Tutto il rendering avviene lato client a partire dai file di testo generati da `bash/scan.sh`.

### Tema
- Dark mode forzato tramite `data-bs-theme="dark"` sul tag `<html>`.
- Solo classi e utility Bootstrap 5.3 (es. `bg-secondary`, `bg-dark`, `text-info`, `ratio`, `object-fit-*`, `position-*`, `btn-group`, `btn-check`, `form-range`, ecc.).
- Icone via Font Awesome 6.4.

### `index.html` — Homepage
1. Carica `gallery.txt` (una galleria per riga).
2. Per ogni galleria fa fetch in parallelo di `<galleria>/photo.txt` e `<galleria>/song.txt` per ottenere i conteggi.
3. Filtra le gallerie senza foto.
4. Renderizza una griglia di card (`row-cols-1/2/3`):
   - **Cover** = prima foto della galleria (`ratio 16x9`, `object-fit-cover`, `loading="lazy"`), cliccabile verso `gallery.html?g=<nome-galleria>`.
   - **Titolo** parsato dal nome cartella (`YYYY MM DD - descrizione` → `descrizione` + data `DD/MM/YYYY`), anch'esso link.
   - **Badge** con numero foto e numero brani audio.
   - **Bottone "Scarica ZIP"** popolato in modo asincrono: viene fatta una `HEAD` su `<galleria>/photos.zip`; se risponde 200 il bottone appare con la dimensione formattata (KB/MB/GB) letta da `Content-Length`. Se lo zip non esiste (galleria troppo grande, vedi `scan.sh`), il bottone semplicemente non viene mostrato.
5. Stati gestiti: spinner di caricamento, alert di errore se `gallery.txt` manca o non ci sono foto.

### `gallery.html` — Visualizzatore
Riceve il nome galleria via query string `?g=<nome>` (decodificato con `decodeURIComponent`) e carica `<galleria>/photo.txt` e `<galleria>/song.txt`.

**Deep linking**: la URL accetta anche un parametro opzionale `&p=<nome-file-foto>` per aprire il carosello direttamente su una foto specifica (es. `gallery.html?g=2025%2010%2014%20-%20corteo%20udine&p=IMGP6055.JPG`). Ad ogni cambio slide la URL viene aggiornata in tempo reale via `history.replaceState` (senza creare entry nella cronologia), così è sempre condivisibile copiando dalla barra del browser. Si usa il **nome file** invece dell'indice numerico per garantire stabilità del link anche se in futuro vengono aggiunte/rimosse foto dalla galleria.

**Toolbar fissa in alto** (unica, compatta, non invasiva) contenente nell'ordine:
- Pulsante "Indietro" → `index.html`.
- Titolo galleria (troncato).
- Toggle vista **Carosello / Griglia** (`btn-group` con `btn-check`).
- Controlli autoplay (visibili solo in vista Carosello):
  - **Toggle autoplay** on/off (icona pause/play).
  - **Input numerico** in secondi tra una foto e l'altra (1–60, default 4).
  - **Bottone download foto corrente** (`<a download>` con `href` aggiornato a ogni `slid.bs.carousel`): scarica direttamente il file originale full-res della foto attualmente visualizzata.
- Controlli audio (visibili solo se la galleria ha brani):
  - Play/Pause, Next, slider Volume, titolo brano corrente (nascosto sotto `lg`).

**Vista Carosello** (default, riempie tutta l'altezza disponibile sotto la toolbar):
- Bootstrap `carousel` con `interval: false` — l'autoplay è gestito da `setInterval` custom basato sull'input secondi, così è dinamico.
- Frecce prev/next, swipe touch nativo Bootstrap.
- Contatore overlay `n/totale` in alto a destra.
- Autoplay si **mette in pausa** automaticamente su `mouseenter` (desktop) e durante il touch (mobile, per consentire pinch-zoom del browser); riprende dopo 1.5s dal `touchend` o all'uscita del mouse.
- Lazy loading: solo le prime 2 immagini sono `eager`, il resto `lazy`.

**Vista Griglia**:
- `row-cols-2/3/4/5` di celle quadrate (`ratio 1x1`, `object-fit-cover`).
- Click su una cella → torna al carosello posizionato su quella foto (`bsCarousel.to(idx)`).
- L'autoplay viene fermato finché si è in vista griglia.

**Sistema audio**:
- Coda dei brani mescolata casualmente (`Math.random` shuffle).
- `ended` → brano successivo.
- `error` → salta il brano (con contatore `errorStreak` per evitare loop infiniti su tutta la coda corrotta).
- Volume iniziale 0.6; tutti i controlli sono nella toolbar (nessuna barra fissa che copra le foto).

## 🚀 Guida Rapida

### Metodo Automatico (Consigliato)

1. **Crea cartella** con foto e file audio (opzionali):
   ```bash
   mkdir "2025-09-23 mio evento"
   # Aggiungi foto e file audio nella cartella...
   ```

2. **Deploy automatico** (esegue scansione + commit + push):
   ```bash
   # Su Linux/Mac
   ./bash/all.sh

   # Su Windows con Git Bash
   & "C:\Program Files\Git\bin\bash.exe" bash/all.sh
   ```

   `bash/all.sh` invoca internamente `bash/scan.sh`, quindi `photo.txt`, `song.txt`, `gallery.txt` e `photos.zip` vengono rigenerati automaticamente prima del commit. Nessuna configurazione manuale necessaria.

   > Per una sola scansione senza deploy puoi comunque lanciare `bash/scan.sh` standalone.

## 🎵 Sistema Audio

### Funzionalità Audio
- **Riproduzione di sottofondo** all'apertura della galleria (se presenti file audio)
- **Controlli compatti** integrati nella toolbar superiore (Play/Pause, Next, Volume)
- **Riproduzione casuale** - coda mescolata all'avvio, brano successivo automatico al termine
- **Gestione errori** - salta automaticamente i file non riproducibili
- **Titolo brano** visibile nella toolbar (nascosto sotto breakpoint `lg` per non affollare la UI)

### Formati Supportati
- MP3, WAV, OGG, M4A

### Controlli Audio
- 🎵 **Play/Pause** - avvia/ferma il brano corrente
- ⏭️ **Next** - passa al brano successivo nella coda mescolata
- � **Volume** - slider (default 0.6)

## 🎠 Sistema Carosello

### Funzionalità Carosello
- **Autoplay configurabile** - toggle on/off + input numerico dei secondi (1–60, default 4)
- **Navigazione** - frecce prev/next, swipe touch nativo Bootstrap
- **Zoom mobile** - pinch-to-zoom del browser (l'autoplay si pausa durante il touch)
- **Contatore** - posizione corrente (es. "5/37") in overlay in alto a destra
- **Lazy loading** - le prime 2 immagini sono `eager`, il resto `lazy`

### Controlli Carosello
- ⬅️➡️ **Frecce** - navigazione manuale
- 📱 **Swipe** - scorri con il dito su mobile
- 🔍 **Zoom** - pinch-to-zoom su mobile
- ⏸️ **Pausa automatica** - su `mouseenter` (desktop) e durante il touch (mobile, ripresa dopo 1.5s)
- ⚙️ **Toggle autoplay + intervallo** - direttamente nella toolbar, modificabili a runtime

## 🔲 Vista Griglia

### Funzionalità Griglia
- **Anteprima completa** - Tutte le foto in una vista
- **Click per aprire** - Passa al carosello sulla foto selezionata
- **Layout responsive** - Si adatta a diverse dimensioni schermo
- **Caricamento ottimizzato** - Lazy loading per performance

## 🔍 Script di Automazione

### scan.sh - Scansione Automatica

Lo script `scan.sh` automatizza completamente la gestione delle gallerie:

**Cosa fa:**
- Scansiona tutte le cartelle del progetto
- Genera `photo.txt` per ogni cartella con foto
- Genera `song.txt` per ogni cartella con audio
- Genera/aggiorna `photos.zip` con le sole foto (vedi sotto)
- Crea `gallery.txt` con l'elenco delle gallerie valide
- Mostra statistiche complete

**Generazione `photos.zip`:**
- Variabile `MAX_ZIP_SIZE_MB` (default **95 MB**, sotto al limite hard di 100 MB di GitHub).
- Calcola la dimensione totale delle foto della cartella:
  - **se > 95 MB** → lo zip **non viene generato**, eventuale `photos.zip`/`photos.zip.hash` precedente viene rimosso, viene loggato un warning. L'`index.html` di conseguenza non mostrerà il bottone download per quella galleria.
  - **se ≤ 95 MB** → calcola un **hash di stato** con `nome|size|mtime` di ogni foto (sha1 troncato a 16 char) e lo confronta con `photos.zip.hash`:
    - hash invariato → zip già aggiornato, skip rigenerazione.
    - hash diverso o zip mancante → rigenera `photos.zip` (modalità STORE, niente compressione perché su JPG è inutile).
- Tool di compressione: prova `zip -0 -@`; in fallback usa `python -m zipfile` (`python3` o `python`). Se nessuno è disponibile lo zip non viene creato e viene loggato un errore.
- Il file `photos.zip` viene committato dal repo (necessario per essere scaricabile via GitHub Pages).

**Output esempio:**
```
🔍 Gallery Scanner - Generazione automatica photo.txt e song.txt
==================================================================
📁 Processando: 2025-09-20 corteo per gaza (blocco della vempa)
✅ Trovate 150 immagini → photo.txt
✅ Trovati 3 file audio → song.txt
📦 Verifica photos.zip...
📦 Generazione photos.zip (78MB)...
✅ photos.zip generato
📊 Cartella completata: 150 foto, 3 audio

📝 Generazione gallery.txt...
✅ Generato gallery.txt con 1 gallerie

📊 STATISTICHE FINALI:
   📂 Cartelle processate: 1/1
   📸 Foto totali: 150
   🎵 File audio totali: 3
   📋 Gallerie valide: 1
   📦 ZIP disponibili: 1 (saltati per dimensione: 0)
```

### all.sh - Deploy Automatico

Lo script `bash/all.sh` automatizza il deployment con versioning incrementale:

**Cosa fa:**
- Esegue automaticamente `scan.sh` come primo step (genera/aggiorna `photo.txt`, `song.txt`, `gallery.txt`, `photos.zip` e `photos.zip.hash`). Se `scan.sh` esce con errore il deploy viene interrotto.
- Rileva automaticamente le modifiche
- Calcola la versione incrementale
- Crea commit con messaggio standardizzato
- Effettua push su GitHub
- Mostra statistiche del deploy

> Non serve più lanciare `bash/scan.sh` manualmente prima di `bash/all.sh`: l'esecuzione è incatenata.

**Output esempio:**
```
🚀 Deploy Automatico Gallery - Versioning Incrementale
==================================================================
📝 Modifiche rilevate:
 M gallery.html
 M index.html
📈 Versione precedente: 17
🆕 Nuova versione: 18
✅ Commit creato: rev 18
✅ Push completato con successo
```

## 🌐 Deployment su GitHub Pages

### Setup Iniziale
1. **Crea repository** su GitHub
2. **Abilita GitHub Pages** nelle impostazioni del repository
3. **Seleziona branch** `main` o `master` come source

### Deploy Automatico
```bash
# Deploy con versioning automatico
./bash/all.sh
```

### URL di Accesso
Il sito sarà disponibile su:
```
https://[username].github.io/[repository-name]/
```

## 📱 Compatibilità

### Browser Supportati
- ✅ Chrome/Chromium (consigliato)
- ✅ Firefox
- ✅ Safari
- ✅ Edge
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

### Dispositivi
- 💻 **Desktop** - Esperienza completa con tutti i controlli
- 📱 **Mobile** - Interfaccia ottimizzata con swipe e zoom
- 📟 **Tablet** - Layout adattivo per schermi medi

## 🛠️ Risoluzione Problemi

### Script non funziona
- **Su Windows**: Usa Git Bash invece di PowerShell
- **Permessi**: Esegui `chmod +x bash/scan.sh bash/all.sh`
- **Directory**: Assicurati di essere nella directory principale

### Gallerie non appaiono
- Verifica che `gallery.txt` sia stato generato
- Controlla che le cartelle contengano file `photo.txt`
- Esegui nuovamente `scan.sh`

### Audio non funziona
- Verifica che i file audio siano in formato supportato
- Controlla che `song.txt` sia stato generato
- Alcuni browser richiedono interazione utente per l'audio

### Problemi di caricamento
- Usa sempre un server HTTP (non aprire file:// direttamente)
- Verifica la connessione internet per GitHub Pages
- Controlla la console browser per errori specifici

## 🎯 Workflow Completo

### Aggiungere Nuova Galleria
```bash
# 1. Crea cartella con foto e audio
mkdir "2025-09-23 nuovo evento"
# Aggiungi foto e file audio...

# 2. Deploy automatico (esegue scan.sh + commit + push)
./bash/all.sh

# 3. ✨ La nuova galleria è online!
```

### Aggiornare Galleria Esistente
```bash
# 1. Aggiungi/rimuovi foto dalla cartella

# 2. Deploy modifiche (la scansione è automatica)
./bash/all.sh
```

## 🚀 Caratteristiche Avanzate

### Autoplay Intelligente
- Si ferma durante hover del mouse
- Si ferma durante zoom su mobile
- Si ferma nella vista griglia
- Riprende automaticamente quando appropriato

### Gestione Errori
- Fallback automatico per file corrotti
- Messaggi di errore user-friendly
- Recovery automatico da problemi di rete

### Performance
- Lazy loading delle immagini
- Caricamento asincrono dell'audio
- Ottimizzazione per dispositivi mobili
- Cache intelligente del browser

## 🔧 Dettagli Tecnici

### Architettura
- **Frontend**: HTML5, CSS3, JavaScript ES6+
- **Framework**: Bootstrap 5.3.2
- **Icons**: Font Awesome 6.4.0
- **Audio**: Web Audio API
- **Responsive**: CSS Grid e Flexbox

### File Generati Automaticamente
- `photo.txt` - Lista delle foto per ogni galleria
- `song.txt` - Lista dei file audio per ogni galleria
- `gallery.txt` - Lista delle gallerie valide

### Formati Supportati
- **Immagini**: JPG, JPEG, PNG, GIF, WEBP
- **Audio**: MP3, WAV, OGG, M4A

---