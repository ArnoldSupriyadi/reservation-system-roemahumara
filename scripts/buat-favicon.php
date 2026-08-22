<?php

/*
 * Membuat favicon dari logo gold.
 *
 * Dijalankan sekali dan hasilnya ikut git; ini bukan bagian dari build. Skrip
 * disimpan supaya kalau logonya berganti, favicon-nya bisa dibuat ulang dengan
 * potongan yang sama persis, bukan ditebak lagi:
 *
 *     php scripts/buat-favicon.php
 *
 * Yang dipakai HANYA bagian tengah ornamen, bukan logo utuh. Logo utuh berisi
 * tulisan "ROEMAH UMARA" yang di 16px jadi noda tak terbaca, dan ornamen
 * lengkapnya berbentuk lanskap 2:1 sehingga di dalam kotak favicon ia hanya
 * mengisi separuh tinggi dan detail sulurnya hilang. Potongan tengah mengisi
 * kotaknya penuh dan masih terbaca sebagai motif yang sama.
 */

$sumber = __DIR__.'/../public/img/LOGO ROEMAH UMARA-gold.png';
$tujuan = __DIR__.'/../public/img';

if (! is_file($sumber)) {
    fwrite(STDERR, "Logo tidak ditemukan: {$sumber}\n");
    exit(1);
}

$src = imagecreatefrompng($sumber);

/*
 * Kotak ornamen pada logo 8000x4500, hasil pemindaian piksel bukan taksiran:
 * pita bertinta pertama ada di y 640-2938, x 1662-6254. Pita kedua (y 3208-3860)
 * adalah tulisannya, sengaja tidak diikutkan.
 */
$ornamen = ['x1' => 1662, 'y1' => 640, 'x2' => 6254, 'y2' => 2938];

$tinggi = $ornamen['y2'] - $ornamen['y1'];
$tengahX = (int) round(($ornamen['x1'] + $ornamen['x2']) / 2);

// 1.06 memberi sedikit ruang napas. Ornamen yang menyentuh tepi terlihat sesak
// dan terpotong pada sudut membulat yang dipakai sebagian peramban.
$sisi = (int) round($tinggi * 1.06);

$kotak = imagecreatetruecolor($sisi, $sisi);
imagesavealpha($kotak, true);
imagealphablending($kotak, false);
imagefill($kotak, 0, 0, imagecolorallocatealpha($kotak, 0, 0, 0, 127));
imagealphablending($kotak, true);
imagecopy(
    $kotak,
    $src,
    0,
    (int) round(($sisi - $tinggi) / 2),
    (int) round($tengahX - $sisi / 2),
    $ornamen['y1'],
    $sisi,
    $tinggi,
);

/** @var array<int, string> $png ukuran => isi berkas PNG */
$png = [];

foreach ([512, 180, 32, 16] as $ukuran) {
    $out = imagecreatetruecolor($ukuran, $ukuran);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $kotak, 0, 0, 0, 0, $ukuran, $ukuran, $sisi, $sisi);

    imagepng($out, "{$tujuan}/favicon-{$ukuran}.png", 9);

    ob_start();
    imagepng($out, null, 9);
    $png[$ukuran] = ob_get_clean();

    imagedestroy($out);
    echo "favicon-{$ukuran}.png\n";
}

/*
 * favicon.ico berisi PNG 16 dan 32 yang disematkan.
 *
 * Peramban modern sebenarnya menerima <link rel="icon" type="image/png">, tapi
 * .ico di akar situs tetap diminta diam-diam oleh peramban lama dan sebagian
 * pembaca RSS. Tanpa berkasnya, permintaan itu berakhir 404 di log tiap hari.
 */
$jumlah = count([16, 32]);
$ico = pack('vvv', 0, 1, $jumlah);
$offset = 6 + 16 * $jumlah;

foreach ([16, 32] as $ukuran) {
    $isi = $png[$ukuran];
    // Lebar dan tinggi 0 berarti 256 pada format ICO; ukuran kita di bawah itu.
    $ico .= pack('CCCCvvVV', $ukuran, $ukuran, 0, 0, 1, 32, strlen($isi), $offset);
    $offset += strlen($isi);
}

foreach ([16, 32] as $ukuran) {
    $ico .= $png[$ukuran];
}

file_put_contents(__DIR__.'/../public/favicon.ico', $ico);
echo "favicon.ico (16 + 32)\n";
