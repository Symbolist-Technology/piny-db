<?php
declare(strict_types=1);

namespace PinyDB;

class PinyDB
{
    private string $dir;
    private bool $useFlock;

    public function __construct(string $dir, bool $useFlock = true)
    {
        $this->dir = rtrim($dir, '/');
        $this->useFlock = $useFlock;

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    private function tableFile(string $table): string
    {
        return "{$this->dir}/{$table}.json";
    }

    public function listTables(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $files = glob($this->dir . '/*.json') ?: [];
        $tables = array_map(static function (string $file): string {
            return pathinfo($file, PATHINFO_FILENAME);
        }, $files);

        sort($tables, SORT_STRING);

        return $tables;
    }

    private function defaultData(): array
    {
        return [
            'auto_id' => 1,
            'rows'    => [],
            'random_ids' => [],
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
        if ($this->useFlock) {
            flock($fp, LOCK_SH);
        }
        $json = stream_get_contents($fp);
        if ($this->useFlock) {
            flock($fp, LOCK_UN);
        }
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

        if (!isset($data['random_ids']) || !is_array($data['random_ids'])) {
            $data['random_ids'] = [];
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
        if ($this->useFlock && !flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException("Cannot lock DB file: {$file}");
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);

        if ($this->useFlock) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public function create(string $table): bool
    {
        $file = $this->tableFile($table);
        if (file_exists($file)) {
            return false;
        }

        $this->save($table, $this->defaultData());
        return true;
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

        $data['random_ids'] = [];

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
            $data['random_ids'] = [];
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
        $data['random_ids'] = [];
        $this->save($table, $data);
    }

    public function truncate(string $table): void
    {
        $this->save($table, $this->defaultData());
    }

    public function drop(string $table): bool
    {
        $file = $this->tableFile($table);
        if (!file_exists($file)) {
            return false;
        }

        return unlink($file);
    }

    /**
     * Queue-style rotation:
     * - Take first row
     * - Move it to the end
     * - Return that row
     *
     * Returns null if table is empty.
     */
    public function rotate(string $table): ?array
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

    /**
     * @deprecated Use rotate() instead.
     */
    public function rotatedPop(string $table): ?array
    {
        return $this->rotate($table);
    }

    /**
     * Randomized rotation:
     * - Builds a temporary shuffle pool of row ids (if missing)
     * - Picks a random id from the pool
     * - Removes it from the pool and returns the corresponding row
     * - If a stale id is encountered, it is dropped and the pick is retried
     * - When the pool becomes empty it is rebuilt from current rows
     *
     * Returns null if table is empty.
     */
    public function random(string $table): ?array
    {
        $data = $this->load($table);

        //return empty, if table is empty
        if(empty($data) || empty($data['rows'])){
            return null;
        }

        //create pool if not exists
        if(empty($data['random_ids'])){
            foreach ($data['rows'] as $row) {
                array_push( $data['random_ids'], (int) $row['id'] );
            }
            $this->save($table, $data);
        }

        while (!empty($data['random_ids'])) {

            //choose random index
            $index = array_rand($data['random_ids']);

            //get id from index
            $id    = (int)$data['random_ids'][$index];

            //remove index from random pool
            array_splice($data['random_ids'], $index, 1);

            //update pool
            $this->save($table, $data);

            //get data by id (implicitly it will check if its valid)
            $record = $this->get( $table , $id );

            //if valid then break or else continue
            if(!empty($record)){
                return $record;
            }
        }

        //if program reached here, it means there is no valid id found, 
        //so recreate random pool and try again
        foreach ($data['rows'] as $row) {
            array_push( $data['random_ids'], (int) $row['id'] );
        }

        return $this->random($table);

    }
}

