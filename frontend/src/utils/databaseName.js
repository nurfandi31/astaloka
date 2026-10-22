/**
 * Aturan penamaan database lokasi, sisi frontend.
 *
 * Kenapa file ini ada
 * ==================
 *
 * Form tambah lokasi menampilkan pratinjau nama database yang AKAN dibuat,
 * supaya admin tidakchluss dengan nama yang ternyata berbeda dari yang dikira
 * saat lokasi tersimpan.
 *
 * Aturan itu milik backend -- `TenantManager::databaseNameFor()` -- dan tidak
 * bisa dipanggil dari sini: satu panggilan HTTP untuk menampilkan satu baris
 * teks berarti satu round-trip di SETIAP ketikan. Jadi aturan ini memang
 * salinan, dan salinan tanpa test akan melenceng diam-diam.
 *
 * Karena itu bentuknya dikunci di `frontend/test/lokasiForm.test.js`, dengan
 * kasus yang sama persis seperti test backend `tests/Unit/DatabaseNameTest.php`.
 * Kalau salah satu berubah, keduanya harus berubah bersama.
 *
 * PILIHAN LOKASI PENTING: file ini TIDAK mengimpor apa pun. Dia kumpulan
 * fungsi murni, tanpa Vue dan tanpa router -- itulah yang membuatnya bisa diuji
 * dengan `node --test` tanpa memasang aplikasi.
 */

/** Awalan bawaan. Harus sama dengan `TENANCY_DATABASE_PREFIX` bawaan. */
export const DATABASE_PREFIX = 'astaloka_'

/** Batas nama database MySQL. */
export const DATABASE_NAME_MAX = 64

/**
 * Nama database untuk sebuah slug, TANPA membuatnya.
 *
 * Mirip `TenantManager::databaseNameFor()`:
 *  1. everything di luar `[a-z0-9_]` diganti underscore
 *  2. digabung dengan prefix
 *  3. dipotong ke batas 64 karakter
 *  4. underscore menggantung di ujung DIBUANG
 *
 * Urutan (3) lalu (4) itu penting. Untuk prefix bawaan `astaloka_` hasilnya
 * sama dengan urutan terbalik -- `substr()` tidak pernah memotong tepat di
 * underscore pemisah karena prefix hanya 9 karakter. Yang membedakan baru
 * terlihat kalau prefix diganti lewat env, dan di situ nama yang satu punya
 * underscore menggantung yang membuat registry menunjuk database yang tidak
 * pernah dibuat.
 *
 * @param {string} slug
 * @param {string} [prefix]
 * @param {number} [batas]
 * @returns {string} Nama database, atau `''` kalau slug tidak menghasilkan nama.
 */
export function previewDatabaseName(
  slug,
  prefix = DATABASE_PREFIX,
  batas = DATABASE_NAME_MAX,
) {
  const bersih = String(slug ?? '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_]/g, '_')

  // Slug yang isinya cuma separator tidak menghasilkan nama yang sah --
  // `astaloka_` sendiri akan diterima MySQL dan menunjuk ke database yang
  // bukan milik lokasi mana pun. Backend menolak kasus yang sama dengan
  // melempar exception; di sini cukup tidak menampilkan apa pun.
  if (bersih === '' || bersih.replace(/_/g, '') === '') return ''

  return `${prefix}${bersih}`.slice(0, batas).replace(/_+$/, '')
}

export default previewDatabaseName