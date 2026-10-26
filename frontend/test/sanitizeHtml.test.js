import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  decodeEntities,
  escapeHtml,
  hasUnsafeMarkup,
  sanitizeRichText,
} from '../src/utils/sanitizeHtml.js'

describe('sanitizeRichText', () => {
  it('mempertahankan HTML sederhana', () => {
    const html = '<p>Narasi <strong>CALK</strong> 2026.</p>'
    assert.equal(sanitizeRichText(html), html)
  })

  it('membuang script beserta isinya', () => {
    const out = sanitizeRichText('<p>aman</p><script>alert(1)</script>')
    assert.ok(!out.includes('script'))
    assert.ok(!out.includes('alert(1)'))
    assert.ok(out.includes('aman'))
  })

  it('membuang script tanpa tag penutup', () => {
    const out = sanitizeRichText('<p>aman</p><script>alert(1)')
    assert.ok(!out.includes('alert(1)'))
    assert.ok(out.includes('aman'))
  })

  it('membuang event handler', () => {
    const out = sanitizeRichText('<p onclick="alert(1)">teks</p>')
    assert.ok(!out.includes('onclick'))
    assert.ok(out.includes('teks'))
  })

  it('membuang javascript: pada href', () => {
    const out = sanitizeRichText('<a href="javascript:alert(1)">klik</a>')
    assert.ok(!out.includes('javascript:'))
  })

  it('membuang skema javascript: yang disisipi karakter kontrol', () => {
    // "java\tscript:" dan "java\nscript:" diabaikan browser saat
    // menafsirkan skema URL, jadi harus ditolak juga.
    for (const pemisah of ['\t', '\n', '\r', '\x00', '\x1f', '\x7f', ' ']) {
      const out = sanitizeRichText(`<a href="java${pemisah}script:alert(1)">klik</a>`)
      assert.ok(
        !out.includes('script:alert'),
        `skema dengan pemisah ${JSON.stringify(pemisah)} lolos: ${out}`,
      )
    }
  })

  it('membuang skema data: pada href walau disisipi spasi', () => {
    const out = sanitizeRichText('<a href="data :text/html;base64,PHNjcmlwdD4=">klik</a>')
    assert.ok(!out.includes('data'))
  })

  it('membuang data:text/html pada href', () => {
    const out = sanitizeRichText('<a href="data:text/html;base64,PHNjcmlwdD4=">klik</a>')
    assert.ok(!out.includes('data:text/html'))
  })

  it('membuang iframe', () => {
    const out = sanitizeRichText('<p>teks</p><iframe src="https://evil.example"></iframe>')
    assert.ok(!out.includes('iframe'))
    assert.ok(out.includes('teks'))
  })

  it('membuang atribut style', () => {
    const out = sanitizeRichText('<p style="background:url(javascript:alert(1))">teks</p>')
    assert.ok(!out.includes('style='))
  })

  it('mempertahankan tautan aman', () => {
    const out = sanitizeRichText('<a href="https://example.com">tautan</a>')
    assert.ok(out.includes('href="https://example.com"'))
  })

  it('mempertahankan gambar data uri png', () => {
    const out = sanitizeRichText('<img src="data:image/png;base64,iVBORw0KGgo=" alt="ttd">')
    assert.ok(out.includes('data:image/png;base64'))
    assert.ok(out.includes('alt="ttd"'))
  })

  it('membuang gambar data:text/html', () => {
    const out = sanitizeRichText('<img src="data:text/html;base64,PHNjcmlwdD4=">')
    assert.ok(!out.includes('data:text/html'))
  })

  it('membuang nilai formula Quill yang rentan', () => {
    // CVE-2025-15056: blot formula meng-interpolasi nilai mentah ke HTML.
    const out = sanitizeRichText('<span></span><img src=x onerror=alert(1)>')
    assert.ok(!out.includes('onerror'))
    assert.ok(!out.includes('alert(1)'))
  })

  it('membuang nilai video Quill dengan tanda kutip', () => {
    const out = sanitizeRichText('<a href="https://example.com" onmouseover="alert(1)">video</a>')
    assert.ok(!out.includes('onmouseover'))
  })

  it('membuang tag tidak dikenal tapi mempertahankan teksnya', () => {
    const out = sanitizeRichText('<p>tepat <marquee>teks</marquee> di sini</p>')
    assert.ok(!out.includes('marquee'))
    assert.ok(out.includes('teks'))
  })

  it('membuang input dan form', () => {
    const out = sanitizeRichText(
      '<form action="https://evil.example"><input name="a"></form><p>teks</p>',
    )
    assert.ok(!out.includes('<form'))
    assert.ok(!out.includes('<input'))
    assert.ok(out.includes('teks'))
  })

  it('menutup tag yang tidak ditutup', () => {
    const out = sanitizeRichText('<p>teks')
    assert.ok(out.includes('</p>'))
  })

  it('menolak tag penutup tanpa pembuka', () => {
    const out = sanitizeRichText('</p>teks')
    assert.ok(!out.includes('</p>'))
    assert.ok(out.includes('teks'))
  })

  it('nilai kosong menghasilkan string kosong', () => {
    assert.equal(sanitizeRichText(null), '')
    assert.equal(sanitizeRichText(undefined), '')
    assert.equal(sanitizeRichText(''), '')
    assert.equal(sanitizeRichText('   '), '')
  })

  it('idempoten', () => {
    const html =
      '<p>CALK <strong>2026</strong></p><table><tr><td align="center">x</td></tr></table>'
    const once = sanitizeRichText(html)
    assert.equal(once, sanitizeRichText(once))
  })

  it('mempertahankan tabel tanda tangan beserta align', () => {
    const out = sanitizeRichText(
      '<table align="center"><tbody><tr><td align="center" colspan="3">x</td></tr></tbody></table>',
    )
    assert.ok(out.includes('align="center"'))
    assert.ok(out.includes('colspan="3"'))
  })
})

describe('sanitizeRichText -- entitas HTML', () => {
  it('tidak meng-escape entity yang sudah ada di teks', () => {
    // `&nbsp;` harus tetap `&nbsp;`, bukan `&amp;nbsp;`.
    // Kalau jadi `&amp;nbsp;`, browser menampilkannya LITERAL
    // "&nbsp;" -- persis bentuk yang tidak pernah ada di backend.
    const out = sanitizeRichText('<p>a&nbsp;b</p>')
    assert.equal(out, '<p>a&nbsp;b</p>')
  })

  it('tidak meng-escape `&amp;` menjadi `&amp;amp;`', () => {
    const out = sanitizeRichText('<p>AT&amp;T</p>')
    assert.equal(out, '<p>AT&amp;T</p>')
  })

  it('membiarkan `&` yang diikuti huruf tetap utuh, seperti backend', () => {
    // Backend tidak menyentuh isi teks sama sekali -- `strip_tags()`
    // hanya membuang tag. Jadi `AT&T` tetap `AT&T` di database.
    // Frontend harus sama, kalau tidak render frontend dan backend
    // berbeda: frontend menampilkan "AT&T", backend menampilkan
    // "AT&amp;T".
    const out = sanitizeRichText('<p>AT&T</p>')
    assert.equal(out, '<p>AT&T</p>')
  })

  it('mempertahankan entity non-ASCII apa adanya', () => {
    // `&copy;` tidak ada di tabel ASCII, tapi browser tetap
    // mendecodenya jadi "©" -- sama seperti backend.
    const out = sanitizeRichText('<p>&copy; 2026</p>')
    assert.equal(out, '<p>&copy; 2026</p>')
  })

  it('membuang skema javascript: yang disamarkan dengan entity', () => {
    // `&colon;` adalah ";" yang di-decode browser menjadi ":" --
    // sehingga "javascript&colon;alert(1)" sebenarnya adalah
    // "javascript:alert(1)".
    const out = sanitizeRichText('<a href="javascript&colon;alert(1)">klik</a>')
    assert.ok(!out.includes('javascript'), out)
    assert.ok(!out.includes('alert(1)'), out)
  })

  it('membuang skema javascript: yang disamarkan dengan entity numerik', () => {
    for (const href of [
      'java&#115;cript:alert(1)',
      'javascript&#58;alert(1)',
      'javascript&#x3a;alert(1)',
      '&#106;avascript:alert(1)',
    ]) {
      const out = sanitizeRichText(`<a href="${href}">klik</a>`)
      assert.ok(!out.includes('alert(1)'), `href ${href} lolos: ${out}`)
    }
  })

  it('membuang skema javascript: yang memakai tab entity', () => {
    // `&Tab;` adalah tab; browser mengabaikannya saat menafsirkan
    // skema URL.
    const out = sanitizeRichText('<a href="java&Tab;script:alert(1)">klik</a>')
    assert.ok(!out.includes('alert(1)'), out)
  })

  it('mempertahankan URL dengan `&` di dalamnya', () => {
    // URL ini harus tetap punya `&amp;` -- kalau jadi `&amp;amp;`,
    // tautannya rusak (alamat sebenarnya jadi "?a=1&amp;b=2").
    const out = sanitizeRichText('<a href="https://x.test/?a=1&b=2">link</a>')
    assert.equal(out, '<a href="https://x.test/?a=1&amp;b=2">link</a>')
  })

  it('tidak mengubah URL yang sudah punya `&amp;`', () => {
    const out = sanitizeRichText('<a href="https://x.test/?a=1&amp;b=2">link</a>')
    assert.equal(out, '<a href="https://x.test/?a=1&amp;b=2">link</a>')
  })

  it('mempertahankan nilai atribut yang memuat `>` di dalam kutip', () => {
    // `>` di dalam nilai ber-kutip itu sah. Regex tokenizer yang
    // lama berhenti di `>` pertama, sehingga nilainya hilang.
    const out = sanitizeRichText('<a title="Total > 500">x</a>')
    assert.equal(out, '<a title="Total &gt; 500">x</a>')
  })

  it('mempertahankan tanda kurung siku di teks', () => {
    const out = sanitizeRichText('<p>a &lt; b</p>')
    assert.equal(out, '<p>a &lt; b</p>')
  })

  it('idempoten untuk HTML berentitas', () => {
    const html = '<p>a&nbsp;b &amp; c</p><a href="https://x.test/?a=1&amp;b=2">link</a>'
    const once = sanitizeRichText(html)
    assert.equal(once, sanitizeRichText(once))
  })

  it('idempoten untuk tag yang ditulis self-closing', () => {
    // HTML5 mengabaikan garis miring penutup pada elemen yang bukan
    // void -- jadi `<span/>` itu tag PEMBUKA, bukan tag tertutup.
    //
    // Kalau sanitizer menganggapnya sudah tertutup, tag penutupnya
    // hilang: `<span/>x` -> `<span>x`. Disanitasi lagi (backend
    // menyanitasi saat baca, frontend menyanitasi lagi sebelum
    // render) hasilnya jadi `<span>x</span>` -- jadi HTML-nya berubah
    // setelah disanitasi kedua kali.
    for (const html of ['<span/>x', '<p/>', '<div/>', '<p>a<br/>b</p>']) {
      const once = sanitizeRichText(html)
      assert.equal(once, sanitizeRichText(once), `tidak idempoten: ${html}`)
    }
  })

  it('tag void tidak pernah mendapat tag penutup', () => {
    // `br` dan `img` memang tidak punya penutup -- baik ditulis
    // `<br>` maupun `<br />`.
    assert.equal(sanitizeRichText('<p>a<br/>b</p>'), '<p>a<br>b</p>')
    assert.equal(sanitizeRichText('<p>a<br>b</p>'), '<p>a<br>b</p>')
  })

  it('tag non-void yang ditulis self-closing tetap mendapat penutup', () => {
    assert.equal(sanitizeRichText('<span/>x'), '<span>x</span>')
    assert.equal(sanitizeRichText('<p/>'), '<p></p>')
  })
})

describe('decodeEntities', () => {
  it('mendecode entity bernama', () => {
    assert.equal(decodeEntities('&amp;'), '&')
    assert.equal(decodeEntities('&nbsp;'), ' ')
    assert.equal(decodeEntities('&colon;'), ':')
  })

  it('mendecode entity numerik desimal dan heksadesimal', () => {
    assert.equal(decodeEntities('&#58;'), ':')
    assert.equal(decodeEntities('&#x3a;'), ':')
  })

  it('mendecode hanya satu kali', () => {
    // Hasil decoding tidak boleh ikut dipindai lagi -- kalau iya,
    // `&amp;colon;` akan jadi ":" dan celah javascript: terbuka.
    assert.equal(decodeEntities('&amp;#58;'), '&#58;')
    assert.equal(decodeEntities('&amp;colon;'), '&colon;')
  })

  it('membiarkan reference yang tidak dikenal apa adanya', () => {
    assert.equal(decodeEntities('&notarealentity;'), '&notarealentity;')
    // `&notit;` -- PHP tidak mengenalnya, jadi dibiarkan apa adanya.
    // Browser men-encode-nya jadi "¬it;", tapi yang menentukan isi
    // tersimpan adalah backend.
    assert.equal(decodeEntities('&notit;'), '&notit;')
  })

  it('membiarkan code point yang tidak valid apa adanya', () => {
    // PHP juga menolak surrogate, noncharacter, dan C1.
    assert.equal(decodeEntities('&#xD800;'), '&#xD800;')
    assert.equal(decodeEntities('&#xFFFE;'), '&#xFFFE;')
    assert.equal(decodeEntities('&#x110000;'), '&#x110000;')
  })

  it('mendecode entity bernama secara sensitive-case seperti PHP', () => {
    // `&AMP;` dan `&amp;` sama-sama dikenal. `&Amp;` TIDAK dikenal
    // PHP -- jadi harus dibiarkan apa adanya.
    assert.equal(decodeEntities('&AMP;'), '&')
    assert.equal(decodeEntities('&amp;'), '&')
    assert.equal(decodeEntities('&Amp;'), '&Amp;')
  })

  it('membiarkan numeric reference tanpa titik koma apa adanya', () => {
    // Backend (PHP) membutuhkan titik koma, dan backend yang
    // menentukan isi tersimpan. Browser lebih longgar, tapi
    // frontend harus meniru backend -- bukan meniru browser.
    assert.equal(decodeEntities('&#58'), '&#58')
    assert.equal(decodeEntities('&#x3a'), '&#x3a')
    assert.equal(decodeEntities('&amp'), '&amp')
  })

  it('menerima null dan undefined', () => {
    assert.equal(decodeEntities(null), '')
    assert.equal(decodeEntities(undefined), '')
  })
})

describe('escapeHtml', () => {
  it('meng-escape seluruh karakter berbahaya', () => {
    assert.equal(
      escapeHtml(`<img src="x" onerror='alert(1)'>&`),
      '&lt;img src=&quot;x&quot; onerror=&#039;alert(1)&#039;&gt;&amp;',
    )
  })

  it('membiarkan `&` yang memulai character reference', () => {
    // Kalau `&` selalu jadi `&amp;`, entity yang sudah ada di teks
    // akan tampil LITERALLY di browser -- "AT&amp;T" bukan "AT&T".
    assert.equal(escapeHtml('AT&amp;T'), 'AT&amp;T')
    assert.equal(escapeHtml('a&nbsp;b'), 'a&nbsp;b')
    assert.equal(escapeHtml('&#58;'), '&#58;')
  })

  it('meng-escape `&` yang tidak memulai character reference', () => {
    // `&` diikuti spasi atau karakter lain bukan awal reference.
    assert.equal(escapeHtml('a & b'), 'a &amp; b')
    assert.equal(escapeHtml('100% & lebih'), '100% &amp; lebih')
  })

  it('menangani null dan undefined', () => {
    assert.equal(escapeHtml(null), '')
    assert.equal(escapeHtml(undefined), '')
  })
})

describe('hasUnsafeMarkup', () => {
  it('mendeteksi payload yang dibuang', () => {
    assert.equal(hasUnsafeMarkup('<p onclick="alert(1)">teks</p>'), true)
  })

  it('tidak menandai HTML aman', () => {
    assert.equal(hasUnsafeMarkup('<p>CALK <strong>2026</strong></p>'), false)
  })

  it('tidak menandai URL dengan `&` sebagai curiga', () => {
    // Sanitasi yang benar mengubah `&` jadi `&amp;` pada atribut.
    // Maknanya sama persis, jadi ini BUKAN sesuatu yang perlu
    // diperingatkan ke pengguna -- kalau iya, peringatan itu akan
    // muncul untuk hampir semua tautan.
    assert.equal(hasUnsafeMarkup('<a href="https://x.test/?a=1&b=2">link</a>'), false)
  })

  it('tidak menandai teks berentitas sebagai curiga', () => {
    assert.equal(hasUnsafeMarkup('<p>a&nbsp;b &amp; c</p>'), false)
  })

  it('mendeteksi skema javascript: yang disamarkan dengan entity', () => {
    // Setelah di-decode, `javascript&colon;alert(1)` menjadi
    // `javascript:alert(1)` -- maknanya berubah, jadi ini curiga.
    assert.equal(hasUnsafeMarkup('<a href="javascript&colon;alert(1)">x</a>'), true)
  })
})
