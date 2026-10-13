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
    <meta name="description" content="ASTALOKA oleh Asta Brata Teknologi adalah platform pengelolaan usaha, administrasi pelanggan, transaksi, dan laporan keuangan. Untuk BUMDes dan lembaga: air minum, internet, pembayaran sampah, dan layanan desa lainnya.">
    <meta name="theme-color" content="#0f766e">

    <title>ASTALOKA &mdash; Asta Layanan Operasional, Keuangan, dan Administrasi</title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>&#128200;</text></svg>">

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
            --teal-50:  #f0fdfa;
            --teal-100: #ccfbf1;
            --teal-200: #99f6e4;
            --teal-300: #5eead4;
            --teal-400: #2dd4bf;
            --teal-500: #14b8a6;
            --teal-600: #0d9488;
            --teal-700: #0f766e;
            --teal-800: #115e59;
            --teal-900: #134e4a;
            --teal-950: #042f2e;

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

            --sh-brand: 0 6px 16px -4px rgb(15 118 110 / .32),
                        0 2px 6px -2px rgb(15 118 110 / .20);

            --ease:   cubic-bezier(.16, 1, .3, 1);
            --spring: cubic-bezier(.34, 1.56, .64, 1);
        }

        *, *::before, *::after { box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            /*
             * Clip horizontal di level `html`, bukan `body`.
             *
             * Glow di `.hero::before/after` sengaja ditempatkan keluar
             * viewport (left: -180px, right: -160px). `overflow-x: hidden`
             * di `body` TIDAK selalu meng-clip-nya -- di beberapa browser
             * `overflow` pada `body` dipropagasi ke viewport dan jadi
             * `visible`, sehingga halaman bisa bergeser ke samping di
             * ponsel. Memotong di `html` bekerja konsisten karena langsung
             * membentuk clipping context untuk seluruh turunan.
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
        @keyframes pop {
            from { opacity: 0; transform: scale(.94) translate3d(0, 18px, 0); }
            to   { opacity: 1; transform: none; }
        }
        @keyframes drift-a {
            from { transform: translate3d(0, 0, 0) scale(1); }
            to   { transform: translate3d(70px, 50px, 0) scale(1.12); }
        }
        @keyframes drift-b {
            from { transform: translate3d(0, 0, 0) scale(1.08); }
            to   { transform: translate3d(-60px, 60px, 0) scale(1); }
        }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgb(20 184 166 / .5); }
            70%  { box-shadow: 0 0 0 9px rgb(20 184 166 / 0); }
            100% { box-shadow: 0 0 0 0 rgb(20 184 166 / 0); }
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
            background: linear-gradient(135deg, var(--teal-400), var(--teal-700));
            color: #fff; font-size: 18px; font-weight: 800;
            box-shadow: var(--sh-brand);
            transition: transform .5s var(--spring), box-shadow .3s var(--ease);
        }
        .brand:hover .dot { transform: rotate(-8deg) scale(1.08); }
        .brand small {
            display: block; font-size: 11px; font-weight: 500;
            color: var(--ink-mute); letter-spacing: 0; line-height: 1.15;
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
        .nav a.navlink:hover { color: var(--teal-700); background: var(--teal-50); }

        /* Garis bawah penanda: scaleX 0 -> 1 dari kiri, bukan fade,
           jadi tumbuh searah gerakan membaca. */
        .nav a.navlink::after {
            content: ""; position: absolute;
            left: 13px; right: 13px; bottom: 3px; height: 2px;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--teal-400), var(--teal-700));
            transform: scaleX(0); transform-origin: left;
            transition: transform .32s var(--ease);
        }
        .nav a.navlink:hover::after,
        .nav a.navlink.is-active::after { transform: scaleX(1); }

        /* Section yang sedang dibaca: warna brand penuh, garis bawah
           tetap terlihat, latar sedikit lebih kuat. */
        .nav a.navlink.is-active {
            color: var(--teal-800); background: var(--teal-50); font-weight: 600;
        }
        .nav a.navlink:focus-visible,
        .nav-toggle:focus-visible,
        .to-top:focus-visible {
            outline: 2px solid var(--teal-500); outline-offset: 2px;
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
        .nav-toggle:hover { border-color: var(--teal-200); box-shadow: var(--sh-sm); }
        .nav-toggle:active { transform: scale(.94); }

        .nav-toggle span {
            display: block;
            width: 18px; height: 2px; margin: 0 auto; border-radius: 2px;
            background: var(--ink);
            transition: transform .34s var(--ease), opacity .2s var(--ease),
                        width .34s var(--ease);
        }
        .nav-toggle span + span { margin-top: 4px; }

        /* Batang atas turun, bawah naik, tengah berputar 90° jadi vertikal.
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
            background: linear-gradient(135deg, var(--teal-600), var(--teal-700));
            color: #fff; box-shadow: var(--sh-brand);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -6px rgb(15 118 110 / .42), 0 3px 8px -2px rgb(15 118 110 / .26);
        }
        .btn-primary:active { transform: translateY(0) scale(.985); }

        .btn-ghost { border-color: var(--line); color: var(--ink); background: #fff; box-shadow: var(--sh-xs); }
        .btn-ghost:hover {
            border-color: var(--teal-300); color: var(--teal-700);
            box-shadow: var(--sh-md); transform: translateY(-2px);
        }

        .btn-lg { padding: 15px 28px; font-size: 16px; }

        /* ---------- hero ---------- */
        .hero { padding: 96px 0 80px; position: relative; overflow: hidden; }

        /* Dua glow yang beranimasi. `transform` (bukan background-position)
           supaya animasi jalan di compositor dan tidak memicu layout. */
        .hero::before,
        .hero::after {
            content: ""; position: absolute; z-index: -1;
            border-radius: 50%; filter: blur(90px); pointer-events: none;
        }
        .hero::before {
            width: 620px; height: 620px; top: -300px; left: -180px;
            background: radial-gradient(circle, rgb(45 212 191 / .30), transparent 68%);
            animation: drift-a 22s ease-in-out infinite alternate;
        }
        .hero::after {
            width: 540px; height: 540px; top: -180px; right: -160px;
            background: radial-gradient(circle, rgb(13 148 136 / .22), transparent 68%);
            animation: drift-b 26s ease-in-out infinite alternate;
        }

        .hero-inner { display: grid; gap: 64px; grid-template-columns: 1.05fr .95fr; align-items: center; }

        .eyebrow {
            display: inline-flex; align-items: center; gap: 9px; margin-bottom: 24px;
            font-size: 12.5px; font-weight: 600;
            color: var(--teal-800);
            background: linear-gradient(135deg, var(--teal-50), #fff);
            border: 1px solid var(--teal-200);
            padding: 7px 15px 7px 11px; border-radius: 999px;
            box-shadow: var(--sh-xs);
        }
        /* Titik berdenyut: penanda "aktif" tanpa perlu kata. */
        .eyebrow::before {
            content: ""; width: 7px; height: 7px; border-radius: 50%;
            background: var(--teal-500); flex: none;
            animation: pulse 2.4s var(--ease) infinite;
        }

        h1 {
            margin: 0 0 22px;
            font-size: clamp(36px, 5.2vw, 55px); line-height: 1.06;
            letter-spacing: -.038em; font-weight: 800;
        }
        /* Gradien pada kata kunci, dengan `color` sebagai fallback untuk
           browser lama yang tidak mendukung background-clip: text. */
        h1 em {
            font-style: normal; color: var(--teal-700);
            background: linear-gradient(120deg, var(--teal-600), var(--teal-400) 60%, var(--teal-300));
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .lede { margin: 0 0 34px; font-size: 18.5px; line-height: 1.65; color: var(--ink-soft); max-width: 56ch; }

        .cta { display: flex; flex-wrap: wrap; gap: 13px; }

        .note {
            margin-top: 26px; font-size: 13.5px; color: var(--ink-mute);
            display: flex; align-items: flex-start; gap: 9px;
        }

        /* ---------- mockup dashboard ----------

           Kartu ini INTERAKTIF (hover) karena isinya memang sesuatu
           yang bisa dibaca -- visual yang_fee statis seperti kartu fitur
           akan terlihat kosong. Sumber kebenarannya tetap mockup, bukan
           data nyata: lihat catatan di markup.

           Bahasa visualnya "terminal", bukan "crypto". Angka Emerald
           dengan label mono + diagram batang sudah delivering hal yang
           sama dengan ticker cryptocurrency -- rasa "uang bergerak" --
           tanpa membawa asosiasi volatilitas dan spekulasi yang justru
           salah untuk sistem administrasi desa. */
        .mock {
            position: relative;
            background: var(--slate-900);
            border: 1px solid rgb(255 255 255 / .08);
            border-radius: var(--r-xl);
            box-shadow: var(--sh-xl), 0 0 0 1px rgb(45 212 191 / .06),
                        0 30px 70px -30px rgb(13 148 136 / .40);
            overflow: hidden;
            transition: transform .45s var(--ease), box-shadow .45s var(--ease);
        }
        .mock:hover {
            transform: translateY(-4px);
            box-shadow: var(--sh-xl), 0 0 0 1px rgb(45 212 191 / .14),
                        0 44px 90px -34px rgb(13 148 136 / .55);
        }

        /* Bar judul: tiga titik + path + badge "contoh".
           Badge itu wajib: tanpa label eksplisit, angka mockup bisa
           dibaca calon pembeli sebagai performa nyata. */
        .mock__bar {
            display: flex; align-items: center; gap: 12px;
            padding: 13px 18px;
            border-bottom: 1px solid rgb(255 255 255 / .07);
            background: rgb(255 255 255 / .025);
        }
        .mock__dots { display: flex; gap: 6px; flex: none; }
        .mock__dots i { width: 10px; height: 10px; border-radius: 50%; background: rgb(255 255 255 / .16); }

        .mock__path {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px; color: var(--slate-400);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .mock__badge {
            margin-left: auto; flex: none;
            font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            color: var(--teal-300);
            background: rgb(45 212 191 / .12);
            border: 1px solid rgb(45 212 191 / .25);
            padding: 3px 9px; border-radius: 999px;
        }

        .mock__body {
            display: grid; gap: 10px;
            grid-template-columns: 1fr 1fr;
            padding: 20px;
        }

        .mock__stat {
            display: flex; flex-direction: column; gap: 5px;
            background: rgb(255 255 255 / .035);
            border: 1px solid rgb(255 255 255 / .06);
            border-radius: var(--r);
            padding: 15px 16px;
            transition: border-color .35s var(--ease), background .35s var(--ease);
        }
        .mock__stat:hover { border-color: rgb(45 212 191 / .3); background: rgb(45 212 191 / .05); }

        .mock__label {
            font-size: 11.5px; font-weight: 500; letter-spacing: .02em;
            color: var(--slate-400);
        }

        /* Angka tabular: tanpanya, digit akan "berganti lebar" saat
           dihitung -- angka yang melompat-lompat terlihat glitch. */
        .mock__value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 21px; font-weight: 600; letter-spacing: -.02em;
            color: var(--emerald-300);
            font-variant-numeric: tabular-nums;
        }

        .mock__delta { font-size: 11.5px; color: var(--slate-500); }
        .mock__delta.up   { color: var(--emerald-400); }
        .mock__delta.down { color: var(--amber-400); }

        .mock__tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 2px; }
        .mock__tags i {
            font-style: normal; font-size: 11px; font-weight: 500;
            color: var(--teal-200);
            background: rgb(45 212 191 / .10);
            border: 1px solid rgb(45 212 191 / .2);
            padding: 3px 8px; border-radius: 7px;
        }

        .mock__chart { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 10px; }

        /* Batang tumbuh dari bawah: tinggi ditulis inline lewat --h.
           Animasi memakai scaleY dengan transform-origin bawah, jadi
           tidak memicu layout selama animasi.

           max-width itu penting. Tanpa batas lebar, `flex: 1` bikin
           tiap batang mengisi seluruh lebar kartu -- hasilnya terbaca
           seperti barcode, bukan grafik. space-between menjaga
           jaraknya merata setelah batang kena batas lebar. */
        /* PENTING: prefix `deck-` wajib. Class polos `.bar` sudah dipakai
           `.wrap.bar` di header, dan CSS batang di bawah menimpa header:
           `max-width: 42px` membuat header selebar 42px dan gradien teal
           membuat header hijau. Tabrakan nama kelas seperti ini tidak
           melempar error -- hanya diam-diam merusak tampilan. */
        .bars {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 8px; height: 76px;
        }
        .deck-bar {
            flex: 1; max-width: 42px;
            height: var(--h, 50%);
            border-radius: 5px 5px 2px 2px;
            background: linear-gradient(180deg, var(--teal-400), var(--teal-600));
            transform-origin: bottom;
            animation: grow .9s var(--ease) backwards;
        }
        /* Batang terakhir disorot: ini yang dibaca mata lebih dulu. */
        .deck-bar--on {
            background: linear-gradient(180deg, var(--emerald-300), var(--teal-500));
            box-shadow: 0 0 18px -4px rgb(45 212 191 / .55);
        }

        /* Garis dasar: tanpa itu, batang yang menempel di tepi bawah
           area grafik terlihat melayang. Border dipakai (bukan
           background) supaya tidak menambah tinggi layout. */
        .bars { border-bottom: 1px solid rgb(255 255 255 / .07); }

        @keyframes grow {
            from { transform: scaleY(0); }
            to   { transform: scaleY(1); }
        }

        /* Jeda bertingkat supaya batang tumbuh berurutan, bukan
           sekaligus -- tanpa JS, cukup nth-child di CSS. */
        .deck-bar:nth-child(1) { animation-delay: .10s; }
        .deck-bar:nth-child(2) { animation-delay: .20s; }
        .deck-bar:nth-child(3) { animation-delay: .30s; }
        .deck-bar:nth-child(4) { animation-delay: .40s; }
        .deck-bar:nth-child(5) { animation-delay: .50s; }
        .deck-bar:nth-child(6) { animation-delay: .60s; }

        /* Baris kecil di bawah mockup: jalan keluar untuk yang sudah jadi
           pelanggan. Sengaja TIDAK diberi tautan -- halaman ini untuk
           calon pembeli baru, dan setiap tautan login di sini cuma
           menambah satu alasan untuk pergi sebelum sempat membaca. */
        .mock__foot {
            margin: 0; padding: 14px 20px 16px;
            border-top: 1px solid rgb(255 255 255 / .07);
            background: rgb(255 255 255 / .02);
            font-size: 12.5px; line-height: 1.6; text-align: center;
            color: var(--slate-500);
        }
        .mock__foot strong { color: var(--slate-400); font-weight: 600; }

        /* ---------- section ---------- */
        section { padding: 80px 0; }
        section.tint {
            background: linear-gradient(180deg, var(--bg-soft), #fff);
            border-block: 1px solid var(--line);
        }

        .sec-head { max-width: 64ch; margin-bottom: 52px; }
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
           cahaya, warna, dan isi. Semuamotion di bawah dipicu hover
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
            background: linear-gradient(90deg, transparent, var(--teal-500), var(--teal-300), transparent);
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
                rgb(45 212 191 / .13),
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
            border-color: var(--teal-200);
            /* Hanya shadow yang dalam sedikit. Bukan shadow yang
               "naik" seperti kartu klik -- bedanya sengaja dibuat
               tipis supaya tidak terbaca sebagai undiscoverable
               surface yang bisa ditekan. */
            box-shadow: var(--sh-lg);
        }

        .card:hover::before { opacity: 1; transform: scaleX(1); }
        .card:hover::after  { opacity: 1; }

        /* Ikon: kotak warnanya bergerak, glyph di dalamnya tidak.

           Glyph ikut bergerak akan terbaca sebagai tombol yang
           "menekan" ikon -- persis hal yang harus dihindari di
           kartu inert. Yang bergerak di sini cuma gradasi, yang
           jelas-jelas dekoratif. */
        .card .ico {
            width: 46px; height: 46px; border-radius: 13px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, var(--teal-50), var(--teal-100));
            color: var(--teal-700); font-size: 22px; margin-bottom: 17px;
            box-shadow: var(--sh-sm);
            transition: background .45s var(--ease), box-shadow .45s var(--ease),
                        border-radius .45s var(--ease);
        }

        .card:hover .ico {
            background: linear-gradient(135deg, var(--teal-100), var(--teal-200));
            border-radius: 10px;
            box-shadow: var(--sh-md);
        }

        .card h3 { margin: 0 0 8px; font-size: 17.5px; letter-spacing: -.016em; font-weight: 700; }
        .card p { margin: 0; font-size: 14.5px; line-height: 1.62; color: var(--ink-soft); }

        /* ---------- animasi masuk kartu ----------

           `.reveal.shown` memakai `rise` yang sama dengan elemen lain,
           tapi kartu dapat versi sendiri: masuk dari bawah_LEBIH jauh
           dan sedikit miring, supaya barisan kartu terasa naik sebagai
           satu blok, bukan 7 elemen yang kebetulan muncul bersamaan.

           Panjang animasi 0.78s dari `rise` di sini diturunkan
           memorable: 7 kartu dengan delay 70ms = 420ms lebih akhir
           untuk yang terakhir. Kalau tiap kartu ikut 0.78s penuh,
           baris terakhir baru selesai di 1.2s -- orang yang sedang
           scroll cepat akan BNI melihat apa pun dan menganggap halaman
           lambat. */
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

           Sama seperti .card: panel statis, bukan elemen interaktif.
           Shadow aktif supaya menyatu dengan kartu fitur. */
        .steps { display: grid; gap: 26px; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); counter-reset: s; }

        .step {
            position: relative; padding: 28px 24px 26px;
            background: #fff; border: 1px solid var(--line); border-radius: var(--r-lg);
            box-shadow: var(--sh-md);
        }

        .step::before {
            counter-increment: s; content: counter(s);
            position: absolute; top: 24px; right: 24px;
            width: 40px; height: 40px; display: grid; place-items: center;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--teal-500), var(--teal-700));
            color: #fff; font-size: 16px; font-weight: 800;
            box-shadow: var(--sh-brand);
        }

        .step h3 { margin: 0 0 8px; font-size: 17px; letter-spacing: -.014em; font-weight: 700; padding-right: 52px; }
        .step p { margin: 0; font-size: 14.5px; line-height: 1.62; color: var(--ink-soft); }

        /* ---------- CTA penutup ---------- */
        .closer {
            position: relative; overflow: hidden;
            background: linear-gradient(150deg, var(--teal-800), var(--teal-950));
            color: #fff; border-radius: var(--r-xl);
            padding: 60px 48px; text-align: center;
            box-shadow: 0 20px 50px -18px rgb(4 47 46 / .55), 0 4px 12px -4px rgb(4 47 46 / .3);
        }
        /* Pola grid halus supaya bidang gelap tidak terasa kosong. */
        .closer::before {
            content: ""; position: absolute; inset: 0;
            background-image: linear-gradient(rgb(255 255 255 / .05) 1px, transparent 1px),
                              linear-gradient(90deg, rgb(255 255 255 / .05) 1px, transparent 1px);
            background-size: 46px 46px;
            -webkit-mask-image: radial-gradient(circle at 50% 40%, #000, transparent 72%);
                    mask-image: radial-gradient(circle at 50% 40%, #000, transparent 72%);
            pointer-events: none;
        }
        /* Glow berputar pelan di belakang teks. */
        .closer::after {
            content: ""; position: absolute;
            width: 520px; height: 520px; top: -180px; left: 50%; margin-left: -260px;
            background: radial-gradient(circle, rgb(45 212 191 / .26), transparent 62%);
            filter: blur(64px); pointer-events: none;
            animation: drift-b 20s ease-in-out infinite alternate;
        }
        .closer > * { position: relative; z-index: 1; }

        .closer h2 { margin: 0 0 14px; font-size: clamp(26px, 3.2vw, 34px); letter-spacing: -.028em; font-weight: 800; }
        .closer p { margin: 0 auto 30px; max-width: 54ch; color: var(--teal-100); font-size: 17px; }
        .closer .btn-primary { background: #fff; color: var(--teal-800); box-shadow: 0 8px 22px -8px rgb(0 0 0 / .4); }
        .closer .btn-primary:hover { background: var(--teal-50); }
        .closer .btn-ghost {
            background: rgb(255 255 255 / .07); border-color: rgb(255 255 255 / .3);
            color: #fff; backdrop-filter: blur(4px);
        }
        .closer .btn-ghost:hover { border-color: #fff; background: rgb(255 255 255 / .16); }

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
            background: linear-gradient(140deg, var(--teal-500), var(--teal-700));
            box-shadow: var(--sh-brand), 0 10px 24px -10px rgb(4 47 46 / .5);

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
            box-shadow: var(--sh-brand), 0 16px 34px -12px rgb(4 47 46 / .6);
        }
        .to-top:active { transform: translateY(1px) scale(.94); }

        /* Panah: dibuat dari dua garis yang berputar 45° (chevron),
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
            color: var(--teal-700); text-decoration: none; font-weight: 600; font-size: 13.5px;
            transition: color .2s var(--ease);
        }
        .foot a:hover { color: var(--teal-800); text-decoration: underline; text-underline-offset: 3px; }

        /* ---------- responsif ---------- */
        @media (max-width: 900px) {
            .hero { padding: 60px 0 52px; }
            .hero-inner { grid-template-columns: 1fr; gap: 44px; }

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
            .nav a.navlink.is-active { box-shadow: inset 3px 0 0 var(--teal-500); }

            .nav .btn { width: 100%; margin-top: 6px; padding: 12px; }

            /* Panel terbuka: kunci scroll halaman di belakang.
               Tanpa ini, sentuh di panel yang lebih pendek dari
               viewport tetap ikut menggulir halaman di belakangnya. */
            html.nav-open { overflow: hidden; }
        }

        @media (max-width: 520px) {
            .wrap { padding-inline: 18px; }
            section { padding: 56px 0; }
            .closer { padding: 42px 22px; }
            .card, .step { padding: 22px 20px; }
            .step::before { top: 18px; right: 18px; width: 34px; height: 34px; font-size: 14px; }
            .step h3 { padding-right: 44px; }

            /* Panel menu ikut menyempit agar sejajar dengan teks. */
            .nav { inset: calc(100% + 8px) 18px auto 18px; }

            /* Dua kolom jadi satu di layar sempit: angka 21px dalam
               setengah lebar 390px akan terpotong. */
            .mock__body { grid-template-columns: 1fr; padding: 16px; }
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
            .hero::before, .hero::after, .closer::after { animation: none; }

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
        }
    </style>
</head>

<body>
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
                --}}
                <a class="btn btn-ghost" href="{{ $centralLoginUrl }}">Masuk</a>

                <a class="btn btn-primary" href="mailto:admin@astabrata.co.id?subject=Penawaran%20ASTALOKA">Ajukan Penawaran</a>
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
        <div class="hero">
            <div class="wrap hero-inner">
                <div class="reveal">
                    <span class="eyebrow">Asta Layanan Operasional, Keuangan, dan Administrasi</span>

                    <h1>Satu sistem untuk <em>semua</em> usaha desa.</h1>

                    <p class="lede">
                        ASTALOKA adalah platform pengelolaan usaha dan administrasi
                        dari Asta Brata Teknologi. Air minum, internet, pembayaran
                        sampah, atau layanan lain &mdash; semuanya berjalan di atas
                        ASTALOKA. Setiap lokasi punya datanya sendiri, terpisah penuh,
                        jadi satu lokasi tidak pernah bisa melihat angka lokasi lain.
                    </p>

                    <div class="cta">
                        <a class="btn btn-primary btn-lg" href="mailto:admin@astabrata.co.id?subject=Penawaran%20ASTALOKA">Ajukan Penawaran</a>
                        <a class="btn btn-ghost btn-lg" href="#fitur">Lihat Fitur</a>
                    </div>

                    <p class="note">
                        <span aria-hidden="true">&#9432;</span>
                        <span>
                            Tanpa biaya instalasi. Data Anda milik Anda, tersimpan
                            di server terpisah dari lokasi lain.
                        </span>
                    </p>
                </div>

                {{-- ---------- pratinjau dashboard ---------- --}}
                {{--
                    Kartu mockup dashboard, bukan ringkasan teks.

                    Alasan pakai visual: daftar bullet menjelaskan fitur,
                    tapi calon pembeli yang melihat dashboard bisa
                    langsung paham ini sistem yang jalan -- bukan mockup
                    wireframe kosong.

                    PENTING SOAL KEJUJURAN: semua angka di sini HARUS
                    memakai class `mock`. Aturan mainnya satu: kalau
                    elemen punya `mock`, isinya boleh berubah kapan saja
                    tanpa perlu review karena tidak pernah ditampilkan
                    sebagai angka asli. Elemen tanpa `mock` berarti
                    kalimat yang benar-benar diklaim ke calon pembeli, jadi
                    jangan pernah menaruh angka di sana.

                    Angka yang tampil BUKAN data produksi. Data ada di
                    database terpisah per lokasi dan tidak pernah dimuat
                    ke halaman publik.
                --}}
                <div class="mock reveal">
                    <div class="mock__bar">
                        <span class="mock__dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span class="mock__path">astaloka &mdash; dashboard</span>
                        <span class="mock__badge">contoh tampilan</span>
                    </div>

                    <div class="mock__body">
                        <div class="mock__stat">
                            <span class="mock__label">Pemasukan bulan ini</span>
                            <span class="mock__value" data-count="18450000" data-money>Rp 0</span>
                            <span class="mock__delta up">&#9650; 12,4% dari bulan lalu</span>
                        </div>

                        <div class="mock__stat">
                            <span class="mock__label">Tagihan belum lunas</span>
                            <span class="mock__value" data-count="9230000" data-money>Rp 0</span>
                            <span class="mock__delta down">&#9660; 7 customer menunggak</span>
                        </div>

                        {{-- Grafik batang: tinggi tiap batang ditulis inline sebagai --h.
                             Murni CSS, tanpa JS dan tanpa gambar. --}}
                        <div class="mock__chart">
                            <span class="mock__label">Penagihan 6 bulan terakhir</span>
                            <div class="bars" aria-hidden="true">
                                <span class="deck-bar" style="--h: 42%"></span>
                                <span class="deck-bar" style="--h: 58%"></span>
                                <span class="deck-bar" style="--h: 49%"></span>
                                <span class="deck-bar" style="--h: 71%"></span>
                                <span class="deck-bar" style="--h: 64%"></span>
                                <span class="deck-bar deck-bar--on" style="--h: 88%"></span>
                            </div>
                        </div>

                        <div class="mock__stat">
                            <span class="mock__label">Pelanggan aktif</span>
                            <span class="mock__value" data-count="1643">0</span>
                            <span class="mock__delta">terdaftar di lokasi ini saja</span>
                        </div>

                        <div class="mock__stat">
                            <span class="mock__label">Laporan siap</span>
                            <span class="mock__tags">
                                <i>Neraca</i><i>Laba Rugi</i><i>Buku Besar</i><i>Arus Kas</i>
                            </span>
                        </div>
                    </div>

                    <p class="mock__foot">
                        Sudah menjadi pelanggan ASTALOKA? Buka langsung
                        <strong>alamat subdomain lokasi Anda</strong>, atau
                        <a href="{{ $centralLoginUrl }}">masuk ke panel pusat</a>.
                    </p>
                </div>
            </div>
        </div>

        {{-- ---------- fitur ---------- --}}
        <section id="fitur">
            <div class="wrap">
                <div class="sec-head reveal">
                    <h2>Satu sistem, semua kebutuhan operasional</h2>
                    <p>
                        ASTALOKA bukan aplikasi air minum. Ia landasan yang bisa
                        dipakai apa pun &mdash; air, internet, sampah, atau layanan
                        desa lain &mdash; dengan modul yang sama-sama sudah siap.
                    </p>
                </div>

                <div class="grid">
                    <div class="card reveal" style="--d: 0ms">
                        <div class="ico" aria-hidden="true">&#128167;</div>
                        <h3>Air Minum</h3>
                        <p>Pencatatan meter, tarif berjenjang, tagihan otomatis, dan riwayat pelanggan air minum dalam satu alur.</p>
                    </div>

                    <div class="card reveal" style="--d: 70ms">
                        <div class="ico" aria-hidden="true">&#128225;</div>
                        <h3>Internet &amp; Tv</h3>
                        <p>Paket langganan, biaya instalasi, penagihan per bulan, dan monitoring pelanggan aktif.</p>
                    </div>

                    <div class="card reveal" style="--d: 140ms">
                        <div class="ico" aria-hidden="true">&#128465;</div>
                        <h3>Pembayaran Sampah</h3>
                        <p>Tagihan angkut dan aturan retribusi per pelanggan, lengkap dengan catatan setoran dan tunggakan.</p>
                    </div>

                    <div class="card reveal" style="--d: 210ms">
                        <div class="ico" aria-hidden="true">&#128101;</div>
                        <h3>Data Pelanggan</h3>
                        <p>Satu basis pelanggan untuk semua layanan, dengan riwayat langganan dan status pembayaran per pelanggan.</p>
                    </div>

                    <div class="card reveal" style="--d: 280ms">
                        <div class="ico" aria-hidden="true">&#128202;</div>
                        <h3>Keuangan &amp; Jurnal</h3>
                        <p>Jurnal masuk dan keluar, buku besar, laba rugi, sampai tutup buku tahunan tanpa Excel.</p>
                    </div>

                    <div class="card reveal" style="--d: 350ms">
                        <div class="ico" aria-hidden="true">&#128203;</div>
                        <h3>Laporan</h3>
                        <p>Laporan tagihan, tunggakan, dan keuangan siap cetak dengan kop lembaga masing-masing lokasi.</p>
                    </div>

                    <div class="card reveal" style="--d: 420ms">
                        <div class="ico" aria-hidden="true">&#128274;</div>
                        <h3>Data Terpisah</h3>
                        <p>Tiap lokasi punya basis data sendiri. Data keuangan satu lokasi tidak pernah bisa diakses lokasi lain.</p>
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
                        <h3>Hubungi kami</h3>
                        <p>Sampaikan kebutuhan usaha Anda &mdash; air minum, internet, sampah, atau lainnya. Kami bantu pilihkan paket yang paling pas.</p>
                    </div>

                    <div class="step reveal" style="--d: 90ms">
                        <h3>Akun langsung jadi</h3>
                        <p>Lokasi Anda dibuat lengkap dengan database sendiri dan akun admin. Tidak perlu install, tidak perlu server, tidak perlu biaya awal.</p>
                    </div>

                    <div class="step reveal" style="--d: 180ms">
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
                        Punya BUMDes, kampung, atau lembaga yang butuh sistem
                        operasional dan keuangan yang rapi? Hubungi Asta Brata
                        Teknologi untuk demo gratis dan penawaran harga.
                    </p>
                    <div class="cta" style="justify-content:center">
                        <a class="btn btn-primary btn-lg" href="mailto:admin@astabrata.co.id?subject=Demo%20ASTALOKA">Minta Demo Gratis</a>
                        <a class="btn btn-ghost btn-lg" href="mailto:admin@astabrata.co.id?subject=Penawaran%20ASTALOKA">Tanya Harga</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap foot">
            <p>&copy; {{ date('Y') }} Asta Brata Teknologi. ASTALOKA &mdash; Asta Layanan Operasional, Keuangan, dan Administrasi.</p>

            {{--
                DiperSatukan di footer, bukan di section mockup.

                Kalimat "Buka langsung alamat subdomain lokasi Anda" yang
                ada di mockup itu bukan ajakan untuk mengetik apa pun --
                pelanggan yang sudah tahu subdomain-nya akan mengetik
                subdomain-nya sendiri. Yang hilang dari halaman ini adalah
                satu jalan untuk orang yang masuk ke domain pusat, yaitu
                superadmin, dan untuk orang yang salah mengetik subdomain lalu mendarat di sini.
                ke sini dari subdomain yang salah ketik.

                `/central/login`, bukan `/login`: lihat catatan di navbar.
            --}}
            <p>
                Sudah punya akun?
                <a href="{{ $centralLoginUrl }}">Masuk ke panel</a>
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
