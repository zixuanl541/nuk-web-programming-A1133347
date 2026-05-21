<?php
include("config.php");

$link = mysqli_connect($db_host, $db_user, $db_pass);

if (!$link) {
    die("MySQL 連線失敗：" . mysqli_connect_error());
}

mysqli_query($link, "CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
mysqli_select_db($link, $db_name);
mysqli_query($link, "SET NAMES utf8mb4");

$sql = "CREATE TABLE IF NOT EXISTS emails (
    No INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (No),
    UNIQUE KEY email (email)
)";
mysqli_query($link, $sql);
