<?php 

include 'koneksi.php';

if (isset($_POST['simpan'])) {
	
	$username = $_POST['username'];
	$password = $_POST['password'];
}

$save = "INSERT INTO tb_daftar SET username='$username', password='$password'";
mysqli_query($koneksi, $save);

if ($save) {
	echo "Sukses";
	header("Location: adm-petugas.php");
	exit();
 } else {
	echo "Gagal Disimpan";
	header("Location: adm-petugas.php");
	exit();
 }
 

 ?>