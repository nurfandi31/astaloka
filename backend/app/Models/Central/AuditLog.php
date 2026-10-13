<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit untuk aksi yang mengubah data sensitif.
 *
 * Berada di database PUSAT -- satu tabel untuk dua sisi aplikasi:
 * aksi superadmin di panel pusat dan aksi user di dalam lokasi.
 *
 * Karena tabel ini tidak punya soft delete dan aplikasi tidak menyediakan
 * endpoint untuk menghapus, ia diperlakukan sebagai catatan append-only.
 * Query ke model ini akan selalu membaca database pusat, apa pun koneksi
 * default yang sedang aktif -- penting, karena `AuditLogger` juga dipanggil
 * dari dalam konteks tenant.
 */
class AuditLog extends Model
{
    /**
     * Dipaksa ke database pusat.
     *
     * Ini bukan sekadar dokumentasi: `TenantManager::activate()` mengganti
     * `database.default` ke database lokasi. Tanpa override di sini, setiap
     * pencatatan audit yang terjadi di tengah request yang sudah ter-resolve
     * tenant akan diam-diam menulis ke database lokasi -- dan log milik
     * pusat hilang tanpa jejak.
     */
    protected $connection = 'central';

    protected $fillable = [
        'actor_id',
        'actor_type',
        'actor_name',
        'actor_role',
        'tenant_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /* ============================================================
     | SCOPE PENCARIAN
     |============================================================
     | Dipisah dari controller supaya query yang sama tidak ditulis
     | ulang di beberapa tempat, dan filter yang tidak valid tertangkap
     | di satu titik.
     */

    /** Hanya aksi dari panel pusat (superadmin). */
    public function scopePanelPusat($query)
    {
        return $query->where('actor_type', 'superadmin');
    }

    /** Hanya aksi yang terjadi di dalam sebuah lokasi. */
    public function scopeFromTenant($query, int $tenantId)
    {
        return $query->whereNotNull('tenant_id')->where('tenant_id', $tenantId);
    }

    /** Filter bebas: aksi, aktor, atau lokasi. Nilai kosong diabaikan. */
    public function scopeFilter($query, array $filters)
    {
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['actor_type'])) {
            $query->where('actor_type', $filters['actor_type']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            // Sampai akhir hari tersebut. Tanpa ini, filter "sampai 13/10"
            // diam-diam hanya memotong tepat jam 00:00.
            $query->where('created_at', '<=', date('Y-m-d 23:59:59', strtotime($filters['to'])));
        }

        if (! empty($filters['search'])) {
            $keyword = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($keyword) {
                $q->where('description', 'like', $keyword)
                    ->orWhere('actor_name', 'like', $keyword)
                    ->orWhere('action', 'like', $keyword);
            });
        }

        return $query;
    }

    /**
     * Daftar aksi yang pernah muncul, untuk mengisi dropdown filter.
     *
     * Diambil dari data yang sudah ada, bukan dari konstanta di kode.
     * Daftar aksi bertambah seiring ada fitur baru, dan dropdown yang
     * menampilkan aksi yang tidak pernah terjadi hanya menambah noise.
     */
    public function scopeDistinctActions($query)
    {
        return $query->select('action')
            ->distinct()
            ->orderBy('action');
    }

    /* ============================================================
     | LABEL
     |============================================================
     */

    /** Label aksi yang terbaca manusia, untuk ditampilkan di UI. */
    public static function actionLabel(string $action): string
    {
        $labels = [
            // Panel pusat
            'central.login' => 'Login Panel Pusat',
            'central.login_failed' => 'Gagal Login Panel Pusat',
            'central.logout' => 'Logout Panel Pusat',
            'tenant.create' => 'Lokasi Dibuat',
            'tenant.update' => 'Lokasi Diperbarui',
            'tenant.deactivate' => 'Lokasi Dinonaktifkan',
            'tenant.activate' => 'Lokasi Diaktifkan',
            'tenant.provision_failed' => 'Penyiapan Lokasi Gagal',
            'invoice.create' => 'Tagihan Dibuat',
            'invoice.mark_paid' => 'Tagihan Dilunasi',
            'invoice.mark_unpaid' => 'Pelunasan Dibatalkan',

            // Aplikasi lokasi
            'auth.login' => 'Login Lokasi',
            'auth.logout' => 'Logout Lokasi',
            'auth.login_failed' => 'Gagal Login Lokasi',
            'auth.password_change' => 'Password Diubah',
            'auth.password_change_failed' => 'Gagal Ubah Password',
            'user.profile_update' => 'Profil Diperbarui',
        ];

        if (isset($labels[$action])) {
            return $labels[$action];
        }

        /*
         * Aksi model lain (pelanggan, tagihan, transaksi, dst) tidak
         * didaftarkan satu per satu -- jumlahnya terlalu banyak dan akan
         * cepat basi. Daftar yang basi lebih buruk daripada tidak ada
         * sama sekali: superadmin akan mengira daftar itu lengkap.
         *
         * Format kesalahannya yang paling sering muncul di kode
         * bentuk adalah `ucfirst(str_replace)`, yang menghasilkan "Customer
         * update" -- setengah Inggris di tengah UI berbahasa Indonesia.
         *
         * Yang ditampilkan bukan kata update/create apa adanya, tapi
         * PERISTAWATAN: "Pelanggan Diperbarui". Nama objeknya ikut dirapikan,
         * jadi `tagihan_masuk.update` terbaca sebagai "Tagihan Masuk Diperbarui".
         * Nilai sebelum dan sesudahnya ada di `properties` -- dibuka lewat
         * panel detail -- bukan di label ini.
         */
        $parts = explode('.', $action, 2);

        // Aksi tanpa titik ("unknown_thing") tidak punya objek maupun verbs
        // yang bisa ditebak. Menebak-nebak di sini menghasilkan label yang
        // terlihat meyakinkan tapi salah -- di halaman audit itu lebih buruk
        // daripada satu baris yang jelas tidak bisa dibaca.
        if (count($parts) !== 2 || $parts[1] === '') {
            return str_replace('_', ' ', $action);
        }

        [$objek, $aksi] = $parts;

        $kamusAksi = [
            'create' => 'Dibuat',
            'update' => 'Diperbarui',
            'delete' => 'Dihapus',
            'destroy' => 'Dihapus',
            'restore' => 'Dipulihkan',
            'deactivate' => 'Dinonaktifkan',
            'activate' => 'Diaktifkan',
            'login' => 'Login',
            'logout' => 'Logout',
            'export' => 'Diekspor',
            'import' => 'Diimpor',
            'print' => 'Dicetak',
        ];

        $kataAksi = $kamusAksi[$aksi] ?? self::titleize($aksi);
        $kataObjek = self::titleize($objek);

        return "{$kataObjek} {$kataAksi}";
    }

    /**
     * Ubah `tagihan_masuk` menjadi `Tagihan Masuk`.
     *
     * `ucfirst` hanya menyentuh huruf pertama, jadi tanpa ini semua
     * kata setelah underscore tetap huruf kecil di tengah label. Ini
     * hanya penting untuk badge -- di UI Indonesia, "Tagihan masuk
     * Diperbarui" terbaca seperti ada kata yang kelewat kecil.
     */
    private static function titleize(string $value): string
    {
        return implode(' ', array_map(
            fn (string $word) => ucfirst(strtolower($word)),
            explode('_', $value),
        ));
    }

    /** Warna badge sesuai jenis aksi, dipakai frontend. */
    public static function toneFor(string $action): string
    {
        /*
         * Urutan pemeriksaan penting, karena beberapa nama aksi cocok dengan
         * lebih dari satu pola -- dan yang menang ditentukan baris pertama,
         * bukan yang paling spesifisik. Tiga jebakan yang sudah menimpa:
         *
         *   - `mark_unpaid` mengandung "paid" sebagai substring. Kalau
         *     diperiksa setelah `mark_paid`, membatalkan pelunasan akan
         *     tampil hijau -- tanda paling menyakitkan yang bisa diberikan
         *     pada aksi yang mengembalikan tagihan ke status belum bayar.
         *   - `password_change_failed` mengandung "failed" dan "change",
         *     jadi harus masuk kategori `danger` sebelum apa pun.
         *   - `deactivate` mengandung "activate", jadi nonaktifkan harus
         *     diperiksa lebih dulu -- kalau tidak, colored "Aktif" untuk
         *     aksi yang justru mematikan sesuatu.
         */
        if (str_contains($action, 'failed') || str_contains($action, 'deactivate')) {
            return 'danger';
        }

        if (str_contains($action, 'unpaid') || str_contains($action, 'revert')) {
            return 'warning';
        }

        // Hapus data dan ganti kredensial adalah dua aksi yang tidak bisa
        // dibatalkan tanpa akses langsung ke server. Hijau untuk keduanya
        // akan/train-the-eye_reader mengajari superadmin bahwa hijau itu
        // aman, dan baris merah akan diabaikan saat yang benar-benar penting.
        if (str_contains($action, 'delete')
            || str_contains($action, 'destroy')
            || str_contains($action, 'purge')
            || str_contains($action, 'drop')
        ) {
            return 'danger';
        }

        if (str_contains($action, 'create') || str_contains($action, 'mark_paid')) {
            return 'success';
        }

        if (str_contains($action, 'update') || str_contains($action, 'activate')) {
            return 'info';
        }

        // `password_change` sengaja TIDAK ikut kategori `update`.
        // Yang berbahaya di sini justru kata "update" -- jadi aksi yang
        // mengubah kredensial perlu warna tersendiri: hijau kalau berhasil
        // (pemilik sendiri yang lakukan), merah kalau gagal.
        if (str_contains($action, 'password_change')) {
            return 'success';
        }

        return 'muted';
    }
}
