MqttGen - Generation and replay of MQTT messages
=====================================

*MqttGen* is an MQTT message generator.

*MqttGen* can be used to create MQTT message flow to test applications using the MQTT protocol. It was created to test the [jMQTT Jeedom plugin](https://github.com/domotruc/jMQTT).

*MqttGen* comes with two tools:
   * *mqttgen* which is a kind of MQTT simulator allowing to send and receive messages. Behaviour is defined in a JSON input file.
   * *mqttplay* which allows to replay a MQTT flow previously recorded thanks to an MQTT client such as mosquitto_sub.

Installation
------------

The recommended way to install *MqttGen* is through [Composer](http://getcomposer.org).

```bash
composer require domotruc/mqttgen
```

Usage of mqttgen
----------------

### As a standalone application

```bash
vendor/bin/mqttgen your_json_input_file.json
```

To run the provided example, execute the following from the directory containing the `composer.json` file:

```bash
vendor/bin/mqttgen vendor/domotruc/mqttgen/topics.json
```

### As a library

Following file is assumed to be in the same directory as your `composer.json` file.

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

It is also possible to interact with *mqttgen*. Given the `topics.json` example file, the following command:

```bash
mosquitto_pub -t 'boiler/hw/setpoint/get' -m ''
```

makes *mqttgen* send the following message:

```
2018-06-24 08:47:46.127 boiler/hw/setpoint 50
```

Then:
```bash
mosquitto_pub -t 'boiler/hw/setpoint/set' -m '65'
```

updates the internal *mqttgen* setpoint value. Sending again the get message:

```bash
mosquitto_pub -t 'boiler/hw/setpoint/get' -m ''
```

makes *mqttgen* send the following message:

```
2018-06-24 08:47:46.127 boiler/hw/setpoint 65
```

Usage of mqttplay
-----------------

*mqttplay* allows to replay an MQTT flow previously recorded thanks to the following command:

```bash
mosquitto_sub -t "#" -v| xargs -d$'\n' -L1 bash -c 'date "+%T.%3N $0"' | tee flow.txt
```

which gives a file such as:

```
15:27:10.358 N/pvinverter/20/Ac/L1/Voltage {"value": 240.59999999999999}
15:27:10.386 N/pvinverter/20/Ac/L1/Power {"value": 1821.8742186612658}
15:27:10.415 N/pvinverter/20/Ac/L1/Energy/Forward {"value": 4272.6587533761876}
15:27:10.496 N/pvinverter/20/Ac/L1/Current {"value": 3.9399999999999999}
```

### As a standalone application

To run the provided example, execute the following from the directory containing the `composer.json` file:

```bash
vendor/bin/mqttplay vendor/domotruc/mqttgen/flow.txt
```

### As a library

Following file is assumed to be in the same directory as your `composer.json` file.

```php
<?php

use  MqttPlay\MqttPlay;

require __DIR__ . '/vendor/autoload.php';

try {
    $filename = 'vendor/domotruc/mqttgen/flow.txt';
    $mqttPlay = new MqttPlay($filename, true, ' ', 'localhost', 1883, 1);
    while (($msg = $mqttPlay->nextMessage()) != null) {
        print($msg[MqttPlay::S_TIME ] . " " . $msg[MqttPlay::S_TOPIC] . " " . $msg[MqttPlay::S_PAYLOAD] . PHP_EOL);
    }
}
catch (\Exception $e) {
    print($e->getMessage() . PHP_EOL);
}
```

The MQTT flow can also be passed directly as an array, which gives:

```php
<?php

use  MqttPlay\MqttPlay;

require __DIR__ . '/vendor/autoload.php';

$mqtt_flow = array(
    array('15:27:10.358', 'N/pvinverter/20/Ac/L1/Voltage', '{"value": 240.59999999999999}'),
    array('15:27:10.386', 'N/pvinverter/20/Ac/L1/Power', '{"value": 1821.8742186612658}'),
    array('15:27:10.415', 'N/pvinverter/20/Ac/L1/Energy/Forward', '{"value": 4272.6587533761876}'),
    array('15:27:10.496', 'N/pvinverter/20/Ac/L1/Current', '{"value": 3.9399999999999999}')
);

try {
    $mqttPlay = new MqttPlay($mqtt_flow, true, ' ', 'localhost', 1883, 1);
    while (($msg = $mqttPlay->nextMessage()) != null) {
        print($msg[MqttPlay::S_TIME ] . " " . $msg[MqttPlay::S_TOPIC] . " " . $msg[MqttPlay::S_PAYLOAD] . PHP_EOL);
    }
}
catch (\Exception $e) {
    print($e->getMessage() . PHP_EOL);
}
```
