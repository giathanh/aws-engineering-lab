FROM php:8.3-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip libonig-dev \
 && docker-php-ext-install pdo_pgsql pdo_mysql pcntl mbstring zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/app
FROM base AS dependencies
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
FROM node:22-bookworm-slim AS web
WORKDIR /web
COPY web/package*.json ./
RUN npm ci
COPY web/ ./
RUN npm run build
FROM base AS app
COPY --from=dependencies --chown=www-data:www-data /var/www/app /var/www/app
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-lab.conf
USER www-data
EXPOSE 9000
CMD ["php-fpm","-F"]
FROM nginx:1.28-alpine AS nginx
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=web /web/dist/ /var/www/app/public/
EXPOSE 8080
