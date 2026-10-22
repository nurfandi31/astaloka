<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Registry satu lokasi (tenant).
 *
 * Model ini SELALU hidup di database pusat, tidak pernah di database lokasi.
 * Karena itu koneksinya dikunci lewat `$connection` di konstruktor supaya
 * tidak ikut ter-redirect oleh TenantManager -- yang menimpa
 * `database.default` setiap request. Tanpa kunci ini, begitu sebuah lokasi
 * aktif, `Tenant::find(1)` diam-diam akan membaca tabel `tenants` dari
 * database lokasi (di mana tabel itu tidak ada) dan melempar error yang
 * sangat menyesatkan.
 *
 * @property int $id
 * @property string $slug
 * @property string $nama
 * @property string $database
 */
class Tenant extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'slug',
        'nama',
        'nama_lembaga',
        'sektor',
        'bidang_usaha',
        'deskripsi_usaha',
        'judul_login',
        'sub_judul_login',
        'deskripsi_login',
        'warna_utama',
        'database',
        'db_username',
        'db_password',
        'db_host',
        'db_port',
        'domain',
        'domain_alternatif',
        'alamat',
        'email',
        'telepon',
        'logo',
        'tgl_registrasi',
        'tgl_pakai',
        'tgl_berakhir',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tgl_registrasi' => 'date',
            'tgl_pakai' => 'date',
            'tgl_berakhir' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(TenantInvoice::class, 'tenant_id');
    }

    /**
     * Domain custom milik lokasi ini.
     *
     * Berdampingan dengan kolom `domain` / `domain_alternatif` di atas, bukan
     * menggantikannya. Lokasi yang hanya punya domain di kolom lama tetap
     * terbaca -- kolom lama tidak dihapus dan masih dipakai sebagai cadangan
     * di TenantResolver::fromHost().
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class, 'tenant_id');
    }

    /** Domain aktif saja -- yang dipakai saat resolusi request. */
    public function activeDomains(): HasMany
    {
        return $this->domains()->where('is_active', true);
    }

    /** Invoice yang belum dibayar. */
    public function unpaidInvoices(): HasMany
    {
        return $this->invoices()->where('status', 'UNPAID');
    }

    /**
     * Kredensial MySQL untuk lokasi ini.
     *
     * Kolom `db_*` pada registry sengaja opsional: bila kosong, lokasi
     * memakai kredensial DB_* yang sama seperti lokasi lain (server sama).
     * Dipakai oleh TenantManager untuk membangun koneksi runtime.
     *
     * @return array{host:string,port:int,username:string,password:string,database:string}
     */
    public function credentials(): array
    {
        $host = $this->db_host ?: config('database.connections.tenant.host');
        $port = (int) ($this->db_port ?: config('database.connections.tenant.port') ?: 3306);

        return [
            'host' => $host,
            'port' => $port,
            'username' => $this->db_username ?: config('database.connections.tenant.username'),
            'password' => $this->db_password !== null && $this->db_password !== ''
                ? $this->db_password
                : config('database.connections.tenant.password'),
            'database' => $this->database,
        ];
    }

    /** Database lokasi sudah ada di MySQL? */
    public function databaseExists(): bool
    {
        $db = $this->database;

        // Nama database berasal dari registry pusat, bukan input user, tapi
        // tetap divalidasi: satu karakter aneh cukup untuk membuat PDO
        // membaca database lain.
        if (! preg_match('/^[A-Za-z0-9_]+$/', $db)) {
            return false;
        }

        return DB::connection('central')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$db]
        ) !== [];
    }

    /** Label ringkas untuk ditampilkan di daftar admin pusat. */
    public function label(): string
    {
        return $this->nama_lembaga ?: $this->nama;
    }

    /* ================================================================
     | PROFIL USAHA
     |
     | Ditambahkan 2026-10-15 bersama redesign dashboard pusat. Dashboard
     | yang baru menjawab "lokasi ini usaha apa", dan sampai kolom ini ada
     | jawabannya tidak tersimpan di mana pun -- lihat docblock migration
     | `add_business_profile_to_tenants_table`.
     *============================================================
     */
    /**
     * Daftar sektor yang bisa dipilih, untuk form tenant.
     *
     * Daftar tertutup di backend, bukan teks bebas di form. Alasannya sektor
     * dipakai untuk MENGELOMPOKKAN: dashboard menjumlahkan tagihan per sektor,
     * dan itu hanya benar kalau semua lokasi menulis kelompok yang sama. Kalau
     * admin bebas mengetik, akan ada "Air Bersih", "Air Bersih ", "air bersih",
     * dan "PDAM" -- empat nama untuk satu kelompok, dan setiap dashboard
     * menjumlahkannya sebagai empat kelompok yang berbeda.
     *
     * Nilai ini disimpan apa adanya (tanpa normalisasi), jadi daftar ini juga
     * berfungsi sebagai sumber kebenaran untuk data yang sudah terlanjur
     * tersimpan. Migration tidak boleh mengubah daftar ini tanpa sekaligus
     * memperbarui baris yang memakai nilai lama.
     *
     * Sektornya sengaja pendek, bukan daftar panjang. Dengan empat sektor,
     * hampir setiap lokasi pasti punya satu. Daftar panjang justru hampir
     * selalu menyisakan "lainnya" sebagai jawaban paling sering.
     */
    public const SEKTOR = [
        'Air Bersih',
        'Sanitasi',
        'Koperasi & Utilities',
        'Lainnya',
    ];

    /**
     * Sector key valid, untuk validasi request.
     * Dipakai sebagai rule `in:` di controller. Nilai diambil dari konstanta
     * yang sama supaya daftar yang divalidasi dan daftar yang ditawarkan form
     * tidak bisa berbeda -- kalau berbeda, form bisa menawarkan pilihan yang
     * selalu ditolak backend.
     */
    public static function sektorValid(): array
    {
        return self::SEKTOR;
    }

    /**
     * Nama usaha yang paling spesifik untuk ditampilkan.
     *
     * `bidang_usaha` lebih spesifik dari `nama_lembaga`, tapi tidak selalu
     * diisi. Urutannya turun dari spesifik ke umum, dan berhenti di nama
     * tenant sebagai batas bawah -- yang selalu ada.
     *
     * Tidak pernah mengembalikan string kosong: pemanggil selalu butuh
     * sesuatu untuk ditampilkan, dan string kosong membuat judul di pop-up
     * kehilangan nama yang paling dikenal orang.
     */
    public function usahaLabel(): string
    {
        foreach ([$this->bidang_usaha, $this->nama_lembaga, $this->nama] as $kandidat) {
            $kandidat = is_string($kandidat) ? trim($kandidat) : '';

            if ($kandidat !== '') {
                return $kandidat;
            }
        }

        return $this->slug;
    }
}
