<?php

namespace MqttGen;

class MqttGen {


	public static function main() {
		if (count($_SERVER['argv']) != 2 ) {
			print('usage: ' . $_SERVER['argv'][0] . ' config_file');
			exit(1);
		}
	}
}
