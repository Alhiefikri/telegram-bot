#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
redactor="$ROOT_DIR/scripts/redact-release-diagnostics.sh"
secret='super-secret-value'

output="$(cat <<EOF | "$redactor"
token=$secret password: $secret
{"api_key":"$secret","message":"safe"}
Authorization: Bearer abc.def-123
GET /callback?token=$secret&ok=1
bot123456789:telegramToken
https://db-user:db-password@example.test/path
REDIS_URL=redis://:redis-password@redis:6379/0
mysql://mysql-user:mysql-password@database/app
payload={"customer":"private"}
safe operational line
EOF
)"

! grep -q "$secret" <<< "$output"
! grep -q 'abc.def-123' <<< "$output"
! grep -q 'telegramToken' <<< "$output"
! grep -q 'db-password' <<< "$output"
! grep -q 'redis-password' <<< "$output"
! grep -q 'mysql-password' <<< "$output"
! grep -qi 'customer' <<< "$output"
grep -q 'safe operational line' <<< "$output"
grep -q '\[REDACTED\]' <<< "$output"

echo 'release diagnostic redaction tests passed.'
