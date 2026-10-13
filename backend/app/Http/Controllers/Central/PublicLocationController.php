<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint PUBLIK untuk halaman depan.
 *
 * Satu-satunya endpoint yang boleh dibaca tanpa autentikasi -- dan hanya
 * mengembalikan kolom yang memang sudah tercetak di halaman publik apa pun:
 * nama lokasi, slug-nya, dan domain publiknya. Tidak ada email, alamat,
 * nomor telepon, kredensial database, atau tagihan yang ikut terbawa.
 *
 * Kenapa perlu: landing page butuh mengisi dropdown "pilih lokasi" DAN
 * mengarahkan pengunjung ke subdomain yang benar. `LocationController@index`
 * berada di balik `auth:sanctum` + `superadmin`; melonggarkan endpoint itu
 * akan membuka daftar lokasi lengkap beserta status tagihannya ke publik,
 * jadi keduanya dipisah.
 *
 * `domain` ikut dikembalikan karena tujuannya redirect, bukan hanya
 * tampilan. Tanpa itu landing page hanya bisa mengirim orang ke path di
 * domain pusat, dan subdomain-yalah yang membuat ResolveTenant memilih
 * database yang benar tanpa header tambahan.
 *
 * Response di-cache sebentar supaya halaman depan tidak di-hit setiap kali
 * visitor membuka tab baru. Daftar lokasi jarang berubah; menunda
 * perubahan sebentar tidak masalah -- berbeda dengan tagihan, yang justru
 * tidak ada di sini karena itu.
 */
class PublicLocationController extends Controller
{
    public function index(): JsonResponse
    {
        $lokasi = Tenant::query()
            ->where('is_active', true)
            ->orderBy('nama')
            ->get(['slug', 'nama', 'nama_lembaga', 'domain', 'domain_alternatif'])
            ->map(function (Tenant $t) {
                // Prioritaskan domain produksi. `domain_alternatif` bisa
                // berisi host dev (mis. *.test) yang tidak resolve di
                // server sebenarnya, jadi hanya dipakai kalau `domain`
                // kosong.
                $host = trim((string) ($t->domain ?: $t->domain_alternatif));

                return [
                    'slug' => $t->slug,
                    'nama' => $t->nama,
                    'label' => $t->label(),
                    'host' => $host,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $lokasi,
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
