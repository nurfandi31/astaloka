<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\TenantManager;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;

/**
 * Branding (judul dan warna) halaman login, tanpa autentikasi.
 *
 * Kenapa endpoint ini harus PUBLIK
 * ================================
 *
 * Yang dikembalikan ini persis yang tampil di halaman login: judul, sub
 * judul, deskripsi, dan warna. Halaman login adalah halaman pertama yang
 * dilihat orang -- sebelum dia punya akun, sebelum dia punya token, dan
 * sebelum dia mengetik apa pun. Endpoint di belakang `auth:sanctum` tidak
 * akan pernah bisa menjawabnya, dan balasan 401 membuat halaman login yang
 * justru tidak bisa memuat teksnya sendiri.
 *
 * Yang ditahan, dan apa yang bisa bocor dari sini
 * ================================================
 *
 * Pertanyaan yang wajar untuk endpoint publik: kenapa tidak mengembalikan
 * isi `tenants` semuanya?
 *
 * Karena yang dikembalikan hanya empat field yang memang sudah tercetak di
 * layar publik. Tidak ikut: nama database, username, password, email admin,
 * alamat, nomor telepon, status langganan, tagihan, atau domain yang tidak
 * aktif. Tidak ada satu pun dari itu yang dibutuhkan untuk merender halaman
 * login, dan semuanya berguna untuk memetakan infrastruktur internal.
 *
 * Ini jawaban yang sama yang diberikan `PublicLocationController` untuk
 * dropdown "pilih lokasi" di landing page. Bedanya hanya jumlah field.
 *
 * Cara lokasi ditemukan
 * =====================
 *
 * Endpoint ini TIDAK mencari tenant sendiri lewat query `tenants`. Ia
 * memakai `TenantManager::current()` -- hasil middleware `ResolveTenant` yang
 * sama dengan seluruh request lain.
 *
 * Alasannya sederhana: kalau endpoint ini punya cara mencari sendiri, ada dua
 * aturan untuk "lokasi mana yang melayani request ini", dan keduanya pasti
 * akan berbeda begitu salah satunya berubah.
 *
 * Kalau tidak ada lokasi aktif (request di domain pusat, atau subdomain yang
 * tidak terdaftar), yang dikembalikan adalah branding BAWAAN, bukan 404.
 *
 * Halaman login di domain pusat memang tidak terjadi -- domain itu ditangani
 * `web.php` yang mengarahkan ke landing page atau ke panel pusat -- tapi SPA
 * tetap memanggil endpoint ini saat boot. Balasan 404 di situ menjadi request
 * yang gagal tanpa pesan di halaman yang sedang tampil, sementara warna bawaan
 * membuat halaman tampil normal.
 */
class PublicBrandingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tenant = app(TenantManager::class)->current();

        return response()->json([
            'success' => true,
            'data' => $tenant !== null ? Branding::for($tenant) : Branding::default(),
        ])->header('Cache-Control', 'public, max-age=60');
    }
}