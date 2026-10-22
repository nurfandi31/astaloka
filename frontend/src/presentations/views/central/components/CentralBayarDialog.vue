<template>
  <!--
    Dialog pencatatan pembayaran tagihan.

    Dipakai dari dua tempat: saat menandai tagihan lunas dari daftar, dan
    langsung dari form "Terbitkan Tagihan" ketika admin tahu tagihannya
    sudah dibayar. Keduanya memakai dialog yang sama supaya cara
    mencatat pembayaran tidak berbeda tergantung dari mana orang masuk --
    kalau tidak, riwayat pembayaran akan punya dua bentuk yang tidak
    bisa dibandingkan saat rekonsiliasi.

    Dipisah dari view, bukan ditulis inline di dua tempat, karena isinya
    bukan sekadar markup: ada aturan yang berbeda-bedanya menurut metode
    pembayaran (lihat catatan di bawah `metodeTunai`).
  -->
  <Teleport to="body">
    <div v-if="tampil" class="modal-overlay" @click="$emit('tutup')">
      <div class="modal-box modal-box--lebar" @click.stop>
        <div class="modal-box__header">
          <div>
            <h3>Catat Pembayaran</h3>
            <p>{{ invoice?.nomor }} &mdash; {{ invoice?.tenant?.label || 'lokasi' }}</p>
          </div>
          <button type="button" class="modal-box__close" aria-label="Tutup" @click="$emit('tutup')">
            <font-awesome-icon icon="times" />
          </button>
        </div>

        <form class="modal-box__body" @submit.prevent="simpan">
          <div class="form-grid">
            <!--
              Nominal ditampilkan sebagai teks, BUKAN sebagai input.

              Yang dicatat di sini adalah uang yang sudah masuk, jadi
              nilainya adalah keputusan yang sudah diambil saat uang
              diterima -- bukan keputusan yang diambil sekarang. Kalau
              nominalnya bisa diedit di sini, nominal yang salah yang
              tidak sengaja tersimpan akan ikut tercatat di riwayat
              pembayaran, dan yang terlihat benar (tagihan lunas) dengan
              nominal yang bukan jumlah yang benar-benar diterima.

              `form-span` karena dia melintasi seluruh baris, bukan
              berbagi kolom dengan isian di bawahnya. Alasannya bentuk,
              bukan tingkat importance: nominal cuma butuh satu kalimat
              pendek, jadi kalau dia berbagi baris dengan tanggal, baris
              itu jadi setengah kosong dan tanggal terlihat melayang di
              sebelah angka yang sama sekali tidak butuh ruang. Setelah
              nominal mengambil barisnya sendiri, tanggal dan pilihan
              kas/rekening jadi satu baris penuh dan sama lebar, dan
              itulah yang membuat form ini terbaca rapi.
            -->
            <div class="form-static form-span">
              <span class="form-static__label">Nominal Tagihan</span>
              <span class="form-static__value">{{ nominalTagihan }}</span>
            </div>

            <!--
              Tanggal bayar, kalender.

              Berbeda dari tanggal terbit, tanggal ini adalah KEJADIAN
              yang terjadi di masa lalu -- sering bukan hari ini, karena
              bukti transfer bisa baru dilihat besok pagi, atau bahkan
              beberapa hari kemudian. Form ini sengaja membiarkan tanggal
              kosong sebagai bawaan dan membiarkan orang mengisinya.

              Kosong berarti backend memakai `now()`, jadi tetap ada
              tanggalnya di database -- tidak ada keadaan "dibayar tapi
              tanggalnya tidak diketahui" yang tersimpan.
            -->
            <AppDatePicker
              v-model="form.tgl_bayar"
              label="Tanggal Bayar"
              placeholder="Hari ini"
              :max-date="today"
              :error="errors.tgl_bayar"
              no-margin
            />

            <!--
              Kas atau rekening tujuan, dalam SATU dropdown.

              Sebelumnya ada dua isian: pilih "Transfer Bank", baru pilih
              rekeningnya. Dua langkah untuk satu keputusan, dan yang kedua
              hanya muncul setelah yang pertama dijawab -- jadi formnya
              berubah bentuk di depan mata.

              Sekarang daftarnya langsung rekening yang ada: "Kas Tunai",
              "Kas di Bank BRI", dan seterusnya. Satu baris = satu tempat
              uangnya benar-benar masuk, dan cara bayarnya (tunai atau
              transfer) ikut terbaca dari baris itu -- backend menentukannya
              sendiri dari kategori rekening, jadi tidak ada lagi yang bisa
              memilih "Transfer Bank" lalu memakai kas tunai.

              BaseSelect dipakai supaya bentuknya sama persis dengan
              filter di halaman tagihan. Native select membuka tampilan
              berbeda di setiap perangkat, dan panel pusat dibaca dari HP
              maupun desktop.

              TANPA `form-span`: dropdown ini sengaja satu kolom, supaya
              berpasangan dengan "Tanggal Bayar" di sebelahnya dalam satu
              baris. Yang membuat kolom 15rem cukup untuk daftar ini
              adalah nomor rekening TIDAK ikut menempel pada nama di dalam
              dropdown -- nomor dan atas namanya muncul di baris
              `rekening-detail` di bawah grid, yang lebarnya penuh.
              kalau nomornya ikut di dalam label, "Kas di Bank BRI -
              0123456789 (PT Asta Brata)" akan terpotong tepat di bagian
              yang paling dicari orang.

              `show-clear` dimatikan dengan alasan yang sama seperti di
              `CentralLokasiDetail.vue`: kolom ini wajib diisi, sehingga
              saat masih kosong tanda silang PrimeVue hanya menambah
              satu kontrol yang tidak melakukan apa-apa.
            -->
            <div class="form-field">
              <label class="form-label" for="pay-rekening">Kas / Rekening Tujuan</label>
              <BaseSelect
                id="pay-rekening"
                v-model="form.central_account_id"
                :options="pilihanRekening"
                option-label="label"
                option-value="value"
                :placeholder="
                  rekeningLoading ? 'Memuat kas dan rekening...' : 'Pilih kas atau rekening bank'
                "
                :loading="rekeningLoading"
                :disabled="simpanSedang || !pilihanRekening.length"
                :error="errors.central_account_id"
                :show-clear="false"
              />
            </div>
          </div>

          <!--
            Nomor rekening, di luar grid dan selebar form.

            Dua alasan sekaligus. Yang pertama bentuk: grid berakhir di
            baris yang sama dengan tanggal, jadi nomor rekening yang
            dibuat elemen di dalam grid akan ikut duduk di kolom 15rem
            dan ikut terpotong. Yang kedua isi -- yang dicari orang bukan
            "Bank BRI" tapi "rekening nomor berapa". Menampilkannya di
            sini membuat orang bisa mencocokkannya dengan yang ada di
            aplikasi banknya tanpa membuka halaman Rekening; kalau tidak,
            urutannya adalah pilih dulu, buka halaman rekening, cari,
            kembali, lalu menebak yang tadi.
          -->
          <p v-if="rekeningTerpilih?.no_rekening" class="rekening-detail">
            <font-awesome-icon icon="building-columns" />
            <span>
              {{ rekeningTerpilih.no_rekening }}
              <template v-if="rekeningTerpilih.atas_nama">
                &mdash; atas nama {{ rekeningTerpilih.atas_nama }}
              </template>
            </span>
          </p>

          <p
            v-else-if="!rekeningLoading && !pilihanRekening.length"
            class="form-block__hint is-bad"
          >
            Belum ada kas atau rekening bank yang terdaftar di panel pusat. Tambahkan dulu di tabel
            <code>central_accounts</code>.
          </p>

          <BaseInput
            v-model="form.catatan"
            label="Catatan"
            type="textarea"
            :rows="2"
            placeholder="Nomor transfer, nama pengirim, atau catatan lain (opsional)"
            :error="errors.catatan"
          />
        </form>

        <div class="modal-box__footer">
          <button type="button" class="btn btn--ghost" @click="$emit('tutup')">Batal</button>
          <button type="button" class="btn btn--save" :disabled="simpanSedang" @click="simpan">
            <font-awesome-icon v-if="simpanSedang" icon="spinner" spin />
            <font-awesome-icon v-else icon="check" />
            Catat Pembayaran
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import AppDatePicker from '@/presentations/components/AppDatePicker.vue'
import BaseInput from '@/presentations/components/ui/BaseInput.vue'
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'
import { invoiceService, rekeningService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'
// Untuk `.form-span` dan `.rekening-detail` yang dipakai di template di
// bawah tapi didefinisikan di lembar gaya bersama, bukan di `<style scoped>`
// komponen ini. Halaman induknya sudah mengimpornya, tapi dialog ini
// ikut mengimpor sendiri supaya tidak bergantung pada urutan pemuatan.
import '@/assets/css/central-form.css'

const props = defineProps({
  tampil: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
})

const emit = defineEmits(['tutup', 'selesai'])

/*
 * Daftar kas dan rekening tujuan, dalam satu daftar.
 *
 * SEMUA akun aktif bisa dipilih, kas tunai termasuk. Tidak ada lagi
 * isian "cara bayar" terpisah: kategori rekening sudah menyatakan
 * itu, dan membiarkan form memilih sendiri membuka kemungkinan
 * "Transfer Bank" yang dipakai kas tunai -- keadaan yang tercatat
 * benar di kolom `metode` tapi salah di kolom `central_account_id`,
 * dan yang tidak bisa diperbaiki tanpa mengedit pembayaran satu per satu.
 *
 * Urutannya dari server (`kategori` dulu, lalu `kode_akun`), jadi
 * kas tunai selalu di atas. Server juga sudah menyaring yang
 * nonaktif, jadi apa pun yang muncul di sini bisa dipilih.
 */
const today = new Date().toISOString().slice(0, 10)

const form = ref(blank())
const errors = ref({})
const simpanSedang = ref(false)

const rekening = ref([])
const rekeningLoading = ref(false)

function blank() {
  return {
    /*
     * Tanggal bayar TIDAK diisi default dengan hari ini.
     *
     * "Hari ini" benar hampir selalu, tapi tidak selalu: tagihan yang
     * dibayar hari lalu dan baru dicatat hari ini akan tercatat
     * dengan tanggal yang salah, dan itu informasi yang dipakai saat
     * menghitung umur tunggakan. Kosong membiarkan orang mengisinya
     * dengan tanggal yang memang terjadi; backend memakai `now()`
     * kalau dibiarkan kosong, sehingga tidak pernah ada pembayaran
     * tanpa tanggal.
     */
    tgl_bayar: '',
    central_account_id: null,
    catatan: '',
  }
}

const nominalTagihan = computed(() => (props.invoice ? formatRupiah(props.invoice.jumlah) : '0'))

/*
 * Opsi dropdown: nama rekening saja, TANPA nomor rekening.
 *
 * `r.label` dari server sudah menempelkan nomor dan atas nama
 * ("Kas di Bank BRI - 0123456789 (PT Asta Brata)"), dan itu benar
 * untuk tempat yang lantainya besar -- tabel rekening. Di sini tidak:
 * kolomnya 15rem karena berpasangan dengan tanggal di sebelahnya, dan
 * label sepanjang itu terpotong tepat di bagian yang dicari orang.
 *
 * Nomornya tidak hilang, hanya pindah tempat: `rekening-detail` di
 * bawah grid menampilkan nomor dan atas nama dari rekening yang
 * terpilih. Jadi daftar pilihannya tetap pendek dan bisa dibaca
 * sekilas, sementara informasi yang dibutuhkan untuk mencocokkan
 * dengan rekening asli tetap tersedia tanpa membuka halaman lain.
 */
const pilihanRekening = computed(() =>
  rekening.value.map((r) => ({ ...r, value: r.id, label: r.nama_akun })),
)

const rekeningTerpilih = computed(
  () => rekening.value.find((r) => r.id === form.value.central_account_id) || null,
)

/*
 * Muat daftar kas dan rekening saat dialog dibuka, bukan saat komponen
 * dibuat.
 *
 * Dialog ini dibuat sekali per halaman tapi dibuka berkali-kali, dan
 * daftar rekening bisa berubah di tab lain antara satu pembukaan dan
 * berikutnya. Memuat ulang tiap kali dibuka membuat yang tampil
 * selalu sama dengan yang ada di server.
 *
 * Dipicu dari `tampil`, bukan dari `onMounted`: komponen ini dibuat
 * sekali saat halaman dimuat, tapi baru dipakai saat orang menekan
 * "Tandai Lunas". Memuat di `onMounted` berarti daftar yang diambil
 * bisa sudah basi sebelum dialognya pernah dibuka.
 */
watch(
  () => props.tampil,
  async (baru) => {
    if (!baru) return

    form.value = blank()
    errors.value = {}

    rekeningLoading.value = true
    try {
      // Tanpa `all: true` -- server hanya mengembalikan yang aktif, dan
      // form pembayaran tidak boleh menawarkan rekening yang sudah
      // dinonaktifkan.
      const res = await rekeningService.getAll()
      rekening.value = res.data?.data || []
    } catch {
      rekening.value = []
    } finally {
      rekeningLoading.value = false
    }
  },
)

async function simpan() {
  errors.value = {}

  if (!props.invoice) return

  /*
   * Kas atau rekening dicek di sini, bukan diserahkan ke backend.
   *
   * Bukan karena backend tidak akan menolak -- dia tetap menolak. Tapi
   * errornya akan datang sebagai 422 setelah tombol sempat berkedip
   * "memproses", dan untuk satu dropdown kosong yang hanya perlu diisi,
   * satu klik ulang lebih murah daripada satu putaran request.
   */

  if (!form.value.central_account_id) {
    errors.value.central_account_id = 'Pilih kas atau rekening bank yang menerima pembayaran.'
    return
  }

  simpanSedang.value = true

  try {
    const payload = {
      tgl_lunas: form.value.tgl_bayar || null,
      central_account_id: form.value.central_account_id,
      catatan: form.value.catatan.trim() || null,
    }

    const res = await invoiceService.markPaid(props.invoice.id, payload)

    emit('selesai', res.data?.data || null)
  } catch (error) {
    const fieldErrors = error.response?.data?.errors || {}

    if (Object.keys(fieldErrors).length) {
      errors.value = fieldErrors
    }
  } finally {
    simpanSedang.value = false
  }
}
</script>

<style scoped>
/*
 * Blok statis, bukan komponen terpisah.
 *
 * Dipakai di form ini hanya sekali, dan isinya empat baris. Kalau mulai
 * muncul di halaman lain, barulah jadi komponen sendiri.
 */
.form-static {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.form-static__label {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-sub, #64748b);
}

.form-static__value {
  font-size: 1.35rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: var(--color-text-main, #1e293b);
  padding: 0.55rem 0;
  border-bottom: 2px solid var(--color-gray-200, #e2e8f0);
}

/*
 * Hanya gaya yang benar-benar milik dialog ini.
 *
 * `.form-span` (dropdown memanjang dari kolom pertama sampai terakhir)
 * dan `.rekening-detail` (nomor rekening di bawah pilihan) sengaja TIDAK
 * diulang di sini -- keduanya sudah ada di `central-form.css`, yang diimpor
 * oleh `CentralTagihanIndex.vue`, halaman induk yang selalu memuat dialog
 * ini. Menyalinnya berarti aturan yang sama punya dua tempat, dan perbaikan
 * di satu tidak menyentuh yang lain.
 */
.form-block__hint {
  font-size: 0.75rem;
  margin-top: 0.4rem;
  color: var(--color-text-sub, #64748b);
}

.form-block__hint.is-bad {
  color: var(--color-danger, #dc2626);
}
</style>
