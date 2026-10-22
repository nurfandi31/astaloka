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
 * Query-nya memuat SELURUH kolom `tenants`, bukan hanya yang dipakai di
 * bawah. Itu terlihat berlebihan untuk endpoint publik -- tapi nama
 * database, `db_username`, dan `db_password` ditambahkan ke tenancy
 * belakangan, dan daftar kolom eksplisit di sini akan cepat basi begitu
 * ada kolom baru yang perlu ikut terbaca.
 *
 * Yang menentukan tidak ada kebocoran adalah `map()` di bawah, bukan
 * kolom mana yang diambil: response dibangun dari array kunci dan nilai
 * yang ditulis tangan, jadi kolom baru di tabel `tenants` tidak pernah
 * ikut terbawa tanpa disengaja.
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
            ->with(['activeDomains' => fn ($q) => $q
                ->where('is_wildcard', false)
                ->orderByDesc('is_primary')
                ->orderBy('id'),
            ])
            ->orderBy('nama')
            ->get()
            ->map(function (Tenant $t) {
                /*
                 * Sumber urutan: `tenant_domains` DULU, baru kolom legacy.
                 *
                 * Urutan ini bukan selera gaya -- itu yang membuat halaman
                 * depan mengirim orang ke domain yang benar-benar dilayani.
                 *
                 * `TenantResolver::fromHost()` mencari di `tenant_domains`
                 * lebih dulu, jadi itulah yang pertama kali dicocokkan saat
                 * request datang. Kolom legacy `tenants.domain` hanya dibaca
                 * sebagai cadangan.
                 *
                 * Membalik urutan itu menghasilkan gejala nyata: `syncPrimary()`
                 * menulis domain utama ke kedua kolom setiap kali domain
                 * berubah, tapi `backfillLegacyDomains()` SENGAJA melewati
                 * lokasi yang sudah punya baris di `tenant_domains`. Kolom
                 * lama karena itu bisa tertinggal menunjuk domain yang
                 * sudah tidak dilayani -- dan halaman depan akan terus
                 * mengarahkan pengunjung ke sana.
                 *
                 * Wildcard dikecualikan di query di atas: `*.astaloka.test`
                 * memang tidak bisa diklik, dan mengarahkan orang ke sana
                 * hanya menghasilkan error browser yang tidak menjelaskan
                 * apa pun. Lokasi yang HANYA punya wildcard tidak punya
                 * host yang bisa dipakai -- `host` jadi kosong dan dropdown-nya
                 * menampilkan nama saja.
                 */
                $host = trim((string) ($t->activeDomains->first()?->host ?? ''));

                if ($host === '') {
                    $host = trim((string) ($t->domain ?: $t->domain_alternatif));
                }

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
