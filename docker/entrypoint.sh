#!/bin/sh
set -e

# Rinfresca public/build con gli asset compilati nell'immagine (vedi Dockerfile),
# così un bind mount/volume su quel path in dev non serve mai asset "vecchi".
# Innocuo in produzione: se non c'è mount, sovrascrive con lo stesso contenuto.
if [ -d /opt/build-assets ]; then
    rm -rf /var/www/html/public/build/*
    cp -a /opt/build-assets/. /var/www/html/public/build/
fi

# Niente "chown -R" su tutto storage: gli originali (storage/app/public/<autore>/...)
# arrivano da rsync e restano dell'utente host (file 644, cartelle 755); il container
# li legge ma non li scrive. Scrive solo nelle cartelle qui sotto.
mkdir -p \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/storage/app/public/_derived

chown -R www-data:www-data \
    /var/www/html/storage/framework \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    /var/www/html/storage/app/public/_derived

exec "$@"
