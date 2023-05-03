<?php

include 'koneksi.php';
if (isset($_POST['simpan'])) {
  $id_karyawan = $_POST['id_karyawan'];
  $nama = $_POST['nama'];
  $waktu = $_POST['waktu'];

  // Validasi apakah karyawan sudah absen hari ini
  $query = "SELECT * FROM tb_absen WHERE id_karyawan='$id_karyawan' AND DATE(waktu) = DATE(NOW())";
  $result = mysqli_query($koneksi, $query);

  if (mysqli_num_rows($result) > 0) {
    // Jika karyawan sudah absen hari ini, tampilkan pesan error
    echo "<script>alert('Koe sudah presensi tol') </script>";
    echo "<script>window.location.href = \"index.php?m=awal\" </script>";
  } else {
    // Jika karyawan belum absen hari ini, lakukan proses penyimpanan data ke database
    $save = "INSERT INTO tb_absen SET id_karyawan='$id_karyawan', nama='$nama', waktu='$waktu'";
    mysqli_query($koneksi, $save);

    if ($save) {
      echo "<script>alert('Berhasil Presensi tol') </script>";
      echo "<script>window.location.href = \"index.php?m=awal\" </script>"; 
    } else {
      echo "Terjadi kesalahan saat menyimpan data";
    }
  }
}
?>