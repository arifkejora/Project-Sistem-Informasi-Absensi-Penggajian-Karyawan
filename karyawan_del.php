<?php 
include 'koneksi.php';

$id_karyawan = $_GET['id_karyawan'];

$sql_h = "DELETE FROM tb_karyawan WHERE id_karyawan = '$id_karyawan'";
$query = mysqli_query($koneksi, $sql_h);

if ($query) {
	header("location: adm-karyawan.php");
}else{
	echo "gagal dihapus";
}
 ?>

