<?php
declare(strict_types=1);

class PinyDB
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    private function tableFile(string $table): string
    {
        return "{$this->dir}/{$table}.json";
    }

    private function defaultData(): array
    {
        return [
            'auto_id' => 1,
            'rows'    => [],
        ];
    }

    private function load(string $table): array
    {
        $file = $this->tableFile($table);

        if (!file_exists($file)) {
            return $this->defaultData();
        }

        $fp = fopen($file, 'r');
        if (!$fp) {
            return $this->defaultData();
        }

        // shared lock for read
        flock($fp, LOCK_SH);
        $json = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['rows'])) {
            $data = $this->defaultData();
        }

        if (!isset($data['auto_id'])) {
            $data['auto_id'] = count($data['rows'])
                ? (max(array_column($data['rows'], 'id')) + 1)
                : 1;
        }

        return $data;
    }

    private function save(string $table, array $data): void
    {
        $file = $this->tableFile($table);

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $fp = fopen($file, 'c+');
        if (!$fp) {
            throw new RuntimeException("Cannot open DB file: {$file}");
        }

        // exclusive lock for write
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException("Cannot lock DB file: {$file}");
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function all(string $table): array
    {
        $data = $this->load($table);
        return $data['rows'];
    }

    public function count(string $table): int
    {
        $data = $this->load($table);
        return count($data['rows']);
    }

    public function insert(string $table, array $row): int
    {
        $data = $this->load($table);

        $id = $data['auto_id'] ?? 1;
        $row['id'] = $id;

        $data['auto_id'] = $id + 1;
        $data['rows'][]  = $row;

        $this->save($table, $data);

        return $row['id'];
    }

    /**
     * Get a row by numeric id.
     */
    public function get(string $table, int $id): ?array
    {
        $data = $this->load($table);
        foreach ($data['rows'] as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    public function update(string $table, int $id, array $fields): bool
    {
        $data = $this->load($table);
        $changed = false;

        foreach ($data['rows'] as &$row) {
            if ((int)$row['id'] === $id) {
                $row = array_merge($row, $fields);
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $this->save($table, $data);
        }

        return $changed;
    }

    public function delete(string $table, int $id): bool
    {
        $data = $this->load($table);
        $rows = $data['rows'];

        $before = count($rows);
        $rows = array_values(array_filter($rows, function ($row) use ($id) {
            return (int)$row['id'] !== $id;
        }));
        $after = count($rows);

        if ($after !== $before) {
            $data['rows'] = $rows;
            $this->save($table, $data);
            return true;
        }

        return false;
    }

    /**
     * Replace all rows at once (used if you want to rewrite the table).
     */
    public function replaceAll(string $table, array $rows): void
    {
        $data = $this->load($table);
        $data['rows'] = array_values($rows);
        $this->save($table, $data);
    }

    /**
     * Queue-style rotation:
     * - Take first row
     * - Move it to the end
     * - Return that row
     *
     * Returns null if table is empty.
     */
    public function rotatedPop(string $table): ?array
    {
        $data = $this->load($table);
        if (empty($data['rows'])) {
            return null;
        }

        $first = array_shift($data['rows']);
        $data['rows'][] = $first;

        $this->save($table, $data);

        return $first;
    }
}

