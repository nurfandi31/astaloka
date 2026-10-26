<?php

namespace App\Services;

use App\Models\SignatureSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * SignatureService - pusat manajemen tanda tangan digital ASTALOKA.
 *
 * Konsep identik dengan SIUPK-Next:
 *   - Template HTML per laporan (rich text editor dengan tabel Mengetahui/Dibuat/Bendahara).
 *   - Image tanda tangan (PNG/JPG/WebP, max 2 MB) yang otomatis diinjeksi ke template.
 *
 * Image disimpan di storage/app/public/signatures/{report_key}.{ext} dengan
 * symlink public/storage.
 */
class SignatureService
{
    private const DISK = 'public';

    private const DIR = 'signatures';

    private const MIME_MAP = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct() {}

    /* ====================== TEMPLATE ====================== */

    /**
     * Ambil semua template (default value kalau record belum ada).
     *
     * Template disanitasi SAAT BACA, bukan hanya saat tulis. `saveTemplates()`
     * sudah menyanitasi, tapi baris yang tersimpan sebelum sanitasi itu
     * ada (atau yang disisipkan langsung ke database di luar aplikasi)
     * belum pernah melewati `HtmlSanitizer`. Kalau baris seperti itu
     * dirender apa adanya lewat `v-html`, payload-nya ikut dieksekusi.
     *
     * Sanitasi bersifat idempoten, jadi aman dijalankan setiap kali dibaca.
     *
     * @return array<string, string>
     */
    public function templates(): array
    {
        $rows = SignatureSetting::query()->get()->keyBy('report_key');

        $out = [];
        foreach (array_keys(SignatureSetting::REPORT_TYPES) as $key) {
            $row = $rows->get($key);
            $html = $row && $row->template_html ? (string) $row->template_html : '';
            $out[$key] = $html === '' ? '' : HtmlSanitizer::clean($html);
        }

        return $out;
    }

    /**
     * Ambil template untuk satu report; fallback ke 'default' kalau belum di-set.
     */
    public function template(string $reportKey): string
    {
        $all = $this->templates();
        $html = $all[$reportKey] ?? '';
        if ($html !== '') {
            return $html;
        }
        if ($reportKey !== 'default') {
            return $all['default'] ?? '';
        }

        return '';
    }

    /**
     * Simpan template untuk beberapa report sekaligus.
     *
     * @param  array<string, string|null>  $templates
     */
    public function saveTemplates(array $templates): void
    {
        DB::transaction(function () use ($templates) {
            foreach (array_keys(SignatureSetting::REPORT_TYPES) as $key) {
                $raw = $templates[$key] ?? '';
                if (! is_string($raw)) {
                    $raw = '';
                }
                $clean = HtmlSanitizer::clean($raw);

                $row = SignatureSetting::firstOrNew(['report_key' => $key]);
                $row->report_key = $key;
                $row->label = SignatureSetting::REPORT_TYPES[$key];
                $row->template_html = $clean;
                $row->save();
            }
        });
    }

    /* ====================== IMAGE ====================== */

    /**
     * Ambil path image untuk beberapa report sekaligus (key => path|null).
     *
     * @return array<string, string|null>
     */
    public function imagePaths(): array
    {
        $rows = SignatureSetting::query()->get()->keyBy('report_key');

        $out = [];
        foreach (array_keys(SignatureSetting::REPORT_TYPES) as $key) {
            $row = $rows->get($key);
            $out[$key] = $row && $row->image_path ? (string) $row->image_path : null;
        }

        return $out;
    }

    public function imagePath(string $reportKey): ?string
    {
        $all = $this->imagePaths();
        $path = $all[$reportKey] ?? null;
        if ($path === null && $reportKey !== 'default') {
            $path = $all['default'] ?? null;
        }

        return $path;
    }

    /**
     * Simpan tanda tangan gambar (data URI inline base64) untuk report tertentu.
     */
    public function storeImage(string $reportKey, string $dataUri): string
    {
        $this->assertReportKey($reportKey);

        [$binary, $extension] = $this->decodeDataUri($dataUri);
        $this->assertValidImage($binary, $extension);

        $this->deleteImage($reportKey, false);

        $path = self::DIR.'/'.$reportKey.'.'.$extension;
        Storage::disk(self::DISK)->put($path, $binary);

        $row = SignatureSetting::firstOrNew(['report_key' => $reportKey]);
        $row->report_key = $reportKey;
        $row->label = SignatureSetting::REPORT_TYPES[$reportKey];
        $row->image_path = $path;
        $row->image_mime = self::MIME_MAP[$extension] ?? 'image/png';
        $row->image_size = strlen($binary);
        $row->save();

        return $path;
    }

    public function deleteImage(string $reportKey, bool $persist = true): void
    {
        $this->assertReportKey($reportKey);

        $path = $this->imagePath($reportKey);
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }

        if (! $persist) {
            return;
        }

        $row = SignatureSetting::firstOrNew(['report_key' => $reportKey]);
        $row->report_key = $reportKey;
        $row->label = SignatureSetting::REPORT_TYPES[$reportKey];
        $row->image_path = null;
        $row->image_mime = null;
        $row->image_size = null;
        $row->save();
    }

    /**
     * Generate data URI inline (base64) untuk disisipkan ke HTML laporan.
     * Aman dipakai tanpa membocorkan path storage ke client.
     */
    public function imageDataUri(string $reportKey): ?string
    {
        $path = $this->imagePath($reportKey);
        if ($path === null) {
            return null;
        }
        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $binary = Storage::disk(self::DISK)->get($path);
        if ($binary === null || $binary === '') {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME_MAP[$ext] ?? 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    /**
     * URL publik untuk image tanda tangan (untuk preview UI).
     */
    public function imageUrl(string $reportKey): ?string
    {
        $path = $this->imagePath($reportKey);
        if ($path === null) {
            return null;
        }

        return asset('storage/'.$path);
    }

    /**
     * Inject image tanda tangan ke template HTML.
     * - Jika template memuat placeholder `{ttd_image}`, replace.
     * - Else: cari baris kosong pertama (`<p><br><br></p>` pattern) dan inject di atasnya.
     */
    public function renderForReport(string $reportKey): string
    {
        $html = $this->template($reportKey);
        if ($html === '') {
            return '';
        }

        $uri = $this->imageDataUri($reportKey);
        if ($uri === null) {
            return $html;
        }

        /*
         * TIDAK ada atribut `style` di sini.
         *
         * `HtmlSanitizer` membuang seluruh atribut `style`, jadi style
         * inline yang dulu ditulis di sini SELALU hilang begitu HTML-nya
         * disanitasi -- dan frontend juga menyanitasi ulang HTML ini
         * sebelum merendernya. Style inline berarti ukuran gambar
         * tanda tangan sedikit berbeda antara backend dan frontend.
         *
         * Ukurannya sekarang diatur dari CSS di sisi frontend
         * (`.report-signature-block :deep(img)`), yang hasilnya sama
         * untuk kedua sisi dan tidak perlu lolos melewati sanitizer.
         */
        $img = '<img src="'.$uri.'" alt="Tanda Tangan" />';

        if (str_contains($html, '{ttd_image}')) {
            return str_replace('{ttd_image}', $img, $html);
        }

        // Sisipkan sebelum baris kosong pertama yang berisi pattern "<p><br/><br/></p>".
        $pattern = '/(<p>(<br\s*\/?>\s*)+<\/p>)/i';
        $replaced = preg_replace($pattern, $img.'$1', $html, 1);
        if ($replaced !== null) {
            return $replaced;
        }

        return $html;
    }

    /**
     * Map view_target dari PelaporanController ke signature report_key.
     * Tutup buku, sub_laporan, dll. di-map ke key turunan.
     */
    public function resolveReportKey(?string $viewTarget, ?string $subLaporan = null): string
    {
        if (! $viewTarget) {
            return 'default';
        }

        // Tutup buku: pilih sub_laporan yang sesuai
        if ($viewTarget === 'tutup_buku') {
            return match ($subLaporan) {
                'alokasi_laba' => 'tutup_buku_alokasi_laba',
                'jurnal_tutup_buku' => 'tutup_buku_jurnal',
                'neraca_tutup_buku' => 'tutup_buku_neraca',
                'laba_rugi_tutup_buku' => 'tutup_buku_laba_rugi',
                'CALK_tutup_buku' => 'tutup_buku_calk',
                default => 'tutup_buku_neraca',
            };
        }

        // Aliases
        $aliases = [
            'calk' => 'calkk',
            'LPM' => 'perubahan_modal',
            'perubahan_modal' => 'perubahan_modal',
        ];

        $key = $aliases[$viewTarget] ?? $viewTarget;

        return array_key_exists($key, SignatureSetting::REPORT_TYPES) ? $key : 'default';
    }

    /* ====================== HELPERS ====================== */

    private function assertReportKey(string $reportKey): void
    {
        if (! array_key_exists($reportKey, SignatureSetting::REPORT_TYPES)) {
            throw new RuntimeException("Jenis laporan tidak dikenal: {$reportKey}");
        }
    }

    /**
     * Decode data URI inline ke binary + extension.
     *
     * @return array{0: string, 1: string}
     */
    private function decodeDataUri(string $dataUri): array
    {
        if (! preg_match('#^data:image/(png|jpeg|jpg|webp);base64,(.+)$#is', trim($dataUri), $m)) {
            throw new RuntimeException('Format tanda tangan harus data URI PNG/JPG/WebP base64.');
        }

        $rawType = strtolower($m[1]);
        $extension = $rawType === 'jpeg' ? 'jpg' : $rawType;
        $decoded = base64_decode(str_replace(' ', '+', $m[2]), true);

        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('Payload gambar tanda tangan rusak.');
        }

        return [$decoded, $extension];
    }

    private function assertValidImage(string $binary, string $extension): void
    {
        if (strlen($binary) > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran gambar tanda tangan maksimal 2 MB.');
        }

        $head = substr($binary, 0, 12);
        $valid = match ($extension) {
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'webp' => strlen($binary) > 12
                && str_starts_with(substr($binary, 0, 4), 'RIFF')
                && str_starts_with(substr($binary, 8, 4), 'WEBP'),
            default => false,
        };

        if (! $valid) {
            throw new RuntimeException('Isi file bukan file gambar yang valid.');
        }
    }
}
