#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(dirname -- "$SCRIPT_DIR")
APP_DIR=${APP_DIR:-"$ROOT_DIR/src"}
MANIFEST_PATH="$APP_DIR/public/build/manifest.json"
ADMIN_THEME_ENTRY='resources/css/filament/admin/theme.css'

printf 'Building Laravel admin assets with npm run build...\n'
npm --prefix "$APP_DIR" run build

if [ ! -s "$MANIFEST_PATH" ]; then
  printf 'Admin asset smoke check failed: /admin/login would be served without the Vite manifest at %s. Run npm run build in %s before deploy.\n' "$MANIFEST_PATH" "$APP_DIR" >&2
  exit 1
fi

if ! MANIFEST_PATH="$MANIFEST_PATH" ADMIN_THEME_ENTRY="$ADMIN_THEME_ENTRY" node --input-type=module <<'NODE'
import { readFileSync } from 'node:fs';

const manifestPath = process.env.MANIFEST_PATH;
const adminThemeEntry = process.env.ADMIN_THEME_ENTRY;
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

if (!Object.hasOwn(manifest, adminThemeEntry)) {
  process.exit(1);
}
NODE
then
  printf 'Admin asset smoke check failed: Vite manifest at %s does not include %s required by /admin/login. Check vite.config.js inputs before deploy.\n' "$MANIFEST_PATH" "$ADMIN_THEME_ENTRY" >&2
  exit 1
fi

printf 'Admin asset smoke check passed: %s includes %s for /admin/login.\n' "$MANIFEST_PATH" "$ADMIN_THEME_ENTRY"
