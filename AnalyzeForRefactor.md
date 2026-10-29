# Analisi per il rifacimento — foto.mandich.dev

> Esito delle decisioni prese con l'utente. Il documento descrive lo **stato attuale** (vedi `SPECS.md`) e le **decisioni**, non codice già scritto.
> Data: 2026-10-29. Ambito attuale: **solo il punto 1** (gestione delle foto fuori da git). Gli altri punti (autori, gallerie per autore/evento, pagina autore, stack) sono affrontati dopo; qui sono segnati solo i vincoli che il punto 1 pone su di loro.

## 1. Dati misurati (da output WSL/SSH dell'utente)

| Cosa | Valore |
|---|---|
| Cartella `oldFoto` | 7,9 GB totali (il resto rispetto ai contenuti, ~3,9 GB, è presumibilmente `.git`: da confermare) |
| Foto | 1017 JPG, 3962 MB totali, 3,90 MB medi |
| Audio | 14 file, 54 MB |
| Gallerie | 8, da 113 MB a 849 MB ciascuna |
| Ritmo di crescita | 1017 foto da 2025-09-20 a 2026-10-16 (~13 mesi): circa 4 GB/anno di originali (stima dai dati, non dichiarata dall'utente) |
| VM (Oracle, Ubuntu 22.04, è la stessa di `unlimiteddb`) | disco 45 GB con 21 GB liberi; **RAM 956 MiB** (405 usati) e **swap 1,4/2,0 GiB usata**; 2 vCPU |
| Docker sulla VM | 12 volumi (1,5 GB), immagini 11,8 GB di cui 6,7 GB recuperabili |
| Dimensioni/EXIF delle foto | foto campione `IMGP5215.JPG`: 4288x2848 px (~12 MP), Pentax K-x, EXIF presente (modello, data di scatto, orientamento). `exiftool` assente in WSL |

## 2. Decisioni prese (punto 1)

1. **Hosting**: si abbandonano le GitHub Pages; il sito vive sulla VM Oracle esistente (stessa di `unlimiteddb`, stack Docker + Traefik) all'host `foto.mandich.dev`. La radice del progetto sul server è `~/sites/foto`.
2. **Foto fuori da git**: foto e audio stanno in `storage/app/public` del progetto Laravel (sul server, nel path ignorato da git, come già fa `unlimiteddb` per le immagini delle carte); il repository contiene solo codice e struttura.
3. **Visibilità**: foto pubbliche, visibili a chiunque (nessun controllo d'accesso).
4. **Download**: restano scaricabili le **foto originali**, sia **singole** sia come **ZIP**. Lo ZIP contiene **tutte le foto della galleria che si sta visualizzando** (evento con tutti gli autori, solo alcuni autori, più eventi di un autore). Generazione **al volo in streaming** (nessun file temporaneo, ZIP in modalità STORE), compatibile con la RAM della VM. Lo ZIP lato browser attuale (JSZip, tutto in RAM) non è adatto a eventi da 700-850 MB.
5. **Audio**: si tengono (14 file), anche questi fuori da git. In una galleria si riproducono **tutti i brani di quell'evento** (di tutti gli autori coinvolti). In futuro solo musica non commerciale.
6. **Caricamento**: lo fa solo l'utente, con accesso al server e senza passare dalla UI della galleria; nessuna pagina admin per ora. Il database si aggiorna con un **comando artisan eseguito sul server**, che scansiona il filesystem (il filesystem è la source of truth) e sincronizza il DB con lo stato del disco.
7. **Backup**: affidato alla gestione del volume dati su Oracle Cloud; nessun altro spazio remoto. (Nota: gli originali restano anche sul PC dell'utente finché non li cancella, quindi esiste di fatto una seconda copia.)
8. **Repository**: azzerare la storia attuale, ricommittare solo la struttura senza cartelle foto/musica, e mettere la nuova versione in un nuovo branch. L'obiettivo **non** è liberare spazio su GitHub ma evitare di scaricare il peso delle foto a ogni clone: per questo basta un force push della storia riscritta (su tutti i branch e tag che contengono le foto). I vecchi clone locali restano pesanti.
9. **DNS** (Cloudflare, zona `mandich.dev`): oggi `foto` è un CNAME verso `rickymandich.github.io` (non proxato). Esiste già un record `*.mandich.dev` A verso la VM (proxato). Per il passaggio basta togliere il CNAME di `foto`: l'host ricade sul wildcard. Il CNAME va rimosso nello stesso momento in cui si disattivano le Pages, per non lasciare un CNAME verso un sito Pages non più presidiato. `foto` resta proxato.
10. **Stack**: Laravel + MariaDB, come `unlimiteddb` (comando artisan per la sincronizzazione, worker delle code per la generazione delle foto, stesso schema di deploy). Rivedibile al punto 6 se emerge qualcosa di meglio. **Attenzione alla RAM**: la VM è già al 67% di RAM con swap al 72% e dai volumi risulta un database per sito; un altro container MariaDB pesa. Da misurare con `docker stats`; alternative: schema separato nel MariaDB di un sito esistente, oppure SQLite (decisione aperta, sezione 5, punto 1).
11. **Tre versioni di ogni foto**: originale (download), versione web (carosello) e miniatura (griglia e copertine). Si scelgono perché lo spazio basta: 21 GB liberi, ~4 GB/anno di originali, derivati stimati sotto 1 GB (stima, non misurata). La generazione è affidata al comando artisan sul server.
12. **Titolo dell'evento**: l'evento si identifica dalla **data** (un evento al giorno); il titolo della cartella è solo un fallback per i typo. Se autori diversi danno titoli diversi alla stessa data **vince il primo trovato** (per renderlo deterministico: primo in ordine alfabetico di cartella autore). Il comando artisan segnala un **avviso** quando per la stessa data i titoli differiscono; l'utente li allinea a mano.
13. **Nomi su disco**: restano quelli originali (spazi, accenti, parentesi), così `rsync` resta incrementale e il titolo dell'evento si vede bene. "Normalizzare" significa che negli URL e nel DB la chiave dell'evento è la data e l'autore ha uno slug; il titolo mostrato è quello della cartella con la prima lettera maiuscola. (Interpretazione da confermare: se si intendeva rinominare anche le cartelle sul server, va rivista la sincronizzazione.)
14. **Rimozioni**: se una foto, una galleria, un autore o un evento spariscono dal disco, la sincronizzazione li toglie dal DB e cancella le versioni generate, mai gli originali.
15. **Permessi**: gli originali restano dell'utente host (`rsync`), il container li legge e scrive solo in `_derived`; l'entrypoint non fa più `chown -R` su tutto `storage`.

## 3. Architettura proposta per il punto 1

Da confermare nel piano di implementazione; i dettagli non decisi sono nella sezione 5.

### Struttura dei file (uguale sul PC e sul server)

```
<autore>                           # cartella con tutto di questo autore
├── autore.md                      # dati dell'autore (vedi sotto)
└── <YYYY MM DD - descrizione>/    # una cartella per galleria; lo stesso evento si riconosce dalla stessa data tra autori diversi
    ├── *.JPG (e altri formati immagine)
    └── *.mp3 / *.aac ...          # audio opzionale
```

Sul server la radice di questa struttura è `~/sites/foto/storage/app/public`.

### File degli autori (`autore.md`)

Gli utenti/autori e i loro dati sono definiti da un file markdown con front matter, nella cartella dell'autore. Lo slug dell'autore è il nome della cartella. I campi sono provvisori fino al punto 5 (pagina autore); i recapiti sono tutti facoltativi. Il comando artisan deve ignorare questo file nella scansione delle foto.

```md
---
nome: <Nome visualizzato>        # se assente si usa il nome della cartella
recapiti:                        # tutti facoltativi, ometti le righe che non servono
  email: <indirizzo@dominio>
  instagram: <@profilo>
  sito: <https://...>
  telefono: <+39 ...>
---

<Descrizione libera dell'autore in markdown, facoltativa>
```

Una copia di questo esempio va inserita nel nuovo progetto quando viene creato.

### Trasferimento e sincronizzazione

1. Si invia la cartella dell'evento dal PC al server (host SSH `mandich`). Raccomandato `rsync` su SSH al posto di `scp`: invia solo ciò che manca o è cambiato, riprende un trasferimento interrotto (utile da hotspot) e si può rilanciare senza ricopiare tutto. Esempio indicativo, da verificare con i nomi con spazi: `rsync -av --partial --progress -e ssh "<cartella evento>/" mandich:~/sites/foto/storage/app/public/<autore>/<cartella evento>/` (niente `-z`: i JPG non si comprimono; niente `--delete` finché non si decide il comportamento sulle rimozioni, sezione 5).
2. Si lancia il comando artisan sul server: scansiona il disco, sincronizza il DB (autori, eventi, foto, audio), genera le tre versioni delle foto e avvisa sui titoli discordanti.

### Generazione delle versioni

Fatta dal comando artisan sul server, come job nel worker, **una foto alla volta**. Le foto sono da ~12 MP: una decodifica con GD occupa circa 50 MB, accettabile ma da tenere sotto controllo su una VM con meno di 1 GB di RAM e swap già in uso. Le versioni stanno in `storage/app/public/_derived/{web,thumb}/<id foto>.webp` (cartella riservata, mai scansionata come autore). Parametri proposti: web 1920 px sul lato lungo, miniatura 480 px, WebP qualità 80.

### Servizio dei file

nginx serve `/storage/` da `storage/app/public` come in `unlimiteddb`; le immagini Docker non contengono mai le foto. Deploy del codice con lo stesso schema di `unlimiteddb` (GitHub Actions → ghcr.io → hook in `deploy/`).

### Spazio

Originali ~4 GB (+~4 GB/anno), derivati stimati sotto 1 GB (stima, non misurata). I 21 GB liberi bastano per diversi anni a questo ritmo, ma sono condivisi con gli altri siti.

## 4. Vincoli che il punto 1 pone sugli altri punti

- **Path e autori (punto 2)**: la struttura `<autore>/<evento>/<file>` evita le collisioni tra file con lo stesso nome (es. `IMG_0001.JPG`) di autori diversi, e l'originale resta scaricabile col suo nome.
- **Database come indice**: il DB, popolato dal comando di sincronizzazione, sostituisce `photo.txt`, `song.txt`, `gallery.txt` e `bash/scan.sh`.
- **ZIP e filtri (punti 2-3)**: dato che lo ZIP contiene la galleria visualizzata, l'URL dello ZIP deve poter esprimere la stessa selezione della vista (evento, autori, più eventi di un autore).

## 5. Decisioni ancora aperte

1. **Database e RAM**: misurare con `docker stats` quanto pesano i container esistenti e scegliere tra MariaDB dedicato (pattern di `unlimiteddb`), schema nel MariaDB di un sito esistente o SQLite (piano, Fase 0.1).
2. **Parametri delle versioni**: proposta WebP, web 1920 px, miniatura 480 px, qualità 80 (foto da 12 MP). Da confermare guardando i risultati sulle prime foto e la dimensione totale di `_derived`.
3. **Proxy Cloudflare**: `*.mandich.dev` è proxato; i download grandi (ZIP da centinaia di MB, in streaming) passano da lì. Da verificare in implementazione (accettato).
4. **Hostname di prova**: proposto `foto-test.mandich.dev` (ricade nel wildcard DNS, come `unlimiteddb-test`), così il sito attuale resta online fino al passaggio finale.
5. **Repository e autore**: URL del repository GitHub e nome del branch da deployare (proposto `new`), e nome della cartella autore da usare per le 8 gallerie esistenti.

## 6. Obsoleto nel vecchio sistema

`bash/scan.sh`, `bash/all.sh` (deploy via push su GitHub Pages), `photo.txt`, `song.txt`, `gallery.txt`, `photos.zip` e `photos.zip.hash`, il bottone "Genera ZIP" lato client.

## 7. Prossimi passi

1. Chiudere le decisioni aperte (sezione 5).
2. Piano scritto: `C:\Users\RickyMandich\PROJECT\foto\implementationPlan-gestioneFotoFuoriDaGit.md`; da eseguire dopo la Fase 0.
3. Procedere con i punti 2-6, tenendo conto della sezione 4.
