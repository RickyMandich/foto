#!/bin/bash
# Eseguito dalla VM dopo "docker compose up", nella cartella del sito.
set -euo pipefail

docker compose exec -T app php artisan migrate --force
# allinea il DB con ciò che c'è su disco (come www-data, per non creare file di log del root).
# Non fa fallire il deploy: può dare errore se la sync d'avvio del worker è ancora in corso (lock); il sito è comunque su.
docker compose exec -T -u www-data app php artisan media:sync || echo "media:sync non completato (già in corso o in errore): vedi 'docker compose logs worker'"
# fa ricaricare il codice al processo queue:work del worker (supervisord lo rilancia)
docker compose exec -T app php artisan queue:restart
# docker/nginx/default.conf è montato dal checkout: il git pull lo cambia ma nginx non lo rilegge da solo
docker compose exec -T nginx nginx -s reload

# media:sync parte anche da solo: all'avvio del worker (docker/supervisor/worker.conf) e ogni giorno (routes/console.php).
# Dopo un upload di foto si può comunque lanciare a mano senza aspettare:
#   docker compose exec -T -u www-data app php artisan media:sync
