<?php
namespace MqttPlay;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class MqttPlay {

    // parameters associated to the function
    const S_TOPIC = 'topic';
    
    // topic name
    const S_PAYLOAD = 'payload';

    /**
     * @var string time key
     */
    const S_TIME = 'time';
    
    /**
     * @var array data array read from the input file
     */
    private $data = array();
    
    /**
     * @var \Mosquitto\Client client
     */
    private $client;
    
    /**
     * @var int QoS
     */
    private $qos;
    
    /**
     * @var float time at simulation start (unix timestamp with microseconds)
     */
    private $t0;
       
    /**
     * @var boolean whether or not the simulation shall be time representative
     */
    private $is_time_representative;
    
    /**
     * @param string $filename MQTT flow file
     * @param bool $is_time_representative whether or not the simulation shall be time representative
     * @param string $delimiter flow file column separator (' ' by default)
     * @param string $host MQTT broker host (localhost by default)
     * @param int $port MQTT broker port (1883 by default)
     * @param int $qos MQTT publication QoS (1 by default) 
     * @throws \Exception in case of file reading the input file
     */
    function __construct(string $filename, bool $is_time_representative=true, string $delimiter =' ', string $host='localhost', int $port=1883, int $qos=1) {
        
        $this->is_time_representative = $is_time_representative;
        $this->qos = $qos;
        
        //
        // Read input file
        //
        if (! file_exists($filename))
            throw new \Exception('File ' . $filename . ' does not exist');
            
        $fp = fopen($filename, "r");
        if ($fp === false)
            throw new \Exception('Read error in file  ' . $filename);
        
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            
            if (count($cols = explode($delimiter, $line, 3)) == 3) {
                $cols[0] = new \DateTime($cols[0]);
                $this->data[] = $cols;
            }
        }
        fclose($fp);
        
        //
        // Configure the broker
        //
        $this->client = new \Mosquitto\Client('mqttplay');
        $this->client->connect($host, $port);
    }
    

    /**
     * Process, publish and return the current message.
     * Move the pointer to the next iteration if $advance_pointer is true.
     * Only one message is published.
     *
     * @param bool $advance_pointer whether or not pointer shall be advance (true by default)
     * @return array|null published message (keys are S_TIME, S_TOPIC and S_PAYLOAD), null if work is ended
     *                    S_TIME is the elapsed time in s since the first message
     */
    public function nextMessage($advance_pointer=true) {
        $msg = null;
        
        if ($this->is_time_representative && !isset($this->t0)) {
            $this->t0 = microtime(true);
        }

        $cur = current($this->data);
        //$last = end($this->data);
        
        // Check if work was ended
        if ($cur === false) {
            return null;
        }
        
        if ($this->is_time_representative) {
            $delta = $this->data[0][0]->diff($cur[0]);
            $s = $delta->f + $delta->s + + ($delta->i * 60) + ($delta->h * 60 * 60) + ($delta->d * 60 * 60 * 24) +
                ($delta->m * 60 * 60 * 24 * 30) + ($delta->y * 60 * 60 * 24 * 365);
            $t = $this->t0 + $s;
            if ($t > microtime(true)) {
                time_sleep_until($t);
            }
        }
        
        $this->client->publish($cur[1], $cur[2], $this->qos, false);
        $this->client->loop();
        
        // Advance the array pointer for the next iteration
        if ($advance_pointer)
            next($this->data);
        
        return array(self::S_TIME => $s, self::S_TOPIC => $cur[1], self::S_PAYLOAD => $cur[2]);
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
            print('usage: ' . $argv[0] . ' input_file' . PHP_EOL);
            return 1;
        }
        
        try {
            $mqttPlay = new MqttPlay($argv[1]);
                       
            // Do the real job here: infinite loop to publish messages
            while (($msg = $mqttPlay->nextMessage()) != null) {
                print($msg[self::S_TIME] . " " . $msg[self::S_TOPIC] . " " . $msg[self::S_PAYLOAD] . PHP_EOL);
            }
        } catch (\Exception $e) {
            print($e->getMessage() . PHP_EOL);
            if (isset($msg))
                print_r($msg);
            return 1;
        }
        
        echo 'Replay ended' . PHP_EOL;
        return 0;
    }
}