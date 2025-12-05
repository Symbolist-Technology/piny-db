# PinyDB
PinyDB ● tiny PHP JSON database

## Install

```
php composer.phar require symbolist/piny-db
```

## Usage

Start Server

```
nohup php PinyDBServer.php ./datadir 127.0.0.1 9999  > /tmp/pinydb.log 2>&1 &


```

#to check logs

```
tail -f /tmp/pinydb.log

```

Use Client

```
php PinyDBCli.php 127.0.0.1 9999
 
```
Example client 

```
pinydb> ping
"PONG"
pinydb> 

```

## commands
```
  PING
  INSERT your_table {"foo":"bar"}
  GET your_table 1
  COUNT your_table
  ALL your_table
  ROTATED_POP your_table

```
