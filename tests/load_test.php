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
    echo "PinyDB Load Tester\n\n";
    echo "Usage: php tests/load_test.php [options]\n\n";
    echo "Options:\n";
    echo "  -p, --processes <int>     Number of processes (default: 10)\n";
    echo "  -c, --calls <int>         Number of calls per process (default: 100)\n";
    echo "  -t, --type <random|rotate> Type of call to execute (default: random)\n";
    echo "      --host <string>       Server host (default: 127.0.0.1)\n";
    echo "      --port <int>          Server port (default: 9999)\n";
    echo "      --table <string>      Target table name (default: records)\n";
    echo "  -h, --help                Show this message\n";
}

$options = getopt('p:c:t:h', ['processes:', 'calls:', 'type:', 'host::', 'port::', 'table::', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    showUsage();
    exit(0);
}

$processes       = max(1, (int)($options['p'] ?? $options['processes'] ?? 10));
$callsPerProcess = max(1, (int)($options['c'] ?? $options['calls'] ?? 100));
$type            = strtolower((string)($options['t'] ?? $options['type'] ?? 'random'));
$host            = (string)($options['host'] ?? '127.0.0.1');
$port            = (int)($options['port'] ?? 9999);
$table           = (string)($options['table'] ?? 'records');

if (!in_array($type, ['random', 'rotate'], true)) {
    fwrite(STDERR, "Invalid --type value. Allowed: random, rotate\n");
    exit(1);
}

$totalCalls = $processes * $callsPerProcess;

function renderProgress(int $success, int $failure, int $total, float $startTime): void
{
    $done      = $success + $failure;
    $percent   = $total > 0 ? ($done / $total) * 100 : 0;
    $barLength = 30;
    $filled    = (int)round(($percent / 100) * $barLength);
    $bar       = str_repeat('#', $filled) . str_repeat('-', $barLength - $filled);

    $elapsed = microtime(true) - $startTime;
    $perCall = $done > 0 ? $elapsed / $done : 0.0;

    $line = sprintf(
        "\r[%s] %6.2f%% | Success: %d | Failure: %d | Elapsed: %.2fs | %.4fs/call",
        $bar,
        $percent,
        $success,
        $failure,
        $elapsed,
        $perCall
    );

    fwrite(STDOUT, $line);
}

function spawnWorkers(
    int $processes,
    int $callsPerProcess,
    string $type,
    string $host,
    int $port,
    string $table
): array {
    $sockets = [];

    for ($i = 0; $i < $processes; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Unable to create socket pair');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Failed to fork process');
        }

        if ($pid === 0) {
            // child
            fclose($pair[0]);
            $client = new PinyDBClient($host, $port);

            for ($call = 0; $call < $callsPerProcess; $call++) {
                $success = false;
                try {
                    if ($type === 'random') {
                        $client->random($table);
                    } else {
                        $client->rotate($table);
                    }
                    $success = true;
                } catch (Throwable $e) {
                    $success = false;
                }

                fwrite($pair[1], $success ? '1' : '0');
            }

            fclose($pair[1]);
            exit(0);
        }

        // parent
        fclose($pair[1]);
        stream_set_blocking($pair[0], false);
        $sockets[$pid] = $pair[0];
    }

    return $sockets;
}

$startTime = microtime(true);
$success   = 0;
$failure   = 0;

$sockets = spawnWorkers($processes, $callsPerProcess, $type, $host, $port, $table);

while (!empty($sockets) || ($success + $failure) < $totalCalls) {
    $read = array_values($sockets);
    if (empty($read)) {
        break;
    }

    $write  = [];
    $except = [];

    $ready = stream_select($read, $write, $except, 1);
    if ($ready === false) {
        break;
    }

    if ($ready > 0) {
        foreach ($read as $socket) {
            $data = fread($socket, 8192);
            if ($data === '' || $data === false) {
                if (feof($socket)) {
                    $pid = array_search($socket, $sockets, true);
                    if ($pid !== false) {
                        fclose($sockets[$pid]);
                        unset($sockets[$pid]);
                    }
                }
                continue;
            }

            $success += substr_count($data, '1');
            $failure += substr_count($data, '0');
        }
    }

    renderProgress($success, $failure, $totalCalls, $startTime);
}

// Ensure all children have exited
while (($childPid = pcntl_wait($status, WNOHANG)) > 0) {
    $pid = $childPid;
    if (isset($sockets[$pid])) {
        fclose($sockets[$pid]);
        unset($sockets[$pid]);
    }
}

$endTime    = microtime(true);
$duration   = $endTime - $startTime;
$totalCalls = max($success + $failure, $totalCalls);
$perCall    = $totalCalls > 0 ? $duration / $totalCalls : 0.0;

// Final progress line
renderProgress($success, $failure, $totalCalls, $startTime);
fwrite(STDOUT, "\n\n");

$successRate = $totalCalls > 0 ? ($success / $totalCalls) * 100 : 0.0;
$failureRate = $totalCalls > 0 ? ($failure / $totalCalls) * 100 : 0.0;

echo "Summary\n";
echo "-------\n";
printf("Total calls: %d\n", $totalCalls);
printf("Success:    %d (%.2f%%)\n", $success, $successRate);
printf("Failure:    %d (%.2f%%)\n", $failure, $failureRate);
printf("Total time: %.3fs\n", $duration);
printf("Avg/call:   %.6fs\n", $perCall);
