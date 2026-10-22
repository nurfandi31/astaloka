/**
 * Harness test untuk komponen Vue di repo ini.
 *
 * Di sinilah tempat tiga masalah yang saling menumpuk diselesaikan: DOM harus
 * ada sebelum `@vue/runtime-dom` dievaluasi; SFC harus dikompilasi jadi ESM;
 * dan `vue` harus dimuat SEKALI untuk komponen dan test-utils.
 *
 * Jalankan: `node --test` dari folder `frontend`.
 */

/*
 * HARUS jadi import pertama, dan itu bukan pilihan gaya.
 *
 * `@vue/runtime-dom` mengikat `document` tepat satu kali, saat modulnya
 * dievaluasi:
 *
 *     const doc = typeof document !== "undefined" ? document : null
 *
 * Nilai itu mengikat selamanya. Kalau `document` belum ada saat itu, `doc`
 * null untuk SELURUH proses, dan tidak ada yang bisa memperbaikinya
 * belakangan. Gejalanya muncul jauh dari penyebabnya:
 *
 *     TypeError: Cannot read properties of null (reading 'createElement')
 *       at createElement (@vue/runtime-dom/dist/runtime-dom.cjs.js:40)
 *
 * yang berarti "dokumennya tidak ada", padahal `document` jelas ada saat test
 * berjalan.
 *
 * Dua import lain di repo ini sama-sama menarik `vue` sebagai IMPORT STATIS:
 * `@vitejs/plugin-vue` dan `@vue/test-utils`. Di ESM import statis dievaluasi
 * sebelum body modul, jadi instalasi DOM yang diletakkan di body berkas ini
 * SELALU kalah -- hasilnya `doc: null` yang tidak bisa dipulihkan, berapa kali
 * pun `pasangDom()` dipanggil di `before()`.
 *
 * Di ESM urutan import dievaluasi sesuai urutan deklarasi. Karena itu instalasi
 * JSDOM dipisah ke modul sendiri yang hanya mengimpor `jsdom`, dan diimpor
 * paling awal di sini.
 */
import windowJsdom from './pasangDom.js'

import { mkdirSync, writeFileSync } from 'node:fs'
import { register } from 'node:module'
import { fileURLToPath } from 'node:url'

/*
 * Loader SFC dipasang sebelum apa pun di repo ini diimpor.
 *
 * `module.register()` mendaftarkan hook yang berjalan di thread terpisah, dan
 * berlaku untuk SEMUA impor berikutnya di proses ini -- termasuk yang dilakukan
 * `@vue/test-utils` dan oleh test. Itu yang membuat alias `@/...` dan berkas
 * `.vue` bisa muncul di mana saja tanpa tiap file perlu tahu soal loader.
 *
 * TIDAK ada `data` yang meneruskan ISI tiruan ke sana, dan itu bukan
 * kelalaian. `register()` me-SNAPSHOT `data` pada saat pemanggilan,
 * sedangkan modul tiruan baru diisi di dalam `before()` test -- jauh
 * setelah itu. Yang dikirim akan selalu kosong.
 *
 * Karena itu tiruannya lewat BERKAS, bukan lewat state. Loader cukup
 * memeriksa apakah ada berkas `.js` yang cocok di `test/__mock__/`, dan
 * pemeriksaan itu selalu melihat keadaan terbaru. Tidak ada state yang harus
 * dibagi antar dua thread.
 *
 * YANG DIKIRIM lewat `data` cuma NAMA BERKAS TEST -- bukan isi tiruan.
 *
 * Nama itu harus diketahui loader, dan tidak ada jalan lain: thread loader
 * tidak mewarisi `process.argv`, jadi `argv[1]` di sana selalu `undefined`
 * (lihat catatan `cariMock` di `loaderSfc.mjs`). Tanpa nama test, loader
 * tidak bisa tahu tiruan mana yang miliknya, dan harus menebak dari daftar
 * berkas -- yang berarti dua test yang meniru modul yang sama bisa saling
 * memakai tiruan.
 *
 * Gejalanya kalau begitu: `does not provide an export named
 * 'invoiceService'`, yang menyebut modul tiruan, bukan dua test yang
 * berebut satu berkas.
 *
 * `data` di-SNAPSHOT saat `register()` dipanggil, dan saat itu nama berkas
 * test SUDAH bisa diketahui -- `process.argv[1]` di thread utama menunjuk
 * berkas itu. Berbeda dengan isi tiruan, yang memang belum ada.
 */
register('./loaderSfc.mjs', import.meta.url, { data: { namaTest: namaTest() } })
/** Folder tempat modul tiruan ditulis. */
const MOCK = fileURLToPath(new URL('./__mock__/', import.meta.url))

/**
 * DOM sudah terpasang begitu berkas ini dimuat.
 *
 * Installasinya berada di modul terpisah (`pasangDom.js`) yang diimpor paling
 * awal di daftar import di atas. Fungsi ini tetap ada karena test memanggilnya,
 * dan mengembalikan `window` JSDOM yang sudah siap.
 */
export async function pasangDom() {
  return windowJsdom
}

/**
 * Muat komponen SFC.
 *
 * Yang dikembalikan adalah objek komponennya, BUKAN module namespace.
 *
 * `import()` di ESM selalu menghasilkan objek namespace, dan Node membuatnya
 * dengan `Object.create(null)` supaya tidak bisa dipalsukan. Gejalanya di
 * `@vue/test-utils` adalah:
 *
 *     isLegacyFunctionalComponent(component)
 *       -> hasOwnProperty(component, 'functional')
 *       -> obj.hasOwnProperty(prop)          // <-- di sini
 *
 *     TypeError: obj.hasOwnProperty is not a function
 *
 * Pesannya tidak menyebut komponen, tidak menyebut `@vue/test-utils`, dan
 * tidak menyebut SFC -- hanya "obj", nama parameter di dalam paket. Untuk
 * komponen yang benar dan harness yang benar, ini tetap terlihat seperti
 * bug di dalam test-utils.
 *
 * Makanya `default` diunwrap di sini, satu-satunya tempat yang tahu bentuk
 * modul hasil loader.
 */
export async function komponen(path) {
  const modul = await import(`../src/${path}`)

  return modul.default ?? modul
}

/**
 * Ganti sebuah modul di dalam `src/` dengan tiruan.
 *
 * Dipakai karena komponen mengimpor service sebagai modul di level teratas:
 * `@/services/central.service` masuk ke `CentralHakAksesIndex.vue` lewat
 * `import`, bukan lewat `provide()`. `global.mocks` milik test-utils tidak
 * menyentuh jalur itu sama sekali, jadi penggantinya harus dipasang di level
 * RESOLVER -- sebelum komponennya diimpor.
 *
 * Cara kerjanya: kodenya ditulis ke `test/__mock__/<nama>.js`, lalu loader
 * mengarahkan specifier itu ke sana.
 *
 * Berkas, bukan modul virtual, karena dua alasan yang keduanya nyata:
 *
 *  1. `register()` me-SNAPSHOT `data` saat dipanggil, sedangkan pagarnya baru
 *     diisi di dalam `before()` test. State yang dibagi antar thread akan
 *     selalu kosong -- dan itu kelihatan sebagai "mock-nya tidak terpakai",
 *     bukan sebagai kesalahan yang jelas.
 *
 *  2. Loader berjalan di thread terpisah. Isi `tires` di sini tidak akan
 *     pernah terlihat hook-nya.
 *
 * Pagar boleh dipasang setelah impor lain, tapi sebelum impor PERTAMA
 * komponen yang memakainya. Modul yang sudah ter-resolve tidak membaca pagarnya
 * lagi.
 */
export function daftarkanPagarVirtual(specifier, isi) {
  mkdirSync(MOCK, { recursive: true })

  const nama = namaPagar(specifier)

  writeFileSync(`${MOCK}${nama}.js`, isi, 'utf-8')
}

/**
 * Nama berkas tiruan untuk sebuah specifier.
 *
 * WAJIB unik per berkas test. Dulu namanya diturunkan dari specifier
 * saja, jadi dua berkas test yang sama-sama meniru
 * `@/services/central.service` menulis ke berkas yang SAMA.
 *
 * Node menjalankan berkas test secara paralel, jadi keduanya menulis ke
 * `__services_central.service.js` pada saat yang tidak ditentukan. Yang
 * menang terakhir yang dipakai -- dan gejalanya muncul jauh dari
 * penyebabnya:
 *
 *     SyntaxError: The requested module '@/services/central.service'
 *     does not provide an export named 'rekeningService'
 *
 * yang menyebut modul tiruan, bukan dua test yang berebut berkas.
 *
 * Yang lebih menyebalkan, test itu bisa saja lolos atau gagal SESUAI
 * urutan penulisan -- jadi kadang hijau, kadang merah, tanpa ada yang
 * berubah di kodenya.
 *
 * Nama berkas test masuk ke dalam nama tiruan supaya tidak ada yang
 * bisa saling menimpa.
 *
 * Loader (`loaderSfc.mjs`) tidak menghitung nama yang sama -- dia
 * MENCARI lewat `cariMock`: berkas di folder tiruan yang berakhiran
 * `_<nama-specifier>.js`. Kalau lebih dari satu yang cocok, yang
 * dipakai adalah yang namanya paling panjang, supaya nama test yang
 * panjang tidak tertimpa nama test yang pendek.
 */
function namaPagar(specifier) {
  return `${namaTest()}_${specifier.replace(/[^\w.-]/g, '_')}`
}

/**
 * Nama berkas test yang sedang berjalan, tanpa ekstensi.
 *
 * `process.argv[1]` adalah berkas test yang dieksekusi Node di dalam
 * proses ini. Loader SFC berjalan di thread lain yang TIDAK mewarisi
 * `process.argv` -- jadi nama di sini tidak bisa dibaca dari sana, dan
 * itu sebabnya loader mencari lewat daftar berkas, bukan lewat `argv`.
 */
function namaTest() {
  const berkas = process.argv[1] ?? ''

  return (berkas.split(/[\\/]/).pop() ?? 'umum').replace(/\.m?js$/, '') || 'umum'
}

/** Tutup DOM. Dipanggil di `after()` supaya tidak ada handle yang menggantung. */
export async function tutupDom() {
  if (windowJsdom) windowJsdom.close()
}