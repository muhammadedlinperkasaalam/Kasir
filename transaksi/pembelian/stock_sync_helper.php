<?php
/**
 * Helper sinkron stok pembelian/restock.
 * Dibuat agar stok barang memakai COALESCE(stok,0), karena stok lama banyak bernilai NULL.
 */

if (!function_exists('pembelian_ensure_stock_sync_table')) {
    function pembelian_ensure_stock_sync_table(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pembelian_stok_sync_log (
            id INT(11) NOT NULL AUTO_INCREMENT,
            no_pembelian CHAR(10) NOT NULL,
            synced_at DATETIME NOT NULL,
            synced_by CHAR(10) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_no_pembelian (no_pembelian)
        ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci");
    }
}

if (!function_exists('pembelian_to_number')) {
    function pembelian_to_number($value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        $value = trim((string)$value);
        if ($value === '') return 0.0;
        $value = str_replace(['Rp', 'rp', ' ', '.'], '', $value);
        $value = str_replace(',', '.', $value);
        return is_numeric($value) ? (float)$value : 0.0;
    }
}

if (!function_exists('pembelian_stock_is_synced')) {
    function pembelian_stock_is_synced(PDO $pdo, string $noPembelian): bool
    {
        pembelian_ensure_stock_sync_table($pdo);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pembelian_stok_sync_log WHERE no_pembelian = ?");
        $stmt->execute([$noPembelian]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('pembelian_mark_stock_synced')) {
    function pembelian_mark_stock_synced(PDO $pdo, string $noPembelian, ?string $userId = null, string $note = 'auto saat simpan pembelian'): void
    {
        pembelian_ensure_stock_sync_table($pdo);
        $stmt = $pdo->prepare("INSERT IGNORE INTO pembelian_stok_sync_log (no_pembelian, synced_at, synced_by, note) VALUES (?, NOW(), ?, ?)");
        $stmt->execute([$noPembelian, $userId, $note]);
    }
}

if (!function_exists('pembelian_apply_stock_from_items')) {
    function pembelian_apply_stock_from_items(PDO $pdo, string $noPembelian, ?string $userId = null): array
    {
        pembelian_ensure_stock_sync_table($pdo);

        if (pembelian_stock_is_synced($pdo, $noPembelian)) {
            throw new Exception("Stok untuk nota $noPembelian sudah pernah disinkronkan. Jika stok masih salah, revisi manual stok barang agar tidak dobel.");
        }

        $cekPembelian = $pdo->prepare("SELECT kode_supplier FROM pembelian WHERE no_pembelian = ?");
        $cekPembelian->execute([$noPembelian]);
        $pembelianRow = $cekPembelian->fetch(PDO::FETCH_ASSOC);
        if (!$pembelianRow) {
            throw new Exception("Nota pembelian $noPembelian tidak ditemukan.");
        }
        $kodeSupplier = $pembelianRow['kode_supplier'] ?? null;

        $stmt = $pdo->prepare("SELECT pi.kode_barang, pi.harga_beli, pi.jumlah, b.nama_barang, b.stok, b.harga_jual
                               FROM pembelian_item pi
                               LEFT JOIN barang b ON b.kode_barang = pi.kode_barang
                               WHERE pi.no_pembelian = ?");
        $stmt->execute([$noPembelian]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            throw new Exception("Nota $noPembelian belum punya item barang.");
        }

        $updated = [];
        // kode_supplier ikut diupdate supaya data barang mengikuti supplier restock terakhir.
        $sql = "UPDATE barang
                SET stok = COALESCE(stok, 0) + ?,
                    harga_beli = ?,
                    kode_supplier = ?
                WHERE kode_barang = ?";
        $upd = $pdo->prepare($sql);

        foreach ($items as $item) {
            $kode = (string)($item['kode_barang'] ?? '');
            $qty = pembelian_to_number($item['jumlah'] ?? 0);
            $harga = pembelian_to_number($item['harga_beli'] ?? 0);

            if ($kode === '' || $qty == 0) {
                continue;
            }

            $upd->execute([$qty, $harga, $kodeSupplier, $kode]);
            if ($upd->rowCount() <= 0) {
                // rowCount bisa 0 jika nilai stok tidak berubah, jadi cek barang tetap ada.
                $cekBarang = $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kode_barang = ?");
                $cekBarang->execute([$kode]);
                if ((int)$cekBarang->fetchColumn() <= 0) {
                    throw new Exception("Barang $kode tidak ditemukan, stok tidak bisa ditambah.");
                }
            }

            $hargaJual = pembelian_to_number($item['harga_jual'] ?? 0);
            $perluUpdateHarga = ($hargaJual > 0 && $harga > $hargaJual);

            $updated[] = [
                'kode_barang' => $kode,
                'nama_barang' => $item['nama_barang'] ?? $kode,
                'qty' => $qty,
                'stok_lama' => $item['stok'],
                'stok_baru' => ((float)($item['stok'] ?? 0)) + $qty,
                'harga_beli_baru' => $harga,
                'harga_jual_saat_ini' => $hargaJual,
                'perlu_update_harga' => $perluUpdateHarga,
            ];
        }

        pembelian_mark_stock_synced($pdo, $noPembelian, $userId, 'manual sinkron dari riwayat pembelian');
        return $updated;
    }
}
