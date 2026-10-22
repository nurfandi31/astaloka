<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Models\Jabatan;
use App\Models\JabatanMenuAccess;
use App\Services\AuditLogger;
use App\Services\MenuAccessService;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hak akses menu per jabatan, dikelola dari PANEL PUSAT untuk seluruh lokasi.
 *
 * KENAPA DI SINI, BUKAN DI APLIKASI LOKASI
 *
 * Cara lama meminta logout-login berulang: satu lokasi = satu sesi. Admin
 * punya lima lokasi, hak akses tiap jabatan untuk semuanya harus diatur satu
 * per satu, tiap kali masuk ke domain masing-masing. Padahal
 * `jabatan_menu_access` itu milik database TIAP LOKASI -- jadi panel pusat
 * tidak bisa cukup menyimpan daftar; ia harus menyeberang ke database yang
 * benar untuk SETIAP lokasi, lalu menulis di sana. `TenantManager::runFor()`
 * yang melakukannya.
 *
 * Yang TIDAK berubah karena perpindahan ini:
 *
 *   - Tabelnya. Tetap `jabatan_menu_access` di database lokasi. Tidak ada
 *     tabel baru di pusat, jadi tidak ada yang perlu dimigrasikan dan tidak
 *     ada lokasi lama yang tiba-tiba kehilangan pengaturannya.
 *   - Aturan mainnya. `MenuAccessService` dipanggil persis seperti
 *     sebelumnya, hanya dari request yang berbeda. `null` tetap berarti
 *     "belum pernah dikonfigurasi" dan `[]` tetap berarti "tidak boleh
 *     apa-apa" -- dua keadaan yang tetap tidak boleh dicampur.
 *   - Aplikasinya. `EnforceMenuAccess` dan
 *     `MenuAccessService::allowedKeysFor()` membaca tabel yang sama seperti
 *     sebelumnya, tanpa tahu siapa yang menulisnya. Jadi pengaturan yang baru
 *     disimpan di pusat langsung berlaku untuk login berikutnya di lokasi itu.
 *
 * DUA LAPIS BATAS, KENAPA KEDUANYA DIPERLUKAN
 *
 *  1. `superadmin` (middleware route). Menjaga pemanggil: panel pusat milik
 *     superadmin saja.
 *  2. Konteks role di {@see bacaJabatan()}. `enforce_roles` default-nya
 *     `admin`, dan superadmin TIDAK ada di daftar itu -- jadi
 *     `MenuAccessService` memperlakukannya seperti role yang tidak pernah
 *     ikut aturan jabatan. Itu tidak menimbulkan masalah di sini, justru
 *     dipakai: sudut pandang role yang dipakai di bawah adalah "admin lokasi"
 *     yang jadi acuan ROLE saat menandai centangnya, bukan identitas pemanggil.
 *
 * Lapisan pertama menjaga SIAPA yang masuk; sisanya menjaga APA yang dibaca
 * dari database lokasi. Salah-satu saja tidak cukup.
 */
class CentralMenuAccessController extends Controller
{
    public function __construct(
        private TenantManager $manager,
        private MenuAccessService $menu,
        private AuditLogger $audit,
    ) {}

    /**
     * Daftar jabatan + status hak aksesnya untuk SATU tenant.
     *
     * Bentuk respons sengaja sama persis dengan `MenuAccessController::index()`
     * di aplikasi lokasi: frontend punya satu komponen untuk menampilkan
     * kotak centang, dan bentuk yang berbeda berarti dua komponen berbeda
     * yang harus dijaga sinkron tanpa ada yang menguji sinkronitasnya.
     *
     * `id` jabatan hanya bermakna DI DALAM satu lokasi -- nomor yang sama
     * menunjuk jabatan berbeda di lokasi berbeda. Frontend wajib menyimpan
     * (tenant, jabatan) sebagai satu pasang, tidak boleh jabatan saja.
     */
    public function index(Request $request, string $location): JsonResponse
    {
        $tenant = $this->cariTenant($location);

        try {
            $data = $this->bacaJabatan($tenant);
        } catch (Throwable $e) {
            /*
             * Database lokasi tidak bisa dibaca -- tidak ada, kredensialnya
             * berubah, atau tabelnya belum ada (lokasi dibuat tanpa seeder
             * jabatan).
             *
             * Ditangani di sini dengan 503 dan pesan yang menyebut penyebabnya.
             * Tanpa itu, halaman pusat menampilkan "belum ada jabatan" untuk
             * lokasi yang sebenarnya sedang bermasalah -- dan superadmin akan
             * menyimpulkan tidak ada yang perlu diatur.
             */
            report($e);

            return response()->json([
                'success' => false,
                'message' => "Database lokasi `{$tenant->slug}` tidak bisa dibaca. "
                    .'Periksa kredensialnya di panel pusat.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Simpan daftar menu untuk sebuah jabatan di SATU tenant.
     *
     * Isi permintaan identik dengan versi lokasi: `menu_keys` adalah daftar
     * LENGKAP, bukan tambahan. Rule validasinya dijelaskan di bawah.
     */
    public function update(Request $request, string $location, int $jabatan): JsonResponse
    {
        /*
         * Rule `present`, bukan `required`.
         *
         * `required` juga menolak ketiadaan key, tapi ia menambah aturan
         * "tidak boleh kosong" -- jadi `menu_keys: []` ikut ditolak. Padahal
         * array kosong itu SAH: itu hasil tombol "Hapus semua", keadaan yang
         * berbeda dari "belum pernah dikonfigurasi" dan tidak boleh ikut
         * hilang. `present` + `array` tepat untuk keduanya: key wajib ada,
         * isinya boleh nol.
         *
         * Ketiadaan key menghasilkan 422 dari `validate()` di bawah, yang
         * sudah tercatat dan TIDAK menyentuh database -- jadi tidak perlu
         * penanganan tambahan di sini.
         */
        $validated = $request->validate([
            'menu_keys'   => ['present', 'array'],
            'menu_keys.*' => ['string'],
        ]);

        $diminta = array_map('strval', $validated['menu_keys']);
        $dikenal = array_column($this->menu->catalog(), 'key');
        $takDikenal = array_values(array_diff($diminta, $dikenal));

        /*
         * Daftar yang seluruh isinya tidak dikenal hampir pasti permintaan
         * yang gagal, bukan pilihan admin. Disimpan apa adanya akan menghapus
         * seluruh hak akses lama lalu membalas `success: true` -- dan tidak ada
         * yang bisa memastikan apakah yang sebenarnya tersimpan. Ditolak 422.
         */
        if ($diminta !== [] && $takDikenal === $diminta) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada menu yang dikenal dari daftar yang dikirim, '
                    .'sehingga hak akses tidak diubah.',
                'code'    => 'MENU_KEYS_UNKNOWN',
                'data'    => ['diabaikan' => $takDikenal],
            ], 422);
        }

        $tenant = $this->cariTenant($location);

        try {
            /*
             * SELURUH pekerjaan database di dalam `runFor()`.
             *
             * `runFor()` mengembalikan `database.default` seperti semula di
             * blok `finally`, jadi kueri yang berhasil maupun yang gagal tidak
             * meninggalkan request ini -- dan render error sesudahnya -- membaca
             * database lokasi. `Jabatan` dan `JabatanMenuAccess` tidak mengunci
             * `$connection`, jadi tanpa penjaga ini keduanya diam-diam akan
             * membaca tabel pusat yang tidak punya keduanya.
             */
            $hasil = $this->manager->runFor($tenant, function () use ($jabatan, $diminta, $tenant, $request) {
                $target = Jabatan::query()->find($jabatan);

                if (! $target instanceof Jabatan) {
                    return null;
                }

                $sebelumnya = $this->menu->storedKeysForJabatan($jabatan);

                $this->menu->saveForJabatan($jabatan, $diminta);

                $sesudah = $this->menu->storedKeysForJabatan($jabatan);

                /*
                 * `diabaikan` dihitung dari `$sesudah`, bukan dari `$takDikenal`.
                 * Keduanya berbeda: `$takDikenal` menolak key yang TIDAK ADA di
                 * katalog, sedangkan `$sesudah` juga tidak memuat key `always`
                 * yang otomatis ditulis -- jadi menghitungnya dari situ akan
                 * melaporkan menu yang sebenarnya tersimpan sebagai "diabaikan".
                 */
                $disimpan = array_values(array_intersect($diminta, $sesudah ?? []));

                $this->audit->record(
                    'menu_access.update',
                    tenant: $tenant,
                    subjectType: Jabatan::class,
                    subjectId: $jabatan,
                    description: "Hak akses menu jabatan `{$target->nama_jabatan}` di lokasi "
                        ."`{$tenant->slug}` diperbarui dari panel pusat.",
                    properties: [
                        'lokasi'     => $tenant->slug,
                        'jabatan'    => $target->nama_jabatan,
                        'sebelumnya' => $sebelumnya,
                        'sesudah'    => $sesudah,
                        'diabaikan'  => array_values(array_diff($diminta, $disimpan)),
                    ],
                    // `$request->user()` ditulis eksplisit, bukan membiarkan
                    // `AuditLogger` menebak lewat `auth()`: pemanggilnya user
                    // pusat yang tokennya dititipkan ke koneksi berbeda, dan
                    // menebak berarti audit bisa saja mencatat aktor kosong
                    // untuk perubahan yang sebenarnya terjadi.
                    actor: $request->user(),
                );

                return [
                    'nama'       => $target->nama_jabatan,
                    'configured' => true,
                    'diabaikan'  => array_values(array_diff($diminta, $disimpan)),
                    'menu'       => $this->menu->menuForJabatan($jabatan, $target->nama_jabatan),
                ];
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => "Hak akses tidak bisa disimpan: {$e->getMessage()}",
            ], 503);
        }

        // Jabatan yang tidak ada di lokasi itu. Terpisah dari 503 di atas:
        // database-nya dibaca dengan baik, memang tidak ada barisnya.
        if ($hasil === null) {
            return response()->json([
                'success' => false,
                'message' => 'Jabatan tidak ditemukan di lokasi ini.',
                'code'    => 'JABATAN_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => "Hak akses menu untuk `{$hasil['nama']}` di lokasi "
                ."`{$tenant->slug}` berhasil disimpan.",
            'data'    => $hasil,
        ]);
    }

    /**
     * Kembalikan satu jabatan di satu lokasi ke default: semua menu boleh.
     *
     * Menghapus barisnya, bukan menyimpan daftar lengkap. "Semua menu" yang
     * ditulis eksplisit akan mulai berbeda dari katalog begitu ada menu baru
     * ditambahkan -- dan menu baru itu otomatis terbuka untuk jabatan ini,
     * padahal tidak pernah dicentang.
     */
    public function reset(Request $request, string $location, int $jabatan): JsonResponse
    {
        $tenant = $this->cariTenant($location);

        try {
            $hasil = $this->manager->runFor($tenant, function () use ($jabatan, $tenant, $request) {
                $target = Jabatan::query()->find($jabatan);

                if (! $target instanceof Jabatan) {
                    return null;
                }

                /*
                 * `saveForJabatan([])` menulis nol baris, yang ambigu dengan
                 * "sudah disetel tapi kosong". Hapus eksplisit supaya benar-benar
                 * kembali ke kondisi "belum pernah dikonfigurasi".
                 */
                $this->menu->saveForJabatan($jabatan, []);
                JabatanMenuAccess::query()->where('jabatan_id', $jabatan)->delete();

                $this->audit->record(
                    'menu_access.reset',
                    tenant: $tenant,
                    subjectType: Jabatan::class,
                    subjectId: $jabatan,
                    description: "Hak akses menu jabatan `{$target->nama_jabatan}` di lokasi "
                        ."`{$tenant->slug}` dikembalikan ke default (semua menu).",
                    actor: $request->user(),
                );

                return [
                    'nama'       => $target->nama_jabatan,
                    'configured' => false,
                    'menu'       => $this->menu->menuForJabatan($jabatan, $target->nama_jabatan),
                ];
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => "Hak akses tidak bisa dikembalikan: {$e->getMessage()}",
            ], 503);
        }

        if ($hasil === null) {
            return response()->json([
                'success' => false,
                'message' => 'Jabatan tidak ditemukan di lokasi ini.',
                'code'    => 'JABATAN_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => "Hak akses `{$hasil['nama']}` di lokasi `{$tenant->slug}` "
                .'dikembalikan ke default: semua menu boleh.',
            'data'    => $hasil,
        ]);
    }

    /**
     * Buat satu jabatan baru di SATU lokasi.
     *
     * Jabatan adalah data milik database lokasi, bukan data pusat: dua lokasi
     * boleh punya jabatan yang berbeda. Jadi ini tidak seperti menambah menu di
     * pusat -- `runFor()` menyeberang ke database yang benar, dan barisnya
     * hanya ada di sana.
     *
     * DIBUAT TANPA MENYENTUH HAK AKSESNYA, dan itu disengaja. `configured`
     * dibiarkan null, yang berarti "semua menu boleh". Menulis daftar menu
     * di saat yang sama akan membuat jabatan baru langsung terkunci dari
     * menu yang belum pernah dilihat superadmin -- dan admin lokasi yang
     * kebetulan memakai jabatan itu kehilangan akses tanpa pernah menyetelnya.
     * Pengaturan berikutnya cukup satu klik "Simpan" seperti jabatan lain.
     */
    public function store(Request $request, string $location): JsonResponse
    {
        /*
         * Batas panjang 100 mengikuti kolom `nama_jabatan` di migrasi. Validasi
         * ada di sini, bukan hanya di database: `validate()` mengembalikan 422
         * yang bisa ditampilkan apa adanya, sedangkan MySQL yang menolak akan
         * melempar PDOException dan menjadi 500.
         */
        $validated = $request->validate([
            'nama_jabatan' => ['required', 'string', 'max:100'],
        ]);

        $nama = trim((string) $validated['nama_jabatan']);
        $tenant = $this->cariTenant($location);

        try {
            $hasil = $this->manager->runFor($tenant, function () use ($nama) {
                /*
                 * `Jabatan::findByName()` memakai perbandingan `LOWER()`, jadi
                 * "bendahara" dan "Bendahara" dianggap sama. Duplikat berarti
                 * dua entri untuk satu jabatan, dan yang kedua diam-diam tidak
                 * pernah jadi yang benar begitu ada user yang ditugaskan ke sana.
                 * Diperiksa di sini, bukan UniqueIndex, karena user yang sudah
                 * ada boleh sudah terlanjur memakai salah ejaannya.
                 */
                if (Jabatan::findByName($nama)) {
                    return null;
                }

                $jabatan = Jabatan::query()->create(['nama_jabatan' => $nama]);

                /*
                 * Bentuknya HARUS sama dengan elemen di `index()`.
                 *
                 * Frontend menempelkan hasil `store()` ke daftar yang sudah
                 * ada tanpa memuat ulang lokasi. Kalau satu field hilang di
                 * sini, frontend akan menampilkan kotak centang untuk
                 * jabatan itu dengan daftar menu kosong -- dan itu terlihat
                 * seperti jabatan yang memang dibatasi, bukan seperti bug.
                 *
                 * `role` ikut di sini karena memang dibutuhkan: dari situ
                 * frontend tahu jabatan ini memakai daftar centang admin,
                 * teknisi, atau surveyor.
                 */
                return [
                    'id'         => (int) $jabatan->id,
                    'nama'       => $jabatan->nama_jabatan,
                    'configured' => false,
                    'total_user' => 0,
                    'roles'      => [],
                    'role'       => $this->menu->roleForJabatan($jabatan->nama_jabatan),
                    'menu'       => $this->menu->menuForJabatan((int) $jabatan->id, $jabatan->nama_jabatan),
                ];
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => "Jabatan tidak bisa ditambahkan: {$e->getMessage()}",
            ], 503);
        }

        /*
         * `null` berarti nama-nya sudah dipakai, bukan kegagalan. Keduanya
         * keluar dari `runFor()` yang sama, jadi dibedakan DI SINI -- kalau
         * tidak, jabatan ganda akan dilaporkan sebagai "database tidak bisa
         * dibaca", yang mengarah ke tempat yang benar-benar tidak bermasalah.
         */
        if ($hasil === null) {
            return response()->json([
                'success' => false,
                'message' => "Jabatan `{$nama}` sudah ada di lokasi ini.",
                'code'    => 'JABATAN_DUPLIKAT',
            ], 422);
        }

        $this->audit->record(
            'jabatan.created',
            tenant: $tenant,
            subjectType: Jabatan::class,
            subjectId: $hasil['id'],
            description: "Jabatan `{$hasil['nama']}` dibuat di lokasi `{$tenant->slug}`.",
            actor: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => "Jabatan `{$hasil['nama']}` berhasil ditambahkan di lokasi `{$tenant->slug}`. "
                .'Semua menu masih terbuka untuk jabatan ini.',
            'data'    => $hasil,
        ], 201);
    }

    /* ================================================================
     | PEKERJAAN INTERNAL
     *============================================================
     */

    /**
     * Daftar jabatan beserta status hak aksesnya, dibaca dari database lokasi.
     *
     * `users` ikut dihitung di sini, bukan dari pusat: tabel `users` juga milik
     * tiap lokasi, jadi tidak ada satu pun angka penggunanya yang bisa dijawab
     * tanpa menyeberang ke sana.
     *
     * `menuForJabatan($id, $nama)` menurunkan role dari NAMA jabatan, bukan
     * dari identitas pemanggil. Itu penting di sini: pemanggilnya superadmin
     * yang tidak punya menu apa pun, sementara daftar centangnya harus
     * mengikuti pekerjaan jabatan yang disetel -- Jabatan Teknisi melihat
     * menu Teknisi, Jabatan Bendahara melihat menu admin.
     *
     * Kolom `role` dikirim apa adanya supaya frontend bisa memberi tahu
     * superadmin jabatan mana yang memakai daftar centang admin -- dan itu
     * sebab kenapa `Cater` dan `Teknisi` perlu disebut eksplisit di config:
     * keduanya benar-benar satu tempat.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bacaJabatan(Tenant $tenant): array
    {
        return $this->manager->runFor($tenant, function () {
            $jabatan = Jabatan::query()
                ->orderBy('id')
                ->get(['id', 'nama_jabatan']);

            $penggunaPerJabatan = DB::table('users')
                ->select('jabatan_id', DB::raw('COUNT(*) as total'))
                ->whereNotNull('jabatan_id')
                ->groupBy('jabatan_id')
                ->pluck('total', 'jabatan_id');

            /*
             * Role apa saja yang memakai tiap jabatan -- dari USERNYA.
             *
             * Berbeda dengan `role` di bawah, yang berasal dari nama jabatan.
             * Yang ini menjawab "siapa saja yang memakai jabatan ini", dan
             * panel pusat membutuhkannya untuk membedakan jabatan yang
             * aturannya benar-benar berlaku dari jabatan yang centangnya akan
             * disimpan lalu tidak mengubah apa pun.
             */
            $rolePerJabatan = DB::table('users')
                ->select('jabatan_id', 'role')
                ->whereNotNull('jabatan_id')
                ->whereNotNull('role')
                ->distinct()
                ->get(['jabatan_id', 'role']);

            $daftarRole = [];

            foreach ($rolePerJabatan as $r) {
                $daftarRole[(int) $r->jabatan_id][] = (string) $r->role;
            }

            $data = [];

            foreach ($jabatan as $j) {
                $tersimpan = $this->menu->storedKeysForJabatan((int) $j->id);
                $roleJabatan = $this->menu->roleForJabatan($j->nama_jabatan);

                $data[] = [
                    'id'         => $j->id,
                    'nama'       => $j->nama_jabatan,
                    // `null` = belum pernah dikonfigurasi (semua menu tampil).
                    // `[]` = sudah dikonfigurasi tapi tidak boleh apa-apa.
                    'configured' => $tersimpan !== null,
                    'total_user' => (int) ($penggunaPerJabatan[$j->id] ?? 0),
                    'roles'      => $daftarRole[(int) $j->id] ?? [],
                    // Role dari NAMA jabatan. `null` hanya terjadi kalau
                    // `nama_jabatan` kosong, dan frontend memperlakukannya
                    // sebagai "menu tidak diketahui" -- bukan sebagai admin.
                    'role'       => $roleJabatan,
                    'menu'       => $this->menu->menuForJabatan((int) $j->id, $j->nama_jabatan),
                ];
            }

            return $data;
        });
    }

    /**
     * Tenant dari path, yang boleh berupa slug ATAU id.
     *
     * Menawarkan keduanya bukan skema: panel pusat menyimpan `id` di baris
     * tabelnya, dan form edit memakai `id` juga, jadi ada momen ketika hanya
     * salah satu yang tersedia di tangan. `firstOrFail()` menghasilkan 404
     * yang wajar untuk keduanya.
     */
    private function cariTenant(string $location): Tenant
    {
        return Tenant::query()
            ->where('slug', $location)
            ->orWhere('id', is_numeric($location) ? (int) $location : 0)
            ->firstOrFail();
    }
}