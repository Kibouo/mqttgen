#!/usr/bin/python
# coding: utf-8
# Inspired from Mariano Guerra's mqttgen.py generator:
# (https://gist.github.com/marianoguerra/be216a581ef7bc23673f501fdea0e15a)
import paho.mqtt.client as mqtt
import json
import copy
import types
import random
import logging
import sys
import time
from  collections import OrderedDict

#####
##### Constants defining keys in the iput JSON file
#####################################################################################
S_MQTT='mqtt'       # mqtt section
S_MISC='misc'       # miscellaneous section
S_TOPICS='topics'   # topics definition section
S_FUN='func'        # function name
S_PAR='param'       # parameters associated to the function
S_TOPIC='topic'     # topic name
S_PAYLOAD='payload' # payload definition

#####
##### Global variables
#####################################################################################
_commands_data = {}


##### Functions callable in the JSON configuration file
#####################################################################################

def randChoice(param):
    return random.choice(param)

def randWalk(param):
    param['cur'] = param['cur'] + random.uniform(-param['rand'], param['rand'])
    return round(param['cur'], 1)

def constant(param):
    return param['cur']

#####################################################################################

# Parse the JSON structure and replace function definition by their return values.
# Function definition are defined by a JSON dictionnary having FUN and PAR keys.
# This function is called recursively to support multi level JSON structures.
#
# @param OrderedDict ori_data original JSON payload structure
# @parma OrderedDict gen_data generated JSON payload structure
# @return gen_data
def generate_values(ori_data, gen_data):
    if type(ori_data) == OrderedDict:
        keys = ori_data.keys()
        if S_FUN in keys and S_PAR in keys:
            gen_data = globals()[ori_data[S_FUN]](ori_data[S_PAR])
        else:
            for key in ori_data:
                gen_data[key] = generate_values(ori_data[key], gen_data[key])
        
    return gen_data


# Create an MQTT client, connect to the broker and start the loop
# @param Dict param MQTT parameters as read in the configuration file
# @return mqtt client
def connect_mqtt(param):
    # Get mqtt section configuration parameters
    host = param.get("host", "localhost")
    port = param.get("port", 1883)
    username = param.get("username")
    password = param.get("password")

    logging.debug("create an MQTT client")
    client = mqtt.Client()

    # Set callback function
    client.on_message = do_on_message

    if username:
        logging.debug("set credential for username=" + username)
        client.username_pw_set(username, password)

    logging.debug("connecting to broker")
    client.connect(host, port=port)

    # Start the loop       
    client.loop_start()

    return client


# MQTT callback on message reception
def do_on_message(client, userdata, message):
    return    

# Generate and publish data for a given topic
def generate_and_publish(client, topic):
    gen_topic = generate_values(topic, copy.deepcopy(topic))
    logging.info('-> ' + gen_topic[S_TOPIC] + ' ' + json.dumps(gen_topic[S_PAYLOAD]))
    client.publish(gen_topic[S_TOPIC], gen_topic[S_PAYLOAD])
    

def check_and_subscribe_topic(client, topic):
    return
    
#####
##### MAIN
#####################################################################################

def main(file):
    try:
        with open(file) as handle:
            # Load the file in the file defined order
            config = json.load(handle, object_pairs_hook=OrderedDict)

            # Extract first level container
            mqtt_config = config.get(S_MQTT, {})
            misc_config = config.get(S_MISC, {})
            topics = config.get(S_TOPICS)

            # Get miscellaneous section configuration parameters
            logging_level = misc_config.get('logging_level', 'info')
            logging_file = misc_config.get('logging_file', '')
            sleep_dur = misc_config.get('sleep_s', 1)
            
            # Configure logging
            logging.basicConfig(filename=logging_file,
                                format='%(asctime)s %(process)5d %(levelname)-8s %(message)s',
                                level=logging_level)
            logging.getLogger("paho.mqtt").setLevel(logging.WARNING)

            if not topics:
                logging.error("no topics specified in config, nothing to do")
                return
            
    except IOError as error:
        logging.error("error opening config file '%s'" % file)
        return
    except ValueError as error:
        logging.error('parsing error in file %s' % file)
        logging.error(error)
        return

    # Connect to the client
    client = connect_mqtt(mqtt_config)

    # Parse all topics:
    #   . check presence of S_TOPIC and S_PAYLOAD at first level
    logging.info('check topics definition and subscribe topics')
    for i in topics:
        keys = topics[i].keys()
        if not S_TOPIC in keys or not S_PAYLOAD in keys:
            logging.error('content error in file %s' % file)
            logging.error("missing key '%s' or key '%s' for topic '%s'" % (S_TOPIC, S_PAYLOAD, i))
            return

    try:
        while True:
            for i in topics:
                logging.debug('-----')
                logging.debug('treating topic ' + topics[i][S_TOPIC])
                generate_and_publish(client, topics[i])
                time.sleep(sleep_dur)
    except KeyboardInterrupt:
        logging.info('ending mqttgen execution')
        client.disconnect()

#####
##### SCRIPT ENTRY POINT
#####################################################################################
if __name__ == '__main__':
    if len(sys.argv) == 2:
        main(sys.argv[1])
    else:
        print("usage: %s config_file" % sys.argv[0])
        
