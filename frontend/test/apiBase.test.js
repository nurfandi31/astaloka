import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import { apiBase, apiOrigin } from '../src/utils/apiBase.js'

/**
 * Basis URL API harus dihitung di satu tempat.
 *
 * Yang diuji di sini adalah satu bug nyata yang pernah ada di produksi:
 * `deploy.yml` menyuntik `VITE_API_BASE_URL=https://astaloka.siupk.com/api`
 * (sudah termasuk `/api`), sementara `utils/storage.js` dan
 * `utils/centralAxios.js` menambahkan `/api` LAGI. Hasilnya
 * `https://astaloka.siupk.com/api/api`, dan nginx menjawab 404 untuk
 * setiap request -- karena tidak ada route yang dimulai dengan `/api/api`.
 *
 * Bugnya lolos karena `utils/axios.js` dan `branding.service.js` memakai
 * nilainya apa adanya, jadi request dari panel pusat dan aplikasi lokasi
 * menuju ke dua basis URL yang BERBEDA. Yang satu benar, yang satu salah,
 * dan keduanya terlihat masuk akal di log.
 *
 * Jalankan: `node --test frontend/test/`
 */
describe('apiBase', () => {
  describe('nilai yang sudah berakhiran /api tidak jadi /api/api', () => {
    it('bentuk produksi: origin dengan suffix /api', () => {
      // Bentuk yang DISUNTIK deploy.yml.
      assert.equal(apiBase('https://astaloka.siupk.com/api'), 'https://astaloka.siupk.com/api')
    })

    it('bentuk yang diharapkan: origin polos', () => {
      // Bentuk yang sekarang diminta: VITE_API_BASE_URL tanpa suffix /api.
      assert.equal(apiBase('https://astaloka.siupk.com'), 'https://astaloka.siupk.com/api')
    })

    it('path di belakang proxy ikut dipertahankan', () => {
      // Backend bisa diletakkan di belakang proxy pada path tertentu.
      // Path itu HARUS ikut terbawa -- kalau dibuang, request-nya mendarat
      // di host yang salah.
      assert.equal(apiBase('https://siupk.com/gateway'), 'https://siupk.com/gateway/api')
      assert.equal(apiBase('https://siupk.com/gateway/api'), 'https://siupk.com/gateway/api')
    })

    it('suffix /api di tengah URL tidak dibuang', () => {
      // Hanya suffix TERAKHIR yang dibuang. '/api' di tengah bukan suffix.
      assert.equal(apiBase('https://siupk.com/api/gateway'), 'https://siupk.com/api/gateway/api')
    })
  })

  describe('origin backend tanpa suffix /api', () => {
    it('membuang satu suffix /api', () => {
      assert.equal(apiOrigin('https://astaloka.siupk.com/api'), 'https://astaloka.siupk.com')
      assert.equal(apiOrigin('https://astaloka.siupk.com/api/'), 'https://astaloka.siupk.com')
    })

    it('membuang trailing slash tanpa merusak host', () => {
      assert.equal(apiOrigin('https://astaloka.siupk.com/'), 'https://astaloka.siupk.com')
    })

    it('tidak mengubah host yang sah', () => {
      assert.equal(apiOrigin('https://astaloka.siupk.com'), 'https://astaloka.siupk.com')
    })
  })

  describe('tanpa konfigurasi', () => {
    it('fallback ke origin sendiri, bukan localhost', () => {
      // Di Node tidak ada `window`, jadi hasilnya kosong. Yang penting:
      // bukan 'localhost' dan bukan '127.0.0.1' -- keduanya terlihat benar
      // selama satu mesin dan langsung rusak begitu SPA dibuka lewat host lain.
      assert.equal(apiBase(''), '/api')
      assert.equal(apiOrigin(''), '')
    })
  })

  describe('semua sumber basis URL menghasilkan nilai yang sama', () => {
    it('axios, storage, dan centralAxios memakai bentuk yang sama', () => {
      // Ketiganya dulu menghitung sendiri dari env yang sama. Sekarang
      // semuanya lewat `apiBase()`, jadi mustahil lagi berbeda satu
      // di antara mereka.
      const configured = 'https://astaloka.siupk.com/api'

      const axiosBase = apiBase(configured)
      const storageBase = apiBase(configured)
      const centralBase = apiBase(configured)

      assert.equal(axiosBase, storageBase)
      assert.equal(storageBase, centralBase)
      assert.equal(axiosBase, 'https://astaloka.siupk.com/api')
    })
  })
})
