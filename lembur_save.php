<?php 

include 'koneksi.php';

if (isset($_POST['simpan'])) {
	
	$id_jabatan = $_POST['id_jabatan'];
	$uanglembur = $_POST['uanglembur'];
}

$save = "INSERT INTO tb_uanglembur SET id_jabatan='$id_jabatan', uanglembur='$uanglembur'";
mysqli_query($koneksi, $save);

if ($save) {
	echo "Sukses";
	header("Location: adm-lembur.php");
	exit();
 } else {
	echo "Gagal Disimpan";
	header("Location: adm-lembur.php");
	exit();
 }
 

 ?>