@echo off
setlocal enabledelayedexpansion
title TraffMont - Local Server Launcher

echo =======================================================
echo          TraffMont - Local Development Server         
echo =======================================================
echo.

:: 1. Check if php is in PATH
where php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    set PHP_CMD=php
    goto :START_SERVER
)

:: 2. Check XAMPP PHP
if exist "C:\xampp\php\php.exe" (
    set PHP_CMD="C:\xampp\php\php.exe"
    goto :START_SERVER
)

:: 3. Check Laragon PHP
for /d %%D in (C:\laragon\bin\php\*) do (
    if exist "%%D\php.exe" (
        set PHP_CMD="%%D\php.exe"
        goto :START_SERVER
    )
)

echo [ERROR] PHP tidak ditemukan di sistem Anda!
echo Pastikan PHP sudah terinstall atau XAMPP/Laragon sudah terpasang.
echo.
pause
exit /b 1

:START_SERVER
echo [OK] Menggunakan PHP: %PHP_CMD%
echo.
echo Server web lokal berjalan di: http://localhost:8000
echo Atau via jaringan WiFi: http://%COMPUTERNAME%:8000
echo Tekan CTRL+C di jendela ini untuk menghentikan server.
echo.

:: Open default browser after 1 second in background
start "" cmd /c "timeout /t 1 /nobreak >nul && start http://localhost:8000"

:: Start PHP built-in server (0.0.0.0 agar bisa diakses HP di WiFi yang sama)
%PHP_CMD% -S 0.0.0.0:8000
pause

