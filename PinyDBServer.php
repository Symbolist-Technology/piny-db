<?php
declare(strict_types=1);

require __DIR__ . '/PinyDB.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php PinyDBServer.php <data_dir> [host] [port]\n");
    exit(1);
}

$dataDir = $argv[1];
$host    = $argv[2] ?? '127.0.0.1';
$port    = (int)($argv[3] ?? 9999);

$db = new PinyDB($dataDir);

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to start server: {$errstr} ({$errno})\n");
    exit(1);
}

echo "PinyDBServer listening on {$host}:{$port}, data dir: {$dataDir}\n";

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }

    handleClient($conn, $db);
    fclose($conn);
}

// --------------------
// Client handler
// --------------------

function handleClient($conn, PinyDB $db): void
{
    stream_set_blocking($conn, true);

    while (!feof($conn)) {
        $line = fgets($conn);
        if ($line === false) {
            break;
        }

        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $response = handleCommand($line, $db);

        fwrite($conn, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
        fflush($conn);
    }
}

function handleCommand(string $line, PinyDB $db): array
{
    $parts = explode(' ', $line, 3);
    $cmd   = strtoupper($parts[0] ?? '');

    try {
        switch ($cmd) {
            case 'PING':
                return ['ok' => true, 'data' => 'PONG'];

            case 'COUNT': {
                if (count($parts) < 2) {
                    return error("COUNT requires: COUNT <table>");
                }
                $table = $parts[1];
                $cnt   = $db->count($table);
                return ['ok' => true, 'data' => $cnt];
            }

            case 'ALL': {
                if (count($parts) < 2) {
                    return error("ALL requires: ALL <table>");
                }
                $table = $parts[1];
                $rows  = $db->all($table);
                return ['ok' => true, 'data' => $rows];
            }

            case 'GET': {
                if (count($parts) < 3) {
                    return error("GET requires: GET <table> <id>");
                }
                $table = $parts[1];
                $id    = (int)$parts[2];
                $row   = $db->get($table, $id);
                return ['ok' => true, 'data' => $row];
            }

            case 'INSERT': {
                if (count($parts) < 3) {
                    return error("INSERT requires: INSERT <table> <json_row>");
                }
                $table   = $parts[1];
                $jsonStr = $parts[2];
                $row     = json_decode($jsonStr, true);
                if (!is_array($row)) {
                    return error("Invalid JSON row");
                }
                $id = $db->insert($table, $row);
                return ['ok' => true, 'data' => $id];
            }

            case 'UPDATE': {
                if (count($parts) < 3) {
                    return error("UPDATE requires: UPDATE <table> <id> <json_fields>");
                }

                // Need 4 parts for UPDATE, so we re-split
                $parts2 = explode(' ', $line, 4);
                if (count($parts2) < 4) {
                    return error("UPDATE requires: UPDATE <table> <id> <json_fields>");
                }

                $table   = $parts2[1];
                $id      = (int)$parts2[2];
                $jsonStr = $parts2[3];

                $fields = json_decode($jsonStr, true);
                if (!is_array($fields)) {
                    return error("Invalid JSON fields");
                }

                $ok = $db->update($table, $id, $fields);
                return ['ok' => true, 'data' => $ok];
            }

            case 'DELETE': {
                if (count($parts) < 3) {
                    return error("DELETE requires: DELETE <table> <id>");
                }
                $table = $parts[1];
                $id    = (int)$parts[2];
                $ok    = $db->delete($table, $id);
                return ['ok' => true, 'data' => $ok];
            }

            case 'ROTATED_POP': {
                if (count($parts) < 2) {
                    return error("ROTATED_POP requires: ROTATED_POP <table>");
                }
                $table = $parts[1];
                $row   = $db->rotatedPop($table);
                return ['ok' => true, 'data' => $row];
            }

            default:
                return error("Unknown command: {$cmd}");
        }
    } catch (Throwable $e) {
        return error("Exception: " . $e->getMessage());
    }
}

function error(string $msg): array
{
    return ['ok' => false, 'error' => $msg];
}

