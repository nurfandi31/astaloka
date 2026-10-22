import { reactive, ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MySwal, showSuccessToast, showErrorToast } from '@/utils/swal'
import { previewDatabaseName } from '@/utils/databaseName'
import { locationService } from '@/services/central.service'

/**
 * Logika bersama halaman Tambah Lokasi dan Ubah Lokasi.
 *
 * Kenapa ini dipisah
 * ==================
 *
 * Kedua halaman itu punya bagian yang sama persis -- dan bagian itulah yang
 * paling rawan rusak kalau ditulis dua kali:
 *
 *  1. Daftar sektor diambil dari backend dan dipetakan ke pasangan
 *     `{ label, value }` untuk `BaseSelect`. Tiga puluh baris, termasuk
 *     catatan kenapa gagalnya tidak boleh membatalkan form.
 *  2. Bentuk `form` beserta nilai awalnya.
 *  3. Pembersihan nilai sebelum dikirim: string kosong harus jadi `null`,
 *     karena backend memvalidasi `email` dengan rule `email` dan string
 *     kosong akan ditolak meski opsionalnya jelas.
 *  4. Pemetaan balasan 422 menjadi pesan per-field.
 *  5. Pratinjau nama database, yang harus mengikuti aturan yang SAMA dengan
 *     backend (`TenantManager::databaseNameFor`).
 *
 * Yang berbeda antar keduanya -- slug sebagai isian, strip penyiapan
 * database, seksi Langganan & Status, konfirmasi sebelum provisioning -- tetap
 * tinggal di masing-masing view. Unterschied itu nyata dan tidak pantas
 * dipaksa jadi satu.
 *
 * Yang dipindah ke sini BUKAN template-nya. Markup kedua halaman memang mirip
 * jadi ~70%, tapi bentuknya berbeda di tempat yang penting: halaman tambah
 * menampilkan slug sebagai isian yang harus diketik manual dan strip
 * "database yang akan dibuat", sedangkan halaman ubah menampilkan slug
 * sebagai database yang sudah jadi. Template-nya tetap terpisah; yang
 * disatukan logikanya, supaya perbaikan di satu sisi otomatis berlaku di
 * sisi lain.
 */

/**
 * Bentuk `form` untuk mode tambah.
 *
 * Tidak ada `database`, `tgl_berakhir`, dan `is_active` di sini: ketiganya
 * hanya boleh diisi saat ubah. `database` ditentukan backend dari slug,
 * `is_active` di-toggle lewat endpoint khusus, dan `tgl_berakhir` sengaja
 * tidak ada di halaman pendaftaran -- lihat catatan panjangnya di
 * `CentralLokasiForm.vue`.
 */
const KOLOM_CREATE = [
  'nama',
  'nama_lembaga',
  'sektor',
  'bidang_usaha',
  'deskripsi_usaha',
  'slug',
  'domain',
  'domain_alternatif',
  'alamat',
  'email',
  'telepon',
  'admin_name',
  'admin_email',
  'admin_password',
]

/**
 * Kolom yang halaman ubah boleh kirim.
 *
 * `slug` dan `database` TIDAK ada di sini, dan itu bukan kelalaian: keduanya
 * tidak bisa diubah setelah lokasi terdaftar, dan mengirimkannya dari form
 * ubah berarti klien memegang kolom yang tidak pernah boleh disentuh.
 */
const KOLOM_EDIT = [
  'nama',
  'nama_lembaga',
  'sektor',
  'bidang_usaha',
  'deskripsi_usaha',
  'domain',
  'domain_alternatif',
  'alamat',
  'email',
  'telepon',
  'tgl_berakhir',
  'is_active',
]

/**
 * Kolom yang selalu berupa teks: `trim()`, dan kosong menjadi `null`.
 *
 * Dipisah dari `tgl_berakhir` dan `is_active` karena keduanya bukan teks.
 * `trim()` pada tanggal akan damaging, dan `is_active` sengaja boleh `false`
 * -- yang berarti "nonaktif", bukan "tidak diisi".
 */
const KOLOM_TEKS = new Set([
  'nama',
  'nama_lembaga',
  'sektor',
  'bidang_usaha',
  'deskripsi_usaha',
  'slug',
  'domain',
  'domain_alternatif',
  'alamat',
  'email',
  'telepon',
  'admin_name',
  'admin_email',
])

/**
 * Susun payload dari nilai form.
 *
 * Ini yang membuat kedua halaman tidak bisa berbeda tipis-tipis soal
 * "string kosong atau `null`". Aturan `nullable` di backend TIDAK menerima
 * string kosong untuk `email` -- `nullable` berarti boleh tidak ada, dan
 * string kosong adalah nilai yang salah bentuk, bukan ketiadaan nilai.
 */
function bangunPayload(form, kolom) {
  const payload = {}

  for (const key of kolom) {
    if (key === 'is_active') {
      payload.is_active = Boolean(form.is_active)
      continue
    }

    if (key === 'tgl_berakhir') {
      // Tanggal kosong berarti "tanpa batas", jadi `null` -- bukan `''`,
      // yang akan ditolak rule `date`.
      payload.tgl_berakhir = form.tgl_berakhir || null
      continue
    }

    if (key === 'admin_password') {
      // Password tidak di-`trim()`: spasi di awal atau akhir bisa jadi bagian
      // dari kata sandi yang disengaja.
      payload.admin_password = form.admin_password || null
      continue
    }

    if (!KOLOM_TEKS.has(key)) continue

    const nilai = typeof form[key] === 'string' ? form[key].trim() : form[key]
    payload[key] = nilai === '' ? null : nilai
  }

  return payload
}

/**
 * @param {'create'|'edit'} mode
 */
export function useLokasiForm(mode) {
  const router = useRouter()
  const route = useRoute()

  const saving = ref(false)
  const loading = ref(false)
  const errors = ref({})

  /* ===== DAFTAR SEKTOR ===== */

  /*
   * Diambil dari backend, bukan ditulis ulang di sini.
   *
   * Backend memvalidasi `sektor` dengan rule `in:`. Kalau frontend punya
   * daftarnya sendiri, daftar yang berbeda sedikit saja akan membuat form
   * menawarkan pilihan yang SELALU ditolak -- dan errornya muncul sebagai
   * "422 dari mana saja", yang tidak pernah mengarah ke field yang salahnya.
   */
  const sektor = ref([])
  const loadingSektor = ref(true)

  onMounted(async () => {
    try {
      const res = await locationService.getSektor()
      sektor.value = res.data?.data ?? []
    } catch {
      // Kegagalan di sini tidak boleh membatalkan seluruh form. Yang paling
      // buruk kalau daftar sektor gagal diambil: kolomnya kosong, dan nilai
      // `sektor` yang sudah tersimpan tidak ikut ter-load -- jadi admin yang
      // membuka form ubah bisa tanpa sadar MENGHAPUS sektor yang sudah ada.
      //
      // Karena itu `form.sektor` tidak pernah ditulis ulang di sini. Nilai
      // lama tetap utuh, dan `select` yang kosong akan menampilkannya sebagai
      // opsi "-- Belum diisi --", yang berarti sama dengan menghapus.
      sektor.value = []
    } finally {
      loadingSektor.value = false
    }
  })

  /*
   * `BaseSelect` bekerja dengan pasangan `{ label, value }`, sedangkan
   * endpoint ini mengirim array string biasa. Bentuknya tidak diubah di
   * component supaya daftar yang sudah jadi bisa tetap dipakai apa adanya.
   */
  const sektorOptions = computed(() => sektor.value.map((s) => ({ label: s, value: s })))

  /* ===== FORM ===== */

  const form = reactive({
    nama: '',
    nama_lembaga: '',
    sektor: '',
    bidang_usaha: '',
    deskripsi_usaha: '',
    slug: '',
    database: '',
    domain: '',
    domain_alternatif: '',
    alamat: '',
    email: '',
    telepon: '',
    tgl_berakhir: null,
    is_active: true,
    admin_name: '',
    admin_email: '',
    admin_password: '',
  })

  /**
   * Pratinjau nama database mengikuti aturan yang sama dengan backend
   * (`TenantManager::databaseNameFor`): prefix + slug dengan tanda hubung
   * diganti underscore, dipotong 64 karakter, dan ekor underscore dibuang.
   *
   * Aturan ini ditulis ulang di sini -- dan TIDAK bisa memanggil backend,
   * karena pemanggilan itu harus menunggu 1-2 menit hanya untuk
   * menunjukkan satu baris teks. Menampilkan pratinjau ini mencegah
   * kebingungan saat lokasi tersimpan dengan nama database yang tidak
   * seperti yang dikira admin.
   *
   * Karena aturan ini DUPLIKAT dari backend, bentuknya dikunci oleh
   * `frontend/test/lokasiForm.test.js` -- kalau backend berubah, test itu
   * yang harus ikut diperbarui, bukan pelan-pelan melenceng di sini.
   */
  const databasePreview = computed(() => previewDatabaseName(form.slug))

  const isEdit = mode === 'edit'

  /**
   * Tujuan "Kembali" ditulis eksplisit, bukan `router.back()`.
   *
   * Halaman tambah selalu kembali ke daftar lokasi; halaman ubah selalu
   * kembali ke detail lokasi yang sedang diedit. `router.back()`
   * mengembalikan ke mana pun orang datang -- bisa dashboard, bisa daftar
   * tagihan -- dan janji tombol harus benar.
   */
  const goBack = () =>
    isEdit
      ? router.push({ name: 'central-lokasi-detail', params: { id: route.params.id } })
      : router.push('/central/lokasi')

  /* ===== MUAT DATA (mode ubah) ===== */

  const loadData = async () => {
    if (!isEdit) return

    loading.value = true

    try {
      const res = await locationService.getById(route.params.id)
      const data = res.data?.data

      if (!data) throw new Error('Data tenant tidak ditemukan.')

      Object.assign(form, {
        nama: data.nama || '',
        nama_lembaga: data.nama_lembaga || '',
        sektor: data.sektor || '',
        bidang_usaha: data.bidang_usaha || '',
        deskripsi_usaha: data.deskripsi_usaha || '',
        slug: data.slug || '',
        database: data.database || '',
        domain: data.domain || '',
        domain_alternatif: data.domain_alternatif || '',
        alamat: data.alamat || '',
        email: data.email || '',
        telepon: data.telepon || '',
        tgl_berakhir: data.tgl_berakhir || null,
        is_active: Boolean(data.is_active),
      })
    } catch (error) {
      showErrorToast(error)
      router.push('/central/lokasi')
    } finally {
      loading.value = false
    }
  }

  if (isEdit) onMounted(loadData)

  /* ===== KIRIM ===== */

  /**
   * 422 = validasi gagal. Tampilkan pesan per-field supaya admin tahu bagian
   * mana yang perlu diperbaiki, bukan hanya toast global.
   *
   * `fieldErrors` dikumpulkan dari `errors` Laravel, yang setiap nilainya
   * array. Yang dipakai hanya yang pertama -- form ini punya satu baris error
   * per field, jadi menampilkan ketiganya sekaligus berarti tiga baris teks
   * untuk satu masalah yang sama.
   */
  const petakanFieldErrors = (error) => {
    if (error.response?.status !== 422) return false

    const fieldErrors = error.response.data?.errors || {}

    if (Object.keys(fieldErrors).length === 0) return false

    errors.value = Object.fromEntries(
      Object.entries(fieldErrors).map(([key, msgs]) => [key, msgs[0]]),
    )

    return true
  }

  const handleSubmit = async () => {
    errors.value = {}

    if (!form.nama.trim()) {
      errors.value.nama = 'Nama tenant wajib diisi.'
      return
    }

    if (!isEdit && !form.slug.trim()) {
      errors.value.slug = 'Slug wajib diisi.'
      return
    }

    saving.value = true

    // Konfirmasi HANYA di mode tambah, dan tanpa syarat.
    //
    // Sebelumnya konfirmasi ini hanya muncul kalau checkbox provisioning
    // aktif -- dan karena checkbox-nya bisa dimatikan, ada jalur yang sama
    // sekali tanpa peringatan untuk operasi yang butuh 1-2 menit dan tidak
    // bisa dibatalkan. Halaman ubah tidak butuh ini: isinya bisa diubah lagi
    // kapan saja.
    if (!isEdit) {
      const confirmed = await MySwal.fire({
        title: 'Siapkan Database Lokasi?',
        html: `Tenant <strong>${form.nama}</strong> akan dibuat beserta database
          <code>${databasePreview.value || '-'}</code>, seluruh migration, dan master
          datanya.<br><br>
          Prosesnya berjalan sinkron di server dan dapat memakan waktu
          <strong>1-2 menit</strong>. Jangan tutup halaman selama proses berjalan.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: 'var(--color-primary, #3b82f6)',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Ya, lanjutkan',
        cancelButtonText: 'Batal',
      })

      if (!confirmed.isConfirmed) {
        saving.value = false
        return
      }
    }

    try {
      const payload = bangunPayload(form, isEdit ? KOLOM_EDIT : KOLOM_CREATE)

      const res = isEdit
        ? await locationService.update(route.params.id, payload)
        : await locationService.create(payload)

      if (isEdit) {
        showSuccessToast(res.data?.message || 'Data tenant diperbarui.')
      } else {
        await MySwal.fire({
          icon: 'success',
          title: 'Tenant Berhasil Didaftarkan',
          html: res.data?.message || 'Tenant baru siap digunakan.',
          confirmButtonColor: 'var(--color-primary, #3b82f6)',
        })
      }

      router.push({
        name: 'central-lokasi-detail',
        params: { id: isEdit ? route.params.id : res.data.data.id },
      })
    } catch (error) {
      if (petakanFieldErrors(error)) {
        MySwal.fire({
          icon: 'error',
          title: 'Data Belum Lengkap',
          text: error.response.data?.message || 'Periksa kembali isian yang ditandai.',
          confirmButtonColor: 'var(--color-primary, #3b82f6)',
        })

        return
      }

      MySwal.fire({
        icon: 'error',
        title: isEdit ? 'Gagal Menyimpan' : 'Gagal Mendaftarkan Tenant',
        text: error.response?.data?.message || 'Terjadi kesalahan pada server.',
        confirmButtonColor: 'var(--color-primary, #3b82f6)',
      })
    } finally {
      saving.value = false
    }
  }

  return {
    form,
    errors,
    saving,
    loading,
    isEdit,
    sektorOptions,
    loadingSektor,
    databasePreview,
    goBack,
    handleSubmit,
  }
}

export default useLokasiForm
