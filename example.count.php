<?php
require __DIR__ . '/vendor/autoload.php';

use PinyDB\PinyDBClient;

$db = new PinyDBClient('127.0.0.1', 9999);

// 1) Basic ping
//echo "PING: ";
//var_dump($db->ping());
//var_dump($db->ping());
var_dump($db->count('visits-signup.live.com'));

// 3) Count rows in "users"
//echo "COUNT visits-signup.live.com: ";
//var_dump($db->count('visits-signup.live.com'));

