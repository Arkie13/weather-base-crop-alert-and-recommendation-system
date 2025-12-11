@echo off
REM Windows Task Scheduler Setup Script for Bantay Presyo Scraper
REM Run this script as Administrator to set up automatic daily price scraping

echo ========================================
echo Bantay Presyo Scraper Setup
echo ========================================
echo.

REM Get the current directory
set SCRIPT_DIR=%~dp0
set PROJECT_DIR=%SCRIPT_DIR%..
set PHP_PATH=C:\xampp\php\php.exe
set SCRAPER_SCRIPT=%SCRIPT_DIR%bantay-presyo-scraper.php

echo Project Directory: %PROJECT_DIR%
echo PHP Path: %PHP_PATH%
echo Scraper Script: %SCRAPER_SCRIPT%
echo.

REM Check if PHP exists
if not exist "%PHP_PATH%" (
    echo ERROR: PHP not found at %PHP_PATH%
    echo Please update PHP_PATH in this script to point to your PHP executable.
    pause
    exit /b 1
)

REM Check if scraper script exists
if not exist "%SCRAPER_SCRIPT%" (
    echo ERROR: Scraper script not found at %SCRAPER_SCRIPT%
    pause
    exit /b 1
)

echo Creating Windows Scheduled Task...
echo.

REM Create scheduled task using schtasks command
schtasks /Create /TN "BantayPresyoScraper" /TR "\"%PHP_PATH%\" \"%SCRAPER_SCRIPT%\"" /SC DAILY /ST 06:00 /RU SYSTEM /F

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================
    echo SUCCESS!
    echo ========================================
    echo.
    echo Scheduled task created successfully!
    echo Task Name: BantayPresyoScraper
    echo Schedule: Daily at 6:00 AM
    echo.
    echo To verify, open Task Scheduler and look for "BantayPresyoScraper"
    echo.
    echo To test manually, run:
    echo   %PHP_PATH% %SCRAPER_SCRIPT%
    echo.
) else (
    echo.
    echo ========================================
    echo ERROR!
    echo ========================================
    echo.
    echo Failed to create scheduled task.
    echo You may need to run this script as Administrator.
    echo.
    echo Manual Setup Instructions:
    echo 1. Open Task Scheduler (Win+R, type: taskschd.msc)
    echo 2. Create Basic Task
    echo 3. Name: Bantay Presyo Scraper
    echo 4. Trigger: Daily at 6:00 AM
    echo 5. Action: Start a program
    echo 6. Program: %PHP_PATH%
    echo 7. Arguments: %SCRAPER_SCRIPT%
    echo 8. Start in: %PROJECT_DIR%
    echo.
)

pause
