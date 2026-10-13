<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice langganan dari ASTA BRATA (pusat) ke setiap lokasi.
 *
 * Meniru tabel `admin_invoice` di SIDBM: satu invoice per lokasi per
 * periode, status UNPAID sampai dikonfirmasi pusat. Lokasi yang invoice-nya
 * lewat batas toleransi akan diblokir login oleh middleware
 * `EnforceInvoiceStatus`.
 *
 * Nomor invoice disimpan sebagai string, bukan integer, karena formatnya
 * bercabang per tahun (mis. `INV/2026/0001`) sehingga urut global tak
 * bermakna. Uniqueness dijamin per lokasi, bukan global.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = config('tenancy.central_connection');

        if (Schema::connection($central)->hasTable('tenant_invoices')) {
            return;
        }

        Schema::connection($central)->create('tenant_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('nomor', 50);

            $table->date('tgl_invoice');
            $table->date('tgl_jatuh_tempo')->nullable();
            $table->date('tgl_lunas')->nullable();

            $table->decimal('jumlah', 15, 2);

            $table->enum('status', ['UNPAID', 'PAID'])->default('UNPAID');

            $table->string('periode', 20)->nullable();
            $table->text('keterangan')->nullable();

            // User pusat yang membuat / mengonfirmasi invoice.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'nomor']);
            $table->index(['status', 'tgl_invoice']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('tenancy.central_connection'))->dropIfExists('tenant_invoices');
    }
};
