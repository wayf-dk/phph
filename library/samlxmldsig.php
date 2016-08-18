<?php

class samlxmldsig {
    const BEGINCERTIFICATE = '-----BEGIN CERTIFICATE-----';
    const ENDCERTIFICATE = '-----END CERTIFICATE-----';



    static $dsig2php_methods = array(
        'http://www.w3.org/2000/09/xmldsig#sha1'            => 'sha1',
        'http://www.w3.org/2001/04/xmlenc#sha256'           => 'sha256',
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1'        => 'RSA-SHA1',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => 'RSA-SHA256',
    );

    static $php2dsig_methods;

    static function signxml($xp, $element, $certificate, $privatekey, $pw, $signatureMethod, $digestMethod, $insertbefore)
    {
        $elementc14n = $element;
        if ($element === $element->ownerDocument->documentElement) {
            $elementc14n = $xp->document; // c14n is much much faster on the document object for large files
        }

        $canonicalxml = $elementc14n->C14N(true, false);

        $digest = base64_encode(hash($digestMethod, $canonicalxml, TRUE));

        $xmldsig_signatureMethod = self::$php2dsig_methods[$signatureMethod];
        $xmldsig_digestMethod = self::$php2dsig_methods[$digestMethod];
        $signaturetext = <<<eos
<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
  <ds:SignedInfo>
    <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>
     <ds:SignatureMethod Algorithm="$xmldsig_signatureMethod"/>
     <ds:Reference URI="">
       <ds:Transforms>
         <ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>
         <ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>
       </ds:Transforms>
       <ds:DigestMethod Algorithm="$xmldsig_digestMethod"/>
      <ds:DigestValue></ds:DigestValue>
    </ds:Reference>
  </ds:SignedInfo>
  <ds:SignatureValue></ds:SignatureValue>
</ds:Signature>
eos;

        $f = $xp->document->createDocumentFragment();
        $f->appendXML($signaturetext);

        $element->insertBefore($f, $element->childNodes->item($insertbefore));

        $ID = $xp->query('@ID', $element)->item(0);
        if ($ID) {
            $ID = $ID->value;
        } else {
            $ID = '_' . uniqid('MDQ-', true);
            $element->setAttribute('ID', $ID);
        }
        $xp->query('//ds:Reference', $element)->item(0)->setAttribute('URI', "#$ID");
        $xp->query('//ds:DigestValue', $element)->item(0)->appendChild(new DOMText($digest));

        $signedinfo = $xp->query('./ds:Signature/ds:SignedInfo', $element)->item(0);
        $canonicalxml2 = $signedinfo->C14N(true, false);

        $signaturevalue = self::sign($privatekey, $pw, $canonicalxml2, $signatureMethod, $digestMethod);

        $xp->query('./ds:Signature/ds:SignatureValue', $element)->item(0)->appendChild(new DOMText(base64_encode($signaturevalue)));

        if ($certificate) {
            $certificate = self::ppcertificate($certificate, false);
            softquery::query($xp, $element, '/./ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate')->appendChild(new DOMText($certificate));
        }
    }

    static function sign($privatekey, $pw, $data, $signatureMethod, $digestMethod) {
        // if it is an hsm key don't interpret it as a PEM encoded key
        //$privatekey = file_get_contents($privatekey);
	    if (substr($privatekey, 0, 4) === 'hsm:') {
            $signaturevalue = self::signHSM($data, $privatekey, $digestMethod);
            if ($signaturevalue === false) {
                throw new Exception('Failure Signing Data: ' . $algo);
            }
        } else {
            $pkey_res = openssl_pkey_get_private($privatekey, $pw);
            openssl_sign($data, $signaturevalue, $pkey_res, $signatureMethod);
            openssl_free_key($pkey_res);
        }
        return $signaturevalue;
    }

    static function signHSM($data, $keyident, $algo) {
        // we do the hashing here - the $algo int/string confusion is due to xmlseclibs
        // openssl_sign confusingly enough accepts just the hashing algorithm
        $hashalgo = array(OPENSSL_ALGO_SHA1 => 'sha1', 'sha1' => 'sha1', 'SHA256' => 'sha256', 'sha256' => 'sha256');
        // always just do the RSA signing - we assume that the service can do the padding/DER encoding
        $algo = $hashalgo[$algo];

        switch ($algo) {
            case 'sha1':
                $t = pack('H*', '3021300906052b0e03021a05000414');
                break;
            case 'sha256':
                $t = pack('H*', '3031300d060960864801650304020105000420');
                break;
        }

        $mech = 'CKM_RSA_PKCS';
        // limit explode to 3 items - 'hsm', the sharedkey and the url, which may contain ':'s
        list($hsm, $sharedkey, $url) = explode(':', trim($keyident), 3);

        $opts = array('http' =>
          array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode(array(
                'data' => base64_encode($t . hash($hashalgo[$algo], $data, true)),
                'mech' => $mech,
                'digest' => '',
                'function' => 'sign',
                'sharedkey' => $sharedkey,
                )),
            'timeout' => 2
          )
        );

        $context  = stream_context_create($opts);
        $res = file_get_contents($url, false, $context);

        if ($res !== false) {
            $res = json_decode($res, 1);
            $res = base64_decode($res['signed']);
        }
        return $res;
    }

    /**
        SAML relevant subset of xmldsig checking:
        - the Signature element is always a child of the signed element
        - always same document references pt. including null URIs - be liberal ...
        - $element is checked and should be used as 'payload' afterwards

        to-do:
            reason for failing ...

    */

    static function checksign($xp, $element, $certificate)
    {
        $infokeys = array(
            'SignatureMethod'        => 'ds:SignedInfo/ds:SignatureMethod/@Algorithm',
            'CanonicalizationMethod' => 'ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm',
            'DigestMethod'           => 'ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm',
            'Transforms'             => 'ds:SignedInfo/ds:Reference/ds:Transforms/ds:Transform/@Algorithm',
            'X509Certificate'        => 'ds:KeyInfo/ds:X509Data/ds:X509Certificate',
            'SignatureValue'         => 'ds:SignatureValue',
            'DigestValue'            => 'ds:SignedInfo/ds:Reference/ds:DigestValue',
            'ID'                     =>  '../@ID', // always Signature's parent ...
            'URI'                    => 'ds:SignedInfo/ds:Reference/@URI',
        );

        $multikeys = array('Transforms');

        $signature = $xp->query("ds:Signature", $element)->item(0);
        if (is_resource($certificate) && get_resource_type($certificate) === 'OpenSSL key') {
            $publickey = $certificate;
        } else {
            $publickey = openssl_pkey_get_public(self::ppcertificate($certificate));
        }
        if (!$signature || !$certificate || !$publickey) { return null; }

        $info = array();
        foreach( $infokeys as $n => $infoxp) {
            $info[$n] = null;
            $multi = in_array($n, $multikeys);
            if (($item = $xp->query($infoxp, $signature)) && $item->length) {
                $nodelist = array(); foreach($item as $node) { $nodelist[] = $node->nodeValue; }
                // check for multiple References ...
                if ($multi) {
                    $info[$n] = $nodelist;
                } else {
                    if (sizeof($nodelist) !== 1) {
                        // ERROR
                    }
                    $info[$n] = $nodelist[0];
                }
            }
        }

        $si_exclusive = preg_match('<^http://www.w3.org/2001/10/xml-exc-c14n#>', $info['CanonicalizationMethod']);
        $si_withcomments = preg_match('/WithComments$/', $info['CanonicalizationMethod']);

        $exclusive = $withcomments = false;
        foreach($info['Transforms'] as $tr) {
            $exclusive = $exclusive || preg_match('<^http://www.w3.org/2001/10/xml-exc-c14n#>', $tr);
            // it doesn't seem to work if we obey what the document tells us here ...
            //$withcomments = $withcomments || preg_match('/WithComments$/', $tr);
        }

        $isvalid = ('#' . $info['ID']) === $info['URI'] || $info['URI'] === ''; // null URI
        //if ($nulluri) { $withcomments = false;  /* @g::$log['null'][$id]++; */ }

        $signedInfoC14N = $xp->query("ds:SignedInfo", $signature)->item(0)->C14N($si_exclusive,  $si_withcomments);
        $signature->parentNode->removeChild($signature);

        if ($element === $element->ownerDocument->documentElement) {
            $element = $xp->document; // c14n is much much faster on the document object for large files
        }

        $signedElementC14N = $element->C14N($exclusive, $withcomments);

        $info['DigestValueComputed'] = base64_encode(hash(self::$dsig2php_methods[$info['DigestMethod']], $signedElementC14N, TRUE));

        $isvalid = $isvalid && $info['DigestValueComputed'] === $info['DigestValue'];
        $isvalid = $isvalid && 1 === openssl_verify($signedInfoC14N, base64_decode($info['SignatureValue']), $publickey
                                                   ,self::$dsig2php_methods[$info['SignatureMethod']]);
        return $isvalid;
    }

    static function ppcertificate($certificate, $pem = true)
    {
        $certificate = chunk_split(str_replace(array(self::BEGINCERTIFICATE, self::ENDCERTIFICATE, " ", "\t", "\r", "\n"), "", $certificate), 64, "\n");
        if ($pem) { $certificate = self::BEGINCERTIFICATE . "\n$certificate"  . self::ENDCERTIFICATE; }
        return $certificate;
    }
}

samlxmldsig::$php2dsig_methods = array_flip(samlxmldsig::$dsig2php_methods);
