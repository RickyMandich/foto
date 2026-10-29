# Foto

Sito di gallerie fotografiche di eventi (cortei, presìdi, iniziative), con foto di più autori, download degli originali (singoli o ZIP) e audio opzionale per evento. È la ricostruzione da zero del vecchio sito statico su GitHub Pages (progetto `oldFoto`, analisi e decisioni in `..\oldFoto\AnalyzeForRefactor.md`).

> **Questo README descrive il progetto così com'è oggi**, non come sarà a fine piano. Roadmap e stato di avanzamento: [`todo.md`](todo.md); piano in corso: [`implementationPlan-gestioneFotoFuoriDaGit.md`](implementationPlan-gestioneFotoFuoriDaGit.md).

## How to use

Sviluppo locale con Docker, sito su **http://localhost:88**:

```bash
docker compose -f docker-compose.dev.yml up -d --build   # prima volta e dopo ogni modifica al codice (il codice sta nell'immagine)
docker compose -f docker-compose.dev.yml exec app php artisan migrate
# copia le foto di prova in storage/app/public/rickymandich/<AAAA MM GG - descrizione>/
docker compose -f docker-compose.dev.yml exec app php artisan media:sync
docker compose -f docker-compose.dev.yml logs -f worker   # generazione delle versioni
docker compose -f docker-compose.dev.yml down             # ferma tutto (con -v cancella anche il database)
```

Il database è MariaDB (porta 3307 sull'host per i client SQL). Pagine pubbliche non ancora implementate: per ora si verifica con i test e con `GET http://localhost:88/download/zip?eventi[]=AAAA-MM-GG`.

Sul server (host di prova `foto-test.mandich.dev`): `bash/upload-event.sh "<cartella-evento>" rickymandich` invia un evento, poi `cd ~/sites/foto && docker compose exec -T app php artisan media:sync`. Il deploy parte da un push sul branch `laravel`.

Dettagli nelle sezioni seguenti; come è fatto il compose di sviluppo (e in cosa differisce da quello di `unlimiteddb`) è spiegato al punto 7.5 del piano.

## Stato attuale

Tutti i piani da **01 a 09** sono stati implementati e verificati con la suite di test Pest (59 test passati, 187 asserzioni):

- **Piano 01**: Layout Blade con tema scuro, componenti base (`layout`, `icon`, `badge`, `button`), icone Heroicons SVG integrate, pagine d'errore (404, 419, 429, 500, 503) e comando `php artisan media:demo`.
- **Piano 02**: Gestione logica e query di selezione `GallerySelection` per evento, autore o selezioni multiple in AND con generazione ZIP coerente.
- **Piano 03**: Home page (`/`) con elenco degli eventi dal più recente, copertine con responsive `srcset`, badge conteggi e paginazione.
- **Piano 04**: Pagina della galleria (`/eventi/{data}` e `/galleria`) lato server con visualizzazione carosello e griglia, chip filtri autore e download ZIP.
- **Piano 05**: Visualizzatore foto interattivo vanilla JS a scroll-snap: carosello con gestione memoria, tastiera (frecce, Home, End, Spazio, G, F), fullscreen e autoplay.
- **Piano 06**: Riproduttore audio di sottofondo della selezione con coda Fisher-Yates, supporto Media Session API e volume memorizzato in localStorage.
- **Piano 07**: Pagine autori (`/autori` e `/autori/{slug}`) con markdown sanificato, link contatti sicuri e selezione multi-evento per download/visualizzazione combinata.
- **Piano 08**: Open Graph e Twitter card responsive per foto specifiche (`?f=`), indicizzazione disattivata per default con header `X-Robots-Tag` e sitemap dinamica (`/sitemap.xml`).
- **Piano 09**: Content Security Policy (CSP) senza CDN, security headers (`X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`), compressione gzip e cache immutabile degli asset.

## Come funziona

Foto e audio stanno **fuori da git**, nel disk `public` di Laravel (`storage/app/public`), con questa struttura (uguale sul PC e sul server):

```
storage/app/public/
├── <autore>/
│   ├── autore.md                          # dati dell'autore, facoltativo (esempio: docs/autore.example.md)
│   └── <AAAA MM GG - descrizione>/        # una galleria: un autore, un evento
│       ├── *.JPG                          # originali (jpg, jpeg, png, webp)
│       └── *.mp3                          # audio opzionale (mp3, aac, m4a, ogg, wav)
└── _derived/{web,thumb}/<id foto>.webp    # versioni generate: non modificare a mano
```

Flusso di lavoro:

1. Si invia la cartella dell'evento al server con `bash/upload-event.sh <cartella-evento> <autore>` (rsync su SSH, riprende se interrotto).
2. Sul server si lancia `php artisan media:sync`: scansiona il disco (**il filesystem è la source of truth**) e allinea il database.
3. Per ogni foto nuova o modificata `media:sync` mette in coda `GeneratePhotoVersionsJob`, che crea la versione **web** (lato lungo 1920 px) e la **miniatura** (480 px) in WebP qualità 80. L'originale resta quello scaricabile.
4. nginx serve originali e versioni da `/storage/...`; gli originali si scaricano uno a uno (link con `download`) o in ZIP.

Regole della sincronizzazione (`app/Services/Media/MediaSynchronizer.php`):

- Gli eventi si identificano dalla **data** (un evento al giorno); un evento può avere una galleria per autore. Il titolo dell'evento è quello della prima galleria in ordine alfabetico di cartella autore; se i titoli differiscono (a parte maiuscole e spazi) `media:sync` stampa un avviso.
- Cartelle che iniziano con `_` o `.` non sono mai autori; cartelle evento con nome non conforme, data non valida o data duplicata per lo stesso autore sono ignorate con un avviso.
- Se un file, una galleria o un autore spariscono dal disco vengono tolti dal database e vengono cancellate le versioni generate, **mai gli originali**. Gli eventi rimasti senza gallerie vengono eliminati.
- È **idempotente**: senza modifiche sul disco non cambia nulla e non mette in coda job. Una foto viene rielaborata se cambiano dimensione o data di modifica, o se mancano le versioni su disco.
- Protezione: se non trova nessuna cartella autore ma il database contiene dati, si ferma (storage non montato?). `--allow-empty` forza.
- `--dry-run` esegue tutto in una transazione che viene annullata: niente scritture, niente job.

`autore.md` ha un front matter YAML con `nome` e `recapiti` (tutti facoltativi) seguito da una descrizione in markdown: vedi [`docs/autore.example.md`](docs/autore.example.md).

## Comandi

```bash
php artisan media:sync               # allinea il DB con le cartelle su disco
php artisan media:sync --dry-run     # mostra cosa cambierebbe, senza scrivere
php artisan media:sync --allow-empty # consente la sincronizzazione senza cartelle autore
php artisan queue:work               # elabora la coda (generazione delle versioni)
composer test                        # test Pest
```

Download ZIP: `GET /download/zip?eventi[]=2025-09-20&autori[]=anna` (almeno uno dei due parametri; le selezioni si combinano in AND; max 6 richieste al minuto per IP). Risposta 422 senza parametri o con date non valide, 404 se non ci sono foto.

## Stack

| Livello | Tecnologia |
|---|---|
| Backend | PHP 8.2+, Laravel 12 |
| Immagini | Intervention Image 3 (driver GD), WebP |
| ZIP | `maennchen/zipstream-php` (streaming, modalità STORE) |
| Front matter | `symfony/yaml` |
| Test | Pest 3 |
| Frontend | Blade, Tailwind CSS 4, Vite 7 |
| Code, cache, sessioni | driver `database` |
| Infrastruttura | Docker (php-fpm + nginx + worker supervisord), Traefik su VM Oracle, deploy con GitHub Actions → ghcr.io (stesso schema di `unlimiteddb`) |

## Struttura del codice

| Percorso | Contenuto |
|---|---|
| `config/photos.php` | disk, cartella `_derived`, estensioni, parametri delle versioni, regex del nome cartella evento |
| `database/migrations/` | `authors`, `events`, `galleries`, `photos`, `tracks` (le colonne dei nomi file usano una collation esatta su MariaDB, vedi `app/Support/DatabaseCollation.php`) |
| `app/Models/` | `Author`, `Event`, `Gallery`, `Photo`, `Track`; `Photo::url()` e `Track::url()` costruiscono gli URL pubblici (`app/Support/PublicUrl.php`) |
| `app/Services/Media/MediaSynchronizer.php` | logica di `media:sync` |
| `app/Services/Media/PhotoVersionGenerator.php` | creazione di web e miniatura |
| `app/Jobs/GeneratePhotoVersionsJob.php` | job in coda (uno alla volta, timeout 120 s, 3 tentativi) |
| `app/Http/Controllers/DownloadController.php` | ZIP in streaming |
| `tests/Feature/Media/` | test di sincronizzazione, versioni e ZIP; helper in `tests/Pest.php` |
| `docker/`, `Dockerfile`, `docker-compose*.yml`, `deploy/`, `.github/workflows/` | infrastruttura |
| `bash/upload-event.sh` | invio di un evento al server |

## Setup locale

Prerequisiti: PHP 8.2+ con estensioni `gd` (con jpeg e webp) ed `exif`, Composer, Node 20+ e un database raggiungibile (vedi `.env.example`; i test usano SQLite in memoria).

```bash
composer install     # la prima volta dopo aver aggiunto le dipendenze: composer update intervention/image maennchen/zipstream-php symfony/yaml
composer run setup   # crea .env, genera la chiave, migra, builda gli asset
composer run dev     # server, code e Vite insieme
composer test        # test Pest
```

In alternativa con Docker: `docker compose -f docker-compose.dev.yml up -d --build` (sito su http://localhost:88, MariaDB sulla porta 3307, worker incluso). Metti le foto di prova in `storage/app/public/<autore>/<AAAA MM GG - descrizione>/` e lancia `docker compose -f docker-compose.dev.yml exec app php artisan media:sync`.

## Deploy (VM Oracle)

Come `unlimiteddb`: push sul branch `laravel` → GitHub Actions costruisce l'immagine `ghcr.io/rickymandich/foto:laravel` → la VM la scarica con `~/scripts/deploy-image.sh foto laravel`, che usa `deploy/pre-deploy.sh` e `deploy/post-deploy.sh`. Sito in `~/sites/foto`, host di prova `foto-test.mandich.dev` (il DNS ricade nel wildcard `*.mandich.dev`). Il `.env` di produzione si crea a mano sul server.

Note di infrastruttura:

- Gli originali arrivano da rsync e restano dell'utente host (file 644, cartelle 755): il container li legge, scrive solo in `storage/app/public/_derived` (l'entrypoint non fa `chown -R` su tutto `storage`).
- La VM ha meno di 1 GB di RAM: un solo worker, una foto alla volta (`--memory=200`, `memory_limit=256M`). `retry_after` della coda (180 s) deve restare superiore al timeout del job (120 s).
- `media:sync` non viene lanciato dal deploy: va lanciato a mano dopo ogni upload (`docker compose exec -T app php artisan media:sync`).
- Il database di produzione è MariaDB dedicato (servizio `db` di `docker-compose.yml`); resta da verificare in Fase 0.1 che la RAM della VM basti.

## Convenzioni di sviluppo

- Non modificare mai i file dei framework e delle dipendenze (`vendor/`, `node_modules/`).
- Leggere e rispettare le regole in `.agent/rules/*.md` se presenti (oggi non ce ne sono).
- Flusso per ogni modifica non banale: analisi, piano `implementationPlan-{cosaDaFare}.md` nella radice, esecuzione, rinomina in `implementationPlan-V-{cosaDaFare}.md`. Per modifiche molto piccole il piano non serve.
- Tenere aggiornati `todo.md` e questo README di pari passo con il codice effettivamente implementato.
