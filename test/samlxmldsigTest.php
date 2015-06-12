<?php

class samlxmldsigtest extends PHPUnit_Framework_TestCase
{
    public function testSignaturesAssertion()
    {
        $signedxmlfile = __DIR__ . '/fixtures/samldsigAssertionTest.xml';
        $xp = xp::xpFromFile($signedxmlfile);
        $element = $xp->query('/samlp:Response/saml:Assertion')->item(0);

        $certificate = $xp->query('ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate', $element)->item(0)->nodeValue;
        $certificate = chunk_split(str_replace(array("-----BEGIN CERTIFICATE-----\n", "-----END CERTIFICATE-----", " ", "\t", "\r", "\n"), "", $certificate), 64, "\n");
        $certificate = "-----BEGIN CERTIFICATE-----\n$certificate-----END CERTIFICATE-----\n";

        $this->assertTrue(samlxmldsig::checksign($xp, $element, $certificate));
        // Signature is now gone ...
        $this->assertNull(samlxmldsig::checksign($xp, $element, $certificate));

        $xp = xp::xpFromFile($signedxmlfile);
        $element = $xp->query('/samlp:Response/saml:Assertion')->item(0);
        $element->setAttribute('fake', 'evil'); // introduce a change that invalidates the signature
        $this->assertFalse(samlxmldsig::checksign($xp, $element, $certificate));
    }


    public function testSignaturesResponse()
    {
        $signedxmlfile = __DIR__ . '/fixtures/samldsigResponseTest.xml';
        $xp = xp::xpFromFile($signedxmlfile);
        $element = $xp->query('/samlp:Response')->item(0);

        $certificate = $xp->query('ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate', $element)->item(0)->nodeValue;
        $certificate = chunk_split(str_replace(array("-----BEGIN CERTIFICATE-----\n", "-----END CERTIFICATE-----", " ", "\t", "\r", "\n"), "", $certificate), 64, "\n");
        $certificate = "-----BEGIN CERTIFICATE-----\n$certificate-----END CERTIFICATE-----\n";

        $this->assertTrue(samlxmldsig::checksign($xp, $element, $certificate));
        // Signature is now gone ...
        $this->assertNull(samlxmldsig::checksign($xp, $element, $certificate));

        $xp = xp::xpFromFile($signedxmlfile);
        $element = $xp->query('/samlp:Response')->item(0);
        $element->setAttribute('fake', 'evil'); // introduce a change that invalidates the signature
        $this->assertFalse(samlxmldsig::checksign($xp, $element, $certificate));
    }
}
