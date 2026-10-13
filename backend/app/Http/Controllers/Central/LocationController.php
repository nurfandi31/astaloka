<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use App\Services\AuditLogger;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Panel pusat ASTA BRATA: daftar lokasi, pendaftaran lokasi baru, dan tagihan
 * langganan.
 *
 * SEMUA akses ke sini dijaga middleware `superadmin`, yang juga mengembalikan
 * koneksi default ke database pusat. Jadi kelas ini boleh memakai
 * `Tenant::query()` dan `DB::table()` tanpaFear terhadap tenant lain.
 */
class LocationController extends Controller
{
    public function __construct(
        private TenantManager $manager,
        private AuditLogger $audit,
    ) {}

    /** Daftar semua lokasi + ringkasan tagihannya. */
    public function index(Request $request): JsonResponse
    {
        $lokasi = Tenant::query()
            // Jumlah invoice yang belum lunas per lokasi. Dipakai untuk badge
            // "menunggak" di daftar, jadi hanya yang UNPAID yang dihitung.
            ->withCount(['unpaidInvoices as tagihan_belum_lunas'])
            ->withSum(
                ['invoices as total_tagihan' => fn ($q) => $q->where('status', 'UNPAID')],
                'jumlah'
            )
            ->orderBy('id')
            ->get()
            ->map(fn (Tenant $t) => $this->present($t));

        return response()->json([
            'success' => true,
            'data' => $lokasi,
        ]);
    }

    /** Detail satu lokasi. */
    public function show(Tenant $location): JsonResponse
    {
        $location->load(['invoices' => fn ($q) => $q->orderByDesc('tgl_invoice')]);

        return response()->json([
            'success' => true,
            'data' => $this->present($location, detail: true),
        ]);
    }

    /**
     * Daftarkan lokasi baru.
     *
     * Provisioning database lokasi adalah WAJIB, bukan opsi.
     *
     * Setiap langkah -- CREATE DATABASE, 66 migration, seeder master,
     * identitas di tabel `settings`, akun admin -- terjadi dalam satu
     * request. Yang boleh dilewati hanya akun admin, dan hanya bila
     * email/password-nya memang tidak diisi: database tanpa admin masih
     * bisa dibenahi lewat `tenancy:make-admin`, sedangkan database yang
     * tidak punya skema tidak bisa dipakai sama sekali.
     *
     * Dampak nyata dari membuat lokasi tanpa database: tenant terdaftar
     * dan terlihat aktif di panel, tapi setiap request ke lokasi itu gagal
     * dengan error koneksi. Superadmin baru sadar saat lokasi itu
     * dibuka, bukan saat didaftarkan.
     *
     * `provision` masih diterima di payload untuk kompatibilitas ke depan,
     * tapi nilainya DIABAIKAN kalau false -- permintaan kontradisikan,
     * dan diam-diam mengabaikannya lebih aman daripada membuat setengah
     * lokasi. Field itu akan dihapus setelah tidak ada klien yang
     * mengirimnya.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:tenants,slug'],
            'nama' => ['required', 'string', 'max:150'],
            'nama_lembaga' => ['nullable', 'string', 'max:191'],
            'domain' => ['nullable', 'string', 'max:191'],
            'domain_alternatif' => ['nullable', 'string', 'max:191'],
            'alamat' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:191'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'tgl_berakhir' => ['nullable', 'date'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'admin_email' => ['nullable', 'email', 'max:191'],
            'admin_password' => ['nullable', 'string', 'min:8'],
            'admin_name' => ['nullable', 'string', 'max:150'],
        ]);

        if (in_array($data['slug'], (array) config('tenancy.reserved_slugs', []), true)) {
            return response()->json([
                'success' => false,
                'message' => "Slug `{$data['slug']}` dicadangkan untuk sistem.",
            ], 422);
        }

        $dbName = $this->databaseNameFor($data['slug']);

        if (Tenant::where('database', $dbName)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Database `{$dbName}` sudah terdaftar untuk lokasi lain.",
            ], 422);
        }

        $tenant = null;

        // Permintaan lama masih mengirim `provision: false`. Lokasi tanpa
        // skema tidak pernah bisa dipakai, jadi bolak-balik sama saja --
        // tolak lebih awal, sebelum database dibuat dan dihapus lagi.
        if ($request->boolean('provision', true) === false) {
            return response()->json([
                'success' => false,
                'message' => 'Penyiapan database lokasi tidak bisa dimatikan. '
                    .'Lokasi tanpa skema tidak bisa dipakai. Buat lewat panel pusat, '
                    .'atau pakai `php artisan tenancy:make` untuk penyiapan manual.',
            ], 422);
        }

        try {
            $this->manager->createDatabase($data['slug']);

            $tenant = Tenant::create([
                'slug' => $data['slug'],
                'nama' => $data['nama'],
                'nama_lembaga' => $data['nama_lembaga'] ?? null,
                'database' => $dbName,
                'domain' => $data['domain'] ?? null,
                'domain_alternatif' => $data['domain_alternatif'] ?? null,
                'alamat' => $data['alamat'] ?? null,
                'email' => $data['email'] ?? null,
                'telepon' => $data['telepon'] ?? null,
                'tgl_registrasi' => now()->toDateString(),
                'tgl_pakai' => now()->toDateString(),
                'tgl_berakhir' => $data['tgl_berakhir'] ?? null,
                'max_users' => $data['max_users'] ?? null,
                'is_active' => true,
            ]);

            $this->manager->migrateTenantDatabase($tenant);

            // Master data (chart of accounts, jenis transaksi, jabatan)
            // wajib ada sebelum lokasi dipakai: tanpa CoA, halaman jurnal
            // akan kosong dan user tidak bisa mulai mencatat.
            $this->manager->seedTenantDatabase($tenant);

            if (! empty($data['admin_email']) && ! empty($data['admin_password'])) {
                $this->createLocationAdmin($tenant, $data);
            }
        } catch (Throwable $e) {
            // Jangan tinggalkan tenant yang menunjuk database tidak ada, dan
            // jangan sisakan database setengah jadi. Keduanya lebih buruk
            // daripada lokasi yang gagal dibuat: tenant yang masih
            // terdaftar tapi database-nya hilang tidak terlihat di panel
            // sebagai masalah, hanya sebagai lokasi yang tidak bisa dibuka.
            $dbDibuang = false;

            if ($tenant) {
                $tenant->delete();

                // Database sudah ada tapi gagal di tengah: hapus supaya tidak
                // jadi jebakan. Pengecekan ulang dipakai karena `createDatabase`
                // bisa saja gagal sebelum CREATE DATABASE sempat jalan.
                if ($this->manager->dropDatabase($dbName)) {
                    $dbDibuang = true;
                }
            }

            // Dicatat meski gagalnya provisioning. Ini justru momen paling
            // penting untuk diaudit: admin mencoba membuat lokasi, prosesnya
            // pecah di tengah, dan sekarang perlu tahu DB-nya sudah dibuang
            // supaya tidak ada setengah-jadi yang menggantung.
            //
            // `$tenant` sudah dihapus di blok di atas, tapi objeknya masih
            // ada di memori -- cukup untuk mengisi kolom audit tanpa
            // membacanya lagi dari database yang sudah tidak punya barisnya.
            $this->audit->record(
                'tenant.provision_failed',
                tenant: $tenant,
                subjectType: Tenant::class,
                subjectId: $tenant?->id,
                description: "Gagal menyiapkan lokasi `{$data['slug']}`: {$e->getMessage()}",
                properties: [
                    'slug' => $data['slug'],
                    'nama' => $data['nama'],
                    'database' => $dbName,
                    'error' => $e->getMessage(),
                    'database_dibuang' => $dbDibuang,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyiapkan lokasi: '.$e->getMessage(),
            ], 500);
        }

        $this->audit->record(
            'tenant.create',
            tenant: $tenant,
            subjectType: Tenant::class,
            subjectId: $tenant->id,
            description: "Lokasi `{$tenant->slug}` ({$tenant->nama}) berhasil dibuat di database `{$dbName}`.",
            properties: [
                'slug' => $tenant->slug,
                'nama' => $tenant->nama,
                'nama_lembaga' => $tenant->nama_lembaga,
                'database' => $dbName,
                'domain' => $tenant->domain,
                'admin_email' => $data['admin_email'] ?? null,
                'tgl_berakhir' => $tenant->tgl_berakhir?->toDateString(),
                'max_users' => $tenant->max_users,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Lokasi berhasil dibuat.',
            'data' => $this->present($tenant->fresh(), detail: true),
        ], 201);
    }

    /** Ubah data lokasi (tanpa menyentuh database-nya). */
    public function update(Request $request, Tenant $location): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:150'],
            'nama_lembaga' => ['nullable', 'string', 'max:191'],
            'domain' => ['nullable', 'string', 'max:191'],
            'domain_alternatif' => ['nullable', 'string', 'max:191'],
            'alamat' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:191'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'tgl_berakhir' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'max_users' => ['nullable', 'integer', 'min:1'],
        ]);

        // Disalin sebelum update. Setelah `$location->update()`, atribut di
        // model sudah berisi nilai baru, jadi diff() di afterwards tidak
        // akan menemukan apa pun yang berubah.
        $sebelum = $location->only(array_keys($data));

        $location->update($data);

        $perubahan = AuditLogger::diff($sebelum, $location->only(array_keys($data)));

        // Hanya catat kalau ada yang benar-benar berubah. Submit form tanpa
        // mengubah apa pun tetap menghasilkan request 200, dan log seperti
        // itu hanya menambah noise saat dicari.
        if ($perubahan !== []) {
            $this->audit->record(
                'tenant.update',
                tenant: $location,
                subjectType: Tenant::class,
                subjectId: $location->id,
                description: "Data lokasi `{$location->slug}` diperbarui ("
                    .implode(', ', array_keys($perubahan)).').',
                properties: ['changes' => $perubahan],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Data lokasi diperbarui.',
            'data' => $this->present($location->fresh(), detail: true),
        ]);
    }

    /**
     * Nonaktifkan lokasi (soft). Data tidak dihapus.
     *
     * Menghapus `is_active` membuat lokasi berhenti di-resolve, tapi database
     * dan datanya tetap utuh -- saklar yang bisa dinyalakan lagi. Penghapusan
     * fisik harus lewat proses terpisah yang eksplisit.
     */
    public function destroy(Tenant $location): JsonResponse
    {
        $sudahNonaktif = ! $location->is_active;

        $location->update(['is_active' => false]);

        // Idempoten secara sengaja: DELETE kedua kali pada lokasi yang
        // sudah nonaktif tidak menghasilkan log baru. Menonaktifkan
        // lokasi yang memang sudah mati bukan kejadian.
        if (! $sudahNonaktif) {
            $this->audit->record(
                'tenant.deactivate',
                tenant: $location,
                subjectType: Tenant::class,
                subjectId: $location->id,
                description: "Lokasi `{$location->slug}` dinonaktifkan. Data dan database tidak dihapus.",
                properties: [
                    'slug' => $location->slug,
                    'nama' => $location->nama,
                    'database' => $location->database,
                ],
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Lokasi `{$location->slug}` dinonaktifkan. Datanya tidak dihapus.",
        ]);
    }

    /**
     * Buat akun admin pertama di database lokasi.
     *
     * Memakai INSERT langsung, bukan `User::create`, karena tabel `users`
     * di lokasi punya kolom yang tidak ada di database pusat
     * (`jabatan_id`, dengan FK ke `jabatans`). Kolom itu sengaja
     * dibiarkan null: admin belum ditugaskan ke jabatan apa pun, dan
     * form user akan menagih kelengkapan itu nanti.
     */
    private function createLocationAdmin(Tenant $tenant, array $data): void
    {
        $this->manager->runFor($tenant, function () use ($data) {
            DB::connection(TenantManager::CONNECTION)->table('users')->insert([
                'name' => $data['admin_name'] ?? 'Admin Lokasi',
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Di luar `runFor()` di atas: applyTenantIdentity() sudah membuka
        // `runFor()` sendiri. Menaruhnya di dalam akan membuat lokasi aktif
        // di-restores dua kali -- tidak merusak, tapi membingungkan saat
        // menelusuri log koneksi.
        $this->manager->applyTenantIdentity($tenant);
    }

    private function databaseNameFor(string $slug): string
    {
        $prefix = (string) config('tenancy.database_prefix', 'astaloka_lokasi_');
        $batas = (int) config('tenancy.max_database_name_length', 64);

        return rtrim(substr($prefix.str_replace('-', '_', strtolower($slug)), 0, $batas), '_');
    }

    private function present(Tenant $t, bool $detail = false): array
    {
        $data = [
            'id' => $t->id,
            'slug' => $t->slug,
            'nama' => $t->nama,
            'label' => $t->label(),
            'database' => $t->database,
            'domain' => $t->domain,
            'domain_alternatif' => $t->domain_alternatif,
            'alamat' => $t->alamat,
            'email' => $t->email,
            'telepon' => $t->telepon,
            'is_active' => $t->is_active,
            'tgl_berakhir' => $t->tgl_berakhir?->toDateString(),
            'max_users' => $t->max_users,
            'database_ada' => $t->databaseExists(),
        ];

        // `tagihan_belum_lunas` hanya ada kalau index() dipanggil (memakai
        // withCount). Di show() atribut ini tidak di-set, jadi dibiarkan null
        // daripada nilai 0 yang menyesatkan -- 0 berarti "tidak ada
        // tagihan", sedangkan null berarti "tidak dihitung".
        if (array_key_exists('tagihan_belum_lunas', $t->getAttributes())) {
            $data['tagihan_belum_lunas'] = (int) $t->getAttribute('tagihan_belum_lunas');
        }

        if (array_key_exists('total_tagihan', $t->getAttributes())) {
            $data['total_tagihan'] = (float) ($t->getAttribute('total_tagihan') ?? 0);
        }

        if ($detail && $t->relationLoaded('invoices')) {
            $data['invoices'] = $t->invoices->map(fn (TenantInvoice $i) => [
                'id' => $i->id,
                'nomor' => $i->nomor,
                'jumlah' => (float) $i->jumlah,
                'status' => $i->status,
                'tgl_invoice' => $i->tgl_invoice?->toDateString(),
                'tgl_jatuh_tempo' => $i->tgl_jatuh_tempo?->toDateString(),
                'deadline' => $i->deadline()->toDateString(),
                'terlambat' => $i->isOverdue(),
                'sisa_hari' => $i->daysRemaining(),
            ]);
        }

        return $data;
    }
}
