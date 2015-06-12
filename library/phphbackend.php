<?php

class PhphBackEnd {

    private $logtag;
    private $buffers = array();
    private $namespaces;
    public  $secapseman;
    private $sogla_gisdlmx;
    public  $config;
    private $cached = array();
    private $starttime;
    private $destinations;

    private $current_log_status = array();
    private $global_log_status = 'PENDING';

    static $logstates = array('PENDING', 'OK', 'WARNING', 'CRITICAL');

	public function __construct($logtag)
    {
        $this->logtag = $logtag;
        self::$logstates = array_flip(self::$logstates);
    }

    public function config(array $conf)
    {
        $this->starttime = microtime(true);
        $options = array();
        array_walk(g::$options, function($v, $i) use (&$options) {
            if (in_array($i, array('config', 'basepath'))) { return; }
            if (is_bool($v) && $v ) { $options[]=$i; }
            elseif (is_bool($v)) {}
            else { $options[] = "$i=$v"; }
        });
        $options = join(' ', $options);
        syslog(LOG_INFO, "{$this->global_log_status}: {$this->logtag} Call PHPH starting ($options)" . g::$config['logsuffix']);
        $this->config = $conf;

        $this->destinations = $conf['destinations'];

        $missing = array();
        foreach($conf['destinations'] as $id => $dst) {
            if ($dst['filename']) {
                $cert = $dst['certspath'] . $dst['certname'];
                $pkey = '';
                if (file_exists($cert)) {
                    list($dummy, $pkey) = self::get_certificates_for_md($cert, 'signing');
                    $pkey = $dst['certspath'] . $pkey;
                    if (!file_exists($pkey)) {
                        $missing['pkey'][$id] = $pkey;
                    }
                } else {
                    $missing['cert'][$id] = $cert;
                }
            }
        }
        if ($missing) {
            print("missing certificates or private keys:\n");
            print_r($missing);
            exit;
        }
    }

    protected function log($loglevel, $loginfo)
    {
        $loginfo['ts'] = time();
        $loginfo['logtag'] = g::$logtag;
        syslog($loglevel, g::$logtag . " " . json_encode($loginfo));
    }

    static function say($what)
    {
        if (g::$options['silent']) { return; }
        syslog(LOG_INFO, g::$logtag . " $what");
    }

    // Updates and sets status, takes hierarchy of status messages into consideration
    // If the index of the new log status is larger than the index of the current
    // log status, then update the current status.
    protected function status($status, $id, $msg)
    {
        if ($status === 'PENDING' || empty($this->current_log_status[$id]) || self::$logstates[$status] > self::$logstates[$this->current_log_status[$id]]) {
            $this->current_log_status[$id] = $status;
        }
        if (self::$logstates[$status] > self::$logstates[$this->global_log_status]) {
            $this->global_log_status = $status;
        }
        syslog(LOG_INFO, "{$this->current_log_status[$id]}: {$this->logtag} Call $id $msg" . g::$config['logsuffix']);
    }

    static function verifySchema($xp, $schema)
    {
        libxml_clear_errors();
        libxml_use_internal_errors(true);
        $xp->document->schemaValidate(g::$config['schemapath'] . $schema);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        return $errors;
    }

    function getMetadataSources($time, $protocol = 'http')
    {
        if (g::$options['prepareonly']) { return; }
        $exit = '';
        $multi = curl_multi_init();
        $channels = array();
        $durations = array();
        $buffers = array();
        $wait = false;
        foreach ($this->destinations as $id => $src) {
            if (empty($src['url'])) { continue; }
            // hack to be able to do file:// in a second run, after getting md from JANUS ...
            if (strpos($src['url'], $protocol) === false) { continue; }
            if (strpos($src['url'], 'file') === 0) { // local files are always just copied - file_get_contents much faster than curl
                if (file_exists($src['url'])) {
                    $this->buffers[$id] = file_get_contents($src['url']);
                    $this->status('PENDING', $id, 'using local');
                } else {
                    $this->buffers[$id] = null;
                    $this->status('CRITICAL', $id, 'missing file: ' . $src['url']);
                }
                continue;
            }
            $path = $src['cachepath'] . "feed-$id.xml";
            $mtime = file_exists($path) ? filemtime($path) : 0;
            // can we get away with just reading what we have -- only relevant for non-local sources
            if ($protocol != 'file' && $time < $mtime + $src['oldness'] && !$src['forcerefresh']) {
                $this->status('PENDING', $id, 'using cached');
                $this->buffers[$id] = null;
                continue;
            }
            $wait = true;
            $url = $src['url'];
            //self::say("downloading [$id] $url\n");
            $this->status('PENDING', $id, $url);
            $durations[$id] = microtime(true);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3000);
//            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_multi_add_handle($multi, $ch);
            $channels[(int)$ch] = $id;
        }

        if ($wait) {
            $active = 0;
            do {
                curl_multi_select($multi);
                do {$mrc = curl_multi_exec($multi, $active);} while ($mrc == CURLM_CALL_MULTI_PERFORM);
                if ($info = curl_multi_info_read($multi, $inqueue)) {
                    $result = $info['result'];
                    $i = $channels[(int)$info['handle']];
                    //self::say("getting [$i] " . $this->destinations[$i]['url']);

                    $duration = round(microtime(true) - $durations[$i], 3);
                    if ($result === CURLE_OK) {
                        $this->buffers[$i] = curl_multi_getcontent($info['handle']);
                        $this->status('PENDING', $i, "download completed in: $duration");
                    } else {
                        $this->status('WARNING', $i, "download failed in: $duration error: $result");
                        $exit = 'metadatadownloaderrors';
                        // error - fake status and content to force reading cached version
                        $this->buffers[$i] = false;
                        syslog(LOG_WARNING, "$i {$this->destinations[$i]['url']} result: $result, duration: $duration, error: " . curl_error($info['handle']));
                    }
                }
            } while ($active || $info);
            curl_multi_close($multi);
        }
        return array($buffers, $exit);
    }

    static function check_source($id, $xp)
    {
        $xslt = new XSLTProcessor();

        libxml_clear_errors();
        libxml_use_internal_errors(true);

        $rules = glob(g::$config['rulespath'] . '*.xsl');

        foreach ($rules as $rule) {
            if (preg_match('/check_framework\.xsl$/', $rule)) { continue; }
            $xslt->importStylesheet(new SimpleXMLElement($rule, 0, true));
            $xslt->transformToXml($xp->document);
        }

        $errors = libxml_get_errors();
        $filterederrors = array();
        // only pass 'proper' constructed errors thru - dismiss missing functions etc.
        // Ian has implemented some xpath functions in Java, asfaik we can't get xpath to call php when used from xslt !!!
        foreach ($errors as $error) {
            if (preg_match('/\[ERROR\] (\S+): (.*)$/', $error->message, $d)) {
                $error->message = preg_replace("/\n/", " " , $error->message);
                if (preg_match('/(' . join('|' , g::$config['destinations'][$id]['metadataerrorsignore']) . ')/', $error->message)) {
                    continue;
                }
                $filterederrors[] = $error;
            }
        }
        libxml_clear_errors();
        return $filterederrors;
    }

    private function checkvalidUntil($validUntil, $xp, $element)
    {
        $vu = $this->optional($xp, '@validUntil', $element);
        if ($vu) {
            $vuts = date_create($vu)->getTimestamp();
            $validUntil = min($vuts, $validUntil);
        }
        return $validUntil;
    }

    private function relativeTime($now, $then)
    {
        $rt = round(($then - $now)/3600/24, 1);
        $prefix =  $rt <= 0 ? '' : '+';
        return $prefix . $rt;
    }

    static function summary($xp, $context, $id, $type)
    {
        $fields = g::$config['summaryfields'];
        $res = array('keywords' => array());
        $res['approved'] = null;
        foreach($fields['vals'] as $k => $xpath) {
            $res[$k] = null;
            $nodes = $xp->query($xpath, $context);
            if ($nodes->length) {
                $res[$k] = $nodes->item(0)->nodeValue;
            }
        }

        foreach($fields['valx'] as $k => $xpath) {
            $res[$k] = null;
            $nodes = $xp->query($xpath, $context);
            if ($nodes->length) {
                 $res[$k] = preg_replace('/^https?:\/\//', '', $nodes->item(0)->nodeValue);
            }
        }
        foreach($fields['multivals'] as $k => $xpath) {
            $res[$k] = array();
            $nodes = $xp->query($xpath, $context);
            foreach($nodes as $node) {
                 $res[$k][] = $node->nodeValue;
            }
        }

        foreach($fields['exists'] as $k => $xpath) {
            $res[$k] = $xp->evaluate($xpath, $context);
        }

        foreach($fields['xxx'] as $k => $fieldlist) {
            $res[$k] = null;
            foreach(preg_split('/ *, */', $fieldlist) as $field) {
                if ($res[$field]) {
                    $res[$k] = $res[$field];
                    break;
                 }
            }
        }

        // summaryfields not coming from the configuration or not available from the xml
        $res['fed'] = $id;
        $res['feedurl'] = g::$config['destinations'][$id]['url'];
        $res['type'] = $type;
        $res['mtime'] = ($fn = phphfrontend::fn($id, $type)) && file_exists($fn) ? gmdate('Y-m-d\TH:i:s\Z', filemtime($fn)) : null;
        $res['approved'] = false;
        $res['entcat'] = array();
        foreach($res['entitycategories'] as $entcat) {
            if (isset(g::$config['seirogetacytitne'][$entcat])) { $res['entcat'][] = g::$config['seirogetacytitne'][$entcat]; }
        }

        foreach(preg_split('/ *, */', $fields['keywordfields']) as $field) {
            if (isset($res[$field])) {
                $value = $res[$field];
                if (is_array($value)) { $value = join(' ', $value); }
                $res['keywords'][] = preg_split('/[^\\p{L}\\p{Nd}]/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        $res['keywords'] = array_values(array_unique(array_diff(call_user_func_array('array_merge', $res['keywords']), preg_split('/ *, */', $fields['stopwords']))));
        $res['collisions'] = array();
        return $res;
    }

    private function createsummary($id, $path, $xp)
    {
        $entities = $xp->query('//md:EntityDescriptor');
        $summary = array();
        foreach($entities as $entity) {
            $res = self::summary($xp, $entity, $id, 'feed');
            $summary[$res['entityid']][] = $res;
        }
        return $summary;
    }

    private function schemasignatureandmdcheck($id, $xp, $element, $checkschema, $checksignature, $certificate, $checkmd)
    {
        $schema_errors = array();
        $metadata_errors = array();
        if ($checkschema) { // if schemacheck is bypassed all tests are ...
            $schema_errors = self::verifySchema($xp, 'ws-federation.xsd');
            $this->handle_valid(sizeof($schema_errors) === 0, "schema", $id);
            if ($schema_errors) {
                self::say("metadata does not validate according to schema. Source: $id, unique error(s):");
                array_walk($schema_errors, function($a) { PhphBackEnd::say("line: {$a->line}:{$a->column}, error: {$a->message}");});
            }

            if ($checksignature) {
                if (!$certificate) { $this->handle_valid($certificate, "missingcert", $id); }
                else { $this->handle_valid(samlxmldsig::checksign($xp, $element, $certificate), "signature", $id); }
            }

            // Validate metadata content
            if ($checkmd) {
                   $metadata_errors = self::check_source($id, $xp);
                   $this->handle_valid(sizeof($metadata_errors) === 0, "metadata", $id);
                   if ($metadata_errors) {
                       $logtag = $this->logtag;
                       self::say("metadata does not conform to Ian's rules. Source: $id, unique error(s):");
                       array_walk($metadata_errors, function($a) { PhphBackEnd::say("{$a->message}");});
                   }
            }
        }
        return array(sizeof($schema_errors), sizeof($metadata_errors));
    }

    private function handle_valid($valid, $type, $id)
    {
        if (!$valid) {
            $this->destinations[$id]['errors'][] = $type;
            $criticality = $this->destinations[$id]["{$type}errors"];
            $this->status($criticality, $id, "$type ERROR");
            if ($criticality === 'CRITICAL') { exit(2); }
        } else {
            $this->status('OK', $id, "$type OK");
        }
    }

    /**
        1st check that we got something otherwise read cached metadata - if both fails panic!
        2nd check signing, schema and general metadata compliance (null-test for now)
            - depending on signingerrors, schemaerrors, and metadataerrors config settings panic or show WARNING or OK if something fails!
    */

    public function preparemetadata($time)
    {
        $collisions = array();
        $summary = array();

        foreach ($this->destinations as $id => $dst) {
            $this->destinations[$id]['entities'] = array();
            $this->destinations[$id]['validUntil'] = $time + (7 * 24 * 60 * 60); // 7 days
            $path = $dst['cachepath'] . "feed-$id.xml";

            if (empty($dst['url'])) { continue; }
            // if the feed is non-critical it might be empty - but we need the keys 'entities' and 'validUntil' later
            if (!isset($this->buffers[$id])) { $this->buffers[$id] = null; }
            $metadataxml = $this->buffers[$id];
            $cachedmetadata = false;

            // Ensure that we have a feed to work with
            // $xmlmetadata === null -> use cached metadata
            // $xmlmetadata === false -> dl of md failed
            if (empty($metadataxml)) {
                if ($metadataxml === false) {
                    $this->destinations[$id]['errors'][] = 'dl';
                }
                // Get the latest cached feed if no feed was downloaded
                if (file_exists($path)) {
                    $metadataxml = file_get_contents($path);
                    $cachedmetadata = true;
                }
                // There was no cached feed and no feed was downloaded
                if (!$metadataxml) {
                    if ($dst['critical']) {
                        $this->status('CRITICAL', $id, "download failed and no local cache");
                        exit; // with error/emergency/panic - someone has to do something
                    } else {
                        // always save an 'empty' non-critical file
                        $metadataxml = '<md:EntitiesDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"></md:EntitiesDescriptor>';
                    }
                }
            }

            // Initialize xpath object
            $this->destinations[$id]['xp'] = $xp = xp::xpFromString($metadataxml);
            $entitiesDescriptor = $xp->query('/md:EntitiesDescriptor')->item(0);

            //printf("%s\n", $id);
            $file = $dst['certspath'] . $id . '.crt';
            $certificate = file_exists($file) ? $certificate = file_get_contents($file) : null;
            list ($schemaerrors, $metadataerrors) = $this->schemasignatureandmdcheck($id, $xp, $entitiesDescriptor, $dst['schemacheck'], $dst['signaturecheck'], $certificate, $dst['metadatacheck']);

            $summary[$id]['entities'] = $this->createsummary($id, $path, $xp);

            foreach ($summary[$id]['entities'] as $sumid => $dummy) {
                $collisions[$sumid][] = $id;
            }

            // $schemaerrors = $metadataerrors = 0;
            if (!$cachedmetadata) {
                // No critical errors - save new version of feed
                sfpc::file_put_contents($path, $metadataxml);

                $cacheDuration = $this->optional($xp, '@cacheDuration', $entitiesDescriptor);
                if (!$cacheDuration) { $cacheDuration = $dst['cacheDuration']; }
                $duration = g::duration2secs($cacheDuration);
                touch($path, $time + $duration);
            }

            $summary[$id]['schemaerrors'] = $schemaerrors;
            $summary[$id]['metadataerrors'] = $metadataerrors;

            // Keep track of the shortest validUntil in the feed
            // Never checks individual EntityDescriptors - just make a reasonably short default if none provided
            $validUntil = $this->checkvalidUntil($this->destinations[$id]['validUntil'], $xp, $entitiesDescriptor);
            // Make collection of entities and clean entities
            foreach($xp->query('//md:EntityDescriptor') as $node) {
                $node->removeAttribute('validUntil');
                $node->removeAttribute('cacheDuration');
                $this->destinations[$id]['entities'][] = $node;
            }

            // Commit shortest validUntil
            $this->destinations[$id]['validUntil'] = $validUntil;

            // Find the relative validUntil and set critical if less than validuntilcritical
            $relativeValidUntil = $this->relativeTime($time, $validUntil);
            $criticality = $dst['validuntilcritical'] && $relativeValidUntil < $dst['validuntilcritical'] ? 'CRITICAL' : 'OK';

            $idps = $xp->query('//md:IDPSSODescriptor')->length;
            $sps  = $xp->query('//md:SPSSODescriptor')->length;
            $this->handle_valid($idps >= $dst['minidps'], "minidps", $id);
            $this->handle_valid($sps >= $dst['minsps'], "minsps", $id);

            // Update final status
            $errorkeywordsstr =  isset($this->destinations[$id]['errors']) ? join(',', $this->destinations[$id]['errors']) : '';
            $this->status($criticality, $id, "<-[$errorkeywordsstr] validuntil: $relativeValidUntil days $sps/$idps");
        }

        // A collision occurs when an entity appears in more than one feed
        $collisions = array_filter($collisions, function($x) { return sizeof($x) > 1; });

        // Add the xtra feeds to the summary for the entity
        foreach ($collisions as $colid => $colls) {
            foreach ($colls as $fid) {
                $summary[$fid]['entities'][$colid][0]['collisions']
                    = array_values(array_diff(array_intersect($colls, g::$config['relevantcollisions']) , array($fid)));
            }
        }

        foreach ($summary as $fid => $fsummary) {
            sfpc::file_put_contents($dst['cachepath'] . "summary-$fid.json", json_encode($fsummary));
        }
    }

    /**
        Save metadata for feeds
    */

    public function export_destinations($now)
    {
        $creationInstant = gmdate('Y-m-d\TH:i:s', $now). 'Z';

        foreach($this->destinations as $id => $dst) {
            $c = 0;
            $tmp = !$dst['filename'];
            if ($tmp) {
                if ($dst['url']) { continue; } // don't save sources that are not published as well
                //if (empty($dst['filters'])) { continue; }
                $dst['publishpath'] = $dst['cachepath'];
                $dst['filename'] = "tmp-$id.xml";
                $dst['nosign'] = true;
            } // only work on final stages ...
            $xp = xp::xpe();
            $res = $xp->document;
            $entities = $this->get_cached($id);

		    $entitiesDescriptor = $res->createElementNS('urn:oasis:names:tc:SAML:2.0:metadata', 'md:EntitiesDescriptor');
		    $res->appendChild($entitiesDescriptor);

    		//$usagePolicy = $this->softquery($xp, $entitiesDescriptor, 'md:Extensions/mdrpi:PublicationInfo/mdrpi:UsagePolicy');
    		//$usagePolicy->setAttribute('xml:lang', 'en');
            //$usagePolicy->appendChild($res->createTextNode('http://www.edugain.org/policy/metadata-tou_1_0.txt'));

            $seen = array();
            foreach ($entities['entities'] as $n => $e) {
                $entityID = $e->getAttribute('entityID');

                if (isset($seen[$entityID])) {
                    self::say("WARNING duplicate entityID found: $entityID\n");
                    continue;
                }
                $seen[$entityID] = 1;
                $entitiesDescriptor->appendChild($res->importNode($e, true));
                $c++;
            }

            // check the mtime of the old version here and - if neccessary - the hash of the new and old file version here
            // - we only write the new one if it has changed or if we are past the mtime + cacheDuration of the old one
            // - we use the ID attribute for the sha256 hash of the content - sans the info we know changes: validUntil ...

            $ID = '_' . sha1($res->C14N(true, false), false); // false equals hex

            $usecached = false;
            $file = $dst['publishpath'] . $dst['filename'];
            $mtime = file_exists($file) ? filemtime($file) : 0;
            if ($mtime && !$dst['forcerefresh'] && !$tmp) { // only falsy if not existing
                if ($mtime + g::duration2secs($dst['cacheDuration']) > $now) { // only write if changed
                    $existingxp = xp::xpFromFile($file);
                    $existingentitiesDescriptor = $existingxp->query('/md:EntitiesDescriptor')->item(0);
                    $existingIDattribute = $existingxp->query('/md:EntitiesDescriptor/@ID');
                    $existingID = null;
                    if ($existingIDattribute->length) { $existingID = $existingIDattribute->item(0)->nodeValue; }
                    $usecached = $ID === $existingID; // no changes and no timeout - ignore the file
                }
            }

            //$usecached = false;
            $cert = $dst['certspath'] . $dst['certname'];
            list($certificates, $privatekeyname) = self::get_certificates_for_md($cert, 'signing');

            if ($usecached) {
                $xp = $existingxp;
                $entitiesDescriptor = $existingentitiesDescriptor;
            } else {
                $entitiesDescriptor->setAttribute('validUntil', gmdate('Y-m-d\TH:i:s', $entities['validUntil']). 'Z');
                $entitiesDescriptor->setAttribute('cacheDuration', $dst['cacheDuration']);
                $entitiesDescriptor->setAttribute('ID',  $ID);

                $publicationinfo = softquery::query($xp, $entitiesDescriptor, '/md:Extensions/mdrpi:PublicationInfo', null, true);
                $publicationinfo->setAttribute('creationInstant', $creationInstant);
                $publicationinfo->setAttribute('publisher', $dst['publisher']);


                $privatekey = file_get_contents($dst['certspath'] . $privatekeyname);
                samlxmldsig::sign($xp, $entitiesDescriptor, $certificates[0], $privatekey, $dst['pw'], $dst['signatureMethod'], $dst['digestMethod']);
                $publishxml = $xp->document->saveXML(); // need to have it before checking because signature check removes the signature

                sfpc::file_put_contents($dst['publishpath'] . $dst['filename'], $publishxml);
                if (!$tmp) { // only for published files
                    // testify clones the document so our present signature is keept for later checking
                    //$this->testify($dst, $xp);
                }
            }

            if ($tmp) { continue; } // don't show status messages for non-published files

            $this->schemasignatureandmdcheck($id, $xp, $entitiesDescriptor, $dst['schemacheck'], $dst['signaturecheck'], $certificates[0], $dst['metadatacheck']);
            $validUntil = date_create($xp->query('/md:EntitiesDescriptor/@validUntil')->item(0)->nodeValue)->getTimestamp();
            $relativeValidUntil = $this->relativeTime($now, $validUntil);
            $sps  = $xp->query('//md:SPSSODescriptor')->length;
            $idps = $xp->query('//md:IDPSSODescriptor')->length;
            $xtra = $usecached ? " using cached" : "";
            // Update status for dsts ...
            $errorkeywordsstr =  isset($this->destinations[$id]['errors']) ? join(',', $this->destinations[$id]['errors']) : '';
            $this->status('OK', $id, "->[$errorkeywordsstr] validuntil: $relativeValidUntil days $sps/$idps$xtra");
        }

        $duration = round(microtime(true) - $this->starttime, 3);
        syslog(LOG_INFO, "{$this->global_log_status}: {$this->logtag} Call PHPH finished duration: $duration" . g::$config['logsuffix']);
    }

    private function get_cached($id)
    {
        $destination = isset($this->destinations[$id]) ? $this->destinations[$id]: null;
        if (empty($this->cached[$id]))  {
            // Merge sources into destination feed
            if ($destination['url']) { $this->cached[$id] = $destination; }
            else { $this->cached[$id] = $this->merge_md($destination['sources']); }

            // 1st make array of filters
            if (isset($destination['filters'])) {
                $filterfuncs = array();
                foreach($destination['filters'] as $filter) {
                    $filterfunc = $destination['filterclass'] . '::filter_' . str_replace('-', '_', $filter);
                    if (is_callable($filterfunc)) {
                        $filterfuncs[] = $filterfunc;
                    } else {
                        die("$id: '$filter' is not a callable method");
                    }
                }
                // run every entity thru the filters

                $res = array();

                foreach($filterfuncs as $filterfunc) { // set up
                    call_user_func($filterfunc, $this, $id, null, null, $destination, 0);
                }

                foreach($this->cached[$id]['entities'] as $ee) {
                    $e = $ee->cloneNode(true);
                    $xp = xp::dom($e->ownerDocument);
                    foreach($filterfuncs as $filterfunc) {
                        $e = call_user_func($filterfunc, $this, $id, $e, $xp, $destination, 1);
                        if (!$e) { continue 2; }
                    }
                    if ($e) { $res[] = $e; }
                }

                $this->cached[$id]['entities'] = $res;
                foreach($filterfuncs as $filterfunc) {
                    // clean up, save files etc calls - with $e (and $state) equal to null
                    call_user_func($filterfunc, $this, $id, null, null, $destination, null);
                }
            }
        }
        if (isset($this->cached[$id])) {
            return $this->cached[$id];
        } else {
            print('no way to get cached version of ' . $id);
        }
    }

    function merge_md(array $sources)    {
        $res = array('entities' => array());
        $ownerdocument = null;
        $validUntil = PHP_INT_MAX;
        foreach($sources as $feed) {
            $entities = $this->get_cached($feed);
            foreach ((array)$entities['entities'] as $entity) {
                if ($ownerdocument === null) {
                    $ownerdocument = $entity->ownerDocument;
                }
                if ($entity->ownerDocument !== $ownerdocument) {
                    $entity = $ownerdocument->importNode($entity, true);
                }
                $res['entities'][] = $entity;
            }
            $res['validUntil'] = min($validUntil, $entities['validUntil']);
        }
        return $res;
    }

    private function testify($dst, $xp)
    {
        // clone the document - we will delete the signature and replace all the certificates
        // and we do not want to invalidate the existing signature ....
        $doc = new DOMDocument();
        $doc->appendChild($doc->importNode($xp->document, true));
        $testxp = xp::dom($doc);

        // remove the signature if any - a new will be created before saving ...
        $signature = $testxp->query('/md:EntitiesDescriptor/ds:Signature');
        if ($signature->length) {
            $signature->item(0)->parentNode->removeChild($signature->item(0));
        }

        // replace all the certs
        $fn = $dst['certspath'] . $dst['testcert'];
        if (!file_exists($fn)) { return; }
        $testcert = samlxmldsig::ppcertificate(file_get_contents($fn), false);

        $certs = $testxp->query('//md:KeyDescriptor/ds:KeyInfo/ds:X509Data/ds:X509Certificate');
        foreach ($certs as $cert) {
            $cert->nodeValue = $testcert;
        }
        sfpc::file_put_contents($dst['testpublishpath'] . $dst['filename'], $testxp->document->saveXML());
    }

    public function optional($xp, $query, $context = null) {
        if (!$xp) { return null; }
        $res = null;
        $tmp = $context ? $xp->query($query, $context) : $xp->query($query);
        if ($tmp->length === 1) { $res = $tmp->item(0)->nodeValue; }
        return $res;
    }

    static function get_certificates_for_md($file, $use)
    {
        $certs = file_get_contents($file);
        if (preg_match_all('/^-----BEGIN CERTIFICATE-----([^-]*)^-----END CERTIFICATE-----.*/m', $certs, $matches)) {
            $pkey = openssl_pkey_get_public($matches[0][0]);
            $details = openssl_pkey_get_details($pkey);
            // mimics openssl x509 -modulus -noout -in <cert> | openssl sha1
            $keyname = sha1('Modulus=' . strtoupper(bin2hex($details['rsa']['n'])) . "\n") . '.key';
            return array($matches[0], $keyname); // certificates, name of private key
        } else {
            syslog(LOG_ERR, "{g::$logtag} something wrong with certificate: $file");
            exit(1);
        }
    }
}
