import Swal from 'sweetalert2'

/*
 * Warna tombol dialog TIDAK ditulis di sini.
 *
 * Nilai `confirmButtonColor` di bawah dievaluasi sekali saat modul ini
 * diimpor -- dan saat itu branding belum dimuat, jadi nilainya pasti warna
 * bawaan. Semua dialog yang dibuka belakangan akan punya tombol biru yang
 * tidak pernah ikut berubah.
 *
 * Warnanya sekarang datang dari CSS: `.swal2-confirm` di `main.css` memakai
 * `!important`, jadi penimpaan ini menang atas inline style dari SweetAlert2.
 * Dengan begitu warnanya selalu mengikuti lokasi, tanpa perlu dievaluasi ulang
 * tiap dialog, dan tanpa risiko warna yang terkunci di nilai import.
 */
export const swalOptions = {
  confirmButtonColor: 'var(--color-primary, #3b82f6)',
  cancelButtonColor: 'var(--color-danger, #ef4444)',
}

export const MySwal = Swal.mixin(swalOptions)

export const Toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 3000,
  timerProgressBar: true,
  customClass: {
    popup: 'swal-toast-custom',
    title: 'swal-toast-title',
  },
  didOpen: (toast) => {
    toast.addEventListener('mouseenter', Swal.stopTimer)
    toast.addEventListener('mouseleave', Swal.resumeTimer)
  },
})

export const showSuccessToast = (title = 'Berhasil') => {
  return Toast.fire({
    icon: 'success',
    title,
    timer: 3000,
  })
}

export const showErrorToast = (error) => {
  const message = error?.response?.data?.message || error?.message || 'Terjadi kesalahan'
  return Toast.fire({
    icon: 'error',
    title: message,
    timer: 4000,
  })
}

export const showWarningToast = (title = 'Peringatan') => {
  return Toast.fire({
    icon: 'warning',
    title,
    timer: 3500,
  })
}

export const showInfoToast = (title = 'Informasi') => {
  return Toast.fire({
    icon: 'info',
    title,
    timer: 3000,
  })
}
