#!/usr/bin/env python3
"""QuantTrend local market-data bridge.

Binds exclusively to 127.0.0.1. Uses only Python's standard library.
Supported providers: Yahoo Finance chart API and Stooq daily CSV.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import math
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

HOST = "127.0.0.1"
DEFAULT_PORT = 8765
USER_AGENT = "QuantTrendLocalBridge/12.0.2 (+localhost; read-only)"
MAX_BODY = 16 * 1024 * 1024
ASSET_SYMBOLS = {
    "SPY": {"yahoo": "SPY", "stooq": "spy.us"},
    "QQQ": {"yahoo": "QQQ", "stooq": "qqq.us"},
    "IWM": {"yahoo": "IWM", "stooq": "iwm.us"},
    "DIA": {"yahoo": "DIA", "stooq": "dia.us"},
    "VTI": {"yahoo": "VTI", "stooq": "vti.us"},
    "VOO": {"yahoo": "VOO", "stooq": "voo.us"},
    "ARKK": {"yahoo": "ARKK", "stooq": "arkk.us"},
    "XLF": {"yahoo": "XLF", "stooq": "xlf.us"},
    "XLK": {"yahoo": "XLK", "stooq": "xlk.us"},
    "XLE": {"yahoo": "XLE", "stooq": "xle.us"},
    "GLD": {"yahoo": "GLD", "stooq": "gld.us"},
    "TLT": {"yahoo": "TLT", "stooq": "tlt.us"},
    "VWCE.DE": {"yahoo": "VWCE.DE", "stooq": "vwce.de"},
    "EUNL.DE": {"yahoo": "EUNL.DE", "stooq": "eunl.de"},
    "IQQE.DE": {"yahoo": "IQQE.DE", "stooq": "iqqe.de"},
    "XMAW.DE": {"yahoo": "XMAW.DE", "stooq": "xmaw.de"},
    "NVDA": {"yahoo": "NVDA", "stooq": "nvda.us"},
    "AAPL": {"yahoo": "AAPL", "stooq": "aapl.us"},
    "TSLA": {"yahoo": "TSLA", "stooq": "tsla.us"},
    "MSFT": {"yahoo": "MSFT", "stooq": "msft.us"},
    "AMZN": {"yahoo": "AMZN", "stooq": "amzn.us"},
    "BTC": {"yahoo": "BTC-USD", "stooq": "btcusd"},
    "GOLD": {"yahoo": "GC=F", "stooq": "gc.f"},
    "OIL": {"yahoo": "BZ=F", "stooq": "bz.f"},
}
SYMBOL_RE = re.compile(r"^[A-Za-z0-9.^=_-]{1,32}$")


class BridgeError(Exception):
    pass


def fetch_url(url: str) -> bytes:
    req = urllib.request.Request(
        url,
        headers={"User-Agent": USER_AGENT, "Accept": "application/json,text/csv,*/*"},
        method="GET",
    )
    try:
        with urllib.request.urlopen(req, timeout=25) as response:
            data = response.read(MAX_BODY + 1)
            if len(data) > MAX_BODY:
                raise BridgeError("Provider-Antwort überschreitet 16 MiB.")
            if response.status != 200:
                raise BridgeError(f"Provider HTTP {response.status}.")
            return data
    except urllib.error.HTTPError as exc:
        raise BridgeError(f"Provider HTTP {exc.code}: {exc.reason}") from exc
    except (urllib.error.URLError, TimeoutError) as exc:
        raise BridgeError(f"Provider nicht erreichbar: {exc}") from exc


def finite_number(value: Any, field: str) -> float:
    try:
        number = float(value)
    except (TypeError, ValueError) as exc:
        raise BridgeError(f"Ungültiger Zahlenwert in {field}.") from exc
    if not math.isfinite(number) or number <= 0:
        raise BridgeError(f"Nicht positiver oder nicht endlicher Wert in {field}.")
    return number


def validate_bars(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    today = datetime.now(timezone.utc).date()
    normalized: dict[str, dict[str, Any]] = {}
    for row in rows:
        try:
            d = date.fromisoformat(str(row["date"]))
        except (KeyError, TypeError, ValueError) as exc:
            raise BridgeError("Ungültiges Tagesdatum in Provider-Daten.") from exc
        if d >= today:
            continue
        bar = {"date": d.isoformat()}
        for key in ("open", "high", "low", "close"):
            bar[key] = finite_number(row.get(key), key)
        volume = row.get("volume")
        try:
            volume = float(volume) if volume not in (None, "") else None
        except (TypeError, ValueError):
            volume = None
        bar["volume"] = volume if volume is not None and math.isfinite(volume) and volume >= 0 else None
        if bar["high"] < max(bar["open"], bar["close"], bar["low"]):
            raise BridgeError(f"OHLC-Inkonsistenz am {bar['date']}: High zu niedrig.")
        if bar["low"] > min(bar["open"], bar["close"], bar["high"]):
            raise BridgeError(f"OHLC-Inkonsistenz am {bar['date']}: Low zu hoch.")
        normalized[bar["date"]] = bar
    result = [normalized[key] for key in sorted(normalized)]
    if len(result) < 2:
        raise BridgeError("Provider lieferte weniger als zwei abgeschlossene Tagesbars.")
    return result


def yahoo_bars(symbol: str, adjusted: bool) -> tuple[list[dict[str, Any]], str]:
    encoded = urllib.parse.quote(symbol, safe="")
    url = f"https://query1.finance.yahoo.com/v8/finance/chart/{encoded}?range=5y&interval=1d&events=div%2Csplits"
    payload = json.loads(fetch_url(url).decode("utf-8"))
    chart = payload.get("chart") or {}
    if chart.get("error"):
        raise BridgeError(str(chart["error"].get("description") or "Yahoo meldet einen Fehler."))
    result = (chart.get("result") or [None])[0]
    if not result:
        raise BridgeError("Yahoo lieferte keine Zeitreihe.")
    timestamps = result.get("timestamp") or []
    quote = ((result.get("indicators") or {}).get("quote") or [{}])[0]
    adj = ((result.get("indicators") or {}).get("adjclose") or [{}])[0].get("adjclose") or []
    fields = {key: quote.get(key) or [] for key in ("open", "high", "low", "close", "volume")}
    rows = []
    for index, stamp in enumerate(timestamps):
        try:
            dt = datetime.fromtimestamp(int(stamp), timezone.utc).date().isoformat()
            values = {key: fields[key][index] if index < len(fields[key]) else None for key in fields}
            if any(values[k] is None for k in ("open", "high", "low", "close")):
                continue
            if adjusted:
                adj_close = adj[index] if index < len(adj) else None
                raw_close = values["close"]
                if adj_close is None or raw_close in (None, 0):
                    continue
                factor = float(adj_close) / float(raw_close)
                if not math.isfinite(factor) or factor <= 0:
                    continue
                for key in ("open", "high", "low", "close"):
                    values[key] = float(values[key]) * factor
            rows.append({"date": dt, **values})
        except (ValueError, TypeError, OverflowError):
            continue
    return validate_bars(rows), "adjusted" if adjusted else "unknown"


def stooq_bars(symbol: str) -> tuple[list[dict[str, Any]], str]:
    encoded = urllib.parse.quote(symbol.lower(), safe=".")
    url = f"https://stooq.com/q/d/l/?s={encoded}&i=d"
    text = fetch_url(url).decode("utf-8-sig", errors="replace")
    reader = csv.DictReader(io.StringIO(text))
    if not reader.fieldnames or not {"Date", "Open", "High", "Low", "Close"}.issubset(reader.fieldnames):
        raise BridgeError("Stooq lieferte keine erwartete CSV-Kopfzeile.")
    rows = []
    for row in reader:
        rows.append({
            "date": row.get("Date"),
            "open": row.get("Open"),
            "high": row.get("High"),
            "low": row.get("Low"),
            "close": row.get("Close"),
            "volume": row.get("Volume"),
        })
    return validate_bars(rows), "unknown"


def data_flags(bars: list[dict[str, Any]]) -> list[dict[str, Any]]:
    flags: list[dict[str, Any]] = []
    for previous, current in zip(bars, bars[1:]):
        ratio = current["close"] / previous["close"]
        if ratio >= 1.8 or ratio <= 0.55:
            flags.append({"date": current["date"], "type": "large_close_change_review", "ratio": round(ratio, 6)})
        try:
            gap = (date.fromisoformat(current["date"]) - date.fromisoformat(previous["date"])).days
        except ValueError:
            gap = 0
        if gap > 7:
            flags.append({"date": current["date"], "type": "calendar_gap_review", "days": gap})
    return flags


class Handler(BaseHTTPRequestHandler):
    server_version = "QuantTrendBridge/12.0.2"

    def log_message(self, fmt: str, *args: Any) -> None:
        sys.stderr.write("[QuantTrendBridge] " + (fmt % args) + "\n")

    def cors(self) -> None:
        origin = self.headers.get("Origin", "")
        if origin in ("null", "http://localhost", "http://127.0.0.1", "https://trade.erdgebunden.de") or origin.startswith("http://localhost:") or origin.startswith("http://127.0.0.1:") or origin.startswith("https://localhost:"):
            self.send_header("Access-Control-Allow-Origin", origin or "*")
            self.send_header("Vary", "Origin")
        self.send_header("Access-Control-Allow-Methods", "GET, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Allow-Private-Network", "true")
        self.send_header("Cache-Control", "no-store")

    def send_json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.cors()
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self) -> None:
        self.send_response(204)
        self.cors()
        self.end_headers()

    def do_GET(self) -> None:
        parsed = urllib.parse.urlsplit(self.path)
        if parsed.path == "/health":
            self.send_json(200, {"ok": True, "service": "QuantTrend Local Bridge", "version": "12.0.2", "bind": HOST})
            return
        if parsed.path != "/bars":
            self.send_json(404, {"error": "Unbekannter Endpunkt. Verfügbar: /health und /bars."})
            return
        query = urllib.parse.parse_qs(parsed.query, strict_parsing=False)
        asset = (query.get("asset") or [""])[0].upper()
        provider = (query.get("provider") or [""])[0].lower()
        override = (query.get("symbol") or [""])[0].strip()
        adjusted = (query.get("adjusted") or ["0"])[0] == "1"
        if asset not in ASSET_SYMBOLS:
            self.send_json(400, {"error": "Asset nicht freigegeben."})
            return
        if provider not in ("yahoo", "stooq"):
            self.send_json(400, {"error": "Provider muss yahoo oder stooq sein."})
            return
        symbol = override or ASSET_SYMBOLS[asset][provider]
        if not SYMBOL_RE.fullmatch(symbol):
            self.send_json(400, {"error": "Ungültiges Provider-Symbol."})
            return
        if adjusted and provider != "yahoo":
            self.send_json(400, {"error": "Adjusted OHLC ist nur für Yahoo verfügbar."})
            return
        try:
            bars, basis = yahoo_bars(symbol, adjusted) if provider == "yahoo" else stooq_bars(symbol)
            canonical = json.dumps(bars, sort_keys=True, separators=(",", ":"), allow_nan=False).encode("utf-8")
            self.send_json(200, {
                "asset": asset,
                "source": f"{provider.title()} {symbol}",
                "basis": basis,
                "bars": bars,
                "flags": data_flags(bars),
                "sha256": hashlib.sha256(canonical).hexdigest(),
                "retrieved_at": datetime.now(timezone.utc).isoformat(),
            })
        except (BridgeError, json.JSONDecodeError, UnicodeDecodeError) as exc:
            self.send_json(502, {"error": str(exc)})


def main() -> int:
    parser = argparse.ArgumentParser(description="QuantTrend loopback-only market data bridge")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    args = parser.parse_args()
    if not 1024 <= args.port <= 65535:
        parser.error("Port muss zwischen 1024 und 65535 liegen.")
    server = ThreadingHTTPServer((HOST, args.port), Handler)
    server.daemon_threads = True
    print(f"QuantTrend Local Bridge 12.0.2 läuft ausschließlich auf http://{HOST}:{args.port}")
    print("Read-only: Yahoo Finance / Stooq. Beenden mit Ctrl+C.")
    try:
        server.serve_forever(poll_interval=0.5)
    except KeyboardInterrupt:
        print("\nBridge beendet.")
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
