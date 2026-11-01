<?php
if (!is_file(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}
http_response_code(200);
echo 'OK';
