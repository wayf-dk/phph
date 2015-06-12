<?php

/**
    Class for handling flat to XML, XML to flat and XML to XML with rules for no-copy and merging.

*/

class flatten {

    /**
        Flattens the $context given the list of prefixes in $prefixes and rules in $xmap
    */

    static function flattenx($xp, $context, $defaultxpath, $prefixes, $xmap)
    {
        $flat = array();
        foreach($xmap as $k => $q) {
            if (!$q) {
                $q = $defaultxpath . $k;
                // always at the end
                $q = preg_replace('/:#$/', '[#]', $q);
            }

            foreach($prefixes as $fprefix => $xprefix) {
                self::x2fhandlerepeatingvalues($k, substr($xprefix . $q, 1), $fprefix, $xp, $context, $flat);
            }
        }
        return $flat;
    }

    /**
        Helper for flattening repeating elements
    */

    static function x2fhandlerepeatingvalues($k, $q, $prefix, $xp, $context, &$flat)
    {
        $x = $repeat = 1;

        $superkey = $k;
        if ($multi = strpos($k, '#')) {
            $supersuper = strpos($k, ':');
            $superkey = substr($k, 0, $supersuper);
            list($repeatingelement, $dummy) = explode('[#', $q, 2);
            $repeat = $xp->query($repeatingelement, $context)->length;
            if (!$repeat) { return; }
        }

        do {
            $kk = preg_replace("/#/", $x - 1, $k, 1);
            $qq = preg_replace('/#/', $x, $q, 1);

            if (strpos($kk, '#')) {
                self::x2fhandlerepeatingvalues($kk, $qq, $prefix, $xp, $context, $flat);
            } else {
                $node = $xp->query($qq, $context);
                if ($node->length === 0 && preg_match('/@xml:lang$/', $qq)) {
                    $node = $xp->query(preg_replace('/@xml:lang$/', '@xs:lang', $qq), $context);
                }

                $val = '';
                if ($node->length) {
                    $flat[$prefix . $k][$prefix . $kk] = $node->item(0)->nodeValue;
                } else if ($multi) {
                    $flat[$prefix . $k][$prefix . $kk] = '';
                }
            }
            $x++;
            $repeat--;
        } while ($multi && $repeat > 0);
    }

    /**
        hierachize ie. convert a flat $data array to a DOM $context
    */

    static function hierarchize($data, $xp, $context, $defaultxpath, $prefixes, $xmap)
    {
        foreach( $xmap as $superkey => $query) {
            if (!$query) {
                $query = $defaultxpath . $superkey;
                $query = preg_replace('/:#$/', '[#]', $query);
            }

            foreach ($prefixes as $fprefix => $xprefix) {
                //printf("%s\n", $role . $superkey);
                if (!array_key_exists($fprefix . $superkey, $data)) { continue; };

                $index = 1;
                foreach( $data[$fprefix . $superkey] as $key => $val) {
                    preg_match('/^(?:(.*)\/)?(.+)$/', $key, $d);
                    list($dummy, $keyrole, $key) = $d;
                    // if (!$key) { $key = $keyrole; }
                    $q = $xprefix . $query;
                    self::f2xhandlerepeatingvalues($key, $q, $val, $xp, $context, 0);
                }
            }
        }
        return $context;
    }

    /**
        Helper for hierarchizing multiple entities from xml to flat
    */

    static function f2xhandlerepeatingvalues($key, $q, $val, $xp, $context, $keyoffset)
    {
        if ($multi = strpos($q, '#')) {
            preg_match('/:(\d+):?/', $key, $d, PREG_OFFSET_CAPTURE, $keyoffset);
            $thisindex = $d[1][0] + 1;
            $keyoffset = $d[1][1];
            $index = 1;
            while( $index < $thisindex) {
                $qq = preg_replace('/#/', $index, $q, 1);
                self::f2xhandlerepeatingvalues($key, $qq, null, $xp, $context, $keyoffset);
                $index++;
            }
            $index = $thisindex + 1;
            $q = preg_replace('/#/', $thisindex, $q, 1); // PHP arrays starts with 0, xpath with 1
            self::f2xhandlerepeatingvalues($key, $q, $val, $xp, $context, $keyoffset);
            return;
        }
        softquery::query($xp, $context, $q, $val);
    }
}
