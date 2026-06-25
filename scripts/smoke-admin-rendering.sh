#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(dirname -- "$SCRIPT_DIR")
APP_DIR=${APP_DIR:-"$ROOT_DIR/src"}
PORT=${PORT:-8001}
BASE_URL="http://127.0.0.1:$PORT"
SESSION_NAME="admin-rendering-smoke-$$"
DB_PATH=$(mktemp "${TMPDIR:-/tmp}/telegram-bot-admin-smoke.XXXXXX.sqlite")
SERVER_LOG=$(mktemp "${TMPDIR:-/tmp}/telegram-bot-admin-smoke-server.XXXXXX.log")
SERVER_PID=

cleanup() {
  if [ -n "${SERVER_PID:-}" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
  fi

  agent-browser --session "$SESSION_NAME" close >/dev/null 2>&1 || true
  rm -f "$DB_PATH" "$SERVER_LOG"
}
trap cleanup EXIT

if ! command -v agent-browser >/dev/null 2>&1; then
  printf 'Admin rendering smoke check requires agent-browser. Install it with: npm i -g agent-browser && agent-browser install\n' >&2
  exit 1
fi

export APP_ENV=local
export APP_DEBUG=true
export APP_URL="$BASE_URL"
export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_PATH"
export SESSION_DRIVER=file
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export MAIL_MAILER=log

printf 'Building Laravel admin assets with npm run build...\n'
npm --prefix "$APP_DIR" run build

printf 'Preparing isolated SQLite database at %s...\n' "$DB_PATH"
php "$APP_DIR/artisan" optimize:clear
php "$APP_DIR/artisan" migrate:fresh --seed --force
php "$APP_DIR/artisan" tinker --execute="App\\Models\\User::where('email', 'test@example.com')->update(['role' => App\\Enums\\UserRole::Owner]);"

printf 'Starting Laravel server at %s...\n' "$BASE_URL"
php "$APP_DIR/artisan" serve --host=127.0.0.1 --port="$PORT" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

php -r '
$url = $argv[1];
$context = stream_context_create(["http" => ["timeout" => 1, "ignore_errors" => true]]);
for ($i = 0; $i < 50; $i++) {
    if (@file_get_contents($url, false, $context) !== false) {
        exit(0);
    }
    usleep(200000);
}
exit(1);
' "$BASE_URL/admin/login"

assert_contrast() {
  local label=$1
  local violations

  violations=$(agent-browser --session "$SESSION_NAME" eval --stdin <<'JS'
(() => {
  const MIN_CONTRAST = 4.5;
  const selectors = 'label, input, textarea, select, button, a, p, span, th, td, [role="button"], [role="link"]';

  function parseRgb(color) {
    const match = color.match(/rgba?\(([^)]+)\)/);
    if (!match) {
      return null;
    }

    const parts = match[1].split(',').map((part) => Number.parseFloat(part));
    return { r: parts[0], g: parts[1], b: parts[2], a: parts[3] ?? 1 };
  }

  function blend(foreground, background) {
    return {
      r: foreground.r * foreground.a + background.r * (1 - foreground.a),
      g: foreground.g * foreground.a + background.g * (1 - foreground.a),
      b: foreground.b * foreground.a + background.b * (1 - foreground.a),
      a: 1,
    };
  }

  function effectiveBackground(element) {
    let background = { r: 255, g: 255, b: 255, a: 1 };
    const chain = [];

    for (let node = element; node; node = node.parentElement) {
      chain.push(node);
    }

    for (const node of chain.reverse()) {
      const color = parseRgb(getComputedStyle(node).backgroundColor);

      if (!color || color.a === 0) {
        continue;
      }

      background = color.a < 1 ? blend(color, background) : color;
    }

    return background;
  }

  function luminance(color) {
    const channel = (value) => {
      value /= 255;
      return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b);
  }

  function contrast(foreground, background) {
    const first = luminance(foreground);
    const second = luminance(background);

    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
  }

  return Array.from(document.querySelectorAll(selectors))
    .map((element) => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();

      if (style.display === 'none' || style.visibility === 'hidden' || rect.width <= 0 || rect.height <= 0) {
        return null;
      }

      const text = (element.value || element.placeholder || element.getAttribute('aria-label') || element.innerText || '').trim();

      if (text === '') {
        return null;
      }

      const foreground = parseRgb(style.color);

      if (!foreground) {
        return null;
      }

      const background = effectiveBackground(element);
      const ratio = contrast(foreground, background);

      if (ratio >= MIN_CONTRAST) {
        return null;
      }

      return {
        tag: element.tagName.toLowerCase(),
        className: String(element.className || '').slice(0, 100),
        text: text.slice(0, 80),
        color: style.color,
        background: `rgb(${Math.round(background.r)}, ${Math.round(background.g)}, ${Math.round(background.b)})`,
        contrast: Number(ratio.toFixed(2)),
      };
    })
    .filter(Boolean)
    .slice(0, 20);
})()
JS
)

  CONTRAST_VIOLATIONS="$violations" LABEL="$label" node --input-type=module <<'NODE'
const violations = JSON.parse(process.env.CONTRAST_VIOLATIONS);
const label = process.env.LABEL;

if (violations.length > 0) {
  throw new Error(`${label}: WCAG contrast failures: ${JSON.stringify(violations)}`);
}

console.log(`${label}: ok, text contrast >= 4.5:1`);
NODE
}

agent-browser --session "$SESSION_NAME" open "$BASE_URL/" >/dev/null
agent-browser --session "$SESSION_NAME" wait --load networkidle >/dev/null

current_url=$(agent-browser --session "$SESSION_NAME" get url)
if [ "$current_url" != "$BASE_URL/admin/login" ]; then
  printf 'Admin rendering smoke check failed: expected / to redirect to %s/admin/login, got %s.\n' "$BASE_URL" "$current_url" >&2
  exit 1
fi

agent-browser --session "$SESSION_NAME" open "$BASE_URL/admin/login" >/dev/null
agent-browser --session "$SESSION_NAME" fill 'input[type=email]' 'test@example.com' >/dev/null
agent-browser --session "$SESSION_NAME" fill 'input[type=password]' 'password' >/dev/null
assert_contrast 'Admin login'
agent-browser --session "$SESSION_NAME" click 'button[type=submit]' >/dev/null
agent-browser --session "$SESSION_NAME" wait --load networkidle >/dev/null

current_url=$(agent-browser --session "$SESSION_NAME" get url)
if [ "$current_url" != "$BASE_URL/admin" ]; then
  printf 'Admin rendering smoke check failed: expected login to reach %s/admin, got %s.\n' "$BASE_URL" "$current_url" >&2
  exit 1
fi

measure_page() {
  local path=$1
  local label=$2
  local max_scroll_height=$3

  agent-browser --session "$SESSION_NAME" open "$BASE_URL$path" >/dev/null
  agent-browser --session "$SESSION_NAME" wait --load networkidle >/dev/null
  assert_contrast "$label"

  local metrics
  metrics=$(agent-browser --session "$SESSION_NAME" eval --stdin <<'JS'
(() => {
  const icons = Array.from(document.querySelectorAll('svg.fi-icon, .fi-icon > svg')).map((icon) => {
    const rect = icon.getBoundingClientRect();
    return Math.max(rect.width, rect.height);
  });

  return {
    url: location.href,
    title: document.title,
    scrollHeight: document.documentElement.scrollHeight,
    bodyScrollHeight: document.body.scrollHeight,
    maxIconSize: icons.length ? Math.round(Math.max(...icons)) : 0,
    viewportHeight: window.innerHeight,
  };
})()
JS
)

  METRICS="$metrics" LABEL="$label" EXPECTED_URL="$BASE_URL$path" MAX_SCROLL_HEIGHT="$max_scroll_height" node --input-type=module <<'NODE'
const metrics = JSON.parse(process.env.METRICS);
const label = process.env.LABEL;
const expectedUrl = process.env.EXPECTED_URL;
const maxScrollHeight = Number(process.env.MAX_SCROLL_HEIGHT);

if (metrics.url !== expectedUrl) {
  throw new Error(`${label}: expected ${expectedUrl}, got ${metrics.url}`);
}

if (metrics.maxIconSize > 64) {
  throw new Error(`${label}: largest icon is ${metrics.maxIconSize}px, expected <= 64px`);
}

if (metrics.scrollHeight > maxScrollHeight || metrics.bodyScrollHeight > maxScrollHeight) {
  throw new Error(`${label}: scroll height ${metrics.scrollHeight}/${metrics.bodyScrollHeight}px exceeds ${maxScrollHeight}px`);
}

console.log(`${label}: ok, max icon ${metrics.maxIconSize}px, scroll height ${metrics.scrollHeight}px`);
NODE
}

measure_page '/admin' 'Dashboard' 2500
measure_page '/admin/categories' 'Categories list' 2500

printf 'Admin rendering smoke check passed.\n'
