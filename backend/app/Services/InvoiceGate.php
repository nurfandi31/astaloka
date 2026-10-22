<?php

namespace App\Services;

use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use Illuminate\Support\Collection;

/**
 * Satu-satunya tempat yang memutuskan "apakah lokasi ini sedang ditagih?".
 *
 * Kenapa perlu berkas sendiri
 * -------------------------
 *
 * Aturan ini dipakai di tiga tempat yang tidak saling terkait:
 *
 *   1. `POST /login`          -- menolak login sebelum token terbit
 *   2. `EnforceInvoiceStatus` -- memblokir request setelah login
 *   3. `MyInvoiceController` -- ringkasan untuk popup
 *
 * Kalau ketiganya menulis syaratnya sendiri-sendiri, mereka pasti akan
 * menyimpang: perbaikan di satu tempat tidak menyentuh yang lain, dan
 * gejalanya user melihat "masuk berhasil" tapi halamannya langsung
 * ditolak 402. Menyatukannya di sini membuat penyimpangan itu jadi
 * mustahil secara struktural.
 *
 * Kapan lokasi diblokir
 * ---------------------
 *
 * Ada invoice status UNPAID -- belum lewat jatuh tempo atau sudah lewat.
 * Ini lebih ketat dari perilaku lama yang hanya memblokir yang overdue
 * (lihat `invoice_block_on_unpaid`).
 *
 * Alasannya: permintaan aslinya adalah "saat login akan muncul tagihan
 * ini dulu" -- penagihan yang terlihat, bukan teguran diam-diam yang baru
 * muncul weeks kemudian setelah tenggatnya lewat. Kalau hanya yang
 * terlambat yang diblokir, tagihan yang baru terbit melintas begitu saja
 * dan tidak pernah dilihat.
 *
 * `invoice_block_on_unpaid` di config mengizinkan dua mode: dengan
 * `false`, perilaku kembali ke "hanya yang lewat jatuh tempo" -- berguna
 * untuk lokasi yang tarifnya tahunan, di mana tagihan terbit sekali
 * setahun sehingga memblokir selama 12 bulan tidak mungkin diterima.
 */
class InvoiceGate
{
    /**
     * Invoice yang sedang memblokir lokasi ini.
     *
     * Mengembalikan collection KOSONG -- bukan null -- supaya pemanggil
     * cukup `isEmpty()` tanpa memeriksa null lebih dulu.
     */
    public function blocking(?Tenant $tenant): Collection
    {
        if (! $tenant instanceof Tenant) {
            return collect();
        }

        $unpaid = $tenant->unpaidInvoices()->get();

        if (! (bool) config('tenancy.invoice_block_on_unpaid', true)) {
            $unpaid = $unpaid->filter(fn (TenantInvoice $i) => $i->isOverdue())->values();
        }

        return $unpaid->sortByDesc(
            fn (TenantInvoice $i) => $i->deadline()->getTimestamp()
        )->values();
    }

    /** Ada tagihan yang memblokir? */
    public function blocks(?Tenant $tenant): bool
    {
        return $this->blocking($tenant)->isNotEmpty();
    }

    /**
     * Bentuk payload yang dikirim ke user lokasi.
     *
     * Dikembalikan sebagai array serializable supaya bisa dipakai oleh
     * respons JSON login DAN respons 402 middleware tanpa format yang
     * berbeda -- popup di halaman login dan popup di dashboard harus
     * menampilkan angka yang sama persis.
     *
     * Kolom `periode` sengaja tidak ikut: user lokasi tidak butuh tahu periode
     * tagihan pusat, cukup nomor, nominal, dan kapan harus dibayar.
     */
    public function payload(?Tenant $tenant): array
    {
        $blocking = $this->blocking($tenant);

        if ($blocking->isEmpty()) {
            return [
                'terlambat' => false,
                'tagihan_terkunci' => 0,
                'total_tagihan' => 0,
                'invoices' => [],
            ];
        }

        return [
            'terlambat' => $blocking->contains(fn (TenantInvoice $i) => $i->isOverdue()),
            'tagihan_terkunci' => $blocking->count(),
            'total_tagihan' => (float) $blocking->sum('jumlah'),
            'invoices' => $blocking->map(fn (TenantInvoice $i) => [
                'nomor' => $i->nomor,
                'jumlah' => (float) $i->jumlah,
                'tgl_invoice' => $i->tgl_invoice?->toDateString(),
                'deadline' => $i->deadline()->toDateString(),
                // Nomor positif = masih punya waktu, negatif = terlambat.
                'sisa_hari' => $i->daysRemaining(),
                'terlambat' => $i->isOverdue(),
            ])->values(),
        ];
    }

    /**
     * Pesan yang ditampilkan user lokasi.
     *
     * Bedakan "belum jatuh tempo" dari "terlambat": keduanya punya urgensi
     * berbeda, dan menampilkan kalimat yang sama untuk keduanya membuat
     * lokasi yang masih dalam tempo merasa pasti ditolak padahal belum.
     * Misinformasi seperti ini membuat orang menunda pembayaran yang
     * sebenarnya belum terlambat.
     */
    public function message(Collection $blocking): string
    {
        $terlambat = $blocking->contains(fn (TenantInvoice $i) => $i->isOverdue());

        if ($terlambat) {
            return 'Tagihan langganan ASTALOKA untuk lokasi ini sudah lewat jatuh tempo. '
                .'Aplikasi tidak bisa digunakan sampai tagihan dilunasi. '
                .'Silakan lakukan pembayaran kepada ASTA BRATA TEKNOLOGI.';
        }

        return 'Tagihan langganan ASTALOKA untuk lokasi ini belum dilunasi. '
            .'Selesaikan pembayaran kepada ASTA BRATA TEKNOLOGI sebelum masuk ke aplikasi.';
    }
}