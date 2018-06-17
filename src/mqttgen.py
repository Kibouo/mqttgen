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
import socket
from datetime import datetime
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
S_SET='set:'        # trailing string to define set command topics mqttgen shall subscribe to
S_GET='get'         # get key
S_SYNC = 'sync'     # synchronous boolean status. If not present, true is assumed.
S_ONCE = 'once'     # once boolean status. If true payload is sent once after connexion.
                    # false assumed if not present

#####
##### Global variables
#####################################################################################
_commands_data = {}
_all_topics = []

##### Functions callable in the JSON configuration file
#####################################################################################

def date(param):
    d = datetime.now()
    return d.strftime(param['fmt'])

def nextChoice(param):
    if 'last' in param:
        if param['last'] == len(param['list'])-1:
            param['last'] = 0
        else:
            param['last'] = param['last'] + 1
    else:
        param['last'] = 0
    return param['list'][param['last']]

def randChoice(param):
    return random.choice(param)

def randWalk(param):
    param['cur'] = param['cur'] + random.uniform(-param['rand'], param['rand'])
    if 'max' in param and param['cur'] > param['max']:
        param['cur'] = param['max']
    elif 'min' in param and param['cur'] < param['min']:
        param['cur'] = param['min']
    return round(param['cur'], 2)

def triangleWave(param):
    param['cur'] = param['cur'] + param['delta']
    if param['cur'] > param['max']:
        param['cur'] = param['max']
        param['delta'] = -param['delta']
    elif param['cur'] < param['min']:
        param['cur'] = param['min']
        param['delta'] = -param['delta']
    return round(param['cur'], 6)

def randUniform(param):
    return round(random.uniform(param['min'], param['max']), 2)

def randGauss(param):
    return round(random.gauss(param['mean'], param['sigma']), 2)

def constant(param):
    return param['cur']

def linear(param):
    param['cur'] = param['cur'] + param['delta']
    return param['cur']

#####################################################################################

# Parse the JSON structure and replace function definition by their return values.
# Function definition are defined by a JSON dictionnary having FUN and PAR keys.
# This function is called recursively to support multi level JSON structures.
#
# @param string key JSON key of the given payload structure
# @param structure ori_data original JSON payload structure
# @param stucture gen_data generated JSON payload structure
# @return gen_data
def generate_values(key, ori_data, gen_data):
    typ = type(ori_data)
    logging.debug('Treating key "' + str(key) + '", type is ' + str(typ))
    if typ == OrderedDict:
        keys = ori_data.keys()
        logging.debug('Keys are: ' + json.dumps(keys))
        if S_FUN in keys and S_PAR in keys:
            gen_data = globals()[ori_data[S_FUN]](ori_data[S_PAR])
        else:
            for i in ori_data:
                if i == '#':
                    del gen_data[i]
                else:
                    gen_data[i] = generate_values(i, ori_data[i], gen_data[i])

    if typ == list:
        for i,v in enumerate(ori_data):
             gen_data[i] = generate_values(i, ori_data[i], gen_data[i])

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
    keepalive = param.get("keepalive", 60)
    willTopic = param.get("willTopic", "")
    willMessage = param.get("willMessage", "")
    willQoS = param.get("willQoS", 0)
    willRetain = param.get("willRetain", False)

    logging.debug("create the MQTT client")
    client = mqtt.Client()

    # Set callback function
    client.on_message = do_on_message

    # Set credential if defined
    if username:
        logging.debug("set credential for username=" + username)
        client.username_pw_set(username, password)

    # Set will message if defined
    if willMessage != "":
        logging.info("set will message: topic=" + willTopic + ", payload=" + willMessage + ", Qos=" + str(willQoS) +
                     ", retain=" + str(willRetain))
        client.will_set(willTopic, willMessage, willQoS, willRetain)

    # Connect to the broker
    logging.info("connect to the MQTT broker (" + host + ")")
    client.connect(host, port=port, keepalive=keepalive)

    # Start the loop
    client.loop_start()

    return client

#
# MQTT callback on message reception
def do_on_message(client, userdata, message):
    global _commands_data

    topic = message.topic
    #topicArray = topic.split('/')
    msg = str(message.payload.decode("utf-8"))
    logging.info("<- " + topic + " " + msg)

    if topic in _commands_data:
        if _commands_data[topic][0] == S_SET:
            try:
                val = int(msg)
            except:
                try:
                    val = float(msg)
                except:
                    val = json.loads(msg)

            _commands_data[topic][2][_commands_data[topic][1]] = val
            logging.info(_commands_data[topic][1] + " set to " + str(val))

        elif _commands_data[topic][0] == S_GET:
            generate_and_publish(client, _commands_data[topic][1], _commands_data[topic][2])

    else:
        logging.warning("unknown command " + topic)


#
# Generate and publish data for a given topic
def generate_and_publish(client, key, topic):
    logging.debug('-----')
    gen_topic = generate_values(key, topic, copy.deepcopy(topic))
    pl_dump = gen_topic[S_PAYLOAD]
    if type(pl_dump) != unicode:
        pl_dump = json.dumps(pl_dump) #, indent=3)
    logging.info('-> ' + gen_topic[S_TOPIC] + ' ' + pl_dump)
    client.publish(gen_topic[S_TOPIC], pl_dump)

#
# Check topic definition and subscribe to get and set related topics
# @param object client MQTT client
# @param string key key of the given ori_data structure
# @param structure ori_data original JSON payload structure
#
def check_subscribe_topic(client, key, ori_data):
    global _commands_data

    # Check topics are only defined once
    def check_topic(topic):
        global _all_topics
        if topic in _all_topics:
            raise ValueError('Topic "' + topic + '" is defined several times.')
        else:
            _all_topics.append(topic)

    typ = type(ori_data)
    logging.debug('Treating key "' + str(key) + '", type is ' + str(typ))

    # The current topic is of type dictionnary
    if typ == OrderedDict:
        keys = ori_data.keys()
        logging.debug('Keys are: ' + json.dumps(keys))

        # Loop on sub-data of the current ori_data
        #    . check topic unicity
        #    . if a get key is found, subscribe to the related topic
        #    . if a set key is found, check the value exists and subscribe to the related topic
        #    . call the current function recursively for the sub-data
        for i in ori_data:
            if i == S_TOPIC:
                check_topic(ori_data[i])

            if i == S_GET:
                check_topic(ori_data[i])
                _commands_data[ori_data[i]] = [ S_GET, key, ori_data ]
                logging.info('Suscribing to ' + ori_data[i])
                client.subscribe(ori_data[i])

            if i[0:len(S_SET)] == S_SET:
                s = i[len(S_SET):]
                check_topic(s)
                if ori_data[i] not in keys:
                    raise ValueError('Key "' + ori_data[i]  + '" not found in element "' + str(key) + '".')
                _commands_data[s] = [ S_SET, ori_data[i], ori_data ]
                logging.info('Suscribing to ' + s)
                client.subscribe(s)

            check_subscribe_topic(client, i, ori_data[i])

    # The current topic is of type list
    # Loop on elements of the list and call the current function recursively
    if typ == list:
        for i,v in enumerate(ori_data):
            check_subscribe_topic(client, i, ori_data[i])

    return

#####
##### MAIN
#####################################################################################

def main(file):

    #
    # Read the input file
    # Configure logging
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
            time_interval = misc_config.get('time_interval', 1)

            # Configure logging
            logging.basicConfig(filename=logging_file,
                                format='%(asctime)s %(process)5d %(levelname)-8s %(message)s',
                                level=logging_level)
            logging.getLogger("paho.mqtt").setLevel(logging.WARNING)

            if not topics:
                logging.error("No key '" + S_TOPICS + "' specified in config, nothing to do")
                return
    except IOError as err:
        logging.error("Error opening config file '%s'" % file)
        return
    except ValueError as err:
        logging.error('Parsing error in file %s' % file)
        logging.error(err)
        return


    # Parse all topics:
    try:
        # Connect the client
        client = connect_mqtt(mqtt_config)

        # Parse and check all topics:
        #   . check presence of S_TOPIC and S_PAYLOAD at first level
        #   . call check_suscribe_topic for each topic
        logging.info('check topics definition and subscribe topics')
        for i in topics:
            if i[0] != '#':
                keys = topics[i].keys()
                if not S_TOPIC in keys or not S_PAYLOAD in keys:
                    raise ValueError("Missing key '%s' or key '%s' for topic '%s'" % (S_TOPIC, S_PAYLOAD, i))
                check_subscribe_topic(client, i, topics[i])

    except ValueError as err:
        logging.error('content error in file %s' % file)
        logging.error(err)
        return
    except socket.error as err:
        logging.error('cannot connect to the MQTT broker')
        logging.error(err)
        return

    # Publish only once messages
    for i in topics:
        if i[0] != '#':
            once = False
            if S_ONCE in topics[i].keys():
                once = topics[i][S_ONCE]

            if once:
                generate_and_publish(client, i, topics[i])
                time.sleep(time_interval)

    # Do the real job here: infinite loop to publish topics
    try:
        while True:
            for i in topics:
                if i[0] != '#':
                    sync = True
                    if S_SYNC in topics[i].keys():
                        sync = topics[i][S_SYNC]

                    once = False
                    if S_ONCE in topics[i].keys():
                        once = topics[i][S_ONCE]

                    if sync and not once:
                        generate_and_publish(client, i, topics[i])
                        time.sleep(time_interval)

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
