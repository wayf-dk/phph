<?php

class errors {
    static $errors = array();
}

class g {
    static $instance;
    static $approot;
    static $mddirs;
    static $config = array();
    static $options = array();
    static $delimitedparams = array();
    static $logtag;
    static $now;
    static $testfilepairs;
    static $path;

    static function init($config)
    {
        openlog($config['syslogident'], $config['production'] ? 0 : LOG_PERROR, LOG_LOCAL0);
        self::$instance = $config['instance'];
        g::$logtag = g::$now = time();
        self::$config = array_replace_recursive($config, self::$config);
        self::$config['seirogetacytitne'] = array_flip(self::$config['entitycategories']);
        foreach(self::$config as $k => $v) {
            // 'toplevel array values with one numeric key [0] is considered a comma delimited value
            if (is_array($v) && sizeof($v) === 1 && isset($v[0])) {
                self::$config[$k] = array_map('trim', preg_split('/( *, *)/', $v[0]));
                continue;
            }
            self::$config[$k] = self::fixpath($k, $v);
        }

        foreach(self::$config['defaults'] as $k => $v) {
            self::$config['defaults'][$k] = self::fixpath($k, $v);
        }

        foreach(self::$config['destinations'] as $id => &$dst) {
            foreach(self::$config['defaults'] as $k => $v) { // there should be a default for every destination field
                if (!isset($dst[$k])) { $dst[$k] = $v; }
                if (is_array($v) && sizeof($v) === 1 && array_key_exists(0, $v)) { // the array clue is from defaults !!!
                    if (is_array($dst[$k]) && $dst[$k][0] === "") { $dst[$k] = array(); continue; }
                    if (is_array($dst[$k])) { $dst[$k] = $dst[$k][0]; }
                    $dst[$k] = array_map('trim', preg_split('/( *, *)/', $dst[$k]));
                    continue;
                }
                $dst[$k] = self::fixpath($k, $dst[$k]);
            }
            // add postfilters to 'final' feeds
            if ($dst['final']) { $dst['filters'] = array_merge($dst['filters'], $dst['publishfilters']); }
            $dst['id'] = $id;

        }
   }

    static function fixpath($parameter, $path)
    {
        if (substr($parameter, -4) === 'path' && substr($path, 0, 1) !== '/') { $path = self::$options['basepath'] . "/$path/"; }
        return $path;
    }

    /**
     * Log message to syslog encoded as JSON.
     *
     * @param  int $loglevel The priority of the message for syslog()
     * @param  mixed $loginfo Log message to be JSON encoded
     */
    static function log($loglevel, $loginfo)
    {
        $loginfo['ip'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $loginfo['ts'] = time();
        $loginfo['host'] = gethostname();
        $loginfo['logtag'] = self::$logtag;
        syslog($loglevel, self::$logtag . ' ' . json_encode($loginfo));
    }


    /**
        Extract named parameters from array and make sure that a key is set for all ie. no isset() ...
        1st parameter is the array, rest is keynames

    */

    static function ex()
    {
        $args = func_get_args();
        $array = array_shift($args);
        $res = array();
        foreach($args as $arg) {
            if (isset($array[$arg])) { $res[$arg] = $array[$arg]; }
            else { $res[$arg] = null; }
        }
        return $res;
    }

    /**
        Fix global getopt to allow default values and set presence of boolean values to true
        $opts is an array with normal $longopts values as keys and the default values as values
    */

    static function getopt($opts)
    {
        $options = getopt('', array_keys($opts));
        // getopt sets no-value options that are present to bool(false) ???
        foreach( $options as $k => $v) { if (is_bool($v) && !$v) { $options[$k] = true; } }

        foreach ($opts as $k => $vx ) {
            $kk = preg_replace('/\W/', '', $k);
            if (!isset($options[$kk])) { $options[$kk] = $opts[$k]; }
        }
        return $options;
    }

    static function duration2secs($duration)
    {
        // primitive and imprecise ISO 8601 duration parser - a year is 365 days, a month is 30 days.
        // PHP ignores the designator at the end of each part so we can multiply without fear
        // PnW and P<date>T<time> are not supported
        // The \b at the end is to make sure that we always get an entry for secs in $d
        $durationinsecs = 0;
        $secs = array(0, 365 * 86400, 30 * 86400, 86400, 3600, 60, 1);
        if (preg_match('/^P(\d+Y)?(\d+M)?(\d+D)?T?(\d+H)?(\d+M)?(\d+S)?(\b)?$/', $duration, $d)) {
            foreach ($secs as $i => $s) {
                $durationinsecs += $s * $d[$i];
            }
            return $durationinsecs;
        } else {
            exit("Unsupported duration: '$duration'");
        }
    }
}
