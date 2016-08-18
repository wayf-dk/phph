<?php

class xp {
    static $namespaces = array(
        'urn:oasis:names:tc:SAML:2.0:protocol' => 'samlp',
        'urn:oasis:names:tc:SAML:2.0:assertion' => 'saml',
        'urn:mace:shibboleth:metadata:1.0' => 'shibmd',
        'urn:oasis:names:tc:SAML:2.0:metadata' => 'md',
        'urn:oasis:names:tc:SAML:metadata:rpi' => 'mdrpi',
        'urn:oasis:names:tc:SAML:metadata:ui' => 'mdui',
        'urn:oasis:names:tc:SAML:metadata:attribute' => 'mdattr',
        'urn:oasis:names:tc:SAML:profiles:SSO:idp-discovery-protocol' => 'idpdisc',
        'urn:oasis:names:tc:SAML:profiles:SSO:request-init' => 'init',
        'http://www.w3.org/2001/XMLSchema-instance' => 'xsi',
        'http://www.w3.org/2001/XMLSchema' => 'xs',
        'http://www.w3.org/1999/XSL/Transform' => 'xsl',
        'http://www.w3.org/XML/1998/namespace' => 'xml',
        'http://schemas.xmlsoap.org/soap/envelope/' => 'SOAP-ENV',
        'http://www.w3.org/2000/09/xmldsig#' => 'ds',
        'http://www.w3.org/2001/04/xmlenc#' => 'xenc',
        'urn:oasis:names:tc:SAML:metadata:algsupport' => 'algsupport',
        'http://ukfederation.org.uk/2006/11/label' => 'ukfedlabel',
        'http://sdss.ac.uk/2006/06/WAYF' => 'sdss',
        'http://wayf.dk/2014/08/wayf' => 'wayf',
        'http://corto.wayf.dk' => 'corto',
        'http://refeds.org/metadata' => 'remd',
    );

    static $secapseman;

    static function xpFromFile($file)
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        if (file_exists($file)) { $doc->load($file, LIBXML_NONET); }
        return self::dom($doc);
    }

    static function xpFromString($xml)
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        // libxml_use_internal_errors(true);
        //libxml_clear_errors();
        if ($xml != null) { $res = $doc->loadXML($xml); }
        //libxml_get_errors();
        return self::dom($doc);
    }

    static function xpe()
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        return self::dom($doc);
    }

    static function dom($doc)
    {
        $xp = new DomXPath($doc);
        foreach(self::$namespaces as $full => $prefix) {
            $xp->registerNamespace($prefix, $full);
        }
        return $xp;
    }

    /**
     * Escape a string so it can be safely used as an xPath data value in an
     * xPath expression
     * @param  string $query The string to escape
     * @return string The escaped string as an concat() expression
     */

    static function escape($query)
    {
        $parts = preg_split("/([\"'])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE + PREG_SPLIT_NO_EMPTY);
        $params = array();
        foreach ($parts as $part) {
            $delim = "'";
            if ($part === "'") { $delim = '"'; }
            $params[] = $delim . $part . $delim;
        }
        if (sizeof($params) === 1) { return $params[0]; }
        return 'concat(' . implode(',', $params) . ')';
    }

    static function pp($element)
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        // importNode get the namespaces right - just saveXML doesn't
        $doc->appendChild($doc->importNode($element, true));
        $doc->loadXML($doc->saveXML());
        $doc->formatOutput = true;
        return $doc->saveXML();
    }

    /**
     * Evaluate the given xPath expression given an optional context.
     *
     * @param  DOMXPath $xp    The xPath to evaluate against
     * @param  string $query   xPath to evaluate
     * @param  string $context Optional context for relative xPath queries. The
     * default is to evaluate against the root element.
     *
     * @return string|DOMNodeList  If the result contains one result the node
     * value is returned otherwise a node list is returned.
     */
    public function optional($xp, $query, $context = null) {
        if (!$xp) { return null; }
        $res = null;
        $tmp = $context ? $xp->query($query, $context) : $xp->query($query);
        if ($tmp->length === 1) { $res = $tmp->item(0)->nodeValue; }
        return $res;
    }
}

xp::$secapseman = array_flip(xp::$namespaces);