#!/usr/bin/env php
<?php
declare(strict_types=1);

use PinyDB\PinyDBClient;

// ----------------------
// Autoload
// ----------------------
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/../src/PinyDBClient.php';

function showUsage(): void
{
    echo "PinyDB Test Data Loader\n\n";
    echo "Usage: php tests/load_data.php [options]\n\n";
    echo "Options:\n";
    echo "  -n, --count <int>      Number of records to insert (default: 50)\n";
    echo "      --host <string>    Server host (default: 127.0.0.1)\n";
    echo "      --port <int>       Server port (default: 9999)\n";
    echo "      --table <string>   Target table name (default: records)\n";
    echo "      --truncate         Truncate table before loading data\n";
    echo "  -h, --help             Show this message\n";
}

$options = getopt('n:h', ['count:', 'host::', 'port::', 'table::', 'truncate', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    showUsage();
    exit(0);
}

$count = max(1, (int)($options['n'] ?? $options['count'] ?? 50));
$host  = (string)($options['host'] ?? '127.0.0.1');
$port  = (int)($options['port'] ?? 9999);
$table = (string)($options['table'] ?? 'records');

try {
    $client = new PinyDBClient($host, $port);
} catch (RuntimeException $e) {
    fwrite(STDERR, "Failed to connect: {$e->getMessage()}\n");
    exit(1);
}

function generateRecord(int $index): array
{
    $names = ['Ada Lovelace', 'Alan Turing', 'Grace Hopper', 'Edsger Dijkstra', 'Donald Knuth'];
    $tags  = ['alpha', 'beta', 'gamma', 'delta', 'omega'];

    $name = $names[$index % count($names)];
    $tag  = $tags[$index % count($tags)];

    return [
        'title'      => "Example record #{$index}",
        'owner'      => $name,
        'email'      => strtolower(str_replace(' ', '.', $name))."@example.com",
        'tag'        => $tag,
        'created_at' => date(DATE_ATOM),
        'payload'    => [
            'index'   => $index,
            'message' => 'Sample payload for integration testing',
        ],
    ];
}

// Ensure table exists; ignore errors if it already does
try {
    $client->create($table);
} catch (RuntimeException $e) {
    // table likely exists; proceed
}

if (isset($options['truncate'])) {
    try {
        $client->truncate($table);
        fwrite(STDOUT, "Truncated table '{$table}'.\n");
    } catch (RuntimeException $e) {
        fwrite(STDERR, "Failed to truncate table: {$e->getMessage()}\n");
        exit(1);
    }
}

$success = 0;
$failure = 0;
$start   = microtime(true);

for ($i = 1; $i <= $count; $i++) {
    $record = generateRecord($i);

    try {
        $client->insert($table, $record);
        $success++;
    } catch (RuntimeException $e) {
        $failure++;
        fwrite(STDERR, "[{$i}/{$count}] Insert failed: {$e->getMessage()}\n");
    }

    if ($i % 10 === 0 || $i === $count) {
        $progress = sprintf("\rInserted %d/%d", $i, $count);
        fwrite(STDOUT, $progress);
    }
}

$elapsed = microtime(true) - $start;
$perCall = $count > 0 ? $elapsed / $count : 0.0;

fwrite(STDOUT, "\n\nSummary\n");
if ($success > 0) {
    fwrite(STDOUT, "  Success: {$success}\n");
}
if ($failure > 0) {
    fwrite(STDOUT, "  Failure: {$failure}\n");
}
fwrite(STDOUT, sprintf("  Total time: %.3fs\n", $elapsed));
fwrite(STDOUT, sprintf("  Avg/insert: %.6fs\n", $perCall));

