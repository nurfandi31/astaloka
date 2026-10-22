<?php

namespace App\Services\Migration;

/**
 * Deskripsi SATU jenis data yang bisa dimigrasi ke sebuah lokasi.
 *
 * Setiap entri di {@see MigrationRegistry} adalah
 * satu implementasi dari kontrak ini. Kontraknya sengaja dibuat SATU
 * METHOD, bukan interface besar: setiap jenis data punya kolom, aturan, dan
 * cara menulisnya sendiri, dan memaksakan satu method untuk semuanya hanya
 * menghasilkan implementasi yang sebagian besar method-nya tidak punya isi.
 *
 * Kontrak ini tidak berubah STARU: `TemplateGenerator` dan `SpreadsheetReader`
 * bekerja dengan bentuk yang sama untuk semua jenis data, jadi menambah
 * jenis data baru tidak menyentuh kode yang sudah ada -- hanya menambah
 * kelas baru dan mendaftarkannya di registry.
 */
interface MigrationBlueprint
{
    /**
     * Key teknis jenis data ini, mis. `villages`.
     *
     * Dipakai di URL (`/central/migrasi/{type}/...`) dan jadi id `<meta>`
     * di frontend. WAJIB stabil: mengubahnya membuat tautan lama mati dan
     * membatalkan batch yang sedang berjalan -- karena batch yang disimpan
     * menyimpan `type` ini sebagai teks.
     */
    public function type(): string;

    /** Label Bahasa Indonesia untuk ditampilkan di UI. */
    public function label(): string;

    /** Satu kalimat yang menjelaskan fungsi datanya, untuk kartu di UI. */
    public function description(): string;

    /**
     * Ikon FontAwesome untuk kartu di UI.
     *
     * Harus sudah terdaftar di `library.add()` `main.js`. Ikon yang tidak
     * terdaftar merender kosong tanpa error di console -- jadi kalau halaman
     * menampilkan kotak kosong, daftar ini tidak kompatibel dengan main.js.
     */
    public function icon(): string;

    /** Urutan tampil di daftar jenis migrasi. Angka kecil di atas. */
    public function order(): int;

    /**
     * Nama sheet di file .xlsx, DAN baris pertama yang dibaca sebagai header.
     *
     * CSV tidak punya sheet, jadi untuk CSV nilai ini dipakai sebagai nama
     * sheet yang dipakai saat menghasilkan template. Panjang minimum 31
     * karakter dan tidak boleh mengandung `\ / ? * [ ]` -- dibatasi di
     * {@see TemplateGenerator}.
     */
    public function sheetName(): string;

    /**
     * Definisi kolom template.
     *
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     type: 'text'|'number'|'decimal'|'integer'|'date'|'email'|'enum'|'phone'|'nik',
     *     required: bool,
     *     help: string,
     *     example: string,
     *     options?: array<int, string>,
     *     max?: int
     * }>
     */
    public function columns(): array;

    /**
     * Tabel yang dibaca dan ditulis di database LOKASI.
     *
     * Dipakai untuk pesan error yang jujur: "tabel `villages` belum ada di
     * lokasi ini" jauh lebih berguna daripada MySQL yang hanya menyebut
     * nama database.
     *
     * @return array<int, string>
     */
    public function tables(): array;

    /**
     * Petakan satu baris dari spreadsheet ke nilai siap-simpan.
     *
     * Dijalankan DI DALAM `runFor()`, jadi seluruh model dan query builder
     * di sini membaca database lokasi. `Jabatan`, `Village`, dan
     * `InstallationPackage` tidak mengunci `$connection` -- tanpa penjaga
     * dari pemanggil, semuanya diam-diam akan membaca database pusat.
     *
     * Nilai `$row` sudah berupa string (dari spreadsheet) dan kuncinya sudah
     * berupa nama kolom template (`key`), bukan header asli di file.
     *
     * KUNCI BERAWALAN `__` DIHAPUSKAN oleh pipeline sebelum hasilnya
     * dipakai, jadi nilai balik boleh memakainya untuk menandai hal
     * internal (lihat `MigrationBlueprint::KUNCI_CADANGAN`). Yang TIDAK
     * boleh ada adalah key lain yang bukan nama kolom database.
     *
     * @param  array<string, string|null>  $row
     * @param  int  $nomorUrut  Baris ke berapa di file, untuk pesan error
     * @return array<string, mixed> Kolom database => nilai, sudah tervalidasi
     *
     * @throws MigrationRowException kalau baris ini tidak bisa diproses
     */
    public function mapRow(array $row, int $nomorUrut): array;

    /**
     * Kunci alami untuk menentukan "baris ini sudah ada" saat mode update.
     *
     * Dikembalikan sebagai `nilai => id_yang_sudah_ada`, di-cache sekali di
     * awal proses -- BUKAN satu query per baris. Kalau implementasi
     * membutuhkan query yang tidak bisa di-cache, kembalikan `[]` dan
     * escribah: setiap baris akan dianggap baru.
     *
     * @return array<string, int|array{0: int}> hash => id baris yang ada
     */
    public function existingIndex(): array;

    /**
     * Tulis satu baris ke database lokasi.
     *
     * Dijalankan di dalam savepoint per baris, jadi melempar
     * {@see MigrationRowException} membatalkan HANYA baris itu --
     * termasuk tulisan yang sudah terlanjur dilakukan untuk baris itu
     * di tabel lain.
     *
     * Yang tidak boleh dilakukan: melempar exception lain. Yang tidak
     * dikenal pipeline dianggap "sistemnya yang salah", dan itu membuat
     * seluruh blok 50 baris dibatalkan -- satu baris rusak tidak boleh
     * menghapus 49 baris yang tidak salah.
     *
     * CARA AMAN MENULIS: pakai {@see tanpaCadangan()} sebelum meneruskan
     * `$data` utuh ke `update()`/`create()`. Menyusun kolom satu per satu
     * juga boleh, tapi menggandakan nama kolom di dua tempat -- dan saat
     * ada kolom baru, tabel yang lupa diperbarui adalah hasil paling
     * diam-diam: nilainya tidak masuk database, tanpa error.
     *
     * @param  array<string, mixed>  $data  Hasil dari {@see mapRow()}
     * @param  array<string, mixed>  $konflik  `id` + `nilai` dari
     *                                         {@see existingIndex()}
     */
    public function persist(array $data, array $konflik, int $nomorUrut): void;

    /**
     * Awalan key yang dipakai pipeline, bukan kolom database.
     *
     * Setiap key yang dimulai dua garis bawah dicabut dari `$data`
     * sebelum `persist()` dipanggil. Jadi `__kunci` (penanda baris),
     * `__sudah_ada`, `__tanpa_biaya`, dan `__kode_lama` hanya hidup
     * selama validasi.
     *
     * Kenapa dicabut, dan bukan sekadar diabaikan:
     *
     * - `$model->update($data)` memakai Eloquent dan menghormati
     *   `$fillable`, jadi `__kunci` diabaikan diam-diam. Tidak ada
     *   error, dan hasilnya benar.
     * - `$query->update($data)` memakai query builder yang TIDAK
     *   menghormati `$fillable` -- semua key ikut jadi SQL. Kalau tabel
     *   yang sama ditulis lewat dua jalur berbeda, hasilnya tidak
     *   konsisten antara keduanya.
     *
     * Yang terjadi sebelum ada aturan ini:
     * `update installation_tickets set ... , __kunci = agus salim|1|1`
     * dan MySQL menjawab "Unknown column '__kunci' in 'field list'".
     * Baris yang sebenarnya tidak salah ditolak karena key internal,
     * dan pesan yang muncul ke user tidak menyebut kolom mana pun yang
     * bisa diperbaiki oleh user itu sendiri.
     */
    public const KUNCI_CADANGAN = '__';

    /**
     * Buang semua key internal, sisakan yang memang kolom database.
     *
     * Dipakai blueprint yang meneruskan `$data` utuh ke Eloquent atau
     * ke query builder.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function tanpaCadangan(array $data): array;
}
