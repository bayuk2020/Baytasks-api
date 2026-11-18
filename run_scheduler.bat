@echo off
REM ============================================================================
REM Scheduler Laravel -- menjalankan `php artisan schedule:run` setiap ~60 detik.
REM
REM Pola kunci + denyut terpisah sama persis dengan run_worker.bat; alasan
REM lengkapnya ditulis di sana. Singkatnya: tanpa kunci ini, satu php yang
REM menggantung membuat log terkunci, watchdog mengira prosesnya hilang, lalu
REM menjalankan salinan baru tiap 5 menit sampai menumpuk puluhan.
REM ============================================================================

cd /d C:\laragon\www\Baytasks-api

set "PHP_BIN=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
set "LOCK=C:\laragon\www\Baytasks-api\scheduler.lock"

2>nul (
  >>"%LOCK%" (
    call :jalan
  )
) || (
  echo [%date% %time%] Instance scheduler lain masih jalan -- keluar. >> scheduler_run.log
  exit /b 1
)
exit /b 0

:jalan
:loop

"%PHP_BIN%" artisan schedule:run >> scheduler_run.log 2>&1

echo %date% %time%>scheduler_heartbeat.txt

ping -n 61 127.0.0.1 >nul

goto loop
