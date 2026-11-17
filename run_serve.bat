@echo off
REM ============================================================================
REM Laravel API untuk api.kabyra.my.id (origin cloudflared: 127.0.0.1:8000)
REM Path PHP ditulis penuh: Task Scheduler menjalankan ini tanpa PATH milik user.
REM ============================================================================
REM
REM KENAPA ADA LOOP DI SINI
REM `artisan serve` adalah server development dan mudah mati:
REM   1. Ia MEMANTAU file .env. Begitu .env berubah ia mencetak
REM      "Environment modified. Restarting server..." lalu mencoba membunuh
REM      proses anaknya dengan exec('taskkill ...'). Kalau Windows sedang gagal
REM      membuat proses baru, exec() itu melempar "Unable to fork" dan
REM      `artisan serve` MATI TOTAL — port 8000 kosong, API 502, dan tidak ada
REM      yang menghidupkannya kembali. Flag --no-reload mematikan pemantau ini.
REM   2. Fatal error apa pun di PHP juga menjatuhkan prosesnya.
REM Loop di bawah membuatnya hidup lagi dalam 5 detik, bukan menunggu dihidupkan
REM manual. Ini jaring pengaman, bukan pengganti server produksi.
REM
REM KENAPA MASIH `artisan serve` DAN BUKAN APACHE
REM Apache + mod_fcgid (PHP 8.3.30) sudah dipasang dan DIUKUR pada 2026-11-10,
REM lalu dibatalkan karena 7x LEBIH LAMBAT di mesin ini:
REM   4 request serentak -> artisan serve 26,8 req/detik (p50 134 ms)
REM                         Apache+fcgid   3,9 req/detik (p50 1083 ms)
REM Sebabnya mod_fcgid membuat proses php-cgi baru setiap ada request serentak,
REM sementara membuat proses di mesin ini mahal (~1 detik, ~18% gagal). Catatan
REM lengkap + config yang sudah jadi ada di
REM C:\laragon\etc\apache2\fcgid-baytasks.conf.disabled.
REM
REM Satu proses sudah cukup karena frontend dipangkas dari 30 request per buka
REM halaman Finance menjadi 8 (lihat src/lib/finance/store.ts).
REM
REM Yang menjaga proses ini tetap hidup ada dua lapis:
REM   1. loop di file ini (restart dalam 5 detik kalau prosesnya mati)
REM   2. Scheduled Task "Baytasks Stack" -> start_stack.ps1 (saat logon + tiap
REM      5 menit), yang menghidupkan ulang kalau bahkan loop ini ikut mati.

cd /d C:\laragon\www\Baytasks-api

set PHP_BIN=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe

:loop

REM `artisan serve` menjalankan php -S sebagai proses anak yang tidak ikut mati
REM saat task dihentikan, dan anak itu tetap menahan port 8000. Bebaskan dulu.
for /f "tokens=5" %%P in ('netstat -ano -p tcp ^| findstr /c:"LISTENING" ^| findstr /c:":8000 "') do taskkill /f /pid %%P >nul 2>&1

echo. >> serve_log.txt
echo [%date% %time%] Menjalankan artisan serve... >> serve_log.txt
"%PHP_BIN%" artisan serve --no-reload --host=127.0.0.1 --port=8000 >> serve_log.txt 2>&1

echo [%date% %time%] artisan serve BERHENTI (exit=%errorlevel%). Restart dalam 5 detik... >> serve_log.txt

REM `timeout` butuh console dan gagal saat dijalankan Task Scheduler
REM (loop jadi berputar tanpa jeda). `ping` bekerja di kedua kondisi.
ping -n 6 127.0.0.1 >nul

goto loop
