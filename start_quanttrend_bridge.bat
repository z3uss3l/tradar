@echo off
setlocal
cd /d "%~dp0"
where py >nul 2>nul
if not errorlevel 1 (
  py -3 quanttrend_bridge.py --port 8765
) else (
  python quanttrend_bridge.py --port 8765
)
if errorlevel 1 (
  echo.
  echo QuantTrend Bridge konnte nicht gestartet werden.
  echo Python 3.10 oder neuer muss installiert sein.
  pause
)
endlocal
