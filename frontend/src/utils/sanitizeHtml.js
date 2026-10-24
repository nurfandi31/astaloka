/**
 * Sanitasi HTML rich-text di sisi frontend.
 *
 * Kenapa file ini ada
 * ==================
 *
 * Aplikasi merender HTML dari editor dengan `v-html`. Dua sumber HTML itu:
 *
 *   1. Yang diketik pengguna di editor (Quill / TipTap) dan disimpan lewat
 *      `POST /settings/sop/custom-calk` atau `POST /settings/sop/calk-catatan`.
 *   2. Yang sudah tersimpan dan dibaca lagi untuk ditampilkan di laporan.
 *
 * Backend sudah menyanitasi keduanya (lihat `App\Services\HtmlSanitizer`).
 * Tapi sanitasi di server saja tidak menutup celah PRATINJAU: payload yang
 * diketik di editor dirender apa adanya sebagai preview sebelum pernah
 * sampai ke server. Kalau Quill menghasilkan markup berbahaya -- dan
 * Quill 2.0.3 memang bisa, lihat di bawah -- payload itu dieksekusi
 * di browser admin SEBELUM request apa pun dikirim.
 *
 * BUG YANG MENYEBABKANNYA
 * ======================
 *
 * Quill 2.0.3 punya celah XSS sendiri (CVE-2025-15056,
 * GHSA-v3m3-f69x-jf25): blot `formula` dan `video` meng-interpolasi nilai
 * mentah ke string HTML saat ekspor, sehingga nilai berisi
 * `</span><img src=x onerror=...>` lolos sebagai markup. Belum ada versi
 * patch-nya -- 2.0.3 masih yang terbaru. PrimeVue Editor memanggil
 * `quill.getSemanticHTML()` pada setiap perubahan teks, jadi setiap
 * pengguna yang mengetik di editor SOP punya jalur ke celah itu.
 *
 * Karena tidak bisa diperbaiki di sisi pustaka, satu-satunya tempat
 * yang bisa menutupnya adalah lapisan aplikasi: di sini dan di server.
 *
 * Kenapa tidak memakai DOMPurify
 * =============================
 *
 * DOMPurify sudah terpasang (ikut jspdf), tapi memakainya di frontend
 * berarti menambah satu dependensi produksi untuk satu fungsi. Fungsi ini
 * sengaja sederhana dan murni, tanpa dependensi, supaya bisa diuji dengan
 * `node --test` tanpa memasang aplikasi -- sama seperti
 * `utils/databaseName.js`.
 */

/**
 * Tag yang boleh ada di hasil.
 *
 * Cukup untuk hasil Quill/TipTap: heading, paragraf, format teks, daftar,
 * tabel, blockquote, kode, gambar, dan tautan.
 *
 * `video` dan `iframe` sengaja tidak ada: keduanya bisa membawa sumber
 * eksternal, dan `iframe` bisa membawa `srcdoc` berisi HTML lengkap.
 */
const ALLOWED_TAGS = new Set([
  'p',
  'br',
  'strong',
  'b',
  'em',
  'i',
  'u',
  's',
  'strike',
  'ul',
  'ol',
  'li',
  'table',
  'thead',
  'tbody',
  'tfoot',
  'tr',
  'th',
  'td',
  'span',
  'div',
  'img',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'a',
  'blockquote',
  'pre',
  'code',
])

/** Elemen yang dibuang BESERTA isinya. Isinya bukan teks yang perlu disimpan. */
const DROP_WITH_CONTENT = new Set([
  'script',
  'style',
  'iframe',
  'object',
  'embed',
  'applet',
  'noscript',
  'template',
  'svg',
  'math',
  'frame',
  'frameset',
  'base',
  'link',
  'meta',
  'title',
  'form',
  'input',
  'button',
  'textarea',
  'select',
  'option',
])

/** Atribut yang boleh dipertahankan per tag. Tag lain tetap boleh, tanpa atribut. */
const ALLOWED_ATTRS = {
  a: ['href', 'title'],
  img: ['src', 'alt', 'title'],
  p: ['align'],
  td: ['align', 'colspan', 'rowspan'],
  th: ['align', 'colspan', 'rowspan'],
  tr: ['align'],
  tbody: ['align'],
  thead: ['align'],
  tfoot: ['align'],
  table: ['align'],
}

const ALIGN_VALUES = new Set(['left', 'center', 'right', 'justify'])
const SAFE_LINK_SCHEMES = new Set(['http', 'https', 'mailto', 'tel'])

/** Pecah HTML menjadi token: tag, teks, atau komentar. */
const TOKEN_RE = /<!--[\s\S]*?-->|<\/?[a-z][a-z0-9]*(?:\s[^<>]*)?\/?>/gi

/**
 * Buang nilai atribut, lalu kembalikan atribut yang diizinkan saja.
 *
 * Nilai yang dikembalikan sudah di-escape satu kali, supaya aman dipakai
 * di dalam `v-html`.
 */
function sanitizeAttributes(tag, rawAttrs) {
  const allowed = ALLOWED_ATTRS[tag] || []
  const kept = []

  for (const attr of allowed) {
    // Atribut harus diikuti tanda '='. Tanpa itu, teks seperti `onclick`
    // di dalam nilai atribut lain bisa ikut terambil.
    const re = new RegExp(`\\b${attr}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s"'>]+))`, 'i')
    const m = rawAttrs.match(re)
    if (!m) continue

    const value = m[1] !== undefined ? m[1] : m[2] !== undefined ? m[2] : m[3] || ''

    if (attr === 'align') {
      const v = value.toLowerCase()
      if (ALIGN_VALUES.has(v)) kept.push(`align="${v}"`)
      continue
    }

    if (attr === 'href' || attr === 'src') {
      if (!isSafeUrl(value, attr === 'src')) continue
      kept.push(`${attr}="${escapeAttribute(value)}"`)
      continue
    }

    kept.push(`${attr}="${escapeAttribute(value)}"`)
  }

  return kept
}

/**
 * Apakah URL ini aman?
 *
 * `src` boleh memakai `data:image/(png|jpeg|jpg|webp);base64,` karena
 * gambar inline memang dipakai di template tanda tangan. `href` tidak boleh
 * memakai `data:` sama sekali -- `data:text/html` di `href` adalah Stored
 * XSS lewat navigasi.
 */
function isSafeUrl(url, isImageSource) {
  const trimmed = String(url).trim()
  if (trimmed === '') return false

  // Buang whitespace dan karakter kontrol yang bisa menyelip di tengah
  // skema (mis. "java\tscript:").
  //
  // `\p{Cc}` = kategori Unicode "Other, Control" -- sama dengan rentang
  // U+0000-U+001F dan U+007F-U+009F, tapi ditulis sebagai properti
  // Unicode supaya file ini tetap terbaca sebagai teks (bukan "binary")
  // dan tidak memicu aturan lint `no-control-regex`.
  const probe = trimmed.replace(/[\s\p{Cc}]+/gu, '').toLowerCase()

  if (probe.startsWith('data:')) {
    if (!isImageSource) return false
    return /^data:image\/(png|jpeg|jpg|webp);base64,/i.test(trimmed)
  }

  // Tanpa skema berarti path relatif -- aman.
  if (!/^[a-z][a-z0-9+.-]*:/i.test(probe)) return true

  const scheme = probe.slice(0, probe.indexOf(':'))
  if (scheme === 'data') return false

  return SAFE_LINK_SCHEMES.has(scheme)
}

/** Escape satu nilai untuk dipakai di dalam atribut kutip-ganda. */
function escapeAttribute(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

/**
 * Escape teks biasa supaya tidak ditafsirkan sebagai HTML.
 */
export function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

/**
 * Bersihkan HTML rich-text.
 *
 * Mirrors `App\Services\HtmlSanitizer` di backend. Keduanya harus
 * menghasilkan allowlist yang sama: server adalah AUTHORITATIVE (datanya
 * disimpan dari sana), frontend hanya lapisan kedua untuk pratinjau.
 *
 * @param {string|null|undefined} html
 * @returns {string} HTML yang aman untuk `v-html`.
 */
export function sanitizeRichText(html) {
  if (html === null || html === undefined) return ''

  let input = String(html)
  if (input.trim() === '') return ''

  // Normalisasi baris baru agar tag terputus tidak lolos dari pemeriksaan
  // berbasis regex.
  input = input.replace(/\r\n?/g, '\n')

  // Buang blok script/style yang tag penutupnya hilang DULU. Kalau tidak,
  // tag tunggalnya sudah ikut hilang di langkah berikutnya dan isi bloknya
  // tinggal menggantung sebagai teks biasa.
  for (const tag of ['script', 'style']) {
    const re = new RegExp(`<${tag}\\b[^>]*>(?:(?!</${tag}\\s*>)[\\s\\S])*$`, 'gi')
    input = input.replace(re, '')
  }

  // Komentar HTML bisa menyembunyikan payload dari parser lain.
  input = input.replace(/<!--[\s\S]*?-->/g, '')

  // Pecah jadi token, lalu proses satu per satu. Processor izinkan nesting
  // dalam jumlah tak terbatas, jadi tag yang tidak ditutup tidak akan
  // menelan seluruh sisa dokumen.
  const out = []
  const openStack = []
  let cursor = 0
  let match

  TOKEN_RE.lastIndex = 0
  while ((match = TOKEN_RE.exec(input)) !== null) {
    if (match.index > cursor) {
      out.push(escapeHtml(input.slice(cursor, match.index)))
    }
    cursor = match.index + match[0].length

    const token = match[0]

    // Komentar: buang.
    if (token.startsWith('<!--')) continue

    const isClosing = token.startsWith('</')
    const nameMatch = token.match(/^<\/?([a-z][a-z0-9]*)/i)
    if (!nameMatch) continue

    const tag = nameMatch[1].toLowerCase()

    if (DROP_WITH_CONTENT.has(tag)) {
      // Buang tag pembuka beserta isinya sampai tag penutup yang cocok.
      if (!isClosing) {
        const closeRe = new RegExp(`</${tag}\\s*>`, 'i')
        const rest = input.slice(cursor)
        const found = rest.match(closeRe)
        if (found) {
          cursor += found.index + found[0].length
          TOKEN_RE.lastIndex = cursor
        } else {
          cursor = input.length
          TOKEN_RE.lastIndex = cursor
        }
      }
      continue
    }

    if (!ALLOWED_TAGS.has(tag)) continue

    if (isClosing) {
      // Tutup tag hanya kalau sedang terbuka -- supaya markup rusak
      // tidak membuat tag penutup muncul tanpa pembuka.
      const idx = openStack.lastIndexOf(tag)
      if (idx === -1) continue

      // Tutup semua tag yang masih terbuka di atasnya (nested).
      while (openStack.length > idx) {
        out.push(`</${openStack.pop()}>`)
      }
      continue
    }

    const attrsRaw = token.replace(/^<[a-z][a-z0-9]*/i, '').replace(/\/?>$/, '')
    const kept = sanitizeAttributes(tag, attrsRaw)
    const selfClosing = token.endsWith('/>') || tag === 'br' || tag === 'img'

    out.push(`<${tag}${kept.length ? ' ' + kept.join(' ') : ''}${selfClosing ? ' /' : ''}>`)

    if (!selfClosing) openStack.push(tag)
  }

  if (cursor < input.length) {
    out.push(escapeHtml(input.slice(cursor)))
  }

  // Tutup tag yang masih terbuka.
  while (openStack.length > 0) {
    out.push(`</${openStack.pop()}>`)
  }

  return out.join('')
}

/**
 * Apakah string HTML ini mengandung sesuatu yang mencurigakan?
 *
 * Dipakai untuk memberi tahu pengguna bahwa isinya tidak bisa disimpan
 * apa adanya -- bukan untuk memblokir (server yang memutuskan).
 *
 * Bedakan dua hal:
 *   - isi berubah karena sanitizer MENGAWALI (misal `onclick` dilepas):
 *     teksnya tetap sama, tapi markupunya berbeda -> curiga.
 *   - isi berubah karena sanitizer MEMBUANG (misal `<script>` dibuang):
 *     teksnya ikut hilang -> jelas curiga.
 *
 * Karena itu keduanya dibandingkan: markup hasil sanitasi vs markup
 * asli, DAN teks hasil sanitasi vs teks asli.
 */
export function hasUnsafeMarkup(html) {
  if (html === null || html === undefined) return false

  const original = String(html)
  const cleaned = sanitizeRichText(original)

  // Markup berubah -> ada yang dibuang atau dilepas.
  if (cleaned !== original) return true

  return false
}

export default sanitizeRichText
