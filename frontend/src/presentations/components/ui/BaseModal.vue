<template>
  <Teleport to="body">
    <div v-if="show" class="base-modal" role="dialog" aria-modal="true" @click.self="emit('close')">
      <div class="base-modal__panel" :class="`base-modal__panel--${size}`">
        <header class="base-modal__head">
          <div class="base-modal__heading">
            <slot name="icon">
              <div class="base-modal__icon">
                <slot name="icon-glyph">
                  <font-awesome-icon :icon="icon" />
                </slot>
              </div>
            </slot>

            <div>
              <h3 class="base-modal__title">{{ title }}</h3>
              <p v-if="subtitle" class="base-modal__subtitle">{{ subtitle }}</p>
            </div>
          </div>

          <button
            type="button"
            class="base-modal__close"
            :aria-label="closeLabel"
            @click="emit('close')"
          >
            <font-awesome-icon icon="xmark" />
          </button>
        </header>

        <div class="base-modal__body">
          <slot />
        </div>

        <footer v-if="$slots.footer" class="base-modal__foot">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue'

const props = defineProps({
  /** Judul besar di header. Wajib -- modal tanpa judul tidak bisa ditutup oleh pembaca layar. */
  title: { type: String, required: true },

  subtitle: { type: String, default: '' },

  /** Ikon Font Awesome di sebelah kiri judul. */
  icon: { type: String, default: 'circle-info' },

  /** `sm` untuk konfirmasi, `md` default, `lg`/`xl` untuk tabel dan diff. */
  size: { type: String, default: 'md' },

  show: { type: Boolean, default: true },

  closeLabel: { type: String, default: 'Tutup' },
})

const emit = defineEmits(['close'])

/**
 * Escape menutup modal, dan scroll body dikunci selama modal terbuka.
 *
 * Tanpa yang kedua, modal yang lebih tinggi dari viewport membuat halaman
 * di belakangnya ikut ter-scroll saat roda mouse dipakai di dalam modal --
 * dan konten yang tadinya di luar layar tetap kelihatan kalau modalnya
 * semi-transparan. Untuk dialog yang menampilkan diff audit, itu membingungkan:
 * yang bergerak seharusnya dialognya, bukan isi halaman di belakangnya.
 */
const onKeydown = (e) => {
  if (e.key === 'Escape' && props.show) emit('close')
}

watch(
  () => props.show,
  (show) => {
    document.body.style.overflow = show ? 'hidden' : ''
  },
  { immediate: true },
)

window.addEventListener('keydown', onKeydown)
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<style scoped>
.base-modal {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.25rem;
  background: rgb(15 23 42 / 0.5);
  overflow-y: auto;
}

.base-modal__panel {
  width: 100%;
  max-height: calc(100vh - 2.5rem);
  display: flex;
  flex-direction: column;
  background: var(--color-card-bg, #fff);
  border-radius: var(--border-radius-lg);
  box-shadow: 0 20px 50px -12px rgb(15 23 42 / 0.35);
  overflow: hidden;
}

.base-modal__panel--sm {
  max-width: 26rem;
}

.base-modal__panel--md {
  max-width: 34rem;
}

.base-modal__panel--lg {
  max-width: 52rem;
}

.base-modal__panel--xl {
  max-width: 72rem;
}

.base-modal__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
  border-bottom: 1px solid var(--color-border, #e2e8f0);
}

.base-modal__heading {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.base-modal__icon {
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  border-radius: var(--border-radius-sm);
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
  display: flex;
  align-items: center;
  justify-content: center;
}

.base-modal__title {
  font-size: 0.95rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.base-modal__subtitle {
  font-size: 0.72rem;
  color: var(--color-text-sub, #64748b);
  margin: 0.1rem 0 0;
}

.base-modal__close {
  width: 1.9rem;
  height: 1.9rem;
  flex-shrink: 0;
  border: none;
  border-radius: var(--border-radius-sm);
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-gray-500, #6b7280);
  cursor: pointer;
  transition: all 0.18s ease;
}

.base-modal__close:hover {
  background: var(--color-gray-200, #e5e7eb);
  color: var(--color-text-main, #1e293b);
}

.base-modal__body {
  flex: 1;
  overflow-y: auto;
  padding: 1.1rem 1.25rem;
}

.base-modal__foot {
  padding: 0.85rem 1.25rem;
  border-top: 1px solid var(--color-border, #e2e8f0);
  background: var(--color-gray-100, #f1f5f9);
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.6rem;
}

@media (max-width: 640px) {
  .base-modal {
    padding: 0.75rem;
  }

  .base-modal__panel {
    max-height: calc(100vh - 1.5rem);
  }
}
</style>
