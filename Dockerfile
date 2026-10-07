FROM composer:2 AS vendor

WORKDIR /app
COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader --ignore-platform-req=ext-intl

FROM node:24-slim AS assets

WORKDIR /app
COPY src/package.json src/package-lock.json ./
RUN npm ci --include=optional \
    && npm install --no-save --ignore-scripts --include=optional \
        @rolldown/binding-linux-x64-gnu@$(node -p "require('rolldown/package.json').version")
COPY src/resources ./resources
COPY src/vite.config.js ./
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM php:8.4-fpm AS runtime

RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
    fonts-dejavu-core \
    fonts-noto-cjk \
    fonts-noto-core \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libicu-dev \
    libpng-dev \
    libpq-dev \
    libwebp-dev \
    libzip-dev \
    supervisor \
    unzip \
    zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 1000 laravel \
    && useradd --uid 1000 --gid laravel --create-home --shell /bin/bash laravel

RUN sed -i 's/^user = .*/user = laravel/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = .*/group = laravel/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm = .*/pm = dynamic/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_children = .*/pm.max_children = 6/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.start_servers = .*/pm.start_servers = 2/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 4/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i -E 's/^;?[[:space:]]*pm.max_requests[[:space:]]*=.*/pm.max_requests = 500/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i -E 's/^;?[[:space:]]*request_terminate_timeout[[:space:]]*=.*/request_terminate_timeout = 60s/' /usr/local/etc/php-fpm.d/www.conf \
    && { \
        echo 'expose_php=0'; \
        echo 'display_errors=0'; \
        echo 'log_errors=1'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=10M'; \
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
COPY src/public/binance.jpeg /opt/lhiestore-public-binance.jpeg
COPY src/public/assets/product-placeholder.svg /opt/lhiestore-public-product-placeholder.svg
RUN mkdir -p /opt/lhiestore-public-storefront \
    && cp -R public/storefront/. /opt/lhiestore-public-storefront/
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint-laravel
COPY docker-scheduler.sh /usr/local/bin/docker-scheduler-laravel
COPY docker-outbound-recovery.sh /usr/local/bin/docker-outbound-recovery-laravel
RUN rm -rf public/storage \
    && rm -rf database/seeders database/factories tests resources/css resources/js \
    && rm -f .env.example README.md package.json package-lock.json phpunit.xml phpunit.concurrency.xml vite.config.js \
    && ln -s /var/www/html/storage/app/public public/storage \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/private storage/app/public bootstrap/cache \
    && chmod +x /usr/local/bin/docker-entrypoint-laravel \
    && chmod +x /usr/local/bin/docker-scheduler-laravel \
    && chmod +x /usr/local/bin/docker-outbound-recovery-laravel \
    && chown -R laravel:laravel /var/www/html \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-laravel"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
