<?php

namespace App\Services;

/**
 * HtmlSanitizer - satu-satunya pintu masuk untuk HTML yang berasal dari
 * pengguna dan akan dirender apa adanya (v-html / innerHTML).
 *
 * Kenapa kelas ini ada:
 *
 * Dua jalur berbeda bisa menyimpan HTML mentah dari editor ke database:
 * `SignatureService` (template tanda tangan) sudah punya sanitizer
 * sendiri, tapi `point_a` milik SOP dan CALK tidak punya apa pun. HTML
 * apa pun yang disimpan di sana langsung dirender dengan `v-html` di
 * frontend, jadi tag/atribut berbahaya ikut tersimpan dan ikut dieksekusi
 * saat laporan dibuka.
 *
 * Quill 2.0.3 punya kerentanan sendiri di sini (CVE-2025-15056,
 * GHSA-v3m3-f69x-jf25): blot `formula` dan `video` meng-interpolasi nilai
 * mentah ke string HTML saat ekspor, jadi `</span><img src=x onerror=...>`
 * bisa lolos sebagai HTML. Belum ada versi patch-nya. Karena itu
 * sanitasi di sisi server bukan pelengkap -- itu satu-satunya lapisan
 * yang bisa menutup celah itu.
 *
 * Prinsipnya: allowlist tag dan atribut. Tag di luar daftar dibuang
 * beserta isi-nya kalau tag itu adalah elemen yang isinya bukan teks
 * (script, style, iframe, object, embed). Tag format biasa seperti
 * `<div>` dibuang tapi teks di dalamnya tetap dipertahankan.
 */
class HtmlSanitizer
{
    /**
     * Tag yang boleh muncul di hasil.
     *
     * Cukup untuk hasil Quill/TipTap: heading, paragraf, format teks,
     * daftar, tabel, blockquote, kode, dan gambar. `video` dan `iframe`
     * sengaja TIDAK ada -- keduanya bisa membawa sumber eksternal dan
     * `iframe` bisa membawa `srcdoc` yang berisi HTML lengkap.
     */
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><strike>'
        .'<ul><ol><li>'
        .'<table><thead><tbody><tfoot><tr><th><td>'
        .'<span><div>'
        .'<img>'
        .'<h1><h2><h3><h4><h5><h6>'
        .'<a>'
        .'<blockquote><pre><code>';

    /**
     * Elemen yang dibuang BESERTA isinya. Isinya bukan teks yang perlu
     * dipertahankan -- justru di dalam `<script>`/`<style>` ada payload.
     */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'noscript', 'template', 'svg', 'math', 'frame', 'frameset',
        'base', 'link', 'meta', 'title', 'form', 'input', 'button',
        'textarea', 'select', 'option',
    ];

    /**
     * Atribut yang boleh dipertahankan per tag.
     *
     * `align` untuk tabel tanda tangan. `href` untuk tautan, `src`/`alt`
     * untuk gambar. Semua atribut lain dibuang -- termasuk `on*` (event
     * handler), `style` (bisa menyisipkan `expression()`/url tak lazim),
     * `class`/`id` (bisa menabrak style aplikasi), dan `srcdoc`.
     *
     * Tag yang tidak disebut di sini tetap BOLEH ada, tapi tanpa
     * atribut apa pun. Daftar ini bukan allowlist tag -- itu urusan
     * `ALLOWED_TAGS`.
     */
    private const ALLOWED_ATTRS = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
        'p' => ['align'],
        'td' => ['align', 'colspan', 'rowspan'],
        'th' => ['align', 'colspan', 'rowspan'],
        'tr' => ['align'],
        'tbody' => ['align'],
        'thead' => ['align'],
        'tfoot' => ['align'],
        'table' => ['align'],
    ];

    /** Nilai `align` yang sah. Sisanya dibuang. */
    private const ALIGN_VALUES = ['left', 'center', 'right', 'justify'];

    /** Panjang maksimum HTML yang disimpan. */
    private const MAX_LENGTH = 50_000;

    /**
     * Protocol yang boleh dipakai pada `href` dan `src`.
     *
     * `data:image/...` hanya sah untuk `src` (gambar inline), tidak untuk
     * `href` -- `data:text/html` di `href` adalah Stored XSS lewat
     * navigasi.
     */
    private const SAFE_LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Bersihkan HTML dari input tidak tepercaya.
     */
    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Normalisasi baris baru agar tag terputus tidak lolos dari
        // pemeriksaan berbasis regex.
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Buang elemen beserta isinya lebih dulu. strip_tags hanya
        // membuang tag pembungkus, bukan teks di dalamnya -- dan teks
        // di dalam <script> justru payload-nya.
        $html = self::dropDangerousElements($html);

        // allowlist tag
        $html = strip_tags($html, self::ALLOWED_TAGS);

        // allowlist atribut
        $html = self::cleanAttributes($html);

        // Lapisan pengaman: buang event handler dan URL javascript: yang
        // mungkin lolos karena penyusunan atribut tidak rapi.
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\s*(href|src|xlink:href)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? $html;

        // Validasi skema pada href dan src.
        $html = self::enforceSafeUrls($html);

        if (strlen($html) > self::MAX_LENGTH) {
            $html = substr($html, 0, self::MAX_LENGTH);
        }

        return trim($html);
    }

    /**
     * Buang elemen berbahaya beserta seluruh isinya.
     *
     * Handles tag berpasangan (script..script) dan tag tunggal (img,
     * iframe, ...) sekaligus varian bersarang.
     */
    private static function dropDangerousElements(string $html): string
    {
        // Buang blok script/style yang tag penutupnya hilang DULU.
        // Kalau tidak, tag tunggalnya sudah ikut hilang di langkah
        // berikutnya dan isi bloknya tinggal menggantung sebagai teks.
        $html = self::dropUnclosedBlocks($html);

        foreach (self::DROP_WITH_CONTENT as $tag) {
            // Pasangan bertanda tangan: <script>...</script>
            $html = preg_replace(
                '#<'.$tag.'\b[^>]*>.*?</'.$tag.'\s*>#si',
                '',
                $html
            ) ?? $html;

            // Tag tunggal: <script ...>, <iframe ...>, dan sejenisnya.
            $html = preg_replace(
                '#<'.$tag.'\b[^>]*/?>#i',
                '',
                $html
            ) ?? $html;
        }

        // Komentar HTML bisa menyembunyikan payload dari parser lain.
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;

        return $html;
    }

    /**
     * Buang blok script/style yang tag penutupnya hilang.
     *
     * Kalau `<script>` dibiarkan tanpa penutup, isi berikutnya ikut
     * terbuang -- lebih baik data hilang daripada payload lolos.
     *
     * Hanya berlaku kalau tag penutupnya memang tidak ada. Kalau ada,
     * pasangan lengkapnya dibuang oleh `dropDangerousElements`.
     */
    private static function dropUnclosedBlocks(string $html): string
    {
        foreach (['script', 'style'] as $tag) {
            // Tag buka tanpa tag tutup -> buang sisanya.
            $html = preg_replace(
                '#<'.$tag.'\b[^>]*>(?:(?!</'.$tag.'\s*>).)*$#si',
                '',
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * Apakah tag ini termasuk allowlist?
     */
    private static function isAllowedTag(string $tag): bool
    {
        static $allowed = null;

        if ($allowed === null) {
            $allowed = array_map(
                static fn (string $t): string => trim($t, '<>'),
                explode('|', str_replace('><', '|', self::ALLOWED_TAGS))
            );
        }

        return in_array($tag, $allowed, true);
    }

    /**
     * Rebuild tiap tag dengan hanya atribut yang diizinkan.
     */
    private static function cleanAttributes(string $html): string
    {
        return preg_replace_callback(
            // Tag pembuka: nama tag, lalu atribut yang diakhiri tanda '='.
            // Atribut tanpa nilai ('disabled') ikut dibuang -- tidak ada
            // satu pun yang diizinkan, jadi tidak perlu diparsing.
            '/<([a-z][a-z0-9]*)((?:\s+[a-zA-Z_:][-a-zA-Z0-9_:.]*\s*(?:=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*)\s*\/?>/i',
            function (array $m): string {
                $tag = strtolower($m[1]);
                $rawAttrs = $m[2] ?? '';

                // Tag di luar allowlist -> buang seluruh tag, teks di
                // dalamnya tetap ada. Tag di dalam allowlist tapi tanpa
                // atribut yang diizinkan -> pertahankan tagnya, buang
                // semua atributnya.
                if (! self::isAllowedTag($tag)) {
                    return '';
                }

                $allowed = self::ALLOWED_ATTRS[$tag] ?? [];
                $kept = [];

                foreach ($allowed as $attr) {
                    // Atribut harus diikuti tanda '='. Tanpa itu, teks
                    // seperti `onclick` di dalam nilai atribut lain tidak
                    // boleh ikut terambil.
                    $pattern = '/\b'.preg_quote($attr, '/').'\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';
                    if (! preg_match($pattern, $rawAttrs, $am)) {
                        continue;
                    }

                    // Nilai ber-kutip selalu menang; kalau kosong, pakai
                    // nilai tanpa kutip.
                    if (($am[2] ?? '') !== '') {
                        $value = $am[2];
                    } elseif (($am[3] ?? '') !== '') {
                        $value = $am[3];
                    } else {
                        $value = $am[4] ?? '';
                    }

                    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    if ($attr === 'align') {
                        if (! in_array(strtolower($value), self::ALIGN_VALUES, true)) {
                            continue;
                        }
                        $kept[] = 'align="'.strtolower($value).'"';

                        continue;
                    }

                    if ($attr === 'href' || $attr === 'src') {
                        if (! self::isSafeUrl($value, $attr === 'src')) {
                            continue;
                        }
                        $kept[] = $attr.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';

                        continue;
                    }

                    $kept[] = $attr.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
                }

                return '<'.$tag.($kept ? ' '.implode(' ', $kept) : '').'>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Pastikan tidak ada URL dengan skema berbahaya yang tertinggal.
     */
    private static function enforceSafeUrls(string $html): string
    {
        $html = preg_replace_callback(
            '/<(a|img)\s+[^>]*?(href|src)\s*=\s*"([^"]*)"[^>]*>/i',
            function (array $m): string {
                $isSrc = strtolower($m[2]) === 'src';

                if (self::isSafeUrl($m[3], $isSrc)) {
                    return $m[0];
                }

                // Buang atributnya, bukan seluruh tag.
                return str_replace($m[2].'="'.$m[3].'"', '', $m[0]);
            },
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Apakah URL ini aman untuk dipakai?
     *
     * `src` boleh memakai `data:image/(png|jpeg|jpg|webp);base64,` karena
     * gambar inline memang dipakai di template tanda tangan. `href` tidak
     * boleh memakai `data:` sama sekali -- `data:text/html` di `href`
     * adalah Stored XSS lewat navigasi.
     */
    private static function isSafeUrl(string $url, bool $isImageSource): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Buang karakter kontrol/whitespace yang bisa menyelip di tengah
        // skema (mis. "java\tscript:").
        $probe = strtolower(preg_replace('/[\s\x00-\x1F\x7F]+/', '', $url) ?? $url);

        if (str_starts_with($probe, 'data:')) {
            if (! $isImageSource) {
                return false;
            }

            return (bool) preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $url);
        }

        // Tanpa skema berarti path relatif -- aman.
        if (! preg_match('#^[a-z][a-z0-9+.\-]*:#i', $probe)) {
            return true;
        }

        $scheme = strstr($probe, ':', true);
        $scheme = $scheme === false ? '' : strtolower($scheme);

        if ($scheme === 'data') {
            return false;
        }

        return in_array($scheme, self::SAFE_LINK_SCHEMES, true);
    }
}
