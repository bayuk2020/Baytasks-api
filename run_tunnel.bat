@echo off
REM ---------------------------------------------------------------------------
REM Cloudflare Tunnel untuk kabyra.my.id / api.kabyra.my.id / db.kabyra.my.id.
REM
REM JALUR BINARY DITULIS LENGKAP, JANGAN ANDALKAN PATH.
REM Sebelumnya file ini cuma memanggil `cloudflared tunnel run`. Itu berhenti
REM bekerja pada 18 Nov 2026 begitu cloudflared.exe dipindah keluar dari folder
REM PHP (satu-satunya tempat yang kebetulan ada di PATH): batch-nya langsung
REM keluar tanpa pesan, dan tunnel tidak pernah hidup.
REM
REM --config juga ditulis eksplisit, karena cloudflared mencari config di home
REM directory pemanggilnya -- dan itu berbeda-beda tergantung siapa yang
REM menjalankan.
REM
REM CATATAN: kalau cloudflared sudah terpasang sebagai Windows Service, file ini
REM TIDAK perlu dijalankan. Dua connector pada tunnel yang sama membuat
REM Cloudflare membagi trafik ke keduanya.
REM ---------------------------------------------------------------------------
cd /d C:\laragon\www\Baytasks-api

set "CF_BIN=C:\cloudflared\cloudflared.exe"
if not exist "%CF_BIN%" set "CF_BIN=C:\laragon\cloudflared.exe"
if not exist "%CF_BIN%" (
  echo [%date% %time%] cloudflared.exe TIDAK DITEMUKAN di C:\cloudflared\ maupun C:\laragon\ >> tunnel_log.txt
  exit /b 1
)

set "CF_CONFIG=C:\Users\bayuk\.cloudflared\config.yml"

:loop
echo [%date% %time%] Menjalankan %CF_BIN% (config: %CF_CONFIG%) >> tunnel_log.txt
"%CF_BIN%" --config "%CF_CONFIG%" tunnel run >> tunnel_log.txt 2>&1
echo [%date% %time%] cloudflared BERHENTI (exit=%errorlevel%). Restart dalam 5 detik... >> tunnel_log.txt
ping -n 6 127.0.0.1 >nul
goto loop
