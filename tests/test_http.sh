#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8080}"
health_json=$(curl -sS "${BASE_URL}/events.php?action=health")
echo "${health_json}" | grep -q '"quanttrend-events/12.0.7"'

code=$(curl -sS -o /dev/null -w "%{http_code}" --get --data-urlencode "url=http://169.254.169.254/latest/meta-data/" "${BASE_URL}/relay.php")
if [[ "${code}" != "400" && "${code}" != "403" ]]; then
  echo "SSRF gate failed with HTTP ${code}" >&2
  exit 1
fi
echo "HTTP INTEGRATION & SSRF GATE PASSED"
