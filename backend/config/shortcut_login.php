<?php

/*
|--------------------------------------------------------------------------
| Login Pintasan (`/login/user`)
|--------------------------------------------------------------------------
|
| Konfigurasi halaman daftar-user yang bisa dipakai untuk langsung masuk
| sebagai salah satu user lokasi, tanpa mengetik email dan password.
|
| Halaman ini TIDAK memverifikasi password sama sekali -- dia menerbitkan
| token Sanctum untuk user yang dipilih. Pemanggil harus memahaminya
| sebagai pintasan, bukan halaman publik. Lihat `ShortcutLoginController`.
|
| CAKUP: SATU LOKASI
| --------------------
| Yang ditampilkan HANYA user dari lokasi yang sedang melayani request
| (`TenantManager::current()`), dibaca lewat koneksi `tenant` -- database
| milik lokasi itu. Tidak ada lokasi lain yang ikut dibaca, tidak ada
| registry pusat yang jadi sumber daftar.
|
| Dulu endpoint ini membuka satu koneksi per lokasi lalu mengembalikan semua
| lokasi sekaligus. Akibatnya satu request membaca banyak database, dan
| halaman yang dibuka di `demo.astaloka.test` bisa menampilkan -- lalu
| menerbitkan token -- untuk user di lokasi yang berbeda. Itu bukan hal
| yang bisa dibiarkan: user `id = 1` ada di setiap lokasi, jadi nama yang
| tampil tidak otomatis menunjuk akun yang sama.
|
| Sekarang daftar dan login sama-sama terikat pada satu lokasi. Kalau tidak
| ada lokasi yang ter-resolve, jawabannya 404 `NO_TENANT` -- bukan daftar
| kosong, karena daftar kosong berarti "lokasi ini tidak punya user", yang
| kesimpulannya berbeda.
|
| `enabled`
| ---------
| `true`  -> halaman dan endpoint-nya hidup di semua environment.
| `false` -> keduanya membalas 404, route-nya tetap terdaftar supaya
|            localhost tidak ikut hilang kalau lupa menyalakannya.
|
| `reveal_password`
| ------------------
| Halaman menampilkan teks password apa adanya kalau password user cocok
| dengan salah satu password demo di bawah. Ini satu-satunya cara menampilkan
| password yang bisa dipercaya: kolom `users.password` berisi hash bcrypt yang
| bersifat satu arah dan tidak bisa dibaca balik -- termasuk oleh kode ini.
|
| Password yang sudah diganti user TIDAK bisa ditampilkan, dan baris itu
| menampilkan `••••••` di kolom password. Tombol "Masuk Dashboard" tetap
| bekerja untuk semua user, karena pintasan ini memang tidak memakai
| password sama sekali.
|
| Set `false` kalau daftar ini tidak boleh menampilkan teks password sama
| sekali -- kolomnya tetap ada, tapi isinya selalu tersamar.
|
*/

return [

    /*
     * Default-nya SEKALI INI adalah `true` untuk semua environment, termasuk
     * produksi. Itu berarti cukup lupa mengetik satu baris di `.env` untuk
     * menerbitkan endpoint yang memberi token Sanctum untuk user mana pun --
     * termasuk `admin` -- tanpa password, ke siapa pun yang tahu URL-nya.
     *
     * Hanya ada satu cara salah di sini, dan yang hilang jokingannya besar:
     * aplikasi yang benar-benar dipakai orang.
     *
     * Jadi nilai yang TIDAK ditulis eksplisit sekarang berarti "hanya
     * environment pengembangan". Produksi dan staging harus menyalakannya
     * sendiri lewat `SHORTCUT_LOGIN_ENABLED=true`. Pesan 404-nya sudah
     * menyebutkan perintahnya, jadi menyalakannya tidak butuh tebakan.
     */
    'enabled' => filter_var(
        env('SHORTCUT_LOGIN_ENABLED', env('APP_ENV', 'production') !== 'production' ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
     * `reveal_password` mengikuti aturan yang sama: tidak aktif di produksi
     * kecuali diminta. Yang ditampilkan halaman ini adalah teks password apa
     * adanya untuk akun yang passwordnya memang lemah dan sudah tertulis di
     * README -- jadi kalau bocor di produksi, itu daftar akun yang siap dicoba.
     */
    'reveal_password' => filter_var(
        env('SHORTCUT_LOGIN_REVEAL_PASSWORD', env('APP_ENV', 'production') !== 'production' ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
     * Password demo yang boleh ditampilkan apa adanya.
     *
     * Daftar, bukan satu string: seeder memakai beberapa nilai berbeda
     * (`password`, `rahasia123`, `legacy`, dan hash lama yang diimpor apa
     * adanya), jadi hanya satu nilai yang akan menutupi hampir semua baris.
     *
     * Menambah nilai di sini berarti menambah satu tebakan yang lagi-lagi
     * bisa dicoba sebagai password. Jangan menambah nilai yang lemah hanya
     * karena kolomnya jadi lebih banyak terisi.
     */
    'demo_passwords' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SHORTCUT_LOGIN_DEMO_PASSWORDS', 'password,rahasia123,legacy')),
    ))),

    /*
     * Berapa banyak user LOKASI INI yang ditampilkan.
     *
     * Endpoint-nya terbuka tanpa autentikasi, jadi daftar lengkap user
     * lokasi bisa jadi besar. Batas ini membuat jawaban selalu punya
     * ukuran, bukan ikut membesar bersama jumlah akun.
     *
     * Dulu nilainya bernama `per_tenant_limit` dan diberi batas jumlah lokasi
     * (`max_tenants`) karena satu request memang menyentuh banyak database.
     * Sekarang hanya satu database yang dibaca, jadi batas lokasi sudah tidak
     * relevan -- dan sengaja tidak ada fallback ke key lama: key yang tidak
     * lagi dibaca lebih baik daripada key yang diam-diam diabaikan.
     */
    'per_location_limit' => (int) env('SHORTCUT_LOGIN_PER_LOCATION_LIMIT', 100),

];
