<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat pembayaran tagihan langganan oleh ASTA BRATA.
 *
 * Satu baris = satu pembayaran penuh untuk satu tagihan.
 *
 * SATU-SATUNYA pembayaran per tagihan, bukan bisa dicicil. Ini bukan
 *itumbuh fitur yang ditambahkan nanti, tapi yang dibuat sengaja:
 * langganan ASTA BRATA dipungut satu kali untuk satu periode, jadi tidak
 * ada keadaan "sudah bayar separuh" yang perlu direpresentasikan. Kalau
 * nanti memang ada tagihan yang perlu dipecah, menambah kolom `status`
 * dan mengubah constraint di sini adalah perubahan yang jelas -- bukan
 * membaca ulang seluruh riwayat pembayaran yang tercatat sebagai
 * pembayaran penuh padahal bukan.
 *
 * Yang membuat tabel ini perlu ada -- dan bukan cukupnya satu kolom
 * `tgl_lunas` di `tenant_invoices` -- adalah tiga hal yang tidak punya
 * tempat lain:
 *
 *   1. ULASAN dari mana uangnya masuk (kas atau rekening bank mana).
 *      Tanpa itu, "sudah lunas" tidak bisa dipertanggungjawabkan ke
 *      siapa pun, dan saat rekening bank tidak sesuai dengan catatan,
 *      tidak ada yang bisa dibandingkan.
 *   2. SALDO per rekening pusat, untuk melihat uang tagihan yang sudah
 *      masuk benar-benar ada di rekening yang dipilih.
 *   3. JEJAK yang bertahan setelah status tagihan diubah. Membatalkan
 *      status lunas (`markUnpaid`) menghapus `tgl_lunas`, dan kalau
 *      pembayaran hanya hidup di situ, riwayatnya ikut hilang bersama
 *      satu klik. Tabel ini tidak pernah dihapus oleh status tagihan.
 *
 * Foreign key ke `tenant_invoices` dan `central_accounts` memakai
 * cascadeOnDelete yang SEBENARNYA: baris tagihan yang dihapus berarti
 * pembayarannya tidak ada makna -- tagihan yang dihapus adalah tagihan
 * yang salah terbit. Baris `central_accounts` sendiri TIDAK pernah
 * dihapus (lihat migrasi tabel itu), jadi cascade ke sini praktis tidak
 * pernah terjadi dan kehadirannya hanya menjaga integritas saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = config('tenancy.central_connection');

        if (Schema::connection($central)->hasTable('tenant_invoice_payments')) {
            return;
        }

        Schema::connection($central)->create('tenant_invoice_payments', function (Blueprint $table) {
            $table->id();

            /*
             * UNIK per `tenant_invoice_id`.
             *
             * Constraint inilah yang ditegakkan database, bukan kode
             * aplikasi. Tagihan yang sudah punya pembayaran tidak bisa
             * dilunasi dua kali walaupun dua admin menekan tombol
             * "Lunas" pada detik yang sama -- dan skenario itu nyata,
             * karena keduanya melihat tagihan yang sama dalam keadaan
             * yang sama dan tidak tahu bahwa yang lain sedang memproses.
             *
             * Dicegah di database, bukan dengan pengecekan di controller:
             * pemeriksaan aplikasi hanya sekuat pemeriksaannya sendiri, dan
             * tidak ada yang menahan dua request yang datang pada waktu yang sama.
             */
            $table->foreignId('tenant_invoice_id')
                ->unique()
                ->constrained('tenant_invoices')
                ->cascadeOnDelete();

            $table->foreignId('central_account_id')
                ->constrained('central_accounts')
                ->cascadeOnDelete();

            /*
             * Cara pembayaran.
             *
             * Disalin dari `central_accounts.kategori` saat pembayaran
             * dicatat, bukan dibaca lewat relasi. Alasannya riwayat: kategori
             * rekening bisa diubah di kemudian hari, dan kalau yang disimpan
             * hanya id-nya, catatan lama ikut berubah bentuk padahal
             * uangnya sudah masuk dengan cara berbeda.
             *
             * Yang tersisa jadi denormalisasi yang bisa basi -- kategori
             * sekarang tidak lagi sama dengan saat pembayaran masuk. Itu
             * memang benar, dan memang itu gunanya.
             */
            $table->enum('metode', ['tunai', 'bank']);

            /*
             * Tanggal yang orang tuju, bukan tanggal saat admin menekan
             * tombol.
             *
             * Beda keduanya nyata dan sering terjadi: tagihan dibayar
             * hari Jumat, baru dicatat hari Senin -- misalnya karena yang
             * bayar mengirim bukti transfer lewat malam. `created_at` sudah
             * merekam kapan pencatatan terjadi, jadi tidak ada informasi
             * yang hilang, dan memakai `created_at` untuk tanggal pembayaran
             * akan membuat tanggal sah pembayaran ikut bergeser ke hari
             * admin kebetulan membuka panelnya.
             */
            $table->date('tgl_bayar');

            /*
             * Nominal yang benar-benar diterima.
             *
             * Disimpan terpisah dari `tenant_invoices.jumlah` walau
             * nilainya hampir selalu sama. Kalau tabel ini hanya
             * menyimpan status "lunas", informasi yang hilang bukan
             * angkanya -- tapi apakah ada yang dibayar lebih atau kurang,
             * yang justru hal pertama yang ditanyakan saat tagihan
             * sengketa.
             */
            $table->decimal('jumlah', 15, 2);

            $table->text('catatan')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(config('tenancy.central_connection'))
            ->dropIfExists('tenant_invoice_payments');
    }
};