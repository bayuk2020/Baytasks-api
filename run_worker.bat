@echo off
REM ============================================================================
REM Worker reminder BayTasks -- menjalankan `php artisan baytasks:reminders`
REM setiap ~30 detik.
REM
REM KENAPA ADA KUNCI SATU-INSTANCE DI SINI
REM Pada 17-18 Nov 2026 proses ini menumpuk sampai 44 salinan sekaligus
REM (scheduler 31 salinan). Rantai kejadiannya:
REM   1. MySQL sempat menolak koneksi, `php artisan baytasks:reminders`
REM      menggantung, dan cmd menahan handle ke worker_log.txt selamanya.
REM   2. Karena worker_log.txt terkunci, salinan berikutnya tidak bisa menulis
REM      apa pun -- termasuk tanda "saya hidup".
REM   3. start_stack.ps1 menilai workernya mati (log tidak berubah) lalu
REM      menjalankan salinan baru SETIAP 5 MENIT. Salinan baru itu juga
REM      langsung macet di berkas yang sama. Begitu seterusnya.
REM
REM Dua hal diperbaiki di bawah:
REM   - KUNCI: cmd membuka target redirect tanpa izin tulis bersama, jadi
REM     instance kedua gagal membuka worker.lock dan langsung keluar. Berapa
REM     kali pun dipanggil, yang hidup tetap satu.
REM   - DENYUT TERPISAH: tanda hidup ditulis ke worker_heartbeat.txt dengan
REM     handle berumur pendek (`>` bukan `>>`), bukan ke log besar yang bisa
REM     ikut terkunci kalau php menggantung.
REM ============================================================================

REM ============================================================================
REM KEJADIAN 24 NOV 2026 -- KUNCI LOG MEMBUNUH WORKER TANPA SUARA
REM
REM Satu php.exe sisa dari 23 Nov 14:27 menggantung sambil memegang handle
REM worker_run.log. Akibatnya baris `php ... >> worker_run.log` GAGAL DIBUKA,
REM dan di cmd, redirect yang gagal membuat perintahnya TIDAK DIJALANKAN SAMA
REM SEKALI. Jadi selama 22 jam: loop tetap berputar, denyut tetap ditulis,
REM watchdog tetap melaporkan "OK", tapi tidak satu pun reminder dikirim.
REM
REM Dua pengaman dipasang di bawah:
REM   1. LOG PER HARI. Nama berkasnya berganti tiap tanggal, jadi handle yang
REM      nyangkut paling lama hanya merusak sisa hari itu, bukan selamanya.
REM   2. DENYUT HANYA KALAU PHP BENAR-BENAR JALAN. Dulu denyut ditulis tanpa
REM      syarat, sehingga kegagalan di atas tetap terlihat sehat. Sekarang
REM      denyut dilewati kalau php tidak bisa dijalankan -- watchdog jadi
REM      punya kesempatan melaporkan macet.
REM ============================================================================

cd /d C:\laragon\www\Baytasks-api

set "PHP_BIN=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
set "LOCK=C:\laragon\www\Baytasks-api\worker.lock"

REM Tanggal diambil lewat wmic supaya tidak bergantung pada format %DATE%
REM yang berbeda-beda menurut bahasa Windows.
for /f "skip=1 tokens=1 delims=." %%t in ('wmic os get LocalDateTime') do if not defined HARI set "HARI=%%t"
set "HARI=%HARI:~0,8%"
set "WORKER_LOG=C:\laragon\www\Baytasks-api\worker_run_%HARI%.log"

2>nul (
  >>"%LOCK%" (
    call :jalan
  )
) || (
  echo [%date% %time%] Instance worker lain masih jalan -- keluar. >> worker_run.log
  exit /b 1
)
exit /b 0

:jalan
:loop

"%PHP_BIN%" artisan baytasks:reminders >> "%WORKER_LOG%" 2>&1

REM Denyut ditulis SESUDAH php selesai, bukan sebelum. Jadi kalau php
REM menggantung, berkas ini ikut basi dan watchdog bisa melaporkan "macet" --
REM bukan mengira workernya hilang lalu menjalankan salinan baru.
REM
REM Dan HANYA kalau php benar-benar bisa dijalankan. Kalau redirect di atas
REM gagal (berkas log dipegang proses lain), cmd mengembalikan errorlevel 1
REM TANPA menjalankan php -- dulu denyut tetap ditulis di kasus itu, dan
REM worker yang lumpuh terlihat sehat selama 22 jam.
if not errorlevel 1 echo %date% %time%>worker_heartbeat.txt

REM `timeout` butuh console dan gagal saat dijalankan Task Scheduler
REM (loop jadi berputar tanpa jeda). `ping` bekerja di kedua kondisi.
ping -n 31 127.0.0.1 >nul

goto loop
