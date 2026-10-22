<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening penampung pembayaran tagihan langganan milik ASTA BRATA.
 *
 * Kenapa tabel terpisah di database PUSAT, dan bukan memakai `accounts`
 * yang sudah ada di tiap database lokasi:
 *
 *   1. Uangnya masuk ke kas ASTA BRATA, bukan ke kas lokasi. `accounts`
 *      milik lokasi dipakai untuk mencatat jurnal lokasi itu sendiri;
 *      mencampur uang masuk ASTA ke sana membuat laporan lokasi ikut
 *      memuat pendapatan yang bukan miliknya.
 *
 *   2. `accounts` tidak ada di database pusat sama sekali -- hanya
 *      `akun_level_1/2/3` yang ada, dan itu kerangka CoA milik SEMUA
 *      lokasi, bukan milik pusat. Mengambil "Kas di Bank BRI" dari sana
 *      berarti membaca kerangka milik orang lain untuk mencatat uang
 *      milik sendiri.
 *
 * Bentuk isinya sengaja mengikuti `accounts` di database lokasi
 * (`kode_akun`, `nama_akun`, `jenis_mutasi`) supaya orang yang sudah
 * terbiasa dengan akun di aplikasi lokasi mengenali istilahnya di sini,
 * dan supaya nama bank yang dipakai di panel pusat sama dengan yang
 * tercetak di rekening bank aslinya.
 *
 * Yang TIDAK ada di sini: `parent_id`. Akun ini hanya berlevel satu --
 * tidak ada hierarki "kas turun ke bank" seperti di `akun_level_1..3`.
 * Menambah hierarki karena "nanti butuh" menghasilkan tabel yang harus
 * dimigrasikan lagi saat hierarki itu benar-benar dipakai.
 *
 * `is_active`, bukan kolom tanggal nonaktif seperti di `accounts` lokasi.
 * Yang dipakai panel pusat selalu satu pertanyaan: "apakah rekening ini
 * masih boleh dipilih?" Satu boolean menjawabnya langsung. Tanggal
 * nonaktif memaksa setiap pembaca memeriksa `tgl_nonaktif >= today()` dan
 * harus menyimpan NULL secara terpisah untuk "tidak pernah dinonaktifkan"
 * -- dua keadaan yang bedanya baru terlihat kalau dibaca.
 *
 * Baris yang pernah dipakai pembayaran TIDAK dihapus:
 * `tenant_invoice_payments.account_id` menunjuk baris ini, jadi menghapus
 * barisnya membuat riwayat pembayaran menunjuk rekening yang tidak ada --
 * dan transaksi uang yang sudah masuk menjadi tidak bisa ditelusuri.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = config('tenancy.central_connection');

        if (Schema::connection($central)->hasTable('central_accounts')) {
            return;
        }

        Schema::connection($central)->create('central_accounts', function (Blueprint $table) {
            $table->id();

            /*
             * `kode_akun` unik, bukan sekadar index.
             *
             * Kode ini yang dibaca orang saat mencocokkan mutasi dengan
             * rekening bank aslinya, jadi dua akun dengan kode sama bukan
             * "duplikat yang tidak masalah" -- yang salah satu akan
             * terlihat seperti rekening yang tidak terdaftar. Unik di
             * sini membuat penolakan terjadi di saat yang salah memasukkan
             * kode, bukan tiga bulan kemudian saat rekonsiliasi.
             */
            $table->string('kode_akun', 10)->unique();
            $table->string('nama_akun', 100);

            /*
             * Kategori HANYA dua: `tunai` dan `bank`.
             *
             * Ini bukan field bebas. Form pembayaran memakainya untuk
             * menentukan pertanyaan mana yang muncul: kalau `tunai`, tidak
             * ada pertanyaan rekening; kalau `bank`, rekening wajib
             * dipilih. Field bebas (mis. "e-wallet" atau "kartu debit")
             * akan membuat form menampilkan rekening wajib untuk kategori
             * yang sebenarnya tidak punya rekening -- dan jawaban yang
             * tersimpan tidak bisa dibaca form mana pun.
             *
             * ENUM MySQL, bukan tabel referensi: daftar ini tidak akan
             * berubah, dan menambah tabel yang isinya cuma dua baris hanya
             * menambah satu query di setiap pembacaan saldo.
             */
            $table->enum('kategori', ['tunai', 'bank'])->default('bank');

            /*
             * `jenis_mutasi` disimpan, tapi tidak dipakai menghitung
             * apa pun di sini.
             *
             * Saldo rekening pusat dihitung dari
             * `tenant_invoice_payments` (jumlah yang benar-benar
             * diterima), bukan dari neraca -- jadi kolom ini tidak
             * memengaruhi angka. Yang membuatnya tetap berguna: nama dan
             * jenis mutasi sebuah akun adalah bagian dari bahasa
             * akuntansi yang dipakai orang, dan tanpa kolom ini "Kas
             * Tunai" di sini hanya sekumpulan teks bebas tanpa penjelasan
             * bahwa itu akun aset.
             */
            $table->string('jenis_mutasi', 6)->default('debet');

            $table->boolean('is_active')->default(true);

            /*
             * Nomor rekening dan atas nama.
             *
             * Nullable, bukan wajib, karena tidak semua rekening punya
             * data yang layak dicetak. Form pembayaran menampilkan nomor
             * rekening hanya kalau ada -- yang kosong tidak boleh
             * terlihat sebagai "tidak diisi", karena orang yang
             * handcari transfer membaca nomor rekening sebagai
             * instruksi, bukan sebagai field yang boleh kosong.
             */
            $table->string('no_rekening', 50)->nullable();
            $table->string('atas_nama', 100)->nullable();

            $table->string('catatan', 255)->nullable();

            $table->timestamps();

            /*
             * Halaman "Rekening Pusat" memuat akun aktif saja lalu
             * menghitung saldo masing-masing.
             *
             * Indeks ini bukan untuk menyaring "aktif saja" -- penyaringan
             * itu sudah di-cover `kategori` dan `is_active`. Yang perlu
             * indeks adalah pengelompokan `account_id` saat menjumlahkan
             * `tenant_invoice_payments`,
             * dan itu dilakukan per rekening pada setiap pembukaan
             * halaman.
             */
            $table->index(['is_active', 'kategori']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('tenancy.central_connection'))->dropIfExists('central_accounts');
    }
};