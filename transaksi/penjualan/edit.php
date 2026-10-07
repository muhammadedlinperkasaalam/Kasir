<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// Validasi ID
if(!isset($_GET['id'])) { header("Location: riwayat.php"); exit; }
$id_transaksi = $_GET['id'];

// Referensi Dropdown
$kategori = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Edit Transaksi: <?= $id_transaksi ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <style>
        body { background-color: #fff3cd; height: 100vh; overflow: hidden; } 
        .catalog-section { height: 100vh; overflow-y: auto; padding: 15px; }
        .product-card { cursor: pointer; transition: transform 0.2s; border: 1px solid #e0e0e0; background: white; }
        .product-card:hover { transform: translateY(-3px); border-color: #ffc107; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .product-img-placeholder { height: 100px; background: #e9ecef; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: #adb5bd; }
        .cart-section { height: 100vh; display: flex; flex-direction: column; background: white; border-left: 1px solid #ddd; }
        .cart-items { flex-grow: 1; overflow-y: auto; }
        .cart-header { padding: 15px; background: #ffc107; color: black; } 
        .cart-footer { padding: 15px; background: #f8f9fa; border-top: 2px solid #ddd; }
        .total-text { font-size: 2rem; font-weight: bold; color: #212529; text-align: right; }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0">
        
        <div class="col-md-8 catalog-section">
            <div class="card shadow-sm mb-3 sticky-top" style="z-index: 10;">
                <div class="card-body p-2">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-1">
                            <a href="riwayat.php" class="btn btn-secondary w-100" title="Kembali"><i class="fas fa-arrow-left"></i></a>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                <input type="text" id="keyword" class="form-control" placeholder="Cari barang..." autocomplete="off">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select id="filterKategori" class="form-select fw-bold">
                                <option value="">- Semua Kategori -</option>
                                <?php foreach($kategori as $k): ?>
                                    <option value="<?= $k['kode_kategori'] ?>"><?= $k['nama_kategori'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 text-end">
                            <span class="badge bg-warning text-dark border border-dark">EDITING: <?= $id_transaksi ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div id="productContainer" class="row g-3"></div>
        </div>

        <div class="col-md-4 cart-section">
            <div class="cart-header">
                <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Transaksi</h5>
                
                <div class="bg-white p-1 rounded mt-2">
                    <select id="pelanggan" class="form-select select2-pelanggan"></select>
                </div>
            </div>

            <div class="cart-items" id="cartContainer"></div>

            <div class="cart-footer">
                <div class="mb-3 text-end">
                    <small class="text-muted">Total Belanja</small>
                    <div class="total-text" id="displayTotal">Rp 0</div>
                </div>

                <div class="mb-2">
                    <select id="metodePembayaran" class="form-select fw-bold text-center bg-light border-warning">
                        <option value="Cash">CASH (Tunai)</option>
                        <option value="QRIS">QRIS</option>
                        <option value="Debit">DEBIT CARD</option>
                        <option value="Transfer">TRANSFER BANK</option>
                        <option value="Utang" class="text-danger fw-bold">UTANG (Tempo)</option>
                    </select>
                </div>

                <div class="row g-2">
                    <div class="col-5">
                        <input type="number" id="inputBayar" class="form-control form-control-lg" placeholder="Bayar">
                    </div>
                    <div class="col-7">
                        <button class="btn btn-warning w-100 btn-lg fw-bold" onclick="simpanPerubahan()">
                            <i class="fas fa-save me-2"></i> UPDATE (SIMPAN)
                        </button>
                    </div>
                </div>
                <div class="mt-2 text-end">
                    <small class="fw-bold text-primary">Kembali: <span id="displayKembali">Rp 0</span></small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    const TRX_ID = '<?= $id_transaksi ?>';
    let keranjang = [];
    let products = [];
    const formatRupiah = (num) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(num);

    // --- 1. INISIALISASI HALAMAN ---
    $(document).ready(function() {
        $('#pelanggan').select2({
            theme: 'bootstrap-5',
            ajax: { url: 'get_pelanggan_json.php', dataType: 'json', delay: 250, processResults: (data) => ({ results: data.results }) },
            placeholder: 'Cari Nama Pelanggan...',
            templateResult: formatPelanggan
        });

        loadProducts();

        // LOAD DATA LAMA VIA AJAX
        fetch(`get_transaksi_json.php?id=${TRX_ID}`)
            .then(res => res.json())
            .then(data => {
                if(!data) { 
                    Swal.fire('Error', 'Data transaksi tidak ditemukan', 'error').then(()=>window.location='riwayat.php');
                    return; 
                }

                // A. ISI PELANGGAN
                let plgText = (data.header.kode_pelanggan === 'UMUM') ? "Pelanggan Umum (Cash)" : "Pelanggan: " + data.header.kode_pelanggan;
                let option = new Option(plgText, data.header.kode_pelanggan, true, true);
                $('#pelanggan').append(option).trigger('change');

                // B. ISI KERANJANG
                keranjang = data.items;
                renderCart();

                // C. ISI PEMBAYARAN (PENTING!)
                // Set nilai dropdown
                $('#metodePembayaran').val(data.header.metode_pembayaran);
                // Set nilai input bayar
                $('#inputBayar').val(parseInt(data.header.tunai));
                
                // Trigger perhitungan kembalian agar langsung muncul
                hitungKembali();
            });
    });

    function formatPelanggan(state) {
        if (!state.id) return state.text;
        return $(`<div class="d-flex justify-content-between"><span class="fw-bold">${state.text}</span><span class="text-muted small">${state.telp||''}</span></div>`);
    }

    // --- 2. LOGIKA KERANJANG & KATALOG ---
    function loadProducts() {
        const k = document.getElementById('keyword').value;
        const c = document.getElementById('filterKategori').value;
        fetch(`get_barang.php?keyword=${k}&kategori=${c}`).then(r=>r.json()).then(d=>{ products=d; renderProducts(); });
    }
    document.getElementById('keyword').addEventListener('keyup', loadProducts);
    document.getElementById('filterKategori').addEventListener('change', loadProducts);

    function renderProducts() {
        const c = document.getElementById('productContainer'); c.innerHTML='';
        products.forEach(p => {
            c.innerHTML += `
            <div class="col-6 col-md-4 col-lg-3">
                <div class="card product-card h-100" onclick="addToCart('${p.kode_barang}')">
                    <div class="product-img-placeholder"><i class="fas fa-box"></i></div>
                    <div class="card-body p-2 text-center">
                        <h6 class="card-title text-truncate mb-1">${p.nama_barang}</h6>
                        <div class="fw-bold text-primary">${formatRupiah(p.harga_jual)}</div>
                    </div>
                </div>
            </div>`;
        });
    }

    function addToCart(kode) {
        let barang = products.find(p => p.kode_barang === kode);
        if(!barang) return;
        let item = keranjang.find(i => i.kode === kode);
        if(item) { item.qty++; } 
        else { keranjang.push({kode: barang.kode_barang, nama: barang.nama_barang, harga: parseFloat(barang.harga_jual), qty: 1}); }
        renderCart();
    }

    function renderCart() {
        let html = '<ul class="list-group list-group-flush">';
        let grandTotal = 0;
        keranjang.forEach((item, i) => {
            let sub = item.harga * item.qty; grandTotal += sub;
            html += `
            <li class="list-group-item px-2">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="me-2 text-truncate" style="max-width: 140px;">
                        <div class="fw-bold small">${item.nama}</div>
                        <small class="text-muted" style="font-size:0.7em">${formatRupiah(item.harga)} x ${item.qty}</small>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold small mb-1">${formatRupiah(sub)}</div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary py-0" onclick="updQty(${i}, -1)">-</button>
                            <button class="btn btn-outline-secondary py-0" onclick="updQty(${i}, 1)">+</button>
                            <button class="btn btn-danger py-0" onclick="delItem(${i})">x</button>
                        </div>
                    </div>
                </div>
            </li>`;
        });
        document.getElementById('cartContainer').innerHTML = html + '</ul>';
        document.getElementById('displayTotal').innerText = formatRupiah(grandTotal);
        document.getElementById('displayTotal').dataset.value = grandTotal;
        
        // Update kembalian setiap kali keranjang berubah
        hitungKembali();
    }

    function updQty(i, v) { if(keranjang[i].qty+v<=0) delItem(i); else { keranjang[i].qty+=v; renderCart(); } }
    function delItem(i) { keranjang.splice(i, 1); renderCart(); }

    // --- 3. LOGIKA PEMBAYARAN ---
    document.getElementById('inputBayar').addEventListener('keyup', hitungKembali);
    document.getElementById('metodePembayaran').addEventListener('change', function() {
        // Logika kunci input jika bukan Cash/Utang
        let total = parseFloat(document.getElementById('displayTotal').dataset.value||0);
        let input = document.getElementById('inputBayar');
        if (this.value === 'Cash' || this.value === 'Utang') {
            if(this.value === 'Utang') input.value = 0;
            input.readOnly = false;
        } else {
            input.value = total;
            input.readOnly = true;
        }
        hitungKembali();
    });

    function hitungKembali() {
        let t = parseFloat(document.getElementById('displayTotal').dataset.value||0);
        let b = parseFloat(document.getElementById('inputBayar').value||0);
        let kembali = b - t;
        let display = kembali >= 0 ? formatRupiah(kembali) : '-' + formatRupiah(Math.abs(kembali));
        
        // Ubah warna text
        let el = document.getElementById('displayKembali');
        el.innerText = display;
        if(b < t) { el.classList.remove('text-primary'); el.classList.add('text-danger'); }
        else { el.classList.remove('text-danger'); el.classList.add('text-primary'); }
    }

    // --- 4. SIMPAN PERUBAHAN (SWEETALERT2) ---
    function simpanPerubahan() {
        let total = parseFloat(document.getElementById('displayTotal').dataset.value||0);
        let bayar = parseFloat(document.getElementById('inputBayar').value||0);
        let metode = document.getElementById('metodePembayaran').value;
        let pelanggan = $('#pelanggan').val();
        
        // Validasi
        if(keranjang.length===0) return Swal.fire('Gagal', 'Keranjang Kosong', 'error');
        if(metode !== 'Utang' && bayar < total) return Swal.fire('Gagal', 'Pembayaran Kurang!', 'error');
        if(metode === 'Utang' && pelanggan === 'UMUM') return Swal.fire('Gagal', 'Pelanggan UMUM tidak boleh hutang!', 'error');

        // Konfirmasi
        Swal.fire({
            title: 'Update Transaksi?',
            html: `Total Baru: <b>${formatRupiah(total)}</b><br>Metode: <b>${metode}</b>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ffc107', // Warna kuning warning
            confirmButtonText: 'Ya, Simpan Update!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Tampilkan Loading
                Swal.fire({ title: 'Menyimpan...', didOpen: () => Swal.showLoading() });

                let data = {
                    no_penjualan: TRX_ID,
                    kode_pelanggan: pelanggan,
                    uang_bayar: bayar,
                    metode: metode,
                    items: keranjang.map(i => ({kode_barang: i.kode, harga: i.harga, jumlah: i.qty}))
                };

                fetch('proses_edit.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'data=' + JSON.stringify(data)
                })
                .then(r => r.json())
                .then(res => {
                    if(res.status === 'success') {
                        // Sukses
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil!',
                            text: 'Data transaksi berhasil diperbarui.',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location = 'riwayat.php';
                        });
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                });
            }
        });
    }
</script>
</body>
</html>