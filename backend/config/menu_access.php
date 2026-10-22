<?php

/*
|--------------------------------------------------------------------------
| Katalog Menu Lokasi
|--------------------------------------------------------------------------
|
| SATU-SATUNYA sumber kebenaran tentang menu aplikasi lokasi: key, label,
| ikon, group, dan prefix API yang dikuasainya.
|
| Daftar ini TIDAK ditulis ulang di frontend. `SidebarView.vue` menarik
| katalog ini lewat `GET /api/menu-access/catalog`, lalu hanya menambahkan
| urutan tampilannya.
|
| Kenapa harus satu sumber: backend memblokir endpoint yang tidak
| diizinkan, frontend menyembunyikan menunya. Kalau keduanya punya daftar
| sendiri, keduanya pasti akan berbeda pada suatu saat, dan gejalanya
| sulit dilacak -- menu disembunyikan tapi endpoint-nya masih terbuka
| (user buka lewat URL), atau endpoint diblokir tapi menunya tetap tampil
| (user melihat menu yang selalu error).
|
| Isi `items` sengaja MIRIP dengan susunan `menuItems` di SidebarView
| yang lama, ditambah satu menu baru (Hak Akses) dan halaman Arsip yang
| ada di router tapi tidak pernah muncul di sidebar.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Menu yang tidak bisa dikunci
    |--------------------------------------------------------------------------
    |
    | Dipakai sebagai penopan struktur: `saveForJabatan()` selalu menulis
    | key ini apa pun yang dikirim admin, supaya jabatan yang sudah
    | dikonfigurasi tidak pernah punya NOL BARIS di tabel.
    |
    | `dashboard` TIDAK ada di katalog items lagi -- ia sengaja bukan menu
    | (lihat catatan panjang di atas `items`). Jadi untuk sekarang daftar
    | ini kosong: tidak ada menu admin yang dipaksa selalu terbuka.
    |
    | Kalau suatu saat butuh menu paksa, isilah dengan key yang ADA di
    | `items` di bawah. Key yang tidak ada di katalog tidak akan berpengaruh
    | apa pun: `allowedKeysFor()` memfilternya terhadap daftar key katalog.
    |
    */
    'always' => [],

    /*
    |--------------------------------------------------------------------------
    | Role yang ikut aturan ini
    |--------------------------------------------------------------------------
    |
    | KEEMPAT role, bukan hanya `admin`.
    |
    | Sebelumnya daftar ini hanya berisi `admin`, dengan alasan bahwa role
    | lain sudah dijaga `CheckRole` dan punya sidebar sendiri. Alasan itu
    | benar untuk MENGAKALI yang sudah ada, tapi keliru untuk MEMBAGIKAN:
    | surveyor punya satu menu tanpa pilihan, dan tidak ada cara memberi
    | seorang surveyor seluruh pekerjaan lapangan tanpa membuat role baru.
    |
    | Jabatan menutup jurang itu tanpa menyentuh routing. User tetap
    | `surveyor` -- semua endpoint miliknya tetap boleh oleh `CheckRole` --
    | tapi menu yang tampil dan endpoint yang boleh dipanggil bisa dibatasi
    | lewat jabatan.
    |
    | Yang TIDAK berubah: kolom `roles` di setiap entri `items` di bawah.
    | Kolom itu tetap BATAS ATAS -- jabatan hanya boleh MENYUSUT dari situ,
    | tidak pernah memberi akses ke menu milik role lain. Memberi teknisi
    | akses ke menu admin tetap urusan role, bukan jabatan.
    |
    | Konsekuensi yang harus diketahui: sekarang jabatan MELETAKSI aturan,
    | bukan hanya membagi. Jabatan yang belum pernah dikonfigurasi tetap
    | mendapat semua menu miliknya (lihat aturan 4 di `MenuAccessService`),
    | jadi mengaktifkan ini tidak mengunci siapa pun secara diam-diam.
    |
    */
    'enforce_roles' => ['admin', 'surveyor', 'teknisi', 'pelanggan'],

    /*
    |--------------------------------------------------------------------------
    | Jabatan -> role
    |--------------------------------------------------------------------------
    |
    | Menentukan menu mana yang DITAWARKAN untuk sebuah jabatan, dan
    | membatasi hak aksesnya ke menu itu saja.
    |
    | Default-nya adalah `admin`: jabatan baru apa pun yang belum disebut di
    | sini -- Bendahara, Sekretaris, dan lain-lain -- otomatis mendapat menu
    | admin. Itu yang diminta: jabatan tambahan umumnya bekerja di belakang
    | layar, bukan di lapangan.
    |
    | PENCOCOKAN TIDAK ADA BUATANNYA: nama jabatan dicocokkan dengan
    | `trim()` + `strtolower()`, jadi "Caters", "cater", dan "CATER" semua
    | jatuh ke baris yang sama. Jabatan yang tidak disebut pun tidak
    | menggagalkan -- ia jatuh ke `jabatan_role_fallback`.
    |
    | `Cater` dan `Teknisi` diarahkan ke `teknisi` dengan sengaja: keduanya
    | satu pekerjaan di satu tempat -- mencatat meter dan menanggung jawab
    | instalasi. Kalau dipisah, keduanya jadi dua daftar menu yang isinya
    | sama dan akan selalu berbeda pada suatu saat.
    |
    | Tiga baris pertama (admin, Direktur, Bendahara) sengaja ditulis
    | eksplisit meski jatuh ke nilai default yang sama, karena daftar ini
    | adalah tempat satu-satunya yang terbaca untuk pertanyaan "jabatan mana
    | yang ikut menu admin".
    |
    */
    'jabatan_roles' => [
        'admin'       => 'admin',
        'direktur'    => 'admin',
        'sekretaris'  => 'admin',
        'bendahara'   => 'admin',
        'pengawas'    => 'admin',
        'pos bayar'   => 'admin',
        'ketua'       => 'admin',

        'surveyor'    => 'surveyor',
        'pelanggan'   => 'pelanggan',

        // Satu tempat untuk cater dan teknisi.
        'teknisi'     => 'teknisi',
        'cater'       => 'teknisi',
        'caters'      => 'teknisi',
    ],

    /*
    | Role yang dipakai jabatan yang namanya tidak dikenal.
    |
    | `admin`, bukan `null`. Jabatan yang tidak dikenali harus tetap bisa
    | dipakai, dan pilihan yang lebih merusak daripada kelonggaran adalah
    | jabatan yang tiba-tiba tidak punya satu pun menu untuk dipilih.
    |
    */
    'jabatan_role_fallback' => 'admin',

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    |
    | `key`    Identitas stabil yang disimpan di tabel `jabatan_menu_access`.
    |          Mengubah atau menghapus key = hak akses yang tersimpan ikut
    |          mati. Untuk mengelompokkan, pakai `group` -- jangan
    |          menyisipkan awalan ke dalam nama key.
    |
    | `path`   Route Vue. Dipakai sidebar dan router guard.
    |
    | `api`    Prefix endpoint (tanpa `/api`) yang dikuasai menu ini.
    |          Kosong = endpoint-nya dipakai bersama dengan halaman lain
    |          dan TIDAK dikunci. Lihat catatan panjang di bawah tabel.
    |
    |          BENTUK PREFIX, dan kenapa bentuknya penting
    |          ------------------------------------------
    |          `pelanggan`            -> `/pelanggan` DAN semua turunannya
    |                                    (`/pelanggan/bill-history`, dll).
    |                                    Turunannya ikut dikuasai.
    |          `pelanggan/bill-detail` -> hanya `/pelanggan/bill-detail` dan
    |                                    turunannya. Tidak menyentuh
    |                                    `/pelanggan/bill-history`.
    |          `installation-tickets`  -> `/installation-tickets` DAN SEMUA
    |                                    turunannya: `/installation-tickets/12`
    |                                    dan `/installation-tickets/12/survey`
    |                                    ikut terkuasai.
    |          `installation-tickets*` -> HANYA `/installation-tickets` itu
    |                                    sendiri. Turunannya TIDAK ikut.
    |          `installation-tickets/*` -> `/installation-tickets/12` saja --
    |                                    SATU segmen tepat di belakang `/`.
    |
    |          Kenapa `*` di akhir TIDAK berarti "sampai ke dalam"
    |          ------------------------------------------------------
    |          `fnmatch` dijalankan dengan flag `FNM_PATHNAME`, jadi `*`
    |          TIDAK bisa menyeberahi `/`. Akibatnya, dua bentuk yang
    |          mirip sekali ini TIDAK sama artinya:
    |
    |            `installation-tickets*`  cocok dengan `/installation-tickets`
    |                                        dan `/installation-tickets-unpaid`
    |                                        (nama yang berawalan sama), tapi
    |                                        TIDAK dengan `/installation-tickets/12`.
    |            `installation-tickets/*`  cocok dengan `/installation-tickets/12`
    |                                        tapi TIDAK dengan
    |                                        `/installation-tickets/12/survey`.
    |
    |          Bentuk yang dipakai bersama beberapa menu HARUS dibedakan
    |          dengan cara yang sama. Kalau sebuah menu "daftar" memakai
    |          `installation-tickets` (polos), menu itu ikut menjadi pemilik
    |          endpoint "simpan" yang jauh lebih dalam -- dan mencabut menu
    |          "simpan" pun tidak akan menutup apa pun, karena "daftar"
    |          masih membuka jalannya.
    |
    |          Konsekuensi yang harus diketahui: `installation-tickets*` juga
    |          cocok dengan `/installation-tickets-unpaid` (nama yang berawalan
    |          sama). Endpoint itu ada di `always_allowed_api`, jadi tidak
    |          pernah terkunci -- tidak ada masalah, tapi jangan lupa kalau
    |          suatu saat ada endpoint lain yang namanya berawalan sama.
    |
    |          Bentuk dengan satu segmen di TENGAH (dipakai `survey.create`
    |          dan `teknisi.hasil-instalasi`) dituliskan di entri masing-masing
    |          -- bentuknya memuat karakter wildcard di tengah, jadi tidak
    |          bisa ditulis di blok komentar ini: penutup wildcard-nya akan
    |          lebih dulu menutup komentarnya sendiri.
    |
    | `roles`  BATAS ATAS role yang boleh punya menu ini, bukan cek akses
    |          akhir. `CheckRole` sudah menentukan boleh/tidaknya sebuah
    |          role memanggil endpoint; preferensi jabatan hanya MENCABUT
    |          dari itu, tidak pernah memberi akses baru.
    |          Bendahara umumnya role `admin` dengan jabatan Bendahara,
    |          sehingga mencentang "Transaksi" memberinya akses ke SELURUH
    |          isi Transaksi -- bukan hanya menunya yang tampil.
    |
    |          Kolom ini juga menentukan menu apa yang DITAWARKAN untuk
    |          sebuah jabatan: lihat `jabatan_roles` di atas. Jabatan role
    |          `admin` hanya melihat entri bertanda `admin`; jabatan Teknisi
    |          hanya melihat entri bertanda `teknisi`.
    |
    | `hidden` Tampil di halaman Hak Akses (supaya bisa dicentang), tapi
    |          tidak dirender sebagai entri menu baru di sidebar. Dipakai
    |          untuk halaman yang sudah ada di router dan diakses dari
    |          dashboard, seperti Arsip.
    |
    */
    'items' => [

        /*
         * Katalog menu APLIKASI LOKASI, semua role.
         *
         * Dulu katalog ini hanya berisi menu `admin`, karena hak akses
         * berbasis jabatan hanya berlaku untuk admin. Sekarang berlaku untuk
         * keempat role, jadi menu surveyor/teknisi/pelanggan juga ditulis di
         * sini -- satu-satunya supaya bisa DICENTANG dari halaman Hak Akses.
         *
         * Yang tidak berubah: `roles` di setiap entri. Sidebar tetap
         * menyaring berdasarkan kolom itu, jadi menu Teknisi tidak muncul di
         * sidebar admin, meskipun keduanya ada di katalog yang sama.
         *
         * "Beranda" tetap bukan entri di sini. `/app` adalah halaman tujuan
         * setelah login, bukan pekerjaan yang harus dikerjakan, jadi tidak
         * ada gunanya menawarkan centang untuk menguncinya.
         */

        // ---- Pengaturan -------------------------------------------------
        [
            'key'   => 'settings.sop',
            'label' => 'Personalisasi (SOP)',
            'group' => 'Pengaturan',
            'icon'  => 'sliders',
            'path'  => '/app/settings/personalisasi-sop',
            // `settings/sop` dipakai BillingForm (semua role) dan Tagihan
            // Bulanan lewat settingsStore -- bukan cuma halaman SOP.
            // Menguncinya akan merusak form pembayaran di halaman yang hak
            // aksesnya justru TIDAK dicabut.
            'api'   => [],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'settings.coa',
            'label' => 'Chart of Account (CoA)',
            'group' => 'Pengaturan',
            'icon'  => 'sitemap',
            'path'  => '/app/settings/coa',
            // Hanya `accounts/by-level` yang dipanggil halaman ini.
            // `GET /accounts` dipakai form Jurnal Umum juga, jadi tidak
            // ikut dikunci -- memotongnya akan membuat dropdown akun di
            // Jurnal Umum kosong.
            'api'   => ['accounts/by-level'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'settings.package',
            'label' => 'Paket & Tarif Layanan',
            'group' => 'Pengaturan',
            'icon'  => 'boxes',
            'path'  => '/app/kelas-biaya',
            // `installation-packages` juga dibutuhkan Daftar Instalasi dan
            // Registrasi, jadi ketiga menunya ikut menyebutnya. Kalau hanya
            // menu ini yang menyebutnya, mencabut menu ini akan ikut
            // membuat dua halaman lain kehilangan dropdown paketnya --
            // padahal dua halaman itu tetap terlihat karena dicentang.
            'api'   => ['installation-packages'],
            'roles' => ['admin'],
        ],
/*
 * Entri `settings.hak-akses` DIHAPUS, karena halamannya pindah ke panel
 * pusat (`/central/lokasi`, accordion per baris tenant).
 *
 * Alasannya bukan sekadar "menu ini sudah tidak ada" -- entri katalog yang
 * menunjuk halaman yang sudah pindah akan membuat sidebar menampilkan menu
 * yang, saat diklik, mendarat di halaman yang mengatur hal yang sudah tidak
 * ada di sana.
 *
 * Yang perlu diketahui soal penghapusan ini: baris `settings.hak-akses`
 * yang sudah tersimpan di `jabatan_menu_access` TIDAK ikut terhapus, dan
 * itu memang aman. `allowedKeysFor()` mengembalikan daftar dari database
 * apa adanya, sedangkan `catalog()` sudah tidak punya key itu lagi -- jadi
 * key tersebut tidak pernah cocok dengan endpoint mana pun dan tidak
 * mengunci apa pun. Jumlah baris yang tersisa juga tidak kosong, jadi
 * jabatan itu tetap terbaca "sudah dikonfigurasi", persis seperti sebelum
 * halaman ini dihapus.
 *
 * Kalau nanti key-nya ditambahkan kembali, semua pen intriguial lama akan
 * langsung berlaku lagi tanpa ada yang menyetel ulang -- itulah alasan
 * `config/menu_access.php` memperlakukan `key` sebagai identitas stabil,
 * bukan sekadar nama tampilan.
 */

        // ---- Basis Data -------------------------------------------------
        [
            'key'   => 'master.pelanggan',
            'label' => 'Pelanggan',
            'group' => 'Basis Data',
            'icon'  => 'users',
            'path'  => '/app/data-pelanggan',
            // `/customers` dipakai halaman Instalasi juga
            // (`useInstalasiActions` menghapus pelanggan dari situ), jadi
            // kedua menunya ikut menyebutnya. Tanpa itu, mencabut menu ini
            // akan membuat tombol hapus di Status Instalasi gagal padahal
            // halamannya sendiri masih terbuka.
            //
            // `trouble-reports` ikut karena laporan gangguan itu menyangkut
            // pelanggan: daftar, detail, dan ubah status laporan semuanya
            // milik admin, dan menu ini satu-satunya tempat yang wajar
            // memiliki itu.
            //
            // CATATAN: prefix `trouble-reports` TIDAK boleh ikut dipakai menu
            // ber-role `pelanggan`. Kalau iya, satu-satunya pemilik endpoint
            // admin itu adalah menu yang role-nya `pelanggan` -- dan
            // `allowedKeysFor()` membuang menu milik role lain dari daftar
            // user, jadi admin yang jabatan-nya dibatasi ikut kehilangan
            // kemampuan mengelola laporan gangguan.
            'api'   => ['customers', 'trouble-reports'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'master.desa',
            'label' => 'Desa',
            'group' => 'Basis Data',
            'icon'  => 'map-marker-alt',
            'path'  => '/app/data-desa',
            // `registrasi.vue` memakainya untuk dropdown desa, jadi kedua
            // menunya ikut menyebutnya. Tanpa itu, mencabut menu Desa akan
            // membuat dropdown desa di form registrasi kosong sementara
            // halaman registrasi sendiri tetap terbuka.
            'api'   => ['villages'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'master.instalasi',
            'label' => 'Daftar Instalasi',
            'group' => 'Basis Data',
            'icon'  => 'building',
            'path'  => '/app/dataInstalasi',
            // `installation-packages` dipakai `useDataInstalasi` untuk
            // nama paket, jadi ikut disebut agar penolakannya konsisten
            // dengan menu Paket & Tarif.
            'api'   => ['installation-tickets', 'installation-packages'],
            'roles' => ['admin'],
        ],

        // ---- Master Instalasi -------------------------------------------
        [
            'key'   => 'instalasi.register',
            'label' => 'Registrasi Instalasi',
            'group' => 'Master Instalasi',
            'icon'  => 'file-signature',
            'path'  => '/app/instalasi/register',
            'api'   => ['installation-tickets', 'villages', 'installation-packages'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'instalasi.status',
            'label' => 'Status Instalasi',
            'group' => 'Master Instalasi',
            'icon'  => 'clipboard-list',
            'path'  => '/app/instalasi/status',
            // `customers` ikut disebut karena `useInstalasiActions`
            // menghapus pelanggan dari halaman ini.
            'api'   => ['installation-tickets', 'customers'],
            'roles' => ['admin'],
        ],
        /*
         * Menu TEKNISI. `admin` sengaja TIDAK ada di `roles`.
         *
         * Sebelumnya ikut berperan, dan itu membuat admin melihat menu
         * "Input Pemakaian Air" di group "Master Instalasi" -- halaman
         * milik teknisi. Akibatnya admin punya DUA halaman untuk satu
         * pekerjaan yang sama:
         *
         *   - `/app/instalasi/teknisiPemakaianAir` (menu ini), tanpa
         *     filter cater, jadi admin yang sebenarnya mau menilai
         *     satu cater saja harus tahu lebih dulu dia pencatat yang mana.
         *   - `/app/instalasi/pemakaian-air` (`tagihan.input` di bawah),
         *     yang memang punya filter "Pilih Teknisi" dan menolak memuat
         *     data sebelum cater dipilih.
         *
         * Yang kedua itulah tampilan yang benar untuk admin, jadi yang
         * pertama cukup dikembalikan ke pemiliknya. Sidebar menyaring
         * berdasarkan `roles`, jadi admin tidak lagi melihat entri ini
         * tanpa perlu menyentuh halamannya sama sekali.
         *
         * `label` juga diubah ke "Input Pemakaian": kata "Air" tidak
         * diperlukan di sidebar karena setiap menu di grup ini soal air,
         * dan "Input Pemakaian Air" hanya berdampingan dengan "Input
         * Tagihan" milik admin -- yang buat orang membacanya jadi dua
         * menu yang terdengar sama.
         */
        [
            'key'   => 'instalasi.pemakaian',
            'label' => 'Input Pemakaian',
            'group' => 'Master Instalasi',
            'icon'  => 'faucet',
            'path'  => '/app/instalasi/teknisiPemakaianAir',
            // `meter-readings/pending` dan `POST /meter-readings` dipakai
            // PemakaianAir (teknisi) DAN dashboard teknisi, jadi keduanya
            // ikut tertutup bersama menu ini.
            'api'   => ['meter-readings'],
            'roles' => ['teknisi'],
        ],

        /*
         * ---- Menu SURVEYOR ----------------------------------------------
         *
         * Dua halaman saja, dan keduanya sudah ada di router. Ditulis di
         * sini (dulu tidak ada sama sekali) supaya bisa DICENTANG dari
         * halaman Hak Akses -- sebelumnya satu-satunya pengaturan yang bisa
         * dilakukan untuk surveyor adalah "jangan sentuh", karena tidak ada
         * satu pun entri miliknya di katalog.
         *
         * KEDUA menu memakai prefix `installation-tickets*`, bukan
         * `installation-tickets`.
         *
         * Bedanya penting, dan ini yang membuat aturan jabatan benar-benar
         * berlaku untuk surveyor:
         *
         *   - `installation-tickets` (tanpa `*`) ikut cocok dengan
         *     turunannya -- termasuk `/installation-tickets/12/survey`, yaitu
         *     POST yang dipakai halaman Input Survey. Kalau prefix itu yang
         *     dipakai, maka `survey.daftar` (yang cuma untuk melihat daftar)
         *     akan ikut menjadi pemilik endpoint POST, dan mencabut
         *     `survey.create` TIDAK akan menutup apa pun: `survey.daftar`
         *     masih membuka jalannya.
         *
         *   - `installation-tickets*` hanya cocok dengan `/installation-tickets`
         *     itu sendiri. Turunannya yang lebih dalam TIDAK ikut -- karena
         *     `fnmatch` dengan `FNM_PATHNAME` membuat `*` tidak bisa
         *     menyeberangi `/`.
         *
         * Jadi `POST /installation-tickets/{id}/survey` hanya bisa dibuka
         * oleh `survey.create`, persis seperti yang diminta: menu yang dicabut
         * berarti endpointnya ikut tertutup.
         *
         * `installation-tickets*` juga cocok dengan
         * `/installation-tickets-unpaid` (nama yang berawalan sama). Endpoint
         * itu sudah ada di `always_allowed_api`, jadi tidak pernah terkunci
         * dan tidak ada masalah -- tapi itulah sebabnya `always` diperiksa
         * lebih dulu di middleware.
         */
        [
            'key'   => 'survey.daftar',
            'label' => 'Daftar Survey',
            'group' => 'Survey',
            'icon'  => 'clipboard-check',
            'path'  => '/app/surveyor',
            // `DashboardMain` mengambil tiket `pending` dan `surveyed`.
            'api'   => ['installation-tickets*'],
            'roles' => ['surveyor'],
        ],
        [
            'key'   => 'survey.create',
            'label' => 'Input Survey',
            'group' => 'Survey',
            'icon'  => 'map-location-dot',
            'path'  => '/app/survey/create',
            // Dua prefix, dan keduanya wajib:
            //   - `installation-tickets*` : daftar tiket `pending` yang
            //     jadi bahan中选择 di form ini.
            //   - `installation-tickets/*/survey` : POST simpan hasil survey.
            //     ID-nya berupa angka yang tidak bisa diprediksi, jadi
            //     wildcard-nya tidak mungkin menabrak halaman lain.
            'api'   => ['installation-tickets*', 'installation-tickets/*/survey'],
            'roles' => ['surveyor'],
        ],

        /*
         * ---- Menu TEKNISI (lanjutan) ------------------------------------
         *
         * Satu tempat untuk dua jabatan: `Cater` dan `Teknisi`. Keduanya
         * dicocokkan ke role `teknisi` lewat `jabatan_roles`, jadi keduanya
         * melihat daftar centang yang PERSIS SAMA dan tidak bisa memakai
         * hak akses yang berbeda satu sama lain.
         *
         * Prefix `installation-tickets` + wildcard + `installation-result`
         * adalah POST dari InstallationResult.vue. Wildcard-nya satu segmen
         * disengaja: bentuknya `installation-tickets/{id}/...`.
         */
        [
            'key'   => 'teknisi.hasil-instalasi',
            'label' => 'Hasil Instalasi',
            'group' => 'Pusat Teknis',
            'icon'  => 'screwdriver-wrench',
            'path'  => '/app/teknisi/hasil-instalasi/:id',
            // Dua prefix, dan KEDUANYA wajib.
            //
            // Yang kedua (satu segmen wildcard + `installation-result`) adalah
            // POST dari InstallationResult.vue. Yang pertama (wildcard polos)
            // adalah `GET /installation-tickets/{id}` -- detail tiket yang
            // dipakai halaman ini untuk mengisi formulirnya.
            //
            // Tanpa yang pertama, halaman yang MUNCUL di sidebar teknisi
            // akan selalu 403: satu-satunya pemilik prefix `installation-tickets`
            // adalah menu admin, dan `allowedKeysFor()` tidak pernah
            // mengizinkan menu admin bagi user teknisi. Gejalanya nyata --
            // halamannya terbuka, tapi isinya tidak pernah datang.
            //
            // Wildcard polos ini HANYA berlaku untuk role `teknisi`: menu ini
            // tidak punya role `admin`, jadi admin tetap memakai pemiliknya
            // sendiri (menu admin yang menyebut `installation-tickets`).
            'api'   => ['installation-tickets/*', 'installation-tickets/*/installation-result'],
            'roles' => ['teknisi'],
        ],
        [
            'key'   => 'teknisi.daftar-tagihan',
            'label' => 'Daftar Tagihan',
            'group' => 'Pusat Teknis',
            'icon'  => 'receipt',
            'path'  => '/app/teknisi/daftar-tagihan',
            // `DaftarTagihan.vue` memanggil `getAllBills()` yang ber-paging
            // ke `monthly-bills`. Prefix-nya `monthly-bills*`, bukan
            // `bills`: `bills` dipakai `tagihan.daftar` milik admin dan
            // tidak pernah dipanggil dari halaman teknisi.
            //
            // Bentuk `monthly-bills*` (wildcard di akhir) penting: ia hanya
            // cocok dengan `/monthly-bills` itu sendiri, sehingga menu ini
            // TIDAK ikut membuka endpoint TULIS milik admin
            // (`/monthly-bills/generate`, `/monthly-bills/{id}/pay`,
            // `/monthly-bills/{id}`). Endpoint itu punya pemiliknya sendiri
            // di `tagihan.input` dan `transaksi.tagihan-bulanan`, dan
            // `CheckRole` menutupnya untuk teknisi -- jadi yang hilang
            // hanya hak akses jabatan, bukan keamanan.
            'api'   => ['monthly-bills*'],
            'roles' => ['teknisi'],
        ],

        /*
         * ---- Menu PELANGGAN ----------------------------------------------
         *
         * Empat halaman portal pelanggan. Semuanya memakai prefix
         * `pelanggan/...` -- satu per halaman, BUKAN satu prefix longgar
         * `pelanggan` untuk ketiganya.
         *
         * Kenapa tidak `pelanggan` untuk semuanya:
         *
         *   - `pelanggan` (tanpa `/...`) ikut cocok dengan SELURUH
         *     turunannya. Kalau ketiga menu memakai itu, maka mencabut
         *     "Lapor Gangguan" tidak akan menutup apa pun: "Tagihan Saya"
         *     dan "Riwayat Tagihan" masih menjadi pemilik endpoint yang
         *     sama, dan middleware menolak hanya kalau SEMUA pemiliknya
         *     tidak diizinkan.
         *
         *   - Prefiks yang tepat membuat setiap menu benar-benar bisa
         *     dikunci: mencabut "Lapor Gangguan" menutup
         *     `POST /pelanggan/trouble-report`, dan tidak ada menu lain
         *     yang ikut membukanya.
         *
         * `pelanggan/dashboard` SENGAJA TIDAK ada di sini: itu "Beranda"
         * portal pelanggan -- halaman tujuan setelah login, bukan pekerjaan
         * yang harus digiling. Ia ada di `always_allowed_api`, sama seperti
         * `dashboard` untuk aplikasi utama. Kalau ikut bisa dikunci, memencet
         * "Hapus semua" akan membuat portal pelanggan terbuka ke halaman
         * yang isinya 403 -- dan tidak ada jalan keluar dari sana.
         *
         * `hidden` untuk dua halaman pertama: keduanya tidak punya entri
         * sidebar sendiri -- portal pelanggan bisa dicapai lewat dashboard --
         * tapi tetap bisa dicentang dari halaman Hak Akses.
         */
        [
            'key'    => 'pelanggan.tagihan',
            'label'  => 'Tagihan Saya',
            'group'  => 'Layanan Pelanggan',
            'icon'   => 'file-invoice-dollar',
            'path'   => '/app/pelanggan/tagihan-detail',
            // `bill-detail` punya `{id?}` di route-nya, jadi prefix tanpa
            // id tetap ikut cocok dengan `/pelanggan/bill-detail/12`.
            'api'    => ['pelanggan/bill-detail'],
            'roles'  => ['pelanggan'],
            'hidden' => true,
        ],
        [
            'key'    => 'pelanggan.riwayat',
            'label'  => 'Riwayat Tagihan',
            'group'  => 'Layanan Pelanggan',
            'icon'   => 'clock-rotate-left',
            'path'   => '/app/pelanggan/riwayat-tagihan',
            'api'    => ['pelanggan/bill-history'],
            'roles'  => ['pelanggan'],
            'hidden' => true,
        ],
        [
            'key'   => 'pelanggan.lapor-gangguan',
            'label' => 'Lapor Gangguan',
            'group' => 'Layanan Pelanggan',
            'icon'  => 'triangle-exclamation',
            'path'  => '/app/pelanggan/lapor-gangguan',
            // Hanya POST-nya. Prefix `trouble-reports` (bentuk jamak) TIDAK
            // ikut: itu endpoint ADMIN -- daftar, detail, dan ubah status
            // laporan. Kalau ikut di sini, satu-satunya pemilik endpoint
            // admin itu adalah menu ber-role `pelanggan`, dan
            // `allowedKeysFor()` membuang menu itu dari daftar user admin
            // -- artinya admin yang jabatan-nya DIBATASI ikut kehilangan
            // kemampuan mengelola laporan gangguan.
            'api'   => ['pelanggan/trouble-report'],
            'roles' => ['pelanggan'],
        ],

        // ---- Pencatatan Tagihan ------------------------------------------
        [
            'key'   => 'tagihan.input',
            'label' => 'Input Tagihan',
            'group' => 'Pencatatan Tagihan',
            'icon'  => 'pen-to-square',
            'path'  => '/app/instalasi/pemakaian-air',
            // `monthly-bills/generate` hanya milik menu ini -- endpoint
            // pembuatan tagihan bulanan, bukan milik daftar tagihan.
            //
            // `meter-readings` dipakai `detailPemakaianAir.vue` (halaman
            // input meter) dan `PemakaianAir.vue` (teknisi), jadi keduanya
            // ikut tertutup bersama menu ini.
            'api'   => ['monthly-bills/generate', 'meter-readings'],
            'roles' => ['admin', 'teknisi'],
        ],
        [
            'key'   => 'tagihan.daftar',
            'label' => 'Daftar Tagihan',
            'group' => 'Pencatatan Tagihan',
            'icon'  => 'receipt',
            'path'  => '/app/instalasi/daftar-tagihan',
            // DUA prefix, dan keduanya wajib:
            //
            //   - `bills` : `GET /bills/recap` dan `GET /bills/{id}` yang
            //     dipakai saat membuka detail tagihan.
            //   - `monthly-bills*` : `GET /monthly-bills` -- endpoint yang
            //     sebenarnya dipanggil `billingService.getBills()`.
            //
            // Yang kedua WAJIB ada. Tanpa itu, halaman ini tidak punya satu
            // pun pemilik untuk daftar tagihannya: `transaksi.tagihan-bulanan`
            // yang juga memakai `monthly-bills` adalah menu Transaksi, dan
            // kalau jabatan yang hanya mencentang "Daftar Tagihan" tidak
            // punya Tagihan Bulanan, endpointnya ikut terblokir -- halaman
            // yang VISIBEL tapi isinya tidak pernah datang.
            //
            // Perhatikan bentuk `monthly-bills*` (bukan `monthly-bills`):
            // bentuk pertama hanya cocok dengan `/monthly-bills` itu sendiri,
            // sehingga halaman ini tidak ikut membuka endpoint TULIS
            // (`/monthly-bills/{id}/pay`, `/monthly-bills/generate`) yang
            // bukan miliknya.
            'api'   => ['bills', 'monthly-bills*'],
            'roles' => ['admin'],
        ],

        // ---- Transaksi ---------------------------------------------------
        [
            'key'   => 'transaksi.jurnal-umum',
            'label' => 'Jurnal Umum',
            'group' => 'Transaksi',
            'icon'  => 'book',
            'path'  => '/app/transaksi/jurnal-umum',
            'api'   => ['transactions', 'jenis-transactions', 'generate-amount'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'transaksi.tagihan-bulanan',
            'label' => 'Tagihan Bulanan',
            'group' => 'Transaksi',
            'icon'  => 'file-invoice-dollar',
            'path'  => '/app/transaksi/tagihan-bulanan',
            // `bills`        : rekap & detail tagihan lama.
            // `monthly-bills*`: daftar tagihan bulanan (dipakai
            //                   `billingStore.fetchBillingPeriods`).
            // `tunggakan`    : generate tunggakan.
            //
            // Yang TIDAK ikut: `monthly-bills/{id}/pay`,
            // `monthly-bills/{id}` (hapus), dan `monthly-bills/generate`.
            // Ketiganya punya pemiliknya sendiri di `tagihan.input` --
            // dan prefix `monthly-bills*` sengaja berhenti di `/monthly-bills`
            // supaya tidak ikut membukanya.
            'api'   => ['bills', 'monthly-bills*', 'tunggakan'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'transaksi.tagihan-instalasi',
            'label' => 'Tagihan Instalasi',
            'group' => 'Transaksi',
            'icon'  => 'file-invoice',
            'path'  => '/app/transaksi/tagihan-instalasi',
            // `installation-tickets-unpaid` dipakai dashboard juga, jadi
            // tidak dikunci. Yang dikunci `installation-tickets` sudah
            // tercakup oleh menu lain di bawah.
            'api'   => [],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'transaksi.ebudgeting',
            'label' => 'E-Budgeting',
            'group' => 'Transaksi',
            'icon'  => 'piggy-bank',
            'path'  => '/app/transaksi/E-budgeting',
            'api'   => ['ebudgeting'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'transaksi.tutup-buku',
            'label' => 'Tutup Buku',
            'group' => 'Transaksi',
            'icon'  => 'lock',
            'path'  => '/app/transaksi/tutup-buku',
            'api'   => ['tutup-buku', 'neraca-saldo'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'transaksi.alokasi-laba',
            'label' => 'Alokasi Laba',
            'group' => 'Transaksi',
            'icon'  => 'calculator',
            'path'  => '/app/transaksi/alokasi-laba',
            'api'   => ['alokasi-laba'],
            'roles' => ['admin'],
        ],
        [
            'key'   => 'transaksi.komisi-sps',
            'label' => 'Komisi SPS',
            'group' => 'Transaksi',
            'icon'  => 'money-bill-wave',
            'path'  => '/app/transaksi/komisi-sps',
            'api'   => ['komisi-sps'],
            'roles' => ['admin'],
        ],

        // ---- Pelaporan ----------------------------------------------------
        [
            'key'   => 'pelaporan',
            'label' => 'Pelaporan',
            'group' => 'Pelaporan',
            'icon'  => 'file-alt',
            'path'  => '/app/pelaporan',
            'api'   => ['pelaporan'],
            'roles' => ['admin'],
        ],

        // ---- Arsip --------------------------------------------------------
        // Ada di router dan bisa dibuka dari dashboard, tapi tidak pernah
        // muncul sebagai entri sidebar. Tetap bisa dicentang di Hak Akses
        // supaya halamannya ikut tertutup kalau jabatan tidak boleh.
        [
            'key'     => 'arsip.tagihan',
            'label'   => 'Arsip Tagihan',
            'group'   => 'Arsip',
            'icon'    => 'archive',
            'path'    => '/app/arsip/tagihan',
            // `monthly-bills*` -- bentuk yang sama dengan yang dipakai
            // `tagihan.daftar` dan `transaksi.tagihan-bulanan`, karena ketiganya
            // memanggil endpoint yang sama: `GET /monthly-bills`.
            //
            // Bentuk polos `monthly-bills` (tanpa `*`) ikut cocok dengan
            // turunannya -- termasuk `/monthly-bills/{id}/pay` dan
            // `/monthly-bills/{id}`. Itu endpoint TULIS milik admin, dan
            // membukanya lewat menu Arsip bukan hal yang benar: mencabut
            // menu Arsip tidak akan menutup pembayaran tagihan, dan
            // sebaliknya mencentang Arsip akan membuka jalur pembayaran.
            'api'     => ['monthly-bills*'],
            'roles'   => ['admin'],
            'hidden'  => true,
        ],
        [
            'key'     => 'arsip.tunggakan',
            'label'   => 'Arsip Tunggakan',
            'group'   => 'Arsip',
            'icon'    => 'hourglass-half',
            'path'    => '/app/arsip/tunggakan',
            'api'     => ['tunggakan'],
            'roles'   => ['admin'],
            'hidden'  => true,
        ],
        [
            'key'     => 'arsip.pemakaian',
            'label'   => 'Arsip Pemakaian',
            'group'   => 'Arsip',
            'icon'    => 'droplet',
            'path'    => '/app/arsip/pemakaian',
            'api'     => ['meter-readings'],
            'roles'   => ['admin'],
            'hidden'  => true,
        ],
        [
            'key'     => 'arsip.instalasi',
            'label'   => 'Arsip Instalasi',
            'group'   => 'Arsip',
            'icon'    => 'boxes',
            'path'    => '/app/arsip/instalasi',
            'api'     => ['installation-tickets'],
            'roles'   => ['admin'],
            'hidden'  => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint yang tidak pernah dikunci
    |--------------------------------------------------------------------------
    |
    | Berisi endpoint yang dipakai BERSAMA di luar area menu: profil,
    | chrome, dan endpoint milik role lain. Memblokir salah satu di sini
    | merusak halaman yang justru VISIBEL -- misalnya judul sidebar
    | memanggil `settings/lembaga-identity` di setiap mount, dan panel
    | notifikasi di topnav memanggil `unpaid-summary` di semua halaman.
    |
    | Daftar ini eksplisit, bukan sekadar "yang tidak ada di `items`".
    | Untuk endpoint yang dipakai satu menu saja, memblokirnya memang
    | perilaku yang diinginkan; yang masuk ke sini justru endpoint yang
    | dipakai lebih dari satu tempat.
    |
    | Wildcard `*` mencocokkan satu segmen. `**` belum didukung -- cukup untuk
    | endpoint yang ada di aplikasi ini.
    |
    */
    'always_allowed_api' => [

        // Autentikasi. `logout` paling penting: kalau ikut terkunci, user
        // tidak bisa keluar dari aplikasi.
        'login',
        'logout',
        'refresh',
        'me',
        'me/*',
        'health',
        'tagihan-saya',
        'tagihan-saya/*',

        // `menu-access` TIDAK ada di sini lagi -- tidak ada yang memanggilnya
        // dari aplikasi lokasi, karena managing-nya pindah ke panel pusat.
        // `/api/menu-access` yang tersisa hanya dibaca, dan `GET` termasuk
        // endpoint bersama: tidak ada satu pun katalog menu yang memiliki
        // prefix itu, jadi `EnforceMenuAccess` sudah membiarkannya lewat.
        //
        // Kalau nanti halaman ini dipakai lagi di lokasi, `GET /menu-access`
        // TIDAK boleh masuk daftar ini tanpa ditinjau ulang: hanya
        // mengetiknya karena "dulu ada" akan membuat pembacaan hak akses
        // ikut lolos untuk jabatan yang tidak berhak -- dan itu persis
        // informasi yang seharusnya tidak bocor ke mereka.

        // Chrome bersama semua role.
        'settings/lembaga-identity',
        'settings/kecamatan',
        'settings/desa',
        'settings/payment-mode',
        'monthly-bills/unpaid-summary',
        'users/penandatangan',

        // `settings/sop` dipakai BillingForm (semua role) dan Tagihan
        // Bulanan lewat settingsStore -- bukan cuma halaman SOP.
        // `settings/signatures` dipakai Pelaporan (preview & export) juga,
        // jadi menguncinya akan merusak ekspor laporan.
        'settings/sop',
        'settings/sop/*',
        'settings/signatures',
        'settings/signatures/*',

        // Dashboard dipakai Beranda dan popup generate piutaran.
        'dashboard',
        'dashboard/*',

        // ---- Endpoint milik role lain: yang MASIH TIDAK bisa dikunci ----
        //
        // Dulu seluruh daftar di bawah ini ada di sini, dengan alasan
        // "role sudah dijaga `CheckRole`". Alasan itu benar waktu
        // `enforce_roles` hanya berisi `admin`, dan MENJADI SALAH sekarang:
        // `CheckRole` memberi tahu role apa yang BOLEH memanggil, bukan
        // jabatan mana yang boleh -- jadi sebuah jabatan tidak pernah bisa
        // dibatasi, karena tidak ada satu pun prefix-nya yang punya pemilik.
        //
        // Yang dipindah ke `items` (dan karena itu bisa dicentang):
        //
        //   installation-tickets*                         -> survey.daftar, survey.create
        //   installation-tickets/*/survey                 -> survey.create
        //   installation-tickets/*                        -> teknisi.hasil-instalasi
        //   installation-tickets/*/installation-result    -> teknisi.hasil-instalasi
        //   pelanggan/bill-detail                         -> pelanggan.tagihan
        //   pelanggan/bill-history                        -> pelanggan.riwayat
        //   pelanggan/trouble-report                      -> pelanggan.lapor-gangguan
        //   trouble-reports, trouble-reports/*           -> master.pelanggan
        //   monthly-bills*                                -> tagihan.daftar,
        //                                                        transaksi.tagihan-bulanan,
        //                                                        arsip.tagihan,
        //                                                        teknisi.daftar-tagihan
        //
        // Yang TETAP di sini punya alasan masing-masing:
        //
        //   - `register-dropdown`, `change-package`, `history`, `transition`,
        //     `advance-stage`, `activate`, `payment`: dipakai halaman
        //     INSTALASI milik admin (`instalasi.register` / `instalasi.status`).
        //     Kalau wildcard-nya ikut dikunci ke `survey.create` atau
        //     `teknisi.hasil-instalasi`, mencabut survey akan ikut mematikan
        //     tombol admin yang halamannya tetap terbuka.
        //
        //   - `installation-tickets-unpaid`, `monthly-bills/usage`: dipakai
        //     dashboard dan popup piutaran, di luar area menu.
        //
        //   - `customers/search`, `customers/*/restore`: `search` dipakai form
        //     pelanggan di beberapa halaman; `restore` dipakai dashboard
        //     teknisi, yang tidak punya halaman Arsip sendiri.
        //
        //   - `survey-results`: dipakai panel detail Status Instalasi
        //     (`aktif.vue`) milik admin.
        //
        //   - `pelanggan/dashboard`: "Beranda" portal pelanggan -- halaman
        //     tujuan setelah login, bukan pekerjaan yang bisa digiling.
        //     Kalau ikut dikunci, memencet "Hapus semua" akan membuat portal
        //     pelanggan terbuka ke halaman yang isinya 403, dan tidak ada
        //     jalan keluar dari sana.
        //
        //   - `pelanggan/profile`: profil pelanggan. Dipakai halaman profil
        //     yang di sidebar TIDAK punya entri katalog sendiri, jadi tidak
        //     ada menu yang bisa mengunci prefix ini -- dan memang tidak
        //     perlu: yang dipanggil hanya data milik user yang sedang login.
        'installation-tickets/register-dropdown',
        'installation-tickets/*/change-package',
        'installation-tickets/*/history',
        'installation-tickets/*/transition',
        'installation-tickets/*/advance-stage',
        'installation-tickets/*/activate',
        'installation-tickets/*/payment',
        'installation-tickets-unpaid',
        'monthly-bills/suspended',
        'monthly-bills/usage',
        'customers/search',
        'customers/*/restore',
        'meter-readings/completed',
        'survey-results',
        'pelanggan/dashboard',
        'pelanggan/profile',
    ],
];
