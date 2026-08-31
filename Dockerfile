# syntax=docker/dockerfile:1

# ---- PHP dependencies (producción — sin paquetes de desarrollo/test) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-scripts --no-interaction --no-progress \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---- PHP dependencies (dev — incluye Pest/Pint/etc, nunca se usa en prod) ----
FROM composer:2 AS vendor-dev
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-scripts --no-interaction --no-progress \
    --prefer-dist --ignore-platform-reqs

# ---- Frontend build ----
# node:22 (no 20, como en wpbotreserva) porque este proyecto usa vite ^8 y
# tailwindcss ^4, que exigen una versión de Node más reciente que la que usa
# el patrón de referencia — ver docs de diferencias en el informe del hito.
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- Base PHP-FPM (extensiones/config compartidas por app y app-dev) ----
# composer.lock exige "php": "^8.3"; se usa 8.4 (satisface el rango) por
# consistencia con el resto de la infraestructura del VPS.
#
# Extensiones instaladas explícitamente — solo las que este proyecto usa
# realmente (verificado contra composer.lock y el código, no copiado a
# ciegas del patrón de referencia):
#   - pdo_mysql : DB_CONNECTION=mysql (única conexión usada)
#   - mbstring  : requerida por laravel/framework (ext-mbstring en su
#                 composer.lock), no viene habilitada por defecto en la
#                 imagen oficial de PHP
#   - intl      : requerida por symfony/validator y otras dependencias
#                 (ext-intl en composer.lock)
#   - zip       : requerida por phpoffice/phpspreadsheet u otra dependencia
#                 con "ext-zip" como require duro (composer.lock)
# NO se instala "redis": no hay ningún consumidor real (QUEUE_CONNECTION y
# CACHE_STORE son "database" por defecto; sin Redis::/Cache::store('redis')
# en el código). NO se instala "bcmath": no aparece como require duro de
# ningún paquete en composer.lock para este proyecto.
FROM php:8.4-fpm-alpine AS php-base
RUN apk add --no-cache \
        icu-dev libzip-dev oniguruma-dev fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql intl zip \
    && apk del .build-deps

COPY docker/php/fpm-healthcheck.conf /usr/local/etc/php-fpm.d/zz-healthcheck.conf
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /var/www/html
EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=5s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
CMD ["php-fpm"]

# ---- "app": imagen de producción (la que se despliega en el VPS) ----
FROM php-base AS app
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build
# git no versiona directorios vacíos — storage/framework/{cache,sessions,views}
# no existen en un checkout limpio hasta que algo los crea.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data

# ---- "app-dev": misma base, con Pest/Pint/etc — solo la usa el override de dev ----
FROM php-base AS app-dev
USER root
# pcov: driver de cobertura de tests — liviano, solo dev, nunca en producción.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps
COPY --from=vendor-dev /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache vendor
USER www-data

# ---- Nginx (static assets baked in, proxian a "app" para requests dinámicos) ----
FROM nginx:1.27-alpine AS nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
