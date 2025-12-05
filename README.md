# PinyDB
PinyDB ● tiny PHP JSON database

## Install

```
php composer.phar require symbolist/piny-db
```

## Usage

Start Server

```

nohup vendor/bin/pinydb-server -h 127.0.0.1 -P 9999 -d ./piny-data > /tmp/pinydb.log 2>&1 &


```

#to check logs

```
tail -f /tmp/pinydb.log

```

Use Client (Interactive Mode)

```

pinydb-cli --host=127.0.0.1 --port=9999 ping

 
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
  REMOVE your_table 1

```
