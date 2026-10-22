<?php

namespace App\Services\Migration\Blueprints;

use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\User;
use App\Models\Village;
use App\Services\Migration\CellValue;
use App\Services\Migration\MigrationBlueprint;
use App\Services\Migration\MigrationBlueprintConcerns;
use App\Services\Migration\MigrationRowException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Migrasi pelanggan aktif.
 *
 * TIGA TABEL, SATU TRANSAKSI
 * ==========================
 *
 * Satu baris di sini menulis tiga baris di tiga tabel:
 *
 *   `users`                   akun login (role=pelanggan)
 *   `installation_tickets`    permohonan tempat pelanggan ini arose
 *   `customers`               data pelanggan dan kode pelanggan
 *
 * Semuanya ditulis dalam satu transaksi per-batch, jadi tidak mungkin
 * ada akun tanpa tiket atau tiket tanpa pelanggan. Itu bukan sekadar
 * kebersihan data: aplikasi membuka halaman pelanggan lewat
 * `customers.ticket_id`, dan `ticket_id` NOT NULL -- jadi separuh
 * penulisan menghasilkan baris yang tidak bisa dibuka sama sekali.
 *
 * MENGAPA TIDAK PAKAI `InstallationTicketBlueprint` DI SINI
 * ======================================================
 *
 * `InstallationTicketBlueprint` sengaja tidak membuat akun.URN
 * Perbedaannya: InstallationTicketBlueprint dipakai untuk memindahkan
 * tiket yang pemohonnya sudah punya akun. Di sini kebalikannya: satu
 * baris harus membuat semuanya sekaligus, jadi email WAJIB diisi.
 *
 * EMAIL DARI NAMA, BUKAN DIAM-DIAM DIBUAT
 * =======================================
 *
 * Kalau email dikosongkan lalu dibuat dari nama
 * (`budi.santoso@gmail.com`), hasil yang sama akan menabrak constraint
 * unik pada baris kedua dengan nama yang sama -- dan pesan error-nya
 * berbunyi "Gagal menyimpan batch: Integrity constraint violation"
 * yang tidak menyebut email mana yang bentrok. Jadi kolomnya wajib,
 * dan errornya menyebut email itu.
 *
 * KODE PELANGGAN
 * ==============
 *
 * Kalau dikosongkan, kode dibuat dari desa lewat
 * `Village::generateNextCustomerCode()`, sama seperti saat pendaftaran
 * dari aplikasi. Kalau diisi, kode itu dipakai apa adanya -- tapi
 * hanya kalau belum dipakai baris lain. `customer_code` unik, dan kode
 * bentrok tidak boleh diperbaiki diam-diam karena kode itu tercetak di
 * tagihan yang sudah keluar.
 */
class CustomerBlueprint implements MigrationBlueprint
{
    use MigrationBlueprintConcerns;

    public function type(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Pelanggan';
    }

    public function description(): string
    {
        return 'Pelanggan aktif lengkap dengan akun login, tiket, dan kode'
            .'pelanggannya. Satu baris menulis tiga tabel sekaligus.';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function order(): int
    {
        return 40;
    }

    public function sheetName(): string
    {
        return 'Pelanggan';
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'kode',
                'label' => 'Kode Pelanggan',
                'type' => 'text',
                'required' => false,
                'help' => 'Kosongkan untuk dibuat otomatis dari desa. Kalau diisi harus unik '
                    .'dan belum dipakai pelanggan lain di lokasi ini.',
                'example' => '005.0001.100.1',
            ],
            [
                'key' => 'nama',
                'label' => 'Nama Pelanggan',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama lengkap sesuai identitas. Maksimal 255 karakter.',
                'example' => 'Siti Rahayu',
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
                'help' => 'Email untuk login pelanggan. Harus unik di lokasi ini. '
                    .'Boleh dibuat dari nama, misalnya siti.rahayu@gmail.com.',
                'example' => 'siti.rahayu@gmail.com',
            ],
            [
                'key' => 'telepon',
                'label' => 'Telepon',
                'type' => 'phone',
                'required' => false,
                'help' => 'Nomor telepon atau WhatsApp pelanggan.',
                'example' => '081234567890',
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
                'key' => 'desa',
                'label' => 'Desa',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama desa. Kalau ada beberapa dusun dengan nama desa sama, '
                    .'tulis "Desa / Dusun". Lihat sheet "Referensi".',
                'example' => 'Sukamaju / Dusun 1',
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
                'key' => 'alamat',
                'label' => 'Alamat',
                'type' => 'text',
                'required' => true,
                'help' => 'Alamat lengkap sesuai kolom Alamat pada tiket.',
                'example' => 'Jl. Merdeka No. 12 RT 02 RW 05',
            ],
            [
                'key' => 'meter_awal',
                'label' => 'Meter Awal',
                'type' => 'decimal',
                'required' => false,
                'help' => 'Angka meter saat pelanggan dipasang. Kosongkan berarti 0.',
                'example' => '0',
            ],
            [
                'key' => 'tanggal_aktif',
                'label' => 'Tanggal Aktif',
                'type' => 'date',
                'required' => false,
                'help' => 'Tanggal mulai dihitung tagihan. Kosongkan berarti hari ini.',
                'example' => '2026-02-01',
            ],
            [
                'key' => 'sandi',
                'label' => 'Kata Sandi',
                'type' => 'text',
                'required' => false,
                'help' => 'Boleh dikosongkan. Kalau kosong, akun dibuat dengan kata sandi '
                    .'"password" dan wajib diganti saat login pertama.',
                'example' => '',
            ],
        ];
    }

    public function tables(): array
    {
        return ['users', 'installation_tickets', 'customers'];
    }

    public function mapRow(array $row, int $nomorUrut): array
    {
        $nama = CellValue::kosongkan($row['nama'] ?? null);

        if ($nama === null) {
            throw MigrationRowException::kosong('Nama Pelanggan');
        }

        if (mb_strlen($nama) > 255) {
            throw MigrationRowException::tidakValid('Nama Pelanggan', $nama, 'Maksimal 255 karakter');
        }

        $email = CellValue::email($row['email'] ?? null);

        if ($email === null) {
            $raw = CellValue::kosongkan($row['email'] ?? null);

            throw $raw === null
                ? MigrationRowException::kosong('Email')
                : MigrationRowException::tidakValid(
                    'Email',
                    $raw,
                    'Format email tidak dikenali, contoh: nama@gmail.com'
                );
        }

        $alamat = CellValue::kosongkan($row['alamat'] ?? null);

        if ($alamat === null) {
            throw MigrationRowException::kosong('Alamat');
        }

        $paketId = $this->cariPaket($row['paket'] ?? null);
        $desaId = $this->cariDesa($row['desa'] ?? null);

        $kode = CellValue::kosongkan($row['kode'] ?? null);

        if ($kode !== null && mb_strlen($kode) > 100) {
            throw MigrationRowException::tidakValid('Kode Pelanggan', $kode, 'Maksimal 100 karakter');
        }

        $nik = CellValue::nik($row['nik'] ?? null);

        if ($nik !== null && mb_strlen($nik) !== 16) {
            throw MigrationRowException::tidakValid(
                'NIK',
                $nik,
                'Harus 16 digit, contoh: 3273011503800001'
            );
        }

        $aktif = CellValue::tanggal($row['tanggal_aktif'] ?? null);
        $rawAktif = CellValue::kosongkan($row['tanggal_aktif'] ?? null);

        if ($rawAktif !== null && $aktif === null) {
            throw MigrationRowException::tidakValid(
                'Tanggal Aktif',
                $rawAktif,
                'Format yang dikenal: YYYY-MM-DD atau DD/MM/YYYY'
            );
        }

        $meter = CellValue::decimal($row['meter_awal'] ?? null) ?? 0.0;

        if ($meter < 0) {
            throw MigrationRowException::tidakValid(
                'Meter Awal',
                CellValue::tampil($meter),
                'Tidak boleh negatif'
            );
        }

        return [
            'name' => $nama,
            'email' => $email,
            'customer_code' => $kode,
            'phone' => CellValue::telepon($row['telepon'] ?? null),
            'nik' => $nik,
            'address' => $alamat,
            'package_id' => $paketId,
            'village_id' => $desaId,
            'initial_meter_reading' => round($meter, 2),
            'activated_at' => $aktif ?? now()->startOfDay()->toDateTimeString(),
            'password' => $this->sandi($row['sandi'] ?? null),
            // Koordinat: lihat catatan pada `InstallationTicketBlueprint`.
            // 0,0 berarti Gulf of Guinea, bukan lokasi pelanggan.
            'lat' => 0.0,
            'lng' => 0.0,
            '__kunci' => $email,
        ];
    }

    public function existingIndex(): array
    {
        /*
         * Indeks dikunci oleh EMAIL, dan itu harus sama persis dengan
         * `__kunci` yang dikembalikan `mapRow()`.
         *
         * Versi sebelumnya memakai dua bentuk kunci sekaligus --
         * `user:<id>` dan `email:<alamat>` -- sehingga tidak ada yang
         * cocok: `mapRow()` mengembalikan email polos, dan pipeline
         * mencari email polos itu di peta yang hanya berisi
         * `user:3` dan `email:agus@uji.test`. Hasilnya tidak salah
         * secara teknis, tapi salah secara diam-diam: mengimpor file
         * yang sama dua kali membuat pelanggan DUPLIKAT, bukan
         * memperbarui yang lama -- dan `users.email` yang unik
         * menggagalkan seluruh batch dengan pesan "Duplicate entry"
         * yang tidak menyebut email mana.
         *
         * Akun tanpa baris `customers` sengaja TIDAK masuk peta.
         * Pelanggan yang belum selesai/tagihan belum ada, dan
         * menulis ulang baris `customers`-nya dari sini berarti
         * menciptakan pelanggan yang tidak pernah ada.
         *
         * Akun STAFF juga tidak masuk peta. Kalau email-nya dipakai
         * akun staff, `persist()` akan menolaknya dengan pesan yang
         * menyebut perannya -- lebih baik daripada diam-diam
         * mengubah peran staff menjadi pelanggan.
         */
        $peta = [];

        /*
         * Satu query, bukan N+1.
         *
         * `foreach (Customer) { User::whereKey(...)->value(...) }`
         * berarti satu query per pelanggan yang sudah ada. Untuk lokasi
         * dengan 2.000 pelanggan, itu 2.000 query -- dan seluruhnya
         * dijalankan di dalam `runFor()` pada koneksi database lokasi,
         * jadi satu permintaan unduh template bisa menahan koneksi itu
         * selama belasan detik hanya untuk membaca daftar yang bisa
         * dijawab dalam satu query.
         *
         * Join yang dipakai memfilter `users.role` di dalam SQL, bukan
         * setelahnya -- kalau tidak, akun staff yang kebetulan punya
         * baris `customers` akan tetap masuk daftar.
         */
        $baris = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->where('users.role', 'pelanggan')
            ->get(['customers.id as customer_id', 'users.email']);

        foreach ($baris as $satu) {
            $peta[mb_strtolower(trim((string) $satu->email))] = (int) $satu->customer_id;
        }

        return $peta;
    }

    public function persist(array $data, array $konflik, int $nomorUrut): void
    {
        $paket = InstallationPackage::findOrFail((int) $data['package_id']);
        $desa = Village::findOrFail((int) $data['village_id']);

        /*
         * `created_by` menunjuk ke user di LOKASI INI, dan kolomnya
         * NOT NULL.
         *
         * Tiket yang diimpor dibuat oleh Migrasi Data, bukan oleh
         * admin lokasi, jadi tidak ada user yang tepat untuk kolom ini.
         * Yang dipakai adalah admin pertama di lokasi; kalau lokasi
         * belum punya admin sama sekali, baris ini gagal dengan pesan
         * yang menjelaskan -- bukan dengan error foreign key dari
         * MySQL yang tidak menyebut tabel mana.
         */
        $data['created_by'] = $this->adminId();

        /*
         * Kode pelanggan yang sudah jadi milik baris INI, bukan milik
         * pelanggan lain.
         *
         * `existingIndex()` mengembalikan id baris `customers` yang akan
         * existingIndex() mengembalikan id baris customers yang akan
         * duplikat, mengimpor file yang sama dua kali akan selalu gagal
         * di baris kode pelanggan -- karena kodenya memang masih
         * tercatat, milik dirinya sendiri. Pesan errornya jujur
         * ("sudah dipakai pelanggan lain") tapi salah sasaran, dan
         * hasil akhirnya "tidak ada yang bisa diimpor" untuk file yang
         * sebenarnya sudah benar.
         */
        $data['__sudah_ada'] = (int) ($konflik['id'] ?? 0);

        /*
         * Kode yang sudah tercetak, untuk baris yang sedang diperbarui.
         *
         * Dibaca di sini, bukan di `existingIndex()`, supaya indeks
         * validasi tetap polos (email -> id) dan tidak jadi tempat
         * kedua yang harus tahu bentuk data ini.
         */
        $data['__kode_lama'] = $data['__sudah_ada'] === 0
            ? null
            : DB::table('customers')
                ->where('id', $data['__sudah_ada'])
                ->value('customer_code');

        /*
         * Tiga hal ditulis, berurutan, karena saling menunjuk:
         *
         *   `users`                tanpa `ticket_id`
         *   `installation_tickets` tanpa `user_id` di bawah
         *   `customers`            menunjuk ke keduanya
         *
         * Akun dibuat lebih dulu karena `customers.user_id` menunjuk
         * ke sana, dan menunjuk ke id yang belum ada akan ditolak
         * foreign key.
         */
        $userId = $this->pakaiAtauBuatUser($data);

        $kode = $this->pakaiAtauBuatKode($data, $desa);

        /*
         * Tiket dibuat atau dipakai ulang lewat kecocokan
         * pemohon+paket+desa+alamat -- sama persis kuncinya dengan
         * `InstallationTicketBlueprint`, jadi tiket yang sudah ada dari
         * migrasi tiket tidak diduplikasi oleh migrasi pelanggan.
         */
        $ticketId = $this->pakaiAtauBuatTicket($data, $userId, $paket, $desa);

        $isidata = [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'customer_code' => $kode,
            'initial_meter_reading' => $data['initial_meter_reading'],
            'meter_photo_url' => null,
            'activated_at' => $data['activated_at'],
        ];

        $id = $konflik['id'] ?? null;

        if ($id !== null) {
            DB::table('customers')->where('id', $id)->update([...$isidata, 'updated_at' => now()]);

            return;
        }

        DB::table('customers')->insert([...$isidata, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Paket, desa, dan kode yang sudah terpakai.
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

    /** @var array<string, array<string, int>> */
    private array $cache = [];

    /**
     * ID akun untuk baris ini, dibuat kalau belum ada.
     *
     * Akun yang email-nya sudah dipakai DICARI, bukan dibuat baru --
     * `users.email` unik, dan membuat duplikat akan menggagalkan seluruh
     * batch. Kalau email-nya dipakai akun yang bukan pelanggan (staff),
     * baris ini ditolak dengan pesan yang menyebutnya, karena memakai
     * akun staff sebagai login pelanggan akan mengubah perannya dan
     * kehilangan akses ke fiturnya sendiri, tanpa ada yang memberi tahu.
     */
    private function pakaiAtauBuatUser(array $data): int
    {
        $email = $data['email'];

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            if ($user->role !== 'pelanggan') {
                throw new MigrationRowException(
                    "Email \"{$email}\" sudah dipakai akun dengan peran "
                    ."\"{$user->role}\". Akun staff tidak bisa dipakai sebagai akun pelanggan.",
                    ['Email'],
                    'EMAIL_DIPAKAI'
                );
            }

            /*
             * Akun yang sudah ada berarti baris ini adalah pembaruan.
             * Kolomnya sudah ditulis ulang oleh `persist()` lewat jalur
             * pembaruan, jadi menuliskannya di sini hanya menggandakan
             * pekerjaan -- bukan menjaganya.
             */
            return (int) $user->id;
        }

        $id = DB::table('users')->insertGetId([
            'name' => $data['name'],
            'email' => $email,
            // Hash dihitung saat validasi, jadi commit tidak perlu
            // menunggu bcrypt 50 ms per baris.
            'password' => $data['password'],
            'role' => 'pelanggan',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Kode pelanggan: dari file, atau dibuat dari desa.
     *
     * Kode dari file yang sudah terpakai ditolak, bukan diganti
     * otomatis. Kode pelanggan tercetak di tagihan yang sudah keluar,
     * jadi kode berbeda di laporan dan di tagihan adalah masalah yang
     * jauh lebih sulit diperbaiki daripada baris yang ditolak dengan
     * pesan yang jelas.
     */
    private function pakaiAtauBuatKode(array $data, Village $desa): string
    {
        $kode = $data['customer_code'];

        if ($kode !== null) {
            $ada = DB::table('customers')
                ->where('customer_code', $kode)
                ->where('id', '!=', $data['__sudah_ada'] ?? 0)
                ->exists();

            if ($ada) {
                throw new MigrationRowException(
                    "Kode Pelanggan \"{$kode}\" sudah dipakai pelanggan lain di lokasi ini. "
                    .'Kosongkan kolomnya untuk membiarkan sistem membuat kode baru.',
                    ['Kode Pelanggan'],
                    'DUPLIKAT'
                );
            }

            return $kode;
        }

        /*
         * Pelanggan yang sudah punya baris TIDAK mendapat kode baru.
         *
         * `generateNextCustomerCode()` selalu menambah penghitung,
         * jadi mengimpor file yang sama dua kali akan mengubah kode
         * pelanggan dari ...100.1 menjadi ...100.6. Pelanggan,
         * datanya, dan semua yang lain sama -- kodenya berbeda saja,
         * tercetak di tagihan yang sudah keluar. Laporan bulanan
         * lalu tidak cocok dengan tagihan.
         *
         * Kode lama dipertahankan: pembaruan tidak mengubah identitas
         * pelanggan, hanya memperbarui datanya.
         */
        $lama = $data['__kode_lama'] ?? null;

        if ($lama !== null) {
            return (string) $lama;
        }

        return $desa->generateNextCustomerCode();
    }

    /**
     * ID tiket, dibuat kalau belum ada.
     *
     * Mencari dulu tiket yang kecocokannya sama persis dengan
     * `InstallationTicketBlueprint`, supaya mengimpor tiket lalu
     * pelanggan dari file berbeda tidak menghasilkan dua tiket untuk
     * satu orang.
     */
    private function pakaiAtauBuatTicket(array $data, int $userId, InstallationPackage $paket, Village $desa): int
    {
        $cocok = DB::table('installation_tickets')
            ->whereRaw('LOWER(applicant_name) = ?', [mb_strtolower($data['name'])])
            ->where('package_id', $paket->id)
            ->where('village_id', $desa->id)
            ->whereRaw('LOWER(address) = ?', [mb_strtolower($data['address'])])
            ->orderBy('id')
            ->first();

        if ($cocok !== null) {
            return (int) $cocok->id;
        }

        $admin = $this->adminId();

        return DB::table('installation_tickets')->insertGetId([
            'package_id' => $paket->id,
            'user_id' => null,
            'applicant_name' => $data['name'],
            'order_date' => now()->toDateString(),
            'nik' => $data['nik'],
            'rt' => null,
            'rw' => null,
            'phone' => $data['phone'],
            'gender' => null,
            'birth_place' => null,
            'birth_date' => null,
            'address' => $data['address'],
            'village_id' => $desa->id,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'status' => 'completed',
            'created_by' => $admin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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

        $paket = InstallationPackage::query()->whereRaw('LOWER(name) = ?', [$kunci])->first();

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
         * Gabungan "desa / dusun" TIDAK ditulis sebagai `||` di SQL.
         * Di MySQL, `||` adalah "OR" yang logis, bukan penggabung
         * string -- hasil query itu selalu benar, sehingga setiap
         * desa di lokasi ini cocok dengan nama apa pun. Gejalanya
         * aneh: pesan errornya berbunyi "Desa X tidak ditemukan" dan
         * sekaligus "contoh yang tersedia: X", karena keduanya
         * menarik dari query yang sama. `CONCAT()` dipakai karena itu
         * benar-benar penggabung string.
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

    private function adminId(): int
    {
        if (isset($this->cache['admin'])) {
            return $this->cache['admin'];
        }

        $admin = User::query()->where('role', 'admin')->orderBy('id')->first();

        if ($admin === null) {
            throw new MigrationRowException(
                'Tidak ada user dengan peran admin di lokasi ini. '
                .'Tiket yang dibuat migrasi butuh pembuat yang valid -- '
                .'migrasikan Akun Staff Lokasi lebih dulu.',
                [],
                'ADMIN_TIDAK_ADA'
            );
        }

        return $this->cache['admin'] = (int) $admin->id;
    }

    /** @see UserBlueprint::sandi() -- aturan yang sama, diulang karena kelas ini tidak bergantung padanya. */
    private function sandi(mixed $isi): string
    {
        $teks = CellValue::kosongkan($isi);

        if ($teks === null) {
            return Hash::make('password');
        }

        $teks = (string) $teks;

        if (str_starts_with($teks, '$2y$')
            || str_starts_with($teks, '$2a$')
            || str_starts_with($teks, '$argon2')) {
            return $teks;
        }

        return Hash::make($teks);
    }
}
