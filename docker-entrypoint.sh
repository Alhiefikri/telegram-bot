#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache public/storefront

artisan() {
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh laravel -c "php artisan $*"
    else
        php artisan "$@"
    fi
}

is_truthy() {
    case "${1:-}" in
        1|true|TRUE|True|yes|YES|Yes|on|ON|On) return 0 ;;
        *) return 1 ;;
    esac
}

fail_startup() {
    echo "ERROR: $*" >&2
    exit 1
}

require_env() {
    eval "value=\${$1:-}"
    [ -n "$value" ] || fail_startup "$1 is required."
}

reject_placeholder_env() {
    eval "value=\${$1:-}"
    case "$value" in
        ""|change-me|replace-me|replace-with-*|base64:replace-with-*|*dogfood*)
            fail_startup "$1 still uses an example or placeholder value."
            ;;
    esac
}

if [ "${APP_ENV:-production}" != "production" ] && ! is_truthy "${APP_ALLOW_NON_PRODUCTION_DOCKER:-false}"; then
    fail_startup "Docker runtime is configured for production only. Set APP_ENV=production or APP_ALLOW_NON_PRODUCTION_DOCKER=true intentionally."
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    if is_truthy "${APP_DEBUG:-false}"; then
        fail_startup "APP_DEBUG must be false in production."
    fi

    case "${APP_URL:-}" in
        https://*) ;;
        *) fail_startup "APP_URL must use https:// in production." ;;
    esac

    case "${APP_URL:-}" in
        https://example.com|https://example.com/*)
            fail_startup "APP_URL still uses the example domain."
            ;;
    esac

    require_env APP_KEY
    require_env DB_PASSWORD
    require_env TELEGRAM_BOT_TOKEN
    require_env TELEGRAM_WEBHOOK_SECRET

    reject_placeholder_env APP_KEY
    reject_placeholder_env DB_PASSWORD
    reject_placeholder_env TELEGRAM_BOT_TOKEN
    reject_placeholder_env TELEGRAM_WEBHOOK_SECRET

    if is_truthy "${DOGFOOD_TIMING_ENABLED:-false}" || is_truthy "${DOGFOOD_FAKE_TELEGRAM_OUTBOUND:-false}"; then
        is_truthy "${APP_ALLOW_DOGFOOD_RUNTIME:-false}" || fail_startup "Dogfood runtime flags are not allowed in production Docker."
    fi

    if [ "${TELEGRAM_PAYMENT_MODE:-manual}" = "pakasir" ]; then
        require_env PAKASIR_SLUG
        require_env PAKASIR_API_KEY
        reject_placeholder_env PAKASIR_SLUG
        reject_placeholder_env PAKASIR_API_KEY

        case "${PAKASIR_BASE_URL:-}" in
            https://*) ;;
            *) fail_startup "PAKASIR_BASE_URL must use https:// in production." ;;
        esac
    fi
fi

if [ "$(id -u)" = "0" ]; then
    install -d -o laravel -g laravel -m 0775 storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache public/storefront
    chown -R laravel:laravel storage/logs
    chmod -R ug+rwX storage/logs
    chmod 2775 storage/logs
fi

rm -f public/storefront/index.html public/storefront/index.html.tmp

if [ -d /opt/lhiestore-public-build ]; then
    rm -rf public/build
    mkdir -p public
    cp -R /opt/lhiestore-public-build public/build
fi

if [ -d /opt/lhiestore-public-storefront ]; then
    mkdir -p public/storefront
    cp -R /opt/lhiestore-public-storefront/. public/storefront/
fi

if [ -r /opt/lhiestore-public-binance.jpeg ]; then
    cp /opt/lhiestore-public-binance.jpeg public/binance.jpeg
fi



if [ "${APP_AUTO_MIGRATE:-false}" = "true" ]; then
    artisan migrate --force
fi

if [ "${APP_OPTIMIZE:-true}" = "true" ]; then
    artisan optimize
fi

if [ "${APP_AUTO_SET_TELEGRAM_WEBHOOK:-false}" = "true" ]; then
    artisan telegram:webhook:set || echo "WARNING: Telegram webhook setup failed; continuing startup."
fi

exec "$@"
