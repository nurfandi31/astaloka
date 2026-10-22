<template>
  <div
    class="currency-input"
    :class="{
      'mb-2': !noMargin,
      'currency-input--sm': size === 'sm',
      'currency-input--error': error,
    }"
  >
    <label v-if="label" class="currency-label">{{ label }}</label>
    <div class="currency-input-wrapper">
      <span v-if="!hidePrefix" class="currency-prefix">Rp.</span>
      <InputNumber
        v-model="internalValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        mode="decimal"
        locale="id-ID"
        :minFractionDigits="2"
        :maxFractionDigits="2"
        :min="0"
        class="custom-input-number"
        @input="handleInput"
        @blur="handleBlur"
        @focus="handleFocus"
      />
    </div>

    <!--
      Error mengalahkan helper, bukan tampil berdampingan.

      Dua pesan di bawah satu input yang sama selalu bertentangan: kalau
      field-nya ditolak karena tidak valid, catatan helper tidak boleh
      tetap terlihat seolah-olah hal itu hanya informasi tambahan.
    -->
    <div v-if="error" class="error-text">{{ error }}</div>
    <div v-else-if="showHelper && helperText" class="helper-text">{{ helperText }}</div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import InputNumber from 'primevue/inputnumber'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  label: { type: String, default: '' },
  placeholder: { type: String, default: '0,00' },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  maxValue: { type: Number, default: null },
  showHelper: { type: Boolean, default: false },
  helperText: { type: String, default: '' },
  /* Pesan validasi. Ditambahkan karena MaksMoneyInput dipakai berdampingan
     dengan BaseInput di modal yang sama, dan keduanya harus menampilkan
     error dengan gaya yang sama -- kalau tidak, isian yang berderet
     terlihat berasal dari dua form berbeda. */
  error: { type: String, default: '' },
  noMargin: { type: Boolean, default: false },
  size: { type: String, default: 'normal', validator: (v) => ['normal', 'sm'].includes(v) },
  hidePrefix: { type: Boolean, default: false },
  /* Apakah isian kosong dianggap 0 atau tetap kosong. Lihat catatan di
     `toNum()` -- ini yang membedakan kolom yang wajib diisi dari kolom
     yang kosong berarti "biarkan sistem yang menghitung". */
  emptyAsZero: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'change'])

const internalValue = ref(props.modelValue ?? null)

// Sync dari luar ke dalam
watch(
  () => props.modelValue,
  (val) => {
    if (val !== internalValue.value) internalValue.value = val ?? null
  },
)

/*
 * Ubah nilai input menjadi angka untuk dikirim ke parent.
 *
 * Isian kosong dikonversi jadi 0 HANYA kalau `emptyAsZero` menyala
 * (bawaan), karena hampir semua pemakai memperlakukan nominal kosong
 * sebagai nol, dan `null` akan membuat `NaN` di perhitungan milik parent.
 *
 * `emptyAsZero` dimatikan untuk kolom yang boleh kosong, di mana kosong
 * berarti "biarkan sistem yang mengisi". Contohnya kolom `jumlah` per item
 * tagihan: kalau kosong ikut jadi 0, baris itu terkirim sebagai nominal
 * nol dan melanggar aturan backend bahwa total item harus sama dengan
 * total tagihan -- jadi bentuk kosongnya justru harus bertahan.
 */
function toNum(v) {
  if (v === null || v === undefined || v === '') return props.emptyAsZero ? 0 : null

  const n = typeof v === 'number' ? v : Number(String(v).replace(/\./g, '').replace(',', '.'))
  return Number.isFinite(n) ? n : props.emptyAsZero ? 0 : null
}

function handleInput(e) {
  emit('update:modelValue', toNum(e.value))
}

function handleBlur() {
  const v = toNum(internalValue.value)
  internalValue.value = v
  emit('update:modelValue', v)
  emit('change', v)
}

function handleFocus() {}
</script>

<style scoped>
@reference "@/assets/css/main.css";

.currency-input {
  @apply w-full transition-all duration-300;
}

.currency-label {
  @apply block text-sm font-normal text-slate-500 mb-1.5 ml-1;
}

.currency-input-wrapper {
  @apply relative flex items-center;
}

.currency-prefix {
  @apply absolute left-4 text-slate-400 text-sm font-medium z-10;
}

.currency-input-wrapper:not(:has(.currency-prefix)) :deep(.p-inputnumber-input) {
  padding-left: 1rem !important;
}

:deep(.p-inputnumber) {
  @apply w-full;
}

:deep(.p-inputnumber-input) {
  display: block !important;
  width: 100% !important;
  padding: 0 1rem !important;
  background-color: #f8fafc !important;
  border: 1px solid #e2e8f0 !important;
  border-radius: 0.75rem !important;
  font-size: 0.875rem !important;
  font-weight: 400 !important;
  color: #334155 !important;
  transition: all 0.3s !important;
  outline: none !important;
  /* Tinggi input WAJIB ditulis eksplisit, jangan lewat CSS v-bind().
     v-bind() di dalam <style scoped> menghasilkan nama variabel ber-hash
     yang dihitung terpisah oleh kompilator CSS dan kompilator script. Di
     build production kedua hash itu bisa berbeda, sehingga
     `height: var(--<hash>)` tidak pernah berisi nilai apa pun dan aturan
     height sama sekali tidak berlaku. Akibatnya hanya sisa padding + border
     yang menentukan tinggi (~19px), dan input terlihat gepeng/kecil.
     Class `.currency-input--sm` di bawah dipakai sebagai gantinya. */
  height: 2.75rem !important;
}

/* Varian kecil untuk `size="sm"` (2.25rem), lihat catatan di atas. */
.currency-input--sm :deep(.p-inputnumber-input) {
  height: 2.25rem !important;
}
:deep(.p-inputnumber-input::placeholder) {
  color: #94a3b8 !important;
  font-weight: 400 !important;
}
:deep(.p-inputnumber-input:hover) {
  border-color: var(--color-primary-400, #60a5fa) !important;
  background-color: #ffffff !important;
  box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.05) !important;
}
:deep(.p-inputnumber-input:focus),
:deep(.p-inputnumber.p-focus > .p-inputnumber-input) {
  border-color: var(--color-primary, #3b82f6) !important;
  box-shadow:
    0 0 0 4px rgba(59, 130, 246, 0.1),
    0 10px 15px -3px rgba(59, 130, 246, 0.05) !important;
  background-color: #ffffff !important;
}

:deep(.p-inputnumber-input:disabled) {
  @apply bg-slate-100 text-slate-400 cursor-not-allowed border-slate-200 shadow-none hover:border-slate-200 hover:shadow-none!;
}

.helper-text {
  @apply mt-1.5 ml-1 text-xs text-slate-400;
}

/* Samakan dengan `.base-input__error` milik BaseInput: modal tagihan
   mencampur keduanya dalam satu form, dan dua isian bersebelahan yang
   kesalahannya tampil dengan gaya berbeda jauh lebih mengganggu daripada
   pesan itu sendiri. */
.error-text {
  @apply mt-1.5 ml-1 text-xs text-red-500;
}
</style>
