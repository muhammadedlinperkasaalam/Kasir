<?php
return [
    // Aktifkan direct print server-side. File akan diprint dari komputer/server XAMPP.
    'enabled' => true,

    // Kosongkan printer_name untuk memakai default printer Windows.
    // Isi nama printer persis seperti di Windows jika ingin printer tertentu.
    // Contoh: 'EPSON L3210 Series'
    'printer_name' => '',

    // Lokasi SumatraPDF. Install SumatraPDF agar bisa silent print PDF.
    'sumatra_pdf' => 'C:\\Program Files\\SumatraPDF\\SumatraPDF.exe',
    'sumatra_pdf_alt' => 'C:\\Program Files (x86)\\SumatraPDF\\SumatraPDF.exe',

    // Setting default print.
    'scale' => 'fit', // fit, shrink, noscale
    'orientation' => '', // kosong / portrait / landscape
    'force_color' => '', // kosong / color / monochrome

    // Kalau true, setelah perintah print berhasil dikirim ke Windows, status file order dinaikkan.
    'mark_printed_after_success' => true,
];
