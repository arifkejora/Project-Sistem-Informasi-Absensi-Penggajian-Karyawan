<?php
// koneksi ke database
$conn = mysqli_connect("localhost", "root", "", "karyawansi");

// cek koneksi
if (mysqli_connect_errno()) {
  echo "Failed to connect to MySQL: " . mysqli_connect_error();
  exit();
}

// cek apakah form sudah di-submit atau belum
if (isset($_POST['submit'])) {
  // ambil data dari form
  $bulan = $_POST['bulan'];
  $tahun = $_POST['tahun'];
  $id_karyawan = $_POST['id_karyawan'];

  // query untuk menghitung total lembur dan gaji sementara
  $query = "SELECT tb_karyawan.id_karyawan, tb_karyawan.nama, tb_jabatan.jabatan, COUNT(tb_lembur.waktu) AS total_lembur, 
            (tb_uanglembur.uang_lembur * COUNT(tb_lembur.waktu)) + 
            (tb_jabatan.gaji/22 * (SELECT COUNT(waktu) FROM tb_absen WHERE id_karyawan = '$id_karyawan' AND MONTH(waktu) = '$bulan' AND YEAR(waktu) = '$tahun')) AS gaji_sementara
            FROM tb_lembur 
            JOIN tb_karyawan ON tb_lembur.id_karyawan = tb_karyawan.id_karyawan 
            JOIN tb_jabatan ON tb_karyawan.id_jabatan = tb_jabatan.id 
            JOIN tb_uanglembur ON tb_karyawan.id_jabatan = tb_uanglembur.id_jabatan 
            WHERE tb_karyawan.id_karyawan = '$id_karyawan'";

  // eksekusi query
  $result = mysqli_query($conn, $query);

  // cek apakah query berhasil dieksekusi
  if (!$result) {
    echo "Error: " . mysqli_error($conn);
    exit();
  }

  // ambil data dari hasil query
  $data = mysqli_fetch_assoc($result);

  // tampilkan hasil perhitungan
  echo "ID Karyawan: " . $data['id_karyawan'] . "<br>";
  echo "Nama: " . $data['nama'] . "<br>";
  echo "Jabatan: " . $data['jabatan'] . "<br>";
  echo "Total lembur: " . $data['total_lembur'] . "<br>";
  echo "Gaji Total: " . $data['gaji_sementara'] . "<br>";
}

// tutup koneksi
mysqli_close($conn);
?>

<!-- form untuk input filter bulan dan id_karyawan -->
<form method="post">
  <label>Bulan:</label>
  <select name="bulan">
    <option value="1">Januari</option>
    <option value="2">Februari</option>
    <option value="3">Maret</option>
    <option value="4">April</option>
    <option value="5">Mei</option>
    <option value="6">Juni</option>
    <option value="7">Juli</option>
    <option value="8">Agustus</option>
    <option value="9">September</option>
    <option value="10">Oktober</option>
    <option value="11">November</option>
    <option value="12">Desember</option>
  </select>
  <label>Tahun:</label>
  <input type="text" name="tahun">
  <label>ID Karyawan:</label>
  <select name="id_karyawan">
  <?php 
        include 'koneksi.php';
        $sql = "SELECT * FROM tb_karyawan";
        $hasil = mysqli_query($koneksi, $sql);
        while ($data = mysqli_fetch_array($hasil)) {
        ?>
        <option value="<?php echo $data['id_karyawan'];?>"><?php echo $data['username']; ?></option>
        <?php } ?>
  </select>
  <button type="submit" name="submit">Submit</button>
</form>

