@echo off
REM ===========================================================================
REM  ASTALOKA -- daftarkan subdomain tenant di hosts file Windows.
REM
REM  Jalankan: klik kanan file ini > "Run as administrator".
REM
REM  KAPAN PERLU DIJALANKAN
REM  ======================
REM
REM  Setiap kali ada lokasi (tenant) baru yang mau dibuka lewat subdomainnya
REM  sendiri, misalnya `lokasi2.astaloka.test`.
REM
REM  Kenapa perlu manual, bukan otomatis:
REM
REM  Laragon di mesin ini TIDAK memakai dnsmasq. Semua domain `.test` dipetakan
REM  satu per satu di hosts file Windows (lihat baris `#laragon magic!`).
REM  Subdomain yang tidak terdaftar di sana akan gagal di-resolve, dan
REM  gejalanya bukan error yang jelas melainkan halaman yang membuka lambat
REM  lalu berakhir di "Tidak dapat diakses situs ini".
REM
REM  Untuk 5 lokasi, jalankan file ini 5 kali dengan nama berbeda.
REM
REM  PEMAKAIAN
REM  ==========
REM
REM    tambahkan-host-tenant.bat lokasi
REM    tambahkan-host-tenant.bat lokasi2 lokasi3 lokasi4 lokasi5
REM
REM  Idempoten: slug yang sudah terdaftar dilewati, tidak diduplikasi.
REM ===========================================================================

setlocal EnableDelayedExpansion

set "HOSTS=%SystemRoot%\System32\drivers\etc\hosts"
set "APEX=astaloka.test"

REM --- Periksa hak akses ----------------------------------------------------
net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo  ERROR: Jalankan sebagai administrator.
    echo.
    echo    Klik kanan file ini ^> "Run as administrator".
    echo.
    pause
    exit /b 1
)

if not exist "%HOSTS%" (
    echo.
    echo  ERROR: hosts file tidak ditemukan di "%HOSTS%".
    echo.
    pause
    exit /b 1
)

if "%~1"=="" (
    echo.
    echo  Pemakaian:
    echo    %~nx0 lokasi
    echo    %~nx0 lokasi2 lokasi3 lokasi4 lokasi5
    echo.
    pause
    exit /b 1
)

echo.
echo  ASTALOKA -- daftarkan subdomain tenant
echo  ---------------------------------------
echo  hosts : %HOSTS%
echo.

set "TAMBAH=0"
set "LEWATI=0"
set "GAGAL=0"

:loop
if "%~1"=="" goto selesai

set "SLUG=%~1"

REM Validasi: hanya huruf kecil, angka, dan strip. Menolak lebih baik daripada
REM mengganti karakter diam-diam -- slug hasil transformasi tidak akan cocok
REM dengan `tenants.slug` di database pusat, dan tidak akan pernah ketemu.
echo %SLUG%| findstr /r /c:"^[a-z0-9][a-z0-9-]*$" >nul
if errorlevel 1 (
    echo    DITOLAK  "%SLUG%"  -- hanya huruf kecil, angka, dan strip.
    set /a GAGAL+=1
    goto lanjut
)

set "HOST=%SLUG%.%APEX%"

findstr /r /c:"^[ ]*127\.0\.0\.1[ ][ ]*%HOST%" "%HOSTS%" >nul
if not errorlevel 1 (
    echo    ADA     %HOST%
    set /a LEWATI+=1
    goto lanjut
)

REM >> tidak pernah pakai: pakai addendum yang menunjuk file sementara supaya
REM karakter baris baru file hosts tidak ikut berubah format.
set "TMP=%TEMP%\astaloka-hosts-%RANDOM%.tmp"
> "%TMP%" echo 127.0.0.1      %HOST%       #ASTALOKA tenant
copy /y "%HOSTS%" "%TMP%.new" >nul
type "%TMP%" >> "%TMP%.new"

REM Gagal di tengah jangan sampai hosts file jadi kosong.
if not exist "%TMP%.new" (
    echo    GAGAL   %HOST%  -- tidak bisa menulis hosts file.
    set /a GAGAL+=1
    goto lanjut
)

move /y "%TMP%.new" "%HOSTS%" >nul
if errorlevel 1 (
    echo    GAGAL   %HOST%  -- tidak bisa menulis hosts file.
    set /a GAGAL+=1
) else (
    echo    DITAMBAK %HOST%
    set /a TAMBAH+=1
)
del /q "%TMP%" >nul 2>&1

:lanjut
shift
goto loop

:selesai
echo.
echo  ---------------------------------------
echo  Ditambahkan : %TAMBAH%
echo  Sudah ada   : %LEWATI%
if not "%GAGAL%"=="0" echo  Ditolak/gagal: %GAGAL%
echo.
echo  CATATAN
echo  -------
echo  1. Tutup semua jendela browser, lalu buka lagi.
echo     Windows menyimpan cache DNS selama session.
echo.
echo  2. Arahkan dev server ke host tenant, bukan panel pusat:
echo        http://^<slug^>.astaloka.test:5173
echo.
echo  3. Kalau masih gagal, cek dua hal ini:
echo       - Baris "127.0.0.1  astaloka.test" ada di hosts file (host pusat).
echo       - vhost astaloka.test.conf punya "ServerAlias *\.astaloka.test".
echo         Tanpa ServerAlias, subdomain dilayani vhost default Laragon.
echo.
pause
exit /b 0
