<template>
  <!--
    Detail satu tagihan.

    Tiap baris di daftar tagihan bisa diklik untuk membuka ini. Konten yang
    DIHAPUS dari tabel -- jatuh tempo, tanggal bayar, rekening tujuan -- tetap
    ada di sini, lengkap dengan konteks yang tidak muat di kolom mana pun:
    sisa hari sebelum diblokir, dan catatan pembayaran.

    Rincian item TIDAK ikut ditampilkan, walaupun backend mengirimnya. Yang
    ditegardah di sini adalah satu tagihan = satu pembayaran untuk satu
    aplikasi, jadi nominal utuh di dalam kotak nominal sudah menjawab
    pertanyaannya. Daftar rincian add-on (lisensi, modul, user tambahan)
    membuat pop-up ini terlihat seperti rincian belanja -- dan orang yang
    membacanya menyimpulkan nominal tagihan bisa dirinci, padahal yang
    sebenarnya ditagihkan framework tetap satu jumlah. Rincian lengkapnya
    ada di cetakan invoice, yang memang satu tempat untuk dokumen resmi.

    Satu dialog untuk seluruh halaman, bukan satu per baris: tagihan yang
    dipilih disimpan di `invoice` pada halaman induk, jadi 50 baris tetap
    berarti satu dialog yang kebanyakan tidak pernah dibuka.
  -->
  <Teleport to="body">
    <div
      v-if="tampil && invoice"
      class="tp"
      role="dialog"
      aria-modal="true"
      :aria-label="`Detail tagihan ${invoice.nomor}`"
      @click.self="emit('tutup')"
    >
      <div class="tp__panel">
        <!-- ===== HEADER ===== -->
        <header class="tp__head">
          <div class="tp__mark" :class="`is-${toneTunggakan}`">
            <font-awesome-icon :icon="ikonTunggakan" />
          </div>

          <div class="tp__ident">
            <div class="tp__badges">
              <span class="status-pill" :class="lunas ? 'status-pill--on' : 'status-pill--off'">
                {{ lunas ? 'Lunas' : 'Belum Lunas' }}
              </span>
              <span v-if="terlambat" class="tp__chip is-bad">
                <font-awesome-icon icon="triangle-exclamation" />
                Terlambat {{ Math.abs(sisaHari) }} hari
              </span>
              <span v-else-if="!lunas && sisaHari >= 0" class="tp__chip">
                <font-awesome-icon icon="clock" />
                {{ sisaHari }} hari lagi
              </span>
            </div>

            <h2 class="tp__title">{{ invoice.nomor }}</h2>
            <p class="tp__tenant">
              {{ invoice.tenant?.label || 'Lokasi tidak diketahui' }}
              <template v-if="invoice.tenant?.slug">
                <span class="tp__slug">{{ invoice.tenant.slug }}</span>
              </template>
            </p>
          </div>

          <button type="button" class="tp__close" aria-label="Tutup" @click="emit('tutup')">
            <font-awesome-icon icon="xmark" />
          </button>
        </header>

        <div class="tp__body">
          <!-- ===== NOMINAL =====
               Sendiri, melebar penuh. Ini angka yang jadi alasan orang membuka
               tagihan ini, jadi tidak boleh berbagi baris dengan tanggal --
               seperti nominal di dialog pembayaran, bukan seperti kolom
               "Jumlah" di tabel yang harus duduk di samping kolom lain. -->
          <div class="tp__amount">
            <p class="tp__amount-label">Nominal Tagihan</p>
            <p class="tp__amount-value">{{ formatRupiah(invoice.jumlah) }}</p>
            <p v-if="invoice.keterangan" class="tp__amount-note">
              {{ invoice.keterangan }}
            </p>
          </div>

          <!-- ===== FAKTA =====
               Tanpa judul seksi. Dulu ada dua seksi (Tanggal + Rincian), jadi
               judulnya dibutuhkan untuk memisahkan keduanya; sekarang hanya
               satu blok, dan setiap barisnya sudah punya label sendiri --
               judul "Tanggal" di atas daftar yang isinya juga tanggal bayar dan
               rekening hanya menambah kata, bukan informasi. -->
          <dl class="tp__facts">
            <div class="tp__fact">
              <dt><font-awesome-icon icon="paper-plane" /> Terbit</dt>
              <dd>{{ invoice.tgl_invoice || '—' }}</dd>
            </div>

            <div class="tp__fact" :class="terlambat ? 'is-bad' : ''">
              <dt><font-awesome-icon icon="clock" /> Jatuh Tempo</dt>
              <dd>
                {{ invoice.tgl_jatuh_tempo || invoice.deadline || '—' }}
                <span v-if="!invoice.tgl_jatuh_tempo && invoice.deadline" class="tp__hint">
                  otomatis: {{ invoice.tgl_invoice }} + masa tenggang
                </span>
              </dd>
            </div>

            <div class="tp__fact" :class="lunas ? 'is-ok' : ''">
              <dt><font-awesome-icon icon="circle-check" /> Dibayar</dt>
              <dd>
                <template v-if="lunas">
                  {{ invoice.payment?.tgl_bayar || invoice.tgl_lunas || '—' }}
                </template>
                <template v-else>Belum dibayar</template>
              </dd>
            </div>

            <div v-if="lunas && rekeningPembayaran" class="tp__fact">
              <dt><font-awesome-icon icon="building-columns" /> Diterima lewat</dt>
              <dd>{{ rekeningPembayaran }}</dd>
            </div>

            <div v-if="catatanPembayaran" class="tp__fact">
              <dt><font-awesome-icon icon="pen-to-square" /> Catatan bayar</dt>
              <dd>{{ catatanPembayaran }}</dd>
            </div>
          </dl>
        </div>

        <!-- ===== FOOTER =====
              Aksinya pindah ke sini. Semuanya dulu berada di kolom "Aksi"
              dan di kaki kartu HP, jadi satu tagihan punya dua tempat tombol
              yang isinya sama persis; menyatukannya di bawah pop-up ini
              membuat hanya ada satu cara untuk menandai lunas tagihan. -->
        <footer class="tp__foot">
          <button type="button" class="tp__btn tp__btn--ghost" @click="emit('tutup')">Tutup</button>

          <div class="tp__foot-actions">
            <button type="button" class="tp__btn tp__btn--print" @click="emit('cetak')">
              <font-awesome-icon icon="print" />
              Cetak
            </button>

            <button v-if="!lunas" type="button" class="tp__btn tp__btn--pay" @click="emit('bayar')">
              <font-awesome-icon icon="check" />
              Tandai Lunas
            </button>

            <button v-else type="button" class="tp__btn tp__btn--undo" @click="emit('batal')">
              <font-awesome-icon icon="rotate-left" />
              Batalkan Lunas
            </button>
          </div>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, watch, onBeforeUnmount } from 'vue'
import { formatRupiah } from '@/utils/format'
// `.status-pill*` dipakai di header pop-up dan berasal dari stylesheet ini
// (bukan `<style scoped>` komponen), jadi dialog mengimpor sendiri supaya
// tidak bergantung pada halaman induk yang kebetulan memuatnya lebih dulu.
import '@/assets/css/central-form.css'

const props = defineProps({
  tampil: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
})

const emit = defineEmits(['tutup', 'cetak', 'bayar', 'batal'])

/* ===== NILAI TURUNAN ===== */

const lunas = computed(() => props.invoice?.status === 'PAID')
const terlambat = computed(() => Boolean(props.invoice?.terlambat))
const sisaHari = computed(() => Number(props.invoice?.sisa_hari || 0))

/*
 * Rekening tujuan, sudah termasuk nomor dan atas namanya.
 *
 * `payment.rekening` dari server sudah berupa label lengkap ("Kas di Bank
 * BRI - 0123456789 (PT Asta Brata)"), jadi tidak dirangkai di sini. Kalau
 * label itu kosong -- pembayaran lama yang tercatat sebelum rekeningnya
 * ditautkan -- jatuh ke nama metode, dan kalau itu juga kosong, barisnya
 * disembunyikan oleh `v-if` di template: lebih baik tidak ada baris daripada
 * baris yang isinya "Dibayar lewat —" dan terlihat seperti data rusak.
 */
const rekeningPembayaran = computed(() => {
  const p = props.invoice?.payment
  if (!p) return null

  if (p.rekening) return p.rekening
  if (p.metode === 'tunai') return 'Kas Tunai'
  if (p.metode) return 'Transfer Bank'

  return null
})

const catatanPembayaran = computed(() => props.invoice?.payment?.catatan || null)

/*
 * Ikon dan warna penanda, mengikuti urutan KEPUTUSAN yang sama dengan
 * `kondisiLokasi` di dashboard: masalah terbesar menang. Terlambat lebih
 * penting dari status lunas, jadi tagihan yang lunas TIDAK PERNAH punya
 * penanda "terlambat" -- `terlambat` sendiri sudah bernilai `false` untuk
 * tagihan lunas, karena `isOverdue()` di server sudah mengecek `!isPaid()`
 * di dalamnya, jadi bukan dua aturan yang harus dijaga agar sinkron.
 */
const penanda = computed(() => {
  if (lunas.value) return { ikon: 'circle-check', tone: 'ok' }
  if (terlambat.value) return { ikon: 'triangle-exclamation', tone: 'bad' }
  return { ikon: 'file-invoice-dollar', tone: 'warn' }
})

/*
 * Ikon dan warnanya ditulis sebagai dua `computed` terpisah, bukan satu
 * objek yang di-destructure.
 *
 * Bentuk yang lama:
 *
 *     const { ikonTunggakan, toneTunggakan } = computed(() => ({ ... }))
 *
 * Terlihat benar, tapi destruktur terhadap `ComputedRef` itu SALAH. Yang
 * di-destructure adalah objek ref-nya -- yang propertinya `value`, `effect`,
 * dan `__v_isRef`. `ikonTunggakan` dan `toneTunggakan` tidak ada di sana,
 * jadi keduanya bernilai `undefined`.
 *
 * Gejalanya tidak mencolok: `<font-awesome-icon :icon="undefined" />`
 * tetap merender sebuah `<svg>`, tapi svg-nya KOSONG (tanpa `<path>`).
 * Jadi ikon "hilang" tanpa error dan tanpa elemen yang hilang dari DOM --
 * hanya kotak kosong sebesar ikon itu. Peramban juga menulis peringatan
 * "Could not find one or more icon(s) undefined" di console, yang mudah
 * terlewat di antara banyak log lain.
 *
 * Perbaikan ini memisahkannya jadi dua `computed` biasa, jadi nilainya
 * diambil dari `.value` secara normal.
 */
const ikonTunggakan = computed(() => penanda.value.ikon)
const toneTunggakan = computed(() => penanda.value.tone)

/* ===== PERILAKU JENDELA ===== */

/*
 * Escape menutup, scroll body dikunci selama terbuka.
 *
 * Roda mouse di atas elemen `position: fixed` bisa ikut menggulir halaman di
 * belakangnya. Tanpa `overflow: hidden` di body, orang yang sedang membaca
 * daftar tagihan bisa unknowingly menggulir halaman lain tanpa sadar tabelnya
 * sudah bergeser -- dan di halaman ini polling 45 detik membuat daftar
 * diam-diam berubah, jadi "baris yang tadi" tidak selalu baris yang sama.
 */
const onKeydown = (e) => {
  if (e.key === 'Escape' && props.tampil) emit('tutup')
}

watch(
  () => props.tampil,
  (terbuka) => {
    document.body.style.overflow = terbuka ? 'hidden' : ''
  },
)

window.addEventListener('keydown', onKeydown)
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<style scoped>
/*
 | POP-UP DETAIL TAGIHAN
 |
 | Bahasa warna SENADA dengan `CentralLokasiPopup.vue` dan halaman tagihan
 | itu sendiri, karena pop-up ini terbuka di atas halaman itu -- ia bagian
 | dari halaman, bukan lapisan asing.
 |
 | Tirai gelap tetap cara termurah untuk menarik perhatian tanpa memindahkan
 | focus: tidak ada satu pun angka daftar yang perlu dibaca ulang saat
 | pop-up ditutup.
 */
.tp {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.25rem;
  background: rgb(15 23 42 / 0.55);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  animation: tp-fade 0.16s ease;
}

@keyframes tp-fade {
  from {
    opacity: 0;
  }
}

.tp__panel {
  /* Peta lokal ke token bersama `--ct-*` -- lihat catatan panjang di
     `assets/css/central-tokens.css`. Nilai warna tidak ditulis di sini.
     Pop-up tagihan tidak memetakan varian `-fill`: tidak ada isian solid
     di sini, hanya chip dan baris info yang memakai `-tint`. */
  --tp-surface: var(--ct-surface-2);
  --tp-line: var(--ct-line);
  --tp-line-strong: var(--ct-line-strong);
  --tp-text: var(--ct-text);
  --tp-dim: var(--ct-text-dim);
  --tp-faint: var(--ct-text-faint);

  --tp-sky: var(--ct-sky);
  --tp-sky-tint: var(--ct-sky-tint);
  --tp-green: var(--ct-green);
  --tp-green-tint: var(--ct-green-tint);
  --tp-amber: var(--ct-amber);
  --tp-amber-tint: var(--ct-amber-tint);
  --tp-red: var(--ct-red);
  --tp-red-tint: var(--ct-red-tint);

  display: flex;
  flex-direction: column;
  width: 100%;
  /* 40rem, bukan 62rem seperti pop-up lokasi: isinya satu nominal dan lima
     fakta. Panel yang selebar separuh layar hanya memperbesar ruang kosong di
     kanan -- dan nominal rupiah yang tampil sempit sudah kelihatan sendiri
     karena tidak ada lagi yang membaginya dengan kolom lain. */
  max-width: 40rem;
  /* `dvh` bukan `vh`: di layar pendek (laptop dengan panel browser tinggi)
     versi `vh` membuat bagian bawah pop-up terpotong dan tidak bisa
     dijangkau. */
  max-height: calc(100dvh - 2.5rem);
  border: 1px solid var(--tp-line);
  border-radius: 18px;
  background: var(--color-card-bg, #fff);
  box-shadow: 0 40px 80px -30px rgb(15 23 42 / 0.55);
  overflow: hidden;
  animation: tp-rise 0.2s ease;
}

@keyframes tp-rise {
  from {
    opacity: 0;
    transform: translateY(10px) scale(0.985);
  }
}

/* ===== HEADER ===== */

.tp__head {
  display: flex;
  align-items: flex-start;
  gap: 0.8rem;
  padding: 0.95rem 1.1rem;
  border-bottom: 1px solid var(--tp-line);
  background:
    radial-gradient(520px 170px at 6% 0%, var(--tp-sky-tint), transparent 70%),
    var(--color-card-bg, #fff);
  flex-shrink: 0;
}

.tp__mark {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.6rem;
  height: 2.6rem;
  flex-shrink: 0;
  border-radius: 13px;
  font-size: 1.05rem;
  background: var(--tp-surface);
  color: var(--tp-dim);
}

.tp__mark.is-ok {
  background: var(--tp-green-tint);
  color: var(--tp-green);
}
.tp__mark.is-warn {
  background: var(--tp-amber-tint);
  color: var(--tp-amber);
}
.tp__mark.is-bad {
  background: var(--tp-red-tint);
  color: var(--tp-red);
}

.tp__ident {
  flex: 1;
  min-width: 0;
}

.tp__badges {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-wrap: wrap;
  margin-bottom: 0.3rem;
}

/*
 * Chip "terlambat N hari" dan "N hari lagi".
 *
 * Padding lebih kecil dari `.status-pill` yang duduk di sebelahnya: keduanya
 * hidup di baris yang sama, dan ukuran yang sama membuat chip terlihat
 * melayang di samping pill yang lebih tinggi.
 */
.tp__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  min-height: 0;
  padding: 0.18rem 0.5rem;
  border-radius: 999px;
  background: var(--tp-surface);
  color: var(--tp-dim);
  font-size: 0.6rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
}

.tp__chip.is-bad {
  background: var(--tp-red-tint);
  color: var(--tp-red);
}

.tp__title {
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 1.02rem;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--tp-text);
  overflow-wrap: anywhere;
}

.tp__tenant {
  margin: 0.1rem 0 0;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--tp-text);
  overflow-wrap: anywhere;
}

.tp__slug {
  margin-left: 0.4rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.68rem;
  font-weight: 400;
  color: var(--tp-faint);
}

.tp__close {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.9rem;
  height: 1.9rem;
  flex-shrink: 0;
  border: 1px solid var(--tp-line);
  border-radius: 9px;
  background: transparent;
  color: var(--tp-dim);
  font-size: 0.8rem;
  cursor: pointer;
  transition: all 0.16s ease;
}

.tp__close:hover {
  background: var(--tp-red-tint);
  border-color: var(--tp-red-tint);
  color: var(--tp-red);
}

/* ===== BODY ===== */

.tp__body {
  flex: 1;
  /* `min-height: 0` wajib: tanpa itu flex item tidak boleh menyusut di
     bawah tinggi isinya, jadi `max-height` panel tidak pernah ditegakkan dan
     yang terpotong adalah kaki dialog -- tempat semua tombolnya. */
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 1rem 1.1rem 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.tp__body::-webkit-scrollbar {
  width: 8px;
}

.tp__body::-webkit-scrollbar-thumb {
  background: var(--tp-line-strong);
  border-radius: 4px;
}

/* ===== NOMINAL ===== */

.tp__amount {
  padding: 0.8rem 0.9rem;
  border-radius: 13px;
  background: linear-gradient(135deg, var(--tp-sky-tint), #f5f3ff);
  border: 1px solid var(--tp-sky-tint);
}

.tp__amount-label {
  margin: 0;
  font-size: 0.58rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--tp-sky);
}

.tp__amount-value {
  margin: 0.18rem 0 0;
  font-size: 1.65rem;
  font-weight: 900;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.03em;
  line-height: 1.15;
  color: var(--tp-text);
}

/*
 * Keterangan invoice dipindah ke bawah nominal, bukan ke baris terpisah.
 *
 * Di tabel dia harus dipotong dengan ellipsis supaya kolom "Nomor" tidak
 * ikut melebar -- dan keterangan justru sering menjelaskan kenapa nominal
 * segitu ada. Di sini ruangnya ada, jadi ia boleh ditulis penuh.
 */
.tp__amount-note {
  margin: 0.3rem 0 0;
  font-size: 0.74rem;
  line-height: 1.5;
  color: var(--tp-dim);
}

/* ===== FAKTA ===== */

.tp__facts {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0.15rem 0.8rem;
  border: 1px solid var(--tp-line);
  border-radius: 12px;
  background: var(--color-card-bg, #fff);
}

.tp__fact {
  display: grid;
  /* Label selebar tetap: isinya "Diterima lewat" (14 karakter) terpotong di
     kolom yang lebih sempit, dan kolom nilai yang paling penting -- nama
     rekening -- justru ikut sempit. */
  grid-template-columns: 8.5rem minmax(0, 1fr);
  gap: 0.6rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid var(--tp-line);
}

.tp__fact:last-child {
  border-bottom: none;
}

.tp__fact dt {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--tp-faint);
}

.tp__fact dt svg {
  width: 0.8rem;
  flex-shrink: 0;
  text-align: center;
}

.tp__fact dd {
  margin: 0;
  font-size: 0.74rem;
  font-weight: 600;
  color: var(--tp-text);
  overflow-wrap: anywhere;
}

.tp__fact.is-bad dd {
  color: var(--tp-red);
}

.tp__fact.is-ok dd {
  color: var(--tp-green);
}

/*
 * Catatan kecil di bawah nilai -- asal batas otomatis dan catatan transfer.
 *
 * `display: block` karena dua-duanya menjawab pertanyaan yang BERBEDA dari
 * nilai di atasnya: tanggal memberitahukan KAPAN, catatan memberitahukan
 * MENGAPA/DARI MANA. Disatukan jadi satu baris membuat tanggal ikut
 * terpotong, dan tanggal yang terpotong tidak bisa dibaca sama sekali.
 */
.tp__hint {
  display: block;
  margin-top: 0.12rem;
  font-size: 0.64rem;
  font-weight: 400;
  color: var(--tp-faint);
}

/* ===== FOOTER ===== */

.tp__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.9rem;
  flex-shrink: 0;
  padding: 0.75rem 1.1rem;
  border-top: 1px solid var(--tp-line);
  background: var(--tp-surface);
}

.tp__foot-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.tp__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  min-height: 2.25rem;
  padding: 0.45rem 0.85rem;
  border: 1px solid transparent;
  border-radius: 9px;
  font-family: inherit;
  font-size: 0.74rem;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.16s ease;
}

.tp__btn--ghost {
  background: transparent;
  border-color: var(--tp-line);
  color: var(--tp-dim);
}

.tp__btn--ghost:hover {
  background: var(--color-card-bg, #fff);
  color: var(--tp-text);
}

.tp__btn--print {
  background: #fff;
  border-color: #bae6fd;
  color: #0284c7;
}

.tp__btn--print:hover {
  background: #f0f9ff;
  border-color: #7dd3fc;
}

.tp__btn--pay {
  background: var(--tp-green-tint);
  border-color: #a7f3d0;
  color: #059669;
}

.tp__btn--pay:hover {
  background: #d1fae5;
}

.tp__btn--undo {
  background: #fff;
  border-color: var(--tp-line-strong);
  color: #475569;
}

.tp__btn--undo:hover {
  background: #f8fafc;
  color: var(--tp-text);
}

/* ===== AKSESIBILITAS ===== */

.tp__close:focus-visible,
.tp__btn:focus-visible {
  outline: 2px solid var(--tp-sky);
  outline-offset: 2px;
}

/* ===== RESPONSIF ===== */

/*
 * Di bawah 40rem kaki dialog menumpuk, tombol utama tetap penuh baris.
 *
 * "Tandai Lunas" tidak boleh dipotong di 320px: itu tombol yang dicari orang
 * saat tagihan benar-benar sudah dibayar, dan labelnya yang sering membuat
 * orang berpikir ada tombol yang lebih pendek di sebelahnya.
 */
@media (max-width: 40rem) {
  .tp {
    padding: 0.6rem;
  }

  .tp__panel {
    max-height: calc(100dvh - 1.2rem);
  }

  .tp__fact {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.12rem;
  }

  .tp__foot {
    flex-direction: column;
    align-items: stretch;
    gap: 0.55rem;
  }

  .tp__foot-actions {
    flex-wrap: wrap;
  }

  .tp__foot-actions .tp__btn {
    flex: 1 1 auto;
  }
}

@media (prefers-reduced-motion: reduce) {
  .tp,
  .tp__panel {
    animation: none;
  }

  .tp__btn,
  .tp__close {
    transition: none;
  }
}
</style>
