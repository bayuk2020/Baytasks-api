@echo off
cd /d C:\laragon\www\Baytasks-api
:loop
echo [%date% %time%] Menjalankan worker... >> worker_log.txt
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan baytasks:reminders >> worker_log.txt 2>&1
timeout /t 30 /nobreak >nul
goto loop