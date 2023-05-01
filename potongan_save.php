<?php 

include 'koneksi.php';

if (isset($_POST['simpan'])) {
	
	$id_jabatan = $_POST['id_jabatan'];
	$potongan = $_POST['potongan'];
}

$save = "INSERT INTO tb_potongan SET id_jabatan='$id_jabatan', potongan='$potongan'";
mysqli_query($koneksi, $save);

if ($save) {
	echo "Sukses";
	header("Location: adm-potongan.php");
	exit();
 } else {
	echo "Gagal Disimpan";
	header("Location: adm-potongan.php");
	exit();
 }
 

 ?>