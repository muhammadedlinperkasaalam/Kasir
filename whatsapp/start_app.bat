@echo off
title WA Gateway Launcher
color 0A

echo ===================================================
echo   MEMULAI WHATSAPP GATEWAY (PHP + NODE.JS)
echo ===================================================
echo.

:: 1. Cek Apakah Folder Ada
if not exist "wa-engine" (
    echo [ERROR] Folder 'wa-engine' tidak ditemukan!
    pause
    exit
)
if not exist "wa-gateway" (
    echo [ERROR] Folder 'wa-gateway' tidak ditemukan!
    pause
    exit
)

echo [1/4] Pastikan XAMPP MySQL sudah RUNNING...
echo (Jika belum, silahkan buka XAMPP Control Panel dan Start MySQL)
echo.

:: 2. Jalankan Node.js (Minimize window biar rapi)
echo [2/4] Menyalakan Node.js Engine (Port 3000)...
start "WA ENGINE (JANGAN DITUTUP)" /min cmd /k "cd wa-engine && node server.js"

:: 3. Jalankan PHP Native Server (Minimize window biar rapi)
echo [3/4] Menyalakan PHP Gateway (Port 8000)...
start "PHP GATEWAY (JANGAN DITUTUP)" /min cmd /k "cd wa-gateway && php -S localhost:8000 -t public"

:: 4. Buka Dashboard di Browser
echo [4/4] Membuka Dashboard...
timeout /t 3 >nul
start http://localhost:8000/dashboard.php
start http://192.168.100.120/addintaprinting/

echo.
echo ===================================================
echo   SUKSES! APLIKASI BERJALAN.
echo   Jangan tutup jendela cmd yang muncul di taskbar.
echo ===================================================
echo.
pause
