import { MySwal } from '@/utils/swal'
import { hapusTenantSlug } from '@/utils/tenantSlug'

/**
 * Popup tagihan langganan yang mengunci aplikasi lokasi.
 *
 * Ditampilkan di dua tempat:
 *   1. Halaman login, saat `POST /login` dibalas 402.
 *   2. Dashboard, saat request apa pun dibalas 402.
 *
 * Yang membuat popup ini BERBEDA dari notifikasi biasa: ia tidak punya
 * tombol tutup. Bukan karena sengaja dibuat annoying, tapi karena di
 * tempat popup ini muncul aplikasi memang sudah tidak bisa dipakai --
 * middleware `EnforceInvoiceStatus` membalas 402 untuk semua request
 * selain daftar putih di `tenancy.invoice_exempt_paths`. Tombol tutup di
 * sini hanya akan menyembunyikan bukti bahwa ada tagihan yang harus
 * dibayar, sementara penyebabnya tetap memblokir.
 *
 * Yang tersisa cuma dua jalan keluar yang nyata: menyalin rekening, dan
 * logout. Keduanya adalah tindakan yang tidak butuh aplikasi bisa dipakai.
 *
 * SALIN RINCIAN
 * -------------
 * Popup menyediakan tombol "Salin rincian" yang menyalin daftar nomor dan
 * nominal tagihan ke clipboard. Ini untuk user yang butuh meneruskan
 * tagihan ke luar aplikasi, misalnya mengirimkannya ke atasan lewat WA.
 *
 * Disebut di sini karena itu satu-satunya bagian popup yang menambah
 * kemampuan; sisanya hanya menampilkan.
 *
 * BENTUK DATA
 * -----------
 * Fungsi ini menerima apa adanya, bentuk persisnya ditentukan backend:
 * `App\Services\InvoiceGate::payload()`. Satu-satunya care yang dilakukan
 * di sini adalah `?? []` dan `?? 0` -- kalau backend mengubah field,
 * popup tidak ikut crash.
 */

/*
 * Popup yang sedang tampil.
 *
 * SweetAlert2 mengizinkan banyak popup bertumpuk, tapi untuk kondisi ini
 * itu hanya menghasilkan dua dialog di atas satu sama lain kalau ada dua
 * request 402 bersamaan (poll + klik menu). Yang bawah tidak bisa
 * ditutup, jadi user tidak bisa menutup keduanya. Karena
 * itu pemanggilan kedua diabaikan selama yang pertama masih terbuka.
 */
let sedangTerbuka = false

const rupiah = (value) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(value || 0))

/**
 * Cegah HTML dari string server ditampilkan sebagai markup.
 *
 * `keterangan` dan nama lokasi berasal dari database pusat, jadi tidak
 * boleh disisipkan mentah ke `html:` SweetAlert2. Form tagihan diisi
 * oleh superadmin, tetapi "superadmin mengetik HTML" bukan alasan untuk
 * membiarkan tag-nya dieksekusi di peramban user lokasi.
 */
const escapeHtml = (value) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')

/**
 * Teks sisa hari.
 *
 * `sisa_hari` negatif berarti lewat tenggat. Angka mentah seperti "-12"
 * tidak akan dimengerti user sebagai "12 hari lalu", jadi ditranslate di
 * sini -- sekali saja, supaya popup login dan popup dashboard memakai
 * kalimat yang sama.
 */
const sisaHariText = (hari, terlambat) => {
  const n = Number(hari || 0)

  if (terlambat) {
    return n === 0 ? 'lewat jatuh tempo hari ini' : `terlambat ${Math.abs(n)} hari`
  }

  if (n === 0) return 'jatuh tempo hari ini'
  if (n === 1) return 'jatuh tempo besok'

  return `jatuh tempo dalam ${n} hari`
}

/**
 * Bangun isi popup.
 *
 * Dipisah dari pemanggil supaya bisa diuji tanpa DOM, dan supaya
 * `debug` di console -- kalau ada yang aneh, lihat HTML-nya tanpa
 * harus menunggu popup muncul.
 */
export const invoiceLockHtml = (data) => {
  const invoices = Array.isArray(data?.invoices) ? data.invoices : []
  const total = data?.total_tagihan
  const terlambat = data?.terlambat === true

  const baris = invoices
    .map((inv) => {
      const badge = inv.terlambat
        ? `<span style="display:inline-block;padding:1px 7px;border-radius:9999px;background:#fee2e2;color:#b91c1c;font-size:10px;font-weight:700;">Terlambat</span>`
        : `<span style="display:inline-block;padding:1px 7px;border-radius:9999px;background:#fef3c7;color:#b45309;font-size:10px;font-weight:700;">Belum Lunas</span>`

      return `
        <tr>
          <td style="padding:8px 6px;border-bottom:1px solid #e5e7eb;">
            <div style="font-weight:700;color:#0f172a;">${escapeHtml(inv.nomor)}</div>
            <div style="font-size:11px;color:#64748b;margin-top:1px;">
              ${sisaHariText(inv.sisa_hari, inv.terlambat)}
            </div>
          </td>
          <td style="padding:8px 6px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap;font-weight:700;color:#0f172a;">
            ${rupiah(inv.jumlah)}
          </td>
          <td style="padding:8px 6px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap;">
            ${badge}
          </td>
        </tr>`
    })
    .join('')

  const subTotal = invoices.length
    ? `<div style="margin-top:10px;padding-top:9px;border-top:2px solid #0d5b74;display:flex;justify-content:space-between;align-items:center;">
         <span style="font-weight:700;color:#0f172a;">Total yang harus dibayar</span>
         <span style="font-size:17px;font-weight:800;color:#0d5b74;">${rupiah(total)}</span>
       </div>`
    : ''

  return `
    <div class="text-left! text-sm! leading-relaxed!" data-invoice-lock>
      <p style="margin:0 0 12px 0;color:#475569;font-size:12.5px;line-height:1.55;">
        ${
          terlambat
            ? 'Tagihan langganan ASTALOKA untuk lokasi ini <b>sudah lewat jatuh tempo</b>. Aplikasi dinonaktifkan sampai tagihan dilunasi.'
            : 'Tagihan langganan ASTALOKA untuk lokasi ini <b>belum dilunasi</b>. Selesaikan pembayaran kepada ASTA BRATA TEKNOLOGI untuk bisa masuk ke aplikasi.'
        }
      </p>

      <table style="width:100%;border-collapse:collapse;font-size:11.5px;">
        <thead>
          <tr style="color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:0.4px;">
            <th style="text-align:left;padding:0 6px 5px;">Nomor</th>
            <th style="text-align:right;padding:0 6px 5px;">Jumlah</th>
            <th style="text-align:right;padding:0 6px 5px;">Status</th>
          </tr>
        </thead>
        <tbody data-invoice-rows>${baris}</tbody>
      </table>

      ${subTotal}

      <div style="margin-top:14px;padding:10px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;">
        <p style="margin:0 0 5px 0;font-size:11px;font-weight:700;color:#334155;">
          Pembayaran melalui transfer bank
        </p>
        <p style="margin:0;font-size:11px;color:#64748b;line-height:1.6;">
          PT. ASTA BRATA TEKNOLOGI<br>
          Bank Mandiri / Bank BRI<br>
          <span style="color:#94a3b8;font-size:10px;">Nomor rekening lengkap tidak disimpan di sistem. Hubungi admin pusat untuk detail rekening.</span>
        </p>
      </div>
    </div>`
}

/**
 * Salin daftar tagihan ke clipboard.
 *
 * Clipboard API butuh HTTPS atau localhost. Di akses lewat IP LAN
 * (`http://192.168.x.x`) `navigator.clipboard` tidak ada -- dan itu bukan
 * kasus yang jarang di aplikasi yang dipakai di lapangan. Karena itu ada
 * jalan cadangan dengan `<textarea>` tersembunyi, yang hanya gagal pada
 * pada satu kondisi: peramban benar-benar tidak mendukung cara mana pun.
 */
const salinKeClipboard = async (data) => {
  const invoices = Array.isArray(data?.invoices) ? data.invoices : []
  if (!invoices.length) return false

  const teks = [
    'TAGIHAN LANGGANAN ASTALOKA',
    ...invoices.map(
      (i) => `${i.nomor} | ${rupiah(i.jumlah)} | ${sisaHariText(i.sisa_hari, i.terlambat)}`
    ),
    '',
    `Total: ${rupiah(data?.total_tagihan)}`,
    'Bagikan kepada ASTA BRATA TEKNOLOGI untuk pelunasan.',
  ].join('\n')

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(teks)
      return true
    }
  } catch {
    // Izin ditolak atau konteks tidak aman -- lanjut ke cara manual.
  }

  try {
    const area = document.createElement('textarea')
    area.value = teks
    area.setAttribute('readonly', '')
    area.style.position = 'fixed'
    area.style.opacity = '0'
    document.body.appendChild(area)
    area.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(area)
    return ok
  } catch {
    return false
  }
}

/**
 * Tampilkan popup penguncian.
 *
 * `options.logout` disisikan supaya pemanggil tidak perlu mengimpor
 * axios ke sini. Ini penting untuk mencegah lingkup import: modul ini
 * dipanggil dari interceptor `utils/axios.js`, yang sudah mengimpor hal
 * itu -- importer axios di sini akan membuat chain yang mudah salah
 * Packages di masa depan.
 *
 * Mengembalikan promise `MySwal` yang resolve saat popup ditutup (hanya
 * lewat tombol "Keluar") atau gagal saat error.
 */
export const showInvoiceLockModal = async (data, options = {}) => {
  if (sedangTerbuka) return
  sedangTerbuka = true

  try {
    const result = await MySwal.fire({
      title: 'Tagihan Belum Lunas',
      html: invoiceLockHtml(data),
      width: 520,
      showConfirmButton: true,
      confirmButtonText: 'Keluar dari Aplikasi',
      confirmButtonColor: '#dc2626',
      // Tidak ada tombol "Batal", tidak ada tanda silang.
      showCancelButton: false,
      showDenyButton: false,
      showCloseButton: false,
      allowOutsideClick: false,
      allowEscapeKey: false,
      // `stopKeydown` dulu tidak dipakai karena SweetAlert2 sudah
      // menolak Escape lewat `allowEscapeKey: false`. Menambal
      // keyboard handler di sini hanya menambah satu tempat lagi di mana
      // "popup bisa tertutup" bisa masuk diam-diam.
      backdrop: 'rgba(15, 23, 42, 0.55)',
      customClass: {
        popup: 'invoice-lock-popup!',
        title: 'text-lg! font-bold!',
        confirmButton: 'rounded-xl! px-4! py-2! font-bold! text-sm!',
      },
      didOpen: (popup) => {
        /*
         * Cegah closure paksa dengan Escape di beberapa tema CSS.
         *
         * SweetAlert2 sudah menutup jalan keluar di atas, tapi `customClass`
         * milik proyek ini pernah memakai `!important` yang bisa
         * membatalkan aturan bawaan. Pastikan semua tombol lain benar-benar
         * hilang sebelum user sempat menekan apa pun.
         */
        popup.querySelectorAll('.swal2-x, .swal2-cancel, .swal2-deny').forEach((btn) => {
          btn.style.setProperty('display', 'none', 'important')
        })

        popup.setAttribute('data-lock', 'active')

        /*
         * Tombol "Salin rincian" disisipkan manual, bukan lewat
         * `showCancelButton`.
         *
         * Cancel button bawaan SweetAlert2 otomatis menutup popup saat
         * diklik -- persis perilaku yang harus dihindari di sini. Yang
         * dibutuhkan tombol dengan aksi tanpa menutup, jadi satu-satunya
         * cara adalah tombol biasa yang disisipkan ke `footer`.
         */
        const footer = popup.querySelector('.swal2-footer')
        if (!footer) return

        const btn = document.createElement('button')
        btn.type = 'button'
        btn.className = 'invoice-lock-copy'
        btn.textContent = 'Salin Rincian Tagihan'

        btn.addEventListener('click', async () => {
          const ok = await salinKeClipboard(data)
          btn.textContent = ok ? 'Rincian tersalin!' : 'Gagal menyalin'
          btn.disabled = ok

          // Kembalikan teks tombol kalau gagal, supaya user bisa mencoba
          // lagi tanpa harus menunggu popup ditutup.
          if (!ok) {
            setTimeout(() => {
              btn.textContent = 'Salin Rincian Tagihan'
              btn.disabled = false
            }, 2500)
          }
        })

        footer.appendChild(btn)
      },
    })

    /*
     * Satu-satunya jalan keluar: logout.
     *
     * Token sengaja TIDAK dihapus di sini. Middleware tidak mencabut
     * token (lihat catatan panjang di EnforceInvoiceStatus) justru
     * supaya status lunas memulihkan akses tanpa perlu login ulang.
     * Yang perlu dibersihkan hanya sisi peramban -- kalau user menekan
     * refresh setelah logout, halaman dashboard akan mencoba merender
     * dengan tagihan yang sama, dan itu membingungkan.
     */
    if (result.isConfirmed) {
      const doLogout = options.logout ?? defaultLogout
      await doLogout()
    }
  } catch {
    // SweetAlert2 menolak kalau dipanggil ulang saat popup lain masih
    // menutup. Di sini popup tagihan adalah hal yang paling penting
    // untuk tampil, jadi kegagalan membuka popup tidak boleh ikut
    // menggagalkan request asalnya.
  } finally {
    sedangTerbuka = false
  }
}

/**
 * Logout bawaan.
 *
 * Sengaja memakai `localStorage` + pindah halaman, bukan memanggil
 * `POST /logout`: memanggukannya berarti mengirim request yang bisa
 * saja dibalas 402 kalau `api/logout` somehow tidak lagi dikecualikan.
 * Membersihkan sisi peramban sudah cukup untuk keluar dari aplikasi --
 * token yang tersisa tidak bisa dipakai karena semua endpoint membalas
 * 402.
 */
const defaultLogout = async () => {
  try {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('user_data')
    localStorage.removeItem('user_role')
    localStorage.removeItem('auth_expires_at')

    // Slug milik sesi ini: sisa di sini akan dipakai lokasi berikutnya yang
    // login di lokasi berbeda (lihat `utils/tenantSlug.js`).
    hapusTenantSlug()
  } catch {
    // localStorage bisa menolak dalam mode privat di sebagian peramban. Tidak
    // ada yang bisa dilakukan, dan memaksa navigasi tetap benar.
  }

  window.location.href = '/login?logout=invoice'
}

export default showInvoiceLockModal