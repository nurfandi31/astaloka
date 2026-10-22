<?php

namespace App\Services\Migration\Blueprints;

use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\User;
use App\Models\Village;
use App\Services\Migration\CellValue;
use App\Services\Migration\MigrationBlueprint;
use App\Services\Migration\MigrationBlueprintConcerns;
use App\Services\Migration\MigrationRowException;

/**
 * Migrasi tiket instalasi: permohonan sambungan baru.
 *
 * TIDAK MEMBUAT AKUN PELANGGAN
 * ===========================
 *
 * Kolom NIK di sini disimpan ke tabel `installation_tickets` sebagai
 * data pemohon, tapi tidak membuat baris `users` dan tidak membuat baris
 * `customers`. Dua tabel itu harus konsisten -- akun yang tidak punya
 * pelanggan tidak bisa login, dan pelanggan yang tidak punya akun tidak
 * bisa dipakai -- dan membuat separuhnya di sini berarti ada cara
 * whistles yang menghasilkan akun atau pelanggan setengah jadi. Kalau
 * memang perlu memindahkan pelanggan lengkap sekaligus, pakai
 * `CustomerBlueprint`, yang menulis tiga tabel dalam satu savepoint.
 *
 * KOORDINAT TIDAK DIBUAT OTOMATIS DARI ALAMAT
 * ============================================
 *
 * Kolom lat/long wajib di skema dan tidak punya nilai turunan. Mengisi 0
 * membuat semua tiket menumpuk di titik yang sama di peta -- dan itu
 * terlihat benar di layar, jadi tidak ada yang memperbaikinya sampai
 * petanya dipakai.
 *
 * Karena itu kolomnya TIDAK opsional diam-diam. Kalau dikosongkan, baris
 * ditolak dengan pesan yang menyebut kenapa -- bukan disimpan dengan 0
 * supaya user tidak diganggu. Alias: koordinat bisa diambil otomatis
 * dari peta pada langkah pemetaan di lokasi, dan hasilnya di sini hanya
 * dibaca kalau sudah terisi.
 *
 * ALAMAT TIDAK DINORMALISIKAN
 * ==========================
 *
 * Alamat dipakai sebagai bagian kunci alami, jadi dua baris yang sama
 * hanya dianggap sama kalau alamatnya identik setelah whitespace-nya
 * diratakan. Menyeragamkan tanda baca (mis. "Jl." -> "Jalan") terlihat
 * rapi tapi justru memecah kunci: tiket yang sama bisa muncul dua kali
 * kalau salah satu ditulis dengan singkatan dan yang lain dengan kepanjangan.
 * Jadi kosongkan berarti 0, dan dokumentasi menyebutkan kenapa.
 */
class InstallationTicketBlueprint implements MigrationBlueprint
{
    use MigrationBlueprintConcerns;

    /** Status yang boleh diisi lewat file. */
    private const STATUS = ['draft', 'pending', 'surveyed', 'unpaid', 'processing', 'completed'];

    public function type(): string
    {
        return 'installation_tickets';
    }

    public function label(): string
    {
        return 'Tiket Instalasi';
    }

    public function description(): string
    {
        return 'Permohonan sambungan: pemohon, alamat, paket, dan statusnya. '
            .'Akun pelanggannya harus sudah ada -- pakai menu Pelanggan untuk memindahkan pelanggan lengkap.';
    }

    public function icon(): string
    {
        return 'clipboard-list';
    }

    public function order(): int
    {
        return 30;
    }

    public function sheetName(): string
    {
        return 'Tiket';
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'nama_pemohon',
                'label' => 'Nama Pemohon',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama lengkap pemohon, sama dengan yang ada di akun pelanggannya.',
                'example' => 'Siti Rahayu',
            ],
            [
                'key' => 'paket',
                'label' => 'Nama Paket',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama paket, harus sama persis dengan yang ada di lokasi ini. '
                    .'Lihat sheet "Referensi".',
                'example' => 'Rumah Tangga 10',
            ],
            [
                'key' => 'desa',
                'label' => 'Desa',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama desa. Kalau ada beberapa dusun dengan nama desa sama, '
                    .'tulis "Desa / Dusun". Lihat sheet "Referensi".',
                'example' => 'Sukamaju / Dusun 1',
            ],
            [
                'key' => 'alamat',
                'label' => 'Alamat',
                'type' => 'text',
                'required' => true,
                'help' => 'Alamat lengkap sesuai kolom Alamat pada tiket.',
                'example' => 'Jl. Merdeka No. 12 RT 02 RW 05',
            ],
            [
                'key' => 'rt',
                'label' => 'RT',
                'type' => 'text',
                'required' => false,
                'help' => 'Nomor RT. Boleh ditulis 002 atau 02.',
                'example' => '002',
            ],
            [
                'key' => 'rw',
                'label' => 'RW',
                'type' => 'text',
                'required' => false,
                'help' => 'Nomor RW.',
                'example' => '005',
            ],
            [
                'key' => 'nik',
                'label' => 'NIK',
                'type' => 'nik',
                'required' => false,
                'help' => 'NIK 16 digit. Jangan diketik sebagai angka; kolomnya sudah diformat teks.',
                'example' => '3273011503800001',
            ],
            [
                'key' => 'telepon',
                'label' => 'Telepon',
                'type' => 'phone',
                'required' => false,
                'help' => 'Nomor telepon atau WhatsApp pemohon.',
                'example' => '081234567890',
            ],
            [
                'key' => 'jenis_kelamin',
                'label' => 'Jenis Kelamin',
                'type' => 'enum',
                'required' => false,
                'options' => ['male', 'female'],
                'help' => 'male = laki-laki, female = perempuan. Boleh dikosongkan.',
                'example' => 'female',
            ],
            [
                'key' => 'tempat_lahir',
                'label' => 'Tempat Lahir',
                'type' => 'text',
                'required' => false,
                'help' => 'Kota/kabupaten tempat lahir.',
                'example' => 'Bandung',
            ],
            [
                'key' => 'tanggal_lahir',
                'label' => 'Tanggal Lahir',
                'type' => 'date',
                'required' => false,
                'help' => 'Format YYYY-MM-DD atau DD/MM/YYYY.',
                'example' => '1980-03-15',
            ],
            [
                'key' => 'tanggal_order',
                'label' => 'Tanggal Order',
                'type' => 'date',
                'required' => false,
                'help' => 'Tanggal permohonan dibuat. Kosongkan berarti hari ini.',
                'example' => '2026-02-01',
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'type' => 'enum',
                'required' => false,
                'options' => self::STATUS,
                'help' => 'Status tiket. Kosongkan berarti pending.',
                'example' => 'surveyed',
            ],
            [
                'key' => 'lattitude',
                'label' => 'Latitude',
                'type' => 'decimal',
                'required' => false,
                'help' => 'Koordinat lintang, contoh -6.2000000. Wajib diisi: kolomnya NOT NULL '
                    .'di database, jadi dikosongkan berarti 0, dan titik 0,0 bukan lokasi pelanggan.',
                'example' => '-6.2',
            ],
            [
                'key' => 'longitude',
                'label' => 'Longitude',
                'type' => 'decimal',
                'required' => false,
                'help' => 'Koordinat bujur, contoh 106.8166667.',
                'example' => '106.8166667',
            ],
        ];
    }

    public function tables(): array
    {
        return ['installation_tickets'];
    }

    public function mapRow(array $row, int $nomorUrut): array
    {
        $nama = CellValue::kosongkan($row['nama_pemohon'] ?? null);

        if ($nama === null) {
            throw MigrationRowException::kosong('Nama Pemohon');
        }

        $alamat = CellValue::kosongkan($row['alamat'] ?? null);

        if ($alamat === null) {
            throw MigrationRowException::kosong('Alamat');
        }

        $paket = $this->cariPaket($row['paket'] ?? null);
        $desa = $this->cariDesa($row['desa'] ?? null);

        $status = CellValue::kosongkan($row['status'] ?? null);
        $status = $status === null ? 'pending' : mb_strtolower($status);

        if (! in_array($status, self::STATUS, true)) {
            throw MigrationRowException::tidakDikenal('Status', $status, self::STATUS);
        }

        $jk = CellValue::kosongkan($row['jenis_kelamin'] ?? null);

        if ($jk !== null) {
            $jk = mb_strtolower($jk);

            if (in_array($jk, ['l', 'laki-laki', 'laki', 'pria', 'male'], true)) {
                $jk = 'male';
            } elseif (in_array($jk, ['p', 'perempuan', 'wanita', 'female'], true)) {
                $jk = 'female';
            } elseif (! in_array($jk, ['male', 'female'], true)) {
                throw MigrationRowException::tidakDikenal(
                    'Jenis Kelamin',
                    (string) $row['jenis_kelamin'],
                    ['male', 'female']
                );
            }
        }

        $nik = CellValue::nik($row['nik'] ?? null);

        if ($nik !== null && mb_strlen($nik) !== 16) {
            throw MigrationRowException::tidakValid(
                'NIK',
                $nik,
                'Harus 16 digit, contoh: 3273011503800001'
            );
        }

        $lat = CellValue::decimal($row['lattitude'] ?? null);
        $lng = CellValue::decimal($row['longitude'] ?? null);

        /*
         * Rentang valid untuk Indonesia, longgar di sisi timur/barat
         * supaya kepulauan yang lebih timur (Papua, 141) tidak tertolak.
         *
         * Rentang ini yang membuat koordinat yang terbalik tertangkap:
         * lat 106 dan lng -6 ditolak di sini dengan pesan yang menyebut
         * kolomnya -- bukan tersimpan dan baru terlihat salah saat petanya
         * dibuka.
         */
        if ($lat === null || $lat < -11 || $lat > 6) {
            throw MigrationRowException::tidakValid(
                'Latitude',
                CellValue::tampil($lat),
                'Harus antara -11 dan 6 (kawasan Indonesia), contoh -6.2'
            );
        }

        if ($lng === null || $lng < 94 || $lng > 142) {
            throw MigrationRowException::tidakValid(
                'Longitude',
                CellValue::tampil($lng),
                'Harus antara 94 dan 142 (kawasan Indonesia), contoh 106.8166667'
            );
        }

        $tanggalLahir = CellValue::tanggal($row['tanggal_lahir'] ?? null);
        $rawLahir = CellValue::kosongkan($row['tanggal_lahir'] ?? null);

        if ($rawLahir !== null && $tanggalLahir === null) {
            throw MigrationRowException::tidakValid(
                'Tanggal Lahir',
                $rawLahir,
                'Format yang dikenal: YYYY-MM-DD atau DD/MM/YYYY'
            );
        }

        $order = CellValue::tanggal($row['tanggal_order'] ?? null);
        $rawOrder = CellValue::kosongkan($row['tanggal_order'] ?? null);

        if ($rawOrder !== null && $order === null) {
            throw MigrationRowException::tidakValid(
                'Tanggal Order',
                $rawOrder,
                'Format yang dikenal: YYYY-MM-DD atau DD/MM/YYYY'
            );
        }

        return [
            'applicant_name' => $nama,
            'package_id' => $paket,
            'village_id' => $desa,
            'address' => $alamat,
            'rt' => CellValue::kosongkan($row['rt'] ?? null),
            'rw' => CellValue::kosongkan($row['rw'] ?? null),
            'nik' => $nik,
            'phone' => CellValue::telepon($row['telepon'] ?? null),
            'gender' => $jk,
            'birth_place' => CellValue::kosongkan($row['tempat_lahir'] ?? null),
            'birth_date' => $tanggalLahir,
            'order_date' => $order ?? now()->toDateString(),
            'status' => $status,
            'lat' => round((float) $lat, 7),
            'lng' => round((float) $lng, 7),
            '__kunci' => self::kunci($nama, $paket, $desa, $alamat),
        ];
    }

    public function existingIndex(): array
    {
        $peta = [];

        foreach (InstallationTicket::query()->get(['id', 'applicant_name', 'package_id', 'village_id', 'address']) as $t) {
            $peta[self::kunci($t->applicant_name, $t->package_id, $t->village_id, $t->address)] = (int) $t->id;
        }

        return $peta;
    }

    public function persist(array $data, array $konflik, int $nomorUrut): void
    {
        /*
         * `created_by` menunjuk ke user di LOKASI INI, dan kolomnya
         * NOT NULL.
         *
         * Tiket yang diimpor dibuat oleh Migrasi Data, bukan oleh admin
         * lokasi, jadi tidak ada user yang tepat untuk kolom ini. Yang
         * dipakai adalah admin pertama di lokasi; kalau lokasi belum punya
         * admin sama sekali, baris ini gagal dengan pesan yang menjelaskan --
         * bukan dengan error foreign key dari MySQL yang tidak menyebut
         * tabel mana.
         */
        $data['created_by'] = $this->adminId();

        /*
         * `__kunci` dan kunci internal lain bukan kolom database.
         *
         * Tanpa baris ini, `update($data)` di bawah mengirim SEMUA key
         * ke SQL, termasuk `__kunci`, dan MySQL menjawab "Unknown column
         * '__kunci' in 'field list'". Baris yang isinya benar ditolak
         * karena key internal, dengan pesan yang tidak menyebut kolom
         * mana pun yang bisa diperbaiki user.
         *
         * `create($data)` di bawah tidak akan salah seperti ini --
         * Eloquent menghormati `$fillable` dan diam-diam membuang key
         * asing. Itulah yang membuat bugnya lolos: jalur yang sama
         * berperilaku berbeda tergantung mana yang dipakai.
         */
        $data = self::tanpaCadangan($data);

        if (($konflik['id'] ?? null) !== null) {
            InstallationTicket::query()->whereKey($konflik['id'])->update($data);

            return;
        }

        InstallationTicket::query()->create($data);
    }

    /**
     * Paket dan desa yang boleh dipilih, dibaca dari lokasi.
     *
     * Ini daftar yang membuat template terasa dibuat untuk lokasi ini:
     * nama paket dan nama desa di sheet Referensi diambil dari database
     * lokasi, bukan dari daftar generik.
     *
     * @return array<string, array<int, string>>
     */
    public function references(): array
    {
        $paket = InstallationPackage::query()
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($n) => (string) $n)
            ->all();

        $desa = Village::query()
            ->orderBy('village_name')
            ->orderBy('hamlet_name')
            ->get(['village_name', 'hamlet_name'])
            ->map(static fn ($v) => trim(($v->village_name ?? '').' / '.($v->hamlet_name ?? '')))
            ->unique()
            ->values()
            ->all();

        $keluaran = [];

        if ($paket !== []) {
            $keluaran['Nama Paket'] = $paket;
        }

        if ($desa !== []) {
            $keluaran['Nama Desa / Dusun'] = $desa;
        }

        return $keluaran;
    }

    /* ================================================================
     | BANTUAN
     |============================================================
     */

    /**
     * Cache nama -> id, diisi sekali per proses.
     *
     * Tanpa cache, 5.000 baris berarti 5.000 query `LOWER(name) = ?`
     * terhadap tabel paket dan desa. Cache berlaku per-request, dan
     * `MigrationRegistry::semua()` membuat instance baru tiap
     * panggilan, jadi tidak ada state yang bocor antar-request.
     *
     * @var array<string, array<string, int>>
     */
    private array $cache = [];

    private function cariPaket(mixed $nilai): int
    {
        $nama = CellValue::kosongkan($nilai);

        if ($nama === null) {
            throw MigrationRowException::kosong('Nama Paket');
        }

        $kunci = mb_strtolower(trim($nama));

        if (isset($this->cache['paket'][$kunci])) {
            return $this->cache['paket'][$kunci];
        }

        $paket = InstallationPackage::query()
            ->whereRaw('LOWER(name) = ?', [$kunci])
            ->first();

        if ($paket === null) {
            $daftar = InstallationPackage::query()->orderBy('name')->pluck('name')->all();

            throw MigrationRowException::tidakDitemukan(
                'Paket',
                $nama,
                'di lokasi ini. Paket yang tersedia: '
                .($daftar === [] ? '(belum ada paket)' : implode(', ', $daftar)),
                'Nama Paket'
            );
        }

        return $this->cache['paket'][$kunci] = (int) $paket->id;
    }

    private function cariDesa(mixed $nilai): int
    {
        $nama = CellValue::kosongkan($nilai);

        if ($nama === null) {
            throw MigrationRowException::kosong('Desa');
        }

        $kunci = mb_strtolower(trim($nama));

        if (isset($this->cache['desa'][$kunci])) {
            return $this->cache['desa'][$kunci];
        }

        /*
         * Dua bentuk penulisan diterima, karena di sheet Referensi nama
         * dusun ikut ditulis -- dan user yang menyalin dari sana tidak
         * akan menghapus bagian dusunnya.
         *
         * Gabungan "desa / dusun" TIDAK ditulis sebagai `||` di SQL. Di
         * MySQL, `||` adalah "OR" yang logis, bukan penggabung string --
         * hasil query itu selalu benar, sehingga setiap desa di lokasi ini
         * cocok dengan nama apa pun. Gejalanya aneh: pesan errornya berbunyi
         * "Desa X tidak ditemukan" dan sekaligus "contoh yang tersedia: X",
         * karena keduanya menarik dari query yang sama. `CONCAT()` dipakai
         * karena itu benar-benar penggabung string.
         */
        $desa = Village::query()
            ->where(function ($q) use ($nama, $kunci) {
                $q->whereRaw('LOWER(village_name) = ?', [mb_strtolower(trim($nama))])
                    ->orWhereRaw('LOWER(hamlet_name) = ?', [mb_strtolower(trim($nama))])
                    ->orWhereRaw(
                        'LOWER(CONCAT(TRIM(village_name), \' / \', TRIM(hamlet_name))) = ?',
                        [$kunci]
                    );
            })
            ->first();

        if ($desa === null) {
            $daftar = Village::query()
                ->orderBy('village_name')
                ->limit(20)
                ->get(['village_name', 'hamlet_name'])
                ->map(static fn ($v) => trim(($v->village_name ?? '').' / '.($v->hamlet_name ?? '')))
                ->all();

            throw MigrationRowException::tidakDitemukan(
                'Desa',
                $nama,
                'di lokasi ini. Contoh yang tersedia: '
                .($daftar === [] ? '(belum ada desa -- migrasikan desa dulu)' : implode(', ', $daftar)),
                'Desa'
            );
        }

        return $this->cache['desa'][$kunci] = (int) $desa->id;
    }

    /** Admin pertama di lokasi, untuk `installation_tickets.created_by`. */
    private function adminId(): int
    {
        if (isset($this->cache['admin'])) {
            return $this->cache['admin'];
        }

        $admin = User::query()->where('role', 'admin')->orderBy('id')->first();

        if ($admin === null) {
            throw MigrationRowException::kosong(
                'Admin Lokasi (tidak ada user role=admin di lokasi ini)'
            );
        }

        return $this->cache['admin'] = (int) $admin->id;
    }

    /**
     * Kunci alami: pemohon + paket + desa + alamat.
     *
     * Nama pemohon saja tidak cukup -- satu orang bisa punya lebih dari
     * satu tiket (mis. sambungan kedua di tahun berikutnya), dan nama +
     * desa + paket + alamat adalah kombinasi yang mengidentifikasi satu
     * permohonan tanpa perlu kolom ID yang tidak ada di template.
     */
    private static function kunci(?string $nama, mixed $paket, mixed $desa, ?string $alamat): string
    {
        return mb_strtolower(trim((string) $nama))
            .'|'.(int) $paket
            .'|'.(int) $desa
            .'|'.mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $alamat)) ?? '');
    }
}
