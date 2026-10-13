<template>
  <div class="central-layout" :class="{ 'central-layout--collapsed': !sidebarOpen }">
    <div
      class="central-layout__overlay"
      :class="{ 'is-open': mobileSidebarOpen }"
      @click="mobileSidebarOpen = false"
    ></div>

    <aside class="central-sidebar" :class="{ 'is-open': mobileSidebarOpen }">
      <div class="central-sidebar__brand">
        <div class="central-sidebar__mark">
          <font-awesome-icon icon="gem" />
        </div>
        <div v-if="sidebarOpen" class="central-sidebar__identity">
          <p class="central-sidebar__name">ASTA BRATA</p>
          <p class="central-sidebar__role">Panel Pusat</p>
        </div>
      </div>

      <button
        type="button"
        class="central-sidebar__collapse"
        :aria-label="sidebarOpen ? 'Ciutkan menu' : 'Buka menu'"
        @click="toggleSidebar"
      >
        <font-awesome-icon :icon="sidebarOpen ? 'angles-left' : 'angles-right'" />
      </button>

      <nav class="central-sidebar__nav">
        <router-link
          v-for="item in menuItems"
          :key="item.to"
          :to="item.to"
          class="central-sidebar__link"
          :class="{ 'is-active': isActive(item.to) }"
          :title="!sidebarOpen ? item.label : undefined"
          @click="mobileSidebarOpen = false"
        >
          <font-awesome-icon :icon="item.icon" class="central-sidebar__icon" />
          <span v-if="sidebarOpen" class="central-sidebar__label">{{ item.label }}</span>
        </router-link>
      </nav>

      <div class="central-sidebar__user" v-if="sidebarOpen">
        <div class="central-sidebar__avatar">
          {{ initials }}
        </div>
        <div class="central-sidebar__user-info">
          <p class="central-sidebar__user-name">{{ user?.name || 'Superadmin' }}</p>
          <p class="central-sidebar__user-mail">{{ user?.email }}</p>
        </div>
      </div>
    </aside>

    <div class="central-main">
      <header class="central-topnav">
        <button
          type="button"
          class="central-topnav__menu-btn"
          aria-label="Buka menu"
          @click="mobileSidebarOpen = true"
        >
          <font-awesome-icon icon="bars" />
        </button>

        <div class="central-topnav__title">
          <h1>{{ pageTitle }}</h1>
          <p>{{ pageSubtitle }}</p>
        </div>

        <div class="central-topnav__actions">
          <button
            type="button"
            class="central-topnav__btn"
            title="Muat ulang data"
            @click="emit('refresh')"
          >
            <font-awesome-icon icon="rotate" :spin="loading" />
          </button>

          <button type="button" class="central-topnav__logout" @click="handleLogout">
            <font-awesome-icon icon="right-from-bracket" />
            <span v-if="sidebarOpen">Keluar</span>
          </button>
        </div>
      </header>

      <main class="central-body">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MySwal } from '@/utils/swal'
import { centralAuthService } from '@/services/central.service'
import { getCentralUser } from '@/utils/centralAxios'
import { useUiStore } from '@/stores/uiStore'

const emit = defineEmits(['refresh'])

const route = useRoute()
const router = useRouter()
const uiStore = useUiStore()

const sidebarOpen = ref(true)
const mobileSidebarOpen = ref(false)

const loading = computed(() => uiStore.loading)
const user = computed(() => getCentralUser())

const menuItems = [
  { label: 'Dashboard', to: '/central', icon: 'gauge-high' },
  { label: 'Lokasi', to: '/central/lokasi', icon: 'location-dot' },
  { label: 'Tagihan', to: '/central/tagihan', icon: 'file-invoice-dollar' },
  { label: 'Audit', to: '/central/audit', icon: 'clipboard-list' },
]

const pageMeta = {
  '/central': {
    title: 'Dashboard Pusat',
    subtitle: 'Ringkasan seluruh lokasi ASTALOKA.',
  },
  '/central/lokasi': {
    title: 'Manajemen Lokasi',
    subtitle: 'Daftar, tambah, dan kelola seluruh lokasi terdaftar.',
  },
  '/central/tagihan': {
    title: 'Tagihan Langganan',
    subtitle: 'Terbitkan dan pantau tagihan langganan tiap lokasi.',
  },
  '/central/audit': {
    title: 'Jejak Audit',
    subtitle: 'Siapa mengubah apa, kapan. Log tidak bisa dihapus dari aplikasi.',
  },
}

const pageTitle = computed(() => pageMeta[route.path]?.title || 'Panel Pusat')
const pageSubtitle = computed(() => pageMeta[route.path]?.subtitle || '')

const initials = computed(() => {
  const name = user.value?.name || 'SA'
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('')
})

const isActive = (to) => (to === '/central' ? route.path === '/central' : route.path.startsWith(to))

const toggleSidebar = () => {
  sidebarOpen.value = !sidebarOpen.value
}

const handleLogout = async () => {
  const result = await MySwal.fire({
    title: 'Konfirmasi Logout',
    text: 'Keluar dari panel pusat?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Ya, keluar',
    cancelButtonText: 'Batal',
  })

  if (!result.isConfirmed) return

  // Sengaja ditelan: user harus tetap keluar dari panel meski server tidak
  // bisa dihubungi. Token server akan ditolak sendiri setelah kedaluwarsa.
  await centralAuthService.logout().catch(() => {})
  router.replace('/central/login?logout=1')
}
</script>

<style scoped>
/*
| Dimensi & warna diambil dari `main.css`, bukan angka sendiri.
|
| Panel pusat dan aplikasi lokasi dipakai bergantian oleh orang yang sama
| -- superadmin membuka panel pusat untuk mengecek tagihan, lalu masuk ke
| aplikasi lokasi untuk mengerjakan sesuatu. Kalau panelnya terlihat beda
| jauh, mereka merasa harus turun ke yang kedua, dan setelah beberapa kali
| berhenti memakai yang pertama.
|
| Sidebar sengaja mengikuti pola yang sudah ada di aplikasi lokasi:
| putih translucent + blur di atas konten, bukan panel gelap. Warna
| aksen diambil dari `--color-primary` (sky), sama seperti tombol dan
| link di seluruh aplikasi.
*/
.central-layout {
  --central-sidebar-width: 250px;
  --central-sidebar-collapsed: 68px;
  --central-topnav-height: 56px;

  display: flex;
  min-height: 100vh;
  background: var(--color-body-bg, #f1f5f9);
}

/* ===== SIDEBAR ===== */
.central-sidebar {
  width: var(--central-sidebar-width);
  flex-shrink: 0;
  /* Sama seperti `.sidebar-panel` di main.css: putih semi-transparan dengan
     blur, supaya konten di belakang tetap terbaca samar. */
  background: rgb(255 255 255 / 0.85);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-right: 1px solid var(--color-border, #e2e8f0);
  color: var(--color-text-sub, #64748b);
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 0;
  height: 100vh;
  z-index: 40;
  transition:
    width 0.22s ease,
    transform 0.22s ease;
}

.central-layout--collapsed .central-sidebar {
  width: var(--central-sidebar-collapsed);
}

.central-sidebar__brand {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem;
  border-bottom: 1px solid var(--color-gray-200, #e5e7eb);
}

.central-sidebar__mark {
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  border-radius: var(--border-radius-sm);
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.central-sidebar__identity {
  min-width: 0;
}

/* Gradien teks yang sama dengan `.sidebar-header-title` di main.css. */
.central-sidebar__name {
  font-size: 0.88rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  background: linear-gradient(to right, #1e293b, #0ea5e9);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  margin: 0;
}

.central-sidebar__role {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--color-primary, #0ea5e9);
  margin: 0.1rem 0 0;
}

.central-sidebar__collapse {
  align-self: flex-end;
  margin: 0.5rem 0.75rem 0;
  background: transparent;
  border: 1px solid var(--color-gray-200, #e5e7eb);
  color: var(--color-gray-400, #94a3b8);
  width: 1.75rem;
  height: 1.75rem;
  border-radius: var(--border-radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  transition: all 0.2s ease;
}

.central-sidebar__collapse:hover {
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
  border-color: var(--color-primary, #0ea5e9);
}

.central-sidebar__nav {
  flex: 1;
  padding: 1rem 0.65rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.central-sidebar__nav::-webkit-scrollbar {
  width: 6px;
}

.central-sidebar__nav::-webkit-scrollbar-thumb {
  background: rgb(14 165 233 / 0.3);
  border-radius: 3px;
}

.central-sidebar__link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.8rem;
  border-radius: var(--border-radius-sm);
  color: var(--color-text-sub, #64748b);
  text-decoration: none;
  font-size: 0.82rem;
  font-weight: 600;
  transition: all 0.18s ease;
  white-space: nowrap;
}

.central-sidebar__link:hover {
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
}

/* Aktif memakai warna solid, bukan gradien. Pola solid di sidebar aplikasi
   lokasi sudah jadi tanda yang dikenal, jadi ini bukan sekadar soal
   estetika -- ini supaya keduanya langsung terbaca sebagai "halaman yang
   sedang dibuka" saat keduanya terlihat bersamaan. */
.central-sidebar__link.is-active {
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  box-shadow: 0 6px 14px -6px rgb(14 165 233 / 0.7);
}

.central-sidebar__icon {
  width: 1.15rem;
  flex-shrink: 0;
  text-align: center;
}

.central-sidebar__user {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.9rem 1rem;
  border-top: 1px solid var(--color-gray-200, #e5e7eb);
}

.central-sidebar__avatar {
  width: 2.1rem;
  height: 2.1rem;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  font-size: 0.78rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}

.central-sidebar__user-info {
  min-width: 0;
}

.central-sidebar__user-name {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.central-sidebar__user-mail {
  font-size: 0.66rem;
  color: var(--color-text-sub, #64748b);
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== MAIN ===== */
.central-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

/* Topnav memakai tinggi yang sama dengan aplikasi lokasi (56px) supaya
   perpindahan panel <-> aplikasi tidak terasa seperti pindah aplikasi. */
.central-topnav {
  height: var(--central-topnav-height);
  background: var(--color-card-bg, #fff);
  border-bottom: 1px solid var(--color-border, #e2e8f0);
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0 1.5rem;
  position: sticky;
  top: 0;
  z-index: 30;
}

.central-topnav__menu-btn {
  display: none;
  background: none;
  border: none;
  color: var(--color-text-sub, #64748b);
  font-size: 1.1rem;
  padding: 0.4rem;
}

.central-topnav__title {
  flex: 1;
  min-width: 0;
}

.central-topnav__title h1 {
  font-size: 0.95rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.central-topnav__title p {
  font-size: 0.7rem;
  color: var(--color-text-sub, #64748b);
  margin: 0;
}

.central-topnav__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.central-topnav__btn,
.central-topnav__logout {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.4rem 0.65rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  background: var(--color-card-bg, #fff);
  color: var(--color-text-sub, #64748b);
  font-size: 0.76rem;
  font-weight: 600;
  transition: all 0.18s ease;
}

.central-topnav__btn:hover {
  background: var(--color-primary-light, #e0f2fe);
  border-color: var(--color-primary, #0ea5e9);
  color: var(--color-primary-dark, #0284c7);
}

.central-topnav__logout {
  background: var(--color-red-50, #fee2e2);
  border-color: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
}

.central-topnav__logout:hover {
  background: #fee2e2;
}

.central-body {
  flex: 1;
  padding: 1.25rem;
}

.central-layout__overlay {
  display: none;
}

@media (max-width: 1024px) {
  .central-sidebar {
    position: fixed;
    transform: translateX(-100%);
  }

  .central-sidebar.is-open {
    transform: translateX(0);
  }

  .central-layout__overlay {
    display: block;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.5);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.22s ease;
    z-index: 35;
  }

  .central-layout__overlay.is-open {
    opacity: 1;
    pointer-events: auto;
  }

  .central-topnav__menu-btn {
    display: flex;
  }

  .central-body {
    padding: 1rem;
  }
}
</style>
