<?php

namespace MqttGen;

/**
 * Class containing all the functions that can be used in the payload section
 * of the JSON input file.
 *
 * Each function shall take the parameter array as input, passed by reference, and
 * return the computed value.
 */
class MqttGenFunc {

    static public function date(array &$param) {
		return \date($param['fmt']);
    }

    static public function nextChoice(array &$param) {
		if (array_key_exists('last', $param)) {
			if ($param['last'] === count($param['list'])-1)
				$param['last'] = 0;
			else
				$param['last'] = $param['last'] + 1;
		}
		else
			$param['last'] = 0;

		return $param['list'][$param['last']];
    }

    static public function randChoice(array &$param) {
        return $param[array_rand($param)];
    }

    static public function randWalk(array &$param) {
		$param['cur'] += self::random(-$param['rand'], $param['rand']);

		if (array_key_exists('max', $param) && $param['cur'] > $param['max'])
			$param['cur'] = param['max'];
		else if (array_key_exists('min', $param) && $param['cur'] < $param['min'])
			$param['cur'] = $param['min'];

		return round($param['cur'], 6);
    }

    static public function triangleWave(array &$param) {
		$param['cur'] = $param['cur'] + $param['delta'];
		if ($param['cur'] > $param['max']) {
			$param['cur'] = $param['max'];
			$param['delta'] = -$param['delta'];
		}
		else if ($param['cur'] < $param['min']) {
			$param['cur'] = $param['min'];
			$param['delta'] = -$param['delta'];
		}
		return round($param['cur'], 6);
    }

    static public function randUniform(array &$param) {
		$r = self::random($param['min'], $param['max']);
		return round($r, 2);
    }

    static public function randGauss(array &$param) {
		return round(self::gauss_ms($param['mean'], $param['sigma']), 2);
    }

    static public function constant(array &$param) {
		return $param['cur'];
    }

    static public function linear(array &$param) {
		$param['cur'] = $param['cur'] + $param['delta'];
		return $param['cur'];
    }


    ###
    ### Internal utility function below
    ###

    /**
     * Returns a uniform random float value comprised between min and max
     */
    static protected function random(float $min, float $max) {
		return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    /**
     * Returns random number with normal distribution: mean=0, std dev=1
     */
    static protected function gauss() {

		// auxilary vars
		$x = self::random(0.0, 1.0);
		$y = self::random(0.0, 1.0);

		// two independent variables with normal distribution N(0,1)
		$u = sqrt(-2*log($x))*cos(2*pi()*$y);
		//$v = sqrt(-2*log($x))*sin(2*pi()*$y);

		// i will return only one, couse only one needed
		return $u;
    }

    /**
     * returns random number with normal distribution:
     * mean=m
     * std dev=s
     */
    static protected function gauss_ms($m=0.0, $s=1.0) {
		return self::gauss()*$s + $m;
    }
}
