<?php

namespace Tests\Unit;

use App\Support\JenisMutasi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Satu-satunya tempat yang memutuskan "apakah akun ini kredit".
 *
 * Kolom `jenis_mutasi` pernah ditulis dengan dua kapitalisasi berbeda: seeder
 * pertama memakai `'Kredit'`, seeder berikutnya `'kredit'`, dan panel pusat
 * bisa menambah bentuk baru lagi. Perbandingan sama persis (`== 'kredit'`)
 * tidak aman untuk data seperti itu -- akun yang tersimpan `'Kredit'`
 * diperlakukan sebagai akun debit, sehingga saldonya berbalik tanda.
 *
 * Test di sini mengunci dua hal yang harus dijaga: `isKredit()` benar untuk
 * semua bentuk kapitalisasi, dan `normalisasi()` tidak pernah mengeluarkan
 * nilai di luar dua nilai baku. Yang kedua penting karena panel pusat
 * memakai `normalisasi()` sebagai satu-satunya pintu masuk penyimpanan --
 * kalau ada nilai ketiga yang lolos ke sana, database akan punya bentuk yang
 * tidak pernah di graphing oleh kode mana pun.
 */
class JenisMutasiTest extends TestCase
{
    /**
     * Bentuk yang pernah benar-benar ada di database.
     *
     * `2.1.02.02` "Utang FEE Kolektor" pernah tersimpan `'Kredit'` dari
     * seeder pertama, dan itu akun kewajiban yang perhitungan saldonya jadi
     * berperilaku seperti aset kalau salah baca.
     */
    #[Test]
    #[DataProvider('yangHarusKredit')]
    public function kapitalisasi_apapun_tetap_kredit(mixed $nilai): void
    {
        $this->assertTrue(JenisMutasi::isKredit($nilai));
    }

    public static function yangHarusKredit(): array
    {
        return [
            'baku huruf kecil' => ['kredit'],
            'huruf besar awal' => ['Kredit'],
            'huruf besar semua' => ['KREDIT'],
            'campur' => ['KrEdIt'],
            'ada spasi di kiri' => ['  kredit'],
            'ada spasi di kanan' => ['kredit  '],
            'ada spasi di kedua sisi' => ["\tkredit\n"],
            'langsung dari database' => [JenisMutasi::KREDIT],
        ];
    }

    /**
     * Yang tidak dikenal dianggap debit.
     *
     * Ini perilaku lama di semua call site dan sengaja dipertahankan:
     * `jenis_mutasi` yang tidak dikenali tidak boleh dibalik menjadi kredit,
     * karena itu akan mengubah tanda saldo tanpa ada yang menyuruh.
     */
    #[Test]
    #[DataProvider('yangHarusDebet')]
    public function selebihnya_diperlakukan_sebagai_debet(mixed $nilai): void
    {
        $this->assertFalse(JenisMutasi::isKredit($nilai));
    }

    public static function yangHarusDebet(): array
    {
        return [
            'baku' => ['debet'],
            'huruf besar awal' => ['Debet'],
            ' huruf besar semua' => ['DEBET'],
            'kosong' => [''],
            'hanya spasi' => ['   '],
            'null' => [null],
            'tidak dikenal' => ['netral'],
            'tipe lain' => [1],
        ];
    }

    /**
     * `normalisasi()` hanya boleh mengeluarkan dua nilai baku.
     *
     * Ini yang mengunci bentuk database ke depan: panel pusat menulis lewat
     * fungsi ini, dan migrasi
     * `2026_10_17_000001_normalize_jenis_mutasi_case` lowered nilai yang
     * sudah terlanjur ada. Kalau `normalisasi()` nanti ditambah cabang ketiga
     * tanpa sengaja, test ini langsung gagal -- bukan setelah satu akun
     * punya saldo yang terbalik di laporan.
     */
    #[Test]
    public function normalisasi_selalu_kembali_ke_salah_satu_nilai_baku(): void
    {
        foreach (['kredit', 'Kredit', 'KREDIT', ' kredit ', 'debet', 'Debet', '', null, 'entah'] as $masuk) {
            $hasil = JenisMutasi::normalisasi($masuk);

            $this->assertContains($hasil, [JenisMutasi::DEBET, JenisMutasi::KREDIT]);
            $this->assertSame($hasil, strtolower($hasil), "Hasil `{$hasil}` belum huruf kecil.");
        }
    }

    /**
     * Nilai baku harus persis yang dibaca perbandingan di seluruh aplikasi.
     *
     * Semua call site dulu menulis `== 'kredit'` dengan literal. Kalau konstanta
     * berubah tanpa memperbarui literal yang tersisa, penghematan ini justru
     * jadi sumber perbandingan yang tidak cocok -- dan `isKredit()` tidak
     * bisa menolong karena yang salah adalah nilai yang dibaca, bukan cara
     * membacanya.
     */
    #[Test]
    public function nilai_baku_harus_sama_dengan_literal_di_seluruh_aplikasi(): void
    {
        $this->assertSame('kredit', JenisMutasi::KREDIT);
        $this->assertSame('debet', JenisMutasi::DEBET);
    }
}