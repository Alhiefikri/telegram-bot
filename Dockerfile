FROM composer:2 AS vendor

WORKDIR /app
COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader --ignore-platform-req=ext-intl

FROM node:24-alpine AS assets

WORKDIR /app
COPY src/package.json src/package-lock.json ./
RUN npm ci
COPY src/resources ./resources
COPY src/vite.config.js ./
RUN npm run build

FROM php:8.4-fpm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libicu-dev \
    libpng-dev \
    libpq-dev \
    libzip-dev \
    supervisor \
    unzip \
    zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_pgsql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 1000 laravel \
    && useradd --uid 1000 --gid laravel --create-home --shell /bin/bash laravel

RUN sed -i 's/^user = .*/user = laravel/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = .*/group = laravel/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_children = .*/pm.max_children = 12/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.start_servers = .*/pm.start_servers = 4/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 4/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 8/' /usr/local/etc/php-fpm.d/www.conf \
    && { \
        echo 'expose_php=0'; \
        echo 'display_errors=0'; \
        echo 'log_errors=1'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
      } > /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /var/www/html

COPY src/ ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/public/build /opt/lhiestore-public-build
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint-laravel
COPY docker-scheduler.sh /usr/local/bin/docker-scheduler-laravel

RUN rm -rf public/storage \
    && rm -rf database/seeders database/factories tests resources/css resources/js \
    && rm -f .env.example README.md package.json package-lock.json phpunit.xml phpunit.concurrency.xml vite.config.js \
    && ln -s /var/www/html/storage/app/public public/storage \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache \
    && chmod +x /usr/local/bin/docker-entrypoint-laravel \
    && chmod +x /usr/local/bin/docker-scheduler-laravel \
    && chown -R laravel:laravel /var/www/html \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-laravel"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
