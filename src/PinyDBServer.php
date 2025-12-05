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

    private PinyDB $db;

    public function __construct(string $host, int $port, string $dataDir)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->dataDir = rtrim($dataDir, '/');

        $this->db = new PinyDB($this->dataDir);
    }

    public function start(): void
    {
        $addr = "tcp://{$this->host}:{$this->port}";

        $this->server = @stream_socket_server($addr, $errno, $errstr);

        if (!$this->server) {
            throw new \RuntimeException("Failed to start server: {$errstr} ({$errno})");
        }

        echo "PinyDBServer listening on {$this->host}:{$this->port}, data dir: {$this->dataDir}\n";

        while (true) {
            $conn = @stream_socket_accept($this->server, -1);
            if ($conn === false) {
                continue;
            }
            $this->handleClient($conn);
            fclose($conn);
        }
    }

    private function handleClient($conn): void
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

                case 'GET':
                    if (count($parts) < 3) return $this->error("GET <table> <id>");
                    return ['ok' => true, 'data' => $this->db->get($parts[1], (int)$parts[2])];

                case 'INSERT':
                    if (count($parts) < 3) return $this->error("INSERT <table> <json_row>");
                    $row = json_decode($parts[2], true);
                    if (!is_array($row)) return $this->error("Invalid JSON row");
                    return ['ok' => true, 'data' => $this->db->insert($parts[1], $row)];

                case 'UPDATE':
                    $p = explode(' ', $line, 4);
                    if (count($p) < 4) return $this->error("UPDATE <table> <id> <json_fields>");
                    $fields = json_decode($p[3], true);
                    if (!is_array($fields)) return $this->error("Invalid JSON");
                    return ['ok' => true, 'data' => $this->db->update($p[1], (int)$p[2], $fields)];

                case 'DELETE':
                    if (count($parts) < 3) return $this->error("DELETE <table> <id>");
                    return ['ok' => true, 'data' => $this->db->delete($parts[1], (int)$parts[2])];

                case 'ROTATED_POP':
                    if (count($parts) < 2) return $this->error("ROTATED_POP <table>");
                    return ['ok' => true, 'data' => $this->db->rotatedPop($parts[1])];

                default:
                    return $this->error("Unknown command: {$cmd}");
            }
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    private function error(string $msg): array
    {
        return ['ok' => false, 'error' => $msg];
    }
}

