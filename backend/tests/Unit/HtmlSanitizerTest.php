<?php

namespace Tests\Unit;

use App\Services\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Test untuk HtmlSanitizer.
 *
 * Fokusnya: apa yang harus TIDAK bisa masuk ke HTML yang dirender dengan
 * v-html. Setiap kasus di sini punya pemicu nyata di aplikasi:
 * - Quill 2.0.3 menyisipkan nilai mentah ke HTML saat ekspor
 *   (CVE-2025-15056) -- jadi nilai berisi tanda kutip dan `</span>`
 *   harus tidak bisa keluar sebagai markup.
 * - `point_a` dan `catatan` CALK disimpan apa adanya lalu dirender dengan
 *   v-html di halaman laporan.
 */
class HtmlSanitizerTest extends TestCase
{
    public function test_html_sederhana_utuh(): void
    {
        $html = '<p>Narasi <strong>CALK</strong> tahun 2026.</p>';

        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_tag_format_umum_boleh(): void
    {
        $html = '<h2>Judul</h2><p><em>miring</em> dan <u>garis bawah</u></p>'
            .'<ul><li>satu</li></ul><ol><li>dua</li></ol>'
            .'<blockquote>kutipan</blockquote><pre><code>kode</code></pre>';

        $out = HtmlSanitizer::clean($html);

        foreach (['<h2>', '<em>', '<u>', '<ul>', '<li>', '<ol>', '<blockquote>', '<pre>', '<code>'] as $tag) {
            $this->assertStringContainsString($tag, $out);
        }
    }

    public function test_tabel_tanda_tangan_boleh(): void
    {
        $html = '<table align="center"><tbody><tr><td align="center" colspan="3"> Mengetahui </td></tr></tbody></table>';

        $out = HtmlSanitizer::clean($html);

        $this->assertStringContainsString('<table align="center">', $out);
        $this->assertStringContainsString('colspan="3"', $out);
        $this->assertStringContainsString('align="center"', $out);
    }

    public function test_script_dibuang_beserta_isinya(): void
    {
        $out = HtmlSanitizer::clean('<p>aman</p><script>alert(1)</script><p>juga aman</p>');

        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('aman', $out);
    }

    public function test_script_tag_tanpa_penutup_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<p>aman</p><script>alert(1)');

        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('aman', $out);
    }

    public function test_style_dibuang_beserta_isinya(): void
    {
        $out = HtmlSanitizer::clean('<style>body{background:url(javascript:alert(1))}</style><p>teks</p>');

        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('teks', $out);
    }

    public function test_event_handler_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<p onclick="alert(1)">teks</p>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('teks', $out);
    }

    public function test_event_handler_dengan_kutip_tunggal_dibuang(): void
    {
        $out = HtmlSanitizer::clean("<img src=\"x\" onerror='alert(1)'>");

        $this->assertStringNotContainsString('onerror', $out);
    }

    public function test_javascript_url_pada_href_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<a href="javascript:alert(1)">klik</a>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('klik', $out);
    }

    public function test_javascript_url_dengan_whitespace_di_tengah_dibuang(): void
    {
        $out = HtmlSanitizer::clean("<a href=\"java\tscript:alert(1)\">klik</a>");

        $this->assertStringNotContainsString('script:', $out);
    }

    public function test_skema_javascript_dengan_karakter_kontrol_dibuang(): void
    {
        // Browser mengabaikan karakter kontrol saat menafsirkan skema URL,
        // jadi "java\0script:" tetap dieksekusi sebagai javascript:.
        foreach (["\t", "\n", "\r", "\x00", "\x1F", "\x7F", ' '] as $pemisah) {
            $html = '<a href="java'.$pemisah.'script:alert(1)">klik</a>';
            $out = HtmlSanitizer::clean($html);

            $this->assertStringNotContainsString(
                'script:',
                $out,
                'Skema dengan pemisah '.json_encode($pemisah).' lolos: '.$out
            );
        }
    }

    public function test_skema_data_dengan_spasi_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<a href="data :text/html;base64,PHNjcmlwdD4=">klik</a>');

        $this->assertStringNotContainsString('data', $out);
    }

    public function test_tautan_aman_boleh(): void
    {
        $out = HtmlSanitizer::clean('<a href="https://example.com">tautan</a>');

        $this->assertStringContainsString('href="https://example.com"', $out);
    }

    public function test_tautan_mailto_dan_tel_boleh(): void
    {
        $out = HtmlSanitizer::clean('<a href="mailto:admin@example.com">email</a>');

        $this->assertStringContainsString('mailto:admin@example.com', $out);
    }

    public function test_data_html_pada_href_dibuang(): void
    {
        // data:text/html di href adalah Stored XSS lewat navigasi.
        $out = HtmlSanitizer::clean('<a href="data:text/html;base64,PHNjcmlwdD4=">klik</a>');

        $this->assertStringNotContainsString('data:text/html', $out);
    }

    public function test_gambar_data_uri_boleh(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgo=';

        $out = HtmlSanitizer::clean('<img src="'.$png.'" alt="tanda tangan">');

        $this->assertStringContainsString('data:image/png;base64', $out);
        $this->assertStringContainsString('alt="tanda tangan"', $out);
    }

    public function test_gambar_data_html_ditolak(): void
    {
        $out = HtmlSanitizer::clean('<img src="data:text/html;base64,PHNjcmlwdD4=">');

        $this->assertStringNotContainsString('data:text/html', $out);
    }

    public function test_src_luar_ke_skema_berbahaya_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<img src="javascript:alert(1)">');

        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_iframe_dibuang_beserta_isinya(): void
    {
        $out = HtmlSanitizer::clean('<p>teks</p><iframe src="https://evil.example"></iframe>');

        $this->assertStringNotContainsString('iframe', $out);
        $this->assertStringContainsString('teks', $out);
    }

    public function test_srcdoc_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<div srcdoc="<script>alert(1)</script>">x</div>');

        $this->assertStringNotContainsString('srcdoc', $out);
    }

    public function test_atribut_style_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<p style="background:url(javascript:alert(1))">teks</p>');

        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString('teks', $out);
    }

    public function test_atribut_class_dan_id_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<p class="x" id="y">teks</p>');

        $this->assertStringNotContainsString('class=', $out);
        $this->assertStringNotContainsString('id=', $out);
    }

    public function test_nilai_formula_quill_yang_rentan_tidak_bisa_keluar_sebagai_markup(): void
    {
        // Pola exploit CVE-2025-15056: blot formula Quill meng-interpolasi
        // nilai mentah ke string HTML, jadi penutup tag ikut terbawa.
        $out = HtmlSanitizer::clean('<span></span><img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_nilai_video_quill_dengan_kutip_tidak_bisa_breaks_markup(): void
    {
        // Pola exploit video blot: nilai berisi tanda kutip yang menutup
        // atribut lebih awal.
        $out = HtmlSanitizer::clean('<a href="https://example.com" onmouseover="alert(1)">video</a>');

        $this->assertStringNotContainsString('onmouseover', $out);
    }

    public function test_komentar_html_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<p>teks</p><!-- <script>alert(1)</script> -->');

        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_tag_tidak_dikenal_dibuang_tapi_teksnya_bertahan(): void
    {
        $out = HtmlSanitizer::clean('<p>tepat <marquee>teks</marquee> di sini</p>');

        $this->assertStringNotContainsString('marquee', $out);
        $this->assertStringContainsString('teks', $out);
    }

    public function test_input_dan_form_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<form action="https://evil.example"><input name="a"></form><p>teks</p>');

        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
        $this->assertStringContainsString('teks', $out);
    }

    public function test_svg_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<svg><script>alert(1)</script></svg><p>teks</p>');

        $this->assertStringNotContainsString('svg', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_nilai_kosong_menghasilkan_string_kosong(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean(''));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }

    public function test_html_yang_sudah_aman_tetap_aman(): void
    {
        $html = '<p>CALK <strong>2026</strong></p><table><tr><td align="center">x</td></tr></table>';

        $out = HtmlSanitizer::clean($html);

        // Sanitasi harus idempoten: sanitize(sanitize(x)) == sanitize(x).
        $this->assertSame($out, HtmlSanitizer::clean($out));
    }

    public function test_panjang_dibatasi(): void
    {
        $out = HtmlSanitizer::clean('<p>'.str_repeat('a', 60_000).'</p>');

        $this->assertLessThanOrEqual(50_000, strlen($out));
    }

    public function test_align_palsu_dibuang(): void
    {
        $out = HtmlSanitizer::clean('<td align="left; background:url(javascript:alert(1))">x</td>');

        $this->assertStringNotContainsString('javascript', $out);
    }
}
