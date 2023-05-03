<?php 

include 'koneksi.php';

if (isset($_POST['simpan'])) {
	
	$id_karyawan = $_POST['id_karyawan'];
	$password = $_POST['password'];

	$update = "UPDATE tb_karyawan SET password='$password' WHERE id_karyawan='$id_karyawan'";
	mysqli_query($koneksi, $update);

	if ($update) {
		echo "Sukses";
		header("Location: index.php");
		exit();
	} else {
		echo "Gagal Disimpan";
		header("Location: index.php");
		exit();
	}
}

?>
