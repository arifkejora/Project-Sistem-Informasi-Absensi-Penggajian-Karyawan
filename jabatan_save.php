<?php 

include 'koneksi.php';

if (isset($_POST['simpan'])) {
	
	$jabatan = $_POST['jabatan'];
	$gaji = $_POST['gaji'];
}

$save = "INSERT INTO tb_jabatan SET jabatan='$jabatan', gaji='$gaji'";
mysqli_query($koneksi, $save);

if ($save) {
	echo "Sukses";
	header("Location: adm-jabatan.php");
	exit();
 } else {
	echo "Gagal Disimpan";
	header("Location: adm-jabatan.php");
	exit();
 }
 

 ?>