MqttGen - Generation of MQTT messages
=====================================

*MqttGen* is an MQTT message generator. Message to be generated are defined in a JSON input file.

*MqttGen* can be used to create MQTT message flow to test applications using the MQTT protocol. It was created to test the [jMQTT Jeedom plugin](https://github.com/domotruc/jMQTT).

Installation
------------

The recommended way to install *MqttGen* is through [Composer](http://getcomposer.org).

```bash
composer require domotruc/mqttgen
```

Usage
-----

### As a standalone application

Execute the following:

```bash
vendor/bin/mqttgen vendor/domotruc/mqttgen/topics.json
```

### As a library

```php
<?php

require __DIR__ . '/vendor/autoload.php';

try {
    $filename = 'vendor/domotruc/mqttgen/topics.json';
    $mqttgen = new MqttGen\MqttGen($filename);
    do {
        $mqttgen->nextMessage();
        sleep(2);
    } while (true);
}
catch (\Exception $e) {
    print($e->getMessage() . PHP_EOL);
}
```

JSON input file
---------------

The library is provided with an example of JSON input file [topics.json](https://github.com/domotruc/mqttgen/blob/master/topics.json) that gives and explains all the options.

Once the libray is installed using `composer` (see above), file `topics.json` can be found in the `vendor/domotruc/mqttgen` directory.

This file generates the following MQTT flows:

```
2018-06-24 08:38:33.171 boiler/status "online"
2018-06-24 08:38:35.173 boiler/brand "undisclosed brand"
2018-06-24 08:38:37.172 boiler/uptime 94002
2018-06-24 08:38:39.173 boiler/date {"time":{"value":"08:38:39"},"date":{"value":"24.06.2018"}}
2018-06-24 08:38:41.175 boiler/ping "ping"
2018-06-24 08:38:43.176 boiler/burner "off"
2018-06-24 08:38:45.174 boiler/temp 89.5
2018-06-24 08:38:47.175 boiler/ext_temp 20.157077
2018-06-24 08:38:49.176 boiler/hw/temp 50.5
2018-06-24 08:38:51.175 boiler/info {"device":"ESP32"}
2018-06-24 08:38:53.179 boiler/temperatures {"device":"tronic","sensorType":"Temperature","values":[9.710066,84.988007,22.03299]}
2018-06-24 08:38:55.179 boiler/power 1.01
2018-06-24 08:38:57.180 boiler/lux 1114.44
```

It is also possible to interact with *MqttGen*. Given the `topics.json` example file, the following command:

```bash
mosquitto_pub -t 'boiler/hw/setpoint/get' -m ''
```

makes *MqttGen* send the following message:

```
2018-06-24 08:47:46.127 boiler/hw/setpoint 50
```

Then:
```bash
mosquitto_pub -t 'boiler/hw/setpoint/set' -m '65'
```

updated the internal *MqttGen* setpoint value. Sending again the get message:

```bash
mosquitto_pub -t 'boiler/hw/setpoint/get' -m ''
```

makes *MqttGen* send the following message:

```
2018-06-24 08:47:46.127 boiler/hw/setpoint 65
```
