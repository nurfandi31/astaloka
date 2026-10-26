/**
 * PEMINDAI TEKS RUSAK: karakter kontrol harus ketahuan.
 *
 * Latar Belakang
 * --------------
 * `scripts/check-text.mjs` sudah menjaga repo ini dari teks yang rusak --
 * huruf CJK nyasar di kalimat Indonesia, atau kata yang kehilangan beberapa
 * huruf di tengah.
 *
 * (Kata contoh yang rusak sengaja tidak ditulis di sini -- memuatnya akan
 * membuat pemindai melaporkan berkas test ini sendiri.)
 *
 * Tapi satu kelas kerusakan lolos: KARAKTER KONTROL.
 *
 * Commit `22abca2` menulis emoji (`\u{1F4CA}`, `\u{1F50D}`) sebagai UTF-8,
 * lalu dibaca sebagai CP1252, lalu ditulis lagi sebagai UTF-8. Hasilnya tiap
 * byte UTF-8 berubah jadi dua karakter CP1252, dan di antaranya ada byte
 * `0x8D` yang jadi `U+008D` -- karakter kontrol C1, bukan huruf.
 *
 * Yang membuatnya berbahaya: byte `0x8D` itu sendiri TIDAK PERNAH TAMPIL
 * di layar. Jadi orang yang membaca kode itu melihat `placeholder="” Cari
 * kode atau nama akun..."` -- sesuatu yang tampak seperti tanda kutip
 * aneh, bukan seperti byte yang salah.
 *
 * Akibatnya bukan tampilan yang aneh. Akibatnya PARSER yang gagal:
 *
 *     Parsing error: control-character-in-input-stream.
 *
 * `vue-eslint-parser` menolaknya. Dan `vite build` tetap hijau -- Vite
 * hanya membundle, string itu keluar ke bundle apa adanya, jadi build
 * tidak pernah menemukan masalahnya. Kalau lint tidak pernah dijalankan,
 * tidak ada yang pernah tahu.
 *
 * Test ini mengunci perbaikannya: pemindai harus melaporkan karakter
 * kontrol.
 */

import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

/** Lokasi skrip pemindai. */
const SKRIP = fileURLToPath(new URL('../scripts/check-text.mjs', import.meta.url))

/**
 * Jalankan pemindai pada satu folder.
 *
 * Dipanggil sebagai proses terpisah, bukan lewat import, karena skripnya
 * menjalankan pemindaian saat modulnya dievaluasi dan keluar dengan
 * `process.exit`. Yang diuji adalah perilaku yang dilihat CI.
 */
function pindai(folder) {
  try {
    const stdout = execFileSync(process.execPath, [SKRIP, folder], {
      encoding: 'utf-8',
      stdio: ['ignore', 'pipe', 'pipe'],
    })

    return { kode: 0, keluar: stdout }
  } catch (err) {
    return { kode: err.status, keluar: err.stdout ?? '', galat: err.stderr ?? '' }
  }
}

/**
 * Folder sementara berisi satu berkas.
 */
function folderDengan(isi, nama = 'contoh.vue') {
  const folder = mkdtempSync(join(tmpdir(), 'cek-teks-'))

  writeFileSync(join(folder, nama), isi, 'utf-8')

  return folder
}

/**
 * Character control C1 `U+008D`.
 *
 * Dibuat dari KODE, bukan ditulis langsung. Byte `0x8D` kalau ditulis
 * langsung ke dalam berkas test bisa hilang diam-diam -- dan kalau hilang,
 * test-nya jadi tidak memeriksa apa pun sambil tetap hijau.
 *
 * `String.fromCharCode(0x8d)` membuatnya selalu ada, berapa kali pun
 * berkasnya ditulis ulang.
 */
const U008D = String.fromCharCode(0x8d)

describe('pemandai teks rusak', () => {
  it('menLaporkankarakterKontrolC1', () => {
    // Berkas yang meniru kerusakan nyata: `U+008D` nyelip di dalam
    // atribut `placeholder`.
    const folder = folderDengan(
      [
        '<template>',
        `  <input placeholder="”${U008D} Cari kode atau nama akun..." />`,
        '</template>',
      ].join('\n'),
    )

    try {
      const { kode, keluar } = pindai(folder)

      assert.notEqual(kode, 0, `pemindai harus gagal, keluarannya:\n${keluar}`)

      assert.ok(
        keluar.includes('karakter kontrol'),
        `keluaran harus menyebut 'karakter kontrol', bukan:\n${keluar}`,
      )
    } finally {
      rmSync(folder, { recursive: true, force: true })
    }
  })

  it('melewatiBerkasYangBersih', () => {
    const folder = folderDengan(
      ['<template>', '  <input placeholder="Cari kode atau nama akun..." />', '</template>'].join(
        '\n',
      ),
    )

    try {
      const { kode, keluar } = pindai(folder)

      assert.equal(kode, 0, `pemindai harus lulus untuk berkas bersih, keluaran:\n${keluar}`)
    } finally {
      rmSync(folder, { recursive: true, force: true })
    }
  })

  it('tidakMenyebutDirinyaSendiri', () => {
    /*
     * Skripnya sendiri memuat pola `KONTROL` dan rentang karakter kontrol di
     * dalam comentario -- jadi kalau pemindai membaca berkasnya sendiri,
     * dia akan selalu melaporkan dirinya sendiri dan tidak pernah bisa dibuat
     * hijau.
     */
    const folder = folderDengan('placeholder="Cari kode atau nama akun..."\n', 'bersih.vue')

    try {
      const { kode, keluar } = pindai(folder)

      assert.equal(kode, 0, `berkas bersih harus lulus, keluaran:\n${keluar}`)
    } finally {
      rmSync(folder, { recursive: true, force: true })
    }
  })
})
