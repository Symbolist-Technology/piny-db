<p align="center">
  <img src="assets/piny-db-logo.svg" width="220" alt="PineDB Logo">
</p>

<p align="center">Tiny File-Based JSON Database for PHP</p>


## Install

```
php composer.phar require symbolist/piny-db
```

## Usage

**Start Server**

```
./vendor/bin/pinydb-server -h 127.0.0.1 -P 9999 -d ./piny-data 
```



**Use Client (Interactive Mode)**

```

./vendor/bin/pinydb-cli --host=127.0.0.1 --port=9999 ping

 
```
Example client Output

```
pinydb> ping
"PONG"
pinydb> 

```

**Using PHP Client (SDK)**

```php
use PinyDB\PinyDBClient;

try{
    $client = new PinyDBClient("127.0.0.1", 9999);

    echo $client->ping();  // PONG
    //$record = array('foo' => 'bar.'.rand(99,9999));
    //echo $client->insert('records', $record);  // PONG
    $record=  $client->rotate('records');
    print_r($record);
    exit;
}
catch(Exception $e) {
    echo 'Caught exception: ',  $e->getMessage(), "\n";
    exit;
}


```

**Disabling file locks**

PinyDB uses `flock` for read and write operations by default. If you are working on a filesystem that does not support file locks (for example, some network mounts), you can disable locking:

```php
use PinyDB\PinyDB;

$db = new PinyDB('/path/to/data', false); // disable flock
```

The TCP server can also skip locking via a flag:

```
./vendor/bin/pinydb-server --disable-flock -h 127.0.0.1 -P 9999 -d ./piny-data
```

## Commands
```
  --help                        Show this help message
  -c <CMD>                      Run a single command
  PING                          Test connection
  CREATE <table>                Create a new table
  DROP <table>                  Drop a table
  COUNT  <table>                Count rows in table
  ALL    <table>                Get all rows
  GET    <table> <id>           Get 1 row
  INSERT <table> <json>         Insert row
  UPDATE <table> <id> <json>    Update row
  DELETE <table> <id>           Delete row
  SHOW TABLES                   List tables
  TRUNCATE <table>              Remove all rows from table
  ROTATE <table>                Pop+rotate queue
  RANDOM <table>                Randomly pop+rotate queue

```
