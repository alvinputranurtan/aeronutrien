<?php

define('DB_HOST', 'inosakti.com');
define('DB_NAME', 'inosakti_iot_core');
define('DB_USER', 'inosakti_iot_core_user_2');
define('DB_PASS', 'inosakti_iot_core_pass_2');

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            exit('Connection failed: '.$e->getMessage());
        }
    }

    return $pdo;
}
