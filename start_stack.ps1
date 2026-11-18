# =============================================================================
# start_stack.ps1 — penjaga (watchdog) semua proses yang dibutuhkan
# api.kabyra.my.id, dan sekaligus pemangkas log yang membengkak.
#
# MASALAH YANG DIATASI
# Folder Startup Windows hanya berisi laragon.lnk. Jadi setelah reboot, Apache
# dan MySQL hidup, tapi `artisan serve`, worker reminder, scheduler, dan
# cloudflared TIDAK — api.kabyra.my.id mati sampai dijalankan manual satu per
# satu. Script ini menghidupkan apa pun yang sedang tidak jalan.
#
# CARA PAKAI
#   powershell -ExecutionPolicy Bypass -File C:\laragon\www\Baytasks-api\start_stack.ps1
#   powershell ... -File ...\start_stack.ps1 -Status      (hanya lihat, tidak start)
#
# Dipasang sebagai Scheduled Task "Baytasks Stack": jalan saat logon dan
# diulang tiap 5 menit sebagai jaring pengaman lapis kedua (lapis pertama
# adalah loop di dalam masing-masing run_*.bat).
# =============================================================================

param(
    [switch]$Status,                 # hanya laporkan keadaan, jangan start apa pun
    [int]$MaxLogMB = 20              # batas ukuran log sebelum dipangkas
)

$ErrorActionPreference = 'Continue'
$root = 'C:\laragon\www\Baytasks-api'

# -----------------------------------------------------------------------------
# PELAJARAN 17-18 NOV 2026 — KENAPA DETEKSINYA SEPERTI INI
#
# Versi pertama script ini menilai worker/scheduler "hidup" semata-mata dari
# umur berkas log-nya. Itu gagal total ketika MySQL sempat menolak koneksi:
# `php artisan` menggantung, cmd menahan handle ke worker_log.txt, salinan
# berikutnya tidak bisa menulis apa pun, umur log membeku, dan script ini
# menyimpulkan workernya mati lalu menjalankan salinan baru SETIAP 5 MENIT.
# Hasilnya 44 proses run_worker.bat dan 31 run_scheduler.bat menumpuk.
#
# Sekarang ada tiga lapis pengaman:
#   1. run_worker.bat / run_scheduler.bat memegang kunci (worker.lock /
#      scheduler.lock), jadi instance kedua keluar sendiri.
#   2. Script ini TIDAK menjalankan apa pun kalau prosesnya masih terlihat ada.
#   3. Kalau proses ada TAPI denyutnya basi, statusnya dilaporkan "MACET" —
#      bukan dijadikan alasan menjalankan salinan baru.
#
# Deteksi memakai dua sinyal karena masing-masing punya titik buta:
#   - CommandLine proses: tidak terbaca untuk proses yang dijalankan sebagai
#     Administrator (nilainya kosong), jadi instance elevated milik user tidak
#     akan terlihat.
#   - Denyut (heartbeat): terbaca untuk siapa pun, tapi bisa basi kalau php
#     menggantung.
# Yang dipakai: ADA PROSES **atau** DENYUT MASIH SEGAR = jangan start.
# -----------------------------------------------------------------------------

function Get-Pembungkus([string]$namaBat) {
    return @(
        Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" -ErrorAction SilentlyContinue |
            Where-Object { $_.CommandLine -and $_.CommandLine -match [regex]::Escape($namaBat) }
    )
}

function Test-Denyut([string]$path, [int]$batasDetik) {
    if (-not (Test-Path -LiteralPath $path)) { return $false }
    $umur = (Get-Date) - (Get-Item -LiteralPath $path).LastWriteTime
    return ($umur.TotalSeconds -le $batasDetik)
}

function Test-PortListening([int]$port) {
    try { return [bool](Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction Stop) }
    catch { return $false }
}

function Test-ProcessByName([string]$name) {
    return [bool](Get-Process -Name ([System.IO.Path]::GetFileNameWithoutExtension($name)) -ErrorAction SilentlyContinue)
}

function Get-ServiceTunnel {
    return Get-Service -Name 'cloudflared' -ErrorAction SilentlyContinue
}

function Test-Http([string]$url, [int]$timeoutDetik = 20) {
    try {
        $r = Invoke-WebRequest -Uri $url -TimeoutSec $timeoutDetik -UseBasicParsing -ErrorAction Stop
        return [int]$r.StatusCode -lt 400
    } catch {
        # 4xx tetap dihitung "sampai", karena yang diuji di sini adalah
        # sambungannya, bukan isi responsnya. Yang dianggap gagal hanya kalau
        # tidak ada respons sama sekali.
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode -lt 500 }
        return $false
    }
}

# -----------------------------------------------------------------------------
# KENAPA KESEHATAN TUNNEL TIDAK CUKUP DIUKUR DARI "ADA PROSESNYA"
#
# tunnel_log.txt 17 Nov 2026 merekam cloudflared yang kehilangan SEMUA koneksi
# ke edge Cloudflare sambil prosesnya tetap hidup beberapa saat:
#     ERR Connection terminated connIndex=1
#     ERR no more connections active and exiting
# Dalam keadaan itu `Get-Process cloudflared` masih menjawab "ada", padahal
# domainnya sudah tidak bisa diakses siapa pun.
#
# Jadi yang diuji di sini adalah PERBEDAAN antara origin lokal dan domain
# publik:
#   origin OK + publik OK    -> sehat
#   origin OK + publik GAGAL -> tunnelnya yang rusak (bukan API-nya)
#   origin GAGAL             -> masalahnya di API, jangan salahkan tunnel
# -----------------------------------------------------------------------------
function Get-KesehatanTunnel {
    $origin = Test-Http 'http://127.0.0.1:8000/api/finance/categories' 15
    if (-not $origin) { return 'origin-mati' }
    $publik = Test-Http 'https://api.kabyra.my.id/api/finance/categories' 25
    if ($publik) { return 'sehat' }
    return 'tunnel-rusak'
}

# --- Pemangkas log -----------------------------------------------------------
# Log ini ditulis dengan `>>` dari dalam batch, jadi handle-nya dipegang selama
# php berjalan: rename/hapus bisa gagal. Yang dilakukan adalah memotong isinya
# di tempat sambil menyimpan ekor terakhirnya ke .prev.txt.
function Compact-Log([string]$path, [int]$maxMB, [int]$keepLines = 2000) {
    if (-not (Test-Path -LiteralPath $path)) { return }
    $file = Get-Item -LiteralPath $path
    if ($file.Length -le ($maxMB * 1MB)) { return }
    $mb = [math]::Round($file.Length / 1MB, 2)

    $prev = Join-Path ([System.IO.Path]::GetDirectoryName($path)) `
                      ([System.IO.Path]::GetFileNameWithoutExtension($path) + '.prev.txt')
    try {
        Get-Content -LiteralPath $path -Tail $keepLines -ErrorAction Stop |
            Set-Content -LiteralPath $prev -Encoding UTF8 -ErrorAction Stop
    } catch {
        Write-Output "  ! gagal menyimpan ekor $([System.IO.Path]::GetFileName($path)): $($_.Exception.Message)"
    }

    try {
        $fs = [System.IO.File]::Open($path, [System.IO.FileMode]::Open,
                                     [System.IO.FileAccess]::Write,
                                     [System.IO.FileShare]::ReadWrite)
        try { $fs.SetLength(0) } finally { $fs.Dispose() }
        Write-Output "  log dipangkas: $([System.IO.Path]::GetFileName($path)) ($mb MB -> 0, ekor $keepLines baris di .prev.txt)"
    } catch {
        Write-Output "  ! tidak bisa memangkas $([System.IO.Path]::GetFileName($path)): $($_.Exception.Message)"
    }
}

$components = @(
    @{
        Nama      = 'artisan serve (API :8000)'
        Hidup     = { Test-PortListening 8000 }
        Proses    = { @() }                       # port sudah cukup sebagai bukti
        Denyut    = { $true }
        Vbs       = 'run_serve_silent.vbs'
        Log       = @('serve_log.txt')
    }
    @{
        Nama      = 'worker reminder'
        Hidup     = { (Get-Pembungkus 'run_worker.bat').Count -gt 0 -or (Test-Denyut (Join-Path $root 'worker_heartbeat.txt') 180) }
        Proses    = { Get-Pembungkus 'run_worker.bat' }
        Denyut    = { Test-Denyut (Join-Path $root 'worker_heartbeat.txt') 180 }
        Vbs       = 'run_silent.vbs'
        Log       = @('worker_run.log', 'worker_log.txt')
    }
    @{
        Nama      = 'scheduler Laravel'
        Hidup     = { (Get-Pembungkus 'run_scheduler.bat').Count -gt 0 -or (Test-Denyut (Join-Path $root 'scheduler_heartbeat.txt') 240) }
        Proses    = { Get-Pembungkus 'run_scheduler.bat' }
        Denyut    = { Test-Denyut (Join-Path $root 'scheduler_heartbeat.txt') 240 }
        Vbs       = 'run_scheduler_silent.vbs'
        Log       = @('scheduler_run.log', 'scheduler_log.txt')
    }
)

Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] start_stack.ps1"

$semuaLog = @()

foreach ($c in $components) {
    $semuaLog += $c.Log

    $jumlahProses = @(& $c.Proses).Count

    # Penumpukan: laporkan dengan jelas, jangan pernah tambah lagi.
    if ($jumlahProses -gt 1) {
        Write-Output ("  TUMPUK {0}  ({1} salinan berjalan - seharusnya 1; hentikan yang berlebih)" -f $c.Nama, $jumlahProses)
        continue
    }

    if (& $c.Hidup) {
        if (-not (& $c.Denyut)) {
            Write-Output ("  MACET {0}  (proses ada tapi denyutnya basi - kemungkinan php menggantung, mis. MySQL mati)" -f $c.Nama)
        } else {
            Write-Output ("  OK    {0}" -f $c.Nama)
        }
        continue
    }

    if ($Status) {
        Write-Output ("  MATI  {0}  (mode -Status, tidak dijalankan)" -f $c.Nama)
        continue
    }

    $vbs = Join-Path $root $c.Vbs
    if (-not (Test-Path -LiteralPath $vbs)) {
        Write-Output ("  ! {0}: launcher tidak ditemukan: {1}" -f $c.Nama, $vbs)
        continue
    }

    Write-Output ("  START {0}  ({1})" -f $c.Nama, $c.Vbs)
    Start-Process -FilePath 'wscript.exe' -ArgumentList "`"$vbs`"" -WindowStyle Hidden | Out-Null
    Start-Sleep -Seconds 4
}

# -----------------------------------------------------------------------------
# CLOUDFLARED — ditangani terpisah dari komponen lain karena punya dua mode.
#
# MODE SERVICE (setelah install_tunnel_service.ps1 dijalankan): Windows sendiri
# yang menghidupkan ulang kalau crash, lewat recovery action. Script ini CUKUP
# melapor, dan SAMA SEKALI TIDAK BOLEH menjalankan run_tunnel_silent.vbs --
# itu akan membuat connector kedua pada tunnel yang sama, dan Cloudflare lalu
# membagi trafik ke dua connector yang bisa punya ingress berbeda.
#
# MODE MANUAL (service belum dipasang): perilaku lama, yaitu menyalakan
# run_tunnel_silent.vbs kalau prosesnya hilang.
# -----------------------------------------------------------------------------
$semuaLog += 'tunnel_log.txt'
$svcTunnel = Get-ServiceTunnel

if ($svcTunnel) {
    if ($svcTunnel.Status -eq 'Running') {
        $sehat = Get-KesehatanTunnel
        switch ($sehat) {
            'sehat'       { Write-Output "  OK    cloudflared tunnel  (Windows Service, domain publik menjawab)" }
            'tunnel-rusak' {
                Write-Output "  RUSAK cloudflared tunnel  (service jalan & origin lokal sehat, tapi domain publik TIDAK menjawab)"
                if (-not $Status) {
                    Write-Output "        -> merestart service cloudflared"
                    try {
                        Restart-Service -Name 'cloudflared' -Force -ErrorAction Stop
                        Write-Output "        -> restart terkirim"
                    } catch {
                        Write-Output "        ! gagal restart (butuh hak Administrator): $($_.Exception.Message)"
                    }
                }
            }
            'origin-mati' { Write-Output "  ?     cloudflared tunnel  (origin lokal :8000 tidak menjawab - periksa API dulu, bukan tunnelnya)" }
        }
    } else {
        Write-Output "  MATI  cloudflared tunnel  (Windows Service status: $($svcTunnel.Status))"
        if (-not $Status) {
            try {
                Start-Service -Name 'cloudflared' -ErrorAction Stop
                Write-Output "        -> service dinyalakan"
            } catch {
                Write-Output "        ! gagal menyalakan (butuh hak Administrator): $($_.Exception.Message)"
            }
        }
    }
} else {
    # Service belum dipasang -- jalur lama.
    if (Test-ProcessByName 'cloudflared.exe') {
        $sehat = Get-KesehatanTunnel
        switch ($sehat) {
            'sehat' {
                Write-Output "  OK    cloudflared tunnel  (proses manual, BELUM jadi service)"
            }
            'tunnel-rusak' {
                Write-Output "  RUSAK cloudflared tunnel  (proses ada & origin sehat, tapi domain publik tidak menjawab)"
                Write-Output "        -> jalankan install_tunnel_service.ps1 sebagai Administrator supaya"
                Write-Output "           Windows yang menghidupkannya ulang otomatis"
            }
            'origin-mati' {
                # Jangan laporkan tunnel sebagai OK kalau origin-nya sendiri mati:
                # status tunnel memang belum bisa disimpulkan dari sini.
                Write-Output "  ?     cloudflared tunnel  (origin lokal :8000 tidak menjawab - periksa API dulu, bukan tunnelnya)"
            }
        }
    } elseif ($Status) {
        Write-Output "  MATI  cloudflared tunnel  (mode -Status, tidak dijalankan)"
    } else {
        $vbs = Join-Path $root 'run_tunnel_silent.vbs'
        if (Test-Path -LiteralPath $vbs) {
            Write-Output "  START cloudflared tunnel  (run_tunnel_silent.vbs)"
            Start-Process -FilePath 'wscript.exe' -ArgumentList "`"$vbs`"" -WindowStyle Hidden | Out-Null
        } else {
            Write-Output "  ! cloudflared tunnel: launcher tidak ditemukan: $vbs"
        }
    }
}

$semuaLog += 'watchdog_log.txt'
foreach ($log in ($semuaLog | Select-Object -Unique)) { Compact-Log (Join-Path $root $log) $MaxLogMB }

# Apache dan MySQL dibiarkan ke Laragon (laragon.lnk sudah ada di folder
# Startup). Keduanya tidak disentuh di sini supaya tidak bentrok dengan
# tombol Start/Stop di jendela Laragon.
Write-Output "  (Apache + MySQL: urusan Laragon, tidak disentuh script ini)"
