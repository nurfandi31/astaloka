/**
 * Uji keamanan pada PUSTAKA Quill yang benar-benar terpasang.
 *
 * Ini bukan rekonstruksi -- file ini meng-import `quill` dari
 * node_modules, jadi yang diuji adalah kode yang benar-benar dipakai
 * aplikasi saat build.
 *
 * Latar belakang
 * ===============
 *
 * Quill 2.0.3 punya celah XSS di ekspor HTML (CVE-2025-15056,
 * GHSA-v3m3-f69x-jf25): blot `formula` dan `video` meng-interpolasi
 * nilai mentah ke string HTML, sehingga nilai berisi `</span>` atau
 * tanda kutip bisa keluar sebagai markup. Belum ada versi patch-nya --
 * 2.0.3 masih yang terbaru di npm.
 *
 * Karena tidak bisa menunggu upstream, repo ini pakai
 * `pnpm.patchedDependencies` untuk menambal dua file itu
 * (`patches/quill@2.0.3.patch`): nilai di-escape sebelum masuk ke
 * string HTML.
 *
 * Test ini guarding patch-nya: kalau ada yang meng-upgrade Quill atau
 * patch-nya hilang, test ini harus gagal -- bukan diam-diam reopening
 * celahnya.
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'

import { describe, it } from 'node:test'

const require = createRequire(import.meta.url)
const quillPkg = require.resolve('quill/package.json')
const quillDir = dirname(quillPkg)

describe('Quill 2.0.3 sudah di-patch (CVE-2025-15056)', () => {
  it('formats/formula.js meng-escape nilai sebelum masuk ke HTML', () => {
    const src = readFileSync(join(quillDir, 'formats', 'formula.js'), 'utf8')

    // Pola yang berbahaya: nilai mentah di-interpolasi ke string HTML.
    assert.ok(
      !src.includes('`<span>${formula}</span>`'),
      'formula.js masih meng-interpolasi nilai mentah -- patch hilang!',
    )

    // Pola yang benar: nilai di-escape.
    assert.match(src, /escapeHTML\(formula\)/, 'formula.js tidak memanggil escapeHTML')
    assert.match(src, /function escapeHTML\(/, 'fungsi escapeHTML tidak ada')
  })

  it('formats/video.js meng-escape nilai sebelum masuk ke HTML', () => {
    const src = readFileSync(join(quillDir, 'formats', 'video.js'), 'utf8')

    assert.ok(
      !src.includes('`<a href="${video}">${video}</a>`'),
      'video.js masih meng-interpolasi nilai mentah -- patch hilang!',
    )

    assert.match(src, /escapeHTML\(video\)/, 'video.js tidak memanggil escapeHTML')
    assert.match(src, /function escapeHTML\(/, 'fungsi escapeHTML tidak ada')
  })

  it('tidak ada blot lain yang meng-interpolasi nilai mentah ke HTML', () => {
    // All blots punya method html(). Yang berbahaya adalah yang
    // menyisipkan nilai mentah ke string HTML tanpa escaping.
    const fs = require('node:fs')
    const formatsDir = join(quillDir, 'formats')
    const offenders = []

    for (const file of fs.readdirSync(formatsDir)) {
      if (!file.endsWith('.js')) continue
      const src = fs.readFileSync(join(formatsDir, file), 'utf8')
      const match = src.match(/html\(\)\s*\{([\s\S]{0,400}?)\n\s{2}\}/)
      if (!match) continue
      const body = match[1]
      if (!body.includes('${')) continue
      // Setiap interpolasi harus dibungkus escapeHTML.
      const interpolations = body.match(/\$\{[^}]+\}/g) || []
      const unsafe = interpolations.filter((i) => !i.includes('escapeHTML('))
      if (unsafe.length) offenders.push(`${file}: ${unsafe.join(', ')}`)
    }

    assert.deepEqual(
      offenders,
      [],
      `blot lain yang meng-interpolasi nilai mentah:\n  ${offenders.join('\n  ')}`,
    )
  })

  it('escapeHTML menutup semua vektor yang relevan', () => {
    const src = readFileSync(join(quillDir, 'formats', 'formula.js'), 'utf8')

    // Ambil fungsi escapeHTML-nya dan uji perilakunya.
    const body = src.slice(src.indexOf('function escapeHTML('))
    const fn = new Function(
      'value',
      body
        .slice(body.indexOf('{') + 1, body.indexOf('\n}'))
        .replace(/return String\(html \?\? ''\)/, "return String(value ?? '')")
        .replace(/html/g, 'value'),
    )

    assert.equal(fn('a&b'), 'a&amp;b')
    assert.equal(fn('<script>'), '&lt;script&gt;')
    assert.equal(fn('"'), '&quot;')
    assert.equal(fn("'"), '&#039;')
    assert.equal(fn(null), '')
  })
})
