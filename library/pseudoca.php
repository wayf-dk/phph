<?php

/**
    Pasudoca creates selfsigned certificates

    It needs a private key, extracts the public key and constructs a
    selfsigned certificate with the given CN as both issuer and subject
*/

class pseudoca {

    static $algos = array(
        'sha1'   => array('digest_oid' => '1.3.14.3.2.26',          'sigalg_oid' => '1.2.840.113549.1.1.5'),
        'sha256' => array('digest_oid' => '2.16.840.1.101.3.4.2.1', 'sigalg_oid' => '1.2.840.113549.1.1.11'),
    );

    static $private_key;
    static $public_key_der;
    static $private_key_info;
    static $digest_algo;
    static $use_hsm = false;

    static function setprivatekey($private_key, $certificate, $digest_algo)
    {
        self::$private_key = file_get_contents($private_key);
        self::$use_hsm = substr(self::$private_key, 0, 4) === 'hsm:';
        if (!self::$use_hsm) {
            self::$private_key = openssl_pkey_get_private(self::$private_key);
            if (self::$private_key === false) {
                trigger_error(sprintf("Error opening private key: %s %s\n", $private_key, openssl_error_string()));
            }
        }
        self::$digest_algo = $digest_algo;
        self::$private_key_info = openssl_pkey_get_details(openssl_pkey_get_public($certificate));
        self::$public_key_der = base64_decode(preg_replace('/(-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----)/', '', self::$private_key_info['key']));
    }

    static function selfsign($cn)
    {
        $tbscertificate = self::TBSCertificate($cn, self::$public_key_der, self::$digest_algo);
        $signature = self::$use_hsm ? self::hsm_encode($tbscertificate, self::$digest_algo) : self::my_rsa_encode($tbscertificate, self::$digest_algo);

        $new_cert =  self::sequence(
            $tbscertificate
          . self::sequence(self::s2oid(self::$algos[self::$digest_algo]['sigalg_oid']) . self::null())
          . self::bitstring($signature)
        );

        return chunk_split(base64_encode($new_cert), 64, "\n");
    }

    static function TBSCertificate($cn, $subjectPublicKeyInfo, $algo)
    {
        $rdn = self::name($cn);
        return self::sequence(
          //  self::tlv("\xA0", self::d2i(2))        // version
           self::d2i('7') // serial number
          . self::sequence(self::s2oid(self::$algos[$algo]['sigalg_oid']) . self::null()) // signature algo + null
          . $rdn
          . self::sequence( // validity
              self::utctime('150101000000Z')
            . self::utctime('251231235959Z')
            )
          . $rdn  // subject
          . $subjectPublicKeyInfo
        );
    }

    static function name($cn)
    {
        return
          self::sequence(
            self::set(
              self::sequence(
                self::s2oid('2.5.4.3') // type CN
              . self::printablestring($cn)
              )
            )
        );
    }

    static function tlv($tag, $val)
    {
        return $tag . self::len($val) . $val;
    }

    static function s2oid($s)
    {
        $e = explode('.', $s);
        $der = chr(40 * $e[0] + $e[1]);

        foreach (array_slice($e, 2) as $c) {
            $mask = 0;
            $derrev = '';
            while ($c) {
                $derrev .= chr(bcmod($c, 128) + $mask);
                $c = bcdiv($c, 128, 0);
                $mask = 128;
            }
            $der .= strrev($derrev);
        }
        return "\x06" . self::len($der) . $der;
    }

    static function sequence($pdu)
    {
        return "\x30" . self::len($pdu) . $pdu;
    }

    static function set($pdu)
    {
        return "\x31" . self::len($pdu) . $pdu;
    }

    static function bitstring($s)
    {
        return "\x03" . self::len($s) . $s;
    }

    static function octetstring($s)
    {
        return "\x04" . self::len($s) . $s;
    }

    static function printablestring($string)
    {
        return "\x0c" . self::len($string) . $string;
    }

    static function utctime($time)
    {
        return "\x17" . self::len($time) . $time;
    }

    static function null()
    {
        return "\x05\x00";
    }

    static function len($i)
    {
        $i = strlen($i);
        if ($i <= 127)
            $res = pack('C', $i);
        elseif ($i <= 255)
            $res = pack('CC', 0x81, $i);
        elseif ($i <= 65535)
            $res = pack('Cn', 0x82, $i);
        else
            $res = pack('CN', 0x84, $i);
        return $res;
    }

    static function d2i($d)
    {
        $der = '';
        $dd = $d;
        while ($d) {
            $x = bcmod($d, 256);
            $der .= chr(bcmod($d, 256));
            $d = bcdiv($d, 256, 0);
        }
        if (ord(substr($der, -1)) > 0x7F) { $der .= "\x00"; }
        return "\x02" . self::len($der) . strrev($der);
    }

    static function hsm_encode($data, $algo)
    {
        return "\x00" . samlxmldsig::signHSM($data, self::$private_key, $algo);
    }

    static function my_rsa_encode($data, $algo)
    {
        $digest = hash($algo, $data, true);

        $t = self::sequence(
               self::sequence(
                 self::s2oid(self::$algos[$algo]['digest_oid'])
               . self::null()
               )
             . self::octetstring($digest));

        $pslen = self::$private_key_info['bits']/8 - (strlen($t) + 3);

        $eb = "\x00\x01" . str_repeat("\xff", $pslen) . "\x00" . $t;

        if (openssl_private_encrypt($eb, $signature, self::$private_key, OPENSSL_NO_PADDING) === false) {
            trigger_error(sprintf("Error encrypting: %s\n", $private_key, openssl_error_string()));
        }
        return "\x00" . $signature;
    }
}