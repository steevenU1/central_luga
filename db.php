<?php
$host = "localhost";
$user = "root"; // Usuario por defecto en Laragon
$pass = ""; // Contraseña vacía por defecto
$dbname = "luga_php";

$conn = new mysqli($host, $user, $pass, $dbname);

// Verificamos la conexión
if ($conn->connect_error) {
    die("❌ Error de conexión: " . $conn->connect_error);
}

// Opcional: establecer codificación UTF-8
$conn->set_charset("utf8");
?>