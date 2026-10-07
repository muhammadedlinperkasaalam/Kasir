<?php
// ================================================================
// SUMBER TUNGGAL RUMUS "SISA UTANG PELANGGAN"
// ================================================================
// File ini dipakai bersama oleh 3 halaman aktif: daftar_piutang.php,
// rincian_piutang.php, dan bayar_cicilan.php -- supaya angka sisa utang
// yang ditampilkan SELALU konsisten di ketiganya, tidak ada lagi rumus
// yang beda-beda di tempat berbeda.
//
// Sumber data utang = nota penjualan yang belum lunas (pelunasan='N').
// Kolom pelanggan.sisa_utang cuma CACHE untuk tampilan cepat, bukan sumber utama.

// Nota dengan sisa di bawah ini dianggap "noise" (sisa recehan akibat pembulatan),
// tidak dihitung sebagai utang. Diseragamkan di satu tempat ini saja.
const PIUTANG_AMBANG_MINIMAL = 100;

// Potongan ekspresi SQL untuk menghitung sisa tagihan 1 nota (belum dikurangi ambang batas).
// Dipakai berulang di query manapun yang perlu angka ini, supaya rumusnya sama persis
// di semua tempat (tidak ditulis ulang manual per file).
function piutangSisaNotaExpr(string $aliasPenjualan = 'p'): string {
    return "(
        (CASE
            WHEN {$aliasPenjualan}.total_omzet > 0 THEN {$aliasPenjualan}.total_omzet
            ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = {$aliasPenjualan}.no_penjualan)
        END)
        - COALESCE({$aliasPenjualan}.uang_bayar, 0)
        - COALESCE({$aliasPenjualan}.nominal_deposit, 0)
    )";
}

// Hitung total sisa utang AKTIF 1 pelanggan (dari semua nota belum lunas miliknya),
// sudah menerapkan ambang batas minimal supaya sisa recehan tidak ikut terhitung.
function hitungSisaUtangPelanggan(PDO $pdo, string $kode_pelanggan): float {
    $expr = piutangSisaNotaExpr('p');
    $sql = "SELECT COALESCE(SUM(sisa), 0) FROM (
                SELECT p.no_penjualan, $expr AS sisa
                FROM penjualan p
                WHERE p.kode_pelanggan = ? AND p.pelunasan = 'N'
            ) x
            WHERE x.sisa >= " . PIUTANG_AMBANG_MINIMAL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$kode_pelanggan]);
    return max(0, (float)$stmt->fetchColumn());
}

// Sinkronkan cache pelanggan.sisa_utang (kolom lama) supaya tetap masuk akal
// buat laporan/halaman lain yang masih membaca kolom itu langsung.
function syncSisaUtangCustomer(PDO $pdo, string $kode_pelanggan): float {
    $sisa = hitungSisaUtangPelanggan($pdo, $kode_pelanggan);
    try {
        $cek = $pdo->query("SHOW COLUMNS FROM pelanggan LIKE 'sisa_utang'");
        if ($cek && $cek->fetch()) {
            $stmt = $pdo->prepare("UPDATE pelanggan SET sisa_utang = ? WHERE kode_pelanggan = ?");
            $stmt->execute([$sisa, $kode_pelanggan]);
        }
    } catch (Exception $e) {
        // Jangan ganggu transaksi utama kalau kolom lama tidak tersedia/bermasalah.
    }
    return $sisa;
}
