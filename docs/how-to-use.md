# How to Use Foto

Questo documento fornisce una panoramica rapida per iniziare a lavorare sul progetto **Foto**.

## Prerequisiti
- **Windows** con WSL (opzionale) o PowerShell.
- **Docker Desktop** (versione recente).
- **PHP 8.2+**, **Composer**, **Node 20+** (necessari solo per sviluppo locale senza Docker).

## Setup locale con Docker
```bash
# Avvia i container (builda se prima volta o dopo modifiche al codice)
docker compose -f docker-compose.dev.yml up -d --build

# Esegui le migrazioni del database
docker compose -f docker-compose.dev.yml exec app php artisan migrate

# Copia foto di prova (sostituisci <autore> e <evento>)
# Example: storage/app/public/rickymandich/2025-09-20 - corteo/
# Place your images inside that folder.
```

## Sincronizzazione media
```bash
# Scansiona il disco e allinea il database (dry‑run per vedere le azioni)
docker compose -f docker-compose.dev.yml exec app php artisan media:sync --dry-run

# Esegui realmente la sincronizzazione
docker compose -f docker-compose.dev.yml exec app php artisan media:sync

# Avvia il worker per generare le versioni web/thumbnail
docker compose -f docker-compose.dev.yml exec app php artisan queue:work
```

## Test
```bash
composer test   # Usa SQLite in‑memory, non dipende da Docker
```

## Aggiungere un autore
1. Copia `docs/autore.example.md` in `storage/app/public/<autore>/autore.md`.
2. Modifica i campi `nome`, `recapiti` e la descrizione in markdown.
3. Esegui `php artisan media:sync` (o tramite Docker) per indicizzare l'autore.

## Accesso al sito
- **Sviluppo locale:** http://localhost:88
- **Produzione:** https://foto.mandich.dev

## Documentazione aggiuntiva
- [README.md](../README.md) – panoramica completa del progetto.
- [docs/autore.example.md](autore.example.md) – schema per il file `autore.md`.

---
*Questo documento è mantenuto aggiornato insieme al `README.md`.*

