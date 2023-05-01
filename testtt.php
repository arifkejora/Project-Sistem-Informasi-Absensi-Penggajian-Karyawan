<?php
    // Koneksi ke database
    $koneksi = mysqli_connect("localhost", "root", "", "karyawansi");
    
    // Jika koneksi gagal
    if (mysqli_connect_errno()) {
        echo "Failed to connect to MySQL: " . mysqli_connect_error();
        exit();
    }

    // Query untuk mengambil data pada tabel tb_absen
    $sql = "SELECT id, id_karyawan, nama, waktu FROM tb_absen";
    $query = mysqli_query($koneksi, $sql);

    // Buat array untuk menyimpan data bulan
    $bulan = array(
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember'
    );

    // Buat form dropdown untuk memilih bulan
    echo "<form method='post'>";
    echo "<select name='bulan'>";
    echo "<option value='' selected>Pilih bulan</option>";
    foreach ($bulan as $key => $value) {
        echo "<option value='".$key."'>".$value."</option>";
    }
    echo "</select>";
    echo "<input type='submit' value='Tampilkan'>";
    echo "</form>";

    // Jika parameter bulan sudah dikirim dari form
    if (isset($_POST['bulan'])) {
        // Ambil nilai bulan dari form
        $bulan = $_POST['bulan'];

        // Query untuk mengambil data dan total absen berdasarkan filter bulan
        $sql = "SELECT id, id_karyawan, nama, waktu FROM tb_absen WHERE MONTH(waktu) = '$bulan'";
        $query = mysqli_query($koneksi, $sql);

        // Hitung total absen
        $total_absen = mysqli_num_rows($query);

        // Tampilkan data pada tabel
        if ($total_absen > 0) {
            echo "<table>";
            echo "<tr><th>ID</th><th>ID Karyawan</th><th>Nama</th><th>Waktu</th></tr>";
            while ($row = mysqli_fetch_array($query)) {
                echo "<tr>";
                echo "<td>".$row['id']."</td>";
                echo "<td>".$row['id_karyawan']."</td>";
                echo "<td>".$row['nama']."</td>";
                echo "<td>".$row['waktu']."</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "<p>Total absen pada bulan ".$bulan.": ".$total_absen."</p>";
        } else {
            echo "<p>Tidak ada data absen pada bulan ".$bulan."</p>";
        }
    }

    // Tutup koneksi
    mysqli_close($koneksi);
?>
