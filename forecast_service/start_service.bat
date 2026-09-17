@echo off
REM forecast_service/start_service.bat
REM One-click launcher for the Demand Forecasting AI service (Prophet).
REM Run this before opening the "Demand forecast" page in the Owner panel
REM so predictions are AI-powered instead of falling back to the
REM statistical estimate. Leave this window open while you use the app --
REM closing it stops the service.

cd /d "%~dp0"

where python >nul 2>nul
if errorlevel 1 (
    echo Python was not found on PATH. Install Python 3.10+ from https://www.python.org/downloads/
    echo and re-run this script.
    pause
    exit /b 1
)

if not exist venv (
    echo Setting up the AI forecast service for the first time - this can take a few minutes...
    python -m venv venv
    call venv\Scripts\activate.bat
    pip install --upgrade pip
    pip install -r requirements.txt
) else (
    call venv\Scripts\activate.bat
)

REM Prophet ships its own precompiled Windows model binary (prophet_model.bin)
REM built against CmdStan 2.33.1, together with the TBB runtime DLL it needs
REM (venv\Lib\site-packages\prophet\stan_model\cmdstan-2.33.1\...\tbb\tbb.dll).
REM That folder isn't on PATH by default, so the model binary fails to even
REM load its threading library the moment a real forecast is requested
REM (confirmed directly: prophet_model.bin exits with "tbb.dll: cannot open
REM shared object file" without this). Must be set here, not just assumed
REM present on the machine.
set "PATH=%~dp0venv\Lib\site-packages\prophet\stan_model\cmdstan-2.33.1\stan\lib\stan_math\lib\tbb;%PATH%"

echo.
echo Starting Demand Forecast AI service on http://127.0.0.1:5000 ...
echo Keep this window open. Close it to stop the service.
echo.
uvicorn app:app --host 127.0.0.1 --port 5000
