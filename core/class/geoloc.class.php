<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
if (!class_exists('FindMyiPhone')) {
	require_once dirname(__FILE__) . '/../../3rdparty/class.findmyiphone.php';
}

class geoloc extends eqLogic {
	/*	 * *************************Attributs****************************** */
	/* 200$ free credit each month, Direction API = 5$ for 1000 request
	  so, 40000 per month, 1000 per day */
	const MAX_GOOGLE_REQUEST_PER_DAY = 1000;

	public static $_widgetPossibility = array('custom' => true);

	/*	 * ***********************Methode static*************************** */

	public static function start() {
		foreach (eqLogic::byType('geoloc', true) as $geoloc) {
			foreach ($geoloc->getCmd('info') as $geoloccmd) {
				if ($geoloccmd->getConfiguration('mode') == 'fixe') {
					$geoloccmd->event($geoloccmd->getConfiguration('coordinate'));
				}
			}
		}
	}

	public static function cron5($_eqlogic_id = null) {
		$eqLogics = ($_eqlogic_id !== null) ? array(eqLogic::byId($_eqlogic_id)) : eqLogic::byType('geoloc', true);
		foreach ($eqLogics as $geoloc) {
			if (is_object($geoloc)) {
				// For apple we update the position based on iCloud information
				if ($geoloc->getConfiguration('isIos','') == 1) {
					$geoloc_xml = $geoloc->getLocation();
					foreach ($geoloc->getCmd('info') as $cmd) {
						if($cmd->getConfiguration('mode') == 'dynamic') {
							$cmd->event($geoloc_xml);
						}
					}
				}
				if ($_eqlogic_id != null || $geoloc->isCronRefreshEnable()) {
					// we update the fields (distance, travelDistance, travelTime)
					// every 5 minutes, query google if configure to do so.
					foreach ($geoloc->getCmd('info') as $cmd) {
						if($cmd->getConfiguration('mode') == 'distance'
							|| $cmd->getConfiguration('mode') == 'travelDistance'
					 		|| $cmd->getConfiguration('mode') == 'travelTime') {
								$cmd->event($cmd->execute());
						}
					}
				}
			}
		}
	}

	/*
     * Fonction exécutée automatiquement tous les jours par Jeedom
     */
    public static function cronDaily() {
        // Reset the daily quota
        self::resetGoogleRequestQuota();
	}

	public static function getDevicesListIos($_id, $_username, $_password) {

		try {
			$fmi = new FindMyiPhone($_username, $_password);
		} catch (Exception $e) {
			print "Error: ".$e->getMessage();
			exit;
		}
		$devicelist= array() ;
		$i=0;
		if (sizeof($fmi->devices) == 0) $fmi->getDevices();
		foreach($fmi->devices as $device){
			$devicelist['devices'][$i]['name']=$device->name;
			$devicelist['devices'][$i]['id']=$device->ID;
			$devicelist['devices'][$i]['deviceClass']=$device->class;
			$i++;
		}

		return $devicelist;

	}

	// We keep a count for the plugin, not per eqLogic for the google quota.
	// we also have a per eqLogic indicator to know if you should use cron
	// to refresh data automatically
    // 'dayUsage', 'dayLimit'
    public static function isGoogleRequestUnderQuota() {
        $plugin = plugin::byId(__CLASS__);
		$cache  = cache::byKey('eqLogicCacheAttr' . $plugin->getId())->getValue();
		$value  = utils::getJsonAttr($cache, 'googleRequest', 0);
		log::add('geoloc', 'debug', 'isGoogleRequestUnderQuota --> req/quota=' . ($value) . '/' . self::MAX_GOOGLE_REQUEST_PER_DAY);
		return ($value <= self::MAX_GOOGLE_REQUEST_PER_DAY);
	}

    public static function addGoogleRequestQuota() {
		$plugin = plugin::byId(__CLASS__);
		$cache  = cache::byKey('eqLogicCacheAttr' . $plugin->getId())->getValue();
		$value  = utils::getJsonAttr($cache, 'googleRequest', 0);
        cache::set('eqLogicCacheAttr' . $plugin->getId(),
            		utils::setJsonAttr($cache, 'googleRequest', ($value + 1)));
    }

	private static function resetGoogleRequestQuota() {
		$plugin = plugin::byId(__CLASS__);
		$cache  = cache::byKey('eqLogicCacheAttr' . $plugin->getId())->getValue();
		$value  = utils::getJsonAttr($cache, 'googleRequest', 0);
		log::add('geoloc', 'info', 'number of google requests done yesterday: ' . $value . ', quota=' . self::MAX_GOOGLE_REQUEST_PER_DAY);
        cache::set('eqLogicCacheAttr' . $plugin->getId(),
            		utils::setJsonAttr($cache, 'googleRequest', 0));
    }

	private function isCronRefreshEnable() {
		return ($this->getConfiguration('cronRefresh', 1) == 1);
	}

	public function getGoogleRequestInterval() {
		return $this->getConfiguration('refreshInterval', 300);
	}


	/*	 * *********************Methode d'instance************************* */

	public function toHtml($_version = 'dashboard') {
		if ($this->getConfiguration('noSpecifyWidget', 0) == 1) {
			return parent::toHtml($_version);
		}
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		$replace['#cmd#'] = '';
		$version = jeedom::versionAlias($_version);
		$maps = array();
		$dynamic = array();
		$cmd_html = '';
		if ($this->getIsEnable()) {
			foreach ($this->getCmd('info', null, true) as $cmd) {
				if ($cmd->getConfiguration('mode') == 'travelTime') {
					$from = $cmd->getConfiguration('from');
					$to = $cmd->getConfiguration('to');
					if (!isset($maps[$from . '_' . $to])) {
						$maps[$from . '_' . $to] = array();
					}
					$maps[$from . '_' . $to]['travelTime'] = $cmd->execCmd();
				}
				if ($cmd->getConfiguration('mode') == 'distance') {
					$from = $cmd->getConfiguration('from');
					$to = $cmd->getConfiguration('to');
					if (!isset($maps[$from . '_' . $to])) {
						$maps[$from . '_' . $to] = array();
					}
					$maps[$from . '_' . $to]['distance'] = $cmd->execCmd();
				}
				if ($cmd->getConfiguration('mode') == 'travelDistance') {
					$from = $cmd->getConfiguration('from');
					$to = $cmd->getConfiguration('to');
					if (!isset($maps[$from . '_' . $to])) {
						$maps[$from . '_' . $to] = array();
					}
					$maps[$from . '_' . $to]['travelDistance'] = $cmd->execCmd();
				}
				if ($cmd->getConfiguration('mode') == 'dynamic') {
					$dynamic[$cmd->getId()] = $cmd;
				}
			}
		}

		foreach ($maps as $key => $map) {
			$key = explode('_', $key);
			if (count($key) != 2) {
				continue;
			}
			foreach ($dynamic as $id => $cmd) {
				if (in_array($id, $key)) {
					unset($dynamic[$id]);
				}
			}
			$from_cmd = cmd::byId($key[0]);
			$to_cmd = cmd::byId($key[1]);
			if (!is_object($from_cmd) || !is_object($to_cmd)) {
				continue;
			}
			$from = $from_cmd->execCmd();
			$to = $to_cmd->execCmd();
			$replaceCmd = array(
				'#name_display#' => $from_cmd->getName() . ' <i class="fa fa-arrow-right"></i> ' . $to_cmd->getName(),
				'#from#' => $from,
				'#collectDate#' => ($from_cmd->getCollectDate() > $to_cmd->getCollectDate()) ? $from_cmd->getCollectDate() : $to_cmd->getCollectDate(),
				'#to#' => $to,
				'#travelDistance#' => (isset($map['travelDistance'])) ? $map['travelDistance'] : __('Inconnue', __FILE__),
				'#distance#' => (isset($map['distance'])) ? $map['distance'] : __('Inconnue', __FILE__),
				'#travelTime#' => (isset($map['travelTime'])) ? $map['travelTime'] : __('Inconnue', __FILE__),
			);
			$replace['#cmd#'] .= template_replace($replaceCmd, getTemplate('core', $version, 'geoloc', 'geoloc'));
		}

		foreach ($dynamic as $id => $cmd) {
			$replaceCmd = array(
				'#state#' => $cmd->execCmd(),
				'#name#' => $cmd->getName(),
				'#collectDate#' => $cmd->getCollectDate(),
				'#id#' => $cmd->getId(),
			);
			$replace['#cmd#'] .= template_replace($replaceCmd, getTemplate('core', $version, 'geoloc_single', 'geoloc'));
		}
		$replace['#max_width#'] = '650px';
		return template_replace($replace, getTemplate('core', $version, 'eqLogic'));
	}

	public function getLocation() {

		if ($this->getConfiguration('username') == '' || $this->getConfiguration('password') == '') {
			return false;
		}
			try {
				$fmi = new FindMyiPhone($this->getConfiguration('username'), $this->getConfiguration('password'));
				$location = $fmi->locate($this->getConfiguration('device'));
				$geoloc=$location->latitude.','.$location->longitude;
			} catch (Exception $e) {
				print "Error: ".$e->getMessage();
				exit;
			}

		foreach ($this->getCmd('info') as $cmd) {
			if($cmd->getConfiguration('mode') == 'dynamic'){
				$cmd->event($geoloc);
			}else{
				$cmd->event($cmd->execCmd());
			}
		}
		foreach ($this->getCmd('info') as $cmd) {
			if($cmd->getConfiguration('mode') == 'distance'){
				$cmd->event($cmd->execCmd());
			}
		}
		return $geoloc;
	}

	 public function postSave() {
		$refreshCmd = $this->getCmd(null, 'refresh');
		if (!is_object($refreshCmd)) {
			$refreshCmd = new geolocCmd();
			$refreshCmd->setName(__('Rafraichir', __FILE__));
			$refreshCmd->setIsVisible(0);
		}
		$refreshCmd->setEqLogic_id($this->getId());
		$refreshCmd->setLogicalId('refresh');
		$refreshCmd->setType('action');
		$refreshCmd->setSubType('other');
		$refreshCmd->save();
	}
}

class geolocCmd extends cmd {
	/*	 * *************************Attributs****************************** */

	/*	 * ***********************Methode static*************************** */
	function get_driving_information($start, $finish, $highways = true) {
		if (strcmp($start, $finish) == 0) {
			return array('distance' => 0, 'time' => 0);
		}
        // BR>> begin
        $eqLogic      = $this->getEqLogic();
        $current_time = time();
        $last     = array('lasttime' => $eqLogic->getCache('lasttime'),
                          'time'     => $eqLogic->getCache('time'),
                          'distance' => $eqLogic->getCache('distance'),
                          'start'    => $eqLogic->getCache('start'),
                          'finish'   => $eqLogic->getCache('finish'));
        //  array('lasttime', 'time', 'distance', 'start', 'finish'));
        //log::add('geoloc', 'debug', 'BR>> cached data (input)' . print_r($last, true));
        if (isset($last['lasttime']) and isset($last['time']) and isset($last['distance']) and isset($last['start']) and isset($last['finish'])) {
           if ($start == $last['start'] and $finish == $last['finish']) {
               log::add('geoloc', 'debug', 'Using cache (same position): start=' . $start . ', finish=' . $finish);
			   return array('distance' => $last['distance'], 'time' => $last['time']);
           }
           $delta = $current_time - (int) $last['lasttime'];
		   $refreshGoogle = $eqLogic->getGoogleRequestInterval();
          // BR>> we refresh every hour during our test
           if ($delta < $refreshGoogle) {
               log::add('geoloc', 'debug', 'Using cache (less than ' . $refreshGoogle . ': current_time=' . $current_time . ', lasttime=' . $last['lasttime'] . ', delta=' . $delta);
			   return array('distance' => $last['distance'], 'time' => $last['time']);
           }
           log::add('geoloc', 'debug', 'cached data are to old ('. $delta . '). Processed with google maps query...');
        }
        // BR>> end
		$start = urlencode($start);
		$finish = urlencode($finish);
		$distance = __('Inconnue', __FILE__);
		$time = __('Inconnue', __FILE__);
		$url = 'https://maps.googleapis.com/maps/api/directions/xml?origin=' . $start . '&destination=' . $finish . '&sensor=false&key=' . trim(config::byKey('apikey', 'geoloc'));;
		if (!$highways) {
			$url .= '&avoid=highways';
		}
		log::add('geoloc', 'debug', 'google URL:' . $url);
		// if we have send (the plugin) a request to google in the last second, wait
        //$last_exec = time();
        //if ($current_time - $last_exec <= 1) {
        //  sleep(1);
        //}
		if (geoloc::isGoogleRequestUnderQuota() === true) {
			if ($data = file_get_contents($url)) {
				geoloc::addGoogleRequestQuota();
            	log::add('geoloc', 'debug', 'data:' . $data);
				$xml = new SimpleXMLElement($data);
				if (isset($xml->route->leg->duration->value) AND (int) $xml->route->leg->duration->value > 0) {
					$distance = (int) $xml->route->leg->distance->value / 1000;
					$distance = round($distance, 1);
					$time = (int) $xml->route->leg->duration->value;
					$time = floor($time / 60);
				} else {
					log::add('geoloc', 'debug', 'Impossible de trouver une route ' . $data);
					throw new Exception(__('Impossible de trouver une route', __FILE__));
				}
				// BR>> begin
            	$last = array('lasttime' => $current_time, 'distance' => $distance, 'time' => $time, 'start' => urldecode($start), 'finish' => urldecode($finish));
            	//log::add('geoloc', 'debug', 'BR>> cached data (output)' . print_r($last, true));
            	foreach ($last as $k => $v) {
            		$eqLogic->setCache($k, $v);
            	}
				// BR>> end
				log::add('geoloc', 'debug', 'distance=' . $distance . ', time=' . $time);
				return array('distance' => $distance, 'time' => $time);
			} else {
				log::add('geoloc', 'debug', 'Impossible de résoudre l\'URL');
				throw new Exception(__('Impossible de résoudre l\'url', __FILE__));
			}
		} else {
			log::add('geoloc', 'warning', __('Vous avez dépassé le quota de requêtes google par jour', __FILE__));
			throw new Exception(__('Vous avez dépassé le quota de requêtes google par jour', __FILE__));
		}
	}
	/*	 * *********************Methode d'instance************************* */

	public function preAjax() {
		if ($this->getConfiguration('mode') == 'fixe' || $this->getConfiguration('mode') == 'dynamic') {
			$this->setSubType('string');
		} else {
			$this->setSubType('numeric');
			if ($this->getConfiguration('mode') == 'fixe') {
				$this->setUnite('min');
			} else {
				$this->setUnite('Km');
			}
			//$this->setDependency();
		}
	}

	public function postSave() {
		switch ($this->getConfiguration('mode')) {
			case 'fixe':
			$this->event($this->getConfiguration('coordinate'));
			break;
			/*case 'distance':
			 $this->setDependency();
			break;
			case 'travelDistance':
			$this->setDependency();
			break;
			case 'travelTime':
			$this->setDependency();
			break;*/
		}
	}

	function setDependency() {
		$fromto = array('from' => '#' . $this->getConfiguration('from') . '#', 'to' => '#' . $this->getConfiguration('to') . '#');
		$dependency = '';
		foreach ($fromto as $key => $value) {
			preg_match_all("/#([0-9]*)#/", $value, $matches);
			foreach ($matches[1] as $cmd_id) {
				if (is_numeric($cmd_id)) {
					$cmd = self::byId($cmd_id);
					if (is_object($cmd) && $cmd->getType() == 'info') {
						if (strpos($dependency, '#' . $cmd_id . '#') === false) {
							$dependency .= '#' . $cmd_id . '#';
						}
					}
				}
			}
		}
		if ($this->getValue() != $dependency) {
			$this->setValue($dependency);
			return true;
		}
		return false;

	}

	function distance($lat1, $lng1, $lat2, $lng2) {
		$earth_radius = 6378.137; // Terre = sphère de 6378km de rayon
		$rlo1 = deg2rad($lng1);
		$rla1 = deg2rad($lat1);
		$rlo2 = deg2rad($lng2);
		$rla2 = deg2rad($lat2);
		$dlo = ($rlo2 - $rlo1) / 2;
		$dla = ($rla2 - $rla1) / 2;
		$a = (sin($dla) * sin($dla)) + cos($rla1) * cos($rla2) * (sin($dlo) * sin($dlo));
		$d = 2 * atan2(sqrt($a), sqrt(1 - $a));
		return round(($earth_radius * $d), 2);
	}

	public function execute($_options = array()) {
		//if($this->getType()=="action"){
        //  	log::add('geoloc', 'debug', 'BR>> cmd->execute(action) / ' . $this->getHumanName());
		//	$geoloc=$this->getEqLogic();
		//	$geoloc->cron5($geoloc->getId());
		//}
		switch ($this->getConfiguration('mode')) {
			case 'fixe':
				$result = $this->getConfiguration('coordinate');
				return $result;
			case 'distance':
			  	log::add('geoloc', 'debug', 'BR>> cmd->execute(distance) / ' . $this->getHumanName());
				$from = cmd::byId($this->getConfiguration('from'));
				$to = cmd::byId($this->getConfiguration('to'));
				if (!is_object($from)) {
					throw new Exception(__('Commande point de départ introuvable : ', __FILE__) . $this->getConfiguration('from'));
				}
				if (!is_object($to)) {
					throw new Exception(__('Commande point d\'arrivé introuvable : ', __FILE__) . $this->getConfiguration('to'));
				}
				$to = explode(',', $to->execCmd());
				$from = explode(',', $from->execCmd());
				if (count($to) > 2) {
					$to[2] = implode(',', array_slice($to, 1));
				}
				if (count($from) > 2) {
					$from[2] = implode(',', array_slice($from, 1));
				}
				if (count($to) == 2 && count($from) == 2) {
					return self::distance($from[0], $from[1], $to[0], $to[1]);
				}
				return 0;
			case 'travelTime':
				log::add('geoloc', 'debug', 'BR>> cmd->execute(travelTime) / ' . $this->getHumanName());
				$from = cmd::byId($this->getConfiguration('from'));
				$to = cmd::byId($this->getConfiguration('to'));
				try {
					$highways = true;
					if ($this->getConfiguration('noHighways', 0) == 1) {
						$highways = false;
					}
					$result = self::get_driving_information($from->execCmd(), $to->execCmd(), $highways);
					return $result['time'];
				} catch (Exception $e) {
					return 0;
				}
			case 'travelDistance':
				log::add('geoloc', 'debug', 'BR>> cmd->execute(travelDistance) / ' . $this->getHumanName());
				$from = cmd::byId($this->getConfiguration('from'));
				$to = cmd::byId($this->getConfiguration('to'));
				try {
					$highways = true;
					if ($this->getConfiguration('noHighways', 0) == 1) {
						$highways = false;
					}
					$result = self::get_driving_information($from->execCmd(), $to->execCmd(), $highways);
					return $result['distance'];
				} catch (Exception $e) {
					return 0;
				}
		}
		//Mise à jour sur refresh pour actualisation des données de trajet (pas de cron car tarification Google)
		$eqlogic = $this->getEqLogic();
		$eqlogic_id = $this->getEqLogic_id();
		switch ($this->getLogicalId()) {
			case 'refresh':
			/*
				foreach (cmd::byEqLogicId($eqlogic_id) as $cmd) {
					$cmd_id = $cmd->getId();
					if ($cmd->getConfiguration('mode') == 'travelTime' || $cmd->getConfiguration('mode') == 'travelDistance') {
						$from = cmd::byId($cmd->getConfiguration('from'));
						$to = cmd::byId($cmd->getConfiguration('to'));
						try {
							$highways = true;
							if ($this->getConfiguration('noHighways', 0) == 1) {
								$highways = false;
							}
							$result = self::get_driving_information($from->execCmd(), $to->execCmd(), $highways);
							if ($cmd->getConfiguration('mode') == 'travelTime') {cmd::byId($cmd_id)->event($result['time']);}
							else if ($cmd->getConfiguration('mode') == 'travelDistance') {cmd::byId($cmd_id)->event($result['distance']);}
						} catch (Exception $e) {
							return 0;
						}
					}
				}
			*/
			break;
		}
	}

	/*	 * ***********************Methode static*************************** */

	/*	 * *********************Methode d'instance************************* */
}

?>
