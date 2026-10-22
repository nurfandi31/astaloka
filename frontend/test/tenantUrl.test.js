import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import { tenantHost, tenantUrl } from '../src/utils/tenantUrl.js'

/**
 * `tenantUrl()` menentukan tautan yang diklik admin di panel pusat.
 *
 * Yang diuji di sini adalah keputusan http/https. Versi sebelumnya menempel
 * `https://` langsung di template, jadi setiap domain development (`.test`,
 * `localhost`, IP jaringan lokal) menghasilkan tautan yang gagal sebelum
 * halaman sempat dimuat -- dan panel terlihat punya domain rusak padahal yang
 * rusak hanya tautannya.
 *
 * Jalankan: `node --test frontend/test/`
 */
describe('tenantUrl', () => {
  describe('domain produksi memakai https', () => {
    it('meng.https untuk domain publik', () => {
      assert.equal(tenantUrl('tirto-mulo.astaloka.co.id'), 'https://tirto-mulo.astaloka.co.id')
      assert.equal(tenantUrl('pamsides.co.id'), 'https://pamsides.co.id')
    })

    it('memakai huruf kecil pada host, bukan mengubahnya', () => {
      // Huruf besar di host adalah host yang BERBEDA secara DNS. Menurunkannya
      // di sini berarti admin diarahkan ke domain yang bukan yang diketik.
      assert.equal(tenantUrl('TirtoMulo.astaloka.co.id'), 'https://TirtoMulo.astaloka.co.id')
    })
  })

  describe('host development memakai http', () => {
    it('meng.http untuk TLD development', () => {
      assert.equal(tenantUrl('demo.astaloka.test'), 'http://demo.astaloka.test')
      assert.equal(tenantUrl('demo.astaloka.local'), 'http://demo.astaloka.local')
      assert.equal(tenantUrl('demo.astaloka.dev'), 'http://demo.astaloka.dev')
    })

    it('meng.http untuk IP jaringan lokal', () => {
      assert.equal(tenantUrl('192.168.1.10'), 'http://192.168.1.10')
      assert.equal(tenantUrl('10.0.0.5'), 'http://10.0.0.5')
      assert.equal(tenantUrl('localhost'), '')
    })

    it('tidak salah menilai 172.16 sampai 172.31 sebagai lokal', () => {
      // `172.` saja terlalu longgar: 172.32 ke atas itu IP publik, dan
      // salah assessingnya membuat tautan produksi jatuh ke http.
      assert.equal(tenantUrl('172.16.0.1'), 'http://172.16.0.1')
      assert.equal(tenantUrl('172.31.255.1'), 'http://172.31.255.1')
      assert.equal(tenantUrl('172.32.0.1'), 'https://172.32.0.1')
    })
  })

  describe('input yang tidak bisa jadi tautan', () => {
    it('wildcard tidak pernah jadi tautan', () => {
      // `*.astaloka.test` tidak punya host yang bisa diklik; mengarahkan
      // orang ke sana hanya menghasilkan error browser yang tidak
      // menjelaskan apa pun.
      assert.equal(tenantUrl('*.astaloka.test'), '')
      assert.equal(tenantUrl('*'), '')
    })

    it('host kosong tidak jadi tautan', () => {
      assert.equal(tenantUrl(''), '')
      assert.equal(tenantUrl(null), '')
      assert.equal(tenantUrl(undefined), '')
      assert.equal(tenantUrl('   '), '')
    })

    it('host tanpa titik tidak jadi tautan', () => {
      // Tidak punya DNS, jadi tidak akan pernah terjangkau.
      assert.equal(tenantUrl('srv01'), '')
    })

    it('skema yang sudah menempel tidak jadi dua kali', () => {
      assert.equal(tenantUrl('https://demo.astaloka.co.id'), 'https://demo.astaloka.co.id')
      assert.equal(tenantUrl('http://demo.astaloka.test'), 'http://demo.astaloka.test')
    })
  })

  it('tidak mengubah input aslinya', () => {
    const host = 'Demo.Astaloka.test'

    tenantUrl(host)

    assert.equal(host, 'Demo.Astaloka.test')
  })
})

describe('tenantHost', () => {
  it('melepas prefiks wildcard untuk ditampilkan', () => {
    assert.equal(tenantHost('*.astaloka.test'), 'astaloka.test')
  })

  it('membiarkan host biasa apa adanya', () => {
    assert.equal(tenantHost('demo.astaloka.test'), 'demo.astaloka.test')
    assert.equal(tenantHost(''), '')
    assert.equal(tenantHost(null), '')
  })
})