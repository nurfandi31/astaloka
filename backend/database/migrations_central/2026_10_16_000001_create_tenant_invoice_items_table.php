<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian baris item invoice langganan.
 *
 * Tabel ini TIDAK ada padanannya di SIDBM (`admin_invoice` di sana cuma
 * menyimpan satu nominal). Di sini nominal `tenant_invoices.jumlah` tetap
 * menjadi sumber kebenaran tunggal -- kolom ini tidak pernah dijumlahkan
 * ulang saat disimpan, tapi tetap dipakai untuk dua hal:
 *
 *   1. Mencetak PDF yang bisa dibaca penerima tagihan. Satu baris
 *      "Tagihan Langganan   Rp 1.500.000" tidak bisa menjelaskan
 *      kepada lokasi apa tagihan itu untuk; rincian item bisa.
 *   2. Menyimpan nominal yang benar-benar ditagihkan, terpisah dari
 *      nominal yang tercatat di invoice.
 *
 * Keterkaitan dengan `jumlah`: keduanya boleh berbeda kalau pusat memberi
 * diskon atau biaya admin di luar rincian item. Kalau begitu `jumlah`
 * tidak sama dengan jumlah `item`. Persis seperti invoice pada umumnya,
 * yang punya Sub Total, Diskon, dan Total yang tidak selalu sama. Jadi
 * kolom `jumlah` TIDAK diturunkan dari item -- keduanya diinput, dan
 * selisihnya ditampilkan sebagai Diskon di cetakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = config('tenancy.central_connection');

        if (Schema::connection($central)->hasTable('tenant_invoice_items')) {
            return;
        }

        Schema::connection($central)->create('tenant_invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_invoice_id')
                ->constrained('tenant_invoices')
                ->cascadeOnDelete();

            // Urutan baris seperti yang terlihat di PDF. Diurutkan juga saat
            // query (lihat TenantInvoiceItem::$orderBy) supaya "baris 1" di
            // cetakan sama dengan "baris 1" yang dilihat saat mengedit.
            $table->unsignedSmallInteger('urutan')->default(1);

            $table->string('nama', 200);

            // Qty `decimal` dan bukan `integer` karena item bisa "1.5 bulan"
            // untuk prorata, bukan cuma bilangan bulat.
            // `decimal` dan bukan `integer` karena item bisa "1.5 bulan"
            // untuk prorata, bukan cuma bilangan bulat.
            $table->decimal('qty', 12, 2)->default(1);

            // Harga per satuan. `jumlah` disimpan terpisah (bukan selalu
            // `qty * harga_satuan`) karena ada kalanya yang ditagihkan
            // bukan hasil perkalian -- misalnya ada pembulatan ke ribuan,
            // atau harga sudah termasuk diskon. Kalau `jumlah` selalu dihitung,
            // nilai yang benar-benar ditagihkan harus diubah pada invoice dan
            // tidak terlihat dari itemnya.
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('jumlah', 15, 2)->default(0);

            $table->string('satuan', 30)->nullable();

            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index(['tenant_invoice_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('tenancy.central_connection'))->dropIfExists('tenant_invoice_items');
    }
};