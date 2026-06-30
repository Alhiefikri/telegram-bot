#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(dirname -- "$SCRIPT_DIR")
APP_DIR=${APP_DIR:-"$ROOT_DIR/src"}
MANIFEST_PATH="$APP_DIR/public/build/manifest.json"
APP_STYLE_ENTRY='resources/css/app.css'
APP_SCRIPT_ENTRY='resources/js/app.js'
APP_CSS_PATH="$APP_DIR/$APP_STYLE_ENTRY"

printf 'Building Laravel admin assets with npm run build...\n'
npm --prefix "$APP_DIR" run build

APP_CSS_PATH="$APP_CSS_PATH" node --input-type=module <<'NODE'
import { readFileSync } from 'node:fs';

const cssPath = process.env.APP_CSS_PATH;
const css = readFileSync(cssPath, 'utf8');
const requiredSnippets = [
  '@import "tailwindcss"',
  '@source "../views"',
  '@source "../js"',
];

const missingSnippets = requiredSnippets.filter((snippet) => !css.includes(snippet));

if (missingSnippets.length > 0) {
  throw new Error(`Tailwind v4 CSS entry is missing admin dashboard source declarations: ${missingSnippets.join(', ')}`);
}
NODE

if [ ! -s "$MANIFEST_PATH" ]; then
  printf 'Admin asset smoke check failed: /admin would be served without the Vite manifest at %s. Run npm run build in %s before deploy.\n' "$MANIFEST_PATH" "$APP_DIR" >&2
  exit 1
fi

MANIFEST_PATH="$MANIFEST_PATH" APP_STYLE_ENTRY="$APP_STYLE_ENTRY" APP_SCRIPT_ENTRY="$APP_SCRIPT_ENTRY" node --input-type=module <<'NODE'
import { readFileSync } from 'node:fs';

const manifestPath = process.env.MANIFEST_PATH;
const requiredEntries = [
  process.env.APP_STYLE_ENTRY,
  process.env.APP_SCRIPT_ENTRY,
];
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const missingEntries = requiredEntries.filter((entry) => !Object.hasOwn(manifest, entry));

if (missingEntries.length > 0) {
  throw new Error(`Vite manifest is missing manual admin dashboard entries: ${missingEntries.join(', ')}`);
}
NODE

printf 'Admin asset smoke check passed: %s includes manual dashboard assets for /admin.\n' "$MANIFEST_PATH"
