/**
 * Pemindai teks rusak.
 *
 * Kegagalan yang berulang di repo ini: beberapa kata di dalam komentar dan
 * string gagal utuh saat file ditulis. Gejalanya konsisten -- menyisakan
 * potongan huruf CJK di tengah kalimat Indonesia, atau kehilangan beberapa
 * huruf di tengah kata ("penuhotone"), atau menyisakan satu kata bahasa
 * Inggris di tengah kalimat Indonesia ("accuses", "actively", "perdre").
 *
 * Yang membuatnya berbahaya bukan artifaknya: file tetap valid, test tetap
 * hijau, dan build tetap lulus. Kerusakan ada di dokumentasi yang jadi
 * satu-satunya penjelasan seorang programmer kenapa kodenya begitu -- dan
 * penjelasan yang salah lebih buruk daripada tidak ada penjelasan.
 *
 * Karena itu ini masuk `npm test`. Kerusakan teks tidak pernah
 * menghasilkan kegagalan test, jadi tidak akan ditemukan tanpa pemeriksaan
 * khusus seperti ini.
 *
 * Jalankan:
 *   node scripts/check-text.mjs                  # src/ dan test/
 *   node scripts/check-text.mjs src ../backend  # folder lain
 *
 * Exit code 1 kalau ada baris mencurigakan, supaya bisa dipakai di CI.
 */
import { readdir, readFile } from 'node:fs/promises'
import { extname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const SKIP_DIRS = new Set([
  'node_modules',
  '.git',
  'vendor',
  'dist',
  'build',
  '.idea',
  '.vscode',
  'storage',
  'coverage',
])

const EXTS = new Set(['.vue', '.js', '.cjs', '.mjs', '.ts', '.css', '.scss', '.php'])

const roots = process.argv.slice(2)
const targets = roots.length > 0 ? roots : ['src', 'test']

async function* walk(dir) {
  let entries
  try {
    entries = await readdir(dir, { withFileTypes: true })
  } catch {
    return
  }

  for (const entry of entries) {
    const full = join(dir, entry.name)

    if (entry.isDirectory()) {
      if (SKIP_DIRS.has(entry.name)) continue
      yield* walk(full)
      continue
    }

    if (EXTS.has(extname(entry.name))) yield full
  }
}

/**
 * Potongan CJK.
 *
 * Repo ini berbahasa Indonesia; tidak ada satu pun kata Tionghoa, Korea,
 * atau Jepang di kode maupun dokumentasi. Jadi kemunculannya hampir pasti
 * berarti operasi karakter gagal separuh jalan, bukan tulisan yang
 * disengaja. Korea dan Jepang ikut dicek karena keduanya muncul dari
 * mekanisme yang sama.
 */
const CJK = /[　-〿぀-ヿ㐀-䶿一-鿿가-힯＀-￯]/

/**
 * Kata yang benar-benar pernah muncul sebagai hasil kerusakan di repo ini.
 *
 * Daftar ini KECUALIAN, bukan aturan umum, dan itu disengaja.
 *
 * Versi pertama pemindai ini mencoba menebak "kata Inggris di tengah
 * kalimat Indonesia" secara umum. Hasilnya tidak berguna: prosa Indonesia
 * yang benar seperti "yang panjang", "sama seperti", dan "dengan alasan"
 * ikut tertangkap, hundreds baris sekaligus. Satu false positive cukup
 * untuk membuat orang mematikan pemindainya setelah temuan pertama, dan
 * pemindai yang sudah dimatikan tidak menangkap apa pun lagi --
 * termasuk kerusakan yang asli.
 *
 * Jadi aturannya sengaja sempit: CJK (bukti, bukan tebakan) plus daftar
 * kata konkret di bawah. Kalau nanti pola kerusakan lain teridentifikasi,
 * tambahkan di sini sebagai kata yang benar-benar pernah muncul -- bukan
 * sebagai tebakan umum.
 */
const KATA_RUSAK = [
  'berartioving',
  'penuhotone',
  'formerly',
  'accuses',
  'actively',
  'perdre',
  'selglobals',
  'Questionable',
  'OPTHON',
]

/**
 * Berkas ini sendiri tidak pernah diperiksa.
 *
 * Daftar `KATA_RUSAK` memuat kata-kata rusak itu sendiri, dan `CJK` memuat
 * rentang karakter CJK itu sendiri -- jadi tanpa pengecualian, pemindai
 * akan selalu melaporkan dirinya sendiri dan tidak pernah bisa dibuat hijau.
 *
 * Satu baris pengecualian jauh lebih murah daripada mencoba menutupi
 * setiap kata rusak sebagai singkatan.
 */
const DIRI_SENDIRI = fileURLToPath(import.meta.url)

/**
 * Karakter kontrol yang TIDAK BOLEH muncul di berkas teks.
 *
 * Damages yang satu ini yang paling repot, karena bukan merusak tampilan --
 * ia merusak PARSER. `U+008D` (C1 control) yang terselip di dalam atribut
 * HTML membuat `vue-eslint-parser` gagal dengan:
 *
 *     Parsing error: control-character-in-input-stream.
 *
 * Yang lebih menyebalkan: `vite build` tetap hijau. Vite hanya membundle,
 * dan string itu keluar ke bundle apa adanya -- jadi build tidak pernah
 * menemukan masalahnya. Yang gagal baru ketahuan saat lint, dan kalau lint
 * tidak pernah dijalankan, tidak ada yang pernah tahu.
 *
 * Asalnya: commit `22abca2` menulis emoji (`📊`, `🔍`) sebagai UTF-8, lalu
 * dibaca sebagai CP1252, lalu ditulis lagi sebagai UTF-8. Hasilnya tiap byte
 * byte UTF-8 berubah jadi dua karakter CP1252, dan di antaranya ada byte
 * `0x8D` yang jadi `U+008D` -- karakter kontrol, bukan huruf.
 *
 * Jadi aturan ini sengaja dibuat berdasarkan KODE, bukan berdasarkan
 * "tampak aneh" -- karena byte `0x8D` itu sendiri tidak pernah tampil di layar.
 */
/*
 * `oxlint` punya aturan `no-control-regex` yang menolak regex yang
 * sengaja mencocokkan karakter kontrol -- persis apa yang dilakukan
 * di sini. Matikan khusus baris ini, bukan seluruh berkas.
 */
/* oxlint-disable-next-line no-control-regex -- memang tujuannya. */
const KONTROL = /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/

let totalFiles = 0
const suspect = []

for (const target of targets) {
  for await (const file of walk(resolve(target))) {
    if (resolve(file) === DIRI_SENDIRI) continue

    totalFiles++

    let text
    try {
      text = await readFile(file, 'utf8')
    } catch {
      continue
    }

    text.split(/\r?\n/).forEach((line, i) => {
      const problems = []

      if (CJK.test(line)) problems.push('CJK')

      if (KONTROL.test(line)) problems.push('karakter kontrol')

      for (const kata of KATA_RUSAK) {
        if (line.includes(kata)) problems.push(`kata: ${kata}`)
      }

      if (problems.length > 0) {
        suspect.push({
          file: relative(process.cwd(), file),
          line: i + 1,
          text: line.trim().slice(0, 140),
          problems,
        })
      }
    })
  }
}

console.log(`Memindai ${totalFiles} berkas.`)

if (suspect.length === 0) {
  console.log('0 baris mencurigakan.')
  process.exit(0)
}

for (const s of suspect) {
  console.log(`${s.file}:${s.line}  [${s.problems.join(', ')}]`)
  console.log(`    ${s.text}`)
}

console.log(`\n${suspect.length} baris mencurigakan.`)
process.exit(1)