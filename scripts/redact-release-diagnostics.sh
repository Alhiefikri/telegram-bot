#!/usr/bin/env bash
set -euo pipefail

# Drop payload/body lines entirely, then redact common credential forms from the
# bounded operational log. The release health JSON is produced separately.
sed -E \
  -e '/(payload|request[_ -]?body|response[_ -]?body)/Id' \
  -e 's/(Authorization[[:space:]]*:[[:space:]]*)(Bearer[[:space:]]+)?[^[:space:],;]+/\1[REDACTED]/Ig' \
  -e 's/("?(token|secret|password|app[_-]?key|api[_-]?key|cookie|set-cookie)"?[[:space:]]*[=:][[:space:]]*)"[^"]*"/\1"[REDACTED]"/Ig' \
  -e 's/("?(token|secret|password|app[_-]?key|api[_-]?key|cookie|set-cookie)"?[[:space:]]*[=:][[:space:]]*)[^"[:space:],;]+/\1[REDACTED]/Ig' \
  -e 's/(Bearer[[:space:]]+)[[:alnum:]_.~+\/-]+/\1[REDACTED]/Ig' \
  -e 's/bot[0-9]{6,}:[[:alnum:]_-]+/bot[REDACTED]/g' \
  -e 's#([[:alpha:]][[:alnum:]+.-]*://)[^/@[:space:]]*:[^/@[:space:]]+@#\1[REDACTED]@#Ig' \
  -e 's/([?&](token|secret|password|key|api_key)=)[^&#[:space:]]+/\1[REDACTED]/Ig'
