@echo off
REM Dipanggil oleh Scheduled Task "Baytasks Stack" (saat logon + tiap 5 menit).
REM Semua keputusan ada di start_stack.ps1; file ini hanya mengarahkan outputnya
REM ke watchdog_log.txt supaya ada jejak kapan komponen pernah dihidupkan ulang.
cd /d C:\laragon\www\Baytasks-api
powershell -NoProfile -ExecutionPolicy Bypass -File C:\laragon\www\Baytasks-api\start_stack.ps1 >> watchdog_log.txt 2>&1
