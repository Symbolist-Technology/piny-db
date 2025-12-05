# PinyDB
PinyDB ● tiny PHP JSON database

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

```
use PinyDB\PinyDBClient;

try{
    $client = new PinyDBClient("127.0.0.1", 9999);

    echo $client->ping();  // PONG
    //$record = array('foo' => 'bar.'.rand(99,9999));
    //echo $client->insert('records', $visit);  // PONG
    $record=  $client->rotatedPop('records');
    print_r($record);
    exit;
}
catch(Exception $e) {
    echo 'Caught exception: ',  $e->getMessage(), "\n";
    exit;
}


```

## Commands
```
  --help                        Show this help message
  -c <CMD>                      Run a single command
  PING                          Test connection
  COUNT  <table>                Count rows in table
  ALL    <table>                Get all rows
  GET    <table> <id>           Get 1 row
  INSERT <table> <json>         Insert row
  UPDATE <table> <id> <json>    Update row
  DELETE <table> <id>           Delete row
  ROTATED_POP <table>           Pop+rotate queue

```
