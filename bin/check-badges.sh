#!/usr/bin/env bash
# Fails if any README badge image resolves to shields "no status" (e.g. wrong branch).
set -euo pipefail
cd "$(dirname "$0")/.."
fail=0
while IFS= read -r url; do
  if ! body=$(curl -fsSL --max-time 20 "$url" 2>&1); then
    echo "FAIL fetch: $url"; fail=1; continue
  fi
  if printf '%s' "$body" | grep -q 'no status'; then
    echo "FAIL no status: $url"; fail=1
  else
    echo "ok: $url"
  fi
done < <(grep -oE '!\[[^]]*\]\(https://[^)]+\)' README.md | sed -E 's/^.*\((https:[^)]+)\)$/\1/' | sort -u)
exit "$fail"
