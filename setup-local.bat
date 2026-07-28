@echo off
REM DramaVerse ID - setup lokal (Windows)

echo === 1/6 Menyalin .env ===
if not exist .env copy .env.example .env

echo === 2/6 Dependensi PHP ===
call composer install
if errorlevel 1 goto :error

echo === 3/6 Membuat APP_KEY ===
call php artisan key:generate

echo === 4/6 Dependensi JS ===
call npm install
if errorlevel 1 goto :error

echo === 5/6 Migration + data contoh ===
echo Pastikan database 'dramaverse' sudah dibuat dan .env sudah diisi.
pause
call php artisan migrate:fresh --seed
if errorlevel 1 goto :error

call php artisan storage:link

echo === 6/6 Build aset ===
call npm run build
if errorlevel 1 goto :error

echo.
echo === SELESAI ===
echo Jalankan: php artisan serve
echo Buka    : http://localhost:8000
echo Admin   : http://localhost:8000/admin/login
goto :eof

:error
echo.
echo GAGAL pada langkah di atas. Baca pesan errornya.
exit /b 1
