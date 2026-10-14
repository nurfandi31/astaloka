<!DOCTYPE html>
<html lang="id" class="no-js">
<head>
    {{--
        Class `no-js` dibuang oleh script di bawah tepat sebelum animasi
        dipasang. Selector `.reveal` punya `opacity: 0`, jadi kalau JS
        gagal dimuat seluruh halaman akan kosong. Guard ini yang
        memastikan konten tetap terlihat apa pun yang terjadi.

        Script-nya inline dan blocking dengan sengaja: kalau placement-nya
        di akhir body, halaman sempat ter-render tak terlihat sebelum
        class berubah -- persis kilatan yang ingin dihindari.
    --}}
    <script>document.documentElement.classList.remove('no-js');</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ASTALOKA oleh Asta Brata Teknologi adalah platform pengelolaan usaha, administrasi pelanggan, transaksi, dan laporan keuangan. Untuk berbagai skala usaha: air minum, internet, pembayaran sampah, jasa, dan layanan lainnya.">
    <meta name="theme-color" content="#0369a1">

    <title>ASTALOKA &mdash; Asta Layanan Operasional, Keuangan, dan Administrasi</title>

    {{--
        Favicon.

        Sebelumnya pakai emoji chart (U+1F4C8) di dalam SVG data-URI.
        Itu masalah yang sama seperti ikon di kartu: yang dirender adalah
        glyph dari font emoji sistem operasi, jadi bentuknya beda di tiap
        perangkat, dan di beberapa sistem bisa tampil kotak kosong di tab
        browser -- termasuk di layar yang paling terlihat.

        Sekarang huruf A dalam kotak biru, digambar penuh, jadi tidak
        bergantung font apa pun.

        Tanda kutip di dalam data-URI ditulis sebagai `%22`. Kalau
        ditulis literal, kutip itu menutup atribut HTML lebih dulu
        dan link rusak tanpa error yang kelihatan.

        `viewBox` 0 0 64 64 dengan huruf yang tidak menyentuh tepi: pada
        ikon 16px, bentuk yang menempel tepi akan tergerus penskalaan.
    --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='15' fill='%230369a1'/%3E%3Cpath d='M20 46L32 17l12 29h-6.4l-2.2-5.8H28.6L26.4 46zM30.4 34.8h3.2L32 29.2z' fill='%23fff'/%3E%3C/svg%3E">

<style>
        /* ============================================================
           DESIGN TOKENS

           Bayangan sengaja berlapis tiga lapis (ambient + key + contact).
           Satu bayangan flat selalu terlihat seperti kotak cetak; tiga
           lapis itulah yang bikin kartu terasa terangkat dari halaman.

           Prefix `--sh-` dipakai semua kelas supaya nilainya bisa diubah
           dari satu tempat, bukan disebar di puluhan deklarasi.
           ============================================================ */
        :root {
            /* Palet brand: sky.
               Sumber acuan = aplikasi admin lokasi/tenant
               (frontend/src/assets/css/main.css `--color-primary`),
               bukan teal. Semua nada di halaman ini -- tombol, garis
               bawah nav, kartu, mockup, panel penutup -- diturunkan dari
               skala ini, jadi ganti warna di satu blok ini cukup. */
            --sky-50:  #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-200: #bae6fd;
            --sky-300: #7dd3fc;
            --sky-400: #38bdf8;
            --sky-500: #0ea5e9;
            --sky-600: #0284c7;
            --sky-700: #0369a1;
            --sky-800: #075985;
            --sky-900: #0c4a6e;
            --sky-950: #082f49;

            --ink:      #0f172a;
            --ink-soft: #475569;
            --ink-mute: #94a3b8;
            --line:     #e2e8f0;
            --bg:       #ffffff;
            --bg-soft:  #f8fafc;

            /* Slate + emerald: palet untuk mockup terminal.
               Dipisah dari palet halaman utama supaya kartu gelap tidak
               ikut berubah kalau warna brand nanti diganti. */
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-900: #0f172a;
            --emerald-300: #6ee7b7;
            --emerald-400: #34d399;
            --amber-400:  #fbbf24;

            --r-sm: 10px;
            --r:    14px;
            --r-lg: 20px;
            --r-xl: 26px;

            /* contact: sangat rapat, menempel permukaan
               key:    arah cahaya, inti elevate
               ambient:luas, sangat tipis, menyebar */
            --sh-xs: 0 1px 2px rgb(15 23 42 / .05);

            --sh-sm: 0 1px 2px rgb(15 23 42 / .04),
                     0 2px 6px -2px rgb(15 23 42 / .06);

            --sh-md: 0 2px 4px -1px rgb(15 23 42 / .04),
                     0 8px 20px -6px rgb(15 23 42 / .10);

            --sh-lg: 0 4px 8px -2px rgb(15 23 42 / .05),
                     0 16px 36px -10px rgb(15 23 42 / .14);

            --sh-xl: 0 8px 16px -4px rgb(15 23 42 / .06),
                     0 28px 60px -16px rgb(15 23 42 / .18);

            --sh-brand: 0 6px 16px -4px rgb(2 132 199 / .32),
                        0 2px 6px -2px rgb(2 132 199 / .20);

            --ease:   cubic-bezier(.16, 1, .3, 1);
            --spring: cubic-bezier(.34, 1.56, .64, 1);
        }

        *, *::before, *::after { box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            /* Clip horizontal di level `html`, bukan `body`.

             Mockup HP sengaja diberi `margin-right` negatif supaya
             keluar melewati tepi kanan panel hero. `overflow-x: hidden`
             di `body` TIDAK selalu meng-clip-nya -- di beberapa browser
             `overflow` pada `body` dipropagasi ke viewport dan jadi
             `visible`, sehingga halaman bisa bergeser ke samping di
             ponsel. Memotong di `html` bekerja konsisten karena langsung
             membentuk clipping context untuk seluruh turunan.
          */
            overflow-x: hidden;
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI",
                         Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--ink);
            background: var(--bg);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        a { color: inherit; }

        /*
         * Header `position: sticky` menutupi TARGET anchor saat link nav
         * diklik: `scroll-margin-top` memberi tahu browser bahwa area
         * teratas elemen itu terhalang header setinggi 70px, sehingga
         * browser menggeser halaman lebih jauh ke bawah agar judul
         * section tidak tersembunyi di balik header.
         *
         * Tanpa ini, klik "Fitur" mendarat dengan heading "#fitur" tepat
         * tertutup header -- konten terlihat, tapi tidak terbaca.
         */
        section[id], [id="masuk"] { scroll-margin-top: 88px; }

        /* `max-width` + `width: 100%` + padding: kalimat "box-sizing: border-box"
           di atas membuat padding dihitung di dalam lebar, jadi .wrap tidak
           pernah melebihi viewport. */
        .wrap { width: 100%; max-width: 1160px; margin-inline: auto; padding-inline: 24px; }

        /* ---------- animasi ---------- */
        @keyframes rise {
            from { opacity: 0; transform: translate3d(0, 26px, 0); }
            to   { opacity: 1; transform: none; }
        }
        @keyframes fade {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        @keyframes drift-b {
            from { transform: translate3d(0, 0, 0) scale(1.08); }
            to   { transform: translate3d(-60px, 60px, 0) scale(1); }
        }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgb(14 165 233 / .5); }
            70%  { box-shadow: 0 0 0 9px rgb(14 165 233 / 0); }
            100% { box-shadow: 0 0 0 0 rgb(14 165 233 / 0); }
        }

        /* Elemen .reveal muncul saat di-scroll, bukan hanya saat page load.

           PENTING: tanpa JavaScript, `opacity: 0` di sini akan membuat
           seluruh konten halaman hilang permanen. Karena itu `html.no-js`
           (dipasang satu baris inline di <head>) memaksa semua elemen
           terlihat, dan IntersectionObserver hanya meng-upgrade elemen itu
           ke animasi kalau JS memang hidup. Konten tidak pernah hilang
           karena animasi gagal. */
        .reveal { opacity: 0; }
        html.no-js .reveal { opacity: 1; transform: none; }

        /* `--d` (delay) dipasang per-elemen di HTML supaya tiap baris
           muncul berurutan, bukan semuanya sekaligus. */
        .reveal.shown { animation: rise .78s var(--ease) var(--d, 0ms) forwards; }

        /* PENTING: `.hero-art` punya class `reveal` sekaligus transform
           geser dari JS. Keduanya menulis properti `transform`.

           Kalau `rise` yang dipakai, `animation` dengan fill-mode
           `forwards` akan MENYIMPAN frame akhirnya (`transform: none`)
           dan terus menimpanya -- hasilnya mockup terkunci di tengah
           dan tidak bisa digeser sama sekali.

           Karena itu `.hero-art` punya `rise-mockup` sendiri: sama
           sama fade, tapi tanpa menulis `transform` sama sekali.
           Transform dikuasai penuh oleh JS. */
        @keyframes rise-mockup {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        .hero-art.reveal.shown { animation: rise-mockup .78s var(--ease) var(--d, 0ms) forwards; }

        /* ---------- header ---------- */
        header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgb(255 255 255 / .78);
            backdrop-filter: saturate(180%) blur(16px);
            -webkit-backdrop-filter: saturate(180%) blur(16px);
            border-bottom: 1px solid transparent;
            transition: border-color .3s var(--ease), box-shadow .3s var(--ease);
        }

        /* Dipasang JS saat scroll: border + bayangan baru muncul setelah
           header benar-benar "cucuk" dari konten, bukan menempel dari atas. */
        header.stuck {
            border-bottom-color: var(--line);
            box-shadow: 0 1px 3px rgb(15 23 42 / .05), 0 8px 24px -12px rgb(15 23 42 / .16);
        }

        /* Nama `.navbar`, bukan `.bar`: `.bar` dipakai untuk batang diagram.
           Nama yang sama di dua konteks berarti aturan CSS diam-diam
           saling menimpa, dan itu sulit dilacak karena tidak ada error. */
        .navbar {
            display: flex; align-items: center; gap: 16px;
            height: 70px;
            position: relative;
        }

        .brand {
            display: flex; align-items: center; gap: 11px;
            font-weight: 700; letter-spacing: -.02em; text-decoration: none;
        }
        .brand .dot {
            width: 36px; height: 36px; border-radius: 11px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, var(--sky-400), var(--sky-700));
            color: #fff; font-size: 18px; font-weight: 800;
            box-shadow: var(--sh-brand);
            transition: transform .5s var(--spring), box-shadow .3s var(--ease);
        }
        .brand:hover .dot { transform: rotate(-8deg) scale(1.08); }
        .brand small {
            display: block; font-size: 11px; font-weight: 500;
            /* `ink-soft`, bukan `ink-mute`. `ink-mute` (#94a3b8) di
               atas putih cuma 2.56:1 -- gagal di teks sekecil 11px.
               Ini baris yang menjelaskan produk dalam dua kata, jadi
               termasuk teks yang harusnya terbaca, bukan dekorasi. */
            color: var(--ink-soft); letter-spacing: 0; line-height: 1.15;
        }

        /* ---------- navbar ----------

           Desktop: deretan link + tombol. Setiap link punya garis bawah
           yang tumbuh dari kiri saat hover, dan link section yang sedang
           dibaca ditandai `.is-active` oleh scroll-spy di JS.

           Mobile (<=900px): `nav` berubah jadi panel dropdown yang
           menempel di bawah header. Tombol hamburger menggantikannya. */
        .nav {
            margin-left: auto;
            display: flex; align-items: center; gap: 4px;
        }

        .nav a.navlink {
            position: relative;
            text-decoration: none; color: var(--ink-soft);
            font-size: 14.5px; font-weight: 500;
            padding: 9px 13px; border-radius: var(--r-sm);
            transition: color .2s var(--ease), background .2s var(--ease);
        }
        .nav a.navlink:hover { color: var(--sky-700); background: var(--sky-50); }

        /* Garis bawah penanda: scaleX 0 -> 1 dari kiri, bukan fade,
           jadi tumbuh searah gerakan membaca. */
        .nav a.navlink::after {
            content: ""; position: absolute;
            left: 13px; right: 13px; bottom: 3px; height: 2px;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--sky-400), var(--sky-700));
            transform: scaleX(0); transform-origin: left;
            transition: transform .32s var(--ease);
        }
        .nav a.navlink:hover::after,
        .nav a.navlink.is-active::after { transform: scaleX(1); }

        /* Section yang sedang dibaca: warna brand penuh, garis bawah
           tetap terlihat, latar sedikit lebih kuat. */
        .nav a.navlink.is-active {
            color: var(--sky-800); background: var(--sky-50); font-weight: 600;
        }
        .nav a.navlink:focus-visible,
        .nav-toggle:focus-visible,
        .to-top:focus-visible {
            outline: 2px solid var(--sky-500); outline-offset: 2px;
        }

        /* ---------- tombol hamburger ----------

           Tiga batang yang morph jadi X. Batangnya punya `transform-origin`
           di tengah supaya berputar pada sumbunya sendiri, bukan di
           ujung -- itu yang bikin garis tengah "naik" saat berubah. */
        .nav-toggle {
            display: none;                 /* desktop: disembunyikan */
            margin-left: auto;
            width: 42px; height: 42px;
            padding: 0; border: 1px solid var(--line); border-radius: 12px;
            background: #fff; cursor: pointer;
            box-shadow: var(--sh-xs);
            transition: box-shadow .25s var(--ease), border-color .25s var(--ease),
                        transform .25s var(--spring);
        }
        .nav-toggle:hover { border-color: var(--sky-200); box-shadow: var(--sh-sm); }
        .nav-toggle:active { transform: scale(.94); }

        .nav-toggle span {
            display: block;
            width: 18px; height: 2px; margin: 0 auto; border-radius: 2px;
            background: var(--ink);
            transition: transform .34s var(--ease), opacity .2s var(--ease),
                        width .34s var(--ease);
        }
        .nav-toggle span + span { margin-top: 4px; }

        /* Batang atas turun, bawah naik, tengah berputar 90 derajat jadi vertikal.
           Lebar batang tengah dikecilkan supaya tidak terlihat lebih tebal dari
           dua batang lain saat jadi vertikal. */
        .nav-toggle[aria-expanded="true"] span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .nav-toggle[aria-expanded="true"] span:nth-child(2) { width: 0; opacity: 0; }
        .nav-toggle[aria-expanded="true"] span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        /* ---------- tombol ---------- */
        .btn {
            position: relative; overflow: hidden;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            text-decoration: none; font-weight: 600; font-size: 14.5px;
            padding: 12px 22px; border-radius: var(--r);
            border: 1px solid transparent; cursor: pointer;
            transition: transform .18s var(--ease), box-shadow .28s var(--ease),
                        background .22s var(--ease), border-color .22s var(--ease);
        }

        /* Sheen: cahaya melintas saat hover. Pure CSS, jadi tidak ada JS
           yang perlu dibersihkan saat navigasi. */
        .btn::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(120deg, transparent 20%,
                        rgb(255 255 255 / .28) 50%, transparent 80%);
            transform: translateX(-120%);
            transition: transform .7s var(--ease);
            pointer-events: none;
        }
        .btn:hover::after { transform: translateX(120%); }
        .btn:active { transform: translateY(1px) scale(.985); }

        .btn-primary {
            background: linear-gradient(135deg, var(--sky-600), var(--sky-700));
            color: #fff; box-shadow: var(--sh-brand);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -6px rgb(2 132 199 / .42), 0 3px 8px -2px rgb(2 132 199 / .26);
        }
        .btn-primary:active { transform: translateY(0) scale(.985); }

        .btn-ghost { border-color: var(--line); color: var(--ink); background: #fff; box-shadow: var(--sh-xs); }
        .btn-ghost:hover {
            border-color: var(--sky-300); color: var(--sky-700);
            box-shadow: var(--sh-md); transform: translateY(-2px);
        }

        .btn-lg { padding: 15px 28px; font-size: 16px; }

        /* Ikon di dalam tombol.

           Symbol ikon digambar dengan `fill: none` + `stroke: currentColor`,
           jadi satu gaya garis bisa dipakai ulang di seluruh halaman. Yang
           tidak bisa adalah lambang-brand: WhatsApp berbentuk siluet
           penuh, dan kalau hanya digores akan terbaca sebagai gelembung
           kosong, bukan sebagai logo.

           Perbedaan itu ditulis di sini sebagai kelas `.i-solid`, bukan
           di dalam `<symbol>`, supaya aturan "semua ikon lain bergaya
           garis" tetap berlaku untuk apa pun yang ditambahkan nanti.

           `flex: none` supaya ikon tidak ikut gepeng kalau teks tombol
           panjang, dan ukuran dikunci supaya tidak mewarisi lebar penuh
           dari elemen svg di dalam flex. */
        .btn svg {
            width: 17px; height: 17px; flex: none;
            fill: none; stroke: currentColor;
            /* 2.4, bukan 1.9. Pada 17px, angka 1.9 hanya menghasilkan
               ~1.35px goresan efektif -- lihat catatan panjang di
               `.mock__ico svg` soal kenapa stroke-width ditulis
               untuk viewBox 24px, bukan untuk ukuran tampil. */
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }
        .btn svg.i-solid { fill: currentColor; stroke: none; }

        /* Tombol di dalam panel gelap.

           Ghost terang (putih dengan border abu) yang dipakai di section
           terang akan hilang di atas dasar biru tua -- teks gelapnya
           jadi tidak terbaca. Di sini diganti versi gelap: isian
           transparan, border putih transparan, teks putih.

           Selector-nya `.hero-panel .btn-ghost`, bukan mengganti
           `.btn-ghost` global, supaya tombol ghost di section terang
           tidak ikut berubah. */
        .hero-panel .btn-ghost {
            background: rgb(255 255 255 / .08);
            border-color: rgb(255 255 255 / .26);
            color: #fff;
            box-shadow: none;
            backdrop-filter: blur(4px);
        }
        .hero-panel .btn-ghost:hover {
            background: rgb(255 255 255 / .16);
            border-color: rgb(255 255 255 / .5);
            color: #fff;
            box-shadow: 0 10px 24px -8px rgb(2 8 20 / .5);
        }

        /* Tombol primary di panel gelap pakai warna lebih terang dari
           versi section terang: biru medium di atas dasar biru tua
           memang inadequate: kontrasnya tidak cukup untuk teks putih. */
        .hero-panel .btn-primary {
            background: linear-gradient(135deg, var(--sky-400), var(--sky-500));
            color: var(--sky-950);
            box-shadow: 0 10px 26px -8px rgb(56 189 248 / .55);
        }
        .hero-panel .btn-primary:hover {
            box-shadow: 0 14px 32px -8px rgb(56 189 248 / .70);
        }

        /* ---------- hero ----------

           Bahasa visual mengikuti pola yang dipakai halaman produk
           modern: satu PANEL gelap besar dengan radius besar, teks
           putih di dalamnya, dan mockup perangkat yang terdorong keluar
           dari panel di sisi kanan.

           Alasannya panel gelap, bukan halaman gelap: landing page ini
           punya lima section panjang. Kalau seluruh halaman gelap,
           turun drastis jumlah kata yang bisa dibaca tanpa mapy --
           dan bagian fitur (yang justru inti ajakan) jadi tidak
           terbaca. Panel gelap hanya di bagian awal: kontras tinggi di
           tempat orang pertama kali memutuskan staying atau leaving,
           lalu langsung kembali terang dan tenang. */
        .hero { padding: 40px 0 20px; position: relative; }

        /* Panel: sudut membulat besar + gradasi diagonal. Radius besar
           itu yang bikin blok sebesar ini terbaca sebagai "kartu
           desain", bukan kotak teks yang kebetulan gelap.

           Gradasinya empat titik, bukan tiga warna tajam: tiga warna
           dengan stop yang jauh (0% / 55% / 100%) bikin ada garis
           perpindahan yang terlihat di tengah panel. Dengan empat
           titik yang lebih dekat, transisinya jadi rata dan tidak
           ada "jahitan" di bagian yang paling jarang dilihat. */
        .hero-panel {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            border-radius: 34px;
            padding: 62px 58px;
            background:
                linear-gradient(145deg,
                    #0c4a6e 0%,
                    var(--sky-900) 28%,
                    var(--sky-950) 62%,
                    #05192b 100%);
            box-shadow: 0 40px 90px -44px rgb(2 132 199 / .70),
                        0 8px 24px -12px rgb(8 47 73 / .55);
        }

        /* Teksur lembut tanpa geometri.

           Semula di sini ada pola grid: dua `linear-gradient` 1px yang
           diulang dengan `background-size: 54px`, diberi mask radial
           supaya memudar di tepi. Tongkosannya kelihatan sekali saja.

           Grid itu memang enak dilihat, tapi tidak mulur: dua garis
           horizontal dan dua garis vertikal yang berulang membuat
           kotak-kotak. Yang dimaksud "tekstur halus" jadi terbaca
           sebagai "kisi" -- dan di panel sebesar ini, kisi yang
           memotong seluruh area konten justru paling mengganggu,
           karena ia menarik mata ke struktur, bukan ke teks.

           Grid 54px juga tidak seragam antar perangkat. Di layar
           Retina atau layar kecil, garis 1px itu bisa bertemu atau
           berjarak 1-2px dan berubah jadi kabut abu. Jadi di satu
           tempat kisi terlihat tegas, di tempat lain jadi noise.

           Diganti dua gumpalan cahaya besar yang sangat lembut.
           Tidak ada tepi, tidak ada pengulangan, jadi tidak mungkin
           membentuk kotak. Sisa kesan "ada sesuatu di sini" cukup
           untuk membuat panel terasa berdepth, tanpa grid.

           `inset: -30%` supaya gradasi ini lebih besar dari panel dan
           tidak pernah menyentuh tepinya -- kalau sampai tepi, tepi
           itu sendiri jadi garis yang bisa dibaca. */
        .hero-panel::before {
            content: ""; position: absolute;
            inset: -30%; z-index: 0;
            background:
                radial-gradient(ellipse 60% 50% at 22% 30%,
                    rgb(56 189 248 / .14), transparent 70%),
                radial-gradient(ellipse 55% 45% at 78% 72%,
                    rgb(14 165 233 / .10), transparent 70%);
            pointer-events: none;
        }
        /* Cahaya biru di sudut kanan, tetap jadi sumber "terang"
           yang arahnya ke mockup. Dipisah dari ::before supaya dua
           lapisan ini tidak saling menimpa saat dianimasikan. */
        .hero-panel::after {
            content: ""; position: absolute; z-index: 0;
            width: 640px; height: 640px;
            top: -240px; right: -160px;
            border-radius: 50%;
            background: radial-gradient(circle, rgb(14 165 233 / .42), transparent 66%);
            filter: blur(70px);
            animation: drift-b 24s ease-in-out infinite alternate;
            pointer-events: none;
        }
        .hero-panel > * { position: relative; z-index: 1; }

        /* Kolom mockup dibuat lebih lebar dari kolom teks supaya HP
           boleh keluar dari panel di kanan tanpa terpotong wrap. */
        .hero-inner { display: grid; gap: 48px; grid-template-columns: 1.06fr .94fr; align-items: center; }

        .eyebrow {
            display: inline-flex; align-items: center; gap: 9px; margin-bottom: 24px;
            font-size: 12.5px; font-weight: 600;
            /* Di dalam panel gelap, warna biru muda di atas tidak
               terbaca. Ganti ke versi gelap: teks terang di atas
               dasar biru transparan. */
            color: var(--sky-200);
            background: rgb(56 189 248 / .12);
            border: 1px solid rgb(125 211 252 / .28);
            padding: 7px 15px 7px 11px; border-radius: 999px;
            backdrop-filter: blur(4px);
        }
        /* Titik berdenyut: penanda "aktif" tanpa perlu kata. */
        .eyebrow::before {
            content: ""; width: 7px; height: 7px; border-radius: 50%;
            background: var(--sky-400); flex: none;
            animation: pulse 2.4s var(--ease) infinite;
        }

        h1 {
            margin: 0 0 22px;
            font-size: clamp(36px, 5.2vw, 55px); line-height: 1.06;
            letter-spacing: -.038em; font-weight: 800;
            color: #fff;
        }
        /* Gradien pada kata kunci, dengan `color` sebagai fallback untuk
           browser lama yang tidak mendukung background-clip: text. */
        h1 em {
            font-style: normal;
            /* Fallback untuk browser lama: biru terang, bukan biru
               gelap -- di atas dasar gelap `--sky-700` hampir hilang. */
            color: var(--sky-300);
            background: linear-gradient(120deg, var(--sky-400), var(--sky-200) 60%, #fff);
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .lede {
            margin: 0 0 34px; font-size: 18.5px; line-height: 1.65;
            color: var(--sky-100); opacity: .92; max-width: 56ch;
        }

        .cta { display: flex; flex-wrap: wrap; gap: 13px; }

        .note {
            margin-top: 26px; font-size: 13.5px;
            color: var(--sky-200); opacity: .8;
            display: flex; align-items: flex-start; gap: 9px;
        }

        /* Ikon di depan .note.

           Semula `&#9432;` (diamond with minus sign) -- glyph font,
           masalah yang sama seperti emoji di kartu fitur: bentuknya
           beda antar platform. Sekarang SVG, jadi selalu sama.

           `flex: none` supaya ikon tidak ikut memendek kalau teksnya
           panjang, dan `margin-top` supaya optiknya sejajar dengan
           baris teks pertama, bukan menempel paling atas. */
        .note > svg {
            width: 16px; height: 16px; flex: none; margin-top: 2px;
            fill: none; stroke: currentColor;
            /* 2.4, bukan 1.9: ~1.6px goresan efektif, bukan ~1.27px
               yang tinggal jadi kabut abu. Lihat `.mock__ico svg`. */
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }

        /* ---------- mockup perangkat ----------

           Dua perangkat: satu HP besar di depan, satu kartu laporan
           di belakangnya. Susunannya yang bikin hero terasa "didesain",
           bukan sekadar teks di samping gambar.

           Kunci penghitungnya ada di `.hero-inner > *:last-child`:
           mockup diberi `margin-right` negatif supaya keluar melewati
           tepi kanan panel. Tanpa itu, HP-nya tertahan di dalam kotak
           dan efek "tembus" yang jadi ciri desain ini hilang. */
        .hero-art { position: relative; }

        /* Bayangan besar di bawah perangkat. Tanpa ini perangkat
           terlihat menempel pada panel, bukan melayang di atasnya.

           `radial-gradient` dengan `ellipse` sudah cukup lembut --
           yang membuatnya tetap terlihat "kotak" adalah blur-nya
           kecil (20px) sementara warnanya pekat. Blur diperbesar
           dan alpha diturunkan supaya bayangan benar-benar memudar
           ke gradasi panel, tanpa tepi yang bisa dibaca. */
        .hero-art::after {
            content: ""; position: absolute;
            left: 50%; bottom: -10px; width: 82%; height: 70px;
            margin-left: -41%;
            background: radial-gradient(ellipse, rgb(2 8 20 / .42), transparent 72%);
            filter: blur(32px);
            pointer-events: none;
        }

        /* ---------- mockup digeser sendiri-sendiri ----------

           `.phone` dan `.art-card` punya tiga movement yang semuanya
           menulis properti `transform`:

             1. kemiringan desain      rotate(-4deg) / rotate(7deg)
             2. animasi masuk          translateY(44px) -> 0
             3. geseran oleh pengguna  --dx / --dy

           Ditulis langsung, tiga-tiganya saling menimpa dan hanya
           yang terakhir menang. Karena itu TIGA-TIGANYA dipisah jadi
           custom property lalu digabung sekali di `transform` milik
           masing-masing elemen. JS hanya menulis `--dx`/`--dy`,
           CSS yang menyusun sendiri, dan tidak ada lagi yang
           perlu tahu urutan penulisan.

           `@property` wajib, bukan opsional: tanpapendaftaran itu,
           `--fy` yang dianimasikan oleh `@keyframes` akan melompat
           antar nilai (diskret), bukan interpolate -- ngambangnya
           jadi kedip, bukan melayang.
        ============================================================ */

        /* Jarak geser, ditulis JS. */
        @property --dx { syntax: "<length>"; inherits: false; initial-value: 0px; }
        @property --dy { syntax: "<length>"; inherits: false; initial-value: 0px; }

        /* Ketinggian animasi masuk. 44px di dasar, 0 saat `.is-in`. */
        @property --ty { syntax: "<length>"; inherits: false; initial-value: 0px; }

        /* Ngambangnya: tinggi dan kemiringan tambahan, dianimasikan
           tanpa henti. */
        @property --fy { syntax: "<length>"; inherits: false; initial-value: 0px; }
        @property --fr { syntax: "<angle>";  inherits: false; initial-value: 0deg; }

        /* Ngambang HP: naik-turun 9px, miring 1.2 derajat.

           Durasi 7.5s, bukan bulat, supaya tidak perlu `animation-delay`
           yang berbeda untuk setiap elemen. Nilai yang sama persis
           tapi durasi berbeda sudah cukup membuat keduanya terlihat
           tidak sinkron -- dan itulah yang membuatnya tidak terlihat
           diprogram. */
        @keyframes ngambang-hp {
            0%, 100% { --fy: 0px;   --fr: 0deg;   }
            50%      { --fy: -9px;  --fr: 1.2deg; }
        }

        /* Kartu belakang: arah miring dibalik dan lebih pelan, supaya
           yang di belakang tidak bergerak searah dengan yang depan. */
        @keyframes ngambang-kartu {
            0%, 100% { --fy: 0px;   --fr: 0deg;   }
            50%      { --fy: -7px;  --fr: -1deg;  }
        }

        /* State saat sedang dipegang.

           Transisi `--dx`/`--dy` DIMATIKAN di sini, dan itu disengaja:
           keduanya ditulis JS setiap `pointermove`. Kalau ikut
           bertransisi, mockup tertinggal di belakang kursor dan
           terasa lengket -- bukan mengikuti jari.

           Yang tetap bertransisi: `--ty`, karena `.is-in` tetap perlu
           animasi masuk pada elemen yang sedang digeser. */
        .phone.is-dragging,
        .art-card.is-dragging {
            cursor: grabbing;
            z-index: 3;
            transition: --ty .9s var(--ease) .12s, opacity .7s var(--ease);
        }

        /* Dipasang JS kalau pengguna meminta gerak minimal. Drag dan
           ngambang tetap jalan -- yang hilang hanya "pegas" saat
           dilepas.

           `animation` sengaja tidak disentuh di sini:
           `prefers-reduced-motion` sudah mematikan animasi lewat blok
           media query, dan mematikan dua kali hanya menambah satu
           tempat lagi yang harus dijaga sinkron. */
        .phone.no-spring,
        .art-card.no-spring {
            transition: opacity .7s var(--ease);
        }

        /* ---------- kartu laporan (belakangan) ----------

           Kartu miring di belakang HP. Fungsinya memberi kedalaman:
           dua bidang datar yang bertumpuk menghasilkan ilusi layer
           tanpa perlu 3D asli.

           Sama seperti `.phone`, latar kartunya sekarang gradasi
           dengan alpha rendah, bukan warna solid. Kalau solid,
           kartu ini jadi kotak biru kedua yang berdiri sendiri di
           panel -- dan gradasi panel terputus di tepinya.

           Alpha rendah dipakai karena kartu ini memang lapisan
           BELAKANGAN: sudah cukup jelas dari kemiringan tujuh derajat
           dan posisinya di belakang HP, tidak perlu warna pekat untuk
           terbaca. Justru semakin pekat, semakin "menempel" dan
           semakin jelas terlihat seperti elemen yang bisa diklik. */
        .art-card {
            position: absolute;
            top: 12%; right: -6%;
            width: 78%;
            /* Di bawah HP secara bawaan. Kartu ini lapisan belakang --
               tanpa z-index, urutan dokumen saja yang menentukan, dan
               begitu HP bergeser ke kiri kartu akan menutupinya bukan
               sebaliknya. `is-dragging` menaikkan nilainya di atas 3. */
            z-index: 1;
            padding: 26px 24px;
            border-radius: 24px;
            background: linear-gradient(150deg,
                rgb(3 105 161 / .78),
                rgb(12 74 110 / .70));
            border: 1px solid rgb(125 211 252 / .18);
            color: #fff;
            /* Sama seperti `.phone`: gradasi panel di belakang ikut
               terblur melewati kartu, jadi tidak ada garis potong
               di tepinya. */
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            /* Keadaan awal: naik 26px dari posisi akhir. Tanpa offset
               ini, transisi ke `translateY(0)` tidak bergerak sama
               sekali -- hanya fade, dan efek "melayang" hilang.

               Susunan sama persis dengan `.phone`, hanya byte yang
               berbeda. Ini disengaja: dua elemen yang disusun dengan
               cara berbeda akan terlihat tidak berasal dari satu
               komponen, dan mengoreksi satu tapi tidak yang lain
               jadi pekerjaan yang berulang. */
            --ty: 26px;

            transform: translate3d(var(--dx), var(--dy), 0)
                       rotate(calc(7deg + var(--fr)))
                       translateY(calc(var(--ty) + var(--fy)));

            /* Durasi 9.4s, bukan 7.5s seperti HP. Gerak dengan nilai sama
               tapi periode berbeda menghasilkan pola yang tidak
               berulang -- kalau keduanya 7.5s, ayunan tinggi dan
               rendah jatuh berbarengan dan mata menangkapnya sebagai
               satu gerakan, bukan dua benda melayang. */
            animation: ngambang-kartu 9.4s var(--ease) infinite;
            animation-delay: 1.6s;

            box-shadow: 0 30px 60px -30px rgb(2 8 20 / .55);
            opacity: 0;
            transition: --ty .8s var(--ease), opacity .6s var(--ease);
            cursor: grab;
            touch-action: none;
        }
        .art-card strong { display: block; font-size: 17px; letter-spacing: -.02em; margin-bottom: 6px; }
        .art-card span { font-size: 13px; color: var(--sky-200); line-height: 1.55; display: block; }
        .art-card::before {
            content: ""; display: block;
            width: 38px; height: 38px; border-radius: 12px;
            background: rgb(255 255 255 / .16);
            margin-bottom: 14px;
        }

        /* ---------- perangkat HP ----------

           Bingkai gelap dengan "notch" pill di atas. Widinya sengaja
           kecil (280px) supaya masih terbaca sebagai "-- ini HP" dan
           bukan tablet -- tablet terbaca sebagai browser window, yang
           justru ingin kita hindari supaya mockup terasa seperti
           aplikasi yang benar-benar dipakai di lapangan.

           BINGKAI TIDAK ADA WARNA SOLID. Semula `background` bingkai
           hampir hitam pekat, yang membuat bentuk kotak HP terasa
           seperti stiker yang ditempel di atas panel -- gradasi panel
           terputus tepat di sekitarnya dan keseluruhan hero terlihat
           dua bidang yang tidak menyambung.

           Sekarang bingkai dibuat dari gradasi transparan ke gelap,
           dengan tepi putih semi transparan. Efeknya: bentuk HP tetap
           terbaca (radius + notch + layar), tapi tidak lagi menjadi
           "lubang hitam" di tengah gradasi panel. Panel dan mockup
           membaca sebagai satu bidang dengan depth, bukan dua objek
           yang ditempel. */
        .phone {
            position: relative;
            /* Di atas kartu laporan. Keduanya establishes stacking
               context sendiri karena punya `transform`, jadi urutan
               dokumen tidak lagi cukup untuk menentukan mana yang
               menutupi mana. */
            z-index: 2;
            width: 280px;
            margin: 0 auto;
            margin-right: -34px;   /* tembus tepi kanan panel */
            padding: 11px;
            border-radius: 42px;
            /* Gradasi dari transparan (tepi) ke gelap (pusat) supaya
               siluet HP terlihat dari radius + layar, bukan dari
               warna bingkai yang solid.

               `backdrop-filter: blur()` membuat gradasi panel di
               belakang ikut terblur saat melewati bingkai -- jadi
               transisinya tidak berhenti tajam di tepi bingkai,
               tapi berlanjut mulus ke panel. Ini yang bikin HP
               terbaca "menempel" di atas gradasi, bukan ditempel
               sebagai kotak terpisah. */
            background: linear-gradient(160deg,
                rgb(30 41 59 / .55),
                rgb(15 23 42 / .78) 55%,
                rgb(2 6 23 / .90));
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            box-shadow:
                0 0 0 1px rgb(255 255 255 / .12),
                0 40px 70px -30px rgb(2 8 20 / .70),
                0 12px 28px -14px rgb(2 132 199 / .35);
            /* Keadaan awal, naik dari bawah. Offset-nya lebih besar
               dari kartu belakang supaya HP terasa paling dekat dengan
               mata, bukan sekadar fade.

               `translateY(44px)` SESUAI dengan `--ty` di bawah, jadi
               keduanya tidak perlu diubah dua kali. */
            --ty: 44px;

            /* Tiga gerakan digabung di sini, dan hanya di sini:
               `--ty` (masuk), `--dx`/`--dy` (geser), `--fy`/`--fr`
               (ngambang). Semuanya di dalam satu `transform`, jadi
               tidak ada yang bisa saling menimpa.

               Urutannya penting: geser paling luar supaya seluruh
               perangkat -- termasuk kodingnya -- bergerak sebagai satu
               blok, dan ngambang di dalam supaya ayunan tidak ikut
               menggeser posisi koding yang sedang digeser user. */
            transform: translate3d(var(--dx), var(--dy), 0)
                       rotate(calc(-4deg + var(--fr)))
                       translateY(calc(var(--ty) + var(--fy)));

            /* Ngambangnya jalan begitu mockup selesai masuk. Tanpa
               penundaan, `rise` dan `ngambang` berebut menulis
               posisi di detik yang sama. */
            animation: ngambang-hp 7.5s var(--ease) infinite;
            animation-delay: 1.2s;

            opacity: 0;
            transition: --ty .9s var(--ease) .12s, opacity .7s var(--ease);
            cursor: grab;
            touch-action: none;
        }

        /* Notch: pil kecil di atas layar, dibuat dengan pseudo-element
           supaya tidak menambah elemen DOM.

           Warnanya `#020617` pekat, bukan mengikuti bingkai yang
           sekarang translusen. Kalau ikut translucent, notch hilang
           dan tepi atas layar terlihat datar -- bentuk "HP" hilang,
           dan yang tersisa cuma kotak dengan radius besar. */
        .phone::before {
            content: ""; position: absolute;
            top: 11px; left: 50%; transform: translateX(-50%);
            width: 86px; height: 20px;
            background: #020617;
            border-radius: 0 0 12px 12px;
            z-index: 2;
        }

        /* Layar: gradasi gelap, bukan `#0f172a` solid.

           `background: var(--slate-900)` yang solid membuat layar
           terbaca sebagai kotak hitam penuh -- itu yang bikin panel
           hero terpotus. Dengan gradasi, layar masih jelas area
           konten (ganti ke kartu putih) tapi tidak lagi menjadi
           lubang hitam di atas gradasi panel. */
        .phone__screen {
            position: relative;
            border-radius: 32px;
            overflow: hidden;
            background: linear-gradient(165deg, #1e293b, #0f172a 60%, #0b1220);
            padding-top: 26px;    /* ruang untuk notch */
        }

        /* Baris status di dalam layar, supaya HP terbaca sebagai HP. */
        .phone__status {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 18px 10px;
            font-size: 10.5px; font-weight: 600;
            color: var(--slate-400);
        }
        .phone__status i {
            display: block; width: 34px; height: 9px;
            background: rgb(255 255 255 / .10);
            border-radius: 999px;
        }

        /* ---------- isi layar ----------

           Isinya statis dan dekoratif. TIDAK ada angka riil di sini:
           semua nilai ditandai dengan class `mockdata` -- aturan yang
           sama dengan mockup dashboard. Lihat catatan jujur di markup
           hero lama untuk alasannya -- angka di mockup boleh berubah
           sewaktu-waktu tanpa review karena tidak pernah muncul
           sebagai klaim performa.

           PENTING: penanda itu bukan `.mock`. `.mock` sudah dipakai
           kartu mockup dashboard (latar putih, radius, overflow), jadi
           memakainya di sini membuat setiap baris dan tombol ikut
           jadi kartu putih -- teks hilang di atas putih. Tabrakan nama
           seperti ini tidak melempar error, hanya merusak tampilan. */
        .phone__body {
            padding: 14px 16px 20px;
            display: grid; gap: 11px;
        }

        .phone__hi {
            font-size: 13px; font-weight: 700; color: #fff;
            letter-spacing: -.01em;
        }
        .phone__hi span { display: block; font-size: 10.5px; font-weight: 500; color: var(--slate-400); margin-top: 2px; }

        /* Kartu ringkas: bukan statistik yang dibaca, tapi bukti
           "ada isi". Tiga baris sudah cukup untuk memberi kesan
           data supaya isi layarnya tidak terlihat berantakan. */
        .phone__row {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 12px;
            background: rgb(255 255 255 / .045);
            border: 1px solid rgb(255 255 255 / .07);
            border-radius: 13px;
        }
        .phone__row > span:first-child {
            width: 30px; height: 30px; flex: none;
            display: grid; place-items: center;
            border-radius: 9px;
            background: rgb(56 189 248 / .16);
            color: var(--sky-300);
        }
        .phone__row > span:first-child svg {
            /* 15px x stroke 2 = ~1.25px efektif, masih di bawah batas
               Anti-Aliasing (lihat catatan di `.mock__ico svg`).
               2.6 menghasilkan ~1.63px. */
            width: 15px; height: 15px;
            fill: none; stroke: currentColor; stroke-width: 2.6;
            stroke-linecap: round; stroke-linejoin: round;
        }
        .phone__row b {
            font-size: 11px; font-weight: 600; color: var(--slate-400);
            display: block; line-height: 1.3;
        }
        .phone__row em {
            display: block; font-style: normal;
            font-size: 13px; font-weight: 700; color: #fff;
            font-variant-numeric: tabular-nums;
            letter-spacing: -.01em;
        }

        /* Baris dengan angka emerald -- penanda "uang", bahasa visual
           yang sama dengan mockup dashboard di bawah. */
        .phone__row--money em { color: var(--emerald-300); }

        /* Baris peringatan: tunggakan. Warna amber, bukan merah --
           merah di mockup akan terbaca sebagai "ada masalah di
           produk Anda sendiri". */
        .phone__row--warn > span:first-child {
            background: rgb(251 191 36 / .14);
            color: var(--amber-400);
        }
        .phone__row--warn em { color: var(--amber-400); }

        /* Tombol aksi besar di dasar layar. Ini yang membuat layar
           terbaca sebagai aplikasi yang bisa dipakai, bukan tabel.

           Teksnya TIDAK putih. Semula `color: #fff` di atas
           gradasi sky-500/sky-600, dan itu hanya memberi kontras
           2.77:1 -- gagal jauh di bawah ambang 4.5:1 untuk teks
           normal. Di layar 12px yang kecil, tombol biru terang itu
           praktis tidak terbaca teksnya.

           `--sky-950` di atas tombol biru terang justru jauh lebih
           kuat (sekitar 8:1) dan sesuai bahasa visual yang dipakai
           tombol utama di panel hero, yang juga gelap di atas biru
           terang. Jadi tombol "aksi utama" di dalam mockup sekarang
           konsisten dengan tombol utama di halaman. */
        .phone__cta {
            display: block; text-align: center;
            margin-top: 4px;
            padding: 11px;
            border-radius: 12px;
            font-size: 12px; font-weight: 700;
            color: var(--sky-950);
            background: linear-gradient(135deg, var(--sky-400), var(--sky-500));
            box-shadow: 0 8px 18px -8px rgb(14 165 233 / .60);
        }

        /* Setelah masuk viewport, perangkat naik ke posisi akhir.
           Delay sedikit lebih lama dari kartu belakang supaya
           tidak keduanya tiba bersamaan -- dan itu terasa seperti
           satu gerakan, bukan dua elemen yang kebetulan muncul.

           Yang ditulis di sini HANYA `--ty`, bukan `transform`.

           `--ty` punya `transition` sendiri, jadi nilainya naik dari
           44px ke 0 dengan sendirinya. Kalau `.is-in` menulis
           `transform: rotate(-4deg) translateY(0)` seperti sebelumnya,
           transform itu akan menimpa seluruh ungkapan yang di dalam
           `.phone` -- termasuk `--dx`/`--dy` dari drag dan `--fy`
           dari ngambangnya. Akibatnya mockup terkunci di tengah begitu
           masuk layar, dan drag mati total. */
        .hero-panel.is-in .phone,
        .hero-panel.is-in .art-card {
            opacity: 1;
        }
        .hero-panel.is-in .phone   { --ty: 0px; }
        .hero-panel.is-in .art-card { --ty: 0px; }

        /* ---------- section pratinjau ----------

           Layout DUA KOLOM: judul + penjelasan di kiri, mockup
           dashboard di kanan.

           Kenapa tidak ditumpuk vertikal seperti sebelumnya: mockup
           occupying full width memaksa user untuk menggulir melewati
           900px teks sebelum melihat visual sama sekali. Dengan
           bersanding, klaim ("satu sistem, semua kebutuhan") dan
           buktinya (dashboard) terbaca dalam satu layar, tanpa
           scroll. Mata bisa pindah kiri-ke-kanan dalam satu lintasan,
           bukan atas-bawah.

           `.mock` diperkecil supaya muat di kolom kanan (560px)
           tanpa membuat baris statistiknya terlalu rapat. */
        .preview { padding: 58px 0 46px; }
        .preview-grid {
            display: grid;
            gap: 56px;
            grid-template-columns: .85fr 1.15fr;
            align-items: center;
        }
        .preview-copy .eyebrow { margin-bottom: 20px; }
        .preview-copy h2 {
            margin: 0 0 16px;
            font-size: clamp(28px, 3.6vw, 40px);
            letter-spacing: -.032em; line-height: 1.12; font-weight: 800;
        }
        .preview-copy > p {
            margin: 0 0 26px;
            font-size: 17px; line-height: 1.65; color: var(--ink-soft);
            max-width: 46ch;
        }
        .preview-points {
            display: grid; gap: 14px; margin: 0; padding: 0;
            list-style: none;
        }
        .preview-points li {
            display: flex; align-items: flex-start; gap: 11px;
            font-size: 15px; line-height: 1.55; color: var(--ink-soft);
        }
        .preview-points svg {
            width: 19px; height: 19px; flex: none; margin-top: 1px;
            fill: none; stroke: var(--sky-600); stroke-width: 2.2;
            stroke-linecap: round; stroke-linejoin: round;
        }
        .preview-points strong { color: var(--ink); font-weight: 700; }

        /* ---------- mockup dashboard ----------

           KARTU PUTIH, bukan gelap.

           Semula kartu ini gelap (`--slate-900`) dengan bahasa visual
           "terminal". Sekarang dibalik terang karena tiga alasan:

           1. Letaknya bersebelahan dengan panel hero yang juga gelap.
              Dua blok gelap bersebelahan dengan jarak tipis di antaranya
              terbaca sebagai satu blok giant, bukan dua bagian.
           2. Mockup sudah punya konteks sendiri dari jaraknya -- tidak
              perlu warna gelap untuk deadly attention.
           3. Kartu putih di halaman terang jauh lebih mudah dibaca sebagai
              sebagai "gambar", bukan sebagai elemen UI sungguhan.
              Kalau gelap, orang bisa mengira itu widget embedded yang
              bisa diklik.

           Konsekuensi yang harus ikut ditangani: semua warna teks
           yang sebelumnya putih/transparan harus diganti ke versi gelap,
           dan kartu-kartu di dalamnya (`mock__stat`) tidak boleh
           putih juga -- kalau putih di atas putih, batasnya hilang.
           Karena itu `mock__stat` memakai abu sangat muda
           (`--bg-soft`), bukan `#fff`.

           INTERAKTIF (hover): isinya memang sesuatu yang dibaca --
           visual statis seperti kartu fitur akan terlihat kosong.
           Sumber kebenarannya tetap mockup, bukan data nyata.

           Berbeda dari kartu fitur: hover MEMANG boleh sedikit
           mengangkat. Batasnya jelas -- tidak ada tombol, tidak ada
           tautan, tidak ada `cursor: pointer` di dalam kartu ini. */
        .mock {
            position: relative;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--r-xl);
            box-shadow: var(--sh-lg), 0 26px 60px -34px rgb(2 132 199 / .30);
            overflow: hidden;
            transition: transform .45s var(--ease), box-shadow .45s var(--ease),
                        border-color .45s var(--ease);
        }

        .mock:hover {
            transform: translateY(-4px);
            border-color: var(--sky-200);
            box-shadow: var(--sh-xl), 0 38px 80px -36px rgb(2 132 199 / .42);
        }

        /* Bar judul: tiga titik + path + badge "contoh".

           Badge itu wajib, bukan hiasan: tanpa label eksplisit, angka
           mockup bisa dibaca calon pembeli sebagai performa nyata. Baris
           ini satu-satunya tempat yang jujur menyatakan status data, jadi
           tidak boleh hilang saat kartu dirapikan. */
        .mock__bar {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 14px;
            border-bottom: 1px solid var(--line);
            background: var(--bg-soft);
        }

        .mock__dots { display: flex; gap: 5px; flex: none; }
        .mock__dots i { width: 7px; height: 7px; border-radius: 50%; background: var(--sky-200); }
        .mock__dots i:nth-child(2) { background: var(--sky-300); }
        .mock__dots i:nth-child(3) { background: var(--sky-400); }

        .mock__path {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11px; color: var(--ink-soft);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .mock__badge {
            margin-left: auto; flex: none;
            font-size: 9.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            color: var(--sky-700);
            background: var(--sky-50);
            border: 1px solid var(--sky-200);
            padding: 2px 7px; border-radius: 999px;
        }

        .mock__body {
            display: grid; gap: 10px;
            grid-template-columns: 1fr 1fr;
            padding: 14px;
        }

        /* ---------- kartu angka ----------

           Kotak PERSGI, bukan rounded. Ini bukan pilihan estetika tapi
           meniru dashboard yang ditiru: kartu metrik di panel admin
           tenant memakai radius kecil, dan mockup yang melengkung
           terlihat seperti kartu Undangan, bukan aplikasi kerja.

           Urutan isi: ikon + label sebaris di atas, angka besar di
           tengah, delta paling bawah. Orang yang memindai dashboard
           selalu lihat kategorinya dulu, baru angkanya.

           Warna per kartu lewat `--accent` (teks ikon) dan
           `--accent-soft` (latar kotak ikon). Dua variabel ini ditulis
           sekali di sini, jadi tiap kartu cukup satu modifier class
           dan tidak ada aturan warna yang tersebar per ikon. */
        .mock__stat {
            --accent: var(--sky-700);
            --accent-soft: var(--sky-100);

            display: flex; flex-direction: column; gap: 8px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 12px 13px 13px;
            transition: border-color .35s var(--ease), box-shadow .35s var(--ease);
        }

        .mock__stat:hover {
            border-color: var(--accent);
            box-shadow: 0 4px 14px -8px rgb(15 23 42 / .28);
        }

        /* Tiap kartu punya warnanya sendiri supaya empat kotak tidak
           terbaca sebagai satu blok seragam:

           pemasukan  hijau  -- uang masuk
           tunggakan  amber  -- perlu perhatian
           pelanggan  biru   -- netral, data identitas
           laporan    indigo -- laporan keuangan, tidak ada di skala sky

           Amber dan hijau sengaja sama-sama gelap di atas latar
           terang. `--emerald-300` yang dulu dipakai akan pudar jadi
           abu di atas putih dan berhenti terbaca sebagai status. */
        .mock__stat--in    { --accent: #047857; --accent-soft: #d1fae5; }
        .mock__stat--warn  { --accent: #b45309; --accent-soft: #fef3c7; }
        .mock__stat--user  { --accent: var(--sky-700); --accent-soft: var(--sky-100); }
        .mock__stat--docs  { --accent: #4338ca; --accent-soft: #e0e7ff; }

        .mock__head { display: flex; align-items: center; gap: 8px; min-width: 0; }

        /* Kotak ikon. Warna teksnya bukan hardcode tapi `--accent`,
           yang diwarisi dari kartu induknya.

           `stroke: currentColor` (lihat aturan di bawah) sudah
           cukup: nilainya diambil dari `color` di sini, jadi tidak
           ada warna yang ditulis dua kali dan tidak bisa meleset.

           Modifier `.mock__ico--warn` yang dulu ada persis untuk
           memaksa ikon tunggakan jadi amber dihapus -- warnanya
           sudah datang dari `.mock__stat--warn` lewat `--accent`,
           jadi selector kedua hanya menambah satu tempat yang harus
           dijaga sinkron. */

           Kalau nanti butuh ikon dengan warna berbeda di dalam kartu
           yang sama, ubah `color` pada elemen pembungkusnya dan ikon
           otomatis mengikuti -- bukan menambah selector warna lagi. */
        .mock__ico {
            width: 24px; height: 24px; flex: none;
            display: grid; place-items: center;
            border-radius: 4px;
            background: var(--accent-soft);
            color: var(--accent);
        }

        /* Ikon diberi `width`/`height` eksplisit di atas, tapi `<svg>`
           di dalam `.mock__ico` TIDAK diberi `display: block`. SVG
           inline adalah elemen sebaris, jadi ia duduk di baseline dan
           whitespace di markup ikut menambah tinggi kotak -- di 14px
           basta untuk menggeser ikon naik beberapa piksel dan membuatnya
           terlihat tidak center.

           `display: block` menutup celah baseline itu. Bukan detail
           sepele: tanpa ini, "kotak ikon" terlihat tidak simetris --
           dan di mockup yang tugasnya terlihat rapi, hal pertama yang
           mata baca justru misalignment, bukan detail 2px ini. */
        /* Ikon di dalam `.mock__ico` (14px).

           `stroke-width: 2` soundskyali cukup, tapi angka itu
           ditulis untuk `viewBox="0 0 24 24"`, BUKAN untuk ukuran
           14px yang benar-benar tampil. SVG menyalin viewBox ke
           kotak 14px dengan skala 14/24, jadi goresan 2px jadi
           benar-benar 2 x (14/24) = 1.17px di layar.

           Di bawah ~1.5px, Anti-Aliasing browser membagi satu
           goresan ke dua piksel dan masing-masingnya diberi bobot
           ~0.5 -- hasilnya bukan "tipis" tapi "kabut abu" yang
           hampir tidak kelihatan di atas latar terang. Di layar
           Retina (devicePixelRatio 2) angka efektifnya turun lagi
           ke ~0.58 device pixel, di bawah batas yang bisa digambar
           sama sekali.

           Makanya ikon yang paling kecil justru yang paling
           hilang: `.mock__ico` 14px dan `.mock__delta` 11px
           keduanya lebih buram dibanding `.card .ico` yang 23px.

           2.7 di sini menghasilkan ~1.58px -- di atas batas itu.
           Harus Cecil dari yang terlihat "tebal": simbolnya berupa
           garis outline, bukan bentuk pekat, dan digambar pada
           warna accent yang sudah gelap. Terlalu tebal akan mulai
           menutup jarak antar garis di `i-docs` yang punya tiga
           garis horizontal berdekatan (y=8, y=12, y=16). */
        .mock__ico svg {
            display: block;
            width: 14px; height: 14px;
            fill: none; stroke: currentColor;
            /* 2.7, bukan 2: lihat catatan di atas. */
            stroke-width: 2.7;
            stroke-linecap: round; stroke-linejoin: round;
        }

        .mock__label {
            font-size: 10.5px; font-weight: 500; letter-spacing: .02em;
            color: var(--ink-soft);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        /* Angka tabular: tanpanya, digit akan berganti lebar saat
           dihitung -- angka yang melompat-lompat terlihat glitch.

           Angka sengaja menempel ke delta-nya, bukan terdorong ke dasar
           kartu: kalau melompot ke bawah, jarak ke delta jadi jauh dan
           kartu sebaris terlihat tidak nyambung.

           Warna hijau tua, bukan emerald terang: angka `--emerald-300`
           yang sebelumnya terlihat bagus di atas gelap akan pudar
           di atas putih dan berhenti terbaca sebagai angka. */
        .mock__value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 19px; font-weight: 600; letter-spacing: -.02em;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        /* Delta: ikon panah, bukan karakter segitiga.

           Karakter segitiga itu glyph font -- bentuknya beda antar
           platform, masalah yang sama seperti emoji di kartu fitur,
           hanya lebih kecil dan lebih sering luput. SVG selalu sama. */
        .mock__delta {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 10.5px; color: var(--ink-mute);
        }
        /* Panah delta. 11px adalah ikon terkecil di halaman, jadi butuh
               stroke paling tebal: 3.4 menghasilkan ~1.56px goresan
               efektif. Angka ini bukan untuk "terlihat tebal" --
               untuk menolak Anti-Aliasing yang akan mengubahnya
               jadi kabut abu. Lihat catatan panjang di
               `.mock__ico svg`. */
        .mock__delta svg {
            width: 11px; height: 11px; flex: none;
            fill: none; stroke: currentColor;
            stroke-width: 3.4; stroke-linecap: round; stroke-linejoin: round;
        }
        /* Panah delta ikut warna kartu, sama seperti ikonnya. Bukan karena
           "naik = bagus": kartu tunggakan punya delta amber dengan
           panah TURUN, dan itu memang atasannya. Yang diwarnai adalah
           kartu, bukan arah trenya.

           Kedua kelas digabung karena nilai dan alasannya sama persis.
           Kalau suatu saat delta naik dan turun mau dibedakan lagi,
           aturan `.up` dan `.down` harus dipisah di sini -- bukan
           ditambahkan warna acak di markup. */
        .mock__delta.up,
        .mock__delta.down { color: var(--accent); }

        /* Tag laporan mengikuti `--accent` kartu induknya. Radius tetap
           kecil supaya konsisten dengan kotak kartu -- tag rounded
           di dalam kotak persegi terbaca sebagai elemen dari sistem
           desain yang berbeda. */
        .mock__tags { display: flex; flex-wrap: wrap; gap: 5px; }
        .mock__tags i {
            font-style: normal; font-size: 10px; font-weight: 500;
            color: var(--accent);
            background: var(--accent-soft);
            border: 1px solid transparent;
            padding: 3px 7px; border-radius: 4px;
        }

        .mock__chart { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 12px; }

        /* ---------- grafik batang ----------

           Tinggi tiap batang ditulis inline sebagai `--h`, urutan untuk
           jeda animasi sebagai `--i`. Murni CSS, tanpa JS dan tanpa gambar.

           `--i` menggantikan `:nth-child(1..6)`. Aturan nth-child bekerja,
           tapi tidak ada yang ingat harus menambah aturan baru kalau jumlah
           batang berubah -- dan kalau lupa, batang baru tumbuh bareng
           batang terakhir, bukan berurutan.

           PENTING: prefix `deck-` wajib dan tidak boleh diubah asal.
           Class polos `.bar` sudah dipakai di header mockup, dan CSS
           batang di bawah akan menimpanya: `max-width` membuat header
           selebar sempit, dan gradien biru membuat header hijau. Tabrakan
           nama kelas seperti ini tidak melempar error -- hanya diam-diam
           merusak tampilan, dan jauh lebih sulit ditelusuri. */
        .deck {
            display: flex; flex-direction: column; gap: 9px;
        }

        .deck__bars {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 8px; height: 78px;
            /* Garis dasar: tanpa itu, batang yang menempel tepi bawah
               terlihat melayang. Border dipakai (bukan background) supaya
               tidak menambah tinggi layout. */
            border-bottom: 1px solid var(--line);
        }

        .deck-bar {
            flex: 1; max-width: 42px;
            height: var(--h, 50%);
            border-radius: 5px 5px 2px 2px;
            background: linear-gradient(180deg, var(--sky-400), var(--sky-600));
            transform-origin: bottom;
            animation: grow .8s var(--ease) backwards;
            animation-delay: calc(var(--i, 0) * 65ms);
        }

        /* Batang terakhir disorot: ini yang dibaca mata lebih dulu, jadi
           juga yang paling perlu kontras. Gelap ke terang, bukan glow:
           `0 0 18px` di atas putih tidak terlihat sama sekali karena
           cahaya putih tidak kontras dengan putih. */
        .deck-bar--on {
            background: linear-gradient(180deg, var(--sky-500), var(--sky-700));
            box-shadow: 0 6px 16px -6px rgb(2 132 199 / .45);
        }

        @keyframes grow {
            from { transform: scaleY(0); }
            to   { transform: scaleY(1); }
        }

        /* Label bulan memakai aturan flex yang sama persis dengan
           batang -- `space-between`, gap, dan `max-width` -- supaya
           kotak keduanya benar-benar sejajar. Beda hanya `text-align`
           dan tanpa `flex-direction`.

           Warnanya `ink-soft`, bukan `ink-mute`: label bulan berukuran
           10.5px di atas putih cuma 2.56:1 dengan abu terang. Ini bukan
           teks dekoratif -- orang membacanya untuk mengurutkan batang
           dari kiri ke kanan, jadi bulan yang tidak terbaca bikin
           grafik kehilangan maknanya. */
        .deck__months { display: flex; justify-content: space-between; gap: 8px; }
        .deck__months span {
            flex: 1; max-width: 42px; text-align: center;
            font-size: 10.5px; letter-spacing: .03em;
            color: var(--ink-soft);
        }
        /* Bulan terakhir disorot karena batangnya yang paling tinggi,
           jadi yang paling banyak dilihat mata. Warna biru, bukan hijau:
           hijau di sini terbaca sebagai "pembaik", bukan "tertinggi". */
        .deck__months span:last-child { color: var(--sky-700); font-weight: 600; }

        /* Baris kecil di bawah mockup: jalan keluar untuk yang sudah jadi
           pelanggan. Tautan di teksnya sengaja minimal -- halaman ini
           untuk calon pembeli baru, dan setiap pintu masuk tambahan hanya
           menambah satu alasan untuk pergi sebelum sempat membaca. */
        .mock__foot {
            margin: 0; padding: 11px 16px 12px;
            border-top: 1px solid var(--line);
            background: var(--bg-soft);
            font-size: 11.5px; line-height: 1.55; text-align: center;
            color: var(--ink-soft);
        }
        .mock__foot strong { color: var(--ink); font-weight: 600; }
        .mock__foot a { color: var(--sky-700); text-decoration: none; border-bottom: 1px solid var(--sky-300); }
        .mock__foot a:hover { border-bottom-color: var(--sky-600); }

        /* ---------- section ----------

           Jarak antar section DIKECILKAN dari 80px ke 62px, dan tiap
           batas section diberi garis tipis yang memudar di kedua ujungnya
           -- bukan garis penuh.

           Kenapa bukan garis penuh: garis `border-bottom` yang memotong
           seluruh lebar halaman terbaca sebagai "batas dokumen", bukan
           "batas antar bagian". Yang dikehendaki adalah sinyal "konten
           berganti topik", jadi gradasi yang hilang di kedua sisi jauh
           lebih tepat: mata diberi tahu ada pergantian tanpa
           sempat merasa halaman terpotong.

            Gradasi lewat `background` pada pseudo-element supaya
           tidak menambah elemen DOM dan tidak mengganggu layout. */
        section { padding: 62px 0; position: relative; }

        /* Garis pemisah antar section.

           Pseudo-element, bukan `border-bottom` pada section itu
           sendiri: border akan ikut berubah lebarnya saat section
           memakai padding berbeda, dan ia menempel langsung di tepi
           konten -- terlalu rapat. Pseudo-element dengan
           `top: -1px` bisa berdiri sendiri sebagai garis transisi. */
        section + section::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg,
                transparent 0%,
                var(--sky-300) 18%,
                var(--sky-300) 82%,
                transparent 100%);
            pointer-events: none;

            /* Garis tumbuh dari tengah saat section masuk layar.
               `scaleX` (bukan `width`) supaya jalan di compositor dan
               tidak memicu layout tiap frame. */
            transform: scaleX(0);
            transform-origin: center;
            transition: transform .9s var(--ease);
        }
        section.is-in::before { transform: scaleX(1); }

        /* Section bertinted (cara kerja) tetap dibedakan lewat latar,
           jadi garis pemisah di atasnya dimatikan supaya tidak ada
           dua sinyal untuk batas yang sama. */
        section.tint::before { display: none; }

        section.tint {
            background: linear-gradient(180deg, var(--bg-soft), #fff);
        }

        /* Section pratinjau (mockup) tidak perlu pemisah tambahan:
           mockup-nya sudah punya bayangan sendiri yang jelas batasnya,
           dan garis tepat di atas kartu besar terasa seperti pagar. */
        section.preview::before { display: none; }

        .sec-head { max-width: 64ch; margin-bottom: 38px; }
        .sec-head h2 {
            margin: 0 0 14px; font-size: clamp(27px, 3.5vw, 36px);
            letter-spacing: -.03em; line-height: 1.16; font-weight: 800;
        }
        .sec-head p { margin: 0; color: var(--ink-soft); font-size: 17.5px; line-height: 1.6; }

        .grid { display: grid; gap: 22px; grid-template-columns: repeat(auto-fit, minmax(262px, 1fr)); }

        /* ---------- kartu fitur ----------

           Kartu INERT: bukan tautan, bukan tombol, tidak bisa diklik.
           Karena itu TIDAK BOLEH terangkat saat hover -- menghidupkan
           sesuatu yang tidak bisa diklik hanya menggoda user untuk
           mencobanya lalu kecewa.

           Yang boleh bergerak hanya yang JELAS tidak bisa diklik:
           cahaya, warna, dan isi. Semua efek di bawah dipicu hover
           pada kartu, tapi tidak pernah mengubah posisinya -- tidak ada
           `translate`, tidak ada `scale`, tidak ada shadow yang membesar.

           Shadow-nya aktif (selalu tampil, bukan nyaris transparan),
           jadi kartu terbaca sebagai panel tersendiri yang terangkat
           dari section. */

        /* Lapisan 1: kartu itu sendiri.

           `overflow: hidden` itu syarat, bukan pilihan: pseudo-element
           cahaya menyapu di bawah dipotong tepi kartu. Tanpa itu,
           cahaya akan keluar sebagai kotak tembus di atas teks
           dan merusak radius-nya. */
        .card {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            padding: 28px;
            box-shadow: var(--sh-md);
            transition: border-color .4s var(--ease), box-shadow .4s var(--ease);
        }

        /* Garis accent di tepi atas. Tumbuh dari tengah saat hover.

           Dipakai `::before` dengan `inset-inline: 0` + `scaleX`, bukan
           `width` -- width memaksa layout di tiap frame, sedangkan
           scale cuma jalan di compositor. Animasi 7 kartu sekaligus
           yang memakai width akan terasa berat dan mengganggu layout halaman. */
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--sky-500), var(--sky-300), transparent);
            transform: scaleX(0);
            transform-origin: center;
            opacity: 0;
            transition: transform .55s var(--ease), opacity .35s var(--ease);
            z-index: 2;
        }

        /* Lapisan 2: cahaya yang menyapu diagonal saat hover.

           `radial-gradient` yang ikut bergerak, bukan `background-position`
           yang dianimasikan -- yang kedua memicu repaint seluruh kartu
           tiap frame, dan dengan 7 kartu sekaligus plus blur 40px di
           dalamnya, itu perbedaan yang terasa di scroll.

           Pointer-events none wajib: tanpa itu, pseudo-element jadi
           target hover dan kursor berubah jadi teks di area itu. */
        .card::after {
            content: '';
            position: absolute;
            inset: -60%;
            background: radial-gradient(
                circle at 30% 30%,
                rgb(56 189 248 / .13),
                transparent 55%
            );
            opacity: 0;
            transition: opacity .5s var(--ease);
            pointer-events: none;
            z-index: 0;
        }

        /* Isi kartu di atas cahaya. Tanpa ini, pseudo-element menutup
           teks dan hover efeknya hilang total. */
        .card > * { position: relative; z-index: 1; }

        .card:hover {
            border-color: var(--sky-200);
            /* Hanya shadow yang sedikit lebih dalam. Bukan shadow yang
               "naik" seperti kartu yang bisa diklik -- bedanya
               sengaja dibuat tipis supaya tidak terbaca sebagai
               permukaan yang menggoda untuk ditekan. */
            box-shadow: var(--sh-lg);
        }

        .card:hover::before { opacity: 1; transform: scaleX(1); }
        .card:hover::after  { opacity: 1; }

        /* Ikon: kotak warnanya bergerak, glyph di dalamnya tidak.

           Glyph ikut bergerak akan terbaca sebagai tombol yang
           "menekan" ikon -- persis hal yang harus dihindari di
           kartu inert. Yang bergerak di sini cuma gradasi, yang
           jelas-jelas dekoratif.

           Ukuran lewat CSS, bukan `font-size`. Isi kotak ini dulu
           emoji, jadi dimensinya dibuat untuk font 22px. Sekarang
           isinya <svg> yang ukuran-nya dikontrol `width`/`height`,
           dan `font-size` tidak lagi berlaku. Tanpa perubahan ini,
           ikon ikut mewarisi ukuran teks di sekitarnya. */
        .card .ico {
            width: 46px; height: 46px; border-radius: 13px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, var(--sky-50), var(--sky-100));
            color: var(--sky-700); margin-bottom: 17px;
            box-shadow: var(--sh-sm);
            transition: background .45s var(--ease), box-shadow .45s var(--ease),
                        border-radius .45s var(--ease);
        }

        .card:hover .ico {
            background: linear-gradient(135deg, var(--sky-100), var(--sky-200));
            border-radius: 10px;
            box-shadow: var(--sh-md);
        }

        /* Bentuk ikon di dalam kotak.

           `fill: none` + `stroke: currentColor` membuat ikon mengikuti
           warna teksnya, jadi saat hover warnanya berubah, ikon ikut
           berubah tanpa selector tambahan. Ujung `round` supaya tidak
           tajam di ukuran kecil. */
        .card .ico svg {
            width: 23px; height: 23px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.75;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .card h3 { margin: 0 0 8px; font-size: 17.5px; letter-spacing: -.016em; font-weight: 700; }
        .card p { margin: 0; font-size: 14.5px; line-height: 1.62; color: var(--ink-soft); }

        /* ---------- animasi masuk kartu ----------

           `.reveal.shown` memakai `rise` yang sama dengan elemen lain,
           tapi kartu dapat versi sendiri: masuk dari bawah lebih jauh
           dan sedikit miring, supaya barisan kartu terasa naik sebagai
           satu blok, bukan 7 elemen yang kebetulan muncul bersamaan.

           Durasi 0.62s, bukan 0.78s seperti `rise`. Hitungannya
           berantai: 7 kartu dengan delay 70ms berarti yang terakhir
           mulai 420ms lebih lambat. Kalau tiap kartu ikut 0.78s
           penuh, baris terakhir baru selesai di 1.2s -- orang yang
           sedang scroll cepat akan lewat begitu saja lalu
           menganggap halaman lambat. */
        @keyframes card-in {
            from {
                opacity: 0;
                transform: translate3d(0, 42px, 0) rotate(-1deg);
            }
            to {
                opacity: 1;
                transform: none;
            }
        }

        .grid .card.reveal.shown { animation: card-in .62s var(--ease) var(--d, 0ms) forwards; }

        /* Ikon dan teks masuk sedikit setelah kartu selesai, supaya mata
           bisa mengikuti urutan: bayangan kartu dulu, lalu isinya.
           Delay-nya kecil (90ms) -- lebih dari itu terasa seperti
           elemen yang gagal loading. */
        .card.reveal.shown .ico { animation: fade .4s var(--ease) calc(var(--d, 0ms) + 90ms) both; }
        .card.reveal.shown h3,
        .card.reveal.shown p  { animation: fade .4s var(--ease) calc(var(--d, 0ms) + 150ms) both; }

        /* ---------- cara kerja ----------

           Panel statis, bukan elemen interaktif. Shadow aktif supaya
           menyatu dengan kartu fitur.

           Perbedaan dari versi lama: nomor langkah pindah dari pojok
           kanan ke kotak ikon besar di kiri, dan garis penghubung
           horizontal membelah antar langkah. Alasannya, tiga kartu
           bersebelahan dengan nomor di pojok kanan forced user untuk
           membaca berlawanan arah dengan alur kalimat. Nomor di kiri
           aligning dengan arah baca normal dan langsung terlihat
           sebagai urutan. */
        .steps {
            display: grid; gap: 22px;
            grid-template-columns: repeat(3, 1fr);
            counter-reset: s;
            position: relative;
        }

        .step {
            position: relative;
            padding: 30px 26px 28px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            box-shadow: var(--sh-md);
            transition: border-color .4s var(--ease), box-shadow .4s var(--ease),
                        transform .4s var(--ease);
        }
        /* Kartu boleh sedikit terangkat: ini bukan elemen yang bisa
           diklik, tapi elevate tipis cukup untuk memberi tanda "ini
           urutan" tanpa menggoda untuk ditekan. */
        .step:hover {
            border-color: var(--sky-200);
            box-shadow: var(--sh-lg);
            transform: translateY(-3px);
        }

        /* Kotak nomor: besar, di kiri, jadi penanda urutan yang dibaca
           pertama sebelum paragrafnya. */
        .step__num {
            counter-increment: s;
            width: 46px; height: 46px;
            display: grid; place-items: center;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--sky-500), var(--sky-700));
            color: #fff; font-size: 19px; font-weight: 800;
            box-shadow: var(--sh-brand);
            margin-bottom: 18px;
        }
        .step__num::before { content: counter(s); }

        .step h3 { margin: 0 0 8px; font-size: 17.5px; letter-spacing: -.018em; font-weight: 700; }
        .step p { margin: 0; font-size: 14.5px; line-height: 1.62; color: var(--ink-soft); }

        /* Tautan di dalam kartu langkah.

           Hanya langkah pertama yang punya tautan, tapi selector-nya bukan
           `.step:first-child` -- kartu yang bisa diklik adalah isi langkah
           itu, dan selector posisi akan ikut bergeser kalau nanti ada
           langkah keempat yang ditambahkan di tengah.

           Margin atas menyamakan jarak teks ke tautan dengan jarak tautan
           ke paragraf, supaya tombol kecil itu terlihat sengaja ada, bukan
           ditempel. */
        .step__cta {
            margin-top: 16px;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px; border-radius: var(--r-sm);
            background: var(--sky-50); border: 1px solid var(--sky-200);
            color: var(--sky-800); font-size: 14px; font-weight: 600;
            text-decoration: none;
            transition: background .22s var(--ease), border-color .22s var(--ease),
                        transform .18s var(--ease), box-shadow .28s var(--ease);
        }
        .step__cta:hover {
            background: #fff; border-color: var(--sky-400);
            transform: translateY(-2px); box-shadow: var(--sh-md);
        }
        .step__cta svg {
            width: 16px; height: 16px; flex: none;
            fill: none; stroke: currentColor;
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }
        .step__cta svg.i-solid { fill: currentColor; stroke: none; }

        /* Garis penghubung antar langkah.

           Ditaruh di belakang kartu (`z-index: -1`) dan dipotong
           oleh gap antar kolom. Kalau tidak dipotong, garis akan
           terlihat masuk ke dalam kartu dan merusak tepinya.

           Hanya untuk dua langkah pertama: `:not(:last-child)` --
           Step terakhir tidak punya successor untuk disambung. */
        .step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 53px;                     /* sejajar dengan tengah kotak nomor */
            left: 100%;
            width: 22px;                   /* = gap, jadi garis hanya di celah */
            height: 2px;
            background: linear-gradient(90deg, var(--sky-300), var(--sky-200));
            z-index: -1;
        }

        /* ---------- CTA penutup ---------- */
        .closer {
            position: relative; overflow: hidden;
            background: linear-gradient(150deg, var(--sky-800), var(--sky-950));
            color: #fff; border-radius: var(--r-xl);
            padding: 48px 44px; text-align: center;
            box-shadow: 0 20px 50px -18px rgb(8 47 73 / .55), 0 4px 12px -4px rgb(8 47 73 / .3);
        }
        /* Tekstur lembut tanpa geometri.

           Pola grid yang dulu ada di sini (dua `linear-gradient` 1px
           berulang) dihapus dengan alasan yang sama seperti di
           `.hero-panel::before`: garis horizontal dan vertikal yang
           berulang pasti membentuk kotak, dan di panel gelap sebesar
           ini kisi jadi hal pertama yang dilihat mata.

           Diganti satu gumpalan cahaya di belakang tombol tengah,
           tempat mata sudah diarahkan. Tidak ada tepi, jadi tidak
           mungkin jadi garis. */
        .closer::before {
            content: ""; position: absolute;
            inset: -25%;
            background:
                radial-gradient(ellipse 55% 60% at 50% 45%,
                    rgb(56 189 248 / .13), transparent 72%);
            pointer-events: none;
        }
        /* Glow berputar pelan di belakang teks. */
        .closer::after {
            content: ""; position: absolute;
            width: 520px; height: 520px; top: -180px; left: 50%; margin-left: -260px;
            background: radial-gradient(circle, rgb(56 189 248 / .26), transparent 62%);
            filter: blur(64px); pointer-events: none;
            animation: drift-b 20s ease-in-out infinite alternate;
        }
        .closer > * { position: relative; z-index: 1; }

        .closer h2 { margin: 0 0 14px; font-size: clamp(26px, 3.2vw, 34px); letter-spacing: -.028em; font-weight: 800; }
        .closer p { margin: 0 auto 30px; max-width: 54ch; color: var(--sky-100); font-size: 17px; }
        .closer .btn-primary { background: #fff; color: var(--sky-800); box-shadow: 0 8px 22px -8px rgb(0 0 0 / .4); }
        .closer .btn-primary:hover { background: var(--sky-50); }
        .closer .btn-ghost {
            background: rgb(255 255 255 / .07); border-color: rgb(255 255 255 / .3);
            color: #fff; backdrop-filter: blur(4px);
        }
        .closer .btn-ghost:hover { border-color: #fff; background: rgb(255 255 255 / .16); }

        /* Kontak alternatif di bawah tombol.

           Satu blok dua baris: label + nomor telepon, lalu alamat email.
           Ditumpuk, bukan satu baris panjang, karena pada layar sempit
           "Atau hubungi langsung: +62 882-0066-44656 astabratamgl@gmail.com"
           tidak muat tanpa membuat satu kata per baris.

           Tipografi sengaja satu tingkat lebih kecil dari tombol: ini
           jalan cadangan, bukan tujuan utama. Tapi tetap di atas warna
           teks utama -- `--sky-100` dengan opacity penuh, bukan
           `--sky-200` seperti `.note` di hero, karena yang diklik di sini
           harus terbaca sekilas tanpa membaca seluruh blok. */
        .kontak-alt {
            margin-top: 22px;
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: center; gap: 6px 10px;
            font-size: 14.5px;
        }
        .kontak-alt__label { color: rgb(255 255 255 / .72); }

        .kontak-alt a, .kontak-email a {
            display: inline-flex; align-items: center; gap: 8px;
            color: #fff; font-weight: 600;
            text-decoration: none;
            transition: opacity .2s var(--ease);
        }
        .kontak-alt a:hover, .kontak-email a:hover { opacity: .82; text-decoration: underline; text-underline-offset: 3px; }

        /* Nomor telepon diberi garis bawah tipis, bukan hanya perubahan
           warna: di atas dasar gelap, `color` saja tidak selalu terbaca
           sebagai "ini bisa diklik". */
        .kontak-alt a { text-decoration: underline; text-decoration-color: rgb(255 255 255 / .35); text-underline-offset: 4px; }
        .kontak-alt a:hover { text-decoration-color: #fff; }

        .kontak-email { margin: 10px 0 0; display: flex; justify-content: center; gap: 8px; font-size: 14.5px; }

        .kontak-alt svg, .kontak-email svg {
            width: 16px; height: 16px; flex: none;
            fill: none; stroke: currentColor;
            /* Sama seperti `.btn svg`: 2.4 supaya goresan efektifnya
               ~1.6px, bukan ~1.27px yang praktis tak terlihat. */
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
            opacity: .85;
        }

        /* ---------- tombol kembali ke atas ----------

           Pojok kanan bawah, fixed. Muncul hanya setelah halaman
           di-scroll lewat ambang tertentu, supaya tidak menambah
           navigasi yang selalu ada di layar pertama yang masih kosong.

           Catatan: `bottom`/`right` memakai `env(safe-area-inset-*)`
           supaya tidak tertutup gesture bar / notch di iPhone. */
        .to-top {
            position: fixed;
            right: max(20px, env(safe-area-inset-right));
            bottom: max(20px, env(safe-area-inset-bottom));
            z-index: 45;

            width: 46px; height: 46px;
            display: grid; place-items: center;
            border: 1px solid rgb(255 255 255 / .22);
            border-radius: 50%;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(140deg, var(--sky-500), var(--sky-700));
            box-shadow: var(--sh-brand), 0 10px 24px -10px rgb(8 47 73 / .5);

            /* Tersembunyi tanpa interaksi: opacity 0 + scale kecil +
               pointer-events none. Bukan `display: none` supaya
               transisinya tetap jalan. */
            opacity: 0;
            visibility: hidden;
            transform: translateY(14px) scale(.82);
            transition: opacity .32s var(--ease), transform .38s var(--spring),
                        visibility .32s, box-shadow .28s var(--ease);
        }
        .to-top.is-visible {
            opacity: 1; visibility: visible; transform: none;
            pointer-events: auto;
        }
        .to-top:hover {
            box-shadow: var(--sh-brand), 0 16px 34px -12px rgb(8 47 73 / .6);
        }
        .to-top:active { transform: translateY(1px) scale(.94); }

        /* Panah: dibuat dari dua garis yang berputar 45 derajat (chevron),
           bukan glyphfont -- supaya tidak bergantung font apa pun
           yang terpasang di sistem user. */
        .to-top span {
            display: block; width: 9px; height: 9px;
            border-top: 2px solid currentColor;
            border-left: 2px solid currentColor;
            transform: rotate(45deg) translate(1px, 1px);
            transition: transform .32s var(--ease);
        }
        .to-top:hover span { transform: rotate(45deg) translate(2px, 2px); }

        /* ---------- footer ---------- */
        footer { border-top: 1px solid var(--line); padding: 40px 0; background: var(--bg); }
        .foot { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; justify-content: space-between; }
        .foot p { margin: 0; font-size: 13.5px; color: var(--ink-mute); }
        .foot a {
            color: var(--sky-700); text-decoration: none; font-weight: 600; font-size: 13.5px;
            transition: color .2s var(--ease);
        }
        .foot a:hover { color: var(--sky-800); text-decoration: underline; text-underline-offset: 3px; }

        /* ---------- responsif ---------- */
        @media (max-width: 900px) {
            .hero { padding: 28px 0 12px; }

            /* Panel kehilangan radius sebesar desktop -- di layar
               sempit, radius 34px memakan hampir seluruh lebar dan
               Tepi melengkungnya tidak terbaca lagi. */
            .hero-panel { border-radius: 24px; padding: 44px 32px; }

            .hero-inner { grid-template-columns: 1fr; gap: 52px; }

            /* Di satu kolom, mockup tidak boleh lagi menindih tepi
               kanan: `margin-right` negatifnya dinolkan supaya HP
               berada di tengah dan tidak keluar dari panel. */
            .phone { margin-right: 0; }
            .art-card { right: 2%; width: 72%; }

            /* Grid pratinjau jadi satu kolom. Mockup tetap di bawah
               teks (bukan sebaliknya) karena di layar sempit urutan
               baca yang wajar: apa yang dijanjikan dulu, baru bukti. */
            .preview { padding: 44px 0 38px; }
            .preview-grid { grid-template-columns: 1fr; gap: 40px; }
            .preview-copy > p { max-width: none; }

            /* Navbar berubah jadi panel dropdown.

               `position: absolute` relatif ke `.navbar` (yang sudah
               `position: relative`) supaya panel menempel tepat di
               bawah bar -- posisi `fixed` harus menghitung tinggi
               header sendiri dan akan meleset kalau tinggi header
               berubah. */
            .nav-toggle { display: block; }

            .nav {
                /* Panel default tersembunyi. `visibility` dipakai
                   bersama `opacity` supaya transisi tetap jalan dan
                   link di dalamnya TIDAK bisa difokus saat tertutup --
                   `display: none` akan mematikan transisi.

                   `inset` men-SEJARAKKAN tepi kiri dan kanan sekaligus.
                   Menulis `right: 0` saja membuat lebar panel ikut
                   `margin-left: auto` yang diwarisi dari aturan desktop
                   -- hasilnya panel setipis tombolnya. */
                position: absolute;
                inset: calc(100% + 8px) 24px auto 24px;
                width: auto;
                margin-left: 0;

                flex-direction: column;
                align-items: stretch;
                gap: 2px;
                padding: 10px;

                /* Background hampir sepenuhnya opaque. Header memakai
                   backdrop-filter yang transparan, dan panel yang ikut
                   transparan membuat teks hero mengintip dari belakang --
                   label jadi sulit dibaca saat ada teks gelap di bawahnya. */
                background: rgb(255 255 255 / .98);
                backdrop-filter: saturate(180%) blur(20px);
                -webkit-backdrop-filter: saturate(180%) blur(20px);
                border: 1px solid var(--line);
                border-radius: var(--r-lg);
                box-shadow: var(--sh-lg);

                opacity: 0;
                visibility: hidden;
                transform: translateY(-8px);
                transition: opacity .28s var(--ease), transform .28s var(--ease),
                            visibility .28s var(--ease);
            }
            .nav.is-open { opacity: 1; visibility: visible; transform: none; }

            /* Garis bawah penanda tidak lagi berguna di panel
               vertikal -- tautan sudah satu baris penuh. */
            .nav a.navlink::after { display: none; }
            .nav a.navlink {
                padding: 12px 14px;
                font-size: 15px;
            }
            .nav a.navlink.is-active { box-shadow: inset 3px 0 0 var(--sky-500); }

            .nav .btn { width: 100%; margin-top: 6px; padding: 12px; }

            /* Panel terbuka: kunci scroll halaman di belakang.
               Tanpa ini, sentuh di panel yang lebih pendek dari
               viewport tetap ikut menggulir halaman di belakangnya. */
            html.nav-open { overflow: hidden; }
        }

        @media (max-width: 520px) {
            .wrap { padding-inline: 18px; }
            section { padding: 44px 0; }
            .closer { padding: 42px 22px; }
            .card, .step { padding: 22px 20px; }
            .step::before { top: 18px; right: 18px; width: 34px; height: 34px; font-size: 14px; }
            .step h3 { padding-right: 44px; }

            /* Panel menu ikut menyempit agar sejajar dengan teks. */
            .nav { inset: calc(100% + 8px) 18px auto 18px; }

            /* Panel hero makin rapat, dan mockup HP ikut mengecil
               supaya bingkai gelapnya tidak melebihi lebar layar. */
            .hero-panel { border-radius: 20px; padding: 34px 22px; }
            .phone { width: 240px; }
            .art-card { padding: 20px 18px; right: 0; width: 76%; }
            .art-card strong { font-size: 15px; }
            .art-card span { font-size: 12px; }

            /* Dua kolom jadi satu di layar sempit: angka 22px dalam
               setengah lebar 390px akan terpotong. */
            .mock__body { grid-template-columns: 1fr; padding: 14px; }
            .mock__value { font-size: 19px; }

            /* Tombol ke-atas sedikit lebih kecil supaya tidak menutupi
               isi di layar 360px. */
            .to-top { width: 42px; height: 42px; }
        }

        /* Layar sangat pendek (horizontal, atau jendela kecil):
           panel menu memakan tinggi viewport. Batas tinggi, bukan
           lebar, karena itu yang menentukan ruang yang tersisa. */
        @media (max-height: 520px) {
            .nav { max-height: calc(100vh - 90px); overflow-y: auto; }
        }

        /* Aksesibilitas: matikan semua gerak kalau pengguna memintanya.

           `animation: none` (!important) penting -- hanya mematikan
           transition tidak cukup, karena loop tak berujung (glow, pulse)
           tetap berjalan dan terus membakar CPU/baterai. */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }

            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
            }

            .reveal { opacity: 1 !important; transform: none !important; }
            .closer::after, .hero-panel::after { animation: none; }

            /* Perangkat mockup: tanpa JS yang memasang `.is-in`,
               `opacity: 0` di gaya dasarnya akan membuat HP dan kartu
               hilang permanen. Jadi state akhir ditulis eksplisit di
               sini, bukan hasil dari observer. */
            .phone, .art-card {
                opacity: 1 !important;
                transition: none !important;
                /* `--ty` ditulis langsung ke 0, bukan lewat transisi
                   yang sudah dimatikan baris di atas: state akhir harus
                   berdiri sendiri, bukan hasil dari animasi yang tidak
                   jalan. */
                --ty: 0px !important;
                /* Ngambangnya ikut dihentikan. Rule `*` di atas sudah
                   membuat durasinya .001ms dan iterasinya 1, jadi
                   tanpa `animation: none` elemen tetap menulis frame
                   akhir `ngambang-*` -- dan frame akhir itu bukan
                   kondisi netral, melainkan posisi ayunan. */
                animation: none !important;
            }

            /* Kartu perlu `animation: none`, bukan cuma durasi ~0.

               `card-in` berakhir dengan `forwards` dan `from` punya
               `opacity: 0`. Durasi .001ms membuat animasi itu SELESAI
               dalam sekejap -- tapi tetap menuliskan frame akhir, dan
               frame akhir kartu memang opacity 1, jadi kebetulan aman.

               Namun `fade` pada .ico/.h3/.p pakai `both` sebagai
               fill-mode, yang menyalin frame `from` ke SEBELUM animasi
               dimulai. Kalau user membuka halaman langsung di section
               fitur (lewati anchor #fitur, atau restore scroll browser),
               animasi sudah lewat -- fill-mode:none tidak reset, tapi
               `animation: none` memaksa elemen kembali ke gaya
               dasarnya tanpa perlu menebak urutan waktu. */
            .grid .card.reveal.shown,
            .card.reveal.shown .ico,
            .card.reveal.shown h3,
            .card.reveal.shown p { animation: none !important; }

            /* Cahaya dan garis accent ikut hilang -- bersama transisi
               di atas sudah otomatis, tapi pseudo-element perlu
               state akhir yang eksplisit supaya kartu tidak terlihat
               seperti belum di-hover dalam mode ini. */
            .card::after { opacity: 0; }
            .card::before { transform: scaleX(1); opacity: .5; }

            /* Batang grafik: `grow` punya `backwards` sebagai fill-mode,
               jadi sebelum delay-nya habis batang TIDAK tampil sama
               sekali -- bukan transparan, tapi setback ke scaleY(0).
               Dengan enam batang dan jeda 65ms, ada ~325ms di mana
               grafik tampil setengah kosong.

               `animation: none` mengembalikan batang ke gaya
               dasarnya (`height: var(--h)`) tanpa menunggu delay,
               jadi grafik langsung utuh. */
            .deck-bar { animation: none !important; }
        }
    </style>
</head>

<body>
    {{--
        Sprite ikon SVG.

        Kenapa sprite dan bukan emoji: emoji dirender oleh font milik
        sistem operasi, jadi bentuk dan warnanya beda di tiap perangkat --
        Windows (Segoe UI Emoji) tidak sama dengan Mac (Apple Color Emoji)
        atau Android (Noto). Sebagian bahkan tampil sebagai kotak kosong
        kalau fontnya tidak ada. Halaman ini tidak punya build step, jadi
        tidak bisa mengandalkan icon font seperti Font Awesome.

        SVG stabil di semua platform: bentuknya ditentukan file ini,
        bukan font yang kebetulan terpasang, dan warnanya mengikuti
        `currentColor` sehingga otomatis ikut berubah saat hover.

        Bentuk outline (isi `none`, `stroke` saja) dipilih supaya
        konsisten dengan kartu putih dan tetap ramping di layar kecil.

        Letak di awal `<body>`, bukan akhir: `<use>` yang menunjuk simbol
        yang belum ter-parse akan menggambar apa pun atau tidak sama
        sekali. Di awal body, sprite sudah ada sebelum kartu mana pun
        dirender.

        `display: none` membuat browser modern melewati seluruh subtree
        ini, termasuk saat menghitung ulang accessibility tree.
    --}}
    <svg width="0" height="0" aria-hidden="true" focusable="false"
         style="position:absolute;display:none" xmlns="http://www.w3.org/2000/svg">
        <defs>
            {{-- Air minum: tetes air dengan garis di tengahnya. --}}
            <symbol id="i-water" viewBox="0 0 24 24">
                <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                <path d="M12 7v10"/>
            </symbol>

            {{-- Internet &amp; TV: monitor dengan antena di atasnya.

                 Gelombang antenanya dua busur dengan titik tengah sama
                 (x=12) dan radius bertingkat, jadi tiap busur terpisah
                 jelas dari yang di atasnya.

                 Semula tiap gelombang adalah busur penuh yang membentang
                 hampir selebar monitor di bawahnya. Dua bentuk sebesar
                 itu saling menindih, dan yang terbaca dari hasilnya
                 hanya garis-basanya. --}}
            <symbol id="i-internet" viewBox="0 0 24 24">
                <path d="M7.4 10.6a6.5 6.5 0 0 1 9.2 0"/>
                <path d="M4.2 7.6a11 11 0 0 1 15.6 0"/>
                <circle cx="12" cy="14.2" r="1.1"/>
                <rect x="2.5" y="16.5" width="19" height="5" rx="1.8"/>
            </symbol>

            {{-- Pembayaran sampah: lorry. --}}
            <symbol id="i-truck" viewBox="0 0 24 24">
                <path d="M10 17h4V5H2v12h3"/>
                <path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8"/>
                <circle cx="7.5" cy="17.5" r="2.5"/>
                <circle cx="17.5" cy="17.5" r="2.5"/>
            </symbol>

            {{-- Data pelanggan: dua orang. --}}
            <symbol id="i-users" viewBox="0 0 24 24">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </symbol>

            {{-- Keuangan: grafik batang.

                 Semula hanya tiga garis vertikal tanpa konteks apa pun --
                 tidak ada sumbu, tidak ada label, tidak ada bentuk yang
                 bisa dijadikan acuan. Di ukuran 23px bentuk itu hanya
                 terbaca sebagai tiga goresan.

                 Garis dasar dan batang terpendek sekarang memberi titik
                 awal, jadi yang naik dan yang turun bisa dibandingkan. --}}
            <symbol id="i-chart" viewBox="0 0 24 24">
                <path d="M4 20h16"/>
                <path d="M7 20v-4"/>
                <path d="M12 20V6"/>
                <path d="M17 20v-9"/>
            </symbol>

            {{-- Laporan: dokumen dengan garis teks. --}}
            <symbol id="i-report" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6"/>
                <path d="M16 13H8"/>
                <path d="M16 17H8"/>
                <path d="M10 9H8"/>
            </symbol>

            {{-- Data terpisah: kunci. --}}
            <symbol id="i-lock" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </symbol>

            {{-- Mockup: garis naik. --}}
            <symbol id="i-trend-up" viewBox="0 0 24 24">
                <path d="M22 7l-8.5 8.5-5-5L2 17"/>
                <path d="M16 7h6v6"/>
            </symbol>

            {{-- Mockup: garis turun. --}}
            <symbol id="i-trend-down" viewBox="0 0 24 24">
                <path d="M22 17l-8.5-8.5-5 5L2 7"/>
                <path d="M16 17h6v-6"/>
            </symbol>

            {{-- Mockup: satu orang untuk pelanggan aktif. --}}
            <symbol id="i-user" viewBox="0 0 24 24">
                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </symbol>

            {{-- Mockup: dokumen bertumpuk untuk daftar laporan. --}}
            <symbol id="i-docs" viewBox="0 0 24 24">
                <path d="M8 3H5a2 2 0 0 0-2 2v3"/>
                <path d="M16 3h3a2 2 0 0 1 2 2v3"/>
                <path d="M8 21H5a2 2 0 0 1-2-2v-3"/>
                <path d="M16 21h3a2 2 0 0 0 2-2v-3"/>
                <path d="M9 8h6"/>
                <path d="M9 12h6"/>
                <path d="M9 16h3"/>
            </symbol>

            {{-- Catatan hero: lingkaran dengan centang.

                 Semula diamond minus (&#9432;). Centang lebih tepat karena
                 isinya janji, bukan peringatan. --}}
            <symbol id="i-check-circle" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9"/>
                <path d="M8.5 12.2l2.4 2.4 4.6-4.9"/>
            </symbol>

            {{-- Kontak: WhatsApp, telepon, email.

                 Ketiganya digambar dengan mode garis, sama seperti symbol
                 lain. WhatsApp tetap punya dua lapis path: siluet yang
                 penuh (path pertama) dan handset di dalamnya (path kedua).

                 Path siluet tidak diberi `fill` sendiri karena aturan
                 fill ada di pemakaiannya (`.i-solid`), bukan di sini:
                 sebagai `<use>` di tombol bewarna solid, WhatsApp harus
                 ikut warna teks tombol. Bentuk penuh dengan garis saja
                 akan terbaca sebagai gelembung kosong, bukan sebagai
                 logo -- itu sebabnya ada kelas `.i-solid`. --}}
            <symbol id="i-wa" viewBox="0 0 24 24">
                <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2z"/>
                <path d="M17.2 14.2c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35z"/>
            </symbol>

            <symbol id="i-telepon" viewBox="0 0 24 24">
                <path d="M21 16.9v2.5a1.9 1.9 0 0 1-2.1 1.9 18.8 18.8 0 0 1-8.2-2.9 18.5 18.5 0 0 1-5.7-5.7A18.8 18.8 0 0 1 2.1 4.4 1.9 1.9 0 0 1 4 2.3h2.5a1.9 1.9 0 0 1 1.9 1.6c.12.9.34 1.8.65 2.64a1.9 1.9 0 0 1-.43 2L7.5 9.7a15 15 0 0 0 5.7 5.7l1.16-1.16a1.9 1.9 0 0 1 2-.43c.84.31 1.73.53 2.64.65A1.9 1.9 0 0 1 21 16.9z"/>
            </symbol>

            <symbol id="i-email" viewBox="0 0 24 24">
                <rect x="2" y="4.5" width="20" height="15" rx="2.2"/>
                <path d="M2.8 6.6L12 13l9.2-6.4"/>
            </symbol>
        </defs>
    </svg>

    {{--
        URL login panel pusat.

        Di produksi, SPA dilayani dari host yang sama dengan Blade ini, jadi
        path relatif `/central/login` benar. Di development tidak: Blade
        dilayani `php artisan serve` di port 8000, sementara SPA-nya hidup
        di Vite dev server port 5173. Tanpa prefix ini, klik "Masuk" dari
        landing page berakhir di 404 -- route yang memang tidak ada di
        `routes/web.php`.

        Nilainya diambil dari config yang sama dengan redirect subdomain di
        `routes/web.php`, jadi tidak ada dua daftar URL frontend yang bisa
        berbeda tanpa disadari.
    --}}
    @php
        $spaBase = rtrim((string) config('tenancy.frontend_url'), '/');
        $centralLoginUrl = $spaBase ? $spaBase . '/central/login' : '/central/login';

        // Kontak penjualan ASTALOKA.
        //
        // Ditulis sekali di sini dan dipakai ulang di hero, langkah
        // "Hubungi kami", section penutup, dan footer. Sebelumnya tiap
        // tombol menulis alamatnya sendiri, jadi satu nomor salah ketik
        // harus dibetulkan di beberapa tempat, dan tidak ada yang
        // ingat tempat terakhir.
        //
        // Dua kanal, sesuai cara orang menghubungi usaha kecil:
        // WhatsApp/telepon untuk yang mau bicara langsung, email untuk
        // yang memang butuh dokumen (proposal, daftar fitur, contoh
        // laporan). Satu nomor untuk WA dan telepon: nomor WhatsApp
        // selalu bisa diputar, jadi tidak perlu nomor kedua.
        //
        // Bentuk link:
        // - `wa.me` butuh internasional TANPA "+" dan tanpa spasi.
        // - `tel:` butuh "+62..." agar tetap bisa dipakai dari luar
        //   negeri.
        // - `rawurlencode` untuk teks dan subjek: kalau nama usaha
        //   pemesan mengandung spasi atau "&", query string-nya rusak
        //   dan aplikasi mail membuka pesan yang terpotong.

        $kontakWaAngka = '62882006644656';       // wa.me: tanpa "+"
        $kontakWaTampil = '+62 882-0066-44656';  // yang dilihat manusia
        // tel: +62... (tanpa spasi, tanpa tanda hubung)
        $kontakTelepon = 'tel:+' . $kontakWaAngka;
        $kontakEmail = 'astabratamgl@gmail.com';

        $sapaanDemo = 'Halo Asta Brata Teknologi, saya mau minta demo gratis ASTALOKA untuk usaha saya. Mohon informasinya, terima kasih.';
        $sapaanHarga = 'Halo Asta Brata Teknologi, saya mau tanya soal paket dan harga ASTALOKA. Mohon informasinya, terima kasih.';

        $kontakWaDemo = 'https://wa.me/' . $kontakWaAngka . '?text=' . rawurlencode($sapaanDemo);
        $kontakWaHarga = 'https://wa.me/' . $kontakWaAngka . '?text=' . rawurlencode($sapaanHarga);

        $kontakEmailDemo = 'mailto:' . $kontakEmail . '?subject=' . rawurlencode('Minta Demo ASTALOKA');
        $kontakEmailHarga = 'mailto:' . $kontakEmail . '?subject=' . rawurlencode('Penawaran Harga ASTALOKA');
    @endphp

    <header>
        <div class="wrap navbar">
            <a class="brand" href="/">
                <span class="dot">A</span>
                <span>ASTALOKA<small>oleh Asta Brata Teknologi</small></span>
            </a>

            <button class="nav-toggle" type="button"
                    aria-expanded="false" aria-controls="nav-menu" aria-label="Buka menu">
                <span></span><span></span><span></span>
            </button>

            <nav class="nav" id="nav-menu">
                <a class="navlink" href="#fitur">Fitur</a>
                <a class="navlink" href="#caranya">Cara Kerja</a>
                <a class="navlink" href="#mulai">Harga</a>

                {{--
                    "Masuk" menuju /central/login, bukan /login.

                    Dua-duanya halaman login SPA, tapi menyimpan token dengan
                    kunci yang berbeda: panel pusat memakai `central_token`,
                    aplikasi lokasi memakai `auth_token`. Menautkan ke /login
                    dari sini akan mengirim superadmin ke form yang salah, dan
                    kredensial yang ia ketik tidak akan pernah sampai ke
                    endpoint yang mengeluarkannya.

                    Petit di navbar, bukan tombol besar: halaman ini untuk
                    calon pelanggan yang belum punya akses. Yang sudah punya
                    akun datang dari subdomain lokasi mereka sendiri, atau
                    lewat bookmark ke /central/login.

                    Bersaudara dengan tombol "Ajukan Penawaran" yang dihapus
                    dari sini, jadi warna accent-nya ikut naik ke `primary`.
                    Kalau "Masuk" tetap ghost, navbar kehilangan satu-satunya
                    aksen warna dan navlink biasa di sebelahnya terlihat
                    sama pentingnya dengan pintu masuk.
                --}}
                <a class="btn btn-primary" href="{{ $centralLoginUrl }}">Masuk</a>
            </nav>
        </div>
    </header>

    {{-- Tombol kembali ke atas. Diletakkan DI LUAR header supaya
         tidak ikut ter-clip atau berubah opacity bersama header saat
         scroll. --}}
    <button class="to-top" type="button" aria-label="Kembali ke atas" tabindex="-1">
        <span aria-hidden="true"></span>
    </button>

    <main>
        {{-- ---------- hero ---------- --}}
        {{--
            Panel gelap besar + mockup HP yang keluar dari tepi kanan.

            Dua perangkat (HP + kartu laporan di belakangnya) dipilih
            karena bagian atas layar harus bisa menunjukkan "ini sistem
            yang jalan" dalam satu glance. Mockup HP dibaca lebih cepat
            daripada kartu mockup datar: bentuknya sudah dikenal orang
            tanpa perlu penjelasan, jadi tidak perlu kata untuk tahu
            itu aplikasi.

            PENTING SOAL KEJUJURAN: semua angka di dalam mockup HP ini
            adalah data contoh, bukan data pelanggan mana pun. Semuanya
            ditulis sebagai class `mock`, yang berarti isinya boleh
            berubah kapan saja tanpa perlu review -- karena tidak pernah
            ditampilkan sebagai angka asli. Jangan pernah menaruh
            angka riil di elemen yang TIDAK punya class `mock`: itu
            kalimat yang benar-benar diklaim ke calon pembeli.
        --}}
        <div class="hero">
            <div class="wrap">
                <div class="hero-panel">
                    <div class="hero-inner">

                        <div class="reveal">
                            <span class="eyebrow">Asta Layanan Operasional, Keuangan, dan Administrasi</span>

                            <h1>Satu sistem untuk <em>semua</em> jenis usaha.</h1>

                            <p class="lede">
                                ASTALOKA adalah platform pengelolaan usaha dan administrasi
                                dari Asta Brata Teknologi. Air minum, internet, pembayaran
                                sampah, jasa, atau usaha apa pun &mdash; semuanya berjalan
                                di atas satu sistem yang sama, lengkap dengan modulnya
                                masing-masing.
                            </p>

                            <div class="cta">
                                {{-- Di dalam panel gelap, tombol ghost memakai
                                     varian gelap (lihat `.hero-panel .btn-ghost`).

                                     Tombol pertama TIDAK lagi `#mulai` seperti
                                     sebelumnya. Menuju section penutup hanya
                                     memindahkan orang ke daftar tombol yang
                                     sama persis -- satu klik tambahan tanpa
                                     informasi baru. Link WhatsApp membawa
                                     pesannya sudah terisi, jadi dari sini
                                     calon pembeli sudah sampai di tujuan
                                     dalam satu klik.

                                     `#mulai` tidak dihapus dari halaman:
                                     masih dipakai navbar dan section cara
                                     kerja. --}}
                                <a class="btn btn-primary btn-lg"
                                   href="{{ $kontakWaDemo }}"
                                   target="_blank" rel="noopener">
                                    <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                                    Minta Demo Gratis
                                </a>
                                <a class="btn btn-ghost btn-lg" href="#fitur">Lihat Fitur</a>
                            </div>

                            <p class="note">
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span>
                                    Tanpa biaya instalasi. Satu akun, semua modul &mdash;
                                    tidak perlu bolak-balik antar aplikasi.
                                </span>
                            </p>
                        </div>

                        {{-- Mockup perangkat. `aria-hidden` karena isinya
                             dekoratif dan sudah dijelaskan di teks hero. --}}
                        <div class="hero-art reveal" aria-hidden="true">
                            <div class="art-card">
                                <strong>Laporan siap</strong>
                                <span>Neraca, laba rugi, buku besar, dan arus kas bisa dicetak kapan saja.</span>
                            </div>

                            <div class="phone">
                                <div class="phone__screen">
                                    <div class="phone__status">
                                        <i></i>
                                        <span class="mockdata">09:41</span>
                                    </div>

                                    <div class="phone__body">
                                        <div class="phone__hi">
                                            Halo, Admin
                                            <span class="mockdata">Ringkasan hari ini</span>
                                        </div>

                                        <div class="phone__row phone__row--money">
                                            <span><svg><use href="#i-trend-up"/></svg></span>
                                            <span>
                                                <b class="mockdata">Pemasukan hari ini</b>
                                                <em class="mockdata">Rp 1.250.000</em>
                                            </span>
                                        </div>

                                        <div class="phone__row phone__row--warn">
                                            <span><svg><use href="#i-trend-down"/></svg></span>
                                            <span>
                                                <b class="mockdata">Tagihan menunggak</b>
                                                <em class="mockdata">7 pelanggan</em>
                                            </span>
                                        </div>

                                        <div class="phone__row">
                                            <span><svg><use href="#i-docs"/></svg></span>
                                            <span>
                                                <b class="mockdata">Tagihan hari ini</b>
                                                <em class="mockdata">32 tagihan</em>
                                            </span>
                                        </div>

                                        <span class="phone__cta mockdata">Buat Tagihan</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ---------- pratinjau dashboard ---------- --}}
        {{--
            Kartu mockup dashboard, bukan ringkasan teks.

            Alasan pakai visual: daftar bullet menjelaskan fitur,
            tapi calon pembeli yang melihat dashboard bisa
            langsung paham ini sistem yang jalan -- bukan mockup
            wireframe kosong.

            Letaknya sebagai section sendiri, bukan di dalam hero:
            hero sekarang dipakai untuk mockup HP (pengalaman harian),
            dan blok ini untuk angka (bukti sistemnya jalan). Dua hal
            berbeda butuh dua penekanan berbeda.

            PENTING SOAL KEJUJURAN: semua angka di sini HARUS
            memakai class `mock`. Aturan mainnya satu: kalau
            elemen punya `mock`, isinya boleh berubah kapan saja
            tanpa perlu review karena tidak pernah ditampilkan
            sebagai angka asli. Elemen tanpa `mock` berarti
            kalimat yang benar-benar diklaim ke calon pembeli, jadi
            jangan pernah menaruh angka di sana.

            Angka yang tampil BUKAN data produksi. Data ada di
            database terpisah per pelanggan dan tidak pernah dimuat
            ke halaman publik.
        --}}
        <section id="pratinjau" class="preview">
            <div class="wrap">
                <div class="preview-grid">

                    <div class="preview-copy reveal">
                        <span class="eyebrow">Ringkasan satu layar</span>

                        <h2>Satu sistem, semua kebutuhan operasional</h2>

                        <p>
                            ASTALOKA bukan aplikasi air minum. Ia landasan yang
                            bisa dipakai apa pun &mdash; air, internet, sampah,
                            atau usaha apa pun &mdash; dengan modul yang
                            sama-sama sudah siap.
                        </p>

                        <ul class="preview-points">
                            <li>
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span><strong>Pemasukan dan tunggakan</strong> terlihat berdampingan, bukan harus dihafal.</span>
                            </li>
                            <li>
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span><strong>Laporan keuangan</strong> bisa dicetak kapan saja tanpa menyusun ulang data.</span>
                            </li>
                            <li>
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span><strong>Semua modul menyatu</strong> &mdash; satu akun cukup, tanpa bolak-balik antar aplikasi.</span>
                            </li>
                        </ul>
                    </div>

                    <div class="mock reveal">
                    <div class="mock__bar">
                        <span class="mock__dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span class="mock__path">astaloka &mdash; dashboard</span>
                        <span class="mock__badge">contoh tampilan</span>
                    </div>

                    <div class="mock__body">
                        <div class="mock__stat mock__stat--in">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-trend-up"/></svg></span>
                                <span class="mock__label">Pemasukan bulan ini</span>
                            </div>
                            <span class="mock__value" data-count="18450000" data-money>Rp 0</span>
                            <span class="mock__delta up">
                                <svg aria-hidden="true"><use href="#i-trend-up"/></svg>
                                12,4% dari bulan lalu
                            </span>
                        </div>

                        <div class="mock__stat mock__stat--warn">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-trend-down"/></svg></span>
                                <span class="mock__label">Tagihan belum lunas</span>
                            </div>
                            <span class="mock__value" data-count="9230000" data-money>Rp 0</span>
                            <span class="mock__delta down">
                                <svg aria-hidden="true"><use href="#i-trend-down"/></svg>
                                7 customer menunggak
                            </span>
                        </div>

                        {{-- Grafik batang: tinggi tiap batang ditulis inline sebagai --h,
                             urutannya sebagai --i. Murni CSS, tanpa JS dan gambar.

                             Label bulan disembunyikan dari screen reader: grafik
                             sudah punya label "Penagihan 6 bulan terakhir" di
                             atasnya. Membaca "Mei Jun Jul" tanpa konteks hanya
                             menambah ketegangan. --}}
                        <div class="mock__chart">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-chart"/></svg></span>
                                <span class="mock__label">Penagihan 6 bulan terakhir</span>
                            </div>
                            <div class="deck">
                                <div class="deck__bars" aria-hidden="true">
                                    <span class="deck-bar" style="--h: 42%; --i: 0"></span>
                                    <span class="deck-bar" style="--h: 58%; --i: 1"></span>
                                    <span class="deck-bar" style="--h: 49%; --i: 2"></span>
                                    <span class="deck-bar" style="--h: 71%; --i: 3"></span>
                                    <span class="deck-bar" style="--h: 64%; --i: 4"></span>
                                    <span class="deck-bar deck-bar--on" style="--h: 88%; --i: 5"></span>
                                </div>
                                <div class="deck__months" aria-hidden="true">
                                    <span>Mei</span><span>Jun</span><span>Jul</span>
                                    <span>Agu</span><span>Sep</span><span>Okt</span>
                                </div>
                            </div>
                        </div>

                        <div class="mock__stat mock__stat--user">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-user"/></svg></span>
                                <span class="mock__label">Pelanggan aktif</span>
                            </div>
                            <span class="mock__value" data-count="1643">0</span>
                            <span class="mock__delta">terdaftar dalam sistem</span>
                        </div>

                        <div class="mock__stat mock__stat--docs">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-docs"/></svg></span>
                                <span class="mock__label">Laporan siap</span>
                            </div>
                            <span class="mock__tags">
                                <i>Neraca</i><i>Laba Rugi</i><i>Buku Besar</i><i>Arus Kas</i>
                            </span>
                        </div>
                    </div>

                    <p class="mock__foot">
                        Sudah pakai ASTALOKA? Buka langsung
                        <strong>alamat usaha Anda</strong>, atau
                        <a href="{{ $centralLoginUrl }}">masuk ke panel pusat</a>.
                    </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- fitur ---------- --}}
        <section id="fitur">
            <div class="wrap">
                <div class="sec-head reveal">
                    <h2>Semua modul, satu landasan</h2>
                    <p>
                        Tiap jenis usaha punya modulnya sendiri, tapi semuanya
                        berjalan di atas data pelanggan dan keuangan yang sama &mdash;
                        jadi tidak ada pencatatan ganda antar layanan.
                    </p>
                </div>

                <div class="grid">
                    <div class="card reveal" style="--d: 0ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-water"/></svg></div>
                        <h3>Air Minum</h3>
                        <p>Pencatatan meter, tarif berjenjang, tagihan otomatis, dan riwayat pelanggan air minum dalam satu alur.</p>
                    </div>

                    <div class="card reveal" style="--d: 70ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-internet"/></svg></div>
                        <h3>Internet &amp; Tv</h3>
                        <p>Paket langganan, biaya instalasi, penagihan per bulan, dan monitoring pelanggan aktif.</p>
                    </div>

                    <div class="card reveal" style="--d: 140ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-truck"/></svg></div>
                        <h3>Pembayaran Sampah</h3>
                        <p>Tagihan angkut dan aturan retribusi per pelanggan, lengkap dengan catatan setoran dan tunggakan.</p>
                    </div>

                    <div class="card reveal" style="--d: 210ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-users"/></svg></div>
                        <h3>Data Pelanggan</h3>
                        <p>Satu basis pelanggan untuk semua layanan, dengan riwayat langganan dan status pembayaran per pelanggan.</p>
                    </div>

                    <div class="card reveal" style="--d: 280ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-chart"/></svg></div>
                        <h3>Keuangan &amp; Jurnal</h3>
                        <p>Jurnal masuk dan keluar, buku besar, laba rugi, sampai tutup buku tahunan tanpa Excel.</p>
                    </div>

                    <div class="card reveal" style="--d: 350ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-report"/></svg></div>
                        <h3>Laporan</h3>
                        <p>Laporan tagihan, tunggakan, dan keuangan siap cetak dengan kop usaha Anda sendiri.</p>
                    </div>

                    <div class="card reveal" style="--d: 420ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-lock"/></svg></div>
                        <h3>Data Pribadi</h3>
                        <p>Data usaha Anda hanya bisa diakses oleh akun Anda sendiri. Tidak ada akun lain yang bisa melihatnya.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- cara kerja ---------- --}}
        <section id="caranya" class="tint">
            <div class="wrap">
                <div class="sec-head reveal">
                    <h2>Cara kerjanya sederhana</h2>
                    <p>Tiga langkah. Tidak ada migrasi data dari sistem lama yang wajib dilakukan.</p>
                </div>

                <div class="steps">
                    <div class="step reveal" style="--d: 0ms">
                        <span class="step__num" aria-hidden="true"></span>
                        <h3>Hubungi kami</h3>
                        <p>Sampaikan kebutuhan usaha Anda &mdash; air minum, internet, sampah, atau lainnya. Kami bantu pilihkan paket yang paling pas.</p>
                        <a class="step__cta" href="{{ $kontakWaHarga }}" target="_blank" rel="noopener">
                            <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                            Chat WhatsApp
                        </a>
                    </div>

                    <div class="step reveal" style="--d: 90ms">
                        <span class="step__num" aria-hidden="true"></span>
                        <h3>Akun langsung jadi</h3>
                        <p>Akun Anda dibuat lengkap dengan modul siap pakai. Tidak perlu install, tidak perlu server, tidak perlu biaya awal.</p>
                    </div>

                    <div class="step reveal" style="--d: 180ms">
                        <span class="step__num" aria-hidden="true"></span>
                        <h3>Langsung bekerja</h3>
                        <p>Catat pembayaran, cetak tagihan, lihat laporan. Tidak ada satu pun langkah yang perlu bantuan teknis.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- penutup ---------- --}}
        <section id="mulai">
            <div class="wrap">
                <div class="closer reveal">
                    <h2>Siap mulai?</h2>
                    <p>
                        Punya usaha yang butuh sistem operasional dan keuangan
                        yang rapi &mdash; sekecil apa pun skalanya? Hubungi Asta
                        Brata Teknologi untuk demo gratis dan penawaran harga.
                    </p>

                    {{--
                        Tiga pintu masuk, bukan satu.

                        Tombol pertama selalu WhatsApp: itu yang paling
                        sering dipakai calon pembeli, dan pesannya sudah
                        terisi, jadi tidak ada langkah tambahan untuk
                        menulis pertanyaan dari nol.

                        Email tetap jadi tombol kedua, bukan disembunyikan
                        di teks: ada yang memang lebih suka mengirim proposal,
                        dan dari aplikasi email prospective bisa lebih cepat
                        daripada lewat aplikasi pesan.

                        Nomor telepon jadi tautan kecil di bawahnya, untuk
                        yang lebih suka bicara.

                        Teks tombol menyebut kanalnya, karena ikon saja
                        tidak selalu terbaca. Ini juga satu-satunya bagian
                        halaman yang boleh menyebut nomor: nomor support
                        teknis yang dipakai di dalam aplikasi berbeda, dan
                        mencampurkannya di sini akan membuat calon pembeli
                        menghubungi orang yang salah.
                    --}}
                    <div class="cta" style="justify-content:center">
                        <a class="btn btn-primary btn-lg"
                           href="{{ $kontakWaDemo }}"
                           target="_blank" rel="noopener">
                            <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                            Minta Demo Gratis
                        </a>
                        <a class="btn btn-ghost btn-lg" href="{{ $kontakEmailHarga }}">
                            <svg aria-hidden="true"><use href="#i-email"/></svg>
                            Tanya Harga via Email
                        </a>
                    </div>

                    <div class="kontak-alt">
                        <span class="kontak-alt__label">Atau hubungi langsung:</span>

                        <a href="{{ $kontakTelepon }}">
                            <svg aria-hidden="true"><use href="#i-telepon"/></svg>
                            {{ $kontakWaTampil }}
                        </a>
                    </div>

                    <p class="kontak-email">
                        <a href="{{ $kontakEmailDemo }}">
                            <svg aria-hidden="true"><use href="#i-email"/></svg>
                            {{ $kontakEmail }}
                        </a>
                    </p>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap foot">
            <p>&copy; {{ date('Y') }} Asta Brata Teknologi. ASTALOKA &mdash; Asta Layanan Operasional, Keuangan, dan Administrasi.</p>

            {{--
                Disatukan di footer, bukan di section mockup.

                Kalimat "Buka langsung alamat usaha Anda" yang ada di
                mockup itu bukan ajakan untuk mengetik apa pun --
                pelanggan yang sudah tahu alamatnya akan mengetik
                sendiri. Yang hilang dari halaman ini adalah satu jalan
                untuk orang yang masuk ke domain pusat, yaitu superadmin,
                dan untuk orang yang salah mengetik alamat lalu mendarat
                di sini.

                `/central/login`, bukan `/login`: lihat catatan di navbar.
            --}}
            <p>
                Sudah punya akun?
                <a href="{{ $centralLoginUrl }}">Masuk ke panel</a>
            </p>

            {{--
                Kontak di footer, bukan di dalam mockup.

                Alasannya sama seperti soal `/central/login` di atas: yang
                hilang dari halaman ini adalah jalan untuk orang yang sudah
                agen di bawah domain pusat -- yaitu yang sedang membaca
                footer, bukan yang sedang menekan tombol di tengah halaman.

                Nomor dan email memakai variabel yang sama dengan hero dan
                section penutup. Kalau suatu saat nomor berubah, tempatnya
                satu: bagian `@php` paling atas.
            --}}
            <p>
                <a href="{{ $kontakWaHarga }}" target="_blank" rel="noopener">WhatsApp {{ $kontakWaTampil }}</a>
                &nbsp;&middot;&nbsp;
                <a href="mailto:{{ $kontakEmail }}">{{ $kontakEmail }}</a>
            </p>
        </div>
    </footer>

    <script>
        /* ============================================================
           BAGIAN 1 -- ANIMASI
           Dipisah dari bagian portal di bawah. Script sebelumnya memakai
           satu IIFE dengan `return` di awal kalau form tidak ada; kalau
           animasi ikut di dalam IIFE yang sama, satu guard yang gagal
           akan mematikan animasi seluruh halaman.
           ============================================================ */
        (function () {
            var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (kurangiGerak || !('IntersectionObserver' in window)) {
                // Tanpa observer atau pengguna minta gerak minimal: tampilkan
                // semua langsung. `no-js` sudah dihapus di <head>, jadi
                // konten aman walau branch ini tidak kena.
                document.querySelectorAll('.reveal').forEach(function (el) {
                    el.classList.add('shown');
                });
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;

                    entry.target.classList.add('shown');
                    // Berhenti mengamati setelah muncul: elemen yang sudah
                    // terlihat tidak perlu dicek lagi setiap scroll.
                    observer.unobserve(entry.target);
                });
            }, {
                // Muncul saat elemen sudah ~12% terlihat, supaya animasi
                // tidak berhenti tepat di tepi layar.
                threshold: 0.12,
                rootMargin: '0px 0px -60px 0px',
            });

            document.querySelectorAll('.reveal').forEach(function (el) {
                observer.observe(el);
            });
        })();

        /* Garis pemisah antar section tumbuh saat section masuk layar.

           Dipisah dari observer `.reveal` karena objek yang diawasi
           berbeda (section, bukan elemen di dalamnya) dan ambangnya
           beda: garis baru terasa saat section menyentuh tepi atas
           viewport, bukan saat 12% isinya terlihat. Kalau ikut aturan
           yang sama, garis sudah menyala sebelum kontennya masuk --
           dan garis hilang sama sekali, karena ia menyala
           persis ketika sedang di luar pandangan. */
        (function () {
            var sections = document.querySelectorAll('main > section');
            if (!sections.length) return;

            if (!('IntersectionObserver' in window)) {
                sections.forEach(function (s) { s.classList.add('is-in'); });
                return;
            }

            var obs = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-in');
                    obs.unobserve(entry.target);
                });
            }, {
                /* `bottom: 5%` -- garis menyala saat section sudah
                   mulai masuk dari bawah, supaya transisinya terasa
                  sebelum kontennya sampai ke tengah layar. */
                threshold: 0,
                rootMargin: '0px 0px -5% 0px',
            });

            sections.forEach(function (s) { obs.observe(s); });
        })();

        /* ============================================================
           MOCKUP PERANGKAT DI HERO

           Terpisah dari observer `.reveal` di atas, apesar memakai
           API yang sama.

           Alasannya bukan soal teknis: HP dan kartu belakang punya
           animasi `transform` sendiri (naik + miring), sementara
           `.reveal.shown` menulis `animation` pada `opacity`. Kalau
           keduanya dipasang di elemen yang sama, aturan `rise` akan
           menimpa `transform` mockup -- HP akan straighten dan
           kehilangan kemiringannya begitu masuk layar.

           Dipisah juga karena timing-nya beda: mockup harus muncul
           bersama hero (langsung terlihat), bukan menunggu scroll.
           Kalau ikut observer, HP baru akan muncul setelah user
           scroll ke bawah -- padahal justru HP yang membuat orang
           berhenti di layar pertama.

           Fallback wajib: kalau `IntersectionObserver` tidak ada atau
           user minta gerak minimal, `.is-in` dipasang langsung --
           tanpa itu, `opacity: 0` di gaya dasar membuat mockup hilang
           permanen.
        ============================================================ */
        (function () {
            var panel = document.querySelector('.hero-panel');
            if (!panel) return;

            var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (kurangiGerak || !('IntersectionObserver' in window)) {
                panel.classList.add('is-in');
                return;
            }

            var obs = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    panel.classList.add('is-in');
                    obs.unobserve(panel);
                });
            }, { threshold: 0.15 });

            obs.observe(panel);
        })();

        /* ============================================================
           MOCKUP HERO DIGESER SATU-SATUNYA

           `.phone` dan `.art-card` punya handler drag masing-masing.
           Kalau keduanya ikut bergerak karena `.hero-art` yang
           digeser, memegang kartu laporan akan terasa seperti
           memegang HP-nya juga -- padahal yang disentuh cuma satu.

           Jadi yang bergeser adalah elemen yang benar-benar ditekan.
           Klik "Laporan siap" hanya kartu itu yang bergerak, HP diam
           di tempat. Ini juga alasan batas geser dihitung per elemen:
           keduanya ada di posisi berbeda, jadi ruang kosong yang
           boleh dipakai juga berbeda.

           Yang ditulis JS hanya `--dx` dan `--dy`. Susunan transform
           dengan kemiringan desain, animasi masuk, dan ngambangnya
           disusun di CSS (lihat blok `@property`) -- bukan di sini.
        ============================================================ */
        (function () {
            var panel = document.querySelector('.hero-panel');
            if (!panel) return;

            var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            /* Dipisah per elemen: posisi geser dibaca dari dataset-nya
               sendiri, bukan dari satu variabel bersama. Kalau
               berbagi variabel, menggeser kartu ikut menggeser HP. */
            var daftar = document.querySelectorAll('.hero-art .phone, .hero-art .art-card');
            if (!daftar.length) return;

            /* Satu elemen saja boleh digeser pada satu waktu. */
            var sedang = null;

            /* Batas geser per elemen, dihitung tiap kali drag mulai.

               Yang dijaga: elemen tidak boleh keluar dari bagian panel
               yang terlihat.

               Sisi yang sudah keluar (HP memang sengaja menembus tepi
               kanan) dikunci di 0 lewat `Math.max`. Tanpa itu, HP bisa
               ditarik ke luar layar dan tidak pernah bisa dikembalikan
               karena tidak ada ruang tersisa di sisi itu. */
            function hitungBatas(el) {
                var p = panel.getBoundingClientRect();
                var e = el.getBoundingClientRect();

                return {
                    /* Kiri dan atas dibatasi penuh -- ke sana tidak ada
                       ruang kosong yang cukup, dan menggeser ke sana
                       hanya menyembunyikan mockup di balik teks hero. */
                    kiri: Math.max(0, p.left - e.left),
                    atas: Math.max(0, p.top - e.top),
                    /* Kanan dan bawah mengikuti sisa ruang yang ada. */
                    kanan: Math.max(0, p.right - e.right),
                    bawah: Math.max(0, p.bottom - e.bottom),
                };
            }

            function geserX(el) { return parseFloat(el.dataset.dx || '0') || 0; }
            function geserY(el) { return parseFloat(el.dataset.dy || '0') || 0; }

            function mulaiGeser(event) {
                if (sedang) return;

                /* Abaikan yang bisa ditekan. Mockup tidak punya tombol
                   sungguhan, tapi `Buat Tagihan` terlihat seperti
                   tombol, dan drag di atasnya tidak ada gunanya. */
                if (event.target.closest && event.target.closest('a, button')) return;

                /* `currentTarget` = elemen yang punya listener ini,
                   yaitu persis yang ditekan. `target` bisa saja anak
                   dari elemen itu, dan menggeser `target` akan membuat
                   kartu ikut bergerak saat HP yang ditekan. */
                var el = event.currentTarget;

                sedang = el;
                el._batas = hitungBatas(el);
                el._penanda = event.pointerId;

                /* Titik awal dikurangi posisi sekarang, supaya mockup
                   tidak melompat ke titik kursor saat drag dimulai.
                   Titik itu adalah titik jangkar, bukan titik asal
                   koordinat. */
                el._mulaiX = event.clientX - geserX(el);
                el._mulaiY = event.clientY - geserY(el);

                /* `setPointerCapture` membuat event tetap terkirim ke
                   elemen ini walau pointer sudah keluar dari tepinya.
                   Tanpa itu, drag yang keluar sedikit dari mockup akan
                   "hilang" di tengah jalan. */
                if (el.setPointerCapture) {
                    try { el.setPointerCapture(event.pointerId); } catch (e) { /* tidak didukung */ }
                }

                el.classList.add('is-dragging');
            }

            function geserKan(event) {
                var el = sedang;
                if (!el || event.pointerId !== el._penanda) return;

                var b = el._batas;

                /* Empat sisi dibatasi terpisah. Mockup boleh keluar ke
                   kanan (memang begitu desainnya) tapi tidak boleh
                   keluar ke bawah panel. Kalau digabung jadi satu angka,
                   HP bisa hilang lewat bawah dan tidak ada tombol untuk
                   mengembalikannya. */
                var x = Math.min(Math.max(event.clientX - el._mulaiX, -b.kiri), b.kanan);
                var y = Math.min(Math.max(event.clientY - el._mulaiY, -b.atas), b.bawah);

                el.dataset.dx = x;
                el.dataset.dy = y;

                el.style.setProperty('--dx', x + 'px');
                el.style.setProperty('--dy', y + 'px');
            }

            function selesaiGeser(event) {
                var el = sedang;
                if (!el) return;
                if (el._penanda !== undefined && event.pointerId !== el._penanda) return;

                sedang = null;
                el.classList.remove('is-dragging');
                el._penanda = undefined;

                /* Lepaskan capture supaya klik berikutnya di halaman
                   lain tidak lagi dialihkan ke elemen ini. */
                if (el.releasePointerCapture && event.pointerId !== undefined) {
                    try { el.releasePointerCapture(event.pointerId); } catch (e) { /* sudah lepas */ }
                }
            }

            daftar.forEach(function (el) {
                el.addEventListener('pointerdown', mulaiGeser);
                if (kurangiGerak) el.classList.add('no-spring');
            });

            /* Listener di `window`, bukan di tiap elemen. Kalau hanya
               di elemennya, pointer yang keluar sedikit dari mockup
               tidak mengirim event apa pun, dan `.is-dragging` nyangkut
               selamanya -- mockup terkunci dan tidak bisa digeser lagi. */
            window.addEventListener('pointermove', geserKan);
            window.addEventListener('pointerup', selesaiGeser);
            window.addEventListener('pointercancel', selesaiGeser);

            /* `resize` mengubah ukuran panel, jadi batas lama tidak lagi
               benar. Keduanya dikembalikan ke tengah supaya tidak
               menggantung di posisi yang sudah tidak valid -- termasuk
               setelah layar ponsel dirotasi. */
            window.addEventListener('resize', function () {
                daftar.forEach(function (el) {
                    el.dataset.dx = '0';
                    el.dataset.dy = '0';
                    el.style.setProperty('--dx', '0px');
                    el.style.setProperty('--dy', '0px');
                });
            });
        })();

        /* Header mendapat border + bayangan hanya setelah di-scroll,
           supaya tidak terlihat seperti garis asing saat masih di atas. */
        (function () {
            var header = document.querySelector('header');
            if (!header) return;

            var pakaiUdah = false;

            function perbarui() {
                if (window.scrollY > 8) {
                    if (!pakaiUdah) { header.classList.add('stuck'); pakaiUdah = true; }
                } else if (pakaiUdah) {
                    header.classList.remove('stuck'); pakaiUdah = false;
                }
            }

            window.addEventListener('scroll', perbarui, { passive: true });
            perbarui();
        })();

        /* ============================================================
           MENU MOBILE

           Panel dropdown, bukan navigasi full-screen. Alasannya: halaman
           ini punya 3 tujuan (scroll) + 1 mailto. Layar penuh untuk
           3 link itu berlebihan dan menambah langkah untuk menutupnya.

           Yang DICATAT di sini karena mudah bikin bug:
           - `aria-expanded` ikut berubah, jadi screen reader tahu status.
           - Klik link HARUS menutup panel, kalau tidak user menekan
             "tutup" di layar yang scrolld-nya sudah berganti.
           - Escape menutup panel, sesuai ekspektasi keyboard.
           - Klik di luar panel menutup juga: user menekan area gelap dan
            ZIHRAD tidak terjadi apa-apa, itu membingungkan.
           ============================================================ */
        (function () {
            var tombol = document.querySelector('.nav-toggle');
            var menu = document.getElementById('nav-menu');
            if (!tombol || !menu) return;

            function buka(apa) {
                tombol.setAttribute('aria-expanded', apa ? 'true' : 'false');
                menu.classList.toggle('is-open', apa);
                document.documentElement.classList.toggle('nav-open', apa);
                tombol.setAttribute('aria-label', apa ? 'Tutup menu' : 'Buka menu');
            }

            tombol.addEventListener('click', function () {
                buka(tombol.getAttribute('aria-expanded') !== 'true');
            });

            menu.addEventListener('click', function (e) {
                // Hanya link di dalam panel. Tombol mailto juga di
                // dalam menu, jadi filter ini tetap benar.
                if (e.target.closest('a')) buka(false);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && tombol.getAttribute('aria-expanded') === 'true') {
                    buka(false);
                    tombol.focus();
                }
            });

            document.addEventListener('click', function (e) {
                if (tombol.getAttribute('aria-expanded') !== 'true') return;
                if (menu.contains(e.target) || tombol.contains(e.target)) return;
                buka(false);
            });

            // Panel yang terbuka saat layar diperbesar ke desktop akan
            // menggantung sebagai kotak kosong: `nav` desktop pakai
            // `display: flex` normal, jadi reset saja state-nya.
            var vpLama = window.matchMedia('(max-width: 900px)');
            var gantiVp = function (e) { if (!e.matches) buka(false); };
            if (vpLama.addEventListener) vpLama.addEventListener('change', gantiVp);
            else if (vpLama.addListener) vpLama.addListener(gantiVp);
        })();

        /* ============================================================
           SCROLL-SPY NAVBAR

           Menandai link section yang sedang dibaca. Yang aktif ditentukan
           dari section yang PUNYA bagian teratas paling dekat dengan
           batas header -- bukan dari section terakhir yang terlihat.
           Aturan ini penting: kalau pakai "terakhir yang terlihat",
           section pendek yang sudah hampir keluar layar masih ditandai
           aktif padahal yang di layar sudah section berikutnya.
           ============================================================ */
        (function () {
            var link = Array.prototype.slice.call(
                document.querySelectorAll('.nav a.navlink[href^="#"]')
            );
            if (!link.length) return;

            var peta = {};
            link.forEach(function (a) {
                var id = a.getAttribute('href').slice(1);
                var seksi = document.getElementById(id);
                if (seksi) peta[id] = { a: a, el: seksi };
            });

            var daftar = Object.keys(peta).map(function (id) { return peta[id]; });
            if (!daftar.length) return;

            function perbarui() {
                // Batas ini = tinggi header + sedikit ruang. Section yang
                // sudah lewat batas ini dianggap selesai dibaca.
                var batas = headerTinggi() + 90;
                var sekarang = null;

                daftar.forEach(function (s) {
                    if (s.el.getBoundingClientRect().top <= batas) sekarang = s;
                });

                /* Section TERAKHIR hampir tidak pernah punya `top` <= batas.
                   Dekat dasar halaman, scrollHeight sudah mentok: section
                   terakhir yang pendek tidak bisa naik melewati batas itu,
                   karena footer di bawahnya sudah memenuhi sisa viewport.
                   Aturan "pilih section terakhir yang top-nya sudah lewat"
                   akan macet di section sebelumnya, dan link nav-nya tetap
                   menyala padahal yang dibaca user adalah CTA penutup.

                   Perbaikan: kalau sudah di dasar halaman, section terakhir
                   yang menang. Diukur dari scrollY supaya tidak bergantung
                   pada tinggi footer. */
                var diBawah = (window.innerHeight + window.scrollY) >=
                              (document.documentElement.scrollHeight - 2);

                if (diBawah) sekarang = daftar[daftar.length - 1];

                // Di atas section pertama: belum ada yang aktif.
                if (!sekarang) {
                    daftar.forEach(function (s) { s.a.classList.remove('is-active'); });
                    return;
                }

                daftar.forEach(function (s) {
                    s.a.classList.toggle('is-active', s === sekarang);
                });
            }

            function headerTinggi() {
                var h = document.querySelector('header');
                return h ? h.getBoundingClientRect().height : 70;
            }

            window.addEventListener('scroll', perbarui, { passive: true });
            perbarui();
        })();

        /* ============================================================
           KEMBALI KE ATAS

           Muncul setelah scroll lewat 1 viewport. Muncul lebih awal
           tidak berguna: user masih di hero, belum butuh ascend ke
           navigation atas.

           `scrollTo({ behavior: 'smooth' })` menghormati
           `prefers-reduced-motion`? Tidak -- dia tetap mulus. Karena itu
           behavior-nya dipilih manual dari media query yang sama. */
        (function () {
            var tombol = document.querySelector('.to-top');
            if (!tombol) return;

            var minimalGerak = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var sudahTampil = false;

            function perbarui() {
                var perlu = window.scrollY > window.innerHeight * 0.9;

                if (perlu === sudahTampil) return;

                sudahTampil = perlu;
                tombol.classList.toggle('is-visible', perlu);
                // Saat disembunyikan, jangan sampai masih bisa difokus --
                // invisible button masih masuk tab order dan bisa bikin
                // fokus hilang entah ke mana.
                tombol.setAttribute('tabindex', perlu ? '0' : '-1');
            }

            tombol.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: minimalGerak ? 'smooth' : 'auto' });
            });

            window.addEventListener('scroll', perbarui, { passive: true });
            perbarui();
        })();

        /* Angka pada mockup dashboard naik dari 0 ke nilai akhir saat
           kartu masuk layar.

           Format pakai id-ID: pemisah ribuan titik dan desimal koma.
           Angka harus terlihat sama dengan yang user lihat di aplikasi,
           bukan seperti format bawaan en-US.

           Angka TETAP "Rp 0" kalau JS mati. Itu disengaja: nilai
           default di markup memang sudah "Rp 0", jadi tanpa JS orang
           masih melihat angka nol yang masuk akal -- bukan "--" atau
           string mentah. Lebih aman daripada menampilkan angka
           pecahan seperti 3.402.913 kalau animasi terputus di
           tengah jalan. */
        (function () {
            var minimGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            function rupiah(n) {
                return 'Rp ' + Math.round(n).toLocaleString('id-ID');
            }

            function tampilkan(el, nilai) {
                // Tagihan/pemasukan pakai Rp; pelanggan aktif tidak.
                el.textContent = el.hasAttribute('data-money') ? rupiah(nilai)
                                : nilai.toLocaleString('id-ID');
            }

            function hitung(el) {
                var target = parseInt(el.getAttribute('data-count'), 10);
                if (!target) return;

                if (minimGerak) { tampilkan(el, target); return; }

                var durasi = 1100;
                var mulai = null;

                function langkah(waktu) {
                    if (mulai === null) mulai = waktu;

                    var progres = Math.min((waktu - mulai) / durasi, 1);

                    // easeOutExpo: melambat mendekati target. Ease-out linier
                    // terasa mekanis -- angka terakhir terlihat "menempel".
                    var eased = progres === 1 ? 1 : 1 - Math.pow(2, -10 * progres);

                    tampilkan(el, target * eased);

                    if (progres < 1) requestAnimationFrame(langkah);
                }

                requestAnimationFrame(langkah);
            }

            var targetAngka = document.querySelectorAll('[data-count]');
            if (!targetAngka.length) return;

            if (!('IntersectionObserver' in window)) {
                Array.prototype.forEach.call(targetAngka, function (el) {
                    tampilkan(el, parseInt(el.getAttribute('data-count'), 10));
                });
                return;
            }

            var obs = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    hitung(entry.target);
                    obs.unobserve(entry.target);
                });
            }, { threshold: 0.4 });

            Array.prototype.forEach.call(targetAngka, function (el) { obs.observe(el); });
        })();
    </script>
</body>
</html>
