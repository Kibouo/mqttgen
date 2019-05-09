<?php
namespace MqttGen;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

/**
 * MqttGen class
 *
 * 2 usages are possibles:
 * . Standalone application: call the main function which features an infinite loop sending messages
 * separated by the delay specified in the configuration file.
 * . Create an MqttGen object and then call the nextMessage object each time you want a message to be published.
 */
class MqttGen {

    /**
     * Constants defining keys in the iput JSON file
     */
    const S_MQTT = 'mqtt';

    // mqtt section
    const S_MISC = 'misc';

    // miscellaneous section
    const S_MESSAGES = 'messages';

    // topics definition section
    const S_FUN = 'func';

    // function name
    const S_PAR = 'param';

    // parameters associated to the function
    const S_TOPIC = 'topic';

    // topic name
    const S_PAYLOAD = 'payload';

    // payload definition
    const S_SET = 'set:';

    // trailing string to define set command topics mqttgen shall subscribe to
    const S_GET = 'get';

    // get key
    const S_SYNC = 'sync';

    // synchronous boolean status. If not present, true is assumed.
    const S_ONCE = 'once';

    // If true payload is sent only once after connexion. false by default.
    const S_RETAIN = 'retain';

    // To specify a retain message. false by default.

    // Monolog logger
    private $logger;

    // Messages as defined in the S_MESSAGES element of the input JSON file
    private $messages;

    // List of MQTT topics published towards the broker
    private $mqtt_topics = array();

    // List of MQTT subscribed topics
    private $commands;

    // Timer interval between messages
    private $time_interval;

    // Publication QoS
    private $qos;

    // MQTT client
    private $client;

    // are only once messages sent?
    private $is_once_ended = false;

    /**
     * Constructor class
     * .
     * Load the JSON file
     * . Configure the $logger
     * . Read and set the $time_interval
     * . Initialises $messages with the message array
     * . Check all messages (presence S_TOPIC and S_PAYLOAD)
     * . Check message definition and subscribe to get and set related topics
     * . Connect to the broker
     *
     * @throw Exception in case of fatal error
     * @param string $_filename JSON input filename
     * @param array $_mqtt_cnfg
     *   array allowing to override the 'mqtt' array of the JSON input filename. Resulting array is built merging
     *   the 'mqtt' array of the JSON input filename (empty array if not present) and the given array (merge_array function
     *   is used, $_mqtt_cnfg begin passed as 2nd parameter).
     */
    function __construct(string $_filename, array $_mqtt_cnfg = array()) {
        if (! file_exists($_filename))
            throw new \Exception('File ' . $_filename . ' does not exist');

        $json = json_decode(file_get_contents($_filename), true);
        if (json_last_error() != JSON_ERROR_NONE)
            self::throwJsonLastErrorException($_filename);

        $misc_cnfg = isset($json[self::S_MISC]) ? $json[self::S_MISC] : Array();

        //
        // Configure logging
        //
        $logging_level = self::arrayValue($misc_cnfg, 'logging_level', Logger::INFO);
        $logging_file = self::arrayValue($misc_cnfg, 'logging_file', 'php://stdout');
        if ($logging_file == '')
            $logging_file = 'php://stdout';

        $formatter = new LineFormatter('[%datetime%] %channel%.%level_name%: %message%' . PHP_EOL);
        $streamHandler = new StreamHandler($logging_file, $logging_level);
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('MgttGen Logger');
        $this->logger->pushHandler($streamHandler);
        $this->logger->debug('===== configuration starts');
        $this->logger->info('logger is configured');
        // ####

        // Get time interval
        $this->time_interval = self::arrayValue($misc_cnfg, 'time_interval', 1);

        // Get the messages array
        if (isset($json[self::S_MESSAGES]))
            $this->messages = $json[self::S_MESSAGES];
        else
            $this->logErrorAndThrowException('no key "' . self::S_MESSAGES . '" specified in config, nothing to do');

        // Connect to the broker
        $mqtt_cnfg = array_merge(isset($json[self::S_MQTT]) ? $json[self::S_MQTT] : Array(), $_mqtt_cnfg);
        $this->connectMqtt($mqtt_cnfg);

        // Get QoS
        $this->qos = self::arrayValue($mqtt_cnfg, 'qos', 1);

        // Parse and check all messages:
        // . check presence of S_TOPIC and S_PAYLOAD at first level
        // . call check_suscribe_topic for each topic
        $this->logger->info('check messages definition and subscribe topics');
        foreach ($this->messages as $key => &$value) {
            if ($key[0] != '#') {
                $this->logger->debug('-----');
                if (! array_key_exists(self::S_TOPIC, $value) || ! array_key_exists(self::S_PAYLOAD, $value)) {
                    $this->logErrorAndThrowException(
                        'missing key "' . self::S_TOPIC . '" or key "' . self::S_PAYLOAD . '" for topic "' . $key . '"');
                }
                $this->checkMessageAndSuscribe($key, $value);
            }
        }
        $this->logger->debug('===== configuration ends');
    }

    /**
     * Mosquitto callback called each time a subscribed topic is dispatched by the broker.
     * Process S_SET and S_GET commands.
     *
     * @param Mosquitto\Message $msg dispatched message
     */
    public function mosquittoMessage($msg) {
        $topic = $msg->topic;
        $pyld = $msg->payload;
        $this->logger->info('<- ' . $topic . ' ' . $pyld);

        if (array_key_exists($topic, $this->commands)) {
            if ($this->commands[$topic][0] == self::S_SET) {
                $val = json_decode($pyld);
                $this->commands[$topic][2][$this->commands[$topic][1]] = $val;
                $this->logger->info($this->commands[$topic][1] . ' set to ' . json_encode($val));
            }
            else if ($this->commands[$topic][0] == self::S_GET) {
                $this->generateAndPublish($this->commands[$topic][1], $this->commands[$topic][2], true);
            }
        }
    }

    /**
     * Process, publish and return the next message.
     * Only one message is sent.
     *
     * @return array published message (keys are S_TOPIC and S_PAYLOAD)
     */
    public function nextMessage() {
        $gen_data = null;

        do {
            // Current key of the message array
            $key = key($this->messages);

            // Skip comments
            if ($key[0] !== '#') {
                $value = $this->messages[$key];

                $once = array_key_exists(self::S_ONCE, $value) ? $value[self::S_ONCE] : false;

                if (($this->is_once_ended && ! $once) || (! $this->is_once_ended && $once)) {
                    $gen_data = $this->generateAndPublish($key, $this->messages[$key], false);
                }
            }

            // Advance the internal pointer of the messages array and
            // reset the pointer to the 1st element when array end is reached.
            if (next($this->messages) === false) {
                $this->is_once_ended = true;
                reset($this->messages);
            }
        } while ($gen_data == null);

        // To keep communications with the MQTT broker working
        $this->client->loop();

        return $gen_data;
    }

    /**
     * main function to be called for a standalone application.
     * Infinite loop sending messages separated by the delay specified in the configuration file.
     *
     * @param array $argv
     * @return int 0 if successfull, non zero in case of error
     */
    public static function main(array $argv) {
        if (count($argv) != 2) {
            print('usage: ' . $argv[0] . ' config_file' . PHP_EOL);
            return 1;
        }

        try {
            $mqttGen = new MqttGen($argv[1]);

            // Do the real job here: infinite loop to publish messages
            do {
                $t = microtime(true) + $mqttGen->time_interval;
                $mqttGen->nextMessage();
                if ($t > microtime(true))
                    time_sleep_until($t);
            } while (true);

            return 0;
        } catch (\Exception $e) {
            // throw $e;
            print($e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Connect to the MQTT broker using given configuration parameter
     *
     * @param array $mqtt_cnfg configuration parameters
     */
    protected function connectMqtt(array $mqtt_cnfg) {

        // Get mqtt section configuration parameters
        $host = self::arrayValue($mqtt_cnfg, "host", "localhost");
        $port = self::arrayValue($mqtt_cnfg, "port", 1883);
        $username = self::arrayValue($mqtt_cnfg, "username");
        $password = self::arrayValue($mqtt_cnfg, "password");
        $keepalive = self::arrayValue($mqtt_cnfg, "keepalive", 60);
        $will_topic = self::arrayValue($mqtt_cnfg, "willTopic", "");
        $will_msg = self::arrayValue($mqtt_cnfg, "willMessage", "");
        $will_qos = self::arrayValue($mqtt_cnfg, "willQoS", 0);
        $will_retain = self::arrayValue($mqtt_cnfg, "willRetain", False);

        $this->logger->debug("create the MQTT client");
        $this->client = new \Mosquitto\Client('mqttgen');

        // Set the callback function
        $this->client->onMessage(array($this,'mosquittoMessage'));

        // Set credential if defined
        if (isset($username) && $username != '') {
            $this->logger->info('setting credential for user ' . $username);
            $this->client->setCredentials($username, $password);
        }

        // Set will message if defined
        if ($will_msg != '') {
            $this->logger->info(
                "set will message: topic=" . $will_topic . ", payload=" . $will_msg . ", Qos=" . $will_qos . ", retain=" .
                $will_retain);
            $this->client->setWill($will_topic, $will_msg, $will_qos, $will_retain);
        }

        // Connect to the broker
        $this->logger->info('connect to the MQTT broker ' . $host . ':' . $port . ', keepalive=' . $keepalive);
        $this->client->connect($host, $port, $keepalive);
    }

    /**
     * Generate and publish the given message
     *
     * @param string $key JSON key of the given message structure
     * @param array $msg JSON array defining the message
     * @param bool $is_async true to send the message whatever the value of S_SYNC
     * @return array|null generated message (keys are S_TOPIC and S_PAYLOAD).
     *         null if nothing is published (sync is false)
     */
    protected function generateAndPublish(string $key, array &$msg, $is_async) {
        $gen_data = $this->generateValues($key, $msg);
        
        // Get retain and sync message parameters
        $retain = array_key_exists(self::S_RETAIN, $msg) ? $msg[self::S_RETAIN] : false;
        $sync = array_key_exists(self::S_SYNC, $msg) ? $msg[self::S_SYNC] : true;
        
        if ($sync || $is_async) {
            if (is_array($gen_data[self::S_PAYLOAD]))
                $gen_data[self::S_PAYLOAD] = json_encode($gen_data[self::S_PAYLOAD]);
                $this->logger->info('-> ' . $gen_data[self::S_TOPIC] . ' ' . $gen_data[self::S_PAYLOAD]);
                $this->client->publish($gen_data[self::S_TOPIC], $gen_data[self::S_PAYLOAD], $this->qos, $retain);
        }
        else
            $gen_data = null;
            
            $this->logger->debug('-----');
            
            return $gen_data;
    }

    /**
     * Parse the JSON structure and replace function definition by their return values.
     * Function definition are defined by a JSON array having FUN and PAR keys.
     * This function is called recursively to support multi level JSON structures.
     *
     * @param $key string
     *            JSON key of the given data structure
     * @param $data array
     *            JSON data array or value
     * @return array generated data
     */
    protected function generateValues($key, &$data) {
        $typ = gettype($data);
        $this->logger->debug('treating key "' . $key . '", type is ' . $typ);

        $gen_data = $data;
        if ($typ == 'array') {
            $this->logger->debug('keys are: ' . implode(', ', array_keys($data)));

            if (array_key_exists(self::S_FUN, $data) && array_key_exists(self::S_PAR, $data)) {
                $this->logger->debug('function: ' . $data[self::S_FUN]);
                $this->logger->debug('param bef: ' . json_encode($data[self::S_PAR]));
                // TIP: param array is passed by reference to have it updated
                $gen_data = call_user_func_array(__NAMESPACE__ . '\\MqttGenFunc::' . $data[self::S_FUN],
                    array(&$data[self::S_PAR]));
                $this->logger->debug('param aft: ' . json_encode($data[self::S_PAR]));
            }
            else {
                // TIP: values shall be iterated by reference to have function param array updated
                foreach ($data as $i => &$value) {
                    if ($i[0] === '#')
                        unset($gen_data[$i]);
                    else {
                        $gen_data[$i] = $this->generateValues($i, $value);
                    }
                }
            }
        }

        return $gen_data;
    }

    /**
     * Check MQTT topics are only defined once
     *
     * @param string $topic topic to be checked
     * @throw Exception if topic already exists
     */
    protected function checkTopicUnicity(string $topic) {
        if (in_array($topic, $this->mqtt_topics))
            throw new \Exception('topic "' + topic + '" is defined several times');
        else
            array_push($this->mqtt_topics, $topic);
    }

    /**
     * Check message definition and subscribe to get and set related topics
     *
     * @param string $key key of the given data structure
     * @param array $data JSON data array or value
     */
    protected function checkMessageAndSuscribe(string $key, &$data) {
        $typ = gettype($data);
        $this->logger->debug('treating key "' . $key . '", type is ' . $typ);

        // Loop on sub-data of the current ori_data. When it is an array:
        // . check topic unicity
        // . if a get key is found, subscribe to the related topic
        // . if a set key is found, check the value exists and subscribe to the related topic
        // . call the current function recursively for the sub-data
        if ($typ == 'array') {

            $this->logger->debug('keys are: ' . implode(', ', array_keys($data)));

            foreach ($data as $i => &$value) {
                if ($i === self::S_TOPIC) {
                    // $this->checkTopicUnicity($value);
                }
                else if ($i === self::S_GET) {
                    $this->checkTopicUnicity($value);
                    $this->commands[$value] = array(self::S_GET,$key,$data);
                    $this->logger->info('suscribing to ' . $value);
                    $this->client->subscribe($value, 1); // QoS=1
                }
                else if (substr($i, 0, strlen(self::S_SET)) == self::S_SET) {
                    $s = substr($i, strlen(self::S_SET));
                    $this->checkTopicUnicity($s);
                    if (! array_key_exists(self::S_PAR, $data) || ! array_key_exists($value, $data[self::S_PAR]))
                        $this->logErrorAndThrowException(
                            'key "' . (self::S_PAR) . '", or element "' . (self::S_PAR) . '[' . $value .
                            ']" not found in element ' . $key);
                    $this->commands[$s] = array(self::S_SET,$value,&$data[self::S_PAR]);
                    $this->logger->info('suscribing to ' . $s);
                    $this->client->subscribe($s, 1); // QoS=1
                }

                $this->checkMessageAndSuscribe($i, $value);
            }
        }
    }

    /**
     * Log the given message as an error and throw an exception
     */
    protected function logErrorAndThrowException(string $msg) {
        $this->logger->error($msg);
        throw new \Exception($msg);
    }

    /**
     * Return the requested array value if existing, or the given default value
     */
    protected static function arrayValue($array, $key, $default_value = null) {
        return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default_value;
    }

    /**
     * Throw an Exception if json_last_error() - see PHP doc - returns an error.
     * Message attached to the Exception gives the JSON error.
     */
    protected static function throwJsonLastErrorException(string $filename) {
        switch (json_last_error()) {
            default:
                return;
            case JSON_ERROR_DEPTH:
                $error = 'Maximum stack depth exceeded';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $error = 'Underflow or the modes mismatch';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $error = 'Unexpected control character found';
                break;
            case JSON_ERROR_SYNTAX:
                $error = 'Syntax error, malformed JSON';
                break;
            case JSON_ERROR_UTF8:
                $error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
        }
        throw new \Exception($error . ' in file ' . $filename);
    }
}

?>
