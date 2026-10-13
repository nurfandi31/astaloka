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
        'max_users',
    ];

    protected function casts(): array
    {
        return [
            'tgl_registrasi' => 'date',
            'tgl_pakai' => 'date',
            'tgl_berakhir' => 'date',
            'is_active' => 'boolean',
            'max_users' => 'integer',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(TenantInvoice::class, 'tenant_id');
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

    /**
     * Apakah host request ini milik lokasi ini. Host kosong berarti lokasi
     * tidak punya domain khusus dan hanya bisa diakses lewat slug.
     */
    public function matchesHost(string $host): bool
    {
        $host = strtolower(trim($host));

        foreach (array_filter([$this->domain, $this->domain_alternatif]) as $d) {
            if (strtolower(trim($d)) === $host) {
                return true;
            }
        }

        return false;
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
}
