<?php
require __DIR__ . '/vendor/autoload.php';

use PinyDB\PinyDBClient;

$db = new PinyDBClient('127.0.0.1', 9999);

// 1) Basic ping
echo "PING: ";
var_dump($db->ping());

// 2) Insert a row into table "users"
$id = $db->insert('users', [
    'email' => 'test@example.com',
    'name'  => 'Test User',
]);
echo "INSERT id: ";
var_dump($id);

// 3) Count rows in "users"
echo "COUNT users: ";
var_dump($db->count('users'));

// 4) Get the row we just inserted
echo "GET users {$id}: ";
var_dump($db->get('users', $id));

