#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(dirname -- "$SCRIPT_DIR")
APP_DIR=${APP_DIR:-"$ROOT_DIR/src"}
MANIFEST_PATH="$APP_DIR/public/build/manifest.json"
APP_STYLE_ENTRY='resources/css/app.css'
APP_SCRIPT_ENTRY='resources/js/app.js'
TAILWIND_CONFIG_PATH="$APP_DIR/tailwind.config.cjs"

printf 'Building Laravel admin assets with npm run build...\n'
npm --prefix "$APP_DIR" run build

TAILWIND_CONFIG_PATH="$TAILWIND_CONFIG_PATH" node --input-type=module <<'NODE'
import { readFileSync } from 'node:fs';

const configPath = process.env.TAILWIND_CONFIG_PATH;
const config = readFileSync(configPath, 'utf8');
const requiredPaths = [
  './resources/views/**/*.blade.php',
  './resources/js/**/*.js',
];

const missingPaths = requiredPaths.filter((path) => !config.includes(path));

if (missingPaths.length > 0) {
  throw new Error(`Tailwind config is missing manual dashboard content paths: ${missingPaths.join(', ')}`);
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
