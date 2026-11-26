#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")"
exec python3 quanttrend_bridge.py --port 8765
