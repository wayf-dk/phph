<?php

class FlattenTest extends PHPUnit_Framework_TestCase
{
    private $xp, $roles, $map, $flat;

    protected function setUp()
    {
        $this->xp = xp::xpFromFile(__DIR__  . "/fixtures/FlattenTest.xml");
        $this->roles = array(
            "role/" => "",
        );
        $this->map = array(
            "one:id" => "/wayf:one/@id",
            "one:two:#" => "/wayf:one/wayf:two[#]",
            "one:two:#:id" => "/wayf:one/wayf:two[#]/@id",
            "redundant" => "/wayf:redundant",
        );
        $this->flat = flatten::flattenx($this->xp, $this->xp->document, '', $this->roles, $this->map);
    }

    public function testFlattenDocument()
    {
        $this->assertArrayHasKey("role/one:id", $this->flat);
        $this->assertArrayHasKey("role/one:two:#", $this->flat);
        $this->assertArrayHasKey("role/one:two:#:id", $this->flat);
        $this->assertArrayNotHasKey("role/redundant", $this->flat);
    }

    public function testRoundtrip()
    {
        $dstXp = xp::xpe();
        $hierarchic = flatten::hierarchize($this->flat, $dstXp, $dstXp->document, '', $this->roles, $this->map);
        $this->assertEqualXMLStructure($this->xp->document->documentElement, $dstXp->document->documentElement);
    }
}

?>