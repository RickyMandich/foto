# Stesso schema di unlimiteddb, senza lo stage delle icone (non c'è ancora un'icona).

# --- Stage 1: dipendenze PHP con Composer (solo vendor, senza codice) ---
# Stessa immagine php:8.2-fpm-alpine dello stage finale: il binario di Composer
# viene copiato dall'immagine ufficiale, così la versione di PHP non cambia
# mai senza preavviso. Dipende solo da composer.json/lock: la cache non viene
# invalidata dalle modifiche al codice.
FROM php:8.2-fpm-alpine AS composer-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# --- Stage 2: build degli asset con Vite + Tailwind CSS 4 ---
# La configurazione di Tailwind sta in resources/css/app.css (@source); i path
# che puntano a vendor/ esistono solo se copiamo qui le viste di paginazione
# di Laravel, altrimenti le loro classi non finiscono nel CSS.
FROM node:20-alpine AS node-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/
COPY --from=composer-deps /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views/ ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/
RUN npm run build

# --- Stage 3: autoloader ottimizzato con il codice dell'app ---
FROM composer-deps AS composer-builder
COPY . .
RUN composer dump-autoload --optimize --no-dev

# --- Stage 4: immagine finale PHP-FPM ---
FROM php:8.2-fpm-alpine

# gd con jpeg e webp (versioni delle foto), exif (data di scatto e orientamento),
# zip, e il resto come in unlimiteddb. memory_limit alto abbastanza per decodificare
# una foto da 12 MP (circa 50 MB con GD).
RUN apk add --no-cache \
    libpng-dev libjpeg-turbo-dev libwebp-dev libzip-dev libxml2-dev oniguruma-dev supervisor \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring bcmath xml gd zip exif \
    && echo "memory_limit=256M" > /usr/local/etc/php/conf.d/memory.ini

WORKDIR /var/www/html

COPY --from=composer-builder /app /var/www/html
# Copia "sorgente" immutabile degli asset, usata dall'entrypoint per rinfrescare
# public/build a ogni avvio (in dev quel path è coperto da un bind mount)
COPY --from=node-builder /app/public/build /opt/build-assets
COPY --from=node-builder /app/public/build /var/www/html/public/build

RUN chown -R www-data:www-data /var/www/html/bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
EXPOSE 9000
CMD ["php-fpm"]
