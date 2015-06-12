<?php

class phphfrontend {

    static function auth($redirect, $users, $forcelogin = true)
    {
        if ((!isset($_SESSION['SAML']) || isset($_GET['reset'])) && $forcelogin) {
            unset($_SESSION['SAML']);
            if ($redirect) { $_SESSION['redirect'] = $redirect; }
            $sporto = new sporto(g::$config['SAML']);
            $_SESSION['SAML'] = $sporto->authenticate();
            $_SESSION['SAML']['AuthTime'] = time();
            if (isset($_SESSION['redirect'])) { header("Location: " . $_SESSION['redirect']); }
        }
        if (!$users || empty($_SESSION['SAML']) || !in_array($_SESSION['SAML']['attributes']['eduPersonPrincipalName'][0], $users)) {
            $error = "Only authenticated users are allowed to make changes.";
            if ($redirect) { header("Location: $redirect" . "&error=" . rawurlencode($error) ); }
            else {
                header("HTTP/1.1 401 Unauthorized");
                print $error; }
            exit;
        }
        return $_SESSION['SAML'];
    }

    static function readme__($path)
    {
        $md = file_get_contents(dirname(__DIR__) .'/doc/README.md');
        preg_match_all('/ *(#+) (.*)/m', $md, $d);
        $toplevel = strlen($d[1][0]);
        $index = self::indexify($d[1], $d[2], $toplevel + 1, $toplevel + 2);
        $md = preg_replace('/^<!--- toc-placeholder --->$/m', "$index", $md, 1);

        print self::render('readme', $md);
    }

    static function indexify($levels, $headings, $from = 1, $to = 3)
    {
        $index = '';
        foreach($levels as $i => $level) {
            if (($level = strlen($level)) < $from || $level > $to) { continue; }
            $level -= $from;
            $a = strtolower(preg_replace(array('/[ "\.]/', '/[^-\w]/', '/-+/'), array('-', '', '-'), $headings[$i]));
            $index .= str_repeat(' ', 4 * $level) . "1. [$headings[$i]](#$a)\n";
        }
        return $index;
    }

    static function ping__($path)
    {
        self::auth(null, g::$config['approveusers'], false);
        print file_get_contents('http://localhost:9000/' . join('/', $path));
    }

    static function auth__($path)
    {
        self::auth('/overview?', g::$config['approveusers'], true);
    }

    static function mdq__($path)
    {
        $path = $path + array(null, null, null, null); // if feed, $entities or entityID is not passed ...
        list($mdq, $feed, $entities, $entityID) = $path;

        if ($entities !== 'entities' || $_GET) {
            header("HTTP/1.1 400 Bad Request"); exit;
        }
        if (empty(g::$config['destinations'][$feed])) {
            header("HTTP/1.1 404 Not Found"); exit;
        }

        $dst = g::$config['destinations'][$feed];
        $mdfile = $dst['mdqpath'] . "$feed/entities/{sha1}" . sha1(urldecode($entityID));

        if (file_exists($mdfile)) {
            header_remove();
            header('content-type: text/xml'); // for debugging does not get saved as as file ...
            //header('Content-Type: application/samlmetadata+xml');
            readfile($mdfile);
        } else {
            header("HTTP/1.1 404 Not Found"); exit;
        }
    }

    static function tail__($path)
    {
        session_write_close();
        if (isset($path[1]) && $path[1] == '6vrxmC81mOP3oCDkW2oWSv5E') {
            restore_error_handler();
            header("Content-Type: text/event-stream");
            header("Cache-Control: no-cache");
            header("Access-Control-Allow-Origin: *.wayf.dk");

            $fp = stream_socket_client("tcp://localhost:8000", $errno, $errstr, 30);

            if (!$fp) {
                echo "$errstr ($errno)<br />\n"; exit;
            }

            $id =  1;
            $headers = getallheaders();
            if (isset($headers['Last-Event-ID'])) { $id = $headers['Last-Event-ID'] + 1;  }
            fwrite($fp, "$id\n");

            while (!feof($fp)) {
                $rec = json_decode(fgets($fp), 1);

                if ($rec['act'] === 'metadatastatus') {  // skip entries from more than 1.5 hours ago, but show start messages to signal that something is happening
                    if ($rec['ts'] + (3600 * 1.5) < time() && !stripos($rec['txt'] , 'Call PHPH starting')) { continue; }

                    $id = $rec['id'];
                    echo "id: $id\n";
                    echo "event: message\n";
                    echo "data: " . json_encode($rec) . "\n\n";
                    ob_flush();
                    flush();
                    continue;
                }
            }
        } else {
            print self::render('tail', null);
        }
    }

    static function dot__()
    {
        $superview = self::superviewinfo();

        $dotview = array();
        foreach( $superview as $path => $dirs ) {
            foreach( $dirs as $dir => $ents) {
                foreach( $ents as $id => $info) {
                    $dotview[$path][$id] = $info['sps'] . ' / ' . $info['idps'];
                }
            }
        }

        $viz = $helpers = '';
        $srcRankSame = array_keys(array_filter( g::$config['destinations'], function($d) { return $d['url']; }));
        $dstRankSame = array_keys(array_filter( g::$config['destinations'], function($d) { return $d['filename'] && !$d['final']; }));
        $finalRankSame = array_keys(array_filter( g::$config['destinations'], function($d) { return $d['final']; }));
        $zzzRankSame = array_diff(array_keys(g::$config['destinations']), $srcRankSame, $dstRankSame, $finalRankSame);


        // green9 "#f7fcf5" "#e5f5e0" "#c7e9c0" "#a1d99b" "#74c476" "#41ab5d" "#238b45" "#006d2c" "#00441b"
        foreach($srcRankSame as $rs) {
            $x = join(', ', g::$config['destinations'][$rs]['filters']);
            $i = $dotview['feed'][$rs];
            $viz .= "{ node [label=\"$i\n$rs\n$x\" penwidth=0.5 fillcolor=\"#f7fcf5\",style=filled URL=\"mdfileview?type=feed&fed=$rs\"]  \"x-$rs\"};\n";
        }

        foreach($zzzRankSame as $rs) {
            $x = join(', ', g::$config['destinations'][$rs]['filters']);
            $i = $dotview['tmp'][$rs];
            $viz .= "{ node [label=\"$i\n$rs\n$x\" penwidth=0.5 fillcolor=\"#e5f5e0\",style=filled URL=\"mdfileview?type=tmp&fed=$rs\"]  \"$rs\"};\n";
        }

        foreach($dstRankSame as $rs) {
            $x = join(', ', g::$config['destinations'][$rs]['filters']);
            $i = $dotview['published'][$rs];
            $viz .= "{ node [label=\"$i\n$rs\n$x\" penwidth=0.5 fillcolor=\"#c7e9c0\",style=filled URL=\"mdfileview?type=published&fed=$rs\"]  \"y-$rs\"};\n";
        }

        foreach($finalRankSame as $rs) {
            $x = join(', ', g::$config['destinations'][$rs]['filters']);
            $type = g::$config['destinations'][$rs]['filename'] ? 'published' : 'tmp';
            $i = $dotview[$type][$rs];
            $viz .= "{ node [label=\"$i\n$rs\n$x\" penwidth=0.5 fillcolor=\"#a1d99b\",style=filled  URL=\"mdfileview?type=$type&fed=$rs\"]  \"z-$rs\"};\n";
        }

        //print "<pre>"; print_r(g::$config); exit;
        foreach (g::$config['destinations'] as $id => $dest) {
            $dstid = $id;
            if (in_array($id, $dstRankSame)) { $dstid = "y-$id"; }
            if (in_array($id, $finalRankSame)) { $dstid = "z-$id"; }
            // Draw source dependencies
            foreach ($dest['sources'] as $src) {
                $x = $src;
                if (in_array($x, $srcRankSame)) { $src = "x-$x"; }
                if (in_array($x, $dstRankSame) && $x != $id) { $src = "y-$x"; }
                $viz .= sprintf("\"%s\" -> \"%s\";\n", $src, $dstid);
            }
            // Draw params dependencies
            foreach ($dest['params'] as $src) {
                $x = $src;
                if (in_array($x, $srcRankSame)) { $src = "x-$x"; }
                if (in_array($x, $dstRankSame) && $x != $id) { $src = "y-$x"; }
                $viz .= sprintf("\"%s\" -> \"%s\" [style = dashed];\n", $src, $dstid);
            }
        }

        $srcRankSame = join(' ', array_map(function($id) { return "\"x-$id\""; }, $srcRankSame));
        $dstRankSame = join(' ', array_map(function($id) { return "\"y-$id\""; }, $dstRankSame));
        $finalRankSame = join(' ', array_map(function($id) { return "\"z-$id\""; }, $finalRankSame));
        //$xxxRankSame = join(' ', array_map(function($id) { return "\"z-$id\""; }, $xxxRankSame));
        //print_r($viz);

        print self::render('viz', compact('viz', 'params', 'srcRankSame', 'dstRankSame', 'finalRankSame', 'helpers'));
    }

    static function raw__() {
        extract(g::ex($_GET, 'fed', 'type'));
        header('content-type: text/xml');
        readfile(self::fn($fed, $type));
    }

    /**
        Approve or unapprove the entity
        Keeps audit log in the xml file
        Status is in <entityDescriptor><md:Extensions><wayf:log approved="true|false">
        Audit log in <entityDescriptor><md:Extensions><wayf:log><wayf:logentry user="user" jira="jiraid" action="approve|unapprove" attrs="attr1,attr2..."/>
    */

    static function approve__() {
        $post = $_SESSION['formvalues'][$_POST['formvalues']];
        self::auth('/show?' . http_build_query($post), g::$config['approveusers'], false);

        unset($_SESSION['formvalues'][$_POST['formvalues']]);
        $entityID = $post['entityID'];
        $fed = g::$config['feeds'][$post['fed']];
        $approvedfile = g::$config['destinations'][$fed]['approvedpath'] . "approved-$fed.xml";

        $xp2 = file_exists($approvedfile) ? xp::xpFromFile($approvedfile) : xp::xpe();
        $doc2 = $xp2->document;

        $entityxpath = '/md:EntitiesDescriptor/md:EntityDescriptor[@entityID="' . $entityID  . '"]';

        $attrs = array();
        if (isset($_POST['attrs']) && $_POST['attrs']) { $attrs = explode(',', $_POST['attrs']); }

        // make sure that only supported attributes is used
        $attrs = array_intersect($attrs, g::$config['attributesupport']['attributes']);
        sort($attrs);

        $ent2 = softquery::query($xp2, $doc2, $entityxpath);
        $requestedattributesquery = 'md:SPSSODescriptor/md:AttributeConsumingService/md:RequestedAttribute';
        $requestedattributes = $xp2->query($requestedattributesquery, $ent2);

        $approvedattributes = array();

        foreach($requestedattributes as $ra) {
            if ($ra->getAttribute('NameFormat') === "urn:oasis:names:tc:SAML:2.0:attrname-format:uri"
                    && isset(basic2oid::$oid2basic[$ra->getAttribute('Name')])) {
                $approvedattributes[] = basic2oid::$oid2basic[$ra->getAttribute('Name')];
            }
        }

        sort($approvedattributes);

        $approved = $xp2->query('md:Extensions/wayf:log[1][@approved="true"]', $ent2)->length === 1;

        // no change in status or approved attributes - just show the entity
        if ((empty($_POST['approve']) && empty($_POST['unapprove']))
            || (isset($_POST['unapprove']) && !$approved)
            || (isset($_POST['approve']) && $approved && $approvedattributes == $attrs)) {
                header('Location: /show?a=b&' . http_build_query($post) . "&error=" . rawurlencode("No changes made!"));
                exit;
        }

        //var_dump($approved); exit;

        // Lock the approvefile exclusively - otherwise we might get a race condition ...
        $fp = fopen($approvedfile, "c+");
        if (!flock($fp, LOCK_EX)) { die("could not lock $approvedfile"); }

        // always remove the currently approved attributes

        $acs = $xp2->query('md:SPSSODescriptor/md:AttributeConsumingService', $ent2);
        if ($acs->length) { $acs->item(0)->parentNode->removeChild($acs->item(0)); }

        $approvedxpath = '/md:Extensions/wayf:log[1]/@approved';
        if (isset($_POST['unapprove'])) {
            softquery::query($xp2, $ent2, $approvedxpath, 'false');
        } elseif (isset($_POST['approve'])) { // do not bother if the list of attributes has not changed
            // make sure that the entity is present in the approved.xml file even if no attributes are approved
            softquery::query($xp2, $ent2, $approvedxpath, 'true');
            // first remove the acs element
            if ($attrs) {
                $acs = softquery::query($xp2, $ent2, '/md:SPSSODescriptor/md:AttributeConsumingService');
                $attrno = 1;
                foreach( $attrs as $attr) {
                    $xp = "/md:RequestedAttribute[$attrno]/";
                    softquery::query($xp2, $acs, $xp . '@FriendlyName', $attr);
                    softquery::query($xp2, $acs, $xp . '@Name', basic2oid::$basic2oid[$attr] );
                    softquery::query($xp2, $acs, $xp . '@NameFormat', 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri');
                    softquery::query($xp2, $acs, $xp . '@isRequired', 'true');
                    $attrno++;
                }
            }
        }

        $loginfo = array(
            'ref'        => $_POST['ticket'],
            'date'       => gmdate('Y-m-d\TH:i:s'). 'Z',
            'user'       => $_SESSION['SAML']['attributes']['eduPersonPrincipalName'][0],
            'action'     => isset($_POST['approve']) ? 'approve' : 'unapprove',
            'attributes' => join(',', $attrs),
        );

        g::log(LOG_INFO, $loginfo);

        $logentryno = $xp2->query('md:Extensions/wayf:log/wayf:logentry', $ent2)->length + 1;
        $sq = "/md:Extensions/wayf:log[1]/wayf:logentry[$logentryno]/";
        foreach( $loginfo as $k => $v) {
            softquery::query($xp2, $ent2, $sq . '@' . $k, $v);
        }

        $doc2->formatOutput = true;

        sfpc::file_put_contents($approvedfile, $doc2->saveXML());
        chmod($approvedfile, 0777);
        fclose($fp);

        header('Location: /show?' . http_build_query($post));
    }

    static function superview__() {
        $superview = self::superviewinfo();
        print self::render('superview', compact('superview'));
    }

    static function mdfileview__() {
        extract(g::ex($_GET, 'fed', 'type', 'errs'));

        $summary = self::summary($type, $fed);
        $overview[$fed] = $summary;
        //print "<pre>";    print_r($summary); print "</pre>";
        if ($errs) {
            $mdfileview_cache = "/tmp/phph-mdfileview-$type-$fed-cache.json";
            $feed = self::fn($fed, $type);
            $feedmtime = filemtime($feed);
            if (!file_exists($mdfileview_cache) || $feedmtime != filemtime($mdfileview_cache)) {
                $xp = xp::xpFromFile(self::fn($fed, $type));
                $xp2 = xp::xpFromString('<md:EntitiesDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"></md:EntitiesDescriptor>');
                $doc = $xp2->document->documentElement;

                $entities = $xp->query('//md:EntityDescriptor');
                // add one to be replaced immediately ...
                if ($entities->length) {
                    $e = $xp2->document->importNode($entities->item(0), true);
                    $doc->appendChild($e);
                }
                foreach($entities as $x) {
                    $e = $xp2->document->importNode($x, true);
                    $doc->replaceChild($e, $doc->firstChild);
                    $metadata_errors = PhphBackEnd::check_source($fed, $xp2);
                    $schema_errors = PhphBackEnd::verifySchema($xp2, 'ws-federation.xsd');
                    $entityID = $x->getAttribute('entityID');
                    $overview[$fed]['entities'][$entityID][0]['schemaerrors'] = sizeof($schema_errors);
                    $overview[$fed]['entities'][$entityID][0]['metadataerrors'] = sizeof($metadata_errors);
                }
                $json = json_encode($overview);
                sfpc::file_put_contents($mdfileview_cache, $json);
                touch($mdfileview_cache, $feedmtime); // feed's mtime is when cacheDuration runs out ...
            } else {
                $json = file_get_contents($mdfileview_cache);
            }
        } else {
            $json = json_encode($overview);
        }
        //print "<pre>";    print_r(json_decode($json, 1)); print "</pre>";
        print self::render('overview', compact('json'));
    }

    static function overview__($path) {

        $feeds = array();

        foreach(g::$config['feeds'] as $fed => $feed) {
            $dst = g::$config['destinations'][$feed];
            $fn = $dst['cachepath'] . "summary-$feed.json";
            if (!file_exists($fn)) { errors::$errors[] = "Missing needed file: $fn"; continue; }
            $file = json_decode(file_get_contents($fn), 1);
            $approvedfile = $dst['approvedpath']. "approved-$fed.xml";
            if (!file_exists($approvedfile)) {
                errors::$errors[] = "Missing needed approved file: $approvedfile";
            } else {
                //if (errors::$errors) { continue; }
                $xp = xp::xpFromFile($approvedfile);
                $approvedentities = $xp->query('md:EntityDescriptor/md:Extensions/wayf:log[1][@approved="true"]/../..'); // find the entity not the wayf:log
                foreach($approvedentities as $ent) {
                    $entityID = $ent->getAttribute('entityID');
                    $file['entities'][$entityID][0]['approved'] = true;
                }
            }
            $feeds[$feed] = $file;
        }
        $json = json_encode($feeds);
        //print "<pre>";    print_r($feeds); print "</pre>";
        print self::render('overview', compact('json'));
    }

    static function debug__($path)
    {
        show__($path);
    }

    static function show__($path) {
        extract(g::ex($_GET, 'entityID', 'fed', 'type'));

        $formvalues = compact('entityID', 'fed', 'type');
        $key = uniqid();
        $_SESSION['formvalues'][$key] = $formvalues;

        $show = $show2 = $show3 = array();
        $xp = xp::xpFromFile(self::fn($fed, $type));

        $xpath = '//md:EntityDescriptor[@entityID="' . $entityID . '"][1]';
        $entity = $xp->query($xpath)->item(0);
        $summary = PhphBackEnd::summary($xp, $entity, $fed, $type);

        $fn = g::$config['destinations'][$fed]['cachepath'] . "summary-$fed.json";
        if (file_exists($fn)) {
            $file = json_decode(file_get_contents($fn), 1);
            $summary['collisions'] = $file['entities'][$entityID][0]['collisions'];
        }

        //print "<pre>";    print_r($summary); print "</pre>";

        $approvedfile = g::$config['destinations'][$fed]['approvedpath'] . "approved-$fed.xml";
        $requestedattributesquery = 'md:SPSSODescriptor/md:AttributeConsumingService/md:RequestedAttribute';

        $grantedattributes = array();
        $unapprovable = false;
        $logentries = array();

        if ($approvable = ($summary['SP'] && file_exists($approvedfile) && $type === 'feed')) {

            $xp2 = xp::xpFromFile($approvedfile);
    //        $granted = $xp->query($requestedattributesquery, $entity);

            if ($entity2 = $xp2->query($xpath)->item(0)) {
                // found the entity in the approved file
                $unapprovable = $xp2->query('md:Extensions/wayf:log[1][@approved="true"]', $entity2)->length;

                $granted = $xp2->query($requestedattributesquery, $entity2);
                foreach($granted as $g) {
                    $oid = isset(basic2oid::$oid2basic[$g->getAttribute('Name')]) ? basic2oid::$oid2basic[$g->getAttribute('Name')] : $g->getAttribute('Name');
                    $grantedattributes[$oid] = 1;
                }

                $logs = $xp2->query('md:Extensions/wayf:log/wayf:logentry', $entity2);
                foreach( $logs as $entry) {
                    $logentries[] = array(
                        'ref' => $entry->getAttribute('ref'),
                        'date' => $entry->getAttribute('date'),
                        'user' => $entry->getAttribute('user'),
                        'action' => $entry->getAttribute('action'),
                        'attributes' => $entry->getAttribute('attributes'),
                    );
                }
    /* constructs the final metadata for the entity with approved changes -  we don't show this currently ....
                $xp3 = xp::xpe();
                $doc3 = $xp3->document;
                $entitydescriptors = $doc3->createElementNS(g::$secapseman['md'], 'EntityDescriptors');
                $doc3->appendChild($entitydescriptors);

                $entity3 = $doc3->createElementNS(g::$secapseman['md'], 'EntityDescriptor');
                $entitydescriptors->appendChild($entity3);

                flatten::merge($xp, $entity, $xp2, $entity2, $xp3, $entity3, g::$config['interfed-nocopy-rules'], g::$config['interfed-merge-rules']);

                $show2 = $flatten->flattenx($xp2, $entity2, '/md:Extensions/wayf:wayf/wayf:', samlmdxmap::$roles, samlmdxmap::$xmap);
                $show3 = $flatten->flattenx($xp3, $entity3, '/md:Extensions/wayf:wayf/wayf:', samlmdxmap::$roles, samlmdxmap::$xmap);
                $doc3->formatOutput = true;
                $doc3->preserveWhiteSpace = false;
                $show3[0][null]['xml']['xml'] = htmlspecialchars($doc3->saveXML($entity3));
    */
                }
        }

        $requestedattributes = $xp->query($requestedattributesquery, $entity);
        $ats = array();
        $requested = array();
        $required = array();
        $xtraats = array();

        foreach($requestedattributes as $ra) {
            if ($ra->getAttribute('NameFormat') === "urn:oasis:names:tc:SAML:2.0:attrname-format:uri"
                    && isset(basic2oid::$oid2basic[$ra->getAttribute('Name')])) {
                $requested[basic2oid::$oid2basic[$ra->getAttribute('Name')]] = $ra->getAttribute('Name');
                if ($ra->getAttribute('isRequired') === "true") {
                    $required[basic2oid::$oid2basic[$ra->getAttribute('Name')]] = $ra->getAttribute('Name');
                }
            } else {
                $xattr = array();
                foreach (array('Name', 'NameFormat', 'FriendlyName') as $a) {
                    $xattr[$a] = $ra->getAttribute($a);
                }
                $xtraats[] = $xattr;
            }
        }

        foreach(g::$config['attributesupport']['attributes'] as $basic) {
            $at['friendlyName'] = $basic;
            $at['oid'] = basic2oid::$basic2oid[$basic];
            $at['requested'] = isset($requested[$basic]);
            $at['required'] = isset($required[$basic]);
            $at['granted'] = isset($grantedattributes[$at['friendlyName']]);
            $ats[] = $at;
        }

        $ats = json_encode($ats);
        $xtraats = json_encode($xtraats);

        $show = flatten::flattenx($xp, $entity, '/md:Extensions/wayf:wayf/wayf:', samlmdxmap::$roles, samlmdxmap::$xmap);

       $show['xml'] = htmlspecialchars(xp::pp($entity));

        // make a document with only this entity for metadata conformance testing ...
        $doc = $xp->document;
        $newentity = $entity->cloneNode(true);
        $empty = $doc->documentElement->cloneNode(false);
        $doc->documentElement->parentNode->replacechild($empty, $doc->documentElement);
        $empty->appendChild($newentity);
        $metadata_errors = PhphBackEnd::check_source($fed, $xp);
        $schema_errors = PhphBackEnd::verifySchema($xp, 'ws-federation.xsd');

        // we only use the message ...
        $metadata_errors = array_map(function($a) { return $a->message; }, $metadata_errors);
        $schema_errors = array_map(function($a) { return $a->message; }, $schema_errors);

        print self::render('show', compact('show', 'ats', 'summary', 'key', 'xtraats', 'approvable', 'unapprovable',
             'metadata_errors', 'schema_errors', 'logentries', 'xxx'));
    }

    static function superviewinfo($update = true)
    {
        $transforms = array();

        $types = array('feed', 'published', 'approved', 'tmp');

        $superviewcache = '/tmp/phph-superview-cache.json';
        $superview = array_fill_keys($types, array());
        if (file_exists($superviewcache)) { $superview = json_decode(file_get_contents($superviewcache), 1);  }
        if ($superview && !$update) { return $superview; }

        foreach(g::$config['destinations'] as $id => $dest) {
            foreach($types as $type) {
                $mdfile = self::fn($id, $type);
                if (!$mdfile) { continue; }
                $pi = pathinfo($mdfile);
                $path = sha1($pi['dirname']);
                if (!file_exists($mdfile)) {
                    unset($superview[$type][$path][$id]);
                    continue;
                }
                $schemaerrors = $metadataerrors = '-';
                if ($type === 'feed') {
                    $fn = $dest['cachepath'] . "summary-$id.json";
                    if (file_exists($fn)) {
                        $summary = json_decode(file_get_contents($fn), 1);
                        $schemaerrors   = $summary['schemaerrors'];
                        $metadataerrors = $summary['metadataerrors'];
                    }
                }
                if (empty($superview[$type][$path][$id]['mtime'])) {
                    $superview[$type][$path][$id]['mtime'] = 0;
                }
                $mtime = filemtime($mdfile);
                if ($mtime > $superview[$type][$path][$id]['mtime']) {
                    $xp = xp::xpFromFile($mdfile);
                    $doc = $xp->document;
                    //foreach($xp->query('/md:EntitiesDescriptor/ds:Signature/ds:SignedInfo/ds:Reference/ds:Transforms/ds:Transform/@Algorithm') as $t) {
                        //$transforms[$t->value][$basename] = 1;
                    //}
                    $idps = $xp->query('//md:EntityDescriptor/md:IDPSSODescriptor', $doc)->length;
                    $sps = $xp->query('//md:EntityDescriptor/md:SPSSODescriptor', $doc)->length;
                    $superview[$type][$path][$id] =
                        array('name'   => $id,
                              'idps'   => $idps,
                              'sps'    => $sps,
                              'mtime'  => $mtime,
                              'fn'     => $mdfile,
                              'serrs'  => $schemaerrors,
                              'mderrs' => $metadataerrors);
                }
                $superview[$type][$path][$id]['delta'] = self::relativeTime($superview[$type][$path][$id]['mtime'], time());
            }
        }
        sfpc::file_put_contents($superviewcache, json_encode($superview));
        return $superview;
    }

    static function summary($type, $fed) {

        $fn = g::$config['destinations'][$fed]['cachepath'] . "summary-$fed.json";
        if (file_exists($fn) && $type === 'feed') {
            return json_decode(file_get_contents($fn), 1);
        }

        $mdfile = self::fn($fed, $type);
        $xp = xp::xpFromFile($mdfile);
        $doc = $xp->document;

        $summary = array('entities' => array());

    /**
        $node = $xp->query('//md:EntitiesDescriptor/@validUntil', $doc)->item(0);
        $summary['validUntil'] = $node ? $node->nodeValue : '';
        $node = $xp->query('//md:EntitiesDescriptor/@cacheDuration', $doc)->item(0);
        $summary['cacheDuration'] = $node ? $node->nodeValue : '';
    */
        $entities = $xp->query('//md:EntityDescriptor', $doc);
        $c = 0;
        foreach($entities as $entity) {
            $c++;
            $res = PhphBackEnd::summary($xp, $entity, $fed, $type);
            $res['type'] = $type;
            $res['collisions'] = array();
            $res['schemaerrors'] = $res['metadataerrors'] = '-';

            $xtra = "";
            if (isset($summary[$res['entityid']])) { $xtra = ' * '; }
            $summary['entities'][$res['entityid']][] = $res;
        }
        return $summary;
    }

    static function fn($fed, $type)
    {
        $dest = g::$config['destinations'][$fed];
        $fn = null;
        if ($type === 'published' && $dest['filename']) { $fn = $dest['publishpath'] . $dest['filename']; }
        elseif (in_array($type, array('feed', 'tmp'))) { $fn = $dest['cachepath'] . "$type-$fed.xml"; }
        elseif (in_array($type, array('approved'))) { $fn = $dest['approvedpath'] . "$type-$fed.xml"; }
        return $fn;
    }

    static function dispatch()
    {
        if (isset($_POST['SAMLResponse'])) { self::auth(null, g::$config['approveusers']); }
        $path = preg_split("/[\?]/", $_SERVER['REQUEST_URI'], 0, PREG_SPLIT_NO_EMPTY);
        $path = (array)preg_split("/[\/]/", $path[0], 0, PREG_SPLIT_NO_EMPTY);

        $defaultcmd = 'overview';

        $cmd = isset($path[0]) ? $path[0] : $defaultcmd;

        $function = "phphfrontend::$cmd" . '__';
        $module = '../modules/' . $cmd . '.php';

        if (is_callable($function)) {
            call_user_func($function, $path);
        } elseif (file_exists($module)) {
            require $module;
        } else {
            die("Unknown function: '$function' or module: '$module'");
        }
    }

    static function relativeTime($time, $now)
    {
        $d = array(
            array(31104000,"Y", ''),
            array(2592000,"Mo", ''),
            array(86400,"D", ''),
            array(3600,"T", ''),
            array(60,"M",''),
            array(1,"S", ''),
        );

        $w = array();

        $return = "";
        $diff = $now - $time;
        $secondsLeft = $diff;
        $items = 0;
        $delim = '';
        $cont = false;

        foreach($d as $i => $x)
        {
             $w[$i] = intval($secondsLeft / $d[$i][0]);
             $secondsLeft -= ($w[$i] * $d[$i][0]);
             $cont = $cont || $w[$i] != 0;
             if ($cont) {
                //if ($items++ >= 2) break;
                $return .= sprintf("%s%02d", $delim, abs($w[$i]));
                $delim = ':';
             }
        }
        return ($diff < 0 ? '-' : '') . $return;
    }

    static function myprint_r($var) {
        if (is_array($var)) {
            echo "<table border=1 cellspacing=0 cellpadding=3>";
            if ($var) {
               foreach ($var as $k => $v) {
                       echo '<tr><td valign="top" style="width:40px;background-color:#F0F0F0;">';
                       echo '<strong>' . $k . ' (' . gettype($v) . ")</strong></td><td>";
                       self::myprint_r($v);
                       echo "</td></tr>";
               }
            } else {
                echo "<tr><td>[ ]</td></tr>";
            }
            echo "</table>";
        } elseif (is_bool($var)) {
            echo $var ? 'true' : 'false';
        } else {
            echo $var;
        }
    }

    static function render($template, $content, $super = array('main'))
    {
        if (is_array($content)) {
            extract($content);
        } // Extract the vars to local namespace
        ob_start(); // Start output buffering
        include(g::$config['templatespath'] . $template . '.phtml'); // Include the file
        $content = ob_get_contents(); // Get the content of the buffer
        ob_end_clean(); // End buffering and discard
        if ($super) {
            return self::render(array_shift($super), compact('content', 'debug'), $super); # array_shift shifts one element from super ...
        }
        return $content; // Return the content
    }
}