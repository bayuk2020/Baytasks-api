@echo off
cd /d C:\laragon\www\Baytasks-api
:loop
echo [%date% %time%] Menjalankan scheduler... >> scheduler_log.txt
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan schedule:run >> scheduler_log.txt 2>&1
REM `timeout` butuh console dan gagal saat dijalankan Task Scheduler
REM (loop jadi berputar tanpa jeda). `ping` bekerja di kedua kondisi.
ping -n 61 127.0.0.1 >nul
goto loop
