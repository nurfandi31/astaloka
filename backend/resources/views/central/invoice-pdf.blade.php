{{--
    Cetakan invoice langganan ASTALOKA.

    Mengikuti cetakan di SABIT
    (`sabit/resources/views/tenant/invoice/print.blade.php`) sedekat mungkin:
    kop bergaris atas-bawah dengan logo di kiri, judul INVOICE + status besar
    di kanan, blok "Dikirim Kepada", tabel DESCRIPTION/TOTAL, tabel total
    dengan kolom nilai berlatar abu-biru, blok pembayaran, lalu blok tanda
    tangan di kanan bawah.

    Meniru sini bukan sekadar soal tampilan. Penerima tagihan di lokasi sudah
    pernah menerima invoice dengan bentuk seperti ini dari ASTA BRATA, dan
    invoice yang bentuknya berubah mendadak terasa seperti dokumen dari pihak
    lain -- penerima menganggapnya palsu sebelum sempat membaca nominalnya.

    Yang tetap BEDA dari SABIT:
      - memakai nomor bertingkat yang sebenarnya (`INV/2026/0001`), bukan
        `INVOICE #3` yang memakai id baris;
      - menampilkan rincian item, bukan cuma satu baris;
      - menampilkan jatuh tempo, karena di sistem ini akses baru terbuka lagi
        setelah tagihan dilunasi;
      - TIDAK menampilkan PPN.

    PENTANGAN SOAL PPN
    ------------------
    Di SABIT, baris "PPN 11%" dihitung tapi TIDAK dijumlahkan ke TOTAL --
    TOTAL sama dengan Sub Total. Itu bukan keputusan yang disengaja, dan
    menirunya berarti cetakan menampilkan nominal pajak yang tidak pernah
    dibayar. Di sini PPN sengaja tidak ditampilkan sama sekali: tagihan
    langganan ini belum ada keputusan pajak yang disepakati, dan menampilkan
    angka yang tidak ditagih hanya menambah pertanyaan dari sisi lokasi.
    Kalau PPN nanti benar-benar dibutuhkan, tambahkan sebagai baris yang ikut
    masuk ke TOTAL -- jangan sebagai baris yang hanya informatif.


    TENTANG SATU HALAMAN SAJA -- kenapa TIDAK pakai float
    ---------------------------------------------------
    Versi sebelumnya memakai `float` + `<div class="bersih"></div>` (clear:
    both) seperti SABIT, dan hasilnya selalu DUA halaman untuk tagihan mana
    pun.

    Penyebabnya sudah diuji: bukan tinggi konten, bukan margin. Menghapus
    semua div `bersih` membuat hasilnya turun ke satu halaman, sedangkan
    memperkecil margin sekenapa pun tidak menolong. Dompdf menghitung letak
    elemen `clear` berbeda dari peramban -- float yang berada rendah membuat
    elemen kosong penanda halaman berikutnya itu jatuh melewati batas bawah, dan
    Dompdf membuat halaman kedua yang isinya tidak ada. Hasilnya: satu tagihan
    tercetak di dua lembar, dan lembar kedua itu kosong.

    Karena itu tata letak di sini memakai TABEL, bukan float:

      - kolom kop (logo | identitas perusahaan) -> tabel 2 kolom;
      - judul (kiri | status) -> tabel 2 kolom;
      - tabel total -> tabel dengan `align="right"`;
      - blok tanda tangan -> tabel 1 kolom dengan `align="right"`. Butuh
        `width: 100%` pada tabel luarnya: tanpa itu tabel menyusut ke lebar
        isinya dan menempel di kiri, jadi rata kanannya tidak berlaku.

    Tabel adalah satu-satunya cara membagi dua kolom yang Calculate width-nya
    sama di Dompdf dan di peramban. Menambah float hanya mengembalikan masalah yang
    sama dengan bentuk yang lebih sulit didiagnosis.


    Dokumen ini dirender oleh Dompdf, bukan peramban. Makanya:
      - CSS disederhanakan; tidak ada flexbox/grid modern;
      - gambar di-inline sebagai base64 (lihat InvoiceController::pdf()).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->nomor }}</title>

    <style>
        * {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10px;
        }

        body {
            margin: 0;
            padding: 15px;
            color: #1f2937;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        /* ── Kop ── */
        .kop {
            border-top: 2px solid #1c6b83;
            border-bottom: 2px solid #1c6b83;
            padding: 8px 0;
        }

        .kop__perusahaan h1 {
            margin: 0;
            font-size: 19px;
            color: #0d5b74;
        }

        .kop__perusahaan p {
            margin: 1px 0;
            font-size: 8.5px;
            color: #444;
        }

        /* ── Judul + status ── */
        .judul {
            margin-top: 10px;
        }

        .judul h2 {
            margin: 0;
            font-size: 25px;
        }

        .judul__nomor { color: #0d5b74; }

        .judul__meta { margin-top: 3px; }

        .judul__meta b { font-weight: bold; }

        .status {
            font-size: 22px;
            font-weight: bold;
        }

        .status--lunas { color: #198754; }
        .status--belum { color: #dc3545; }

        .status__cap {
            width: 92px;
            margin: 4px 0 0 auto;
        }

        /* ── Ditagihkan kepada ──
             Polos, tanpa kotak, sama seperti di SABIT. Baris-baris di sini
             sudah punya pemisah berupa garis kop, spasi, dan tebal, jadi
             kotak hanya menambah garis lain tanpa menambah informasi. */
        .dikirim { margin-top: 8px; }

        .dikirim b { font-weight: bold; }

        .dikirim__nama {
            font-size: 11px;
            font-weight: bold;
        }

        /* ── Tabel rincian ── */
        .rincian { margin-top: 10px; }

        thead th {
            background: #0d5b74;
            color: #fff;
            padding: 6px 8px;
            font-size: 9px;
            letter-spacing: 0.4px;
        }

        thead th.kanan { text-align: right; }
        thead th.tengah { text-align: center; }

        tbody td {
            padding: 5px 8px;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }

        td.kanan { text-align: right; }
        td.tengah { text-align: center; }

        .isi-item { font-weight: bold; }

        .catatan-item {
            font-size: 8.5px;
            color: #6b7280;
            margin-top: 2px;
        }

        /*
         * Satuan ditulis menyatu dengan jumlah, bukan di baris berikutnya.
         * Diperankanya justru soal ruang: satuan yang diletakkan di baris
         * sendiri menambah tinggi satu baris item, dan tinggi baris item itu
         * yang menentukan berapa baris muat satu lembar. Ruang yang dihemat di
         * sini dipakai untuk baris rincian yang lebih banyak, bukan untuk
         * spasi kosong.
         *-ruang yang dihemat itu dipakai untuk baris rincian yang lebih
         * banyak, bukan untuk spasi kosong.
         */
        .satuan-item {
            font-size: 8.5px;
            color: #6b7280;
        }

        .baris-sederhana {
            text-align: center;
            font-weight: bold;
        }

        /* ── Tabel total ──
             Kolom nilai diberi latar abu-biru seperti di SABIT: teks putih
             di atasnya membuat nilai terbaca sebagai blok tersendiri, bukan
             sekadar deretan angka di kanan tabel. */
        .total {
            width: 300px;
            margin-top: 6px;
        }

        .total td { padding: 5px 8px; }

        .total td.keterangan {
            text-align: right;
            font-weight: bold;
        }

        .total td.nilai {
            background: #8da8b5;
            color: #fff;
            font-weight: bold;
            text-align: right;
        }

        .total__baris-final td {
            font-size: 11px;
            font-weight: bold;
        }

        .total__diskon td.nilai { background: #a9bfc9; }

        /* ── Pembayaran ── */
        .pembayaran {
            margin-top: 14px;
        }

        .pembayaran b { font-weight: bold; }

        .pembayaran ul {
            margin: 4px 0 0;
            padding-left: 15px;
            color: #374151;
            line-height: 1.7;
        }

        /* ── Tanda tangan ── */
        .ttd__nama { font-weight: bold; }

        .ttd__ruang { height: 30px; }

        /* ── Catatan kaki ── */
        .kaki {
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #666;
            font-size: 8.5px;
            line-height: 1.5;
        }
    </style>
</head>
<body>

{{-- ── Kop ── --}}
<table class="kop">
    <tr>
        <td style="width: 70px;">
            @if(!empty($logo))
                <img src="data:image/png;base64,{{ $logo }}" alt="Logo ASTA BRATA" style="width: 52px;">
            @endif
        </td>
        <td>
            <div class="kop__perusahaan">
                <h1>PT. ASTA BRATA TEKNOLOGI</h1>
                <p>IT Consulting, System, Training and Digital Audits</p>
                <p>SK. Kemenkumham RI Nomor AHU-01329.40.10.2014</p>
                <p>Office : Jalan Perintis Kemerdekaan Km 1.5 Banyurip Magelang</p>
                <p>Telp : (0293) 3195558 &nbsp;|&nbsp; Email : info@astabratagroup.com
                    &nbsp;|&nbsp; Website : astabratagroup.com</p>
            </div>
        </td>
    </tr>
</table>

@php
    $tenant = $invoice->tenant;
    $items = $invoice->items;
    $subTotal = $items->isNotEmpty() ? (float) $items->sum('jumlah') : (float) $invoice->jumlah;

    /*
     * Diskon = selisih antara total rincian dan nominal yang ditagih.
     *
     * Tidak disimpan sebagai kolom terpisah karena dalam kebanyakan tagihan
     * nilainya nol. Yang dihitung ulang saat Cetak, bukan diambil dari
     * invoice, karena selama keduanya dibuat berbaris dari form yang sama
     * selisih selalu nol -- dan jadi tidak perlu kolom yang isinya nol hampir
     * selalu.
     */
    $diskon = $subTotal - (float) $invoice->jumlah;
@endphp

{{-- ── Judul + status ── --}}
<table class="judul">
    <tr>
        <td style="width: 62%; vertical-align: top;">
            <h2>INVOICE <span class="judul__nomor">#{{ $invoice->nomor }}</span></h2>

            <div class="judul__meta">
                <b>Tanggal Invoice :</b> {{ $invoice->tgl_invoice?->format('d/m/Y') ?? '-' }}<br>
                <b>Tanggal Lunas :</b> {{ $invoice->tgl_lunas?->format('d/m/Y') ?? '-' }}<br>
                <b>Jatuh Tempo :</b> {{ $invoice->deadline()->format('d/m/Y') }}
            </div>
        </td>

        <td class="judul__kanan" style="text-align: right; vertical-align: top;">
            @if($invoice->isPaid())
                <div class="status status--lunas">PAID</div>
                @if(!empty($stempelLunas))
                    <img class="status__cap" src="data:image/png;base64,{{ $stempelLunas }}" alt="LUNAS">
                @endif
            @else
                <div class="status status--belum">UNPAID</div>
            @endif
        </td>
    </tr>
</table>

{{-- ── Ditagihkan kepada ── --}}
<div class="dikirim">
    <b>Dikirim Kepada :</b><br>

    <div class="dikirim__nama">{{ $tenant?->label() ?? 'Lokasi' }}</div>
    @if($tenant?->nama && $tenant?->nama !== $tenant?->label())
        {{ $tenant->nama }}<br>
    @endif
    @if($tenant?->domain)
        {{ $tenant->domain }}<br>
    @endif
    @if($tenant?->alamat)
        {{ $tenant->alamat }}<br>
    @endif
    @if($tenant?->email)
        Email: {{ $tenant->email }}<br>
    @endif
    @if($tenant?->telepon)
        Telp: {{ $tenant->telepon }}
    @endif
</div>

{{-- ── Rincian ── --}}
<div class="rincian">
    <table>
        <thead>
            <tr>
                @if($items->isNotEmpty())
                    <th style="width: 44%;">Keterangan</th>
                    <th class="tengah" style="width: 10%;">Qty</th>
                    <th class="kanan" style="width: 23%;">Harga Satuan</th>
                    <th class="kanan" style="width: 23%;">Jumlah</th>
                @else
                    <th style="width: 70%;">DESCRIPTION</th>
                    <th style="width: 30%;">TOTAL</th>
                @endif
            </tr>
        </thead>

        <tbody>
            @forelse($items as $item)
                <tr>
                    <td>
                        <div class="isi-item">{{ $item->nama }}</div>
                        @if($item->catatan)
                            <div class="catatan-item">{{ $item->catatan }}</div>
                        @endif
                    </td>
                    <td class="tengah">
                        {{ rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',') }}@if($item->satuan)&nbsp;<span class="satuan-item">{{ $item->satuan }}</span>@endif
                    </td>
                    <td class="kanan">{{ number_format((float) $item->harga_satuan, 0, ',', '.') }}</td>
                    <td class="kanan">{{ number_format((float) $item->jumlah, 0, ',', '.') }}</td>
                </tr>
            @empty
                {{--
    Invoice tanpa rincian item -- tagihan yang terbit sebelum tabel item ada,
    atau memang sengaja diisi nominal saja. `itemLabel()` sudah jatuh ke
    keterangan atau teks bawaan supaya baris ini tidak pernah kosong: baris
    Keterangan kosong membuat tagihan ini tidak terlihat seperti tagihan
    sama sekali.
--}}
                <tr>
                    <td class="baris-sederhana">
                        {{ $invoice->itemLabel() }}
                        <br>Masa Aktif :
                        {{ $invoice->tgl_invoice->format('d/m/Y') }}
                        -
                        {{ ($invoice->tgl_lunas ?? $invoice->deadline())->format('d/m/Y') }}
                    </td>
                    <td class="kanan">{{ number_format((float) $invoice->jumlah, 0, ',', '.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="total" align="right">
        <tr>
            <td class="keterangan" style="width: 55%;">Sub Total</td>
            <td class="nilai">{{ number_format($subTotal, 0, ',', '.') }}</td>
        </tr>

        @if(abs($diskon) >= 0.01)
            <tr class="total__diskon">
                <td class="keterangan">{{ $diskon > 0 ? 'Diskon' : 'Penyesuaian' }}</td>
                <td class="nilai">{{ $diskon > 0 ? '-' : '+' }} {{ number_format(abs($diskon), 0, ',', '.') }}</td>
            </tr>
        @else
            <tr>
                <td class="keterangan">Diskon</td>
                <td class="nilai">0</td>
            </tr>
        @endif

        <tr class="total__baris-final">
            <td class="keterangan">TOTAL</td>
            <td class="nilai">{{ number_format((float) $invoice->jumlah, 0, ',', '.') }}</td>
        </tr>
    </table>
</div>

{{--
    Rekening.

    Nomor rekening SENGAJA TIDAK dicetak. Yang dicetak hanya nama bank dan nama
    pemilik rekening, karena sistem ini belum punya satu nomor rekening yang
    benar. Cetak nomor yang karangan akan membuat lokasi mengirim uang ke
    rekening yang tidak ada -- dan tagihan yang salah kirim lebih lambat
    dibongkar daripada tagihan yang belum dibayar sama sekali.
--}}
<div class="pembayaran">
    <b>Pembayaran Transfer Via :</b>

    <ul>
        <li>Bank Mandiri &nbsp; a.n. PT. ASTA BRATA TEKNOLOGI</li>
        <li>Bank BRI &nbsp; a.n. PT. ASTA BRATA TEKNOLOGI</li>
        <li>
            <b>Jumlah :</b> {{ number_format((float) $invoice->jumlah, 0, ',', '.') }}
            &nbsp;|&nbsp;
            <b>No. Invoice :</b> {{ $invoice->nomor }}
        </li>
    </ul>
</div>

{{--
    Area tanda tangan.

    Selalu "Direktur Utama", sama seperti di SABIT, dan TIDAK bergantung pada
    status tagihan. Untuk tagihan lunas, tanda tangan adalah pengesahan
    Kwitansi dari pihak penagih -- bukan pengesahan tagihan yang belum dibayar
    seperti yang sempat dicoba sebelumnya, karena itu membingungkan penerima
    invoice (tanda tangan di atas tagihan yang belum dibayar?) sekaligus
    membocorkan siapa yang sudah membayar ke pihak lokasi.

    `ttd.png` boleh tidak ada; ruang kosongnya tetap disediakan supaya blok
    tanda tangan tidak tampak tidak sengaja hilang.

    Blok ini di KANAN halaman, sejajar dengan blok total dan rekening di
    atasnya.

    Penempatannya sebelum ini terlihat di kiri, dan itu bukan karena blok ini
    memang rata kiri. TABEL LUARNYA tidak punya `width: 100%`, jadi tabel itu
    menyusut selebar isinya (250px) dan menempel di sisi kiri halaman --
    membuat `align="right"` pada selnya tidak pernah berarti apa-apa.

    Width 100% plus sel kosong di sebelah kiri membuatnya benar-benar rata
    kanan, dan bentuknya tetap sama dengan tabel total (`align="right"`).

    Tetap tabel, bukan float: lihat catatan tata letak di bagian atas file ini.
--}}
<table style="margin-top: 10px; width: 100%;">
    <tr>
        <td></td>
        <td align="right" style="width: 250px;">
            <table style="width: 250px;">
                <tr>
                    <td align="center">
                        Direktur Utama

                        @if(!empty($ttd))
                            <br><img src="data:image/png;base64,{{ $ttd }}" alt="Tanda tangan" style="width: 95px;">
                        @else
                            <div class="ttd__ruang"></div>
                        @endif

                        <div class="ttd__nama">PT. ASTA BRATA TEKNOLOGI</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="kaki">
    Invoice ini diterbitkan otomatis oleh sistem ASTALOKA Panel Pusat.<br>
    Tagihan yang belum dilunasi akan memblokir akses aplikasi lokasi sampai pembayaran diterima.
</div>

</body>
</html>