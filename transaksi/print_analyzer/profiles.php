<?php
require_once __DIR__.'/_init.php';
PA_KasirBridge::ensureTables($pdo);
$title='Profil Harga Analyzer';

$barang=$pdo->query("SELECT kode_barang,nama_barang,harga_jual FROM barang WHERE aktif='Y' ORDER BY nama_barang LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
$barangByKode=[];
foreach($barang as $b){ $barangByKode[$b['kode_barang']]=$b; }

function harga_barang(array $barangByKode, string $kode): int {
    if($kode === '' || !isset($barangByKode[$kode])) return 0;
    return (int)round((float)$barangByKode[$kode]['harga_jual']);
}

function profile_payload_prices(array $barangByKode): array {
    $kodeBw = trim((string)($_POST['kode_barang_bw'] ?? ''));
    $kode25 = trim((string)($_POST['kode_barang_color_25'] ?? ''));
    $kode50 = trim((string)($_POST['kode_barang_color_50'] ?? ''));
    $kode75 = trim((string)($_POST['kode_barang_color_75'] ?? ''));
    $kode100 = trim((string)($_POST['kode_barang_color_100'] ?? ''));
    return [
        'name'=>trim((string)($_POST['name'] ?? '')),
        'paper_size'=>trim((string)($_POST['paper_size'] ?? '')),
        'paper_type'=>trim((string)($_POST['paper_type'] ?? '')),
        'print_mode'=>in_array(($_POST['print_mode'] ?? 'color'), ['color','grayscale'], true) ? $_POST['print_mode'] : 'color',
        'printer_paper_type'=>in_array(($_POST['printer_paper_type'] ?? 'plain'), ['plain','photo_glossy'], true) ? $_POST['printer_paper_type'] : 'plain',
        'price_bw'=>harga_barang($barangByKode, $kodeBw),
        'price_color_25'=>harga_barang($barangByKode, $kode25),
        'price_color_50'=>harga_barang($barangByKode, $kode50),
        'price_color_75'=>harga_barang($barangByKode, $kode75),
        'price_color_100'=>harga_barang($barangByKode, $kode100),
        'kode_barang_bw'=>$kodeBw ?: null,
        'kode_barang_color_25'=>$kode25 ?: null,
        'kode_barang_color_50'=>$kode50 ?: null,
        'kode_barang_color_75'=>$kode75 ?: null,
        'kode_barang_color_100'=>$kode100 ?: null,
        'duplex_discount_per_sheet'=>max(0, (int)($_POST['duplex_discount_per_sheet'] ?? 0)),
    ];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    pa_verify_csrf();
    $action = $_POST['action'] ?? 'create';
    try{
        if($action === 'delete'){
            $id = (int)($_POST['id'] ?? 0);
            if($id <= 0) throw new RuntimeException('Profil tidak valid.');
            $stmt=$pdo->prepare('DELETE FROM price_profiles WHERE id=?');
            $stmt->execute([$id]);
            pa_flash('success','Profil harga berhasil dihapus.');
        }elseif($action === 'update'){
            $id = (int)($_POST['id'] ?? 0);
            if($id <= 0) throw new RuntimeException('Profil tidak valid.');
            $d = profile_payload_prices($barangByKode);
            if($d['name']==='') throw new RuntimeException('Nama profil wajib diisi.');
            $stmt=$pdo->prepare("UPDATE price_profiles SET
                name=?, paper_size=?, paper_type=?, print_mode=?, printer_paper_type=?, print_side='1_sisi', duplex_discount_per_sheet=?,
                price_bw=?, price_color_25=?, price_color_50=?, price_color_75=?, price_color_100=?,
                kode_barang_bw=?, kode_barang_color_25=?, kode_barang_color_50=?, kode_barang_color_75=?, kode_barang_color_100=?
                WHERE id=?");
            $stmt->execute([
                $d['name'],$d['paper_size'],$d['paper_type'],$d['print_mode'],$d['printer_paper_type'],$d['duplex_discount_per_sheet'],
                $d['price_bw'],$d['price_color_25'],$d['price_color_50'],$d['price_color_75'],$d['price_color_100'],
                $d['kode_barang_bw'],$d['kode_barang_color_25'],$d['kode_barang_color_50'],$d['kode_barang_color_75'],$d['kode_barang_color_100'],
                $id
            ]);
            pa_flash('success','Profil harga berhasil diperbarui.');
        }else{
            $d = profile_payload_prices($barangByKode);
            if($d['name']==='') throw new RuntimeException('Nama profil wajib diisi.');
            $stmt=$pdo->prepare("INSERT INTO price_profiles
                (name,paper_size,paper_type,print_mode,printer_paper_type,print_side,duplex_discount_per_sheet,price_bw,price_color_25,price_color_50,price_color_75,price_color_100,kode_barang_bw,kode_barang_color_25,kode_barang_color_50,kode_barang_color_75,kode_barang_color_100)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $d['name'],$d['paper_size'],$d['paper_type'],$d['print_mode'],$d['printer_paper_type'],'1_sisi',$d['duplex_discount_per_sheet'],
                $d['price_bw'],$d['price_color_25'],$d['price_color_50'],$d['price_color_75'],$d['price_color_100'],
                $d['kode_barang_bw'],$d['kode_barang_color_25'],$d['kode_barang_color_50'],$d['kode_barang_color_75'],$d['kode_barang_color_100']
            ]);
            pa_flash('success','Profil harga ditambahkan. Harga otomatis diambil dari harga jual produk/jasa yang dipilih.');
        }
    }catch(Throwable $e){ pa_flash('error',$e->getMessage()); }
    header('Location: profiles.php'); exit;
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editProfile = null;
if($editId > 0){
    $stmt=$pdo->prepare('SELECT * FROM price_profiles WHERE id=?');
    $stmt->execute([$editId]);
    $editProfile=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$profiles=$pdo->query('SELECT * FROM price_profiles ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

function barang_dropdown($name,$barang,$priceTarget,$selected=''){
    $wrapId = 'ss_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . '_' . substr(md5((string)$selected.$name),0,5);
    $selectedLabel = 'Pilih barang/jasa';
    $selectedPrice = 0;
    foreach($barang as $b){
        if((string)$b['kode_barang'] === (string)$selected){
            $selectedPrice = (int)round((float)$b['harga_jual']);
            $selectedLabel = $b['kode_barang'].' - '.$b['nama_barang'].' ('.rupiah($selectedPrice).')';
            break;
        }
    }
    echo '<div class="searchable-select" id="'.e($wrapId).'" data-price-target="'.e($priceTarget).'">';
    echo '<input type="hidden" name="'.e($name).'" value="'.e((string)$selected).'">';
    echo '<button type="button" class="form-select text-start ss-toggle" aria-expanded="false">'.e($selectedLabel).'</button>';
    echo '<div class="ss-menu shadow-sm">';
    echo '<div class="p-2 border-bottom"><input type="text" class="form-control form-control-sm ss-search" placeholder="Ketik kode / nama barang jasa..." autocomplete="off"></div>';
    echo '<div class="ss-options">';
    echo '<button type="button" class="ss-option text-muted" data-value="" data-price="0" data-label="Pilih barang/jasa" data-search="">Kosongkan pilihan</button>';
    foreach($barang as $b){
        $price = (int)round((float)$b['harga_jual']);
        $label = $b['kode_barang'].' - '.$b['nama_barang'].' ('.rupiah($price).')';
        $search = strtolower($b['kode_barang'].' '.$b['nama_barang'].' '.$price);
        $active = ((string)$b['kode_barang'] === (string)$selected) ? ' active' : '';
        echo '<button type="button" class="ss-option'.$active.'" data-value="'.e($b['kode_barang']).'" data-price="'.e((string)$price).'" data-label="'.e($label).'" data-search="'.e($search).'">'.e($label).'</button>';
    }
    echo '<div class="ss-empty text-muted small px-3 py-2" style="display:none">Barang/jasa tidak ditemukan.</div>';
    echo '</div></div></div>';
    return $selectedPrice;
}
require __DIR__.'/_header.php';
$fp = $editProfile ?: ['id'=>0,'name'=>'','paper_size'=>'A4','paper_type'=>'HVS','print_mode'=>'color','printer_paper_type'=>'plain','kode_barang_bw'=>'','kode_barang_color_25'=>'','kode_barang_color_50'=>'','kode_barang_color_75'=>'','kode_barang_color_100'=>'','duplex_discount_per_sheet'=>0];
$isEdit = (bool)$editProfile;
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <b><?= $isEdit ? 'Edit Profil Harga' : 'Tambah Profil Harga' ?></b>
        <?php if($isEdit): ?><a href="profiles.php" class="btn btn-sm btn-outline-secondary">Batal edit</a><?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(pa_csrf()) ?>">
          <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
          <?php if($isEdit): ?><input type="hidden" name="id" value="<?= (int)$fp['id'] ?>"><?php endif; ?>
          <div class="mb-2">
            <label class="form-label">Nama profil</label>
            <input class="form-control" name="name" value="<?= e((string)$fp['name']) ?>" placeholder="A4 HVS" required>
          </div>
          <div class="row">
            <div class="col"><label class="form-label">Ukuran</label><input class="form-control" name="paper_size" value="<?= e((string)$fp['paper_size']) ?>"></div>
            <div class="col"><label class="form-label">Kertas</label><input class="form-control" name="paper_type" value="<?= e((string)$fp['paper_type']) ?>"></div>
          </div>
          <div class="mt-3">
            <label class="form-label">Mode Print</label>
            <select class="form-select" name="print_mode">
              <option value="color" <?= (($fp['print_mode'] ?? 'color') === 'color') ? 'selected' : '' ?>>Warna</option>
              <option value="grayscale" <?= (($fp['print_mode'] ?? 'color') === 'grayscale') ? 'selected' : '' ?>>Grayscale</option>
            </select>
            <small class="text-muted">Pilih Grayscale jika customer minta cetak murah. Harga akan dihitung memakai mapping BW dan Print Klien otomatis aktif grayscale.</small>
          </div>

          <div class="mt-3">
            <label class="form-label">Paper Type</label>
            <select class="form-select" name="printer_paper_type">
              <option value="plain" <?= (($fp['printer_paper_type'] ?? 'plain') === 'plain') ? 'selected' : '' ?>>Plain Paper</option>
              <option value="photo_glossy" <?= (($fp['printer_paper_type'] ?? 'plain') === 'photo_glossy') ? 'selected' : '' ?>>Photo Paper Glossy</option>
            </select>
            <small class="text-muted">Setelan ini ikut ke Print Klien. Plain Paper untuk HVS/kertas biasa, Photo Paper Glossy untuk media foto/glossy.</small>
          </div>
          <div class="mt-3">
            <label class="form-label">Potongan 2 sisi per sheet/lembar</label>
            <div class="input-group"><span class="input-group-text">Rp</span><input class="form-control" name="duplex_discount_per_sheet" type="number" min="0" value="<?= (int)($fp['duplex_discount_per_sheet'] ?? 0) ?>"></div>
            <small class="text-muted">Isi 0 untuk profil tanpa potongan. Isi misalnya 50 untuk profil yang ingin diberi potongan 2 sisi.</small>
          </div>

          <h6 class="mt-3">Mapping ke Master Barang/Jasa</h6>
          <small class="text-muted d-block mb-2">Pilih produk/jasa. Harga per sheet/halaman otomatis memakai <b>harga_jual</b> dari produk yang dipilih.</small>

          <label class="form-label">BW</label>
          <?php $pBw=barang_dropdown('kode_barang_bw',$barang,'price_bw',$fp['kode_barang_bw'] ?? ''); ?>
          <div class="input-group input-group-sm mt-1 mb-2"><span class="input-group-text">Harga</span><input class="form-control auto-price" id="price_bw" name="price_bw" type="number" value="<?= (int)$pBw ?>" readonly></div>

          <label class="form-label mt-2">Warna 25%</label>
          <?php $p25=barang_dropdown('kode_barang_color_25',$barang,'price_color_25',$fp['kode_barang_color_25'] ?? ''); ?>
          <div class="input-group input-group-sm mt-1 mb-2"><span class="input-group-text">Harga</span><input class="form-control auto-price" id="price_color_25" name="price_color_25" type="number" value="<?= (int)$p25 ?>" readonly></div>

          <label class="form-label mt-2">Warna 50%</label>
          <?php $p50=barang_dropdown('kode_barang_color_50',$barang,'price_color_50',$fp['kode_barang_color_50'] ?? ''); ?>
          <div class="input-group input-group-sm mt-1 mb-2"><span class="input-group-text">Harga</span><input class="form-control auto-price" id="price_color_50" name="price_color_50" type="number" value="<?= (int)$p50 ?>" readonly></div>

          <label class="form-label mt-2">Warna 75%</label>
          <?php $p75=barang_dropdown('kode_barang_color_75',$barang,'price_color_75',$fp['kode_barang_color_75'] ?? ''); ?>
          <div class="input-group input-group-sm mt-1 mb-2"><span class="input-group-text">Harga</span><input class="form-control auto-price" id="price_color_75" name="price_color_75" type="number" value="<?= (int)$p75 ?>" readonly></div>

          <label class="form-label mt-2">Warna 100%</label>
          <?php $p100=barang_dropdown('kode_barang_color_100',$barang,'price_color_100',$fp['kode_barang_color_100'] ?? ''); ?>
          <div class="input-group input-group-sm mt-1 mb-2"><span class="input-group-text">Harga</span><input class="form-control auto-price" id="price_color_100" name="price_color_100" type="number" value="<?= (int)$p100 ?>" readonly></div>

          <button class="btn btn-primary mt-3 w-100"><?= $isEdit ? 'Update Profil' : 'Simpan Profil' ?></button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><b>Daftar Profil</b></div>
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead><tr><th>Profil</th><th>Harga otomatis</th><th>Mapping</th><th class="text-end">Aksi</th></tr></thead>
          <tbody>
            <?php if(!$profiles): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">Belum ada profil harga.</td></tr>
            <?php endif; ?>
            <?php foreach($profiles as $pr): ?>
            <tr>
              <td><b><?= e($pr['name']) ?></b><br><small class="text-muted"><?= e($pr['paper_size'].' / '.$pr['paper_type']) ?><br>Mode: <?= (($pr['print_mode'] ?? 'color') === 'grayscale') ? 'Grayscale' : 'Warna' ?><br>Paper Type: <?= (($pr['printer_paper_type'] ?? 'plain') === 'photo_glossy') ? 'Photo Paper Glossy' : 'Plain Paper' ?><br>Potongan 2 sisi: <?= rupiah($pr['duplex_discount_per_sheet'] ?? 0) ?>/sheet</small></td>
              <td><small>BW <?= rupiah($pr['price_bw']) ?><br>25 <?= rupiah($pr['price_color_25']) ?> · 50 <?= rupiah($pr['price_color_50']) ?><br>75 <?= rupiah($pr['price_color_75']) ?> · 100 <?= rupiah($pr['price_color_100']) ?></small></td>
              <td><small>BW: <?= e($pr['kode_barang_bw'] ?: '-') ?><br>25: <?= e($pr['kode_barang_color_25'] ?: '-') ?><br>50: <?= e($pr['kode_barang_color_50'] ?: '-') ?><br>75: <?= e($pr['kode_barang_color_75'] ?: '-') ?><br>100: <?= e($pr['kode_barang_color_100'] ?: '-') ?></small></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="profiles.php?edit=<?= (int)$pr['id'] ?>">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Hapus profil harga ini?');">
                  <input type="hidden" name="_csrf" value="<?= e(pa_csrf()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Hapus</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<style>
.searchable-select{position:relative}.searchable-select .ss-menu{display:none;position:absolute;left:0;right:0;top:100%;z-index:1050;background:#fff;border:1px solid #ced4da;border-radius:.5rem;margin-top:.25rem;overflow:hidden}.searchable-select.open .ss-menu{display:block}.searchable-select .ss-options{max-height:260px;overflow:auto}.searchable-select .ss-option{display:block;width:100%;border:0;background:#fff;text-align:left;padding:.45rem .75rem;font-size:.875rem}.searchable-select .ss-option:hover,.searchable-select .ss-option.active{background:#f1f5f9}.searchable-select .ss-toggle{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-right:2rem}.auto-price{background:#f8fafc;font-weight:600}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  function closeAll(except) {
    document.querySelectorAll('.searchable-select.open').forEach(function (box) {
      if (box !== except) box.classList.remove('open');
    });
  }

  document.querySelectorAll('.searchable-select').forEach(function (box) {
    var toggle = box.querySelector('.ss-toggle');
    var hidden = box.querySelector('input[type="hidden"]');
    var search = box.querySelector('.ss-search');
    var options = Array.prototype.slice.call(box.querySelectorAll('.ss-option'));
    var empty = box.querySelector('.ss-empty');
    var priceInput = document.getElementById(box.dataset.priceTarget || '');

    toggle.addEventListener('click', function () {
      var willOpen = !box.classList.contains('open');
      closeAll(box);
      box.classList.toggle('open', willOpen);
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) {
        search.value = '';
        filterOptions('');
        setTimeout(function () { search.focus(); }, 10);
      }
    });

    search.addEventListener('input', function () { filterOptions(this.value); });

    search.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { box.classList.remove('open'); toggle.focus(); }
      if (e.key === 'Enter') {
        e.preventDefault();
        var first = options.find(function (opt) { return opt.style.display !== 'none'; });
        if (first) first.click();
      }
    });

    options.forEach(function (opt) {
      opt.addEventListener('click', function () {
        hidden.value = opt.dataset.value || '';
        toggle.textContent = opt.dataset.label || 'Pilih barang/jasa';
        if (priceInput) priceInput.value = opt.dataset.price || 0;
        box.classList.remove('open');
        toggle.focus();
      });
    });

    function filterOptions(keyword) {
      var q = (keyword || '').toLowerCase().trim();
      var shown = 0;
      options.forEach(function (opt) {
        var haystack = (opt.dataset.search || opt.textContent || '').toLowerCase();
        var match = !q || haystack.indexOf(q) !== -1;
        opt.style.display = match ? 'block' : 'none';
        if (match) shown++;
      });
      if (empty) empty.style.display = shown ? 'none' : 'block';
    }
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.searchable-select')) closeAll();
  });
});
</script>
<?php require __DIR__.'/_footer.php'; ?>
