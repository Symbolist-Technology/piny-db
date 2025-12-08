<?php
declare(strict_types=1);

namespace PinyDB;

/**
 * PinyDB CLI client
 *
 * Usage examples:
 *   pinydb --host=127.0.0.1 --port=9999
 *   pinydb -h 127.0.0.1 -P 9999
 *   pinydb -h 127.0.0.1 -P 9999 -c "PING"
 *   pinydb -h 127.0.0.1 -P 9999 -c "COUNT your_table"
 */
class PinyDBCli
{
    public function run(array $argv): int
    {

        // help command
        if (in_array('--help', $argv, true) || in_array('-h', $argv, true) || in_array('help', $argv, true)) {
            $this->printHelp();
            return 0;
        }

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

        // Single-command mode
        if ($command !== null) {
            $res = $this->send($host, $port, $timeout, $command);
            $this->printResult($res);
            return ($res['ok'] ?? false) ? 0 : 1;
        }

        // Interactive mode
        $this->interactiveLoop($host, $port, $timeout);
        return 0;
    }

    private function printHelp(): void
    {
        echo "PinyDB CLI Commands\n";
        echo "-------------------\n";
        echo "  --help | help           Show this help message\n";
        echo "  -c <CMD>                Run a single command\n";
        echo "  --daemon | -d           Run in background\n";
        echo "  --pidfile=              PID file when Runs in background\n";
        echo "  PING                    Test connection\n";
        echo "  CREATE <table>          Create a new table\n";
        echo "  DROP <table>            Drop a table\n";
        echo "  COUNT <table>           Count rows in table\n";
        echo "  ALL <table>             Get all rows\n";
        echo "  GET <table> <id>        Get 1 row\n";
        echo "  INSERT <t> <json>       Insert row\n";
        echo "  UPDATE <t> <id> <json>  Update row\n";
        echo "  DELETE <t> <id>         Delete row\n";
        echo "  SHOW TABLES             List tables\n";
        echo "  TRUNCATE <t>            Remove all rows from table\n";
        echo "  ROTATE <t>              Pop+rotate queue\n";
        echo "  RANDOM <t>              Randomly pop+rotate queue\n\n";
        echo "Examples:\n";
        echo "  pinydb-cli -c \"PING\"\n";
        echo "  pinydb-cli -c \"CREATE users\"\n";
        echo "  pinydb-cli -c \"INSERT users {\\\"name\\\":\\\"aa\\\"}\"\n";
    }

    private function printBanner(): void
    {
        $banner = <<<ASCII
  ____  _              ____  ____  
 |  _ \(_)_ __  _   _ / ___|| __ ) 
 | |_) | | '_ \| | | |\___ \|  _ \ 
 |  __/| | | | | |_| | ___) | |_) |
 |_|   |_|_| |_|\__, ||____/|____/ 
               |___/               
ASCII;

        echo $banner . "\n";
    }

    // -----------------------------
    // Core send function
    // -----------------------------
    private function send(string $host, int $port, int $timeout, string $cmd): array
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            fwrite(STDERR, "Connect failed: {$errstr} ({$errno})\n");
            return ['ok' => false, 'error' => "Connect failed: {$errstr} ({$errno})"];
        }

        stream_set_timeout($fp, $timeout);
        stream_set_blocking($fp, true);

        $line  = trim($cmd) . "\n";
        $bytes = @fwrite($fp, $line);
        if ($bytes === false) {
            fclose($fp);
            fwrite(STDERR, "Write failed\n");
            return ['ok' => false, 'error' => 'Write failed'];
        }

        $resp = fgets($fp);
        fclose($fp);

        if ($resp === false) {
            fwrite(STDERR, "Empty response from server\n");
            return ['ok' => false, 'error' => 'Empty response from server'];
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "Invalid JSON response: {$resp}\n");
            return ['ok' => false, 'error' => 'Invalid JSON response'];
        }

        return $decoded;
    }

    private function printResult(array $res): void
    {
        if (!($res['ok'] ?? false)) {
            $err = $res['error'] ?? 'Unknown error';
            fwrite(STDERR, "ERROR: {$err}\n");
            return;
        }

        $data = $res['data'] ?? null;
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    // -----------------------------
    // Interactive mode
    // -----------------------------
    private function interactiveLoop(string $host, int $port, int $timeout): void
    {
        $this->printBanner();

        $this->printHelp();

        echo "\nType 'help' to see commands again. Use 'exit', 'quit' or '\\q' to leave.\n\n";

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

            // Allow trailing ';' like mysql
            if (substr($line, -1) === ';') {
                $line = rtrim(substr($line, 0, -1));
            }

            $lower = strtolower($line);
            if (in_array($lower, ['exit', 'quit', '\\q'], true)) {
                break;
            }

            if (in_array($lower, ['help', '?'], true)) {
                $this->printHelp();
                continue;
            }

            $res = $this->send($host, $port, $timeout, $line);
            $this->printResult($res);
        }
    }
}

