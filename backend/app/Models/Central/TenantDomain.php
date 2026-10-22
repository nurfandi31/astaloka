<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu domain custom milik satu lokasi (tenant).
 *
 * Berdampingan dengan kolom `domain` / `domain_alternatif` di tabel
 * `tenants`, bukan menggantikannya -- lihat
 * `database/migrations_central/..._create_tenant_domains_table.php`.
 *
 * Berdampingan dengan tabel `users` di database lokasi: `$connection` dikunci
 * ke `central` supaya model ini tetap membaca tabel yang benar walau
 * `TenantManager::activate()` sudah menimpa `database.default`. Tanpa kunci itu,
 * begitu sebuah lokasi aktif, `TenantDomain::all()` diam-diam membaca
 * database lokasi -- di mana tabel ini tidak ada.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $host
 * @property bool $is_wildcard
 * @property bool $is_primary
 * @property bool $is_active
 * @property-read Tenant $tenant
 */
class TenantDomain extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'host',
        'is_wildcard',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_wildcard' => 'boolean',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Host yang disimpan TANPA prefiks wildcard.
     *
     * Untuk domain persis, ini sama persis dengan `host`. Untuk wildcard,
     * `*.astaloka.test` disimpan penuh di `host`, dan bagian setelah `*.`
     * disimpan di sini supaya pencocokan bisa membandingkan sisa host
     * dengan suffix -- tanpa harus memotong string berulang di tiap request.
     *
     * @return string Persis `host`, atau host tanpa `*.`
     */
    public function suffix(): string
    {
        return $this->is_wildcard
            ? substr($this->host, 2)
            : $this->host;
    }

    /**
     * Host yang-SEBENARNYA diketik user di address bar.
     *
     * Untuk wildcard, `*.astaloka.test` sendiri tidak bisa diklik -- tidak ada
     * host yang persis bernama itu. Nilai ini hanya untuk ditampilkan di
     * panel dan di panduan DNS.
     */
    public function displayHost(): string
    {
        return $this->host;
    }

    /**
     * Apakah host request ini dilayani domain ini.
     *
     * Dua aturan, dan keduanya penting:
     *
     *   1. Wildcard harus menjulurkan SATU label saja. `*.astaloka.test`
     *      melayani `demo.astaloka.test`, tapi tidak boleh melayani
     *      `a.b.astaloka.test`. Tanpa batas ini, satu entri wildcard bisa
     *      mengambil alih seluruh subdomain milik orang lain yang
     *      kebetulan memakai subdomain yang sama.
     *
     *   2. Wildcard tidak boleh cocok dengan apex-nya sendiri. `*.astaloka.test`
     *      TIDAK melayani `astaloka.test` -- kalau iya, mendaftarkan wildcard
     *      untuk satu lokasi akan diam-diam mengambil alih domain pusat.
     */
    public function matches(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        if (! $this->is_wildcard) {
            return $this->host === $host;
        }

        $suffix = '.'.$this->suffix();

        if (! str_ends_with($host, $suffix)) {
            return false;
        }

        // Host yang tersisa setelah sufiks dibuang harus berisi tepat satu
        // label dan tidak boleh kosong. `demo.astaloka.test` -> `demo` (lolos);
        // `a.b.astaloka.test` -> `a.b` (ditolak); `astaloka.test` -> `` (ditolak).
        $label = substr($host, 0, -strlen($suffix));

        return $label !== '' && ! str_contains($label, '.');
    }
}