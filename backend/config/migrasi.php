<?php

/*
|--------------------------------------------------------------------------
| Batas & nama halaman Migrasi Data
|--------------------------------------------------------------------------
|
| Dipisah dari `MigrationPipeline` supaya nilainya bisa diubah per
| lingkungan lewat `.env` tanpa menyentuh kode, dan supaya controller
| bisa membacanya tanpa harus membuat objek pipeline dulu.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Batas ukuran file
    |----------------------------------------------------------------------
    |
    | Kb. File migrasi adalah daftar orang -- NIK, nama, alamat, telepon --
    | dan satu file 10 MB sudah cukup untuk sekitar 50.000 baris, yang
    | jauh melebihi kebutuhan satu lokasi. Batas yang lebih besar tidak
    | menambah kapasitas, hanya memperbesar risiko kehabisan memori saat
    | dibaca.
    |
    */
    'maks_kilobyte' => (int) env('MIGRASI_MAKS_KB', 10 * 1024),

    /*
    |----------------------------------------------------------------------
    | Template tanpa dropdown
    |----------------------------------------------------------------------
    |
    | Kolom `enum` punya daftar tetap, jadi bisa dipasang sebagai
    | dropdown di Excel. Kolom yang nilainya dibaca dari database lokasi
    | (paket, desa, jabatan) TIDAK bisa: daftar itu bisa berubah di
    | tengah -- admin lokasi menambah paket setelah template diunduh --
    | dan dropdown yang usang akan menolak nilai yang sebenarnya valid.
    |
    | Daftar lengkapnya ditulis di sheet "Referensi", dan nilainya selalu
    | diperiksa ulang saat validasi.
    |
    */
    'dropdown_dari_database' => false,

];
