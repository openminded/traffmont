@echo off
setlocal enabledelayedexpansion
title TraffMont - Local Periodic Cron Runner (Every 60 Seconds)

echo =======================================================
echo        TraffMont - Local 1-Minute Cron Simulator       
echo =======================================================
echo.

where php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    set PHP_CMD=php
    goto :RUN_LOOP
)
if exist "C:\xampp\php\php.exe" (
    set PHP_CMD="C:\xampp\php\php.exe"
    goto :RUN_LOOP
)
for /d %%D in (C:\laragon\bin\php\*) do (
    if exist "%%D\php.exe" (
        set PHP_CMD="%%D\php.exe"
        goto :RUN_LOOP
    )
)

echo [ERROR] PHP tidak ditemukan!
pause
exit /b 1

:RUN_LOOP
echo Pengecekan TraffMont berjalan otomatis setiap 60 detik...
echo Biarkan jendela ini tetap terbuka selama pengujian lokal.
echo.

:CYCLE
%PHP_CMD% cron.php
echo Menunggu 60 detik sebelum pengecekan berikutnya...
timeout /t 60 /nobreak >nul
echo.
goto :CYCLE

