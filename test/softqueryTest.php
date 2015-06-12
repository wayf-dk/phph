<?php

class softquerytest extends PHPUnit_Framework_TestCase
{
    private $xp, $doc;

    // Make xpath query and return length of returned list of nodes
    protected function xpLength($query)
    {
        return $this->xp->query($query, $this->doc)->length;
    }

    // Make xpath query and return value of the first node
    protected function xpValue($query)
    {
        $nodes = $this->xp->query($query, $this->doc);
        return $nodes->item(0)->nodeValue;
    }

    // Set up xpath with empty document
    // This is run before every test
    protected function setUp()
    {
        $this->xp = xp::xpe();
        // $this->doc = softquery::query($this->xp, $this->xp->document, '/md:root');
        // $this->doc = $this->xp->document;
        $node = $this->xp->document->createElement('md:root');
        $this->doc = $this->xp->document->appendChild($node);
    }

    // Run softquery with single basic query
    // Should insert an element since no existing element can be found
    public function testInsert()
    {
        $ent1 = softquery::query($this->xp, $this->doc, '/md:one');
        $this->assertEquals(1, $this->xpLength('md:one'));
    }

    // Run softquery with single deep query with attribute
    // Should insert elements recursively in order to insert element deep in the document
    public function testInsertDeepWithAttribute()
    {
        $ent1 = softquery::query($this->xp, $this->doc, '/md:one/md:two/md:three[@attr="three"]');
        $this->assertEquals(1, $this->xpLength('md:one/md:two/md:three'));
        $this->assertEquals("three", $this->xpValue('md:one/md:two/md:three/@attr'));
    }

    // Run softquery repeatedly
    // Should fetch existing element on repeat query
    public function testInsertRepeat()
    {
        $ent1 = softquery::query($this->xp, $this->doc, '/md:one');
        $ent2 = softquery::query($this->xp, $this->doc, '/md:one');
        $this->assertEquals(1, $this->xpLength('md:one'));
        $this->assertSame($ent1, $ent2);
    }

    // Run softquery with position of element specified a 2 with existing element
    // Should insert a single additional element at position
    public function testInsertPosition()
    {
        $ent1 = softquery::query($this->xp, $this->doc, '/md:one');
        $ent2 = softquery::query($this->xp, $this->doc, '/md:one[2]');
        $this->assertEquals(2, $this->xpLength('md:one'));
        $this->assertNotSame($ent1, $ent2);
    }

    // Run softquery with position of element specified as 5
    // Should batch insert 5 new elements in order to return element at specified position
    public function testInsertPositionBatch()
    {
        $ent1 = softquery::query($this->xp, $this->doc, '/md:one[5]');
        $this->assertEquals(5, $this->xpLength('md:one'));
    }

    // Run softquery with nested batches
    public function testInsertNestedBatches()
    {
        $ent1 = softquery::query($this->xp, $this->doc, '/md:one[5]/md:two[5]');
        $this->assertEquals(5, $this->xpLength('md:one'));
        $this->assertEquals(5, $this->xpLength('md:one[5]/md:two'));
    }
}

?>