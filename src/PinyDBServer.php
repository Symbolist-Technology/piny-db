<?php
declare(strict_types=1);

namespace PinyDB;

use Throwable;

class PinyDBServer
{
    private string $host;
    private int    $port;
    private string $dataDir;
    private $server = null;

    private int $clientTimeout;
    private string $logfile;

    private PinyDB $db;

    private bool $useFlock;

    private bool $useForks = true;
    private int $maxChildren;
    private int $activeChildren = 0;

    public function __construct(string $host, int $port, string $dataDir, int $clientTimeout = 3, bool $useFlock = true, $logfile = '/tmp/pinydb.log', int $maxChildren = 20)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->dataDir = rtrim($dataDir, '/');
        $this->clientTimeout = $clientTimeout;
        $this->useFlock = $useFlock;
        $this->logfile = $logfile;
        $this->maxChildren = $maxChildren;

        $this->db = new PinyDB($this->dataDir, $this->useFlock);
    }

    private function log( $msg = '' ) {
        //add date
        $msg = "[".date('c')."] ".$msg."\n";
        //echo $msg;
        //write to file
        file_put_contents( $this->logfile, $msg, FILE_APPEND );
    }

    public function start(): void
    {
        $addr = "tcp://{$this->host}:{$this->port}";

        $this->server = @stream_socket_server($addr, $errno, $errstr);

        if (!$this->server) {
            $this->log( "Failed to start server: {$errstr} ({$errno}");
            throw new \RuntimeException("Failed to start server: {$errstr} ({$errno})");
        }

        $this->log( "Starting to listen on {$this->host}:{$this->port}, data dir: {$this->dataDir}");

        $this->setupChildReaper();

        while (true) {
            $conn = @stream_socket_accept($this->server, -1);
            if ($conn === false) {
                $this->log("Failed to get connection!");
                continue;
            }

            if ($this->useForks && function_exists('pcntl_fork')) {
                $this->reapChildren();

                while ($this->activeChildren >= $this->maxChildren) {
                    $this->log("Max child processes reached ({$this->maxChildren}), waiting...");
                    $this->reapChildren(true);
                }

                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->log("Failed to fork for incoming connection");
                    fclose($conn);
                    continue;
                }

                if ($pid === 0) {
                    // Child process: handle client and exit.
                    fclose($this->server);
                    $childPid = getmypid();
                    $this->log("Connection successful (child {$childPid})!");
                    $this->handleClient($conn);
                    fclose($conn);
                    exit(0);
                }

                // Parent process: close child socket and continue accepting.
                fclose($conn);
                $this->log("Connection dispatched to child {$pid}");
                $this->activeChildren++;
                continue;
            }

            $this->log("Connection successful!");
            $this->handleClient($conn);
            fclose($conn);
        }
    }

    private function setupChildReaper(): void
    {
        if (!$this->useForks || !function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGCHLD, function () {
            $this->reapChildren();
        });
    }

    private function reapChildren(bool $block = false): void
    {
        if (!$this->useForks || !function_exists('pcntl_waitpid')) {
            return;
        }

        while (true) {
            $pid = pcntl_waitpid(-1, $status, $block ? 0 : WNOHANG);

            if ($pid > 0) {
                $this->activeChildren = max(0, $this->activeChildren - 1);
                continue;
            }

            if ($pid === 0 && !$block) {
                break;
            }

            if ($pid === -1) {
                break;
            }
        }
    }

    private function handleClient($conn): void
    {
        stream_set_blocking($conn, true);
        stream_set_timeout($conn, $this->clientTimeout);

        while (!feof($conn)) {
            $line = fgets($conn);
            if ($line === false) {
                $meta = stream_get_meta_data($conn);
                if (($meta['timed_out'] ?? false) === true) {
                    $this->log("Connection Timeout!");
                    break;
                }
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $response = $this->handleCommand($line);

            fwrite($conn, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
            fflush($conn);
        }
    }

    private function handleCommand(string $line): array
    {
        $parts = explode(' ', $line, 3);
        $cmd   = strtoupper($parts[0] ?? '');

        try {
            switch ($cmd) {

                case 'PING':
                    return ['ok' => true, 'data' => 'PONG'];

                case 'COUNT':
                    if (count($parts) < 2) return $this->error("COUNT <table>");
                    return ['ok' => true, 'data' => $this->db->count($parts[1])];

                case 'ALL':
                    if (count($parts) < 2) return $this->error("ALL <table>");
                    return ['ok' => true, 'data' => $this->db->all($parts[1])];

                case 'SHOW':
                    if (strtoupper($parts[1] ?? '') !== 'TABLES') return $this->error("SHOW TABLES");
                    return ['ok' => true, 'data' => $this->db->listTables()];

                case 'GET':
                    if (count($parts) < 3) return $this->error("GET <table> <id>");
                    return ['ok' => true, 'data' => $this->db->get($parts[1], (int)$parts[2])];

                case 'INSERT':
                    if (count($parts) < 3) return $this->error("INSERT <table> <json_row>");
                    $row = json_decode($parts[2], true);
                    if (!is_array($row)) return $this->error("Invalid JSON row");
                    return ['ok' => true, 'data' => $this->db->insert($parts[1], $row)];

                case 'PUSH':
                    if (count($parts) < 3) return $this->error("PUSH <table> <json_row>");
                    $row = json_decode($parts[2], true);
                    if (!is_array($row)) return $this->error("Invalid JSON row");
                    return ['ok' => true, 'data' => $this->db->push($parts[1], $row)];

                case 'CREATE':
                    if (count($parts) < 2) return $this->error("CREATE <table>");
                    return ['ok' => true, 'data' => $this->db->create($parts[1])];

                case 'DROP':
                    if (count($parts) < 2) return $this->error("DROP <table>");
                    return ['ok' => true, 'data' => $this->db->drop($parts[1])];

                case 'UPDATE':
                    $p = explode(' ', $line, 4);
                    if (count($p) < 4) return $this->error("UPDATE <table> <id> <json_fields>");
                    $fields = json_decode($p[3], true);
                    if (!is_array($fields)) return $this->error("Invalid JSON");
                    return ['ok' => true, 'data' => $this->db->update($p[1], (int)$p[2], $fields)];

                case 'DELETE':
                    if (count($parts) < 3) return $this->error("DELETE <table> <id>");
                    return ['ok' => true, 'data' => $this->db->delete($parts[1], (int)$parts[2])];

                case 'TRUNCATE':
                    if (count($parts) < 2) return $this->error("TRUNCATE <table>");
                    $this->db->truncate($parts[1]);
                    return ['ok' => true, 'data' => true];

                case 'ROTATE':
                    if (count($parts) < 2) return $this->error("ROTATE <table>");
                    return ['ok' => true, 'data' => $this->db->rotate($parts[1])];

                case 'ROTATED_POP':
                    if (count($parts) < 2) return $this->error("ROTATED_POP <table>");
                    return ['ok' => true, 'data' => $this->db->rotate($parts[1])];

                case 'POP':
                    if (count($parts) < 2) return $this->error("POP <table>");
                    return ['ok' => true, 'data' => $this->db->pop($parts[1])];

                case 'RANDOM':
                    if (count($parts) < 2) return $this->error("RANDOM <table>");
                    return ['ok' => true, 'data' => $this->db->random($parts[1])];

                default:
                    return $this->error("Unknown command: {$cmd}");
            }
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    private function error(string $msg): array
    {
        $this->log("Error: $msg!");
        return ['ok' => false, 'error' => $msg];
    }
}
