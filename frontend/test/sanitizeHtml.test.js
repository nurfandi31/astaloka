import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { escapeHtml, hasUnsafeMarkup, sanitizeRichText } from '../src/utils/sanitizeHtml.js'

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

describe('escapeHtml', () => {
  it('meng-escape seluruh karakter berbahaya', () => {
    assert.equal(
      escapeHtml(`<img src="x" onerror='alert(1)'>&`),
      '&lt;img src=&quot;x&quot; onerror=&#039;alert(1)&#039;&gt;&amp;',
    )
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
})
