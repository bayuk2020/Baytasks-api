@echo off
REM ---------------------------------------------------------------------------
REM Cloudflare Tunnel untuk kabyra.my.id / api.kabyra.my.id / db.kabyra.my.id.
REM Ingress dibaca dari C:\Users\bayuk\.cloudflared\config.yml (tunnel
REM b1fe7c98-9c46-4836-a61d-1017c63d2baa alias "baytasks-tunnel").
REM
REM PENTING — jangan jalankan connector kedua untuk tunnel yang sama. Sempat ada
REM dua proses cloudflared hidup bersamaan (`cloudflared tunnel run` dan
REM `cloudflared tunnel run baytasks-tunnel`): Cloudflare membagi trafik ke
REM keduanya, dan karena ingress hanya dibaca SEKALI saat start, connector yang
REM lebih tua tetap memakai config lama. Akibatnya sebagian request bisa
REM diarahkan ke origin yang salah secara acak.
REM
REM Loop di bawah ini menghidupkan ulang connector kalau ia keluar sendiri,
REM supaya domain tidak ikut mati saat koneksi ke edge terputus total.
REM ---------------------------------------------------------------------------
cd /d C:\laragon\www\Baytasks-api

:loop
echo [%date% %time%] Menjalankan cloudflared tunnel... >> tunnel_log.txt
cloudflared tunnel run >> tunnel_log.txt 2>&1
echo [%date% %time%] cloudflared BERHENTI (exit=%errorlevel%). Restart dalam 5 detik... >> tunnel_log.txt
ping -n 6 127.0.0.1 >nul
goto loop
