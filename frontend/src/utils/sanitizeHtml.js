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
 *
 * TENTANG ENTITAS HTML
 * ====================
 *
 * Dua aturan yang dipakai file ini punya alasan yang sama: hasilnya
 * harus identik dengan yang dihasilkan `App\Services\HtmlSanitizer` di
 * backend, dan harus aman ketika dibaca browser.
 *
 * Aturan 1 -- teks TIDAK boleh di-escape dua kali.
 *
 * `escapeHtml` biasa mengubah `&` jadi `&amp;`. Itu benar untuk teks yang
 * belum pernah di-escape, tapi salah untuk HTML yang sudah contains
 * entity: `AT&amp;T` jadi `AT&amp;amp;T`, dan `&nbsp;` jadi
 * `&amp;nbsp;`. Browser lalu menampilkannya LITERALLY -- "AT&amp;T" --
 * bukan "AT&T". Backend tidak punya masalah itu karena `strip_tags()`
 * tidak menyentuh isi teks.
 *
 * Jadi di teks, `&` hanya di-escape kalau TIDAK memulai character
 * reference. `&nbsp;`, `&amp;`, `&copy;`, `&#58;` dibiarkan apa adanya:
 * browser mendecode-nya persis seperti backend, jadi hasil render-nya
 * sama dan byte-nya juga sama.
 *
 * Aturan 2 -- nilai atribut harus di-decode SEBELUM skema URL diperiksa.
 *
 * `href="javascript&colon;alert(1)"` terlihat seperti path relatif kalau
 * diperiksa apa adanya -- tidak ada tanda titik dua. Tapi browser
 * mendecode `&colon;` jadi `:` dan payload-nya jadi `javascript:alert(1)`.
 * Kalau atribut lalu di-escape apa adanya, `&` jadi `&amp;`, jadi
 * `&amp;colon;` -- browser mendecode `&amp;` jadi `&` dan BERHENTI di
 * situ. Satu decoding saja, bukan dua. Hasilnya `&colon;`, bukan `:`.
 * Jadi order-nya wajib: decode dulu (untuk memeriksa skema), lalu
 * encode ulang (untuk dikirim ke browser).
 *
 * Decode -> periksa -> encode ulang adalah urutan yang sama dengan
 * `html_entity_decode()` lalu `htmlspecialchars()` di PHP.
 */

import ENTITIES from './htmlEntities.js'

/**
 * Tabel entity HTML5 yang dikenal PHP.
 *
 * Diimpor dari `htmlEntities.js` -- 2125 entri named dengan titik koma.
 * Dipisah ke berkas sendiri supaya tabel yang besar ini tidak menutupi
 * logika sanitizer yang perlu dibaca orang.
 *
 * Kenapa tabel LENGKAP, bukan hanya yang ASCII
 * ============================================
 *
 * Backend menulis nilai atribut dengan urutan: `html_entity_decode()`
 * lalu `htmlspecialchars()`. `htmlspecialchars()` mengubah setiap `&`
 * jadi `&amp;` -- jadi entri APAPUN yang sudah ada di nilai itu berubah
 * bentuk, dan harus bisa di-decode kembali supaya hasilnya sama.
 *
 * Kalau tabelnya hanya entri ASCII, `&copy;` tidak ter-decode, dan
 * frontend merender "&copy;" LITERAL -- sementara backend merender "©".
 * Dua-duanya salah terhadap yang satu sama lain.
 *
 * Yang menentukan isi tersimpan adalah backend, jadi frontend harus
 * meniru backend persis, bukan meniru browser.
 */
/**
 * Pola character reference: numerik desimal, numerik heksadesimal, dan
 * bernama.
 *
 * Titik koma WAJIB ada -- sama seperti `html_entity_decode()` di PHP.
 * Browser boleh memakai bentuk tanpa titik koma (`&#58`), tapi backend
 * tidak, dan yang menentukan isi tersimpan adalah backend.
 *
 * Nama entity DICOCOKKAN SECARA SENSITIVE-CASE, seperti PHP:
 * `&AMP;` dan `&amp;` sama-sama dikenal, tapi `&Amp;` TIDAK -- dan
 * `&Colon;` berarti "∷", bukan ":".
 *
 * Karena itu nama yang tertangkap dicek apa adanya terhadap tabel --
 * BUKAN dilowercase-kan. Kalau dilowercase, `&Colon;` akan salah
 * menjadi `&colon;` dan berubah dari "∷" jadi ":", dan `&Amp;` akan
 * salah dikenal padahal PHP tidak mengenalnya.
 * Bentuk heksadesimal tetap punya `#`: `&#x3a;`, bukan `&x3a;`.
 */
const ENTITY_RE = /&(?:#([0-9]+)|#([xX])([0-9a-fA-F]+)|([a-zA-Z][a-zA-Z0-9]*));/g

/**
 * Apakah code point ini boleh dipakai sebagai character reference?
 *
 * Aturannya sama dengan yang dipakai PHP: karakter kontrol C0 boleh
 * kecuali TAB, LF, dan FF; CR tidak boleh; karakter C1 (0x7F-0x9F)
 * tidak boleh; surrogate tidak boleh; noncharacter tidak boleh; dan
 * nilai di luar rentang Unicode tidak boleh.
 *
 * Code point yang ditolak dibiarkan apa adanya sebagai teks -- bukan
 * diganti U+FFFD. Itu yang dilakukan PHP juga, dan itu yang dilakukan
 * browser kalau reference-nya memang tidak valid.
 */
function isValidCodePoint(codePoint) {
  if (codePoint < 0x20) return codePoint === 0x09 || codePoint === 0x0a || codePoint === 0x0c
  if (codePoint >= 0x7f && codePoint <= 0x9f) return false
  if (codePoint >= 0xd800 && codePoint <= 0xdfff) return false
  if (codePoint >= 0xfdd0 && codePoint <= 0xfdef) return false
  if (codePoint > 0x10ffff) return false
  if ((codePoint & 0xffff) === 0xfffe || (codePoint & 0xffff) === 0xffff) return false
  return true
}

/**
 * Decode character reference seperti `html_entity_decode()`.
 *
 * Satu kali saja. Hasil decoding TIDAK ikut dipindai lagi -- jadi
 * `&amp;#58;` jadi `&#58;`, bukan `:`. Aturan "satu kali" ini yang
 * membuat encode-ulang jadi aman: kalau `&` hasil decoding ikut
 * dipindai, `&amp;colon;` akan jadi `:` dan celah
 * `javascript:` terbuka lagi.
 *
 * @param {string|null|undefined} value
 * @returns {string}
 */
export function decodeEntities(value) {
  const text = String(value ?? '')
  if (!text.includes('&')) return text

  return text.replace(ENTITY_RE, (match, dec, hexMark, hex, name) => {
    if (name !== undefined) {
      const known = ENTITIES.get(name)
      return known === undefined ? match : known
    }

    const codePoint = dec !== undefined ? Number.parseInt(dec, 10) : Number.parseInt(hex, 16)

    if (!Number.isFinite(codePoint) || !isValidCodePoint(codePoint)) return match

    return String.fromCodePoint(codePoint)
  })
}

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

/**
 * Tag void: tag yang TIDAK PUNYA tag penutup.
 *
 * Hanya `br` dan `img` yang void di allowlist ini. Tag lain -- termasuk
 * `span`, `p`, `div` -- butuh tag penutup, meskipun sumbernya ditulis
 * self-closing (`<span/>`).
 *
 * Kenapa ini penting, dan bukan sekadar detail teknis:
 *
 * HTML5 mengabaikan garis miring penutup pada elemen yang bukan void
 * dan bukan elemen foreign. Jadi `<span/>` bukan tag yang "sudah
 * tertutup" -- dia tag PEMBUKA, persis seperti `<span>`. Kalau
 * sanitizer memperlakukannya sebagai tag tertutup, hasilnya rusak:
 *
 *     <span/>x  ->  <span>x        (span-nya menggantung, tak tertutup)
 *
 * Lalu kalau HTML itu disanitasi lagi -- dan itu memang terjadi:
 * backend menyanitasi saat baca (`SignatureService::templates()`),
 * lalu frontend menyanitasi lagi sebelum merender -- hasilnya jadi
 * `<span>x</span>`. Jadi HTML-nya berubah setelah disanitasi kedua
 * kali: sanitizer tidak idempoten.
 *
 * Menentukan void-ness dari NAMA TAG membuat dua hal selalu benar:
 * tag yang butuh penutup selalu mendapatnya, dan tag void tidak
 * pernah mendapatnya.
 */
const VOID_TAGS = new Set(['br', 'img'])

/**
 * Pecah HTML menjadi token: tag, teks, atau komentar.
 *
 * Karakter `>` BOLEH muncul di dalam nilai atribut yang diapit tanda
 * kutip -- `href="a>b"` itu tag yang sah, dan pemecah token harus
 * berhenti di situ, bukan di `>` pertama. Regex lama (`\s[^<>]*`)
 * salah baca itu sebagai tag yang berakhir di `>` pertama, sehingga
 * sisa `b">` bocor sebagai teks dan atributnya hilang.
 *
 * Sebaliknya, `>` di dalam nilai atribut TANPA kutip TIDAK boleh
 * diterima -- HTML menetapkan itu sebagai akhir tag. Jadi dua bentuk
 * kutip punya aturan berbeda, dan regex ini membedakannya.
 *
 * Atribut tanpa nilai (`disabled`) juga diterima, lalu dibuang oleh
 * allowlist -- tidak ada satu pun atribut tanpa nilai yang diizinkan.
 *
 * Slash di akhir (`<img ... />`) harus tetap diterima sebagai penanda
 * tag void. Karena itu slash SETELAH nilai atribut boleh -- tapi slash
 * yang ada DI TENGAH atribut (`<img/src="x"/onerror="alert(1)">`)
 * TIDAK, sebab itu sebenarnya atribut bernama "src" dan "onerror" yang
 * dipisahkan slash, dan HTML memperlakukannya sebagai atribut biasa.
 */
const TOKEN_RE =
  /<!--[\s\S]*?-->|<\/?[a-z][a-z0-9]*(?:\s+[^\s"'<>/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'=<>`]*))?)*\s*\/?>/gi

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

    const raw = m[1] !== undefined ? m[1] : m[2] !== undefined ? m[2] : m[3] || ''

    /*
     * Decode DULU, baru pakai.
     *
     * Nilai atribut yang sudah ter-escape di sumbernya (`&amp;`,
     * `&colon;`, `&#58;`) arrive ke sini apa adanya. Kalau diperiksa
     * apa adanya, `javascript&colon;alert(1)` tidak punya titik dua
     * dan terbaca sebagai path relatif -- padahal browser akan
     * mendecode `&colon;` jadi `:` dan menjalankannya.
     *
     * Backend melakukan hal yang sama: `html_entity_decode()` dulu,
     * baru `isSafeUrl()`, baru `htmlspecialchars()` untuk menulis
     * kembali.
     */
    const value = decodeEntities(raw)

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

/**
 * Escape satu nilai untuk dipakai di dalam atribut kutip-ganda.
 *
 * Setara dengan `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` di PHP:
 * `&` jadi `&amp;`, `<` jadi `&lt;`, `>` jadi `&gt;`, `"` jadi `&quot;`,
 * `'` jadi `&#039;`.
 *
 * Nilai yang masuk ke sini SUDAH di-decode (`decodeEntities`), jadi
 * meng-escape `&` di sini aman dan tidak mengulang escaping yang
 * sudah ada di sumbernya. Hasilnya tepat sama dengan yang ditulis
 * backend, dan browser mendecodenya kembali ke nilai yang sama.
 */
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
 *
 * `&` TIDAK selalu di-escape jadi `&amp;`.
 *
 * Kalau selalu, teks yang sudah berisi entity rusak: `AT&amp;T` jadi
 * `AT&amp;amp;T` dan `&nbsp;` jadi `&amp;nbsp;`. Browser
 * menampilkannya LITERALLY -- "AT&amp;T", bukan "AT&T" -- padahal
 * backend menyimpannya apa adanya. Hasil render frontend dan backend
 * jadi berbeda, dan angka `&` di laporan berubah jadi lebih banyak.
 *
 * Jadi `&` hanya di-escape kalau TIDAK memulai character reference:
 * `&` diikuti huruf atau `#`. Dengan begitu `&nbsp;`, `&amp;`,
 * `&copy;`, `&#58;` dibiarkan apa adanya -- browser mendecode-nya
 * persis seperti yang dilakukan backend, jadi teksnya sama.
 *
 * Yang TIDAK boleh lolos adalah `&` yang tidak memulai reference
 * apa pun -- misalnya `&lt;` yang entah bagaimana sudah jadi teks
 * polos. Karena `&lt;` memang reference yang sah, `&` di dalamnya
 * dibiarkan, dan `lt;` ikut ter-decode browser menjadi `<` sebagai
 * TEKS -- bukan sebagai tag. Aman: karakter `<` yang muncul lewat
 * decoding reference tidak pernah diperlakukan browser sebagai awal
 * tag.
 *
 * Catatan: `&` diikuti huruf kecil atau besar, lalu `#` untuk numerik.
 * `&notit;` memang reference yang sah (`¬it;`), jadi dibiarkan --
 * sama seperti perilaku PHP.
 */
export function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&(?![a-zA-Z#])/g, '&amp;')
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

    /*
     * Void-ness ditentukan dari NAMA TAG, bukan dari cara tag itu
     * ditulis di sumbernya.
     *
     * `<span/>` di HTML5 itu tag PEMBUKA -- garis miringnya diabaikan,
     * sama seperti `<span>`. Kalau tag seperti itu dianggap sudah
     * tertutup, tag penutupnya hilang dan HTML-nya jadi tidak
     * idempoten: `<span/>x` -> `<span>x` -> (dissanitasi lagi)
     * `<span>x</span>`.
     *
     * Bentuk TULISANNYA tetap `<tag ...>` tanpa garis miring, sama
     * dengan backend. Backend membangun ulang setiap tag dari nol
     * (`cleanAttributes`), jadi `<br />` dan `<img ... />` selalu
     * menjadi `<br>` dan `<img ...>`. Tanpa ini, frontend dan backend
     * menghasilkan byte yang berbeda untuk HTML yang sama.
     */
    const isVoid = VOID_TAGS.has(tag)

    out.push(`<${tag}${kept.length ? ' ' + kept.join(' ') : ''}>`)

    if (!isVoid) openStack.push(tag)
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
 * Yang dibandingkan adalah bentuk yang SUDAH di-decode, bukan bentuk
 * mentah.
 *
 * Alasannya: sanitasi yang benar mengubah `&` jadi `&amp;` pada atribut
 * (`?a=1&b=2` jadi `?a=1&amp;b=2`), dan itu perubahan yang BENIGN --
 * maknanya sama persis, cuma ditulis supaya browser tidak salah baca.
 * Kalau perbandingan dilakukan pada bentuk mentah, setiap URL dengan
 * tanda `&` akan dilaporkan "curiga", dan peringatan itu jadi tidak
 * berguna karena selalu muncul.
 *
 * Bentuk yang di-decode membandingkan MAKNA: kalau nilai atributnya
 * sama setelah di-decode, tidak ada yang hilang dan tidak ada yang
 * berbahaya -- muatan teksnya persis sama seperti aslinya.
 *
 * Perbandingan pada bentuk yang sudah di-decode juga menutup kasus
 * yang lebih penting: payload yang diubah BENTUKnya supaya lolos
 * pemeriksaan. `javascript&colon;alert(1)` berubah jadi
 * `javascript:alert(1)` setelah di-decode -- itu jelas berbeda dari
 * aslinya, dan harus dilaporkan.
 */
export function hasUnsafeMarkup(html) {
  if (html === null || html === undefined) return false

  const original = String(html)
  const cleaned = sanitizeRichText(original)

  // Markup berubah setelah kedua-duanya di-decode -> ada yang dibuang,
  // dilepas, atau diubah maknanya.
  if (decodeEntities(cleaned) !== decodeEntities(original)) return true

  return false
}

export default sanitizeRichText
