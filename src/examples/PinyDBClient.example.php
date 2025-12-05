<?php
require_once  "../PinyDBClient.php";

$client = new PinyDBClient("127.0.0.1", 9999);

echo $client->ping();  // PONG

// Insert
$id = $client->insert("your_table", [
    "foo"  => "bar",
    "time" => time(),
]);
echo "Inserted ID: $id\n";

