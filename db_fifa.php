<?php
// db_fifa.php — conexión a BD central del Mundial Zentral

$host_fifa = "localhost";
$user_fifa = "u790246665_mundialzentral";
$pass_fifa = "Gmunozm2024*";
$db_fifa   = "fifa_zentral";

$connFifa = new mysqli($host_fifa, $user_fifa, $pass_fifa, $db_fifa);

if ($connFifa->connect_error) {
    die("Error de conexión FIFA Zentral: " . $connFifa->connect_error);
}

$connFifa->set_charset("utf8mb4");