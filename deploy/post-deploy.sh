#!/bin/bash
# Eseguito dalla VM dopo "docker compose up", nella cartella del sito.
set -euo pipefail

docker compose exec -T app php artisan migrate --force
# fa ricaricare il codice al processo queue:work del worker (supervisord lo rilancia)
docker compose exec -T app php artisan queue:restart
# docker/nginx/default.conf è montato dal checkout: il git pull lo cambia ma nginx non lo rilegge da solo
docker compose exec -T nginx nginx -s reload

# Nota: media:sync NON viene lanciato dal deploy. Si lancia a mano dopo ogni upload di foto:
#   docker compose exec -T app php artisan media:sync
