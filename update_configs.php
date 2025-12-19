<?php

require 'db.php';
$pdo = db();
$pdo->exec("UPDATE configurations SET data_configuration = JSON_SET(data_configuration, '$.theme', 'light', '$.device_type', 'nutrition') WHERE JSON_EXTRACT(data_configuration, '$.theme') IS NULL");
echo 'Updated existing configs.';
