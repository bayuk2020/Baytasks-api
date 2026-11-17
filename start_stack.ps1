# =============================================================================
# start_stack.ps1 — penjaga (watchdog) semua proses yang dibutuhkan
# api.kabyra.my.id, dan sekaligus pemangkas log yang membengkak.
#
# MASALAH YANG DIATASI
# Folder Startup Windows hanya berisi laragon.lnk. Jadi setelah reboot, Apache
# dan MySQL hidup, tapi `artisan serve`, worker reminder, scheduler, dan
# cloudflared TIDAK — api.kabyra.my.id mati sampai dijalankan manual satu per
# satu. Script ini menghidupkan apa pun yang sedang tidak jalan, jadi aman
# dipanggil berulang: komponen yang sudah hidup dilewati, tidak pernah dobel.
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
# KENAPA DETEKSINYA TIDAK MEMAKAI CommandLine
#
# Sebagian proses di mesin ini dijalankan dari terminal Administrator. Win32_Process
# TIDAK memberikan CommandLine untuk proses elevated kalau yang bertanya bukan
# proses elevated — nilainya kosong. Jadi mencocokkan 'run_worker.bat' di
# CommandLine akan MELEWATKAN worker yang sedang hidup, dan script ini akan
# menjalankan worker kedua: reminder terkirim dobel.
#
# Karena itu tiap komponen dideteksi dari bukti yang tidak bergantung hak akses:
#   - artisan serve : port 8000 sedang LISTENING
#   - worker        : worker_log.txt disentuh < 3 menit lalu (loop-nya 30 detik)
#   - scheduler     : scheduler_log.txt disentuh < 4 menit lalu (loop-nya 60 detik)
#   - cloudflared   : ada proses bernama cloudflared.exe (nama proses selalu
#                     terbaca, beda dengan CommandLine)
# -----------------------------------------------------------------------------

function Test-PortListening([int]$port) {
    try {
        return [bool](Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction Stop)
    } catch {
        return $false
    }
}

function Test-Heartbeat([string]$path, [int]$withinSeconds) {
    if (-not (Test-Path -LiteralPath $path)) { return $false }
    $age = (Get-Date) - (Get-Item -LiteralPath $path).LastWriteTime
    return ($age.TotalSeconds -le $withinSeconds)
}

function Test-ProcessByName([string]$name) {
    return [bool](Get-Process -Name ([System.IO.Path]::GetFileNameWithoutExtension($name)) -ErrorAction SilentlyContinue)
}

# --- Pemangkas log -----------------------------------------------------------
# Log-log ini ditulis dengan `>>` dari dalam batch, jadi handle-nya DIPEGANG
# selama proses hidup: rename/hapus akan gagal (sharing violation). Yang bisa
# dilakukan adalah memotong isinya di tempat (SetLength(0)) sambil menyimpan
# ekor terakhirnya ke file .prev.txt supaya jejak terdekat tidak hilang.
#
# Dipanggil SETELAH deteksi, karena memotong file ikut memperbarui
# LastWriteTime — kalau dijalankan lebih dulu, worker/scheduler yang sudah mati
# akan terlihat seperti masih berdenyut.
function Compact-Log([string]$path, [int]$maxMB, [int]$keepLines = 2000) {
    if (-not (Test-Path -LiteralPath $path)) { return }
    $file = Get-Item -LiteralPath $path
    if ($file.Length -le ($maxMB * 1MB)) { return }
    $mb = [math]::Round($file.Length / 1MB, 2)

    # ChangeExtension(..., $null) menyisakan titik di ujung ("worker_log."),
    # jadi nama file dibentuk dari direktori + nama tanpa ekstensi.
    $prev = Join-Path ([System.IO.Path]::GetDirectoryName($path)) `
                      ([System.IO.Path]::GetFileNameWithoutExtension($path) + '.prev.txt')
    try {
        Get-Content -LiteralPath $path -Tail $keepLines -ErrorAction Stop |
            Set-Content -LiteralPath $prev -Encoding UTF8 -ErrorAction Stop
    } catch {
        Write-Output "  ! gagal menyimpan ekor $([System.IO.Path]::GetFileName($path)): $($_.Exception.Message)"
    }

    try {
        # FileShare::ReadWrite supaya proses penulis tidak terganggu.
        $fs = [System.IO.File]::Open(
            $path,
            [System.IO.FileMode]::Open,
            [System.IO.FileAccess]::Write,
            [System.IO.FileShare]::ReadWrite)
        try { $fs.SetLength(0) } finally { $fs.Dispose() }
        Write-Output "  log dipangkas: $([System.IO.Path]::GetFileName($path)) ($mb MB -> 0, ekor $keepLines baris di .prev.txt)"
    } catch {
        Write-Output "  ! tidak bisa memangkas $([System.IO.Path]::GetFileName($path)): $($_.Exception.Message)"
    }
}

$components = @(
    @{ Nama = 'artisan serve (API :8000)'; Cek = { Test-PortListening 8000 };                                          Vbs = 'run_serve_silent.vbs';     Log = 'serve_log.txt' }
    @{ Nama = 'worker reminder';           Cek = { Test-Heartbeat (Join-Path $root 'worker_log.txt') 180 };             Vbs = 'run_silent.vbs';           Log = 'worker_log.txt' }
    @{ Nama = 'scheduler Laravel';         Cek = { Test-Heartbeat (Join-Path $root 'scheduler_log.txt') 240 };          Vbs = 'run_scheduler_silent.vbs'; Log = 'scheduler_log.txt' }
    @{ Nama = 'cloudflared tunnel';        Cek = { Test-ProcessByName 'cloudflared.exe' };                              Vbs = 'run_tunnel_silent.vbs';    Log = 'tunnel_log.txt' }
)

Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] start_stack.ps1"

$toCompact = @()

foreach ($c in $components) {
    $toCompact += (Join-Path $root $c.Log)

    if (& $c.Cek) {
        Write-Output ("  OK    {0}" -f $c.Nama)
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

$toCompact += (Join-Path $root 'watchdog_log.txt')
foreach ($log in $toCompact) { Compact-Log $log $MaxLogMB }

# Apache dan MySQL dibiarkan ke Laragon (laragon.lnk sudah ada di folder
# Startup). Keduanya tidak disentuh di sini supaya tidak bentrok dengan
# tombol Start/Stop di jendela Laragon.
Write-Output "  (Apache + MySQL: urusan Laragon, tidak disentuh script ini)"
