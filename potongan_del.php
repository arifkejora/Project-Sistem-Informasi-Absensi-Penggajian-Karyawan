<?php 
include 'koneksi.php';

$id = $_GET['id'];

$sql_h = "DELETE FROM tb_potongan WHERE id = '$id'";
$query = mysqli_query($koneksi, $sql_h);

if ($query) {
	header("location: adm-potongan.php");
}else{
	echo "gagal dihapus";
}
 ?>

