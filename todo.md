# Todo

Roadmap a specchio dei piani di implementazione. Piano attuale: [`implementationPlan-gestioneFotoFuoriDaGit.md`](implementationPlan-gestioneFotoFuoriDaGit.md) (punto 1 dell'analisi in `..\oldFoto\AnalyzeForRefactor.md`).

> Aggiornato durante l'esecuzione. Legenda: `[x]` fatto e verificato, `[~]` scritto ma non ancora provato in ambiente reale, `[ ]` da fare.

## Verifiche in locale

- [x] `composer update intervention/image maennchen/zipstream-php symfony/yaml` (installati anche Pest e le altre dipendenze di sviluppo; `composer.lock` rigenerato)
- [x] `test.cmd`: **28 test passati (85 asserzioni)** — sincronizzazione, versioni, ZIP
- [ ] `php artisan media:sync --dry-run` e poi reale su una cartella di prova in `storage/app/public` (con foto vere da ~12 MP: orientamento EXIF, tempi, RAM)
- [ ] scaricare uno ZIP da un browser e aprirlo
- [ ] `docker compose -f docker-compose.dev.yml up -d --build`, poi `migrate` e `media:sync` da `exec app`: sito su http://localhost:88, worker che genera le versioni (compose di sviluppo riscritto, mai avviato)

## Punto 1 — Gestione foto fuori da git

- [ ] Fase 0: misura RAM VM (decisi: database MariaDB, branch `laravel`, autore `rickymandich`); rsync sul server; URL del repository; regole agente (**richiede l'utente: SSH/decisioni**)
- [x] Fase 1: configurazione base (`config/photos.php`, timezone, locale, dipendenze installate)
- [x] Fase 2: schema DB (authors, events, galleries, photos, tracks) — fatto dall'utente, senza colonne `seen_token` (la sincronizzazione tiene gli id visti in memoria)
- [x] Fase 3: modelli e helper URL/percorsi — fatto dall'utente (`Support\PublicUrl`, `Support\DatabaseCollation`)
- [x] Fase 4: comando `media:sync` (`Services/Media/MediaSynchronizer.php`, `Console/Commands/SyncMedia.php`) — test passati; resta la prova su dati reali
- [x] Fase 5: generazione versioni (`Services/Media/PhotoVersionGenerator.php`, `Jobs/GeneratePhotoVersionsJob.php`) — test passati; resta la prova su foto reali
- [x] Fase 6: download ZIP in streaming (`Http/Controllers/DownloadController.php`, rotta `download.zip`); download singolo = link all'originale con `download`, nessun codice PHP — test passati; resta la prova da browser e dietro Cloudflare
- [~] Fase 7: Docker, nginx, worker, deploy hooks, workflow — scritti (`Dockerfile`, `docker/`, `docker-compose*.yml`, `deploy/`, `.github/workflows/deploy-foto.yml`, `bash/upload-event.sh`), mai provati; MariaDB dedicato (deciso), branch di deploy `laravel`, compose di sviluppo sulla porta 88 (spiegato al punto 7.5 del piano)
- [x] Fase 8: test Pest (`tests/Pest.php`, `tests/Feature/Media/{MediaSyncTest,PhotoVersionGeneratorTest,ZipDownloadTest}.php`) — 28 passati
- [ ] Fase 9: deploy di prova su `foto-test.mandich.dev` (**richiede l'utente**)
- [ ] Fase 10: migrazione dei contenuti esistenti (1017 foto, 14 audio, 8 eventi) e verifiche (**richiede l'utente**)
- [~] Fase 11: README e documentazione — README riscritto con lo stato reale, `docs/autore.example.md` creato; `AnalyzeForRefactor.md` (in `oldFoto`) da aggiornare a fine esecuzione
- [ ] Fase 12: reset della storia del vecchio repository e nuovo branch (**richiede l'utente**)

## Piani per finire il sito (01-09)

Dipendenze: `01` → nessuna; `02` → nessuna (parallelo a 01); `03` → 01, 02; `04` → 01, 02; `05` → 04; `06` → 04, 05; `07` → 01, 02, 03, 04; `08` → 04, 05; `09` → 04, 08.

- [x] [Piano 01](implementationPlan-V-01-layoutEBaseUi.md) — layout, tema, componenti di base, `media:demo`
- [x] [Piano 02](implementationPlan-V-02-selezioneGalleria.md) — `GallerySelection`, ordine, filtri, ZIP unificato (parallelo a 01)
- [x] [Piano 03](implementationPlan-V-03-homeEventi.md) — home: elenco degli eventi con miniature e paginazione
- [x] [Piano 04](implementationPlan-V-04-paginaGalleria.md) — pagina galleria lato server (evento e selezione, griglia, barra)
- [x] [Piano 05](implementationPlan-V-05-visualizzatoreFoto.md) — carosello JavaScript, griglia, autoplay, tastiera, schermo intero
- [x] [Piano 06](implementationPlan-V-06-playerAudio.md) — player audio della galleria
- [x] [Piano 07](implementationPlan-V-07-paginaAutore.md) — pagine degli autori e selezione multi-evento
- [x] [Piano 08](implementationPlan-V-08-condivisioneESeo.md) — Open Graph, condivisione, indicizzazione, icona
- [x] [Piano 09](implementationPlan-V-09-sicurezzaEPerformance.md) — CSP, security header, gzip, cache asset

## Dopo i piani

- [x] `.env-overrides` (variabili non sensibili tracciate in Git, caricate da `bootstrap/app.php` prima del `.env`, come in `unlimiteddb`): file creato (vuoto, solo commento), `Dotenv::createMutable(...)->safeLoad()` in `bootstrap/app.php`, README aggiornato. Non provato con una build né con la suite (nessuna variabile dentro, quindi nessun effetto atteso)
- [~] `media:sync` automatico: all'avvio del worker (`media-sync-boot`), nel `post-deploy.sh` e ogni giorno con lo scheduler (`laravel-schedule` = `schedule:work` nel `worker.conf`; lo schedule è in `routes/console.php`) — scritto, da provare sulla VM (`supervisorctl status` nel worker, log all'avvio)
- [ ] Passaggio di `foto.mandich.dev` dalle GitHub Pages alla VM (DNS) e spegnimento delle Pages
