<template>
  <div class="cnp" role="dialog" aria-label="Notifikasi Panel Pusat">
    <header class="cnp__head">
      <div class="cnp__title">
        <font-awesome-icon icon="bell" class="cnp__title-icon" />
        <div>
          <h3>Notifikasi</h3>
          <p class="cnp__subtitle">
            <template v-if="loading && !ringkasan">Memuat...</template>
            <template v-else-if="total === 0"
              >Semua beres. Tidak ada yang perlu ditindaklanjuti.</template
            >
            <template v-else>{{ total }} hal perlu ditindaklanjuti.</template>
          </p>
        </div>
      </div>

      <button type="button" class="cnp__close" aria-label="Tutup" @click="emit('close')">
        <font-awesome-icon icon="times" />
      </button>
    </header>

    <!--
         Ringkasan di atas daftar.

         Dua angka ini yang paling sering jadi alasan membuka panel: berapa
         lokasi yang bermasalah, dan berapa nominal yang menghambat.
         Daftar di bawahnya menjawab "yang mana", tapi tidak ada yang
         bisa dilihat tanpa menggulir -- jadi harus ada yang terlihat
         sebelum menggulir.
    -->
    <div class="cnp__stats">
      <div class="cnp__stat">
        <span class="cnp__stat-label">Perlu Tindakan</span>
        <span class="cnp__stat-value is-warn">{{ total }}</span>
      </div>
      <div class="cnp__stat">
        <span class="cnp__stat-label">Tunggakan</span>
        <span class="cnp__stat-value is-danger">{{ formatRupiah(ringkasan?.nilaiTunggakan) }}</span>
      </div>
    </div>

    <div class="cnp__body">
      <div v-if="loading && !ringkasan" class="cnp__empty">
        <font-awesome-icon icon="spinner" spin />
        <span>Memuat notifikasi...</span>
      </div>

      <div v-else-if="error" class="cnp__empty">
        <font-awesome-icon
          icon="triangle-exclamation"
          class="cnp__empty-icon cnp__empty-icon--warn"
        />
        <span>{{ error }}</span>
      </div>

      <div v-else-if="items.length === 0" class="cnp__empty">
        <font-awesome-icon icon="circle-check" class="cnp__empty-icon cnp__empty-icon--ok" />
        <span>Tidak ada notifikasi. Semua lokasi sehat dan tidak ada tunggakan.</span>
      </div>

      <ul v-else class="cnp__list">
        <li
          v-for="item in items"
          :key="item.key"
          class="cnp__row"
          :class="`is-${item.tone}`"
          role="button"
          tabindex="0"
          @click="go(item)"
          @keydown.enter.prevent="go(item)"
          @keydown.space.prevent="go(item)"
        >
          <span class="cnp__row-icon">
            <font-awesome-icon :icon="item.icon" />
          </span>

          <div class="cnp__row-main">
            <p class="cnp__row-title">{{ item.title }}</p>
            <p class="cnp__row-desc">{{ item.desc }}</p>
          </div>

          <font-awesome-icon icon="chevron-right" class="cnp__row-chevron" />
        </li>
      </ul>
    </div>

    <footer class="cnp__foot">
      <button type="button" class="cnp__refresh" :disabled="loading" @click="refresh">
        <font-awesome-icon icon="arrows-rotate" :spin="loading" />
        <span>Segarkan</span>
      </button>
      <button type="button" class="cnp__link" @click="goTagihan">
        <font-awesome-icon icon="file-invoice-dollar" />
        <span>Buka Tagihan</span>
      </button>
    </footer>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useCentralNotifications } from '@/composables/useCentralNotifications'
import { formatRupiah } from '@/utils/format'

const emit = defineEmits(['close'])

const router = useRouter()

/*
 * Panel ini TIDAK punya state sendiri -- semuanya dibaca dari composable.
 *
 * Sebelumnya panel punya `ref` sendiri dan menghitung sendiri aturan "apa
 * yang jadi notifikasi", sementara badge di topnav menghitung dari sumber
 * yang berbeda. Dua salinan aturan itu pasti akan berbeda setelah
 * salah satunya diubah, dan gejalanya bukan "kurang tepat" -- lonceng
 * berbunyi 2, panel terbuka berbunyi 3, dan tidak ada yang bisa memastikan
 * mana yang benar.
 *
 * Badge di topnav harus terlihat SEBELUM panel ada. Kalau panel yang
 * mengisi angkanya sendiri, badge baru terisi setelah lonceng diklik --
 * jadi orang tidak pernah tahu harus membukanya. Karena itu angka dihitung
 * sekali, di `useCentralNotifications`, dan keduanya membacanya.
 *
 * Data diambil dari `/central/dashboard`, bukan endpoint notifikasi
 * tersendiri: angka yang dibutuhkan sudah ada di sana, dan endpoint terpisah
 * akan jadi permintaan kedua ke data yang sama -- dengan peluang besar
 * memberi angka berbeda kalau keduanya dipanggil pada saat yang tidak sama
 * persis.
 *
 * Konsekuensinya: panel ini menampilkan HAL YANG BUKAAN PERUBAH. Tagihan
 * yang terlambat lalu dilunasi tidak muncul di sini. Jangan pakai daftar
 * ini sebagai sumber kebenaran tagihan -- untuk itu ada `/central/tagihan`.
 */
const { items, ringkasan, loading, error, refresh } = useCentralNotifications()

const total = computed(() => items.value.length)

const go = (item) => {
  emit('close')
  router.push({ name: item.route }).catch(() => {})
}

const goTagihan = () => {
  emit('close')
  router.push({ name: 'central-tagihan' }).catch(() => {})
}

// Diambil saat panel pertama kali dibuka, bukan saat komponen dibuat:
// navbar ada di semua halaman, jadi fetch saat mount berjalan di latar
// pada setiap navigasi tanpa ada yang menunggu.
refresh()
</script>

<style scoped>
/*
| Panel notifikasi.
|
| Dua masalah yang berbeda asalnya, dan keduanya diperbaiki di sini.
|
| 1. POSISI. `right: 0` berarti "rata tepi kanan dengan leluhur yang
|    terposisi". Leluhur terposisinya adalah `.central-topnav` (`sticky`),
|    bukan tombol lonceng -- karena `.central-topnav__notif` tidak punya
|    `position: relative` (lihat CentralLayout.vue). Jadi panel 22rem itu
|    terhampar di layar tengah, tidak di bawah tombolnya.
|
| 2. LUAR LAYAR. Bahkan setelah jangkar benar, `right: 0` dengan lebar
|    tetap 22rem tidak pernah bisa menjauh dari tepi kanan. Di layar sempit
|    tombol ikut terdorong ke kanan (navbar `padding: 0 1.5rem` tidak ikut
|    menyusut), dan panel ikut bergeser -- keluar layar.
|
| Yang hilang bukan cuma sebagian panel. `.cnp` punya `overflow: hidden` dan
| panelnya `absolute`, jadi tidak ada cara menggulir ke horizontal untuk
| menemukan kembali bagian yang keluar. Daftar notifikasi yang paling
| penting -- baris "Tangani" -- bisa hilang seluruhnya tanpa jejak.
|
| Dua lapis perbaikan:
|   `max-width: calc(100vw - 1.5rem)` -- jaring pengaman. Panel tidak pernah
|      lebih lebar dari layar: sisa 1.5rem dibagi dua, jadi 0.75rem di kiri
|      dan 0.75rem di kanan. Nilai ini sama dengan jarak tepi yang dipakai
|      `topnav-notifications.css` di aplikasi lokasi, jadi panel di kedua
|      aplikasi tidak terlihat disobeyekkan.
|   Bawah 640px, `position: fixed` dengan `left` dan `right` eksplisit
|      (lihat di bawah).
|
| `z-index: 50` di atas topnav (30), di bawah overlay laci sidebar (35) dan
| pop-up lokasi (1000).
*/
.cnp {
  position: absolute;
  top: calc(100% + 0.5rem);
  right: 0;
  width: 22rem;
  max-width: calc(100vw - 1.5rem);
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-lg, 0.75rem);
  box-shadow: 0 18px 38px -14px rgb(15 23 42 / 0.28);
  overflow: hidden;
  z-index: 50;
  animation: cnp-in 0.15s ease-out;
}

@keyframes cnp-in {
  from {
    opacity: 0;
    transform: translateY(-6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/*
| Layar sempit: panel lepas dari tombol dan jadi seperti sheet.
|
| Alasannya bukan sekadar "Tidak muat". Di bawah 640px, panel 22rem (352px)
| yang menempel pada tombol lonceng membuat dua masalah sekaligus: tepi
| kirinya hampir menyentuh tepi kiri layar (sisanya cuma ~8px), dan panel
| menutupi hampir seluruh lebar layar -- padahal di layar ini yang di bawah
| navbar adalah daftar lokasi, bukan panel.
|
| Full-width dengan jarak tepi 0.75rem (sama seperti `--central-gutter`)
| kelihatan lebih tenang dan, yang lebih penting, MEMBERI RUANG untuk daftar
| di bawahnya terlihat.
*/
@media (max-width: 640px) {
  .cnp {
    position: fixed;
    top: var(--central-topnav-height, 56px);
    left: 0.75rem;
    right: 0.75rem;
    width: auto;
    max-width: none;
  }
}

.cnp__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.cnp__title {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  min-width: 0;
}

.cnp__title-icon {
  color: var(--color-primary, #0ea5e9);
  font-size: 0.95rem;
  margin-top: 0.2rem;
  flex-shrink: 0;
}

.cnp__title h3 {
  font-size: 0.88rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.cnp__subtitle {
  font-size: 0.72rem;
  color: var(--color-gray-400, #94a3b8);
  margin: 0.15rem 0 0;
  line-height: 1.4;
}

.cnp__close {
  background: none;
  border: none;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.85rem;
  padding: 0.25rem;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.16s ease;
}

.cnp__close:hover {
  color: var(--color-text-main, #1e293b);
}

.cnp__stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1px;
  background: var(--color-gray-100, #f1f5f9);
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.cnp__stat {
  background: var(--color-card-bg, #fff);
  padding: 0.7rem 1rem;
}

.cnp__stat-label {
  display: block;
  font-size: 0.64rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-gray-400, #94a3b8);
}

.cnp__stat-value {
  display: block;
  font-size: 1rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin-top: 0.15rem;
}

.cnp__stat-value.is-warn {
  color: var(--color-warning, #f59e0b);
}

.cnp__stat-value.is-danger {
  color: var(--color-red-800, #991b1b);
  font-size: 0.9rem;
}

.cnp__body {
  max-height: 20rem;
  overflow-y: auto;
}

.cnp__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 2.5rem 1.5rem;
  text-align: center;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.5;
}

.cnp__empty-icon {
  font-size: 1.5rem;
}

.cnp__empty-icon--ok {
  color: var(--color-success, #10b981);
}

.cnp__empty-icon--warn {
  color: var(--color-warning, #f59e0b);
}

.cnp__list {
  list-style: none;
  margin: 0;
  padding: 0.35rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.cnp__row {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.65rem 0.7rem;
  border-radius: var(--border-radius-md, 0.5rem);
  cursor: pointer;
  transition: background 0.15s ease;
}

.cnp__row:hover {
  background: var(--color-gray-100, #f1f5f9);
}

.cnp__row-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  flex-shrink: 0;
  border-radius: 8px;
  font-size: 0.75rem;
}

.is-danger .cnp__row-icon {
  background: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
}

.is-warn .cnp__row-icon {
  background: var(--color-yellow-50, #fef3c7);
  color: var(--color-warning, #f59e0b);
}

.is-info .cnp__row-icon {
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
}

.cnp__row-main {
  min-width: 0;
  flex: 1;
}

.cnp__row-title {
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.cnp__row-desc {
  font-size: 0.71rem;
  color: var(--color-gray-500, #6b7280);
  line-height: 1.45;
  margin: 0.15rem 0 0;
}

.cnp__row-chevron {
  font-size: 0.7rem;
  color: var(--color-gray-300, #cbd5e1);
  flex-shrink: 0;
  margin-top: 0.55rem;
}

.cnp__foot {
  display: flex;
  border-top: 1px solid var(--color-gray-100, #f1f5f9);
  background: var(--color-gray-50, #f8fafc);
}

.cnp__refresh,
.cnp__link {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  padding: 0.65rem 0.75rem;
  border: none;
  background: none;
  font-size: 0.75rem;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: background 0.16s ease;
}

.cnp__refresh {
  color: var(--color-gray-500, #6b7280);
  border-right: 1px solid var(--color-gray-100, #f1f5f9);
}

.cnp__refresh:hover:not(:disabled) {
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-text-main, #1e293b);
}

.cnp__refresh:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.cnp__link {
  color: var(--color-primary-dark, #0284c7);
}

.cnp__link:hover {
  background: var(--color-primary-light, #e0f2fe);
}

.cnp__close:focus-visible,
.cnp__row:focus-visible,
.cnp__refresh:focus-visible,
.cnp__link:focus-visible {
  outline: 2px solid var(--color-primary, #0ea5e9);
  outline-offset: -2px;
}
</style>
