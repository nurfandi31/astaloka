<template>
  <Teleport to="body">
    <div
      v-if="show && rincian"
      class="pv"
      role="dialog"
      aria-modal="true"
      :aria-label="`Detail lokasi ${rincian.label}`"
      @click.self="emit('close')"
    >
      <div class="pv__panel">
        <!-- ===== HEADER ===== -->
        <header class="pv__head">
          <div class="pv__ident">
            <div class="pv__mark" :class="`is-${markTone}`">
              <font-awesome-icon :icon="markIcon" />
            </div>

            <div class="pv__ident-text">
              <div class="pv__badges">
                <span v-if="rincian.sektor" class="pv__chip">{{ rincian.sektor }}</span>
                <span class="pv__chip" :class="rincian.is_active ? 'is-ok' : 'is-off'">
                  {{ rincian.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
                <span v-if="!rincian.database_ada" class="pv__chip is-bad">
                  <font-awesome-icon icon="database" />
                  Database hilang
                </span>
              </div>

              <h2 class="pv__title">{{ rincian.label }}</h2>
              <p class="pv__slug">{{ rincian.slug }}</p>
            </div>
          </div>

          <button type="button" class="pv__close" aria-label="Tutup" @click="emit('close')">
            <font-awesome-icon icon="xmark" />
          </button>
        </header>

        <div class="pv__body">
          <!-- ===== PROFIL USAHA ===== -->
          <section class="pv__col">
            <h3 class="pv__section-title">
              <font-awesome-icon icon="building" />
              Profil Usaha
            </h3>

            <div class="pv__usaha" :class="rincian.bidang_usaha ? '' : 'is-empty'">
              <p class="pv__usaha-label">Bidang Usaha</p>
              <p class="pv__usaha-value">{{ usaha }}</p>
              <p v-if="rincian.deskripsi_usaha" class="pv__usaha-desc">
                {{ rincian.deskripsi_usaha }}
              </p>
              <p v-else class="pv__usaha-desc is-hint">
                Belum diisi. Buka halaman Ubah Tenant untuk menambahkan bidang usaha lokasi ini.
              </p>
            </div>

            <dl class="pv__facts">
              <div class="pv__fact">
                <dt><font-awesome-icon icon="location-dot" /> Alamat</dt>
                <dd>{{ rincian.alamat || '—' }}</dd>
              </div>
              <div class="pv__fact">
                <dt><font-awesome-icon icon="envelope" /> Email</dt>
                <dd>
                  <a v-if="rincian.email" :href="`mailto:${rincian.email}`" class="pv__link">
                    {{ rincian.email }}
                  </a>
                  <span v-else>—</span>
                </dd>
              </div>
              <div class="pv__fact">
                <dt><font-awesome-icon icon="phone" /> Telepon</dt>
                <dd>{{ rincian.telepon || '—' }}</dd>
              </div>
              <div class="pv__fact">
                <dt><font-awesome-icon icon="globe" /> Domain</dt>
                <dd>
                  <!--
                    Wildcard tidak pernah jadi tautan: `*.astaloka.test`
                    tidak punya host yang bisa diklik. `tenantUrl()` juga
                    memilih http/https sesuai jenis host-nya, jadi domain
                    development (`.test`) tidak menghasilkan tautan
                    `https://` yang gagal sebelum halaman ter-load.
                  -->
                  <a
                    v-if="tenantUrl(rincian.domain)"
                    :href="tenantUrl(rincian.domain)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="pv__link"
                  >
                    {{ rincian.domain }}
                    <font-awesome-icon icon="arrow-up-right-from-square" />
                  </a>
                  <span v-else>—</span>
                </dd>
              </div>
              <div class="pv__fact">
                <dt><font-awesome-icon icon="database" /> Database</dt>
                <dd>
                  <code class="pv__code">{{ rincian.database }}</code>
                </dd>
              </div>
              <div class="pv__fact">
                <dt><font-awesome-icon icon="calendar-days" /> Berlaku Sampai</dt>
                <dd>{{ rincian.tgl_berakhir || 'Tanpa batas waktu' }}</dd>
              </div>
            </dl>
          </section>

          <!-- ===== TAGIHAN ===== -->
          <section class="pv__col pv__col--wide">
            <h3 class="pv__section-title">
              <font-awesome-icon icon="file-invoice-dollar" />
              Rincian Tagihan
            </h3>

            <div class="pv__stats">
              <div class="pv__stat" :class="rincian.terlambat.jumlah ? 'is-bad' : 'is-ok'">
                <p class="pv__stat-label">Terlambat</p>
                <p class="pv__stat-value">{{ formatRupiah(rincian.terlambat.nominal) }}</p>
                <p class="pv__stat-note">{{ rincian.terlambat.jumlah }} tagihan</p>
              </div>

              <div class="pv__stat" :class="rincian.belumLunas.jumlah ? 'is-warn' : 'is-ok'">
                <p class="pv__stat-label">Belum Lunas</p>
                <p class="pv__stat-value">{{ formatRupiah(rincian.belumLunas.nominal) }}</p>
                <p class="pv__stat-note">{{ rincian.belumLunas.jumlah }} tagihan</p>
              </div>

              <div class="pv__stat is-ok">
                <p class="pv__stat-label">Lunas</p>
                <p class="pv__stat-value">{{ formatRupiah(rincian.lunas.nominal) }}</p>
                <p class="pv__stat-note">{{ rincian.lunas.jumlah }} tagihan</p>
              </div>

              <div class="pv__stat is-total">
                <p class="pv__stat-label">Total Seluruh Tagihan</p>
                <p class="pv__stat-value">{{ formatRupiah(rincian.total.nominal) }}</p>
                <p class="pv__stat-note">
                  {{ rincian.total.jumlah }} tagihan sejak {{ pertamaDiterbitkan }}
                </p>
              </div>
            </div>

            <div class="pv__invoices">
              <div class="pv__invoices-head">
                <h4 class="pv__invoices-title">Daftar Tagihan</h4>
              </div>

              <div v-if="riwayatLoading && !riwayat.length" class="pv__state">
                <font-awesome-icon icon="spinner" spin />
                <span>Memuat riwayat tagihan...</span>
              </div>

              <div v-else-if="!riwayat.length" class="pv__state">
                <font-awesome-icon icon="circle-check" />
                <span>Belum ada tagihan untuk lokasi ini.</span>
              </div>

              <template v-else>
                <div class="pv__table-wrap" :class="{ 'is-growing': riwayatGrowing }">
                  <table class="pv__table">
                    <thead>
                      <tr>
                        <th class="pv__th">Nomor</th>
                        <th class="pv__th">Periode</th>
                        <th class="pv__th">Terbit</th>
                        <th class="pv__th">Jatuh Tempo</th>
                        <th class="pv__th pv__th--right">Jumlah</th>
                        <th class="pv__th pv__th--center">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="inv in riwayat" :key="inv.id" class="pv__tr">
                        <td class="pv__td">
                          <span class="pv__inv-number">{{ inv.nomor }}</span>
                          <span v-if="inv.keterangan" class="pv__inv-note" :title="inv.keterangan">
                            {{ inv.keterangan }}
                          </span>
                        </td>
                        <td class="pv__td pv__td--mono">{{ inv.periode || '—' }}</td>
                        <td class="pv__td pv__td--mono">{{ inv.tgl_invoice || '—' }}</td>
                        <td class="pv__td pv__td--mono">
                          {{ inv.tgl_jatuh_tempo || inv.deadline }}
                          <span v-if="inv.terlambat" class="pv__inv-bad">
                            <font-awesome-icon icon="triangle-exclamation" />
                            {{ Math.abs(inv.sisa_hari) }} hari
                          </span>
                        </td>
                        <td class="pv__td pv__td--right pv__td--amount">
                          {{ formatRupiah(inv.jumlah) }}
                        </td>
                        <td class="pv__td pv__td--center">
                          <span
                            class="pv__pill"
                            :class="
                              inv.status === 'PAID' ? 'is-ok' : inv.terlambat ? 'is-bad' : 'is-warn'
                            "
                          >
                            {{
                              inv.status === 'PAID'
                                ? 'Lunas'
                                : inv.terlambat
                                  ? 'Terlambat'
                                  : 'Belum Lunas'
                            }}
                          </span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="pv__pager">
                  <p v-if="terpotong" class="pv__footnote">
                    <font-awesome-icon icon="circle-info" />
                    Menampilkan {{ riwayat.length }} tagihan terbaru dari total {{ totalRiwayat }}.
                  </p>
                  <span v-else />
                  <button
                    v-if="terpotong"
                    type="button"
                    class="pv__more-btn"
                    :disabled="riwayatLoading"
                    @click="muatSemua"
                  >
                    <font-awesome-icon
                      :icon="riwayatLoading ? 'spinner' : 'chevron-down'"
                      :spin="riwayatLoading"
                    />
                    {{ riwayatLoading ? 'Memuat...' : 'Muat semua' }}
                  </button>
                </div>
              </template>
            </div>
          </section>
        </div>

        <!-- ===== FOOTER ===== -->
        <footer class="pv__foot">
          <p class="pv__foot-note">
            <font-awesome-icon icon="circle-info" />
            Dashboard ini hanya menampilkan. Terbitkan tagihan dan ubah data tenant dilakukan di
            halaman tenant.
          </p>

          <div class="pv__foot-actions">
            <button type="button" class="pv__btn pv__btn--ghost" @click="emit('close')">
              Tutup
            </button>
            <router-link
              :to="{ name: 'central-lokasi-detail', params: { id: rincian.id } }"
              class="pv__btn pv__btn--primary"
              @click="emit('close')"
            >
              <font-awesome-icon icon="arrow-right" />
              Halaman Tenant
            </router-link>
          </div>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { invoiceService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'
import { tenantUrl } from '@/utils/tenantUrl'

const props = defineProps({
  /** Object dari `rincianLokasi` di `/central/dashboard` untuk satu tenant. */
  rincian: { type: Object, default: null },
  show: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

/* ===== RIWAYAT TAGIHAN ===== */

const riwayat = ref([])
const riwayatLoading = ref(false)
const riwayatGrowing = ref(false)
const totalRiwayat = ref(0)

/** Berapa baris yang ditampilkan sebelum orang menekan "Muat semua". */
const AWAL = 8

/**
 * Ambil daftar tagihan untuk satu lokasi.
 *
 * `perPage` 8 (= AWAL) untuk opening, 50 untuk "muat semua". 50 bukan
 * "semua": endpoint ini dipaginasi, jadi satu tombol tidak pernah mengunduh
 * seluruh riwayat tiga tahun dalam satu kali. Yang tersisa punya halaman
 * tagihan yang memang bisa difilter dan dipaginasi.
 */
const ambilRiwayat = async (perPage) => {
  try {
    const res = await invoiceService.getAll({
      tenant_id: props.rincian.id,
      per_page: perPage,
    })

    riwayat.value = res.data?.data?.data ?? []
    totalRiwayat.value = res.data?.data?.meta?.total ?? riwayat.value.length
  } catch {
    // Diamkan saja. Error di sini tidak menutup popup: profil usaha dan semua
    // angka ringkasan di atas sudah benar dan tetap berguna.
    riwayat.value = []
    totalRiwayat.value = 0
  }
}

const muatSemua = async () => {
  riwayatGrowing.value = true
  riwayatLoading.value = true
  await ambilRiwayat(50)
  riwayatLoading.value = false
  riwayatGrowing.value = false
}

/*
 * Riwayat hanya diambil saat popup benar-benar dibuka, dan dibuang saat
 * ditutup.
 *
 * `immediate: true` sengaja TIDAK dipakai bersama `show`: kalau dipairingkan
 * begitu, `rincian` yang diberikan akan diambil saat komponen dibuat --
 * yaitu saat halaman dashboard dimuat, untuk semua lokasi sekaligus kalau
 * pemanggil salah. Yang memicu di sini hanya perubahan `show`, jadi satu
 * request per pembukaan, untuk satu lokasi yang benar-benar dibuka.
 */
watch(
  () => props.show,
  async (terbuka) => {
    if (!terbuka || !props.rincian) {
      riwayat.value = []
      totalRiwayat.value = 0
      return
    }

    riwayatLoading.value = true
    await ambilRiwayat(AWAL)
    riwayatLoading.value = false
  },
)

/* ===== NILAI TURUNAN ===== */

/**
 * Nama usaha yang tampil.
 *
 * `usaha_label` dari backend sudah berurutan dari paling spesifik ke paling
 * umum (bidang_usaha -> nama_lembaga -> nama). Fallback di sini hanya untuk
 * kalau backend versi lama tidak mengirim field itu -- pakai `label` yang
 * memang selalu ada.
 */
const usaha = computed(() => props.rincian?.usaha_label || props.rincian?.label || '—')

/** "Maret 2025" dari tagihan paling lama. Kosong kalau belum ada tagihan. */
const pertamaDiterbitkan = computed(() => {
  const tertua = [...riwayat.value].reverse().at(0)
  if (!tertua?.tgl_invoice) return 'berakhir'

  const [tahun, bulan] = tertua.tgl_invoice.split('-')
  const nama = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
  ]

  return `${nama[Number(bulan) - 1] ?? bulan} ${tahun}`
})

/** True kalau yang tampil belum seluruh riwayat. */
const terpotong = computed(() => riwayat.value.length < totalRiwayat.value)

/**
 * Ikon + warna penanda di header.
 *
 * Mengikuti urutan KEPUTUSAN yang sama dengan `kondisiLokasi` di backend --
 * masalah yang paling besar menang. Database hilang mengalahkan kedaluwarsa
 * karena lokasi tanpa database tidak bisa dipakai sama sekali, jadi status
 * langganannya belum relevan di depan masalah itu.
 */
/*
 * Bentuk yang lama:
 *
 *     const { markIcon, markTone } = computed(() => ({ ... }))
 *
 * Destruktur terhadap `ComputedRef` itu SALAH: yang di-destructure adalah
 * objek ref-nya -- yang propertinya `value`, `effect`, dan `__v_isRef`.
 * `markIcon` dan `markTone` tidak ada di sana, jadi keduanya `undefined`.
 *
 * Gejalanya tidak mencolok: `<font-awesome-icon :icon="undefined" />` tetap
 * merender sebuah `<svg>`, tapi svg-nya KOSONG (tanpa `<path>`). Ikon
 * "hilang" tanpa error dan tanpa elemen yang hilang dari DOM.
 *
 * Dipisah jadi dua `computed` biasa supaya nilainya diambil dari `.value`.
 */
const penandaLokasi = computed(() => {
  if (!props.rincian) return { ikon: 'location-dot', tone: 'muted' }
  if (!props.rincian.database_ada) return { ikon: 'database', tone: 'bad' }
  if (props.rincian.terlambat?.jumlah > 0) return { ikon: 'clock-rotate-left', tone: 'warn' }
  if (!props.rincian.is_active) return { ikon: 'ban', tone: 'muted' }
  return { ikon: 'location-dot', tone: 'ok' }
})

const markIcon = computed(() => penandaLokasi.value.ikon)
const markTone = computed(() => penandaLokasi.value.tone)

/*
 * Escape menutup, sama seperti modal lain di panel ini (`BaseModal`).
 * Dipasang saat komponen dibuat dan dilepas saat halaman dibuang lewat
 * `import.meta.hot` -- kalau dipasang di `onMounted` saja, popup yang belum
 * pernah dibuka tidak akan listen Escape, dan popup yang sedang terbuka akan
 * tetap merespons tombol dari popup lain.
 *
 * `onBeforeUnmount` ditambahkan juga supaya scroll body dikembalikan saat
 * orang menutup tab / berpindah halaman dengan popup masih terbuka -- kalau
 * tidak, `overflow: hidden` di body ikut tertinggal dan seluruh aplikasi
 * terasa beku setelah meninggalkan halaman ini.
 */
const onKeydown = (e) => {
  if (e.key === 'Escape' && props.show) emit('close')
}

watch(
  () => props.show,
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
 | POP-UP DETAIL LOKASI
 |
 | Warnanya SENADA dengan dashboard, bukan netral.
 |
 | Alasannya: pop-up ini terbuka di atas dashboard, jadi ia adalah bagian
 | dari halaman itu -- bukan lapisan asing. Kalau warnanya melenceng dari
 | dashboard, orang harus menyesuaikan mata dua kali: sekali untuk popup, lalu
 | sekali lagi saat kembali ke dashboard. Satu bahasa warna = satu kali
 | penyesuaian.
 |
 | Panel sekarang ikut terang, sama seperti dashboard. Yang tetap gelap hanya
 | TIRAI di belakang pop-up -- bukan halamannya. Tirai gelap tetap cara termurah
 | untuk menarik attention tanpa memindahkan focus, dan tidak ada satu pun
 | angka dashboard yang perlu dibaca ulang saat pop-up ditutup.
 */
.pv {
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
  animation: pv-fade 0.16s ease;
}

@keyframes pv-fade {
  from {
    opacity: 0;
  }
}

.pv__panel {
  /* Peta lokal ke token bersama `--ct-*` -- lihat catatan panjang di
     `assets/css/central-tokens.css`. Nilai warna tidak ditulis di sini. */
  --pv-bg: var(--color-card-bg, #ffffff);
  --pv-surface: var(--ct-surface-2);
  --pv-surface-2: var(--ct-surface-2);
  --pv-line: var(--ct-line);
  --pv-line-strong: var(--ct-line-strong);
  --pv-text: var(--ct-text);
  --pv-dim: var(--ct-text-dim);
  --pv-faint: var(--ct-text-faint);

  --pv-sky: var(--ct-sky);
  --pv-sky-tint: var(--ct-sky-tint);
  --pv-sky-fill: var(--ct-sky-fill);
  --pv-green: var(--ct-green);
  --pv-green-tint: var(--ct-green-tint);
  --pv-green-fill: var(--ct-green-fill);
  --pv-amber: var(--ct-amber);
  --pv-amber-tint: var(--ct-amber-tint);
  --pv-amber-fill: var(--ct-amber-fill);
  --pv-red: var(--ct-red);
  --pv-red-tint: var(--ct-red-tint);
  --pv-red-fill: var(--ct-red-fill);
  --pv-slate-tint: var(--ct-slate-tint);
  --pv-violet: var(--ct-violet);
  --pv-violet-tint: var(--ct-violet-tint);

  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 62rem;
  /* `dvh` bukan `vh`: di layar pendek (laptop dengan panel browser tinggi)
     versi `vh` membuat bagian bawah pop-up terpotong dan tidak bisa
     dijangkau. */
  max-height: calc(100dvh - 2.5rem);
  border: 1px solid var(--pv-line);
  border-radius: 18px;
  background: var(--pv-bg);
  box-shadow: 0 40px 80px -30px rgb(15 23 42 / 0.55);
  overflow: hidden;
  animation: pv-rise 0.2s ease;
}

@keyframes pv-rise {
  from {
    opacity: 0;
    transform: translateY(10px) scale(0.985);
  }
}

/* ===== HEADER ===== */

.pv__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.9rem;
  padding: 0.95rem 1.1rem;
  border-bottom: 1px solid var(--pv-line);
  background:
    radial-gradient(560px 180px at 6% 0%, var(--pv-sky-tint), transparent 70%), var(--pv-bg);
  flex-shrink: 0;
}

.pv__ident {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  min-width: 0;
}

.pv__mark {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.6rem;
  height: 2.6rem;
  flex-shrink: 0;
  border-radius: 13px;
  font-size: 1.05rem;
  background: var(--pv-slate-tint);
  color: var(--pv-dim);
}

.pv__mark.is-ok {
  background: var(--pv-green-tint);
  color: var(--pv-green);
}
.pv__mark.is-warn {
  background: var(--pv-amber-tint);
  color: var(--pv-amber);
}
.pv__mark.is-bad {
  background: var(--pv-red-tint);
  color: var(--pv-red);
}

.pv__ident-text {
  min-width: 0;
}

.pv__badges {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  flex-wrap: wrap;
  margin-bottom: 0.3rem;
}

.pv__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  background: var(--pv-violet-tint);
  color: var(--pv-violet);
  font-size: 0.58rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
}

.pv__chip.is-ok {
  background: var(--pv-green-tint);
  color: var(--pv-green);
}
.pv__chip.is-off {
  background: var(--pv-slate-tint);
  color: var(--pv-dim);
}
.pv__chip.is-bad {
  background: var(--pv-red-tint);
  color: var(--pv-red);
}

.pv__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--pv-text);
  overflow-wrap: anywhere;
}

.pv__slug {
  margin: 0.1rem 0 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.66rem;
  color: var(--pv-faint);
}

.pv__close {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.9rem;
  height: 1.9rem;
  flex-shrink: 0;
  border: 1px solid var(--pv-line);
  border-radius: 9px;
  background: transparent;
  color: var(--pv-dim);
  font-size: 0.8rem;
  cursor: pointer;
  transition: all 0.16s ease;
}

.pv__close:hover {
  background: var(--pv-red-tint);
  border-color: var(--pv-red-tint);
  color: var(--pv-red);
}

/* ===== BODY: DUA KOLOM ===== */

/*
 * Kolom kanan 1.5x kolom kiri.
 *
 * Kiri isinya daftar label-value yang tidak panjang; kanan berisi empat kartu
 * nominal dan tabel tagihan. Tabel butuh lebar -- nama tagihan, periode, dua
 * tanggal, nominal, dan status tidak muat di separuh panel tanpa membuat
 * semua kolom melebar jadi tidak terbaca.
 */
.pv__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.5fr);
  gap: 1.1rem;
  flex: 1;
  min-height: 0;
  padding: 1rem 1.1rem;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.pv__body::-webkit-scrollbar {
  width: 8px;
}

.pv__body::-webkit-scrollbar-thumb {
  background: var(--pv-line-strong);
  border-radius: 4px;
}

.pv__col {
  min-width: 0;
}

.pv__section-title {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0 0 0.7rem;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--pv-dim);
}

.pv__section-title svg {
  color: var(--pv-sky);
}

/* ===== PROFIL USAHA ===== */

.pv__usaha {
  padding: 0.7rem 0.8rem;
  border-radius: 12px;
  background: linear-gradient(135deg, var(--pv-sky-tint), var(--pv-violet-tint));
  border: 1px solid var(--pv-line);
  margin-bottom: 0.8rem;
}

.pv__usaha.is-empty {
  background: var(--pv-surface-2);
  border-color: var(--pv-line);
  border-style: dashed;
}

.pv__usaha-label {
  margin: 0;
  font-size: 0.58rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--pv-faint);
}

.pv__usaha-value {
  margin: 0.2rem 0 0;
  font-size: 0.95rem;
  font-weight: 800;
  line-height: 1.3;
  color: var(--pv-text);
  overflow-wrap: anywhere;
}

.pv__usaha-desc {
  margin: 0.3rem 0 0;
  font-size: 0.7rem;
  line-height: 1.5;
  color: var(--pv-dim);
}

.pv__usaha-desc.is-hint {
  color: var(--pv-faint);
  font-style: italic;
}

/* ===== FAKTA ===== */

.pv__facts {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  margin: 0;
}

.pv__fact {
  display: grid;
  grid-template-columns: 7.2rem minmax(0, 1fr);
  gap: 0.5rem;
  padding: 0.42rem 0;
  border-bottom: 1px solid var(--pv-line);
}

.pv__fact:last-child {
  border-bottom: none;
}

.pv__fact dt {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.66rem;
  font-weight: 700;
  color: var(--pv-faint);
}

.pv__fact dt svg {
  width: 0.8rem;
  flex-shrink: 0;
  text-align: center;
}

.pv__fact dd {
  margin: 0;
  font-size: 0.72rem;
  color: var(--pv-text);
  overflow-wrap: anywhere;
}

.pv__link {
  color: var(--pv-sky);
  font-weight: 600;
  text-decoration: none;
}

.pv__link:hover {
  text-decoration: underline;
}

.pv__code {
  padding: 0.08rem 0.35rem;
  border-radius: 5px;
  background: var(--pv-surface-2);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.68rem;
  color: var(--pv-dim);
}

/* ===== STATISTIK TAGIHAN ===== */

.pv__stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin-bottom: 0.85rem;
}

.pv__stat {
  padding: 0.6rem 0.65rem;
  border-radius: 11px;
  background: var(--pv-surface-2);
  border-top: 2px solid var(--pv-dim);
  min-width: 0;
}

.pv__stat.is-ok {
  border-top-color: var(--pv-green-fill, #10b981);
}
.pv__stat.is-warn {
  border-top-color: var(--pv-amber-fill, #f59e0b);
}
.pv__stat.is-bad {
  border-top-color: var(--pv-red-fill, #ef4444);
}

/*
 * TOTAL diberi treatment sendiri: melebar penuh, warna paling kuat.
 *
 * Alasannya tiga, dan semuanya soal "apa yang dicari orang pertama": ini
 * angka yang paling sering ditanyakan ("berapa tagihan lokasi ini?"), ini
 * satu-satunya angka yang JUMLAH dari tiga angka di atasnya (jadi bisa dicek
 * sendiri), dan ini angka yang justifies kenapa pop-up ini dibuka.
 *
 * Kalau keempat angka dibuat sama besar, yang ini tidak menonjol padahal
 * justru yang paling penting.
 */
.pv__stat.is-total {
  grid-column: 1 / -1;
  padding: 0.75rem 0.85rem;
  background: linear-gradient(135deg, var(--pv-sky-tint), var(--pv-violet-tint));
  border: 1px solid var(--pv-sky-tint);
  border-top-width: 2px;
  border-top-color: var(--pv-sky);
}

.pv__stat-label {
  margin: 0;
  font-size: 0.56rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--pv-faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pv__stat.is-total .pv__stat-label {
  color: var(--pv-sky);
}

.pv__stat-value {
  margin: 0.18rem 0 0;
  font-size: 0.95rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--pv-text);
  overflow-wrap: anywhere;
}

.pv__stat.is-total .pv__stat-value {
  font-size: 1.5rem;
  color: var(--pv-text);
}

.pv__stat-note {
  margin: 0.1rem 0 0;
  font-size: 0.6rem;
  color: var(--pv-faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pv__stat.is-total .pv__stat-note {
  font-size: 0.65rem;
  color: var(--pv-dim);
}

/* ===== DAFTAR TAGIHAN ===== */

.pv__invoices {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.pv__invoices-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  margin-bottom: 0.45rem;
}

.pv__invoices-title {
  margin: 0;
  font-size: 0.7rem;
  font-weight: 800;
  color: var(--pv-text);
}

.pv__state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 2rem 1rem;
  color: var(--pv-faint);
  font-size: 0.74rem;
  font-weight: 600;
}

.pv__table-wrap {
  border: 1px solid var(--pv-line);
  border-radius: 11px;
  overflow-x: auto;
  background: var(--pv-surface-2);
}

.pv__table {
  width: 100%;
  border-collapse: collapse;
  min-width: 34rem;
}

.pv__th {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 0.45rem 0.6rem;
  text-align: left;
  font-size: 0.56rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--pv-faint);
  background: var(--pv-surface-2);
  white-space: nowrap;
  user-select: none;
}

.pv__th--right {
  text-align: right;
}

.pv__th--center {
  text-align: center;
}

.pv__td {
  padding: 0.45rem 0.6rem;
  font-size: 0.68rem;
  color: var(--pv-dim);
  border-top: 1px solid var(--pv-line);
  vertical-align: middle;
}

.pv__td--mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.64rem;
  white-space: nowrap;
}

.pv__td--right {
  text-align: right;
}

.pv__td--center {
  text-align: center;
}

.pv__td--amount {
  font-weight: 800;
  color: var(--pv-text);
  white-space: nowrap;
}

.pv__tr:hover .pv__td {
  background: var(--pv-surface-2);
}

.pv__inv-number {
  display: block;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.66rem;
  font-weight: 700;
  color: var(--pv-text);
  white-space: nowrap;
}

.pv__inv-note {
  display: block;
  max-width: 13rem;
  margin-top: 0.05rem;
  font-size: 0.58rem;
  color: var(--pv-faint);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pv__inv-bad {
  display: block;
  margin-top: 0.08rem;
  font-size: 0.58rem;
  font-weight: 700;
  color: var(--pv-red);
  white-space: nowrap;
}

.pv__pill {
  display: inline-block;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  font-size: 0.55rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
}

.pv__pill.is-ok {
  background: var(--pv-green-tint);
  color: var(--pv-green);
}
.pv__pill.is-warn {
  background: var(--pv-amber-tint);
  color: var(--pv-amber);
}
.pv__pill.is-bad {
  background: var(--pv-red-tint);
  color: var(--pv-red);
}

.pv__pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  margin-top: 0.5rem;
}

.pv__footnote {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  font-size: 0.62rem;
  color: var(--pv-faint);
}

.pv__more-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.32rem 0.6rem;
  border: 1px solid var(--pv-line);
  border-radius: 8px;
  background: transparent;
  color: var(--pv-sky);
  font-size: 0.64rem;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.16s ease;
}

.pv__more-btn:hover:not(:disabled) {
  border-color: var(--pv-sky);
  background: var(--pv-sky-tint);
}

.pv__more-btn:disabled {
  opacity: 0.55;
  cursor: progress;
}

/* ===== FOOTER ===== */

.pv__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.9rem;
  flex-shrink: 0;
  padding: 0.75rem 1.1rem;
  border-top: 1px solid var(--pv-line);
  background: var(--pv-surface-2);
}

/*
 * Catatan di footer bukan hiasan.
 *
 * Ini satu-satunya tempat yang menyatakan dengan jelas bahwa dashboard TIDAK
 * bisa menerbitkan tagihan -- dan itu pertanyaan yang muncul begitu pop-up
 * ini terbuka. Menjawabannya di sini (bukan dengan menolak, tapi dengan
 * menunjukkan ke mana) lebih baik daripada membiarkan orang mencari-cari
 * tombol yang memang tidak ada.
 */
.pv__foot-note {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.64rem;
  line-height: 1.45;
  color: var(--pv-faint);
  max-width: 26rem;
}

.pv__foot-note svg {
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.pv__foot-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.pv__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.42rem 0.75rem;
  border: 1px solid transparent;
  border-radius: 9px;
  font-size: 0.7rem;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.16s ease;
}

.pv__btn--ghost {
  background: transparent;
  border-color: var(--pv-line);
  color: var(--pv-dim);
}

.pv__btn--ghost:hover {
  background: var(--pv-surface-2);
  color: var(--pv-text);
}

.pv__btn--primary {
  background: linear-gradient(135deg, #0ea5e9, #38bdf8);
  color: #fff;
  /* Pop-up ini lewat Teleport ke <body>, jadi TIDAK berada di dalam
   * `.central-layout` dan tidak ikut mewarisi --central-shadow-btn-primary
   * dari sana. Nilainya ditulis langsung, sama seperti --pv-* yang lain di
   * file ini. Kalau tokennya diubah di CentralLayout.vue, nilai di sini ikut
   * disesuaikan manual.
   */
  box-shadow: 0 8px 18px -10px rgb(56 189 248 / 0.8);
}

.pv__btn--primary:hover {
  filter: brightness(1.08);
  transform: translateY(-1px);
}

/* ===== AKSESIBILITAS ===== */

.pv__close:focus-visible,
.pv__more-btn:focus-visible,
.pv__btn:focus-visible,
.pv__link:focus-visible {
  outline: 2px solid var(--pv-sky);
  outline-offset: 2px;
}

/* ===== RESPONSIF ===== */

@media (max-width: 900px) {
  .pv__body {
    grid-template-columns: minmax(0, 1fr);
  }

  .pv__stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .pv__stat.is-total {
    grid-column: 1 / -1;
  }
}

@media (max-width: 640px) {
  .pv {
    padding: 0.6rem;
  }

  .pv__panel {
    max-height: calc(100dvh - 1.2rem);
  }

  .pv__fact {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.1rem;
  }

  .pv__stats {
    grid-template-columns: minmax(0, 1fr);
  }

  .pv__foot {
    flex-direction: column;
    align-items: stretch;
    gap: 0.6rem;
  }

  .pv__foot-note {
    max-width: none;
  }

  .pv__foot-actions {
    justify-content: stretch;
  }

  .pv__btn {
    flex: 1;
    justify-content: center;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pv,
  .pv__panel {
    animation: none;
  }

  .pv__btn,
  .pv__close,
  .pv__more-btn {
    transition: none;
  }
}
</style>
