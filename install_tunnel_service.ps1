# =============================================================================
# install_tunnel_service.ps1
# Memasang cloudflared sebagai Windows Service supaya tunnel api.kabyra.my.id
# hidup sendiri saat boot dan dihidupkan ulang otomatis kalau crash.
#
#   >>> HARUS DIJALANKAN SEBAGAI ADMINISTRATOR <<<
#   Klik kanan PowerShell -> "Run as administrator", lalu:
#     powershell -ExecutionPolicy Bypass -File C:\laragon\www\Baytasks-api\install_tunnel_service.ps1
#
# KENAPA SERVICE, BUKAN LOOP BATCH
# Sampai sekarang tunnel dijalankan manual dari jendela terminal. Begitu
# jendelanya tertutup, user logout, atau prosesnya keluar sendiri, domainnya
# mati sampai dijalankan lagi dengan tangan. tunnel_log.txt merekam kejadian
# itu pada 17 Nov 2026:
#     ERR Connection terminated connIndex=1
#     ERR no more connections active and exiting
#     INF Tunnel server stopped
# Sebagai service, Windows yang bertanggung jawab menghidupkannya kembali --
# tidak bergantung pada jendela cmd, sesi login, atau Scheduled Task.
#
# CATATAN PENTING: memasang service saja TIDAK membuatnya auto-restart.
# Recovery bawaan Windows untuk service baru adalah "Take No Action". Script
# ini mengaturnya lewat `sc.exe failure` di langkah 5 -- itu bagian yang paling
# sering terlewat kalau memasang manual.
# =============================================================================

param(
    # cloudflared.exe sekarang ada di dalam folder instalasi PHP 8.1.10. Itu
    # rapuh: Laragon menghapus folder PHP lama saat versinya diganti, dan
    # service-nya ikut mati karena ImagePath-nya menunjuk ke sana. Default-nya
    # disalin ke lokasi netral. Pakai -JanganSalin kalau mau tetap di tempat.
    [string]$FolderStabil = 'C:\cloudflared',
    [switch]$JanganSalin,
    [string]$ConfigPath = 'C:\Users\bayuk\.cloudflared\config.yml',
    [string]$BinaryPath = ''
)

$ErrorActionPreference = 'Stop'

function Langkah([string]$t) { Write-Host "`n=== $t ===" -ForegroundColor Cyan }
function Ok([string]$t)      { Write-Host "    OK   $t" -ForegroundColor Green }
function Info([string]$t)    { Write-Host "    ..   $t" }
function Peringatan([string]$t) { Write-Host "    !!   $t" -ForegroundColor Yellow }

# -----------------------------------------------------------------------------
# PEMANGGIL PROGRAM LUAR - JANGAN panggil .exe langsung dengan `2>&1`.
#
# Di PowerShell 5.1, `& program.exe ... 2>&1` membungkus SETIAP baris stderr
# menjadi ErrorRecord. Digabung dengan $ErrorActionPreference = 'Stop' di atas,
# baris stderr pertama langsung MENGHENTIKAN seluruh script -- walaupun
# programnya sukses (exit code 0).
#
# Itu persis yang terjadi 18 Nov 2026: cloudflared menulis log normalnya ke
# stderr ("INF Uninstalling cloudflared agent service"), script berhenti tepat
# setelah service dicopot, dan tunnel ditinggal dalam keadaan MATI TOTAL --
# service sudah hilang, connector lama sudah dimatikan di langkah 3, dan
# pemasangan ulang tidak pernah terjadi.
#
# Helper ini menurunkan ErrorActionPreference hanya selama program luar
# berjalan, lalu melaporkan exit code-nya secara eksplisit.
# -----------------------------------------------------------------------------
function Jalankan {
    param(
        [Parameter(Mandatory)][string]$Program,
        [string[]]$Argumen = @(),
        [switch]$Diam
    )
    $simpan = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $keluaran = & $Program @Argumen 2>&1 | ForEach-Object { "$_" }
        $kode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $simpan
    }
    if (-not $Diam) {
        foreach ($b in $keluaran) { if ($b.Trim()) { Info $b.Trim() } }
    }
    return [pscustomobject]@{ Kode = $kode; Keluaran = ($keluaran -join "`n") }
}

# --- 0. Pastikan Administrator -----------------------------------------------
$identitas = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identitas)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "Script ini HARUS dijalankan sebagai Administrator." -ForegroundColor Red
    Write-Host "Buka PowerShell lewat klik kanan -> 'Run as administrator', lalu ulangi." -ForegroundColor Red
    exit 1
}
Ok "berjalan sebagai Administrator ($($identitas.Name))"

# --- 1. Cari binary ----------------------------------------------------------
Langkah '1. Menemukan cloudflared.exe'
# JANGAN hanya mengandalkan PATH. Pada 18 Nov 2026 cloudflared.exe dipindah
# keluar dari folder PHP -- satu-satunya lokasi yang kebetulan ada di PATH --
# dan semua pemanggilan `cloudflared ...` langsung gagal tanpa pesan.
$kandidat = @(
    $BinaryPath,
    'C:\cloudflared\cloudflared.exe',
    'C:\laragon\cloudflared.exe',
    (Get-Command cloudflared -ErrorAction SilentlyContinue).Source
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }

$sumber = $kandidat | Select-Object -First 1
if (-not $sumber) {
    Write-Host "cloudflared.exe tidak ditemukan di PATH maupun lokasi yang biasa." -ForegroundColor Red
    Write-Host "Jalankan ulang dengan -BinaryPath 'C:\jalur\ke\cloudflared.exe'" -ForegroundColor Red
    exit 1
}
Info "ditemukan di: $sumber"

$binary = $sumber
if (-not $JanganSalin) {
    if (-not (Test-Path $FolderStabil)) { New-Item -ItemType Directory -Path $FolderStabil -Force | Out-Null }
    $binary = Join-Path $FolderStabil 'cloudflared.exe'
    Copy-Item -LiteralPath $sumber -Destination $binary -Force
    Ok "disalin ke lokasi stabil: $binary"
    Peringatan "PATH kamu masih menunjuk ke salinan lama. Itu tidak masalah untuk"
    Peringatan "service ini, tapi kalau nanti update cloudflared, update juga salinan di atas."
} else {
    Peringatan "memakai binary di tempat asal (-JanganSalin)."
    if ($binary -match '\\php-[0-9.]+') {
        Peringatan "binary ada di dalam folder PHP. Kalau folder itu dihapus/diganti versi,"
        Peringatan "service akan mati karena ImagePath-nya tidak valid lagi."
    }
}

# --- 2. Periksa config & kredensial ------------------------------------------
Langkah '2. Memeriksa config dan kredensial'
if (-not (Test-Path $ConfigPath)) { Write-Host "config.yml tidak ditemukan: $ConfigPath" -ForegroundColor Red; exit 1 }
Ok "config  : $ConfigPath"

$isiConfig = Get-Content $ConfigPath -Raw
if ($isiConfig -match '(?m)^\s*credentials-file:\s*(.+?)\s*$') {
    $kredensial = $Matches[1].Trim().Trim('"')
    if ($kredensial -notmatch '^[A-Za-z]:\\') {
        Peringatan "credentials-file BUKAN jalur absolut: $kredensial"
        Peringatan "Service jalan sebagai LocalSystem dengan home directory berbeda,"
        Peringatan "jadi jalur relatif tidak akan ketemu. Ubah ke jalur lengkap dulu."
    } elseif (-not (Test-Path $kredensial)) {
        Write-Host "    credentials-file tidak ada: $kredensial" -ForegroundColor Red; exit 1
    } else {
        Ok "kredensial: $kredensial (absolut, bisa dibaca LocalSystem)"
    }
} else {
    Peringatan "tidak menemukan baris credentials-file di config.yml"
}

# --- 3. Siapkan jaring pengaman ----------------------------------------------
Langkah '3. Menyiapkan connector cadangan sebelum menyentuh service'
$jalan = @(Get-Process -Name cloudflared -ErrorAction SilentlyContinue)
# -----------------------------------------------------------------------------
# URUTAN DI SINI SENGAJA DIBALIK DARI VERSI SEBELUMNYA.
#
# Versi lama mematikan SEMUA connector di langkah ini, lalu baru memasang
# service. Begitu pemasangan gagal di tengah -- dan itu terjadi dua kali pada
# 18 Nov 2026 -- domainnya ditinggal tanpa connector sama sekali, mati total
# sampai dihidupkan manual.
#
# Sekarang connector tingkat-user (run_tunnel.bat) DIBIARKAN HIDUP sebagai
# jaring pengaman selama pemasangan, dan baru dimatikan di langkah 6 setelah
# service terbukti melayani. Kalau service gagal, langkah 6 mengembalikannya.
#
# Yang dimatikan sekarang hanya proses milik SERVICE LAMA, karena service-nya
# memang harus dicopot sebelum dipasang ulang.
# -----------------------------------------------------------------------------
$prosesService = @($jalan | Where-Object {
    $ind = Get-CimInstance Win32_Process -Filter "ProcessId=$($_.Id)" -ErrorAction SilentlyContinue
    if (-not $ind) { return $false }
    $induk = Get-CimInstance Win32_Process -Filter "ProcessId=$($ind.ParentProcessId)" -ErrorAction SilentlyContinue
    $induk -and $induk.Name -eq 'services.exe'
})

if ($prosesService.Count -eq 0) {
    Info "tidak ada connector milik service yang berjalan"
} else {
    foreach ($p in $prosesService) {
        Info "mematikan connector milik service lama (pid $($p.Id))"
        Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds 3
}

$batLoop = @(Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" |
             Where-Object { $_.CommandLine -and $_.CommandLine -match 'run_tunnel\.bat' })
if ($batLoop.Count -gt 0) {
    Ok "connector tingkat-user DIBIARKAN hidup sebagai jaring pengaman ($($batLoop.Count) loop)"
} else {
    # Tidak ada yang melayani sama sekali -- nyalakan dulu supaya domain tetap
    # hidup kalau pemasangan service nanti gagal.
    Info "tidak ada connector tingkat-user; menyalakannya dulu sebagai cadangan"
    Start-Process -FilePath 'wscript.exe' -ArgumentList '"C:\laragon\www\Baytasks-api\run_tunnel_silent.vbs"' -WindowStyle Hidden
    Start-Sleep -Seconds 15
}

# --- 4. Pasang service -------------------------------------------------------
Langkah '4. Memasang service'
$svcLama = Get-Service -Name 'cloudflared' -ErrorAction SilentlyContinue
if ($svcLama) {
    Info "service 'cloudflared' sudah ada -- dicopot dulu supaya ImagePath-nya diperbarui"
    Jalankan -Program $binary -Argumen @('service', 'uninstall') | Out-Null
    Start-Sleep -Seconds 3
}

$hasilInstall = Jalankan -Program $binary -Argumen @('--config', $ConfigPath, 'service', 'install')

$svc = Get-Service -Name 'cloudflared' -ErrorAction SilentlyContinue
if (-not $svc) {
    Write-Host "Service gagal terpasang (exit code $($hasilInstall.Kode))." -ForegroundColor Red
    Peringatan "Tidak apa-apa: connector tingkat-user masih melayani domainmu."
    Peringatan "Periksa dengan: powershell -File C:\laragon\www\Baytasks-api\start_stack.ps1 -Status"
    exit 1
}
Ok "service terpasang"

# -----------------------------------------------------------------------------
# WAJIB: tulis ulang binPath dengan argumen lengkap.
#
# `cloudflared --config <path> service install` pada versi 2026.7.1 TIDAK
# membawa argumen apa pun ke service. Dibuktikan dari Event Log setelah
# percobaan pertama 18 Nov 2026:
#
#     Cloudflared service arguments: [C:\cloudflared\cloudflared.exe]
#
# Tidak ada --config, bahkan tidak ada `tunnel run`. Akibatnya service hidup
# tapi tidak tersambung ke tunnel mana pun, dan SEMUA domain menjawab
# HTTP 530 (origin unreachable) walau origin lokalnya sehat HTTP 200.
#
# Jadi argumennya dipasang sendiri lewat sc.exe. Perhatikan spasi setelah
# `binPath=` -- itu sintaks sc.exe, bukan salah ketik.
# -----------------------------------------------------------------------------
Langkah '4b. Menulis argumen service (--config + tunnel run)'
$binPath = '"' + $binary + '" --config "' + $ConfigPath + '" tunnel run'
Jalankan -Program 'sc.exe' -Argumen @('config', 'cloudflared', 'binPath=', $binPath) | Out-Null

$imagePath = (Get-CimInstance Win32_Service -Filter "Name='cloudflared'").PathName
Info "ImagePath sekarang: $imagePath"
if ($imagePath -notmatch 'tunnel\s+run') {
    Write-Host "    ImagePath tidak memuat 'tunnel run' -- service tidak akan tersambung." -ForegroundColor Red
    exit 1
}
Ok "argumen service sudah benar"

# --- 5. Atur auto-restart ----------------------------------------------------
Langkah '5. Mengatur pemulihan otomatis (bagian yang paling sering terlewat)'
# reset= 86400 : hitungan kegagalan di-reset tiap 24 jam
# actions=     : restart setelah 5 detik, lalu 10 detik, lalu 30 detik
Jalankan -Program 'sc.exe' -Argumen @('failure', 'cloudflared', 'reset=', '86400', 'actions=', 'restart/5000/restart/10000/restart/30000') | Out-Null
# failureflag 1 = recovery juga berlaku saat service keluar dengan exit code
# bukan-nol, bukan hanya saat crash. cloudflared yang kehabisan koneksi ke edge
# keluar "normal" dengan exit code -- tanpa flag ini, Windows tidak menganggapnya
# kegagalan dan tidak menghidupkannya kembali.
Jalankan -Program 'sc.exe' -Argumen @('failureflag', 'cloudflared', '1') | Out-Null
Jalankan -Program 'sc.exe' -Argumen @('config', 'cloudflared', 'start=', 'auto') | Out-Null
Ok "startup type: Automatic (hidup saat boot, tanpa perlu login)"

# Verifikasi, jangan percaya begitu saja -- percobaan pertama menghasilkan
# satu aksi RESTART/20000 bawaan cloudflared, bukan tiga aksi yang diminta.
Langkah '5b. Memverifikasi pengaturan pemulihan'
Jalankan -Program 'sc.exe' -Argumen @('qfailure', 'cloudflared') | Out-Null

# --- 6. Serah terima: service ambil alih, cadangan dimatikan -----------------
Langkah '6. Serah terima dari connector cadangan ke service'

function Uji-Domain {
    $hasil = @{}
    foreach ($u in @(
      'https://api.kabyra.my.id/api/finance/categories',
      'https://db.kabyra.my.id/',
      'https://kabyra.my.id/'
    )) {
        try {
            $r = Invoke-WebRequest -Uri $u -TimeoutSec 30 -UseBasicParsing
            $hasil[$u] = [int]$r.StatusCode
        } catch {
            $c = 0
            if ($_.Exception.Response) { $c = [int]$_.Exception.Response.StatusCode }
            $hasil[$u] = $c
        }
    }
    return $hasil
}

function Matikan-Cadangan {
    $loop = @(Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" |
              Where-Object { $_.CommandLine -and $_.CommandLine -match 'run_tunnel\.bat' })
    foreach ($b in $loop) {
        Info "mematikan loop cadangan run_tunnel.bat (pid $($b.ProcessId))"
        Jalankan -Program 'taskkill' -Argumen @('/T', '/F', '/PID', "$($b.ProcessId)") -Diam | Out-Null
    }
    # Sisa proses cloudflared yang BUKAN milik service juga dihentikan.
    foreach ($p in @(Get-Process -Name cloudflared -ErrorAction SilentlyContinue)) {
        $ind = Get-CimInstance Win32_Process -Filter "ProcessId=$($p.Id)" -ErrorAction SilentlyContinue
        if (-not $ind) { continue }
        $induk = Get-CimInstance Win32_Process -Filter "ProcessId=$($ind.ParentProcessId)" -ErrorAction SilentlyContinue
        if (-not $induk -or $induk.Name -ne 'services.exe') {
            Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
        }
    }
    Start-Sleep -Seconds 3
}

Matikan-Cadangan
Start-Service -Name 'cloudflared'
Info "menunggu service tersambung ke edge Cloudflare..."
Start-Sleep -Seconds 20

$svc = Get-Service -Name 'cloudflared'
Info "status service : $($svc.Status)"
Info "proses cloudflared: $(@(Get-Process -Name cloudflared -ErrorAction SilentlyContinue).Count) (harus 1)"

$cek = Uji-Domain
$gagal = @($cek.GetEnumerator() | Where-Object { $_.Value -lt 200 -or $_.Value -ge 500 })
foreach ($e in $cek.GetEnumerator()) {
    if ($e.Value -ge 200 -and $e.Value -lt 500) { Ok ("{0,-52} HTTP {1}" -f $e.Key, $e.Value) }
    else { Peringatan ("{0,-52} HTTP {1}" -f $e.Key, $e.Value) }
}

# -----------------------------------------------------------------------------
# ROLLBACK OTOMATIS
# Kalau service ternyata tidak melayani (mis. argumennya tidak terpakai seperti
# percobaan pertama yang menghasilkan HTTP 530 di semua domain), jangan tinggal
# domainnya mati. Kembalikan ke connector tingkat-user yang sudah terbukti
# jalan, lalu laporkan.
# -----------------------------------------------------------------------------
if ($gagal.Count -gt 0) {
    Langkah 'ROLLBACK -- service tidak melayani, mengembalikan connector cadangan'
    Peringatan "$($gagal.Count) domain gagal. Service dihentikan dan dinonaktifkan."
    Stop-Service -Name 'cloudflared' -Force -ErrorAction SilentlyContinue
    Jalankan -Program 'sc.exe' -Argumen @('config', 'cloudflared', 'start=', 'demand') | Out-Null

    Start-Process -FilePath 'wscript.exe' -ArgumentList '"C:\laragon\www\Baytasks-api\run_tunnel_silent.vbs"' -WindowStyle Hidden
    Start-Sleep -Seconds 20

    $cek2 = Uji-Domain
    foreach ($e in $cek2.GetEnumerator()) {
        if ($e.Value -ge 200 -and $e.Value -lt 500) { Ok ("pulih: {0,-45} HTTP {1}" -f $e.Key, $e.Value) }
        else { Peringatan ("MASIH GAGAL: {0,-38} HTTP {1}" -f $e.Key, $e.Value) }
    }
    Write-Host "`nService TIDAK dipakai. Domain dilayani connector tingkat-user seperti sebelumnya." -ForegroundColor Yellow
    Write-Host "Kirim output lengkap ini supaya penyebabnya bisa dicari." -ForegroundColor Yellow
    exit 1
}

$akhir = @(Get-Process -Name cloudflared -ErrorAction SilentlyContinue)
if ($akhir.Count -eq 1) { Ok "tepat 1 connector berjalan, milik service (pid $($akhir[0].Id))" }
else { Peringatan "$($akhir.Count) proses cloudflared berjalan -- seharusnya 1" }

Write-Host "`nSelesai." -ForegroundColor Green
Write-Host "Mulai sekarang tunnel tidak perlu dijalankan manual lagi."
Write-Host "Kalau ingin mencopotnya:  `"$binary`" service uninstall"
