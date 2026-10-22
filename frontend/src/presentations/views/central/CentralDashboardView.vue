<template>
  <!--
    | Dibangun ulang 2026-10-15. Struktur sebelumnya: 4 kartu KPI sejajar,
    | baris "perlu perhatian", tiga chart (bar + 2 donat), lalu tabel lokasi.
    | Semuanya kartu putih dengan tinggi sama, di atas latar abu muda.
    |
    | Kartunya sekarang: hero total tagihan + 2 KPI + kondisi lokasi di baris
    | atas; daftar lokasi dan "Perlu Perhatian" di baris bawah. Kartu "Status
    | Tagihan" di kolom kanan sudah dihapus 2026-10-16 -- isinya ada di hero,
    | di "Perlu Perhatian", dan lengkapnya di halaman Tagihan.
    |
    | Yang berubah, dan kenapa:
    |
    | 1. WARNA. Tema terang, sama seperti seluruh aplikasi dan seperti
    |    halaman-halaman panel pusat yang lain (Tenant, Tagihan, Superadmin).
    |    Versi sebelumnya memakai latar gelap dengan kartu yang lebih terang
    |    dari latarnya, dengan alasan "pemisah yang jelas antara panel pusat
    |    dan aplikasi lokasi". Pemisah itu sudah ada dan lebih murah: sidebar,
    |    topnav, dan isi halamannya sendiri sudah berbeda. Yang ditambahkan
    |    latar gelap cuma kontras yang harus dibaca ulang setiap kali mata
    |    berpindah ke tab aplikasi lokasi -- sementara warna aksen di kedua
    |    tempat sudah sama-sama sky.
    |
    | 2. LAYOUT. Bento: kartu berukuran tidak seragam, masing-masing punya
    |    peran dan luas yang berbeda. Empat KPI lama sama besar padahal
    |    jelas tidak setara -- "Total Lokasi" cuma satu angka dan bisa dibaca
    |    dalam sepersekian detik, sedangkan "Nilai Tunggakan" justru butuh
    |    ruang karena angkanya panjang dan sering jadi fokus.
    |
    | 3. POP-UP PER LOKASI. Yang benar-benar baru. Dulu tidak ada cara untuk
    |    melihat "lokasi ini usaha apa, tagihannya berapa, detailnya seperti
    |    apa" tanpa pindah halaman. Sekarang satu klik.
    |
    | 4. TIDAK ADA SCROLL Halaman. Grid ini `height: 100dvh` dan setiap
    |    panel yang isinya bisa banyak (daftar lokasi, daftar tagihan di
    |    pop-up) punya scroll-nya sendiri di dalam. Alasannya bukan sekadar
    |    Alasan: dashboard ini dipakai sambil berdiri, dan halaman yang harus
    |    di-scroll membuat angka ringkasan keluar dari layar persis saat
    |    orang butuh membandingkan.
    |
    | 5. TIDAK ADA TOMBOL TAMBAH / TERBITKAN. Ini lihat-baca saja. Semua
    |    tindakan mengarah ke halaman masing-masing, dan pop-up hanya punya
    |    tombol "Buka Halaman Tenant".
    -->
  <div class="bd">
    <!-- ===== BARIS 1: HERO TOTAL + KPI + KONDISI ===== -->
    <section class="bd__hero">
      <!--
        Kartu hero menampilkan TOTAL SELURUH TAGIHAN, bukan tunggakan.

        Alasannya: ini satu-satunya angka di halaman ini yang tidak bisa
        dijawab dari mana pun lain di panel. Tunggakan ada di halaman tagihan,
        jumlah lokasi ada di halaman tenant -- tapi "sejak sistem berjalan kita
        sudah menagihkan berapa" hanya bisa dijawab di sini.

        Tunggakan TIDAK lagi jadi KPI terpisah, tapi tetap ada tepat di
        bawahnya. Yang berubah cuma tempatnya: dua angka ini dibaca berurutan
        -- "total" lalu "dari total itu, berapa yang belum masuk" -- jadi
        menumpuknya di satu kartu lebih cepat dipahami daripada sengaja
        memisahkannya ke dua kartu yang jauh.
      -->
      <article class="card card--hero">
        <header class="card__head">
          <span class="card__kicker">
            <font-awesome-icon icon="wallet" />
            Total Seluruh Tagihan
          </span>
          <span class="card__stamp" :class="{ 'is-busy': busy }" :title="stampTitle">
            <font-awesome-icon icon="rotate" :spin="busy" />
            {{ waktuRelatif }}
          </span>
        </header>

        <p class="hero__value">{{ formatRupiah(ringkasan.nilaiTotalTagihan) }}</p>
        <p class="hero__note">
          {{ ringkasan.jumlahTagihanDiterbitkan }} tagihan diterbitkan ke
          {{ ringkasan.totalLokasi }} lokasi sejak panel ini dipakai.
        </p>

        <!--
          Tunggakan dan lewat jatuh tempo: DUA KOLOM di bawah angka total.

          Sempat ditumpuk satu di bawah yang lain, dengan alasan "keduanya
          bagian dari satu cerita, jadi dibaca dari atas ke bawah". Alasan itu
          benar secara bahasa, tapi salah secara ruang: kartu hero punya tinggi
          tetap dari grid (`minmax(0, 1fr)`) dan `overflow: hidden`, jadi
          setiap baris ekstra tidak menambah tinggi kartu, dia memotong isi.
          Dua sel yang ditumpuk = 6 baris teks, dan pada layar pendek baris
          terakhir ("2 tagihan") terpotong di tengah.

          Dua kolom memakai ruang kosong yang memang sudah ada di kanan kartu.
          Isinya jadi 3 baris, jadi muat tanpa dipotong di tinggi kartu yang
          sama. Urutan bacanya tetap sama -- kiri ke kanan, tepat di bawah
          totalnya -- jadi ceritanya tidak berubah: "dari total segini, berapa
          yang belum masuk, dan berapa yang sudah lewat tempo".

          Catatan kedua sel dibuat memakai pola yang sama ("4 tagihan belum
          lunas" / "2 tagihan lewat tempo"). Sebelumnya yang kedua hanya
          "2 tagihan" tanpa penjelas, padahal yang pertama menjelaskan --
          dua baris dengan bentuk berbeda di dalam satu blok terbaca sebagai
          dua hal yang beda importance-nya.
        -->
        <div class="hero__stack">
          <div class="hero__cell">
            <p class="hero__cell-label">Tunggakan</p>
            <p class="hero__cell-value">{{ formatRupiah(ringkasan.nilaiTunggakan) }}</p>
            <p class="hero__cell-note">{{ ringkasan.tagihanBelumLunas }} tagihan belum lunas</p>
          </div>
          <div class="hero__cell">
            <p class="hero__cell-label">Lewat Jatuh Tempo</p>
            <p class="hero__cell-value" :class="{ 'is-bad': ringkasan.tagihanTerlambat > 0 }">
              {{ formatRupiah(ringkasan.nilaiTerlambat) }}
            </p>
            <p class="hero__cell-note">{{ ringkasan.tagihanTerlambat }} tagihan lewat tempo</p>
          </div>
        </div>
      </article>

      <!-- KPI: dua angka, satu di bawah yang lain -->
      <div class="bd__kpis">
        <article v-for="k in kpis" :key="k.key" class="card card--kpi" :class="`is-${k.tone}`">
          <span class="kpi__icon"><font-awesome-icon :icon="k.icon" /></span>
          <div class="kpi__text">
            <p class="kpi__label">{{ k.label }}</p>
            <p class="kpi__value">{{ k.nilai }}</p>
            <p class="kpi__note">{{ k.note }}</p>
          </div>
        </article>
      </div>

      <!--
        Kondisi lokasi.

        PIE PENUH (lingkaran padat, tanpa lubang di tengah), bukan donat dan
        bukan bar.

        Kenapa bukan donat: lubang di tengah memakan radius yang justru dipakai
        untuk menampilkan ukuran irisan kecil. Kategori di sini bisa 1 lokasi
        dari 20, dan di donat 5% itu jadi garis tipis yang tidak bisa dibaca.

        Kenapa bukan bar seperti sebelumnya: bar diisi dengan "terbesar = 100%"
        -- jadi panjangnya BUKAN proporsi sebenarnya. Itu membuat "1 nonaktif
        dari 20" terlihat sama dengan "20 nonaktif" kalau kategori sehat yang
        dihitung dari 20. Di chart ini panjang busur justru proporsi yang
        jujur, jadi tidak perlu penjelasan tambahan untuk membacanya.

        Tidak ada label di dalam irisan: 6 kategori di radius 100px menghasilkan
        teks yang hanya muat di 1 dari 6 busur. Nilai lengkap ada di legenda di
        bawah, dan irisan kecil tetap bisa dibaca lewat HOVER lewat tooltip.
      -->
      <article class="card card--kondisi">
        <header class="card__head">
          <h3 class="card__title">Kondisi Lokasi</h3>
          <span class="card__count">{{ ringkasan.totalLokasi }}</span>
        </header>

        <div v-if="!kondisiSeries.length" class="card__empty">
          <font-awesome-icon icon="location-dot" />
          Belum ada lokasi terdaftar.
        </div>

        <template v-else>
          <div class="pie-wrap">
            <svg
              class="pie"
              :viewBox="`0 0 ${pie.size} ${pie.size}`"
              role="img"
              :aria-label="pie.ariaLabel"
              @mouseleave="pieHover = null"
            >
              <template v-for="s in pie.slices" :key="s.key">
                <!-- Kategori tunggal: <circle>, bukan busur 360 derajat -->
                <circle
                  v-if="s.circle"
                  class="pie__slice"
                  :class="{ 'is-dim': pieHover && pieHover !== s.key }"
                  :cx="pie.cx"
                  :cy="pie.cy"
                  :r="s.circle"
                  :fill="s.color"
                  @mouseenter="pieHover = s.key"
                />
                <path
                  v-else
                  :d="s.path"
                  :fill="s.color"
                  class="pie__slice"
                  :class="{ 'is-dim': pieHover && pieHover !== s.key }"
                  @mouseenter="pieHover = s.key"
                />
              </template>
            </svg>

            <!-- Tooltip: nilai + persentase, muncul saat irisan di-hover -->
            <div v-if="pieHover" class="pie-tip" role="status">
              <span class="pie-tip__dot" :style="{ background: pie.byKey[pieHover].color }" />
              <span class="pie-tip__label">{{ pie.byKey[pieHover].label }}</span>
              <span class="pie-tip__value">{{ pie.byKey[pieHover].nilai }}</span>
              <span class="pie-tip__pct">{{ pie.byKey[pieHover].persen }}%</span>
            </div>
          </div>

          <!-- Legenda: nilai sebelum label, karena angka yang dicari duluan -->
          <ul class="pie-legend">
            <li
              v-for="s in pie.legend"
              :key="s.key"
              class="pie-legend__item"
              :class="{ 'is-on': pieHover === s.key }"
              @mouseenter="pieHover = s.key"
              @mouseleave="pieHover = null"
            >
              <span class="pie-legend__dot" :style="{ background: s.color }" />
              <span class="pie-legend__value">{{ s.nilai }}</span>
              <span class="pie-legend__label">{{ s.label }}</span>
            </li>
          </ul>
        </template>
      </article>
    </section>

    <!-- ===== BARIS 2: DAFTAR LOKASI (kiri) + SISI KANAN ===== -->
    <section class="bd__split">
      <!-- ===== DAFTAR LOKASI ===== -->
      <article class="card card--list">
        <header class="card__head card__head--tools">
          <div>
            <h3 class="card__title">Lokasi</h3>
            <p class="card__sub">
              {{ daftarLokasi.length }} dari {{ ringkasan.totalLokasi }} lokasi
            </p>
          </div>

          <div class="list__tools">
            <div class="list__search">
              <font-awesome-icon icon="magnifying-glass" />
              <input
                v-model="search"
                type="search"
                placeholder="Cari nama, sektor, domain..."
                aria-label="Cari lokasi"
              />
            </div>

            <div class="list__filters">
              <button
                v-for="f in filterChip"
                :key="f.key"
                type="button"
                class="chip"
                :class="{ 'is-on': filter === f.key }"
                @click="filter = f.key"
              >
                {{ f.label }}
                <span v-if="f.count" class="chip__count">{{ f.count }}</span>
              </button>
            </div>
          </div>
        </header>

        <div class="list__head">
          <button
            type="button"
            class="list__sort"
            :class="{ 'is-on': urut === 'urgensi' }"
            @click="urut = 'urgensi'"
          >
            Urutan: {{ URTAN[urut].label }}
          </button>

          <router-link to="/central/lokasi" class="list__cta">
            <font-awesome-icon icon="location-dot" />
            Kelola Lokasi
          </router-link>
        </div>

        <div v-if="!siap" class="list__state">
          <font-awesome-icon icon="spinner" spin />
          <span>Memuat data lokasi...</span>
        </div>

        <div v-else-if="!daftarLokasi.length" class="list__state">
          <font-awesome-icon
            :icon="search ? 'magnifying-glass' : 'location-dot'"
            class="list__state-icon"
          />
          <p v-if="search">Tidak ada lokasi yang cocok dengan "{{ search }}".</p>
          <p v-else-if="ringkasan.totalLokasi === 0">Belum ada lokasi terdaftar.</p>
          <p v-else>Tidak ada lokasi pada filter ini.</p>
        </div>

        <!--
          Daftar, bukan tabel.

          Alasan berubah dari tabel: yang paling sering dipakai di sini adalah
          MEMBUKA SATU LOKASI, bukan membandingkan kolom. Tabel unggul kalau
          yang dibandingkan adalah kolom yang sama ke bawah -- tapi di sini
          setiap baris punya nominal, tanggal, dan status yang berbeda, dan
          yang dilakukan hampir selalu "lihat yang ini".

          Barisnya tetap bisa dibandingkan: nominal rata kanan dengan lebar
          kolom yang sama, jadi memindai ke bawah tetap mungkin. Yang hilang
          cuma kemampuan menyortir per kolom, dan itu dikembalikan lewat satu
          tombol pengurutan di kepala daftar.
        -->
        <ul v-else class="rows">
          <li v-for="row in daftarLokasi" :key="row.id" class="row" :class="`is-${row.tone}`">
            <button type="button" class="row__btn" @click="bukaDetail(row)">
              <span class="row__stripe" />

              <div class="row__main">
                <p class="row__name">
                  {{ row.usaha }}
                  <span v-if="row.pill" class="row__pill" :class="`is-${row.pill.tone}`">
                    {{ row.pill.label }}
                  </span>
                </p>
                <p class="row__meta">
                  <span v-if="row.sektor" class="row__sektor">{{ row.sektor }}</span>
                  <span v-if="row.slug" class="row__slug">{{ row.slug }}</span>
                  <span v-if="row.sisa" class="row__sisa" :class="`is-${row.expiryTone}`">
                    {{ row.sisa }}
                  </span>
                </p>
              </div>

              <div class="row__money">
                <p class="row__amount" :class="{ 'is-clear': !row.tagihan_belum_lunas }">
                  {{ row.tagihan_belum_lunas ? formatRupiah(row.total_tagihan) : 'Lunas' }}
                </p>
                <p class="row__amount-note">
                  {{ row.tagihan_belum_lunas }} tagihan
                  <span v-if="row.overdueCount" class="row__overdue">
                    · {{ row.overdueCount }} terlambat
                  </span>
                </p>
              </div>

              <span class="row__go"><font-awesome-icon icon="chevron-right" /></span>
            </button>
          </li>
        </ul>
      </article>

      <div class="bd__side">
        <!--
          ===== PERLU PERHATIAN =====

          Kartu "Status Tagihan" yang pernah di sini dihapus.

          Isinya sudah ada di tempat lain, persis sama angkanya: kartu hero
          menampilkan tunggakan dan lewat jatuh tempo, kartu alert menampilkan
          tagihan lewat tempo sebagai baris "Tangani", dan halaman Tagihan
          punya tabel lengkapnya. Yang tersisa dari kartu ini cuma pengelompokan
          aman / segera / terlambat / lunas -- dan dari empat itu, hanya
          "terlambat" yang punya tindakan. Dua baris sisanya dibaca sebagai
          data, bukan sebagai sesuatu yang perlu ditindaklanjuti.

          Yang tersisa juga membuat kolom kanan melebar: `bd__split` memberi
          sisi kanan `minmax(320px, 0.62fr)`, dan "Perlu Perhatian" yang
          sendirian tidak butuh 320px -- alert-nya berupa teks satu-dua baris
          dengan tombol kecil di kanan. Sebelum kartu ini dihapus, ketiga
          kartu (Kondisi / Status Tagihan / Perlu Perhatian) harus berbagi
          kolom 320px itu, dan teksnya jadi sempit sementara ruang kosongnya
          justru besar.

          Panel "Kondisi Lokasi" juga naik ke kolom ini (lihat `.bd__side`),
          jadi isi kolom kanan sekarang: kondisi dipie, lalu daftar alert.
        -->
        <article class="card card--alerts">
          <header class="card__head">
            <h3 class="card__title">Perlu Perhatian</h3>
            <span v-if="perhatian.length" class="card__count is-bad">{{ perhatian.length }}</span>
          </header>

          <div v-if="!siap" class="card__empty">
            <font-awesome-icon icon="spinner" spin />
            <span>Memuat...</span>
          </div>

          <div v-else-if="!perhatian.length" class="card__clear">
            <font-awesome-icon icon="circle-check" />
            <p>
              Semua lokasi beres. Tidak ada tunggakan, tidak ada langganan habis, tidak ada database
              hilang.
            </p>
          </div>

          <ul v-else class="alerts">
            <li v-for="item in perhatian" :key="item.key" class="alert" :class="`is-${item.tone}`">
              <span class="alert__icon"><font-awesome-icon :icon="item.icon" /></span>
              <div class="alert__body">
                <p class="alert__label">{{ item.label }}</p>
                <p class="alert__value">{{ item.value }}</p>
              </div>
              <router-link :to="item.to" class="alert__link">
                {{ item.link }}
                <font-awesome-icon icon="chevron-right" />
              </router-link>
            </li>
          </ul>
        </article>
      </div>
    </section>

    <!-- ===== POP-UP DETAIL LOKASI ===== -->
    <CentralLokasiPopup :rincian="popupRincian" :show="popupOpen" @close="popupOpen = false" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { showErrorToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import { centralService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'
import CentralLokasiPopup from './components/CentralLokasiPopup.vue'

const uiStore = useUiStore()

const data = ref(null)
const diperbaruiPada = ref('')
const errorMuncul = ref(false)

const search = ref('')
const filter = ref('semua')

/*
 * Pop-up detail.
 *
 * Sumbernya `rincianLokasi` dari respons dashboard -- sudah ada di memori,
 * jadi pop-up terbuka tanpa satu pun request tambahan. Yang tetap diambil
 * terpisah hanya daftar tagihannya (lihat `CentralLokasiPopup`).
 *
 * `null` berarti tertutup. Dipakai sebagai penanda yang sama supaya tidak
 * ada dua state yang bisa tidak sinkron (mis. `open` boolean + `id` terpisah).
 */
const popupRincian = ref(null)
const popupOpen = computed(() => popupRincian.value !== null)

const bukaDetail = (row) => {
  popupRincian.value = rincian.value[row.id] ?? null
}

/* ===== DATA ===== */

const ringkasan = computed(
  () =>
    data.value?.ringkasan ?? {
      totalLokasi: 0,
      lokasiAktif: 0,
      lokasiNonaktif: 0,
      nilaiTunggakan: 0,
      tagihanBelumLunas: 0,
      nilaiTerlambat: 0,
      tagihanTerlambat: 0,
      lokasiSehat: 0,
      lokasiMasalah: 0,
      nilaiTotalTagihan: 0,
      jumlahTagihanDiterbitkan: 0,
    },
)

/** Peta `tenant_id => rincian`, untuk pop-up. */
const rincian = computed(() => data.value?.rincianLokasi ?? {})

/**
 * Data sudah pernah termuat SEKALI, bukan "sedang tidak loading".
 *
 * Penting untuk polling: tiap 30 detik spinner naik lagi, dan kalau itu
 * yang menentukan kosong-tidaknya layar, seluruh halaman berkedip kosong
 * setiap 30 detik. Layar yang gelisah membuat orang berhenti mempercayainya.
 */
const siap = computed(() => data.value !== null && !errorMuncul.value)

const busy = computed(() => uiStore.loading)

const HARI_MS = 86400000

/*
 * "3 menit lalu" -- kapan layar ini terakhir menampilkan angka baru, bukan
 * kapan server terakhir mengubah datanya.
 *
 * Karena `Date.now()` bukan sumber reaktif, teksnya hanya dihitung ulang saat
 * `diperbaruiPada` berubah, yaitu tiap polling. Itu cukup: geraknya tidak
 * lebih cepat dari 30 detik, dan tidak ada yang salah dengan teks yang basi
 * setengah menit.
 */
const waktuRelatif = computed(() => {
  if (!diperbaruiPada.value) return 'beberapa detik lalu'

  const ms = Date.now() - new Date(diperbaruiPada.value).getTime()
  const menit = Math.floor(ms / 60000)

  if (menit < 1) return 'baru saja'
  if (menit === 1) return '1 menit lalu'
  if (menit < 60) return `${menit} menit lalu`

  const jam = Math.floor(menit / 60)
  if (jam === 1) return '1 jam lalu'
  if (jam < 24) return `${jam} jam lalu`

  return `${Math.floor(jam / 24)} hari lalu`
})

const stampTitle = computed(() =>
  diperbaruiPada.value
    ? `Terakhir dimuat ${new Date(diperbaruiPada.value).toLocaleString('id-ID')}`
    : 'Memuat data',
)

/* ===== WARNA =====
 *
 * Chart.js tidak dipakai di halaman ini lagi, jadi warna TIDAK perlu dibaca
 * dari `getComputedStyle` seperti di versi sebelumnya -- yang perlu canvas
 * saja yang harus menyalin nilai token ke dalam objek config. Warna di sini
 * hanya dipakai sebagai `background` pada elemen DOM, jadi token CSS bisa
 * dipakai langsung dan otomatis ikut berubah kalau palet aplikasi berubah.
 *
 * Yang dipakai di sini adalah varian `-fill`, bukan varian teks: nilai ini
 * jadi isian bar, titik, dan strip. Varian teks yang gelap itu untuk teks di
 * atas putih; memakainya sebagai isian membuat bar dan strip terlihat terlalu
 * berat dibanding card-nya.
 */

const c = (name, fallback) => `var(${name}, ${fallback})`

const WARNA = {
  ok: c('--bd-green-fill', '#10b981'),
  warn: c('--bd-amber-fill', '#f59e0b'),
  bad: c('--bd-red-fill', '#ef4444'),
  info: c('--bd-sky-fill', '#0ea5e9'),
  muted: c('--bd-slate', '#64748b'),
}

/*
 * Warna kategori tidak dipilih bebas: setiap warna punya ARTINYA.
 *
 * Urutan gradasi: hijau (sehat) -> biru (hampir habis) -> kuning (menunggak)
 * -> abu (nonaktif) -> merah tua (bermasalah). Yang merah tua dipakai untuk
 * "tidak bisa dipakai", bukan untuk "menunggak", supaya merah di layar ini
 * selalu berarti hal yang sama: lokasi ini tidak bisa dilayani.
 */
const KONDISI_WARNA = {
  healthy: () => WARNA.ok,
  expiring: () => WARNA.info,
  due: () => WARNA.warn,
  off: () => WARNA.muted,
  expired: () => WARNA.bad,
  broken: () => c('--bd-red-deep', '#7f1d1d'),
}

const TONE = {
  ok: () => 'is-ok',
  warn: () => 'is-warn',
  bad: () => 'is-bad',
  muted: () => 'is-muted',
  info: () => 'is-info',
}

/* ===== KONDISI LOKASI: PIE PENUH ===== */

/*
 * Daftar kondisi, siap dipakai `pie` dan legenda.
 *
 * Tidak ada lagi pembagian "terbesar = 100%" seperti di versi bar: pie memakai
 * proporsi terhadap JUMLAH, jadi angka yang ditampilkan adalah proporsi yang
 * sebenarnya. `persen` pun dihitung ulang di dalam `pie` dari total, bukan di
 * sini.
 */
const kondisiSeries = computed(() => {
  const rows = data.value?.kondisiLokasi ?? []

  return rows.map((r) => ({
    key: r.key,
    label: r.label,
    nilai: r.nilai,
    color: KONDISI_WARNA[r.key]?.() ?? WARNA.muted,
  }))
})

/*
 * PIE PENUH: lingkaran padat, tidak berlubang.
 *
 * Path irisan dibangun sebagai busur dari titik tengah, bukan dari dua titik
 * di tepi lingkaran -- jadi tidak ada `innerRadius` sama sekali. Donat
 * bawaan di sini selalu memakan radius yang justru dibutuhkan irisan kecil.
 *
 * Busur terakhir menutup lingkaran penuh: kalau irisan terakhir tidak
 * menggambar dari titik tengah kembali ke titik awal, sudut antara irisan
 * terakhir dan titik awal (12 o'clock) akan hilang dan lingkaran terlihat
 * seperti wedges yang tidak menutup.
 *
 * `size` 200 memberi diameter 200px di viewBox 200x200; `r` dikurangi 2px
 * supaya garis pemisah antar-irisan tidak ikut terpotong tepi.
 */
const PIE_SIZE = 200

const pieHover = ref(null)

const pie = computed(() => {
  const rows = kondisiSeries.value
  const total = rows.reduce((n, r) => n + r.nilai, 0)

  const size = PIE_SIZE
  const cx = size / 2
  const cy = size / 2
  const r = size / 2 - 2

  if (!total) {
    return {
      size,
      cx,
      cy,
      total: 0,
      slices: [],
      legend: [],
      byKey: {},
      ariaLabel: 'Belum ada lokasi terdaftar',
    }
  }

  const pointAt = (angle) =>
    `${(cx + r * Math.cos(angle)).toFixed(2)} ${(cy + r * Math.sin(angle)).toFixed(2)}`

  // Busur dari tengah -> tepi -> tepi berikutnya -> kembali ke tengah.
  const arc = (from, to) => {
    const large = to - from > Math.PI ? 1 : 0
    return `M ${cx} ${cy} L ${pointAt(from)} A ${r} ${r} 0 ${large} 1 ${pointAt(to)} Z`
  }

  const slices = []
  const byKey = {}

  let cursor = -Math.PI / 2 // mulai dari 12 o'clock, searah jarum jam

  /*
   * Kategori tunggal = 100% = satu lingkaran penuh.
   *
   * Path busur tidak bisa menggambar ini: dari titik tengah, tepi, kembali ke
   * titik tengah, tepi yang sama -- busur 360 derajat ulang ke titik yang
   * sama membuat SVG tidak menggambar apa-apa, dan hasilnya slice tak
   * terlihat. Jadi satu-satunya kategori digambar sebagai `<circle>`, bukan
   * busur. Ini bukan kasus tepi yang bisa diabaikan: "semua lokasi sehat"
   * justru kondisi yang paling sering muncul.
   */
  const tunggal = rows.length === 1

  for (const row of rows) {
    const sweep = (row.nilai / total) * Math.PI * 2
    const slice = {
      key: row.key,
      label: row.label,
      nilai: row.nilai,
      color: row.color,
      persen: Math.round((row.nilai / total) * 100),
      path: tunggal ? '' : arc(cursor, cursor + sweep),
      circle: tunggal ? r : null,
    }

    slices.push(slice)
    byKey[row.key] = slice
    cursor += sweep
  }

  return {
    size,
    cx,
    cy,
    total,
    slices,
    legend: slices,
    byKey,
    ariaLabel: slices.map((s) => `${s.label} ${s.nilai} lokasi`).join(', '),
  }
})

/* ===== KPI ===== */

/*
 * DUA KPI, bukan empat.
 *
 * Yang tersisa sengaja berpasangan: total lokasi dan lokasi aktif. Keduanya
 * satu keluarga (jumlah), jadi bisa dibandingkan langsung tanpa mengubah
 * satuan -- dan yang ditanyakan paling sering justru "berapa lokasi kita,
 * berapa yang bisa dipakai".
 *
 * Yang dipindah ke kartu hero, bukan dihapus: tunggakan dan lewat jatuh tempo.
 * Keduanya nominal, jadi satuan dan panjang angkanya berbeda dari dua angka
 * di sebelahnya -- dicampur dalam grid KPI yang sama membuat barisnya
 * terlihat setengah penuh padahal isinya penuh. Di hero mereka ditumpuk di
 * bawah total tagihan, yang justru gunanya: dibaca sebagai "dari total
 * segini, berapa yang belum masuk".
 *
 * "Lokasi Sehat" ikut hilang dari sini bukan karena tidak berguna -- nilainya
 * masih ada sebagai kartu "Perlu Perhatian" dan sebagai bar di Kondisi
 * Lokasi. Yang hilang hanya duplikatnya.
 */
const kpis = computed(() => {
  const r = ringkasan.value
  const nonaktif = r.lokasiNonaktif

  return [
    {
      key: 'lokasi',
      label: 'Total Lokasi',
      nilai: String(r.totalLokasi),
      note: nonaktif > 0 ? `${nonaktif} nonaktif` : 'Semua terdaftar aktif',
      icon: 'location-dot',
      tone: r.totalLokasi === 0 ? 'muted' : 'info',
    },
    {
      key: 'aktif',
      label: 'Lokasi Aktif',
      nilai: String(r.lokasiAktif),
      note: nonaktif > 0 ? `${nonaktif} tidak bisa diakses` : 'Semua bisa diakses',
      icon: 'circle-check',
      tone: r.lokasiAktif === 0 ? (r.totalLokasi > 0 ? 'bad' : 'muted') : 'ok',
    },
  ]
})

/* ===== PERLU PERHATIAN ===== */

/*
 * Hanya kondisi yang punya tindakan, dan setiap baris mengarah ke tempat
 * penyelesaiannya. Tidak ada tombol aksi di sini: dashboard untuk melihat,
 * dan provisioning lokasi bisa berjalan 30-120 detik -- salah klik berarti
 * satu lokasi terdaftar tapi belum jadi database.
 *
 * Angka agregat yang sudah tampil di kartu hero dan KPI sengaja TIDAK
 * diulang: nilainya sudah ada di atas, dan mengulangnya berarti ada dua
 * tempat yang harus selalu sinkron tanpa ada yang bertanggung jawab.
 */
const perhatian = computed(() => {
  const items = []
  const kondisi = data.value?.kondisiLokasi ?? []
  const cari = (key) => kondisi.find((k) => k.key === key)?.nilai ?? 0

  const broken = cari('broken')
  if (broken > 0) {
    items.push({
      key: 'db',
      tone: 'bad',
      icon: 'database',
      label: 'Database lokasi hilang',
      value: `${broken} lokasi terdaftar tapi database-nya tidak ada di server. Lokasi tidak bisa dipakai.`,
      to: '/central/lokasi',
      link: 'Perbaiki',
    })
  }

  const expired = cari('expired')
  if (expired > 0) {
    items.push({
      key: 'expired',
      tone: 'bad',
      icon: 'calendar-xmark',
      label: 'Langganan sudah habis',
      value: `${expired} lokasi melewati tanggal berakhirnya.`,
      to: '/central/lokasi',
      link: 'Perpanjang',
    })
  }

  if (ringkasan.value.tagihanTerlambat > 0) {
    items.push({
      key: 'overdue',
      tone: 'warn',
      icon: 'triangle-exclamation',
      label: 'Tagihan lewat jatuh tempo',
      value: `${ringkasan.value.tagihanTerlambat} tagihan, total ${formatRupiah(ringkasan.value.nilaiTerlambat)}.`,
      to: '/central/tagihan',
      link: 'Tangani',
    })
  }

  const off = cari('off')
  if (off > 0) {
    items.push({
      key: 'off',
      tone: 'muted',
      icon: 'ban',
      label: 'Lokasi nonaktif',
      value: `${off} lokasi tidak bisa diakses user lokasinya.`,
      to: '/central/lokasi',
      link: 'Lihat',
    })
  }

  const expiring = cari('expiring')
  if (expiring > 0) {
    items.push({
      key: 'expiring',
      tone: 'info',
      icon: 'calendar-days',
      label: 'Langganan hampir habis',
      value: `${expiring} lokasi berakhir dalam 30 hari ke depan.`,
      to: '/central/lokasi',
      link: 'Lihat',
    })
  }

  return items
})

/* ===== DAFTAR LOKASI ===== */

/*
 * Sisa hari dihitung dari tengah malam, bukan dari `Date.now()`.
 *
 * `tgl_berakhir` cuma tanggal, tanpa jam. Kalau selisihnya dihitung dari jam
 * 14:47, lokasi yang berakhirnya besok sore terbaca "0 hari lagi" -- dan
 * yang berakhir hari ini terbaca "-1", seolah sudah lewat padahal belum.
 * Menyamakan jam ke 00:00 lokal membuat hasilnya sama dengan yang orang
 * baca sendiri di kalender.
 */
const hariTersisa = (tgl) => {
  if (!tgl) return null

  const target = new Date(`${tgl}T00:00:00`)
  if (Number.isNaN(target.getTime())) return null

  const today = new Date()
  today.setHours(0, 0, 0, 0)

  return Math.round((target - today) / HARI_MS)
}

const deskripsiSisa = (sisaHari) => {
  if (sisaHari === null) return ''
  if (sisaHari < 0) return `lewat ${Math.abs(sisaHari)} hari`
  if (sisaHari === 0) return 'berakhir hari ini'
  return `${sisaHari} hari lagi`
}

/*
 * Badge status -- satu sumber kebenaran untuk daftar dan pop-up.
 *
 * Urutannya adalah URUTAN KEPUTUSAN: kondisi pertama yang cocok menang.
 * `database_ada` dicek paling dulu karena lokasi tanpa database tidak bisa
 * dipakai sama sekali, jadi status tagihannya belum relevan di depan
 * masalah itu. Tanpa urutan ini, satu lokasi bisa tampil "Sehat" di daftar
 * sambil database-nya hilang -- dan orang akanRlalu saja.
 */
const badgeStatus = (row) => {
  if (!row.database_ada) return { label: 'Database Hilang', tone: 'bad' }
  if (row.sisaHari !== null && row.sisaHari < 0) return { label: 'Kedaluwarsa', tone: 'bad' }
  if (!row.is_active) return { label: 'Nonaktif', tone: 'muted' }
  if (row.overdueCount > 0) return { label: 'Menunggak', tone: 'warn' }
  if (row.tagihan_belum_lunas > 0) return { label: 'Belum Lunas', tone: 'warn' }
  if (row.sisaHari !== null && row.sisaHari <= 30) return { label: 'Segera Habis', tone: 'info' }
  return { label: 'Sehat', tone: 'ok' }
}

/*
 * Jumlah tagihan terlambat per lokasi, PERSIS.
 *
 * Diberikan backend sebagai peta `tenant_id => jumlah`, dihitung dari seluruh
 * tagihan UNPAID -- bukan dari sampel. Ini penting karena angkanya tampil
 * apa adanya di daftar: menghitungnya dari sampel tagihan "yang paling
 * mendesak" akan menampilkan "3 terlambat" untuk lokasi yang sebenarnya punya
 * 9, dan tidak ada yang bisa mencocokkan angka itu dari layar.
 *
 * Kunci JSON selalu string, sedangkan `row.id` angka; pencarian di objek JS
 * otomatis menyamakan keduanya, jadi tidak perlu `Number()` di sini.
 */
const terlambatPerLokasi = computed(() => data.value?.overduePerLokasi ?? {})

const lokasiRows = computed(() =>
  Object.values(rincian.value).map((row) => {
    const sisaHari = hariTersisa(row.tgl_berakhir)
    const overdueCount = Number(terlambatPerLokasi.value[row.id] ?? 0)
    const enriched = { ...row, sisaHari }

    /*
     * Bobot severity, bukan urutan_if-else, supaya bisa diuji sendiri.
     *
     * Dipilih supaya satu masalah berat tidak tenggelam oleh banyak masalah
     * kecil, dan `>` (bukan `>=`) supaya ketika bebannya sama, masalah yang
     * lebih akut -- yang ditulis lebih dulu -- yang menang.
     */
    let severity = null
    let rank = 0
    const bump = (name, weight) => {
      if (weight > rank) {
        rank = weight
        severity = name
      }
    }

    if (!row.database_ada) bump('broken', 100)
    if (sisaHari !== null && sisaHari < 0) bump('expired', 90)
    if (!row.is_active) bump('off', 70)
    if (overdueCount > 0) bump('overdue', 60)
    if (row.tagihan_belum_lunas > 0) bump('due', 40)
    if (sisaHari !== null && sisaHari <= 30) bump('expiring', 20)

    return {
      ...enriched,
      overdueCount,
      sisa: deskripsiSisa(sisaHari),
      pill: badgeStatus(enriched, overdueCount),
      // Warna strip di sisi kiri baris, mengikuti masalah yang menang.
      tone: TONE[
        {
          broken: 'bad',
          expired: 'bad',
          off: 'muted',
          overdue: 'warn',
          due: 'warn',
          expiring: 'info',
        }[severity] ?? 'ok'
      ](),
      expiryTone:
        sisaHari === null ? '' : sisaHari < 0 ? 'is-bad' : sisaHari <= 30 ? 'is-warn' : '',
      rank,
    }
  }),
)

/* ===== FILTER & PENCARIAN ===== */

const filterChip = computed(() => {
  const rows = lokasiRows.value
  const hitung = (fn) => rows.filter(fn).length

  return [
    { key: 'semua', label: 'Semua', count: rows.length },
    {
      key: 'masalah',
      label: 'Perlu Tindakan',
      count: hitung((r) => r.pill.tone === 'bad' || r.pill.tone === 'warn'),
    },
    {
      key: 'tunggakan',
      label: 'Ada Tunggakan',
      count: hitung((r) => r.tagihan_belum_lunas > 0),
    },
    { key: 'sehat', label: 'Sehat', count: hitung((r) => r.pill.tone === 'ok') },
  ]
})

/* ===== URUTAN ===== */

/*
 * Default: URGENSY -- lokasi yang paling perlu ditangani paling atas.
 *
 * Dashboard ini dipakai untuk memutuskan "apa yang saya kerjakan sekarang".
 * Urutan id bawaan mengembalikan lokasi terdaftar paling lama, yaitu yang
 * paling lama -- bukan yang paling mendesak.
 */
const URTAN = {
  urgensi: { label: 'paling mendesak', cmp: (a, b) => b.rank - a.rank },
  nominal: {
    label: 'tagihan tertinggi',
    cmp: (a, b) => Number(b.total_tagihan || 0) - Number(a.total_tagihan || 0),
  },
  nama: { label: 'nama (A-Z)', cmp: (a, b) => a.usaha.localeCompare(b.usaha, 'id') },
  berakhir: {
    label: 'berakhir paling dekat',
    cmp: (a, b) =>
      (a.sisaHari ?? Number.MAX_SAFE_INTEGER) - (b.sisaHari ?? Number.MAX_SAFE_INTEGER),
  },
}

const urut = ref('urgensi')

const daftarLokasi = computed(() => {
  const keyword = search.value.trim().toLowerCase()

  let list = lokasiRows.value

  if (filter.value === 'masalah') {
    list = list.filter((r) => r.pill.tone === 'bad' || r.pill.tone === 'warn')
  } else if (filter.value === 'tunggakan') {
    list = list.filter((r) => r.tagihan_belum_lunas > 0)
  } else if (filter.value === 'sehat') {
    list = list.filter((r) => r.pill.tone === 'ok')
  }

  if (keyword) {
    list = list.filter((r) =>
      [r.usaha, r.label, r.nama, r.slug, r.domain, r.sektor, r.alamat].some((v) =>
        String(v ?? '')
          .toLowerCase()
          .includes(keyword),
      ),
    )
  }

  /*
   * Pelumas yang sama untuk semua urutan: nama usaha.
   *
   * Tanpa itu, daftar melompat setiap polling 30 detik hanya karena urutan
   * database berubah, dan orang yang sedang membaca kehilangan tempatnya.
   */
  return [...list].sort(
    (a, b) => URTAN[urut.value].cmp(a, b) || a.usaha.localeCompare(b.usaha, 'id'),
  )
})

/* ===== PEMUATAN DATA ===== */

/*
 * Satu request, bukan dua.
 *
 * Versi lama memanggil `getDashboard()` DAN `getAll()` bersamaan, padahal
 * `/central/locations` mengembalikan baris yang sama dengan `rincianLokasi`
 * plus hitungan tagihan -- jadi daftar lokasi, pop-up, dan grafik sekarang
 * semuanya berasal dari satu sumber. Dua sumber untuk hal yang sama adalah
 * dua tempat yang bisa berbeda: polling 30 detik cukup sering untuk menangkap
 * halaman yang menunjukkan total tagihan lama di sebelah kanan lokasi yang
 * barunya sudah masuk.
 */
const loadData = async (options = {}) => {
  const silent = options.silent === true

  try {
    const res = await centralService.getDashboard({ silent })

    data.value = res.data?.data ?? null
    // Timestamp dari klien, bukan `diperbaruiPada` dari server. Yang diukur
    // adalah "kapan layar ini terakhir sebenarnya diperbarui", dan itu
    // terjadi tepat setelah response tiba.
    diperbaruiPada.value = new Date().toISOString()
    errorMuncul.value = false
  } catch (error) {
    // Poll latar gagal harus diam; lihat catatan di `useAutoRefresh`.
    if (silent) return

    // Data yang sudah pernah termuat TIDAK dikosongkan. Menampilkan error di
    // atas angka yang masih relevan lebih berguna daripada mengosongkan
    // seluruh layar. `diperbaruiPada` sengaja tidak disentuh supaya teks
    // "diperbarui 4 menit lalu" terus memberi tahu bahwa angka di layar ini
    // bukan yang terbaru.
    errorMuncul.value = data.value === null
    if (errorMuncul.value) showErrorToast(error)
  }
}

onMounted(() => loadData())

/*
 * Auto-refresh 30 detik.
 *
 * `silent: true` wajib -- tanpa itu `uiStore.loading` naik tiap 30 detik dan
 * seluruh chrome ikut berkedip, termasuk ikon "rotate" di kartu hero.
 */
useAutoRefresh(() => loadData({ silent: true }), { interval: 30000 })

/*
 * Escape menutup pop-up, dan scroll body dikembalikan saat halaman dilepas
 * -- kalau tidak, `overflow: hidden` yang dipasang `CentralLokasiPopup`
 * ikut tertinggal dan seluruh aplikasi terasa beku setelah meninggalkan
 * halaman ini.
 */
const onKeydown = (e) => {
  if (e.key === 'Escape') popupRincian.value = null
}

window.addEventListener('keydown', onKeydown)
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<style scoped>
/*
 | TOKEN
 |
 | Prefix `bd-` (bento dashboard) dipakai supaya tidak bentrok dengan token
 | aplikasi yang sudah ada -- `--color-primary` milik seluruh aplikasi, dan
 | menimpanya di sini akan mengubah warna tombol di halaman tenant yang sedang
 | terbuka di tab lain.
 |
 | Di tema terang, elevasi dibaca dari bayangan: kartu putih di atas latar abu
 | muda, tepat seperti `--card-shadow-base` di main.css. Karena itu warna
 | diambil dari token yang sama dengan halaman panel pusat lain -- ganti palet
 | aplikasi, seluruh panel ikut berubah.
 */
.bd {
  /*
   | Peta lokal ke token bersama `--ct-*`.
   |
   | Nilai warna TIDAK ditulis di sini lagi. Semuanya berasal dari
   | `assets/css/central-tokens.css`, yang dipakai juga oleh kedua pop-up --
   | lihat catatan panjang di file itu. Yang ditulis di bawah hanya PENAMAAN
   | LOKAL, supaya `var(--bd-red)` di file ini tetap terbaca jelas dan tidak
   | jadi ratusan pemanggilan yang harus diubah manual kalau nama tokennya
   | diganti someday.
   |
   | Token yang dipetakan tapi tidak kelihatan dipakai di halaman ini
   | sengaja TIDAK dihapus: `.bd` diwarisi semua elemen turunannya, dan
   | menyingkirkan yang "tidak kelihatan dipakai" hanya menambah pekerjaan
   | untuk pembaca nanti.
   |
   | Dua nilai tetap ditulis langsung, karena memang milik halaman ini dan
   | tidak dipakai pop-up mana pun: latar abu (`--bd-bg`) dan latar kartu
   | (`--bd-surface`) -- pop-up tidak punya latar halaman, hanya permukaan.
   */
  --bd-bg: var(--color-body-bg, #f1f5f9);
  --bd-bg-2: #eef4f8;
  --bd-surface: var(--color-card-bg, #ffffff);
  --bd-surface-2: var(--ct-surface-2);
  --bd-line: var(--ct-line);
  --bd-line-strong: var(--ct-line-strong);

  --bd-text: var(--ct-text);
  --bd-text-dim: var(--ct-text-dim);
  --bd-text-faint: var(--ct-text-faint);

  /*
   | Aksen.
   |
   | Warna di sini dipakai dua tempat: sebagai WARNA TEKS (kicker, tautan, chip)
   | dan sebagai ISIAN (strip, bar, titik). Warna terang masih enak sebagai
   | isian di atas gelap, tapi sebagai teks di atas putih kontrasnya jalah.
   | Jadi palet gelap dipakai untuk yang jadi teks, dan padanan yang lebih
   | terang untuk yang tetap jadi isian -- supaya "merah" di layar ini selalu
   | merah yang sama, hanya berbeda kadar.
   */
  --bd-sky: var(--ct-sky);
  --bd-sky-fill: var(--ct-sky-fill);
  --bd-green: var(--ct-green);
  --bd-green-fill: var(--ct-green-fill);
  --bd-amber: var(--ct-amber);
  --bd-amber-fill: var(--ct-amber-fill);
  --bd-red: var(--ct-red);
  --bd-red-fill: var(--ct-red-fill);
  --bd-red-deep: var(--ct-red-deep);
  --bd-slate: var(--ct-slate);
  --bd-violet: var(--ct-violet);
  --bd-violet-fill: var(--ct-violet-fill);

  /* Isian transparan (chip, pill, bar track): warna yang sama, diencerkan. */
  --bd-sky-tint: var(--ct-sky-tint);
  --bd-green-tint: var(--ct-green-tint);
  --bd-amber-tint: var(--ct-amber-tint);
  --bd-red-tint: var(--ct-red-tint);
  --bd-slate-tint: var(--ct-slate-tint);
  --bd-violet-tint: var(--ct-violet-tint);

  --bd-radius: var(--ct-radius);
  --bd-radius-sm: var(--ct-radius-sm);
  --bd-gap: var(--ct-gap);

  /*
   | Grid dua baris yang JUMLAH TINGGINYA = tinggi yang tersisa di layar.
   |
   | Dulu `height: 100dvh`. Itu menghitung tinggi viewport dari nol, padahal
   | grid ini berada DI DALAM `.central-body` yang sudah dipotong oleh topnav
   | (56px) dan padding halaman. Hasilnya halaman selalu lebih tinggi dari
   | layar -- tidak terlihat sebagai error, tapi baris paling bawah dashboard
   | (daftar tenant) terpotong dan halaman ikut bisa digulir, padahal
   | maksud halaman ini justru tidak boleh punya scroll.
   |
   | `--central-topnav-height` dan `--central-gutter` diambil dari
   | CentralLayout.vue supaya pemotongannya ikut berubah kalau salah satunya
   | diubah. `dvh`, bukan `vh`: di ponsel `100vh` menghitung address bar yang
   | sedang muncul, jadi isinya jadi lebih tinggi dari layar yang terlihat.
   |
   | `minmax(0, ...)` di kedua kolom WAJIB. Tanpa itu, grid track ikut
   | memuai selebar isi (min-content), dan satu nominal rupiah panjang atau
   | satu nama lokasi panjang akan mendorong kolom lain sampai halaman ikut
   | bergeser. `0` bilang: boleh turun ke nol, jangan memaksa lebar.
   |
   | Latar (gradient) dipindah ke `.central-body` di CentralLayout.vue, bukan
   | di sini. Grid ini sekarang dibatasi max-width yang sama dengan halaman
   | lain, jadi kalau latar tetap menempel padanya, di monitor lebar akan
   | terlihat garis sambung antara tepi gradien dan abu muda di sekitarnya.
   */
  position: relative;
  display: grid;
  grid-template-rows: minmax(0, 1fr) minmax(0, 1.05fr);
  gap: var(--bd-gap);
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
  height: calc(100dvh - var(--central-topnav-height, 56px) - (2 * var(--central-gutter, 1.25rem)));
  max-height: calc(
    100dvh - var(--central-topnav-height, 56px) - (2 * var(--central-gutter, 1.25rem))
  );
  overflow: hidden;
  padding: 0;
  color: var(--bd-text);
  font-variant-numeric: tabular-nums;
}

/* ===== BARIS 1: HERO + KPI + KONDISI ===== */

/*
 * Tiga kolom, bukan grid otomatis.
 *
 * Kolom hero diberi `1.45fr` karena isinya angka rupiah sepanjang 13 digit --
 * kolom yang terlalu sempit membuatnya turun dua baris dan terlihat seperti
 * kartu yang gagal memuat. KPI dapat `1fr` (angka pendek, muatcomfortable),
 * kondisi dapat `1fr` (bar, labelnya yang pendek).
 */
.bd__hero {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr) minmax(0, 1fr);
  gap: var(--bd-gap);
  min-height: 0;
}

/*
 * KPI: satu kolom, dua kartu.
 *
 * Bukan 2x2 seperti sebelumnya. Dua kartu dalam satu kolom tetap mendapat
 * tinggi masing-masing setengah dari kolom, jadi ukurannya sama dengan
 * kartu hero di sebelahnya -- sementara hero sekarang membawa tiga angka.
 */
.bd__kpis {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  grid-template-rows: repeat(2, minmax(0, 1fr));
  gap: var(--bd-gap);
  min-height: 0;
}

/* ===== BARIS 2: DAFTAR + SISI ===== */

/*
| Baris 2: daftar lokasi di kiri, "Perlu Perhatian" di kanan.
|
| Kolom kanan menyempit dari `0.62fr` jadi `0.55fr`. Setelah kartu "Status
| Tagihan" dihapus, isi kolom ini tinggal satu kartu: daftar alert berupa teks
| satu-dua baris. Di 0.62fr teksnya ikut membungkus lebih awal sementara ruang
| kosong di sebelahnya justru bertambah -- dan alert yang paling penting
| (tagihan lewat tempo, database hilang) justru yang paling sering jadi dua
| baris.
|
| `minmax(300px, ...)` bukan 320px: 300px sudah cukup untuk ikon + label +
| nilai tanpa memaksa baris ketiga, dan 20px yang dibebaskan kembali ke
| daftar lokasi yang memang lebih butuh lebar (nama lokasi + nominal).
*/
.bd__split {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(300px, 0.55fr);
  gap: var(--bd-gap);
  min-height: 0;
}

/* Satu kartu saja, jadi tanpa grid baris -- cukup jadi kolom flex biasa
   supaya kartu ini mengisi seluruh tinggi yang tersisa. */
.bd__side {
  display: grid;
  grid-template-rows: minmax(0, 1fr);
  gap: var(--bd-gap);
  min-height: 0;
}

/* ===== CARD: DASAR ===== */

.card {
  position: relative;
  display: flex;
  flex-direction: column;
  min-height: 0;
  min-width: 0;
  padding: 0.85rem 0.95rem;
  border: 0;
  border-radius: var(--bd-radius);
  background: var(--bd-surface);
  /* Bayangan aktif, bukan `none`.
   *
   | Dulu kartu dashboard sengaja tanpa bayangan, dengan alasan "bayangan
   | membuat kartu putih terbaca sebagai kotak di dalam kotak". Alasan itu
   | benar untuk satu kartu sendirian, tapi tidak berlaku kalau enam kartu
   | berdiri berdampingan: tanpa bayangan, batas antar kartu ditentukan
   | sepenuhnya oleh `gap` abu muda, dan yang terlihat hanyalah lajur abu
   | di antaranya -- bukan kartu.
   |
   | Jadi masalahnya bukan "bayangan berlebihan", tapi "kartu ini punya,
   | kartu sebelahnya tidak". Daftar tagihan, daftar tenant, user pusat, dan
   | form tenant semuanya punya bayangan dengan warna dan radius berbeda
   | satu sama lain, dan yang paling tipis (1px, opacity .08) praktis tidak
   | terlihat. Akar masalahnya bukan keputusan desain mana pun yang benar,
   | tapi tidak adanya satu nilai yang dipakai bersama.
   *
   | Sekarang semuanya ambil `--central-shadow-card` dari CentralLayout.vue,
   | jadi elevasi di panel ini sama di setiap halaman.
   */
  box-shadow: var(--central-shadow-card);
  overflow: hidden;
}

.card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  flex-shrink: 0;
  margin-bottom: 0.7rem;
}

.card__title {
  margin: 0;
  font-size: 0.74rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--bd-text-dim);
  white-space: nowrap;
}

.card__sub {
  margin: 0.2rem 0 0;
  font-size: 0.66rem;
  color: var(--bd-text-faint);
}

.card__kicker {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--bd-sky);
}

/*
 * "baru saja" (waktu polling terakhir).
 *
 * Bentuk pil, bukan teks telanjang. Sebagai teks biasa di tepi kanan header
 * yang lebarnya ratusan piksel, "baru saja" terlihat melayang: tidak ada
 * bentuk yang mengikat teks sekecil itu ke tempatnya. Pil abu muda
 * memberinya bentuk dan jarak, jadi terbaca sebagai cap waktu, bukan
 * seperti sisa teks yang kebetulan ada di sana.
 */
.card__stamp {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  background: var(--bd-surface-2);
  font-size: 0.62rem;
  font-weight: 600;
  color: var(--bd-text-faint);
  white-space: nowrap;
}

/* Saat polling berjalan, angka yang ditampilkan sudah data lama tapi
   kesannya masih "baru saja". Warna biru membuat stamp ini terbaca sebagai
   status yang sedang bekerja, bukan teks mati. */
.card__stamp.is-busy {
  color: var(--bd-sky);
}

.card__count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.5rem;
  height: 1.5rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  background: var(--bd-slate-tint);
  color: var(--bd-text-dim);
  font-size: 0.68rem;
  font-weight: 800;
}

.card__count.is-bad {
  background: var(--bd-red-tint);
  color: var(--bd-red);
}

.card__link {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  font-size: 0.66rem;
  font-weight: 700;
  color: var(--bd-text-dim);
  text-decoration: none;
  white-space: nowrap;
}

.card__link:hover {
  color: var(--bd-sky);
}

.card__empty {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--bd-text-faint);
  text-align: center;
}

/* ===== CARD: HERO ===== */

.card--hero {
  justify-content: flex-start;
  padding: 1rem 1.15rem;
  /* Hero tetap paling menonjol, tapi lewat warna: biru muda sangat tipis di
     atas putih. Perbedaan kadar, bukan perbedaan terang-gelap. */
  background:
    radial-gradient(720px 260px at 8% 0%, var(--bd-sky-tint), transparent 68%), var(--bd-surface);
}

/*
 * Jarak header ke angka hero, dikendalikan dari satu tempat.
 *
 * `.card__head` punya `margin-bottom: 0.7rem` sendiri untuk semua kartu, dan
 * kedua margin itu tidak saling runtuh di flex column -- jadi kalau
 * `.hero__value` ikut memberi margin, jaraknya dijumlahkan tanpa disadar.
 * Di sini `margin-bottom` header dinolkan, jadi 0.5rem di bawah benar-benar
 * berarti 0.5rem.
 */
.card--hero .card__head {
  margin-bottom: 0;
}

/*
 * Angka hero.
 *
 * `clamp` dengan `vw` supaya mengikuti lebar kartu, bukan ukuran tetap.
 * Tanpa itu, nominal 13 digit ("Rp 1.234.567.890") tidak muat di kartu yang
 * menyempit dan ikut memaksa grid track melebar.
 *
 * `overflow-wrap: anywhere` sebagai pengaman terakhir: kalau suatu saat
 * nominalnya benar-benar lebih panjang dari yang di-clamp, angka boleh pecah
 * antar baris alih-alih mendorong kolom lain keluar dari grid.
 */
.hero__value {
  margin: 0.5rem 0 0;
  font-size: clamp(1.6rem, 3.4vw, 2.65rem);
  font-weight: 800;
  letter-spacing: -0.045em;
  line-height: 1.05;
  color: var(--bd-text);
  overflow-wrap: anywhere;
}

.hero__note {
  margin: 0.45rem 0 0;
  font-size: 0.7rem;
  line-height: 1.45;
  color: var(--bd-text-dim);
}

/*
 * Tunggakan dan lewat jatuh tempo, DUA KOLOM di bawah angka hero.
 *
 * Kenapa kolom, bukan tumpukan: kartu hero punya tinggi tetap dari grid
 * (`minmax(0, 1fr)`) dan `overflow: hidden`. Tinggi kartu tidak pernah ikut
 * bertambah saat isinya bertambah -- yang terjadi isi dipotong. Dua sel
 * ditumpuk = 6 baris, dan baris paling bawah terpotong begitu saja.
 *
 * Kenapa pemisah vertikal, bukan horizontal: dua angka ini dibaca
 * berdampingan sebagai satu blok kecil, dan batas kiri tipis memisahkan
 * keduanya tanpa menambah tinggi. Garis horizontal memakan satu
 * baris lagi -- persis baris yang tidak pernah muat.
 *
 * `margin-top: auto` tetap dipakai supaya blok ini menempel di dasar kartu.
 * Tanpa itu, isinya menempel ke angka total di atas dan kartu hero kehilangan
 * "bawahnya" -- ruang kosong akan jatuh di antara angka total dan catatan,
 * bukan di bagian yang paling jarang dilihat (tepi bawah kartu).
 *
 * Garis atas di blok ini membuat ruang kosong di tengah terbaca sebagai
 * pemisah dua bagian, bukan sebagai celah yang tidak terisi. Tanpa garis itu,
 * jarak antara catatan dan "Tunggakan" terlihat seperti kesalahan jarak --
 * dan itulah yang bikin kartu ini terasa tidak rapi.
 */
.hero__stack {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0;
  margin-top: auto;
  padding-top: 0.75rem;
  border-top: 1px solid var(--bd-line);
}

/* Pemisah hanya di antara keduanya, bukan di tepi kiri sel pertama --
   kalau ada, tepi kartu jadi bersilangan dengan garis dan kartu terlihat
   seperti tabel yang salah tempat. */
.hero__cell + .hero__cell {
  padding-left: 0.85rem;
  border-left: 1px solid var(--bd-line);
}

.hero__cell-label {
  margin: 0;
  font-size: 0.62rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--bd-text-faint);
}

.hero__cell-value {
  /* Label 0.62rem (kecil, uppercase) ke angka 1rem: 0.25rem (4px) cukup
     untuk membelah keduanya. Yang tadinya 0.2rem hampir tidak terlihat, dan
     yang terbaca "TUNGGAKAN Rp 7.575.000" tanpa jeda -- bukan label lalu
     angka, tapi satu baris panjang.

     1rem, bukan 0.95rem yang lama: nilai ini sekarang berdiri sendiri di
     kolomnya sendiri, tidak lagi berbagi baris dengan angka total di atasnya,
     dan tidak lagi harus seramping itu demi muat. 0.95rem sisa dari
     struktur tumpukan, bukan pilihan yang sadar. */
  margin: 0.25rem 0 0;
  font-size: 1rem;
  font-weight: 800;
  letter-spacing: -0.015em;
  color: var(--bd-text);
  overflow-wrap: anywhere;
}

.hero__cell-value.is-bad {
  color: var(--bd-red);
}

.hero__cell-note {
  margin: 0.2rem 0 0;
  font-size: 0.62rem;
  color: var(--bd-text-faint);
}

/* ===== CARD: KPI ===== */

.card--kpi {
  flex-direction: row;
  align-items: center;
  gap: 0.6rem;
  padding: 0.65rem 0.75rem;
}

.kpi__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  border-radius: var(--bd-radius-sm);
  font-size: 0.82rem;
  background: var(--bd-slate-tint);
  color: var(--bd-text-dim);
}

.card--kpi.is-ok .kpi__icon {
  background: var(--bd-green-tint);
  color: var(--bd-green);
}
.card--kpi.is-info .kpi__icon {
  background: var(--bd-sky-tint);
  color: var(--bd-sky);
}
.card--kpi.is-warn .kpi__icon {
  background: var(--bd-amber-tint);
  color: var(--bd-amber);
}
.card--kpi.is-bad .kpi__icon {
  background: var(--bd-red-tint);
  color: var(--bd-red);
}

.kpi__text {
  min-width: 0;
}

.kpi__label {
  margin: 0;
  font-size: 0.6rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--bd-text-faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kpi__value {
  /* Jarak ke label, bukan 0.1rem. Di kartu setinggi sekitar 90px, 0.1rem cuma
     1.6px dan praktis tidak terlihat -- label dan angka menempel jadi satu blok,
     dan yang terbaca bukan "angka di bawah label" tapi dua baris rapat tanpa
     hubungan. 0.25rem (4px) cukup untuk memisahkan tanpa membuat kartu terasa
     longgar, karena jarak ke catatan di bawahnya juga ikut naik. */
  margin: 0.25rem 0 0;
  font-size: 1.05rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.2;
  color: var(--bd-text);
  overflow-wrap: anywhere;
}

.kpi__note {
  /* Sama seperti di atas: 0.05rem (0.8px) tidak membelah baris, hanya
     menempelkan. */
  margin: 0.2rem 0 0;
  font-size: 0.62rem;
  color: var(--bd-text-faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ===== CARD: KONDISI (PIE PENUH) ===== */

.card--kondisi {
  min-height: 0;
}

/*
 * SVG di tengah-tengah, dengan tooltip diposisikan relatif terhadapnya.
 *
 * `position: relative` dipakai supaya tooltip bisa diposisikan relatif
 * terhadap lingkaran, bukan halaman.
 */
.pie-wrap {
  position: relative;
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  min-height: 0;
  padding: 0.1rem 0;
}

.pie {
  width: 100%;
  max-width: 11.5rem;
  height: auto;
  overflow: visible;
}

/*
 * `stroke` putih sebagai pemisah, bukan outline hitam.
 *
 * Pemisah putih membuat irisan yang dipisah terlihat sebagai potongan
 * yang bersih di atas kartu putih, dan tidak menambah kontras yang tidak
 * perlu. Lebarnya 2px cukup untuk membedakan kategori berwarna mirip tanpa
 * memakan busur irisan kecil.
 */
.pie__slice {
  stroke: var(--bd-surface);
  stroke-width: 2;
  cursor: pointer;
  transition: opacity 0.16s ease;
}

.pie__slice.is-dim {
  opacity: 0.35;
}

.pie-tip {
  position: absolute;
  top: 0.4rem;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: baseline;
  gap: 0.3rem;
  padding: 0;
  border: 0;
  background: none;
  white-space: nowrap;
  pointer-events: none;
  z-index: 2;
}

.pie-tip__dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  flex-shrink: 0;
}

.pie-tip__label {
  font-size: 0.62rem;
  font-weight: 700;
  color: var(--bd-text-dim);
}

.pie-tip__value {
  font-size: 0.74rem;
  font-weight: 800;
  color: var(--bd-text);
}

.pie-tip__pct {
  font-size: 0.6rem;
  font-weight: 700;
  color: var(--bd-text-faint);
}

/*
 * Legenda: dua kolom, nilai sebelum label.
 *
 * Nilai DIDULU karena di sinilah orang mencari angka -- kalau label dulu,
 * mata harus melewati teks untuk sampai ke angka. Dua kolom supaya enam
 * kategori tidak jadi satu baris panjang yang keluar dari kartu.
 */
.pie-legend {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.2rem 0.6rem;
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
}

.pie-legend__item {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  min-width: 0;
  padding: 0.1rem 0.2rem;
  border-radius: 5px;
  cursor: default;
  transition: background 0.16s ease;
}

.pie-legend__item.is-on {
  background: var(--bd-surface-2);
}

.pie-legend__dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 2px;
  flex-shrink: 0;
}

.pie-legend__value {
  font-size: 0.72rem;
  font-weight: 800;
  color: var(--bd-text);
  flex-shrink: 0;
}

.pie-legend__label {
  font-size: 0.62rem;
  color: var(--bd-text-dim);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== CARD: DAFTAR LOKASI ===== */

.card--list {
  padding: 0;
}

.card__head--tools {
  margin: 0;
  padding: 0.8rem 0.9rem 0.7rem;
  align-items: flex-start;
}

.list__tools {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.list__search {
  position: relative;
  display: flex;
  align-items: center;
  min-width: 12rem;
}

.list__search svg {
  position: absolute;
  left: 0.55rem;
  color: var(--bd-text-faint);
  font-size: 0.68rem;
  pointer-events: none;
}

.list__search input {
  width: 100%;
  padding: 0.36rem 0.6rem 0.36rem 1.75rem;
  border: 1px solid var(--bd-line);
  border-radius: var(--bd-radius-sm);
  background: var(--bd-surface);
  color: var(--bd-text);
  font-size: 0.72rem;
  transition: all 0.16s ease;
}

.list__search input::placeholder {
  color: var(--bd-text-faint);
}

.list__search input:focus {
  outline: none;
  border-color: var(--bd-sky);
  background: var(--bd-surface);
  box-shadow: 0 0 0 3px var(--bd-sky-tint);
}

.list__filters {
  display: flex;
  gap: 0.3rem;
  flex-wrap: wrap;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.3rem 0.55rem;
  border: 1px solid var(--bd-line);
  border-radius: 999px;
  background: transparent;
  color: var(--bd-text-dim);
  font-size: 0.66rem;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.16s ease;
}

.chip:hover {
  border-color: var(--bd-line-strong);
  color: var(--bd-text);
}

.chip.is-on {
  background: var(--bd-sky-tint);
  border-color: var(--bd-sky);
  color: var(--bd-sky);
}

.chip__count {
  padding: 0 0.28rem;
  border-radius: 999px;
  background: var(--bd-slate-tint);
  font-size: 0.6rem;
  font-weight: 800;
}

.chip.is-on .chip__count {
  background: var(--bd-sky);
}

/* Kepala daftar: pengurutan + CTA */
.list__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  flex-shrink: 0;
  padding: 0.5rem 0.9rem;
  border-top: 1px solid var(--bd-line);
  border-bottom: 1px solid var(--bd-line);
  background: var(--bd-surface-2);
}

.list__sort {
  padding: 0.3rem 0.6rem;
  border: 1px dashed var(--bd-line-strong);
  border-radius: var(--bd-radius-sm);
  background: transparent;
  color: var(--bd-text-dim);
  font-size: 0.66rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.16s ease;
}

.list__sort:hover,
.list__sort.is-on {
  border-style: solid;
  border-color: var(--bd-sky);
  color: var(--bd-sky);
}

.list__cta {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.34rem 0.65rem;
  border-radius: var(--bd-radius-sm);
  background: linear-gradient(135deg, #0ea5e9, #38bdf8);
  color: #fff;
  font-size: 0.68rem;
  font-weight: 800;
  text-decoration: none;
  white-space: nowrap;
  box-shadow: 0 8px 18px -10px rgb(56 189 248 / 0.8);
  transition:
    filter 0.16s ease,
    transform 0.16s ease;
}

.list__cta:hover {
  filter: brightness(1.08);
  transform: translateY(-1px);
}

.list__state {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 2rem 1rem;
  color: var(--bd-text-faint);
  font-size: 0.75rem;
  font-weight: 600;
  text-align: center;
}

.list__state-icon {
  font-size: 1.6rem;
  color: var(--bd-line-strong);
}

.list__state p {
  margin: 0;
}

/* ===== DAFTAR: BARIS ===== */

/*
 * Scroll di DALAM daftar, bukan di halaman.
 *
 * Ini satu-satunya bagian halaman ini yang isinya bisa banyak tanpa batas --
 * jumlah lokasi tidak pernah dibatasi. Daftar yang bisa tumbuh harus punya
 * tempat tumbuhnya sendiri, supaya halaman tetap diam.
 */
.rows {
  flex: 1;
  list-style: none;
  margin: 0;
  padding: 0.3rem;
  overflow-y: auto;
  overscroll-behavior: contain;
  min-height: 0;
}

.rows::-webkit-scrollbar {
  width: 8px;
}

.rows::-webkit-scrollbar-track {
  background: transparent;
}

.rows::-webkit-scrollbar-thumb {
  background: rgb(148 163 184 / 0.22);
  border-radius: 4px;
}

.rows::-webkit-scrollbar-thumb:hover {
  background: rgb(148 163 184 / 0.38);
}

.row + .row {
  border-top: 1px solid rgb(148 163 184 / 0.08);
}

.row__btn {
  position: relative;
  display: grid;
  grid-template-columns: 3px minmax(0, 1fr) minmax(0, auto) 1rem;
  align-items: center;
  gap: 0.7rem;
  width: 100%;
  padding: 0.6rem 0.55rem;
  border: none;
  border-radius: var(--bd-radius-sm);
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
  transition: background 0.16s ease;
}

.row__btn:hover {
  background: rgb(148 163 184 / 0.09);
}

.row__btn:focus-visible {
  outline: 2px solid var(--bd-sky);
  outline-offset: -2px;
}

/*
 * Strip warna di kiri.
 *
 * Lapisan HALUS, bukan blok warna solid: isi baris harus tetap bisa dibaca,
 * dan strip adalah penanda sekunder -- nilainya sudah ada di pill status.
 * Lolos ke `overflow: hidden` di `.card`, jadi ujungnya ikut radius kartu.
 */
.row__stripe {
  align-self: stretch;
  border-radius: 999px;
  background: var(--bd-line-strong);
}

.row.is-ok .row__stripe {
  background: var(--bd-green-fill);
}
.row.is-info .row__stripe {
  background: var(--bd-sky-fill);
}
.row.is-warn .row__stripe {
  background: var(--bd-amber-fill);
}
.row.is-bad .row__stripe {
  background: var(--bd-red-fill);
}
.row.is-muted .row__stripe {
  background: var(--bd-slate);
}

.row__main {
  min-width: 0;
}

.row__name {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.79rem;
  font-weight: 700;
  color: var(--bd-text);
  min-width: 0;
}

/*
 * Nama dipotong dari KANAN (`direction: rtl` + `text-align: left`), bukan
 * dari kiri.
 *
 * Nama lembaga Indonesia almost selaluORG memisahkan yang generik di depan
 * ("Perum", "PD", "Koperasi", "UPTD") dari yang membedakan di belakang
 * ("Tirto Mulo", "Harapan Bersama"). Memotong dari belakang membuang bagian
 * yang membedakan; memotong dari depan membuang bagian yang sama di semua
 * baris.
 *
 * `direction: rtl` yangakkan titik-titik pindah ke depan; `&lrm;` di dalam
 * teks mengembalikan arah bacaan supaya sisa nama tidak terbalik tampilnya.
 */
.row__name-text,
.row__name > :first-child {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  direction: rtl;
  text-align: left;
}

.row__pill {
  flex-shrink: 0;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  font-size: 0.56rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  direction: ltr;
}

.row__pill.is-ok {
  background: var(--bd-green-tint);
  color: var(--bd-green);
}
.row__pill.is-info {
  background: var(--bd-sky-tint);
  color: var(--bd-sky);
}
.row__pill.is-warn {
  background: var(--bd-amber-tint);
  color: var(--bd-amber);
}
.row__pill.is-bad {
  background: var(--bd-red-tint);
  color: var(--bd-red);
}
.row__pill.is-muted {
  background: var(--bd-slate-tint);
  color: var(--bd-text-dim);
}

.row__meta {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0.25rem 0 0;
  min-width: 0;
  overflow: hidden;
}

.row__sektor,
.row__slug,
.row__sisa {
  font-size: 0.62rem;
  white-space: nowrap;
}

.row__sektor {
  flex-shrink: 0;
  padding: 0.05rem 0.35rem;
  border-radius: 4px;
  background: var(--bd-violet-tint);
  color: var(--bd-violet);
  font-weight: 700;
}

.row__slug {
  overflow: hidden;
  text-overflow: ellipsis;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: var(--bd-text-faint);
}

.row__sisa {
  flex-shrink: 0;
  font-weight: 700;
  color: var(--bd-text-dim);
}

.row__sisa.is-warn {
  color: var(--bd-amber);
}

.row__sisa.is-bad {
  color: var(--bd-red);
}

.row__money {
  flex-shrink: 0;
  text-align: right;
  white-space: nowrap;
}

.row__amount {
  margin: 0;
  font-size: 0.82rem;
  font-weight: 800;
  color: var(--bd-text);
}

.row__amount.is-clear {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.72rem;
  color: var(--bd-green);
}

.row__amount-note {
  margin: 0.2rem 0 0;
  font-size: 0.6rem;
  color: var(--bd-text-faint);
}

.row__overdue {
  color: var(--bd-red);
  font-weight: 800;
}

.row__go {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--bd-line-strong);
  font-size: 0.66rem;
  transition:
    color 0.16s ease,
    transform 0.16s ease;
}

.row__btn:hover .row__go {
  color: var(--bd-sky);
  transform: translateX(2px);
}

/* ===== CARD: PERLU PERHATIAN ===== */

.card--alerts {
  min-height: 0;
}

.card__clear {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 1rem;
  text-align: center;
  color: var(--bd-green);
}

.card__clear svg {
  font-size: 1.5rem;
}

.card__clear p {
  margin: 0;
  font-size: 0.72rem;
  line-height: 1.5;
  color: var(--bd-text-dim);
}

.alerts {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 0.5rem;
  list-style: none;
  margin: 0;
  padding: 0;
  min-height: 0;
  overflow-y: auto;
}

.alert {
  display: grid;
  grid-template-columns: 1.6rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem 0.7rem;
  border-radius: var(--bd-radius-sm);
  background: var(--bd-surface-2);
  border-left: 2px solid var(--bd-slate);
}

.alert.is-bad {
  border-left-color: var(--bd-red);
  background: var(--bd-red-tint);
}
.alert.is-warn {
  border-left-color: var(--bd-amber);
  background: var(--bd-amber-tint);
}
.alert.is-info {
  border-left-color: var(--bd-sky);
  background: var(--bd-sky-tint);
}
.alert.is-muted {
  border-left-color: var(--bd-slate);
}

.alert__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.78rem;
  color: var(--bd-text-dim);
}

.alert.is-bad .alert__icon {
  color: var(--bd-red);
}
.alert.is-warn .alert__icon {
  color: var(--bd-amber);
}
.alert.is-info .alert__icon {
  color: var(--bd-sky);
}

.alert__body {
  min-width: 0;
}

.alert__label {
  margin: 0;
  font-size: 0.72rem;
  font-weight: 800;
  color: var(--bd-text);
}

.alert__value {
  margin: 0.2rem 0 0;
  font-size: 0.62rem;
  line-height: 1.45;
  color: var(--bd-text-dim);
}

.alert__link {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  flex-shrink: 0;
  font-size: 0.64rem;
  font-weight: 800;
  color: var(--bd-sky);
  text-decoration: none;
  white-space: nowrap;
}

.alert__link:hover {
  text-decoration: underline;
}

/* ===== AKSESIBILITAS ===== */

/*
 * Fokus keyboard harus terlihat di mana pun. `outline-offset: -2px` (minus)
 * dipakai pada elemen yang sudah punya border sendiri -- dengan offset
 * positif, outline-nya jatuh ke luar elemen dan di dalam `.card` yang
 * `overflow: hidden`, ujungnya ikut terpotong.
 */
.row__btn:focus-visible,
.chip:focus-visible,
.list__sort:focus-visible,
.list__cta:focus-visible,
.card__link:focus-visible,
.alert__link:focus-visible,
.list__search input:focus-visible {
  outline: 2px solid var(--bd-sky);
  outline-offset: -2px;
}

.list__search input:focus-visible {
  outline-offset: 0;
}

/* ===== RESPONSIF ===== */

/*
 * Empat titik patah, bukan satu.
 *
 * Setiap patah menjawab pertanyaan yang berbeda:
 *   1200px  bento tiga kolom terlalu sempit -> KPI pindah ke baris sendiri
 *   1024px  sidebar jadi laci, ruang konten berkurang -> dua kolom melebar
 *   780px   layar ponsel -> satu kolom, tinggi dilepas, scroll halaman balik
 *   520px  /meta   tablet/ponsel kecil -> kartu KPI jadi satu kolom
 */
@media (max-width: 1200px) {
  .bd {
    grid-template-rows: minmax(0, 1fr) minmax(0, 1fr);
  }

  .bd__hero {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    grid-template-rows: minmax(0, 1.15fr) minmax(0, 1fr);
  }

  /* Kartu kondisi jadi baris penuh di bawah hero + KPI */
  .card--kondisi {
    grid-column: 1 / -1;
  }

  .bd__kpis {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    grid-template-rows: minmax(0, 1fr);
  }
}

@media (max-width: 1024px) {
  .bd__split {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(0, 1.15fr) minmax(0, 1fr);
  }
}

/*
 * Bawah 48rem, tinggi kembali jadi scroll halaman.
 *
 * Kenapa berubah: grid `100dvh` dengan panel dalam yang bisa scroll hanya
 * berhasil kalau ada ruang vertikal yang cukup. Di ponsel, tinggi layar
 * dipakai topnav + beberapa baris daftar -- sisa ruang untuk daftar hanya
 * jadi tiga baris. Di titik itu scroll halaman lebih baik daripada panel yang
 * bisa menggulir sendiri: yang penting daftar punya ruang.
 *
 * Kenapa 48rem dan bukan 780px: 780px itu sisa penulisan manual yang tidak
 * sama dengan tetangganya. `CentralLayout.vue` dan `CentralTagihanIndex.vue`
 * memakai `48rem` (= 768px, dengan `html { font-size: 16px }`) sebagai batas
 * "mengganti tampilan desktop dengan tampilan ponsel".
 *
 * Yang tidak terlihat begitu: pada zoom 100% keduanya identik, jadi
 * selisih 12px itu memang tidak akan muncul di laptop mana pun. Tapi `px`
 * ikut ter-skala begitu pengguna mengubah zoom ke 110% atau ukuran font
 * perangkat, sedangkan `rem` tidak. Akibatnya batas gaya di sini bergeser
 * 12px dari tetangganya -- dan di lebar tepat 770px, topnav sudah memakai
 * gaya ponsel sementara bento masih memakai gaya tablet. Dua bagian yang
 * mestinya bergantian bersamaan tampil dalam mode berbeda, tanpa ada yang
 * menyebabkannya terlihat di kode.
 */
@media (max-width: 48rem) {
  .bd {
    display: flex;
    flex-direction: column;
    height: auto;
    max-height: none;
    overflow: visible;
  }

  .bd__hero,
  .bd__split,
  .bd__kpis,
  .bd__side {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: none;
  }

  .card--kondisi {
    grid-column: auto;
  }

  /* Kembali ke auto-height: di mode halaman, panel dalam tidak perlu
     menggulir sendiri -- halaman yang menggulir. */
  .rows,
  .alerts {
    overflow-y: visible;
    max-height: none;
  }

  .card--list {
    max-height: none;
  }

  .list__tools {
    justify-content: flex-start;
  }

  .row__btn {
    grid-template-columns: 3px minmax(0, 1fr) 1rem;
    row-gap: 0.35rem;
  }

  /* Nominal turun ke baris kedua, rata penuh -- di lebar 360px, nominal
     dan nama dalam satu baris berarti salah satunya pasti terpotong. */
  .row__money {
    grid-column: 2 / 3;
    text-align: left;
  }

  .row__go {
    grid-column: 3;
    grid-row: 1;
  }

  .hero__stack {
    margin-top: 0.8rem;
  }
}

@media (max-width: 520px) {
  /* Padding `.bd` yang dulu 10px di sini dihapus: halaman ini tidak lagi
     punya padding sendiri -- jaraknya datang dari `--central-gutter` di
     CentralLayout.vue, sama seperti enam halaman lain. Nilai 10px ini yang
     bikin dashboard terlihat "lebih sempit" di HP dibanding halaman tetangganya,
     sementara `--bd-gap` (14px) tetap dipakai jarak antar kartu. */
  .bd__kpis {
    gap: 10px;
  }

  /*
   * Kembali ke tumpukan di bawah 520px.
   *
   * Aman di sini justru karena mode halaman sudah aktif (< 780px): `.bd`
   * kehilangan `height` dan `overflow: hidden`, jadi kartu tidak lagi punya
   * tinggi tetap yang bisa memotong isi. Di atas 780px dua kolom tetap
   * dipakai, karena di situ tinggi kartu yang dibatasi grid-lah yang
   * jadi masalahnya -- bukan lebarnya.
   */
  .hero__stack {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.55rem;
  }

  .hero__cell + .hero__cell {
    padding-left: 0;
    padding-top: 0.55rem;
    border-left: 0;
    border-top: 1px solid var(--bd-line);
  }

  .card__head--tools {
    flex-direction: column;
    align-items: stretch;
  }

  .list__search {
    width: 100%;
  }

  .list__head {
    flex-wrap: wrap;
  }

  .alert {
    grid-template-columns: 1.4rem minmax(0, 1fr);
  }

  .alert__link {
    grid-column: 2;
    margin-top: 0.15rem;
  }
}

/*
 * `prefers-reduced-motion` tidak otomatis mematikan CSS transition, hanya
 * animasi yang ditandai lewat API. Semua transisi di halaman ini cuma
 * pewarnaan dan perubahan lebar 0.16-0.28 detik, jadi dimatikan tanpa
 * kehilangan makna.
 */
@media (prefers-reduced-motion: reduce) {
  .row__btn,
  .row__go,
  .chip,
  .list__sort,
  .list__cta,
  .list__search input,
  .pie__slice,
  .pie-legend__item {
    transition: none;
  }
}
</style>
