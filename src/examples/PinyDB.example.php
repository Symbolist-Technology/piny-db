<?php

require_once '../PinyDB.php';

$db = new PinyDB(__DIR__ . '/datadir');

// insert
$id = $db->insert('your_table', ['foo' => 'bar']);

print "inserted with id($id)\n";

// get count
$count = $db->count('your_table');
print "total count$count)\n";

// rotate queue and get next visit
$record = $db->rotate('your_table');
print_r($record);
