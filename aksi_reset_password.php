<?php
include 'koneksi.php';
// koneksi ke database
$koneksi = mysqli_connect("localhost", "root", "", "karyawansi");

// cek koneksi
if (!$koneksi) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

// cek apakah parameter id_karyawan telah terdefinisi dan tidak kosong
if (isset($_GET['id_karyawan']) && !empty(trim($_GET['id_karyawan']))) {
    // ambil id_karyawan dari parameter
    $id_karyawan = trim($_GET['id_karyawan']);

    // query untuk mengambil data karyawan berdasarkan id_karyawan
    $query = "SELECT * FROM tb_karyawan WHERE id_karyawan = '$id_karyawan'";
    $result = mysqli_query($koneksi, $query);

    // cek apakah query berhasil dieksekusi
    if ($result) {
        // cek apakah data karyawan ditemukan
        if (mysqli_num_rows($result) == 1) {
            // ambil data karyawan dari hasil query
            $row = mysqli_fetch_assoc($result);

            // generate password baru secara acak
            $new_password = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);

            // hash password baru
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // query untuk mengupdate password karyawan
            $update_query = "UPDATE tb_karyawan SET password = '$hashed_password' WHERE id_karyawan = '$id_karyawan'";
            $update_result = mysqli_query($koneksi, $update_query);

            // cek apakah query berhasil dieksekusi
            if ($update_result) {
                // tampilkan pesan sukses beserta password baru
                echo "Password karyawan dengan id $id_karyawan berhasil direset. Password baru: $new_password";
            } else {
                // tampilkan pesan error
                echo "Error: " . mysqli_error($koneksi);
            }
        } else {
            // tampilkan pesan error jika data karyawan tidak ditemukan
            echo "Data karyawan dengan id $id_karyawan tidak ditemukan.";
        }
    } else {
        // tampilkan pesan error jika query gagal dieksekusi
        echo "Error: " . mysqli_error($koneksi);
    }
} else {
    // tampilkan pesan error jika parameter id_karyawan kosong atau tidak terdefinisi
    echo "Parameter id_karyawan tidak valid.";
}

// tutup koneksi ke database
mysqli_close($conn);
?>
