<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Services\AuditLogger;
use App\Services\Migration\MigrationBlueprint;
use App\Services\Migration\MigrationPipeline;
use App\Services\Migration\MigrationRegistry;
use App\Services\Migration\MigrationRowException;
use App\Services\Migration\SpreadsheetReader;
use App\Services\Migration\TemplateGenerator;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Migrasi data dari panel pusat ke database SATU lokasi.
 *
 * TIGA LANGKAH, DAN MENGAPA TIDAK SATU
 * ====================================
 *
 *   1. Unduh template  -> file .xlsx kosong + daftar referensi lokasi
 *   2. Validasi        -> file diunggah, dibaca, dipetakan, TIDAK ditulis
 *   3. Commit          -> baris yang lolos validasi ditulis ke lokasi
 *
 * Alasan lengkapnya untuk tidak menggabungkan langkah 2 dan 3 ada di
 * {@see MigrationPipeline}. Ringkasnya: kalau penulisan terjadi sambil
 * memvalidasi, satu baris yang gagal di baris 900 membuat superadmin
 * tidak tahu apakah 899 baris sebelumnya sudah tersimpan -- sehingga
 * "migrasi gagal" tidak bisa dibedakan dari "migrasi separuh jalan",
 * dan yang kedua terlihat berhasil di notifikasi.
 *
 * SETIAP ENDPOINT BUTUH SATU LOKASI YANG JELAS
 * ==============================================
 *
 * Karena satu lokasi = satu database, "data mana yang masuk ke mana" harus
 * terlihat di setiap langkah, bukan hanya di halaman awal. Nama lokasi ada
 * di URL setiap endpoint, di respons setiap langkah, dan di sheet `Data`
 * template yang diunduh. Kalau user mengunggah file untuk lokasi yang
 * salah, `commit()` menolak berdasarkan lokasi yang tersimpan bersama
 * hasil validasi -- bukan setelah ribuan baris sudah ditulis.
 *
 * MENGAPA TIDAK ADA `destroy`
 * ==========================
 *
 * Ada unduh dan ada commit, tapi tidak ada "batalkan". Membatalkan
 * berarti menghapus data yang sudah masuk -- dan tabel operasional
 * lokasi tidak punya mekanisme soft delete, jadi satu-satunya jalan
 * adalah menghapus baris, yang bisa ikut menghapus tagihan yang
 * sudah jatuh tempo. Tombol yang terlihat setara dengan tombol
 * "Hapus" adalah jebakan; tidak adanya tombol lebih jujur.
 */
class CentralMigrationController extends Controller
{
    public function __construct(
        private TenantManager $manager,
        private AuditLogger $audit,
        private MigrationPipeline $pipeline,
        private TemplateGenerator $template,
    ) {}

    /**
     * Daftar jenis data yang bisa dimigrasi.
     *
     * Tidak butuh lokasi: daftar ini sama untuk semua lokasi, dan
     * tidak menyentuh database lokasi sama sekali -- jadi tidak ada
     * alasan untuk menahannya di balik pemilihan lokasi.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'jenis' => MigrationRegistry::ringkasan(),
                'batas' => [
                    'kilobyte' => MigrationPipeline::maksKilobyte(),
                    'ekstensi' => SpreadsheetReader::ekstensiYangBoleh(),
                ],
            ],
        ]);
    }

    /**
     * Unduh template untuk satu jenis data di satu lokasi.
     *
     * Isi sheet "Referensi" dibaca dari database lokasi -- itu yang
     * membuat template yang diunduh berbeda untuk setiap lokasi, dan
     * alasan seluruh fitur ini harus punya filter lokasi yang jelas
     * sejak langkah pertama, bukan hanya saat commit.
     *
     * Format ditentukan `?format=csv`; default-nya XLSX karena hanya
     * XLSX yang bisa membawa dropdown dan sheet penjelasan. CSV tetap
     * disediakan karena spreadsheet yang dipakai admin lokasi sering
     * tidak punya fitur itu, dan karena ada yang membaca file di
     * ponsel.
     */
    public function template(Request $request, string $location, string $type): StreamedResponse|JsonResponse
    {
        $tenant = $this->cariTenant($location);
        $blueprint = $this->cariBlueprint($type);

        if ($blueprint === null) {
            return $this->tipeTidakDikenal($type);
        }

        $format = strtolower((string) $request->query('format', 'xlsx'));

        if (! in_array($format, ['xlsx', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Format \"{$format}\" tidak dikenal. Gunakan `xlsx` atau `csv`.",
            ], 422);
        }

        try {
            $berkas = $this->template->buat($blueprint, $tenant, $format);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => "Template `{$blueprint->label()}` tidak bisa dibuat: database lokasi "
                    ."`{$tenant->slug}` tidak bisa dibaca. Periksa kredensialnya di panel pusat.",
            ], 503);
        }

        $this->audit->record(
            'migration.template',
            tenant: $tenant,
            subjectType: 'migration',
            description: "Template migrasi `{$blueprint->type()}` diunduh untuk lokasi `{$tenant->slug}`.",
            actor: $request->user(),
        );

        return response()->streamDownload(
            fn () => print $berkas['body'],
            $berkas['filename'],
            [
                'Content-Type' => $format === 'csv'
                    ? 'text/csv; charset=UTF-8'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Length' => (string) strlen($berkas['body']),
                'X-Migrasi-Lokasi' => $tenant->slug,
                'X-Migrasi-Jenis' => $blueprint->type(),
                'X-Migrasi-Format' => $format,
            ]
        );
    }

    /**
     * Langkah 2: baca file, periksa tiap baris, TIDAK menulis apa pun.
     */
    public function validate(Request $request, string $location, string $type): JsonResponse
    {
        $tenant = $this->cariTenant($location);
        $blueprint = $this->cariBlueprint($type);

        if ($blueprint === null) {
            return $this->tipeTidakDikenal($type);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.MigrationPipeline::maksKilobyte(),
                'extensions:'.implode(',', SpreadsheetReader::ekstensiYangBoleh()),
            ],
        ], [
            'file.required' => 'Pilih file yang mau diimpor.',
            'file.max' => 'Ukuran file melebihi batas '
                .round(MigrationPipeline::maksKilobyte() / 1024).' MB.',
            'file.extensions' => 'Format file harus salah satu dari: '
                .implode(', ', SpreadsheetReader::ekstensiYangBoleh()).'.',
        ]);

        $file = $validated['file'];
        $isi = file_get_contents($file->getRealPath());

        if ($isi === false) {
            return response()->json([
                'success' => false,
                'message' => 'File yang diunggah tidak bisa dibaca dari server.',
            ], 500);
        }

        try {
            $hasil = $this->pipeline->validate(
                $blueprint,
                $tenant,
                $isi,
                strtolower($file->getClientOriginalExtension() ?: $file->extension())
            );
        } catch (MigrationRowException $e) {
            /*
             * Kolom wajib yang tidak ada di header. Ini masalah file,
             * bukan masalah baris -- jadi tidak ada nomor baris yang bisa
             * disebut, dan tidak ada satu pun baris yang boleh ditulis.
             */
            return response()->json([
                'success' => false,
                'code' => $e->kode(),
                'message' => $e->getMessage(),
                'data' => [
                    'jenis' => $blueprint->type(),
                    'lokasi' => $tenant->slug,
                    'kolom_wajib' => array_column($blueprint->columns(), 'label'),
                ],
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => "File tidak bisa dibaca: {$e->getMessage()}",
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $hasil['gagal'] === 0
                ? "{$hasil['siap']} baris siap diimpor ke lokasi `{$tenant->slug}`."
                : "{$hasil['siap']} baris siap, {$hasil['gagal']} baris ditolak.",
            'data' => [
                'hash' => $hasil['hash'],
                'jenis' => $blueprint->type(),
                'label' => $blueprint->label(),
                'lokasi' => $tenant->slug,
                'lokasi_nama' => $tenant->label(),
                'total' => $hasil['total'],
                'siap' => $hasil['siap'],
                'gagal' => $hasil['gagal'],
                'baru' => $hasil['baru'],
                'diperbarui' => $hasil['diperbarui'],
                'baris' => $hasil['baris'],
                'disembunyikan' => $hasil['disembunyikan'],
                /*
                 * Definisi kolom ikut dikirim, bukan membiarkan frontend
                 * mengambilnya dari `GET /migrasi-data`.
                 *
                 * Alasannya bukan penghematan request: daftar di index itu
                 * bisa saja berasal dari jenis data yang SEDANG DIPILIH kalau
                 * daftar berubah di tengah sesi. Yang menentukan adalah
                 * `ringkas` yang berkunci pada kolom template -- kalau header
                 * tabel preview memakai daftar yang berbeda, setiap nilainya
                 * jatuh ke kolom yang salah tanpa error.
                 *
                 * Yang dikirim hanya `key` dan `label`, karena itulah yang
                 * dipakai tabel. Aturan kolom sudah ada di sheet "Petunjuk"
                 * yang diunduh sebagai template.
                 */
                'kolom' => array_map(
                    static fn (array $c) => [
                        'key' => $c['key'],
                        'label' => $c['label'],
                        'required' => $c['required'],
                    ],
                    $blueprint->columns()
                ),
            ],
        ]);
    }

    /**
     * Langkah 3: tulis baris yang tadi lolos validasi.
     *
     * Satu permintaan, satu jawaban -- tidak ada progress di tengah.
     *
     * Commit sengaja TIDAK streaming: dibutuhkan antrean dan tabel
     * status supaya browser bisa menanyakan "sudah sejauh mana", dan
     * keduanya belum ada di proyek ini. Tanpa keduanya, progress yang
     * dikirim per Connection tidak pernah sampai ke browser -- response
     * yang sudah ter-buffer tidak bisa di-flush sampai selesai, jadi
     * user tetap melihat spinner tanpa tahu apakah prosesnya jalan.
     *
     * Yang bisa jujur ditampilkan: spinner dengan teks yang berubah
     * (membaca file, memeriksa 1.200 baris, menulis ke lokasi).
     * Persentase palsu lebih merusak daripada spinner, karena user
     * menunggu sesuatu yang tidak pernah datang kalau prosesnya sebenarnya
     * tersangkut.
     */
    public function commit(Request $request, string $location, string $type): JsonResponse
    {
        $tenant = $this->cariTenant($location);
        $blueprint = $this->cariBlueprint($type);

        if ($blueprint === null) {
            return $this->tipeTidakDikenal($type);
        }

        $validated = $request->validate([
            'hash' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ], [
            'hash.required' => 'Sesi validasi sudah berakhir. Unggah ulang file-nya.',
        ]);

        $hasil = $this->pipeline->commit(
            $blueprint,
            $tenant,
            (string) $validated['hash'],
        );

        if (! $hasil['ok']) {
            return response()->json([
                'success' => false,
                'message' => $hasil['detail'][0]['pesan'] ?? 'Migrasi tidak bisa dijalankan.',
                'data' => $hasil,
            ], 422);
        }

        $this->audit->record(
            'migration.commit',
            tenant: $tenant,
            subjectType: 'migration',
            description: sprintf(
                'Migrasi `%s` ke lokasi `%s`: %d baris tersimpan, %d gagal.',
                $blueprint->type(),
                $tenant->slug,
                $hasil['berhasil'],
                $hasil['gagal'],
            ),
            properties: [
                'jenis' => $blueprint->type(),
                'total' => $hasil['total'],
                'berhasil' => $hasil['berhasil'],
                'gagal' => $hasil['gagal'],
            ],
            actor: $request->user(),
        );

        $ringkas = $hasil['gagal'] === 0
            ? "{$hasil['berhasil']} baris berhasil dimigrasikan ke lokasi `{$tenant->slug}`."
            : "{$hasil['berhasil']} baris berhasil, {$hasil['gagal']} gagal. "
                .'Perinciannya ada di bawah.';

        return response()->json([
            'success' => true,
            'message' => $ringkas,
            'data' => [
                ...$hasil,
                'jenis' => $blueprint->type(),
                'label' => $blueprint->label(),
                'lokasi' => $tenant->slug,
                'lokasi_nama' => $tenant->label(),
                // Sama seperti pada `validate()`: frontend butuh tahu
                // berapa baris yang tidak ikut tampil di tabel detail.
                'disembunyikan' => max(
                    0,
                    ($hasil['semuaDetail'] ?? count($hasil['detail'])) - count($hasil['detail'])
                ),
            ],
        ]);
    }

    /**
     * Unduh daftar baris yang gagal, untuk dikirim ke whoever mengisi file.
     *
     * Format CSV, bukan XLSX: file ini dibuka di WhatsApp atau email, dan
     * CSV bisa dilampirkan langsung tanpa perlu Excel. Kolom paling penting
     * adalah nomor baris -- itu yang dipakai untuk menemukan baris salah
     * di file asli.
     */
    public function logGagal(Request $request, string $location, string $type): StreamedResponse|JsonResponse
    {
        $tenant = $this->cariTenant($location);
        $blueprint = $this->cariBlueprint($type);

        if ($blueprint === null) {
            return $this->tipeTidakDikenal($type);
        }

        $validated = $request->validate([
            'detail' => ['required', 'array', 'max:5000'],
            'detail.*.nomor' => ['required', 'integer'],
            'detail.*.pesan' => ['required', 'string'],
        ]);

        $nama = 'gagal-migrasi-'.$blueprint->type().'-'.date('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($validated, $tenant, $blueprint) {
            $keluaran = fopen('php://output', 'wb');

            /*
             * BOM UTF-8. Tanpa itu, Excel di Windows membuka file ini
             * sebagai ANSI, dan semua karakter Indonesia -- termasuk
             * nama pelanggan yang barisnya gagal -- berubah jadi kotak
             * tanya. Berkasnya tetap terbaca; isinya tidak.
             */
            fwrite($keluaran, "\xEF\xBB\xBF");

            fputcsv($keluaran, ['Lokasi', 'Jenis Data', 'Baris', 'Kolom', 'Alasan']);

            foreach ($validated['detail'] as $baris) {
                fputcsv($keluaran, [
                    $tenant->label(),
                    $blueprint->label(),
                    $baris['nomor'],
                    implode('; ', $baris['kolom'] ?? []),
                    $baris['pesan'],
                ]);
            }

            fclose($keluaran);
        }, $nama, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ================================================================
     | PEKERJAAN INTERNAL
     |============================================================
     */

    /**
     * Tenant dari path, yang boleh berupa slug ATAU id.
     *
     * Sama seperti controller central lain: panel pusat menyimpan `id` di
     * baris tabelnya, dan form edit memakai `id` juga, jadi ada momen ketika
     * hanya salah satu yang tersedia.
     */
    private function cariTenant(string $location): Tenant
    {
        return Tenant::query()
            ->where('slug', $location)
            ->orWhere('id', is_numeric($location) ? (int) $location : 0)
            ->firstOrFail();
    }

    /**
     * Jenis migrasi dari path, atau null kalau tidak dikenal.
     *
     * Pesan 404 menyebut daftar yang ADA, bukan hanya "tidak ditemukan".
     * Satu-satunya cara salah ke sini adalah mengetik atau menempel URL
     * yang salah, dan tanpa daftar yang ada user akan mengira fiturnya
     * yang bermasalah.
     */
    private function cariBlueprint(string $type): ?MigrationBlueprint
    {
        return MigrationRegistry::cari($type);
    }

    private function tipeTidakDikenal(string $type): JsonResponse
    {
        $ada = array_column(MigrationRegistry::ringkasan(), 'type');

        return response()->json([
            'success' => false,
            'code' => 'JENIS_TIDAK_DIKENAL',
            'message' => "Jenis data \"{$type}\" tidak dikenal.",
            'data' => ['yang_tersedia' => $ada],
        ], 404);
    }
}
