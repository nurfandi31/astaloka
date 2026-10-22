<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pisahkan batas tagihan menunggak sebelum suspend dari `toleransi_tunggakan`.
 *
 * Latar belakang
 * --------------
 *
 * `settings.toleransi_tunggakan` dipakai sebagai empat hal berbeda:
 *
 *   1. Tanggal generate piutang dalam bulan (1-28).
 *   2. Jumlah hari mundur dari hari run untuk ambang piutang.
 *   3. Tanggal dalam bulan pemakaian tagihan untuk deteksi advance payment.
 *   4. Jumlah tagihan unpaid sebelum sambungan pelanggan diputus.
 *
 * Keempatnya tidak bisa bergerak naik-turun bersama dalam satu rentang angka.
 * Admin yang ingin menunda generate ke tanggal 20 secara tidak sengaja ikut
 * mengubah batas tagihan yang boleh menunggak menjadi 20 tagihan.
 *
 * Yang dilakukan
 * --------------
 *
 * Kolom baru nullable, TANPA default. Nilai kosong berarti "pakai nilai lama
 * persis seperti sebelumnya", jadi perilaku semua lokasi yang sudah ada tidak
 * berubah sama sekali sampai admin mengisinya lewat halaman SOP.
 *
 * Sengaja tidak diisi dari `toleransi_tunggakan` di migrasi ini: kolom itu
 * dibaca sebagai SISA NILAI setiap lokasi, jadi menyalinnya di sini
 * membekukan angka lama pada kolom yang justru dimaksud untuk diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'billing_unpaid_limit')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_unpaid_limit')
                ->nullable()
                ->after('toleransi_tunggakan')
                ->comment('Batas tagihan unpaid sebelum suspend. Kosong = pakai toleransi_tunggakan.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'billing_unpaid_limit')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('billing_unpaid_limit');
        });
    }
};