<?php
declare(strict_types=1);

class PinyDBClient
{
    private string $host;
    private int    $port;
    private $sock = null;
    private int    $timeout;

    public function __construct(string $host, int $port, int $timeout = 3)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    private function connect(): void
    {
        if ($this->sock && !feof($this->sock)) {
            return;
        }
    
        $this->sock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->sock) {
            throw new RuntimeException("PinyDBClient connect error: {$errstr} ({$errno})");
        }
    
        stream_set_timeout($this->sock, $this->timeout);
        stream_set_blocking($this->sock, true);
    }

    private function send(string $line): array
    {
        $this->connect();

        $line = trim($line)."\n";
        $bytes = @fwrite($this->sock, $line);

        if ($bytes === false) {
            // reconnect and retry once
            fclose($this->sock);
            $this->sock = null;
            $this->connect();

            $bytes = fwrite($this->sock, $line);
            if ($bytes === false) {
                throw new RuntimeException("PinyDBClient write failed");
            }
        }

        $resp = fgets($this->sock);
        if ($resp === false) {
            fclose($this->sock);
            $this->sock = null;
            throw new RuntimeException("PinyDBClient response error (empty)");
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("PinyDBClient invalid JSON response: ".$resp);
        }

        if ($decoded['ok'] !== true) {
            $err = $decoded['error'] ?? 'Unknown error';
            throw new RuntimeException("PinyDBServer error: {$err}");
        }

        return $decoded;
    }

    // ------------------------------
    // Public API
    // ------------------------------

    public function ping(): string
    {
        $r = $this->send("PING");
        return $r['data'];
    }

    public function count(string $table): int
    {
        $r = $this->send("COUNT {$table}");
        return $r['data'];
    }

    public function all(string $table): array
    {
        $r = $this->send("ALL {$table}");
        return $r['data'];
    }

    public function get(string $table, int $id): ?array
    {
        $r = $this->send("GET {$table} {$id}");
        return $r['data'];
    }

    public function insert(string $table, array $row): int
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE);
        $r    = $this->send("INSERT {$table} {$json}");
        return $r['data']; // id
    }

    public function update(string $table, int $id, array $fields): bool
    {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE);
        $cmd  = "UPDATE {$table} {$id} {$json}";
        $r    = $this->send($cmd);
        return (bool)$r['data'];
    }

    public function delete(string $table, int $id): bool
    {
        $r = $this->send("DELETE {$table} {$id}");
        return (bool)$r['data'];
    }

    public function rotatedPop(string $table): ?array
    {
        $r = $this->send("ROTATED_POP {$table}");
        return $r['data'];
    }
}

