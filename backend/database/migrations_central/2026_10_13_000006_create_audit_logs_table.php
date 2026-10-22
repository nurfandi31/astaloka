<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log di DATABASE PUSAT.
 *
 * Satu tabel untuk semua aksi, lintas dua sisi:
 *
 *   - Panel Pusat (ASTA BRATA): aksi superadmin terhadap registry lokasi,
 *     tagihan, dan tagihan langganan. Aktor = superadmin.
 *   - Aplikasi Lokasi: aksi pengguna di dalam lokasi (tambah pelanggan,
 *     ubah tagihan, hapus, dsb). Aktor = user lokasi.
 *
 * Kenapa satu tabel dan bukan dua? Karena pertanyaan yang paling sering
 * diajukan saat investigasi adalah "siapa yang mengubah data ini, kapan",
 * dan jawabannya bisa berasal dari kedua sisi. Memecahnya jadi dua tabel
 * memaksa orang mencari di tempat yang salah saat sedang menelusuri
 * insiden -- especially ketika user tidak tahu asal mula datanya.
 *
 * Baris ini TIDAK bisa dihapus dari aplikasi: tidak ada endpoint delete,
 * dan tabel tidak punya soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Penjaga yang sama seperti migrasi pusat lain. Tabel ini pernah
        // dibuat manual dengan `--path=database/migrations_central`
        // SEBELUM folder tersebut didaftarkan lewat `loadMigrationsFrom()`,
        // jadi tabelnya sudah ada sementara catatan di tabel `migrations`
        // default belum memuat baris untuk migrasi ini.
        //
        // Tanpa penjaga di sini, `php artisan migrate` biasa gagal dengan
        // `Table 'audit_logs' already exists` -- kesalahan yang justru muncul
        // dari upaya memperbaiki masalah aslinya.
        if (Schema::connection('central')->hasTable('audit_logs')) {
            return;
        }

        Schema::connection('central')->create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Aktor boleh null: aksi dari scheduler/queue/artisan tidak punya
            // user yang sedang login. actor_type tetap diisi supaya baris
            // tersebut bisa dibedakan dari aksi manusia.
            $table->unsignedBigInteger('actor_id')->nullable()->index();

            // superadmin | user | system
            $table->string('actor_type', 30)->default('user')->index();

            // Disalin, bukan diambil lewat relasi. Nama user bisa diganti,
            // dan audit harus tetap menampilkan nama yang berlaku SAAT aksi
            // terjadi -- bukan nama yang berlaku saat log dibaca.
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 30)->nullable();

            // Lokasi yang terkena dampak aksi. Null untuk aksi yang tidak
            // terikat lokasi (mis. membuat lokasi baru, atau login pusat).
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Dot-notation: tenant.create, tenant.update, customer.create.
            $table->string('action', 60)->index();

            // Objek yang diubah: model class + primary key-nya.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->text('description')->nullable();

            // Payload JSON. Untuk update, berisi { field: { old, new } }
            // hasil AuditLogger::diff(). Untuk create/delete, berisi
            // detail tambahan sesuai konteks aksinya.
            $table->json('properties')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            // Index untuk slicing log berdasarkan waktu.
            $table->index('created_at');

            // Melacak "semua perubahan pada objek ini".
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('audit_logs');
    }
};
