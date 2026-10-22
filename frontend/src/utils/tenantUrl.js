/**
 * URL untuk membuka domain milik tenant dari panel pusat.
 *
 * Ada di satu tempat karena dipakai panel pusat di beberapa tampilan
 * (`CentralLokasiDetail.vue` dan `CentralLokasiPopup.vue`), dan dua
 * versi yang sedikit berbeda akan menghasilkan tautan yang benar di
 * satu halaman dan salah di halaman lain -- tanpa pesan error apa pun.
 *
 * MASALAH YANG DIATASI
 *
 * Versi sebelumnya menempelkan `https://` langsung di template:
 *
 *     :href="`https://${d.host}`"
 *
 * Itu selalu benar untuk domain produksi yang sudah punya sertifikat,
 * tapi salah di dua kasus yang justru sering terjadi:
 *
 *   1. Host development (`.test`, `localhost`, `192.168.x.x`) tidak
 *      punya sertifikat. Tautan `https://demo.astaloka.test` gagal
 *      sebelum halaman sempat dimuat, dan panel menampilkan tautan
 *      yang terlihat benar tapi selalu gagal diklik.
 *   2. Lokasi yang belum memasang SSL sama sekali. Menunggu sertifikat
 *      adalah hal wajar, dan panel tidak boleh membuat aksesnya
 *      tampak rusak selama itu berlangsung.
 *
 * Pakai `http://` untuk host development dan `https://` untuk yang lain,
 * DITAMBAHKAN dengan opsi untuk menyalin host ke papan klip, sehingga
 * admin tetap punya jalan ketika tautannya memang belum bisa dibuka.
 */

/** TLD yang jelas hanya untuk development / jaringan lokal. */
const DEV_TLDS = ['test', 'local', 'localhost', 'internal', 'lan', 'dev', 'staging'];

/**
 * Host ini punya sertifikat, atau setidaknya berada di ranah publik --
 * jadi diasumsikan HTTPS.
 *
 * Label terakhir dipakai, bukan seluruh host: `demo.astaloka.test` dan
 * `192.168.1.10` sama-sama berakhir dengan label yang menandakan
 * development, dan keduanya memang tidak punya sertifikat.
 */
function looksLocal(host) {
  const lower = String(host || '').toLowerCase();

  if (!lower) return true;
  if (/^(localhost|127\.0\.0\.1|0\.0\.0\.0|\[?::1\]?)$/.test(lower)) return true;
  if (lower.startsWith('10.')) return true;
  if (lower.startsWith('192.168.')) return true;
  if (/^172\.(1[6-9]|2\d|3[01])\./.test(lower)) return true;
  if (lower.endsWith('.local')) return true;

  const tld = lower.split('.').pop();

  return DEV_TLDS.includes(tld);
}

/**
 * URL lengkap untuk sebuah host tenant.
 *
 * Mengembalikan string kosong untuk input yang tidak bisa jadi tautan:
 * host kosong, atau wildcard (`*.astaloka.test`) yang memang tidak punya
 * padanan host yang bisa diklik. Pemanggil sudah membedakan wildcard di
 * template, tapi pengaman di sini membuat fungsi ini aman dipanggil
 * dari mana saja tanpa pemeriksaan ulang.
 */
export function tenantUrl(host) {
  const raw = String(host || '').trim();

  if (!raw) return '';
  if (raw.includes('*')) return '';

  // Skema yang sudah menempel dibuang dulu, supaya `https://demo.test`
  // tidak berakhir jadi `https://https://demo.test`.
  const cleaned = raw.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '');

  if (!cleaned || !cleaned.includes('.')) return '';

  const protocol = looksLocal(cleaned) ? 'http' : 'https';

  return `${protocol}://${cleaned}`;
}

/** Host tanpa wildcard, untuk ditampilkan. */
export function tenantHost(host) {
  const raw = String(host || '').trim();

  if (!raw) return '';

  return raw.replace(/^\*\./, '');
}