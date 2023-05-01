<?php 
include 'koneksi.php';

$id = $_GET['id'];

$sql_h = "DELETE FROM tb_uanglembur WHERE id = '$id'";
$query = mysqli_query($koneksi, $sql_h);

if ($query) {
	header("location: adm-lembur.php");
}else{
	echo "gagal dihapus";
}
 ?>

