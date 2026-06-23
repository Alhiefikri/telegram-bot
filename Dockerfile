FROM composer:2 AS vendor

WORKDIR /app
COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader --ignore-platform-req=ext-intl

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
    && sed -i 's/^group = .*/group = laravel/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY src/ ./
COPY --from=vendor /app/vendor ./vendor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R laravel:laravel /var/www/html \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 9000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
