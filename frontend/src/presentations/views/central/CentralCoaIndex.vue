<template>
  <div class="coa-page">
    <!--
         ===== Penjelasan singkat =====

         Dua hal yang perlu diketahui sebelum menyentuh tabel ini, diringkas
         jadi dua baris pendek. Semula dua paragraf panjang yang bersama
         memakan tinggi layar penuh sebelum tabelnya sendiri terlihat.

         Peringatan kedua tidak lagi menyebut "menomori ulang atau menghapus
         baris": tidak ada tombol untuk keduanya di halaman ini. Menyebutkan
         bahaya yang tidak bisa dilakukan dari sini hanya membuat orang
         mencari tombol yang memang tidak ada, dan mengejar ketakutan yang
         tidak perlu.
    -->
    <div class="coa-notice">
      <font-awesome-icon icon="circle-info" />
      <p>
        Level 1-3 dipakai bersama semua lokasi dan disimpan di database pusat -- perubahan di sini
        langsung terlihat di laporan mereka. Akun level 4 tetap milik masing-masing lokasi.
      </p>
    </div>

    <div class="coa-notice coa-notice--warn">
      <font-awesome-icon icon="triangle-exclamation" />
      <p>
        <strong>Kode akun dan induknya tidak bisa diubah dari sini.</strong>
        Akun level 4 di setiap lokasi menunjuk induknya ke tabel ini tanpa dijaga database, jadi
        merombak kodenya membuat laporan mereka kehilangan sebagian akunnya.
      </p>
    </div>

    <div class="coa-notice coa-notice--warn">
      <font-awesome-icon icon="triangle-exclamation" />
      <p>
        <strong>Menghapus berjenjang dan permanen.</strong> Menghapus sebuah akun ikut menghapus
        seluruh turunannya, termasuk akun level 4 milik setiap lokasi. Akun yang sudah punya data
        keuangan tidak bisa dihapus -- backend akan menyebutkan mana yang tertahan. Tidak ada tempat
        sampah: yang hilang tidak bisa dikembalikan.
      </p>
    </div>

    <!--
         ===== Toolbar =====

         Ringkasan jumlah akun di kiri, kotak pencarian di kanan -- urutan
         yang sama dengan tabel lain di panel pusat, tempat kotak pencarian
         menempel ke tepi kanan dan tidak pernah melar memenuhi baris.

         Lebarnya dibatasi 20rem, bukan `flex: 1`. Pohon ini hanya punya
         beberapa kolom dan tidak butuh ruang selebar itu; kotak yang melar
         memenuhi baris membuat pencarian terlihat seperti satu-satunya
         kontrol yang penting, padahal yang sebenarnya adalah akunnya.
    -->
    <div class="coa-toolbar">
      <div class="coa-toolbar__meta">
        <span
          ><strong>{{ filteredTree.length }}</strong
          >/{{ tree.length }} akun level 1</span
        >
        <span
          ><strong>{{ totalLevel2 }}</strong> level 2 &middot;
          <strong>{{ totalLevel3 }}</strong> level 3</span
        >
      </div>

      <div class="coa-toolbar__search">
        <font-awesome-icon icon="magnifying-glass" />
        <input v-model="search" type="text" placeholder="Cari kode atau nama akun..." />
      </div>

      <button type="button" class="coa-btn coa-btn--primary coa-btn--tambah" @click="openCreate(1)">
        <font-awesome-icon icon="plus" />
        <span>Tambah Akun</span>
      </button>
    </div>

    <!-- ===== Pohon ===== -->
    <div class="coa-tree-wrap">
      <p v-if="loading" class="coa-state">
        <font-awesome-icon icon="spinner" spin />
        <span>Memuat struktur akun...</span>
      </p>

      <p v-else-if="error" class="coa-state coa-state--error">
        <font-awesome-icon icon="circle-exclamation" />
        <span>{{ error }}</span>
      </p>

      <p v-else-if="!tree.length" class="coa-state">
        <font-awesome-icon icon="folder-open" class="coa-state__icon" />
        <span>Struktur akun belum tersedia di database pusat.</span>
      </p>

      <p v-else-if="!filteredTree.length" class="coa-state">
        <font-awesome-icon icon="magnifying-glass" />
        <span>Tidak ada akun yang cocok dengan &quot;{{ search }}&quot;.</span>
      </p>

      <ul v-else class="coa-tree">
        <li v-for="l1 in filteredTree" :key="l1.id">
          <div class="coa-row coa-row--1">
            <button
              type="button"
              class="coa-toggle"
              :aria-label="isExpanded(l1) ? 'Ciutkan' : 'Buka'"
              @click="toggle(l1)"
            >
              <font-awesome-icon :icon="isExpanded(l1) ? 'chevron-down' : 'chevron-right'" />
            </button>

            <span class="coa-code">{{ l1.kode_akun }}</span>
            <span class="coa-name">{{ l1.nama_akun }}</span>
            <span class="coa-mut" :class="mutClass(l1.jenis_mutasi)">{{ l1.jenis_mutasi }}</span>
            <button
              type="button"
              class="coa-row__aksi"
              title="Tambah akun level 2 di bawah ini"
              @click="openCreate(2, l1)"
            >
              <font-awesome-icon icon="plus" />
            </button>
            <button type="button" class="coa-row__aksi" title="Ubah akun ini" @click="openEdit(l1)">
              <font-awesome-icon icon="pen" />
            </button>
            <button
              type="button"
              class="coa-row__aksi coa-row__aksi--danger"
              title="Hapus akun ini beserta seluruh turunannya"
              @click="openDelete(l1)"
            >
              <font-awesome-icon icon="trash-can" />
            </button>
          </div>

          <ul v-show="isExpanded(l1)" class="coa-children">
            <li v-for="l2 in l1.children" :key="l2.id">
              <div class="coa-row coa-row--2">
                <button
                  type="button"
                  class="coa-toggle"
                  :aria-label="isExpanded(l2) ? 'Ciutkan' : 'Buka'"
                  @click="toggle(l2)"
                >
                  <font-awesome-icon :icon="isExpanded(l2) ? 'chevron-down' : 'chevron-right'" />
                </button>

                <span class="coa-code">{{ l2.kode_akun }}</span>
                <span class="coa-name">{{ l2.nama_akun }}</span>
                <span class="coa-mut" :class="mutClass(l2.jenis_mutasi)">{{
                  l2.jenis_mutasi
                }}</span>
                <button
                  type="button"
                  class="coa-row__aksi"
                  title="Tambah akun level 3 di bawah ini"
                  @click="openCreate(3, l2)"
                >
                  <font-awesome-icon icon="plus" />
                </button>
                <button
                  type="button"
                  class="coa-row__aksi"
                  title="Ubah akun ini"
                  @click="openEdit(l2)"
                >
                  <font-awesome-icon icon="pen" />
                </button>
                <button
                  type="button"
                  class="coa-row__aksi coa-row__aksi--danger"
                  title="Hapus akun ini beserta seluruh turunannya"
                  @click="openDelete(l2)"
                >
                  <font-awesome-icon icon="trash-can" />
                </button>
              </div>

              <ul v-show="isExpanded(l2)" class="coa-children">
                <li v-for="l3 in l2.children" :key="l3.id">
                  <div class="coa-row coa-row--3">
                    <span class="coa-toggle coa-toggle--leaf" />
                    <span class="coa-code">{{ l3.kode_akun }}</span>
                    <span class="coa-name">{{ l3.nama_akun }}</span>
                    <span class="coa-mut" :class="mutClass(l3.jenis_mutasi)">{{
                      l3.jenis_mutasi
                    }}</span>
                    <span class="coa-pos">Posisi {{ l3.posisi }}</span>
                    <button
                      type="button"
                      class="coa-row__aksi"
                      title="Ubah akun ini"
                      @click="openEdit(l3)"
                    >
                      <font-awesome-icon icon="pen" />
                    </button>
                    <button
                      type="button"
                      class="coa-row__aksi coa-row__aksi--danger"
                      title="Hapus akun ini beserta akun level 4 di semua lokasi"
                      @click="openDelete(l3)"
                    >
                      <font-awesome-icon icon="trash-can" />
                    </button>
                  </div>
                </li>
              </ul>
            </li>
          </ul>
        </li>
      </ul>
    </div>

    <!-- ===== Dialog edit ===== -->
    <BaseModal
      v-if="editing"
      :title="`Ubah Akun Level ${editing.level}`"
      :subtitle="editing.kode_akun"
      icon="pen"
      size="md"
      @close="closeEdit"
    >
      <template #default>
        <div class="coa-form">
          <p class="coa-form__scope">
            <font-awesome-icon icon="globe" />
            <span
              >Perubahan ini berlaku untuk <strong>semua lokasi</strong>, bukan hanya satu.</span
            >
          </p>

          <label class="coa-field">
            <span>Nama akun</span>
            <input v-model="form.nama_akun" type="text" maxlength="100" />
          </label>

          <label class="coa-field">
            <span>Jenis mutasi</span>
            <!--
                 Dua opsi statis, jadi `filter` dan `showClear` dimatikan:
                 mengetik "deb" untuk memilih satu dari dua pilihan menambah
                 langkah tanpa menambah kecepatan. Yang konsisten dengan
                 filter halaman lain tetap penting -- native select punya
                 tinggi dan padding berbeda dari Select PrimeVue, dan
                 campuran keduanya membuat baris form terlihat tidak
                 rata.
            -->
            <BaseSelect
              v-model="form.jenis_mutasi"
              :options="JENIS_MUTASI_OPTIONS"
              option-label="label"
              option-value="value"
              :filter="false"
              :show-clear="false"
              placeholder="Pilih jenis mutasi"
            />
            <small> Menentukan arah saldo di laporan. Disimpan otomatis dalam huruf kecil. </small>
          </label>

          <label v-if="editing.level === 3" class="coa-field">
            <span>Posisi</span>
            <input v-model.number="form.posisi" type="number" min="0" max="9" />
            <small>Urutan akun di dalam level 3 saat laporan ditampilkan.</small>
          </label>

          <p v-if="formError" class="coa-form__error">
            <font-awesome-icon icon="circle-exclamation" />
            <span>{{ formError }}</span>
          </p>
        </div>
      </template>

      <template #footer>
        <button type="button" class="coa-btn coa-btn--ghost" :disabled="saving" @click="closeEdit">
          Batal
        </button>
        <button type="button" class="coa-btn coa-btn--save" :disabled="saving" @click="save">
          <font-awesome-icon v-if="saving" icon="spinner" spin />
          <span>Simpan</span>
        </button>
      </template>
    </BaseModal>

    <!-- ===== Dialog tambah ===== -->
    <BaseModal
      v-if="creating"
      :title="`Tambah Akun Level ${creating.level}`"
      :subtitle="creating.parent ? `Di bawah ${creating.parent.kode_akun} (${creating.parent.nama_akun})` : 'Kategori utama baru'"
      icon="plus"
      size="md"
      @close="closeCreate"
    >
      <template #default>
        <div class="coa-form">
          <p class="coa-form__scope">
            <font-awesome-icon icon="globe" />
            <span>Akun baru ini berlaku untuk <strong>semua lokasi</strong> dan langsung tampil di laporan mereka.</span>
          </p>

          <label class="coa-field">
            <span>Induk akun</span>
            <input :value="creating.parent ? `${creating.parent.kode_akun} - ${creating.parent.nama_akun}` : '— (kategori utama)'" type="text" disabled />
            <small v-if="creating.level === 1">Level 1 adalah kategori utama, jadi tidak punya induk.</small>
            <small v-else>Induk bisa diganti dengan menutup dialog ini dan memulai dari baris induk yang lain.</small>
          </label>

          <label class="coa-field">
            <span>Kode akun</span>
            <input v-model="createForm.kode_akun" type="text" maxlength="10" placeholder="mis. 1.1.07.00" />
            <small>Panjang maksimal 10 karakter. Kode tidak bisa diubah setelah disimpan.</small>
          </label>

          <label class="coa-field">
            <span>Nama akun</span>
            <input v-model="createForm.nama_akun" type="text" maxlength="100" placeholder="mis. Kas Operasional" />
          </label>

          <label class="coa-field">
            <span>Jenis mutasi</span>
            <BaseSelect
              v-model="createForm.jenis_mutasi"
              :options="JENIS_MUTASI_OPTIONS"
              option-label="label"
              option-value="value"
              :filter="false"
              :show-clear="false"
              placeholder="Pilih jenis mutasi"
            />
            <small>Menentukan arah saldo di laporan. Kosongkan untuk mewarisi nilai induk.</small>
          </label>

          <label v-if="creating.level === 3" class="coa-field">
            <span>Posisi</span>
            <input v-model.number="createForm.posisi" type="number" min="0" max="9" />
            <small>Urutan akun di dalam level 3 saat laporan ditampilkan.</small>
          </label>

          <p v-if="createError" class="coa-form__error">
            <font-awesome-icon icon="circle-exclamation" />
            <span>{{ createError }}</span>
          </p>
        </div>
      </template>

      <template #footer>
        <button type="button" class="coa-btn coa-btn--ghost" :disabled="creating_busy" @click="closeCreate">
          Batal
        </button>
        <button type="button" class="coa-btn coa-btn--save" :disabled="creating_busy" @click="submitCreate">
          <font-awesome-icon v-if="creating_busy" icon="spinner" spin />
          <span>Simpan</span>
        </button>
      </template>
    </BaseModal>

    <!--
         ===== Dialog hapus =====

         Isinya diambil dari endpoint `impact`, bukan dihitung di browser.

         Kalau angkanya dihitung di sini, pohon yang tampil bisa berbeda dari
         yang benar-benar dihapus -- dan di halaman yang menyangkut semua
         lokasi, "tampak benar tapi tidak sama" adalah kegagalan yang paling
         berbahaya: orangnya tidak punya alasan untuk tidak percaya.
    -->
    <BaseModal
      v-if="deleting"
      title="Hapus Akun"
      :subtitle="deleting.kode_akun"
      icon="triangle-exclamation"
      size="md"
      @close="closeDelete"
    >
      <template #default>
        <div class="coa-form">
          <p v-if="impactLoading" class="coa-form__scope">
            <font-awesome-icon icon="spinner" spin />
            <span>Memeriksa dampaknya di semua lokasi...</span>
          </p>

          <template v-else-if="impact">
            <p v-if="impact.blocked" class="coa-form__error">
              <font-awesome-icon icon="circle-exclamation" />
              <span>{{ impact.message }}</span>
            </p>

            <template v-else>
              <p class="coa-form__scope coa-form__scope--danger">
                <font-awesome-icon icon="triangle-exclamation" />
                <span>{{ impact.message }}</span>
              </p>

              <ul v-if="impact.jumlah_level4 > 0" class="coa-impact-list">
                <li v-for="loc in impact.locations" :key="loc.slug" class="coa-impact-list__loc">
                  <span class="coa-impact-list__slug">{{ loc.slug }}</span>
                  <span>{{ loc.jumlah_level4 }} akun level 4 ikut terhapus</span>
                  <ul>
                    <li v-for="acc in loc.accounts" :key="acc.kode_akun">
                      <code>{{ acc.kode_akun }}</code> {{ acc.nama_akun }}
                    </li>
                  </ul>
                </li>
              </ul>
            </template>
          </template>

          <template v-if="impact && !impact.blocked">
            <label class="coa-field">
              <span>Ketik kode akun untuk mengonfirmasi</span>
              <input v-model="confirmText" type="text" :placeholder="deleting.kode_akun" autocomplete="off" />
              <small>Kode di bawah harus diketik persis: <code>{{ deleting.kode_akun }}</code></small>
            </label>

            <label class="coa-check">
              <input v-model="confirmCheck" type="checkbox" />
              <span
                >Saya mengerti akun ini dan seluruh turunannya di semua lokasi akan dihapus permanen.</span
              >
            </label>
          </template>

          <p v-if="deleteError" class="coa-form__error">
            <font-awesome-icon icon="circle-exclamation" />
            <span>{{ deleteError }}</span>
          </p>
        </div>
      </template>

      <template #footer>
        <button type="button" class="coa-btn coa-btn--ghost" :disabled="deleting_busy" @click="closeDelete">
          Batal
        </button>
        <button
          type="button"
          class="coa-btn coa-btn--danger"
          :disabled="deleting_busy || !canDelete"
          @click="submitDelete"
        >
          <font-awesome-icon v-if="deleting_busy" icon="spinner" spin />
          <span>Hapus Permanen</span>
        </button>
      </template>
    </BaseModal>

    <!--
         Hasil simpan ditampilkan sebagai pesan melayang, bukan di dalam dialog.

         Dialognya sudah tertutup, jadi pesan "tidak ada perubahan" yang
         diletakkan di dalam form akan ikut hilang bersama form -- persis di
         saat orang paling perlu diberi tahu bahwa simpanannya tidak
         menghasilkan apa pun.
    -->
    <Transition name="coa-toast">
      <div v-if="notice" class="coa-toast" :class="`coa-toast--${notice.tone}`">
        <font-awesome-icon :icon="notice.tone === 'ok' ? 'circle-check' : 'circle-info'" />
        <span>{{ notice.text }}</span>
        <button type="button" class="coa-toast__close" @click="notice = null">
          <font-awesome-icon icon="xmark" />
        </button>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { coaService } from '@/services/central.service'
import BaseModal from '@/presentations/components/ui/BaseModal.vue'
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'

const tree = ref([])
const loading = ref(false)
const error = ref('')
const search = ref('')

const editing = ref(null)
const saving = ref(false)
const notice = ref(null)
const form = reactive({ nama_akun: '', jenis_mutasi: 'debet', posisi: 0 })

/* ===== Tambah ===== */
const creating = ref(null)
const creating_busy = ref(false)
const createError = ref('')
const createForm = reactive({
  kode_akun: '',
  nama_akun: '',
  jenis_mutasi: null,
  posisi: 1,
})

/* ===== Hapus ===== */
const deleting = ref(null)
const deleting_busy = ref(false)
const deleteError = ref('')
const impact = ref(null)
const impactLoading = ref(false)
const confirmText = ref('')
const confirmCheck = ref(false)

/*
 * Tombol hapus hanya hidup kalau TIGA hal benar: dampaknya sudah diketahui
 * dan tidak terhalang, kodenya diketik cocok, dan centangnya sudah dicek.
 *
 * `confirmText` dibandingkan dengan `trim()`, bukan `===` telanjang. Orang
 * menyalin-tempel, dan kode yang terlihat sama adalah cara paling umum
 * tersandung guard yang justru dimaksudkan tegas; kalau sampai kena 422,
 * pesannya sudah menjelaskan persis apa yang salah.
 */
const confirmCocok = computed(() => {
  if (!deleting.value || !impact.value || impact.value.blocked) return false

  return confirmText.value.trim() === String(deleting.value.kode_akun).trim()
})

const canDelete = computed(() => confirmCocok.value && confirmCheck.value)

/*
 * Nilai `value` huruf kecil karena inilah yang dikirim ke backend dan
 * yang dipakai `mutClass()` untuk menentukan warna. Label yang tampil
 * capitalize supaya konsisten dengan penamaan akun di laporan.
 */
const JENIS_MUTASI_OPTIONS = [
  { label: 'Debet', value: 'debet' },
  { label: 'Kredit', value: 'kredit' },
]
const formError = ref('')

/**
 * Keadaan buka/tutup disimpan per `id` akuntannya, bukan menempel pada objek.
 *
 * `id` level 2 dan level 3 berasal dari tabel yang berbeda, jadi keduanya bisa
 * bernilai sama tanpa arti. Karena itu kuncinya didahului level-nya --
 * "1:3" dan "3:3" jelas berbeda, sedangkan keduanya tidak akan tertukar.
 *
 * Disimpan terpisah dari `tree` dengan sengaja: `save()` memuat ulang data
 * dari server, dan kalau keadaan buka/tutup ikut hilang tiap muat ulang,
 * pengguna yang sedang menjelajah pohon akan terus terkunci di tampilan
 * tertutup setelah setiap penyimpanan.
 */
const expanded = reactive(new Set())

const keyOf = (level, id) => `${level}:${id}`

/*
 * Setiap node diberi `key` dan `level`-nya sendiri saat dimuat, lalu seluruh
 * tempat memakai `node.key`.
 *
 * Versi sebelumnya menyusun kunci di dua tempat sekaligus: `toggle()`
 * menerimanya dari argumen template, sedangkan `isExpanded()` harus
 * meneruskannya sendiri. Salah satu lupa, keduanya jadi membandingkan kunci
 * yang berbeda -- chevron tetap tertutup padahal barisnya sudah dibuka, atau
 * sebaliknya, dan tidak ada error yang terlihat.
 */
const decorate = (nodes, level) =>
  nodes.map((n) => ({
    ...n,
    level,
    key: keyOf(level, n.id),
    children: decorate(n.children ?? [], level + 1),
  }))

const isExpanded = (node) => expanded.has(node.key)

const toggle = (node) => {
  if (expanded.has(node.key)) expanded.delete(node.key)
  else expanded.add(node.key)
}

const totalLevel2 = computed(() => tree.value.reduce((n, l1) => n + (l1.children?.length ?? 0), 0))

const totalLevel3 = computed(() =>
  tree.value.reduce(
    (n, l1) => n + (l1.children ?? []).reduce((m, l2) => m + (l2.children?.length ?? 0), 0),
    0,
  ),
)

const matches = (node) => {
  const q = search.value.trim().toLowerCase()

  if (!q) return true

  return (
    String(node.kode_akun ?? '')
      .toLowerCase()
      .includes(q) ||
    String(node.nama_akun ?? '')
      .toLowerCase()
      .includes(q)
  )
}

/**
 * Pencarian diterapkan di dalam memori, bukan lewat query server.
 *
 * Strukturnya kecil dan tetap (5 / 14 / 42 baris) dan sudah ikut terambil di
 * satu respons `tree`, jadi menambahkannya ke endpoint hanya menambah satu
 * request tanpa hemat yang berarti -- sementara di sisi ini, mengetik tidak
 * menembak API pada setiap ketikan.
 *
 * Aturan yang dipakai: sebuah level dipertahankan kalau dirinya sendiri
 * cocok, atau kalau turunannya ada yang cocok. Kalau induknya cocok, seluruh
 * anaknya ikut ditampilkan apa adanya -- memfilter anak dari induk yang
 * sudah cocok membuat "Kas" menampilkan "Kas" tanpa "Kas Tunai", padahal
 * keduanya sedang dicari.
 */
const filteredTree = computed(() => {
  if (!search.value.trim()) return tree.value

  return tree.value
    .map((l1) => {
      const l2list = (l1.children ?? [])
        .map((l2) => {
          if (matches(l2)) return l2

          const l3list = (l2.children ?? []).filter(matches)

          return l3list.length ? { ...l2, children: l3list } : null
        })
        .filter(Boolean)

      return matches(l1) || l2list.length ? { ...l1, children: l2list } : null
    })
    .filter(Boolean)
})

const mutClass = (mut) =>
  String(mut ?? '').toLowerCase() === 'kredit' ? 'coa-mut--kredit' : 'coa-mut--debet'

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const res = await coaService.getAll()

    tree.value = decorate(res.data?.data?.tree ?? [], 1)
  } catch {
    tree.value = []
    error.value = 'Gagal memuat struktur akun dari database pusat.'
  } finally {
    loading.value = false
  }
}

/*
 * Semua level mulai tertutup.
 *
 * Setiap level 1 punya anak, jadi membuka kelima level 1 sekaligus
 * menampilkan semua 42 baris di bawahnya -- bukan 5. Setelah setiap
 * penyimpanan, `load()` mengembalikan isi ke keadaan itu, sehingga layar
 * melompat tepat di saat orang sedang membandingkan hasil simpanannya
 * dengan akun lain.
 *
 * Karena `expanded` terpisah dari `tree` (lihat catatan di atas), keadaan
 * ini bertahan di luar muat ulang. Level yang benar-benar perlu dibuka
 * tetap bisa dibuka manual.
 */

const openEdit = (node) => {
  editing.value = { ...node }
  formError.value = ''

  form.nama_akun = node.nama_akun ?? ''
  form.jenis_mutasi = String(node.jenis_mutasi ?? 'debet').toLowerCase()
  form.posisi = node.posisi ?? 0
}

const closeEdit = () => {
  if (saving.value) return

  editing.value = null
  formError.value = ''
}

const save = async () => {
  if (!editing.value) return

  saving.value = true
  formError.value = ''

  try {
    const payload = {
      nama_akun: form.nama_akun.trim(),
      jenis_mutasi: form.jenis_mutasi,
    }

    if (editing.value.level === 3) payload.posisi = Number(form.posisi)

    const res = await coaService.update(editing.value.level, editing.value.id, payload)

    const tersimpan = res.data?.changed !== false

    editing.value = null

    // Selalu muat ulang, bahkan saat tidak ada yang berubah. Datanya dibaca
    // bersama oleh semua lokasi, jadi menyisakan angka yang sudah usang di
    // layar hanya menunggu seseorang membandingkan dan menyimpulkan bahwa
    // panel pusatnya yang salah.
    //
    // `expanded` sengaja tidak disentuh: muat ulang hanya mengganti isi,
    // bukan pilihan orang tentang apa yang mau dilihat.
    await load()

    notice.value = tersimpan
      ? {
          tone: 'ok',
          text: `"${form.nama_akun.trim()}" diperbarui. Berlaku untuk semua lokasi.`,
        }
      : {
          tone: 'info',
          text: 'Tidak ada perubahan: nilai yang diketik sudah sama dengan yang tersimpan.',
        }
  } catch (e) {
    formError.value = e?.response?.data?.message ?? 'Gagal menyimpan perubahan. Coba lagi.'
  } finally {
    saving.value = false
  }
}

/* ================================================================
   TAMBAH
   ================================================================ */

/**
 * Buka dialog tambah di bawah sebuah baris.
 *
 * `parent` level 1 dicatat di sini hanya supaya dialog bisa menampilkan
 * induknya sebagai teks. Backend tetap memverifikasi sendiri bahwa induk
 * itu masih ada -- kalau antara membuka dialog dan menekan Simpan, baris
 * induknya dihapus orang lain, jawabannya 422, bukan akun level 3 yang
 * diam-diam hilang dari pohon.
 */
const openCreate = (level, parent = null) => {
  creating.value = { level, parent: parent ? { ...parent } : null }
  createError.value = ''

  createForm.kode_akun = ''
  createForm.nama_akun = ''

  /*
   * `jenis_mutasi` sengaja `null`, bukan mewarisi nilai induknya di sini.
   *
   * Nilai yang sama akan tampil sebagai "Debet" padahal belum ada yang
   * memilih -- dan backend tidak lagi bisa membedakannya dari pilihan yang
   * sengaja dibuat. Default tersembunyi seperti ini lebih buruk daripada
   * kolom kosong: yang kosong diisi orang, yang tersembunyi tidak.
   */
  createForm.jenis_mutasi = null
  createForm.posisi = 1
}

const closeCreate = () => {
  if (creating_busy.value) return

  creating.value = null
  createError.value = ''
}

const submitCreate = async () => {
  if (!creating.value) return

  if (!createForm.kode_akun.trim() || !createForm.nama_akun.trim()) {
    createError.value = 'Kode akun dan nama akun wajib diisi.'
    return
  }

  creating_busy.value = true
  createError.value = ''

  const { level, parent } = creating.value
  const nama = createForm.nama_akun.trim()

  try {
    await coaService.create(level, {
      kode_akun: createForm.kode_akun.trim(),
      nama_akun: nama,
      jenis_mutasi: createForm.jenis_mutasi,
      parent_id: parent?.id,
      posisi: level === 3 ? Number(createForm.posisi) : null,
    })

    creating.value = null

    await load()

    /*
     * Induk yang baru mendapat anak langsung dibuka.
     *
     * Baris baru selalu tertutup kalau keadaan ini dibiarkan apa adanya:
     * `load()` mengembalikan isi ke keadaan awal, dan akun yang baru saja
     * ditambahkan ada di dalam cabang yang tertutup -- jadi orang menekan
     * Simpan lalu mendapat halaman yang persis seperti sebelum menekan.
     * Membuka indukannya adalah satu-satunya cara hasil kerja itu terlihat
     * tanpa perlu dicari.
     */
    if (parent) expanded.add(keyOf(parent.level, parent.id))

    notice.value = {
      tone: 'ok',
      text: `"${nama}" ditambahkan sebagai akun level ${level}. Berlaku untuk semua lokasi.`,
    }
  } catch (e) {
    createError.value = e?.response?.data?.message ?? 'Gagal menambahkan akun. Coba lagi.'
  } finally {
    creating_busy.value = false
  }
}

/* ================================================================
   HAPUS
   ================================================================ */

/**
 * Buka dialog hapus, sekalian ambil dampaknya.
 *
 * `impact` diambil DI SINI, bukan setelah `openDelete` selesai -- supaya
 * angka yang tampil dan angka yang dipakai backend untuk menghapus berasal
 * dari pemindaian yang sama. Kalau impact-nya baru diambil setelah dialog
 * terbuka, ada jeda di mana layar sudah menampilkan "3 akun" sementara
 * pemindaian yang dijalankan menghapus 5.
 */
const openDelete = async (node) => {
  deleting.value = { ...node }
  deleteError.value = ''
  impact.value = null
  impactLoading.value = true
  confirmText.value = ''
  confirmCheck.value = false

  try {
    const res = await coaService.getImpact(node.level, node.id)

    impact.value = res.data?.data ?? null
  } catch (e) {
    impact.value = null
    deleteError.value =
      e?.response?.data?.message ??
      'Gagal memeriksa dampaknya. Hapus dibatalkan supaya tidak ada yang hilang tanpa sengaja.'
  } finally {
    impactLoading.value = false
  }
}

const closeDelete = () => {
  if (deleting_busy.value) return

  deleting.value = null
  impact.value = null
  deleteError.value = ''
  confirmText.value = ''
  confirmCheck.value = false
}

const submitDelete = async () => {
  if (!deleting.value || !canDelete.value) return

  deleting_busy.value = true
  deleteError.value = ''

  const { level, id, kode_akun } = deleting.value

  try {
    const res = await coaService.remove(level, id, confirmText.value.trim())

    closeDelete()

    await load()

    /*
     * Kunci baris yang dihapus dibersihkan dari `expanded`.
     *
     * Tanpa ini, kunci-kunci itu memenuhi Set selamanya. Tidak terlihat --
     * `expanded` tidak dirender, dan `isExpanded()` tidak pernah dipanggil
     * untuk node yang sudah tidak ada -- tapi Set-nya bertambah setiap kali
     * ada penghapusan, dan itu hal pertama yang membingungkan pembaca
     * berkas ini nanti: halaman dengan 61 akun punya ratusan kunci.
     */
    for (const key of Array.from(expanded)) {
      if (key.startsWith(`${level}:`)) expanded.delete(key)
    }

    notice.value = {
      tone: 'ok',
      text: res.data?.message ?? `Akun "${kode_akun}" dihapus.`,
    }
  } catch (e) {
    /*
     * 409 (ada akun turunan yang sudah dipakai) dan 422 (kode konfirmasi
     * salah) DITAMPILKAN APA ADANYA. Pesan backend sudah menyebut akun
     * mana, di lokasi mana, dan kenapa tertahan; meringkasnya jadi "gagal
     * menghapus" membuang satu-satunya informasi yang membuat orang tahu
     * harus melakukan sesuatu.
     */
    deleteError.value =
      e?.response?.data?.message ?? 'Gagal menghapus akun. Coba lagi.'
  } finally {
    deleting_busy.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.coa-page {
  /* Lebar maksimum yang sama dengan halaman panel pusat yang lain. */
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: 14px;
  /* Elevasi penuh, sama dengan kartu utama halaman lain. */
  box-shadow: var(--central-shadow-card);
  overflow: hidden;
}

/* ===== Pemberitahuan ===== */
.coa-notice {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  padding: 0.6rem 1.25rem;
  background: var(--color-blue-50, #f0f9ff);
  border-bottom: 1px solid var(--color-blue-100, #e0f2fe);
  font-size: 0.74rem;
  color: var(--color-blue-800, #1e3a8a);
  line-height: 1.55;
}

/*
 * Paragraf di dalam banner, bukan `span`.
 *
 * `span` di dalam flex jadi anak flex, dan lebarnya mengikuti konten --
 * jadi di layar sempit teksnya tetap dalam satu baris panjang yang
 * meluber keluar kartu dan dipotong, bukan membungkus. `p` punya `margin`
 * bawaan yang juga perlu dinolkan supaya jarak ke ikon tetap konsisten.
 */
.coa-notice p {
  margin: 0;
  min-width: 0;
}

.coa-notice svg {
  margin-top: 0.15rem;
  flex-shrink: 0;
}

.coa-notice--warn {
  background: var(--color-yellow-50, #fef3c7);
  border-bottom-color: var(--color-yellow-100, #fde68a);
  color: var(--color-yellow-800, #92400e);
}

/* ===== Toolbar ===== */
.coa-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
  flex-wrap: wrap;
}

/*
 * Bentuknya mengikuti kotak pencarian `CentralLokasiIndex.vue`: ikon
 * dijepit di dalam kotak dengan `position: absolute`, inputnya yang diberi
 * padding kiri. Versi sebelumnya menumpuk ikon dan input dalam satu baris
 * flex, jadi jarak teks ke ikon ikut ditentukan oleh lebar ikon -- dan
 * jarak itu terlihat meleset dari tepi kotak.
 *
 * Kotak dibuat selebar baris di layar sempit lewat media query di bawah,
 * bukan lewat `min-width` yang akan berbenturan dengan `max-width` di atas.
 */
.coa-toolbar__search {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
  max-width: 20rem;
}

.coa-toolbar__search svg {
  position: absolute;
  left: 0.75rem;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.82rem;
  pointer-events: none;
}

.coa-toolbar__search input {
  width: 100%;
  padding: 0.5rem 0.85rem 0.5rem 2.25rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: 9px;
  /* 16px, bukan 0.83rem. Di bawah 16px, iOS Safari melakukan zoom otomatis
     saat input difokuskan, dan zoom itu tidak pernah dikembalikan: begitu
     diketik, halaman tetap ter-zoom sampai di-reload. central-login.css
     sudah menjelaskan masalah yang sama untuk input login; input pencarian
     di halaman ini punya masalah yang persis sama, jadi diperbaiki sama. */
  font-size: 16px;
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-text-main, #1e293b);
  transition: all 0.18s ease;
}

/* Di desktop 16px membuat kolom pencarian terlihat lebih besar dari
   toolbar di sekitarnya. `rem` yang lebih kecil hanya aman di pointer
   yang presisi -- yaitu mouse. */
@media (pointer: fine) {
  .coa-toolbar__search input {
    font-size: 0.83rem;
  }
}

.coa-toolbar__search input:focus {
  outline: none;
  border-color: var(--color-primary, #0ea5e9);
  background: var(--color-card-bg, #fff);
  box-shadow: 0 0 0 3px rgb(14 165 233 / 0.14);
}

.coa-toolbar__meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  font-size: 0.74rem;
  color: var(--color-gray-500, #6b7280);
}

.coa-toolbar__meta strong {
  color: var(--color-text-main, #1e293b);
}

/* ===== Pohon ===== */
.coa-tree-wrap {
  padding: 0.5rem 0;
  min-height: 12rem;
}

.coa-tree,
.coa-children {
  list-style: none;
  margin: 0;
  padding: 0;
}

/*
 * Garis penghubung memakai `margin-left`, bukan `padding-left`, supaya
 * `border-left` tetap menempel pada baris induknya -- kalau memakai padding,
 * garisnya bergeser beberapa piksel ke kanan dan tidak terlihat menyambung
 * dengan chevron di atasnya.
 */
.coa-children {
  margin-left: 1.45rem;
  padding-left: 1rem;
  border-left: 1px dashed var(--color-border, #e2e8f0);
}

.coa-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 1.25rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
  transition: background 0.15s ease;
}

.coa-row:hover {
  background: var(--color-gray-100, #f1f5f9);
}

.coa-row--1 {
  background: var(--color-gray-50, #f8fafc);
}

.coa-row--3 {
  background: var(--color-card-bg, #fff);
}

/*
 * Tombol expand/collapse.
 *
 * Desktop: `1.1rem` (~18px) cukup. Mouse bisa membidik 18px, dan di desktop
 * barisnya sendiri sudah tinggi ~44px, jadi area yang diklik sebenarnya
 * jauh lebih besar dari ikonnya.
 *
 * Tapi tinggi sebenarnya itu hasil `align-items: center` di `.coa-row`, bukan
 * sesuatu yang bisa diandalkan: begitu label akun membungkus dua baris atau
 * `.coa-row` dipadatkan, area sentuhnya ikut menyusut ke tinggi ikon. Jadi
 * yang diukur untuk target sentuh adalah tinggi ikonny, bukan tinggi baris --
 * dan 18px terlalu kecil untuk jari.
 *
 * Di `@media (pointer: coarse)` ukurannya jadi 2rem (32px) -- bukan 2.5rem
 * seperti tombol lain, karena kolom ini sudah sempit dan melebar 14px per
 * tingkat di layar 320px.
 */
.coa-toggle {
  width: 1.1rem;
  flex-shrink: 0;
  border: none;
  background: transparent;
  color: var(--color-gray-400, #94a3b8);
  cursor: pointer;
  padding: 0;
  font-size: 0.7rem;
}

.coa-toggle--leaf {
  display: inline-block;
  cursor: default;
}

.coa-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
  color: var(--color-gray-500, #6b7280);
  white-space: nowrap;
  min-width: 5.6rem;
}

.coa-name {
  flex: 1;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--color-text-main, #1e293b);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.coa-row--1 .coa-name {
  font-weight: 800;
}

.coa-row--3 .coa-name {
  font-weight: 500;
  font-size: 0.78rem;
}

.coa-mut {
  padding: 0.12rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.64rem;
  font-weight: 700;
  text-transform: lowercase;
  white-space: nowrap;
}

.coa-mut--debet {
  background: var(--color-green-50, #d1fae5);
  color: var(--color-green-800, #065f46);
}

.coa-mut--kredit {
  background: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
}

.coa-pos {
  font-size: 0.66rem;
  color: var(--color-gray-400, #94a3b8);
  white-space: nowrap;
}

/*
 * Aksi baris: tambah, ubah, hapus.
 *
 * `.coa-row__aksi` menggantikan `.coa-edit` yang dulu hanya ada satu tombol
 * di ujung baris. Sekarang ada tiga, dan masing-masing punya warna sendiri:
 * `--danger` hanya muncul di hover tombol hapus, bukan dari awal.
 *
 * Yang merah dari awal adalah kesalahan yang mahal di halaman seperti ini:
 * tombol hapus akan dibaca sebagai "hapus biasa saja" oleh orang yang belum
 * tahu akibatnya -- dan akibatnya di sini adalah menghapus akun di semua
 * lokasi.
 */
.coa-row__aksi {
  border: 1px solid var(--color-border, #e2e8f0);
  background: var(--color-card-bg, #fff);
  color: var(--color-gray-400, #94a3b8);
  border-radius: 6px;
  padding: 0.25rem 0.45rem;
  cursor: pointer;
  font-size: 0.68rem;
  flex-shrink: 0;
  transition: all 0.15s ease;
}

.coa-row__aksi:hover {
  color: var(--color-primary, #0ea5e9);
  border-color: var(--color-primary, #0ea5e9);
  background: var(--color-primary-light, #e0f2fe);
}

.coa-row__aksi--danger:hover {
  color: var(--color-red-600, #dc2626);
  border-color: var(--color-red-400, #f87171);
  background: var(--color-red-50, #fee2e2);
}

/*
 * Target sentuh.
 *
 * Dipisah dengan `@media (pointer: coarse)`, bukan `@media (max-width)`,
 * karena yang menentukan adalah cara orang berinteraksi, bukan
 * lebarnya layar. Tablet 1024px yang dipakai dengan jari harus mendapat
 * target yang sama dengan ponsel 360px, dan laptop 360px yang dipakai
 * dengan mouse tidak butuh tombol selebar 40px di baris yang sangat padat.
 *
 * 2.5rem (40px) untuk keduanya. Untuk .coa-toggle ukurannya 2rem, bukan
 * 2.5rem, karena kolom ini sudah sempit dan barisnya berisi lima elemen --
 * melebar 14px per tombol akan otomatis menggeser nama akun, bukan menambah ruang
 * untuknya.
 */
@media (pointer: coarse) {
  .coa-toggle {
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .coa-row__aksi {
    min-height: 2.5rem;
    min-width: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

/* ===== Status ===== */
.coa-state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin: 0;
  padding: 2.5rem 1rem;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.82rem;
  text-align: center;
}

.coa-state--error {
  color: var(--color-red-600, #dc2626);
}

.coa-state__icon {
  font-size: 1.8rem;
}

/* ===== Form ===== */
.coa-form {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.coa-form__scope {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0;
  padding: 0.6rem 0.75rem;
  background: var(--color-yellow-50, #fef3c7);
  border: 1px solid var(--color-yellow-100, #fde68a);
  border-radius: var(--border-radius-sm);
  font-size: 0.74rem;
  color: var(--color-yellow-800, #92400e);
  line-height: 1.5;
}

.coa-form__scope svg {
  margin-top: 0.15rem;
  flex-shrink: 0;
}

.coa-field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.coa-field > span {
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--color-gray-500, #6b7280);
}

/*
 * Isian form akun.
 *
 * `font-size: 16px` dengan alasan yang sama seperti kolom pencarian di atas:
 * iOS Safari melakukan zoom otomatis untuk input di bawah 16px saat difokuskan,
 * dan zoom itu tidak pernah kembali dengan sendirinya. Karena modal ini
 * mencapai form terpanjang di panel pusat (nama akun + kode + parent),
 * zoom di sini paling merusak: modal ikut ter-zoom dan kakinya keluar layar.
 *
 * `@media (pointer: fine)` mengembalikan ukuran yang lebih rapat di desktop,
 * supaya isian tidak terlihat lebih besar dari teks di sekitarnya ketika
 * dipakai dengan mouse.
 */
.coa-field input,
.coa-field select {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-main, #1e293b);
  background: var(--color-card-bg, #fff);
  outline: none;
}

@media (pointer: fine) {
  .coa-field input,
  .coa-field select {
    font-size: 0.82rem;
  }
}

.coa-field input:focus,
.coa-field select:focus {
  border-color: var(--color-primary, #0ea5e9);
}

.coa-field small {
  font-size: 0.68rem;
  color: var(--color-gray-400, #94a3b8);
  line-height: 1.45;
}

.coa-form__error {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0;
  padding: 0.55rem 0.7rem;
  border-radius: var(--border-radius-sm);
  background: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
  font-size: 0.74rem;
  line-height: 1.5;
}

.coa-form__error svg {
  margin-top: 0.15rem;
  flex-shrink: 0;
}

/* ===== Tombol ===== */
/*
 * `min-height: 2.5rem` (40px), sama seperti `.btn` di central-form.css.
 * Tanpa itu `padding: 0.5rem` + font 0.78rem hanya menghasilkan ~31px --
 * di bawah target sentuh yang dipakai platform mobile.
 *
 * Match dengan `.btn` itu disengaja: kedua kelas dipakai di kaki modal yang
 * sama (Struktur Akun vs Detail Tenant), dan tombol yang identik di dua
 * modal berbeda tidak boleh punya tinggi berbeda.
 */
.coa-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-height: 2.5rem;
  padding: 0.5rem 0.9rem;
  border-radius: var(--border-radius-sm);
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: var(--central-shadow-btn);
  transition: all 0.18s ease;
}

.coa-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.coa-btn--ghost {
  border: 1px solid var(--color-border, #e2e8f0);
  background: var(--color-card-bg, #fff);
  color: var(--color-neutral-600, #475569);
}

.coa-btn--ghost:hover:not(:disabled) {
  background: var(--color-gray-100, #f1f5f9);
  box-shadow: var(--central-shadow-btn-hover);
}

.coa-btn--primary {
  border: 1px solid var(--color-primary, #0ea5e9);
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  box-shadow: var(--central-shadow-btn-primary);
}

.coa-btn--primary:hover:not(:disabled) {
  background: var(--color-primary-dark, #0284c7);
  box-shadow: var(--central-shadow-btn-primary-hover);
}

/*
 * Tombol hapus.
 *
 * Merah, bukan abu-abu seperti tombol ghost. Bedanya bukan warna: tombol
 * hapus adalah satu-satunya aksi di dialog yang tidak bisa dibatalkan, dan
 * tombol yang bentuknya sama dengan "Batal" di sebelahnya -- lengkap
 * dengan jarak yang sama -- adalah cara tercepat membuat orang salah klik.
 * Yang ini menghapus permanen.
 */
.coa-btn--danger {
  border: 1px solid var(--color-red-600, #dc2626);
  background: var(--color-red-600, #dc2626);
  color: #fff;
  box-shadow: var(--central-shadow-btn-primary);
}

.coa-btn--danger:hover:not(:disabled) {
  background: var(--color-red-700, #b91c1c);
  border-color: var(--color-red-700, #b91c1c);
}

/* ===== Konfirmasi hapus ===== */

/*
 * Daftar akun level 4 yang ikut terhapus, dikelompokkan per lokasi.
 *
 * `overflow-x: auto` karena `kode_akun` tidak bisa membelah dan nama akun
 * bisa sepanjang 100 karakter. Tanpa itu, satu kode panjang membuat dialog
 * melebar melewati tepi layar dan bagian yang memuat "Hapus Permanen" ikut
 * terdorong keluar jangkauan -- persis tombol yang paling tidak boleh
 * sulit dijangkau di dialog ini.
 */
.coa-impact-list {
  list-style: none;
  margin: 0;
  padding: 0.6rem 0.75rem;
  background: var(--color-gray-50, #f8fafc);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  font-size: 0.72rem;
  color: var(--color-gray-700, #334155);
  max-height: 14rem;
  overflow-y: auto;
}

.coa-impact-list__loc + .coa-impact-list__loc {
  margin-top: 0.6rem;
  padding-top: 0.6rem;
  border-top: 1px solid var(--color-gray-200, #e5e7eb);
}

.coa-impact-list__slug {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
}

.coa-impact-list ul {
  list-style: disc;
  margin: 0.25rem 0 0;
  padding-left: 1.1rem;
}

.coa-impact-list code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.7rem;
}

.coa-form__scope--danger {
  background: var(--color-red-50, #fee2e2);
  border-color: var(--color-red-200, #fecaca);
  color: var(--color-red-800, #991b1b);
}

/*
 * Checkbox peringatan.
 *
 * `align-items: flex-start` supaya teks panjang di sebelahnya mulai dari
 * baris pertama, bukan ikut turun mengikuti kotak kecil di depan. Tanpa
 * itu, baris pertama terlihat turun beberapa piksel dan seluruh checkbox
 * terbaca salah kolom.
 */
.coa-check {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  font-size: 0.74rem;
  color: var(--color-gray-700, #334155);
  line-height: 1.5;
  cursor: pointer;
}

.coa-check input {
  margin-top: 0.15rem;
  flex-shrink: 0;
  width: 1rem;
  height: 1rem;
  cursor: pointer;
}

/* Tombol tambah di toolbar */
.coa-btn--tambah {
  flex-shrink: 0;
}

/*
 * Tombol "Simpan" -- abu, sama dengan aplikasi lokasi.
 *
 * `coa-btn--primary` (biru) tidak dipakai untuk simpan: panel pusat
 * menyimpan data seperti panel lokasi, dan tombol simpan yang berbeda
 * warna di dua panel yang berdampingan terlihat seperti dua aplikasi
 * berbeda. Lihat `.btn--save` di `central-form.css`.
 */
.coa-btn--save {
  border: 1px solid var(--central-btn-save);
  background: var(--central-btn-save);
  color: #fff;
  box-shadow: var(--central-shadow-btn-save);
}

.coa-btn--save:hover:not(:disabled) {
  background: var(--central-btn-save-hover);
  box-shadow: var(--central-shadow-btn-save-hover);
}

/* ===== Pesan melayang ===== */
/*
 * `min-width: 0` pada teks di dalamnya.
 *
 * `.coa-toast` adalah flex container, dan isinya adalah
 * `"<nama akun>" diperbarui. Berlaku untuk semua lokasi.` -- dengan nama
 * akun sepanjang 100 karakter (`maxlength` di isian form). Tanpa
 * `min-width: 0`, flex item punya `min-width: auto` yang berarti tidak boleh
 * menyusut di bawah `min-content`: satu kata panjang tanpa spasi membuat
 * toast melebar melewati tepi kiri layar, dan karena toast `fixed` di kanan,
 * tidak ada yang bisa menggulir untuk menemukan bagian yang keluar.
 *
 * `overflow-wrap: anywhere` menutup kasus kedua: nama akun yang memang
 * panjang dan tidak punya titik spasi.
 */
.coa-toast {
  position: fixed;
  bottom: 1.5rem;
  right: 1.5rem;
  z-index: 60;
  display: flex;
  align-items: center;
  gap: 0.55rem;
  max-width: 26rem;
  padding: 0.7rem 0.9rem;
  border-radius: var(--border-radius-md, 0.5rem);
  box-shadow: 0 10px 25px rgba(15, 23, 42, 0.15);
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.45;
}

.coa-toast > span {
  min-width: 0;
  overflow-wrap: anywhere;
}

.coa-toast--ok {
  background: var(--color-green-50, #d1fae5);
  color: var(--color-green-800, #065f46);
  border: 1px solid var(--color-green-200, #a7f3d0);
}

.coa-toast--info {
  background: var(--color-blue-50, #f0f9ff);
  color: var(--color-blue-800, #1e3a8a);
  border: 1px solid var(--color-blue-200, #bfdbfe);
}

.coa-toast__close {
  margin-left: auto;
  border: none;
  background: transparent;
  color: inherit;
  cursor: pointer;
  opacity: 0.6;
  padding: 0;
}

.coa-toast__close:hover {
  opacity: 1;
}

.coa-toast-enter-active,
.coa-toast-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}

.coa-toast-enter-from,
.coa-toast-leave-to {
  opacity: 0;
  transform: translateY(0.5rem);
}

@media (max-width: 640px) {
  /*
   * Batas 20rem dihapus di sini supaya kotak turun ke baris toolbar
   * sendiri yang membentang penuh -- bukan menggantung di tengah baris
   * dengan sisa ruang kosong di sebelah kanannya.
   */
  .coa-toolbar__search {
    max-width: none;
  }

  .coa-code {
    min-width: auto;
  }

  .coa-pos {
    display: none;
  }

  .coa-toast {
    left: 1rem;
    right: 1rem;
    max-width: none;
  }

  /*
   * Indentasi anak-anak dipangkas.
   *
   * `.coa-children` menambah 1.45rem margin + 1rem padding = ~39px per
   * tingkat. Akun level 3 dikubung tiga kali, jadi indentsinya saja
   * memakan 117px -- di 320px sisa untuk nama akun tinggal ~85px, dan
   * tombol edit di ujung baris terpotong.
   *
   * Indentasi ini informasi, bukan dekorasi: bentuknya hilang kalau
   * dihapus sepenuhnya. Yang dipangkas Separuhnya -- hubungan parent-child
   * masih terbaca, tapi tidak lagi memakan ruang yang dibutuhkan nama.
   * Garis putus-putus di kirinya tetap ada, jadi hierarkinya tetap bisa
   * dibaca tanpa memakai angka pixel.
   */
  .coa-children {
    margin-left: 0.5rem;
    padding-left: 0.55rem;
  }

  /* Padding baris ikut dipangkas: 1.25rem kiri-kanan = 40px, dan di 320px
     itu 14% dari lebar yang tersedia. */
  .coa-row {
    padding: 0.5rem 0.6rem;
    gap: 0.4rem;
  }
}
</style>
