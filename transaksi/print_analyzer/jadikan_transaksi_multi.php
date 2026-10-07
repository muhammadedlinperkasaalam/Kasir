<?php
require_once __DIR__.'/_init.php';
PA_KasirBridge::ensureTables($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location:index.php');exit;}
pa_verify_csrf();
$token=trim((string)($_POST['batch_token']??''));
$qty=max(1,(int)($_POST['qty_cetak']??1));
$kodePelanggan=$_POST['kode_pelanggan'] ?: 'UMUM';
$metode=$_POST['metode'] ?? 'Cash';
$uangBayar=(float)($_POST['uang_bayar']??0);
$finishing=trim((string)($_POST['finishing']??''));
$catatan=trim((string)($_POST['catatan']??''));
try{
    if($token==='') throw new RuntimeException('Batch multi file tidak valid.');
    $pdo->beginTransaction();
    $st=$pdo->prepare('SELECT j.*, pp.* , j.id job_id, pp.name profile_name FROM print_jobs j JOIN price_profiles pp ON pp.id=j.price_profile_id WHERE j.batch_token=? ORDER BY j.id FOR UPDATE');
    $st->execute([$token]);
    $jobs=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$jobs) throw new RuntimeException('Batch multi file tidak ditemukan.');
    foreach($jobs as $j){
        if(!empty($j['no_penjualan'])) throw new RuntimeException('Salah satu file sudah dijadikan transaksi: '.$j['no_penjualan']);
        if($j['status']==='failed') throw new RuntimeException('Ada file yang gagal dianalisis: '.$j['original_filename']);
    }

    $pricing=new PA_PriceCalculator();
    $no=PA_KasirBridge::nextNoPenjualan($pdo);
    $kodeUser=$_SESSION['user_id'];
    $shift=$_SESSION['shift'] ?? '1';
    $stmtOp=$pdo->prepare('SELECT shift FROM operator WHERE kode_user=?');
    $stmtOp->execute([$kodeUser]);
    if($op=$stmtOp->fetch(PDO::FETCH_ASSOC)) $shift=$op['shift'] ?: $shift;

    $pageCountsStmt=$pdo->prepare('SELECT final_category, COUNT(*) total FROM print_job_pages WHERE print_job_id=? GROUP BY final_category');
    $itemAgg=[];
    $grandSummary=['total_pages'=>0,'bw_pages'=>0,'color_25_pages'=>0,'color_50_pages'=>0,'color_75_pages'=>0,'color_100_pages'=>0];
    foreach($jobs as $job){
        foreach($grandSummary as $k=>$_){ $grandSummary[$k]+=(int)($job[$k]??0); }
        $pageCountsStmt->execute([(int)$job['job_id']]);
        $counts=[];
        foreach($pageCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $r){ $counts[$r['final_category']]=(int)$r['total']; }
        foreach(['bw','color_25','color_50','color_75','color_100'] as $cat){
            $hal=(int)($counts[$cat]??0);
            if($hal<=0) continue;
            $kodeBarang=$pricing->barangKode($job,$cat);
            if($kodeBarang==='') throw new RuntimeException('Profil harga belum punya mapping kode barang untuk kategori '.(pa_setting('categories')[$cat]??$cat).' pada file '.$job['original_filename']);
            $harga=$pricing->unitPrice($job,$cat);
            $key=$kodeBarang.'|'.$harga.'|'.$cat;
            if(!isset($itemAgg[$key])){
                $itemAgg[$key]=['kode_barang'=>$kodeBarang,'harga'=>$harga,'cat'=>$cat,'halaman'=>0,'jumlah'=>0,'files'=>[]];
            }
            $itemAgg[$key]['halaman'] += $hal;
            $itemAgg[$key]['jumlah'] += $hal*$qty;
            $itemAgg[$key]['files'][] = $job['original_filename'].' ('.$hal.' hlm)';
        }
    }
    if(!$itemAgg) throw new RuntimeException('Tidak ada item halaman untuk dibuat transaksi.');

    $stmtBarang=$pdo->prepare('SELECT * FROM barang WHERE kode_barang=?');
    $stmtKomp=$pdo->prepare('SELECT * FROM pengeluaran_barang WHERE kode_barang=?');
    $updStok=$pdo->prepare('UPDATE barang SET stok = stok - ? WHERE kode_barang=?');
    $insertItem=$pdo->prepare('INSERT INTO penjualan_item (no_penjualan,tgl_penjualan,kode_user,kode_pelanggan,kode_barang,harga_beli_kotor,harga_beli_bersih,harga_jual,diskon,jumlah,stok_awal,stok_terakhir,keterangan,shift_new,pelunasan,subtotal) VALUES (?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

    $totalOmzet=0; $totalLaba=0;
    foreach($itemAgg as $it){
        $stmtBarang->execute([$it['kode_barang']]);
        $brg=$stmtBarang->fetch(PDO::FETCH_ASSOC);
        if(!$brg) throw new RuntimeException('Kode barang '.$it['kode_barang'].' tidak ditemukan.');
        $jumlah=(float)$it['jumlah'];
        $harga=(float)$it['harga'];
        $subtotal=$harga*$jumlah;
        $totalOmzet += $subtotal;
        $beliBersih=(float)($brg['harga_beli_bersih']??0);
        $beliKotor=(float)($brg['harga_beli']??0);
        $totalLaba += ($harga-$beliBersih)*$jumlah;
        $stokAwal=(float)($brg['stok']??0);
        $stokAkhir=$stokAwal;
        $stmtKomp->execute([$it['kode_barang']]);
        $komp=$stmtKomp->fetchAll(PDO::FETCH_ASSOC);
        if($komp){
            foreach($komp as $k){ $updStok->execute([(float)$k['jumlah']*$jumlah, $k['kode_keluar']]); }
        } else {
            $updStok->execute([$jumlah,$it['kode_barang']]);
            $stokAkhir=$stokAwal-$jumlah;
        }
        $fileNote=implode('; ', array_slice($it['files'],0,6));
        if(count($it['files'])>6) $fileNote.='; dan lainnya';
        $ket='Multi Analyzer - '.(pa_setting('categories')[$it['cat']]??$it['cat']).' - '.$it['halaman'].' halaman x '.$qty.' rangkap. '.$fileNote;
        $insertItem->execute([$no,$kodeUser,$kodePelanggan,$it['kode_barang'],$beliKotor,$beliBersih,$harga,0,$jumlah,$stokAwal,$stokAkhir,$ket,$shift,'N',$subtotal]);
    }

    $pelunasan = ($metode==='Utang' || $uangBayar < ($totalOmzet-100)) ? 'N' : 'Y';
    if($pelunasan==='Y') $pdo->prepare("UPDATE penjualan_item SET pelunasan='Y' WHERE no_penjualan=?")->execute([$no]);
    $cols=[]; try{$pdo->query('SELECT metode_pembayaran FROM penjualan LIMIT 1'); $cols[]='metode_pembayaran';}catch(Exception $e){}
    $sql="INSERT INTO penjualan (no_penjualan,kode_pelanggan,kode_user,tgl_penjualan,jam,keterangan,uang_bayar,shift,pelunasan,tgl_transaksi,total_omzet,total_laba".($cols?',metode_pembayaran':'').") VALUES (?,?,?,CURDATE(),CURTIME(),?,?,?,?,CURDATE(),?,?".($cols?',?':'').")";
    $params=[$no,$kodePelanggan,$kodeUser,'Dari Print Analyzer multi file: '.count($jobs).' file'.($catatan?' | '.$catatan:''),$uangBayar,$shift,$pelunasan,$totalOmzet,$totalLaba];
    if($cols)$params[]=$metode;
    $pdo->prepare($sql)->execute($params);

    if($pelunasan==='N' && $kodePelanggan!=='UMUM'){
        $kurang=max(0,$totalOmzet-$uangBayar);
        try{$pdo->prepare('UPDATE pelanggan SET sisa_utang = COALESCE(sisa_utang,0) + ? WHERE kode_pelanggan=?')->execute([$kurang,$kodePelanggan]);}catch(Exception $e){}
    }
    try{$pdo->prepare('INSERT INTO arus_kas (no_penjualan,metode_pembayaran,tanggal,jenis,keterangan,jumlah_masuk,jumlah_keluar,kode_user) VALUES (?,?,CURDATE(),?,?,?,?,?)')->execute([$no,$metode,'Penjualan Print Analyzer multi file '.$no, min($uangBayar,$totalOmzet),0,$kodeUser]);}catch(Exception $e){}

    $updJob=$pdo->prepare('UPDATE print_jobs SET no_penjualan=?, status="converted" WHERE id=?');
    $insFile=$pdo->prepare('INSERT INTO order_files (no_penjualan,nama_file,path_file,status_baca,jenis_kertas,ukuran_kertas,qty_cetak,printed_count,finishing,halaman,total_halaman,bw_pages,color_25_pages,color_50_pages,color_75_pages,color_100_pages,estimasi_harga,analyzer_job_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach($jobs as $job){
        $updJob->execute([$no,(int)$job['job_id']]);
        $pathRel='uploads/print_analyzer/uploads/'.$job['stored_filename'];
        $insFile->execute([$no,$job['original_filename'],$pathRel,'selesai',$job['paper_type'],$job['paper_size'],$qty,0,$finishing,'Semua',$job['total_pages'],$job['bw_pages'],$job['color_25_pages'],$job['color_50_pages'],$job['color_75_pages'],$job['color_100_pages'],((float)$job['estimated_total']*$qty),(int)$job['job_id']]);
    }
    $pdo->prepare('INSERT INTO order_pekerjaan (no_penjualan,status_order,catatan) VALUES (?,?,?)')->execute([$no,'Antri','Dibuat dari Print Analyzer multi file. '.$catatan]);
    foreach(['Print semua file','Finishing','Packing','Hubungi pelanggan'] as $task){
        $pdo->prepare('INSERT INTO order_tasks (no_penjualan,nama_task,status_task) VALUES (?,?,?)')->execute([$no,$task,'pending']);
    }
    $pdo->commit();
    pa_flash('success','Berhasil membuat satu transaksi gabungan '.$no.' dari '.count($jobs).' file.');
    header('Location: batch.php?token='.urlencode($token)); exit;
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    pa_flash('error',$e->getMessage());
    header('Location: batch.php?token='.urlencode($token)); exit;
}
