<script setup>
import { watch, onMounted, onBeforeUnmount } from 'vue'
import { RouterView } from 'vue-router'
import { useUiStore } from '@/stores/uiStore'
import { useToast } from 'primevue/usetoast'

const uiStore = useUiStore()
const toast = useToast()

// Watch for global toast messages
watch(
  () => uiStore.toastMessage,
  (newMsg) => {
    if (newMsg) {
      toast.add(newMsg)
    }
  },
)

// Fix PrimeVue toast aria-hidden retaining focus issue
let toastObserver = null
const fixToastAriaHidden = () => {
  document.querySelectorAll('.p-toast[aria-hidden="true"]').forEach((el) => {
    if (el.querySelector(':focus')) {
      el.removeAttribute('aria-hidden')
    }
  })
}
const handleFocusIn = (e) => {
  const toastRoot = e.target?.closest?.('.p-toast')
  if (toastRoot) {
    toastRoot.removeAttribute('aria-hidden')
  }
}
onMounted(() => {
  document.addEventListener('focusin', handleFocusIn)
  toastObserver = new MutationObserver(fixToastAriaHidden)
  toastObserver.observe(document.body, {
    attributes: true,
    attributeFilter: ['aria-hidden'],
    subtree: true,
  })
})
onBeforeUnmount(() => {
  document.removeEventListener('focusin', handleFocusIn)
  toastObserver?.disconnect()
})
</script>

<template>
  <div id="app-root">
    <PrimeToast />
    <div v-if="uiStore.loading" class="global-loader">
      <ProgressBar mode="indeterminate" style="height: 4px" />
    </div>
    <RouterView />
  </div>
</template>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

html,
body {
  height: 100%;
  width: 100%;
  overflow-x: hidden;
}

body {
  font-family:
    'Inter',
    -apple-system,
    BlinkMacSystemFont,
    'Segoe UI',
    Roboto,
    sans-serif;
}

#app,
#app-root {
  height: 100%;
  width: 100%;
  min-width: 100%;
}

.swal-progress-success {
  background: #22c55e !important;
}
.swal-toast-custom .swal2-title {
  margin-bottom: 0 !important;
}

.swal-toast-custom .swal2-html-container {
  margin-top: 4px !important;
}
.global-loader {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
}

/*
| Lebar notifikasi (toast) dibatasi layar, bukan angka tetap.
|
| PrimeVue menempelkan `position: fixed; top: 20px; right: 20px` sebagai
| GAYA INLINE pada elemennya, dan memberi lebar tetap 400px lewat
| `--p-toast-width`. Gaya inline menang atas stylesheet biasa, jadi
| `max-width` di sini tetap berlaku -- tetapi TIDAK bisa mengubah `right`
| atau lebar 400px-nya.
|
| Akibatnya di layar yang lebih sempit dari 440px (400px + 20px + 20px),
| notifikasi melimpah ke kiri. Diukur di 375px: kotaknya membentang dari
| -45px sampai 355px, jadi 45px -- bagian paling kiri, tempat letak ikon --
| terpotong. Dan ini bukan kasus pinggiran: setiap HP modern punya lebar
| di bawah 440px, jadi setiap notifikasi di aplikasi ini terpotong.
|
| Yang perlu dijaga hanya lebarnya. Batasnya `calc(100vw - 2rem)` karena
| PrimeVue memberi 20px sisipan di kanan; dengan begitu tepi kirinya
| selalu mulai di >= 0 dan tidak ada satu pixel pun yang hilang.
|
| Sengaja TIDAK memakai media query: aturan ini benar pada lebar berapa
| pun, termasuk 320px, dan menaruhnya di `@media (max-width: 26rem)` akan
| menyisakan lebar 400px yang sama persis di 400px sampai 439px.
*/
.p-toast {
  max-width: calc(100vw - 2rem);
}
</style>
