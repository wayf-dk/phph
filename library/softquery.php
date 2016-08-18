<?php

class softquery
{
    /**
        generative xpath query - ie. mkdir -p for xpath ...
        Understands simple xpath expressions including indexes and attribute values
    */

    static function query($xp, $context, $query, $rec = null, $before = false)
    {
        // $query always starts with / ie. is always 'absolute' in relation to the $context
        $attr = false;
        // split in path elements, an element might include an attribute expression incl. value eg.
        // /md:EntitiesDescriptor/md:EntityDescriptor[@entityID="https://wayf.wayf.dk"]/md:SPSSODescriptor
        preg_match_all('#/([^/"]*("[^"]*")?[^/"]*)#', $query, $d);
        $path = $d[1];
        foreach ($path as $element) {
            $nodes = $xp->query($element, $context);
            if ($nodes->length) {
                $attr = $element[0] === '@';
                $context = $nodes->item(0);
                continue;
            } else {
                $attr = preg_match('/^(?:(\w+):?)?         # optional localnamespace
                                      ([^\[@]*)            # element not containing [ or @
                                      (?:\[(\d+)\])?       # optional position
                                      (?:\[?@ ([^=]+)      # attributename prefixed by optional [ - for expression
                                      (?:="([^"]*)"])?)?   # optional attribute value
                                      ()$/x'               # only to make sure the optional attribute values is set in $d!
                                      , $element, $d);
                if (!$attr) { exit("softquery::query: '$query' not ok. Element: '$element'\n"); }
                list($dummy, $ns, $element, $position, $attribute, $value) = $d;

                if ($element) {
                    if ($position == 0) { // [0] does not exists so always add the element - we still get the path though
                        $newcontext = self::createElementNS($xp, $ns, $element, $context, $before);
                    } else if ($position) {
                        $i = 1;
                        $newcontext = null;
                        while ($i <= $position) {
                            $existingelement = $xp->query("$ns:$element" . "[$i]", $context);
                            if ($existingelement->length) {
                                $newcontext = $existingelement->item(0);
                            } else {
                                $newcontext = self::createElementNS($xp, $ns, $element, $context, $newcontext ? $newcontext->nextSibling : false);
                            }
                            $i++;
                        }
                    } else {
                        $newcontext = self::createElementNS($xp, $ns, $element, $context, $before);
                    }
                    $context = $newcontext;
                }
                if ($attribute) {
                    $newcontext = $context->setAttribute($attribute, $value);
                    if ($value === '') { $context = $newcontext; } // if we don't have a value
                }
            }
        }
        // adding the provided value always at end ..
        if ($rec !== null) {
            $context->nodeValue = htmlspecialchars($rec);
        }
        return $context;
    }

    static function create($xp, $context, $query, $rec = null, $before = null)
    {
        // $query always starts with / ie. is always 'absolute' in relation to the $context
        $attr = false;
        // split in path elements, an element might include an attribute expression incl. value eg.
        // /md:EntitiesDescriptor/md:EntityDescriptor[@entityID="https://wayf.wayf.dk"]/md:SPSSODescriptor
        preg_match_all('#/([^/"]*("[^"]*")?[^/"]*)#', $query, $d);
        $path = $d[1];
        foreach ($path as $element) {
            $attr = preg_match('/^(?:(\w+):?)?         # optional localnamespace
                                  ([^\[@]*)            # element not containing [ or @
                                  (?:\[(\d+)\])?       # optional position
                                  (?:\[?@ ([^=]+)      # attributename prefixed by optional [ - for expression
                                  (?:="([^"]*)"])?)?   # optional attribute value
                                  ()$/x'               # only to make sure the optional attribute values is set in $d!
                                  , $element, $d);
            if (!$attr) { exit("softquery::query: '$query' not ok. Element: '$element'\n"); }
            list($dummy, $ns, $element, $position, $attribute, $value) = $d;

            if ($element) {
                $context = self::createElementNS($xp, $ns, $element, $context, $before);
                $before = null; // can only be used once - for the first element
            }
            if ($attribute) {
                $newcontext = $context->setAttribute($attribute, $value);
                //if ($value === '') { $context = $newcontext; } // if we don't have a value
            }
        }
        // adding the provided value always at end ..
        if ($rec !== null) {
            $context->nodeValue = htmlspecialchars($rec);
        }
        return $context;
    }

    static function createElementNS($xp, $ns, $element, $context, $before)
    {
        $namespace = null;
        $prefixed = $element;
        if ($ns) {
           $namespace = xp::$secapseman[$ns];
           $prefixed = $ns . ':' . $element;
        }
        $newelement = $xp->document->createElementNS($namespace, $prefixed);
        if ($before) {
            // this is a hack - have to look into it ...
            $beforewhat = is_bool($before) ? $context->firstChild : $before;
            $context = $context->insertBefore($newelement, $beforewhat);
        } else {
            $context = $context->appendChild($newelement);
        }
        return $context;
    }
}