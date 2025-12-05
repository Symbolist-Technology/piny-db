#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * PinyDB CLI client
 *
 * Usage:
 *   php PinyDBCli.php -h 127.0.0.1 -P 9999
 *   php PinyDBCli.php --host=127.0.0.1 --port=9999
 *   php PinyDBCli.php -h 127.0.0.1 -P 9999 -c "PING"
 *   php PinyDBCli.php -h 127.0.0.1 -P 9999 -c "COUNT your_table"
 */

$options = getopt(
    "h:P:t:c:",
    ["host:", "port:", "timeout:", "command:"]
);

$host    = $options['h']      ?? $options['host']    ?? '127.0.0.1';
$port    = isset($options['P']) ? (int)$options['P']
          : (isset($options['port']) ? (int)$options['port'] : 9999);
$timeout = isset($options['t']) ? (int)$options['t']
          : (isset($options['timeout']) ? (int)$options['timeout'] : 3);
$command = $options['c']      ?? $options['command'] ?? null;

// -----------------------------
// Core send function
// -----------------------------
function pinydb_send(string $host, int $port, int $timeout, string $cmd): array
{
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        fwrite(STDERR, "Connect failed: {$errstr} ({$errno})\n");
        exit(1);
    }

    stream_set_timeout($fp, $timeout);
    stream_set_blocking($fp, true);

    $line = trim($cmd) . "\n";
    $bytes = @fwrite($fp, $line);
    if ($bytes === false) {
        fclose($fp);
        fwrite(STDERR, "Write failed\n");
        exit(1);
    }

    $resp = fgets($fp);
    fclose($fp);

    if ($resp === false) {
        fwrite(STDERR, "Empty response from server\n");
        exit(1);
    }

    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Invalid JSON response: {$resp}\n");
        exit(1);
    }

    return $decoded;
}

function pinydb_print_result(array $res): void
{
    if (!($res['ok'] ?? false)) {
        $err = $res['error'] ?? 'Unknown error';
        fwrite(STDERR, "ERROR: {$err}\n");
        return;
    }

    $data = $res['data'] ?? null;

    // Pretty print JSON output
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

// -----------------------------
// Single command mode
// -----------------------------
if ($command !== null) {
    $res = pinydb_send($host, $port, $timeout, $command);
    pinydb_print_result($res);
    exit(($res['ok'] ?? false) ? 0 : 1);
}

// -----------------------------
// Interactive mode
// -----------------------------
echo "PinyDB CLI connected to {$host}:{$port}\n";
echo "Commands:\n";
echo "  PING\n";
echo "  INSERT your_table {\"foo\":\"bar\"}\n";
echo "  GET your_table 1\n";
echo "  COUNT your_table\n";
echo "  ALL your_table\n";
echo "  ROTATED_POP your_table\n";
echo "Use 'exit', 'quit' or '\\q' to leave.\n\n";

while (true) {
    if (function_exists('readline')) {
        $line = readline("pinydb> ");
        if ($line === false) {
            echo "\n";
            break;
        }
        $line = trim($line);
        if ($line !== '') {
            readline_add_history($line);
        }
    } else {
        echo "pinydb> ";
        $line = fgets(STDIN);
        if ($line === false) {
            echo "\n";
            break;
        }
        $line = trim($line);
    }

    if ($line === '') {
        continue;
    }

    $lower = strtolower($line);
    if (in_array($lower, ['exit', 'quit', '\\q'], true)) {
        break;
    }

    // Allow trailing ';' like mysql
    if (substr($line, -1) === ';') {
        $line = rtrim(substr($line, 0, -1));
    }

    $res = pinydb_send($host, $port, $timeout, $line);
    pinydb_print_result($res);
}

