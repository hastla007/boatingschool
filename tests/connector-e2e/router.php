<?php

/*
| Router für den PHP-Entwicklungsserver (php -S … -t <wordpress> router.php):
| vorhandene Dateien und Verzeichnisse liefert der Server selbst, alles
| andere geht an WordPress (hübsche Permalinks wie /my-account/bootsschule/).
*/

$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path !== '/' && file_exists($_SERVER['DOCUMENT_ROOT'].$path)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'].'/index.php';
