# PHPH - a PHederation PHeeder written in PHP

## Screencast

<iframe width="420" height="315" src="https://www.youtube-nocookie.com/embed/Jv_xYdd1Hrs?rel=0&amp;showinfo=0" frameborder="0" allowfullscreen></iframe>

It is also available here: [https://youtu.be/Jv_xYdd1Hrs]( https://youtu.be/Jv_xYdd1Hrs)

## Background

PHPH is a SAML 2.0 metadata handler written to solve some specific issues for WAYF:

- WAYF has a strict process for minimising and approving the attribute release policy for SPs that are registered directly by WAYF. PHPH makes it possible for WAYF to do the same for imported interfederation entities, while still keeping - the rest of - an entity's metadata up to date.

- The same functionality allows WAYF to specify a specific operational ARP for entities that describes their ARP in a more abstract wayf using eg. entity categories, federation defaults or MARI.

- The need to consolidate metadata handling to be able to prepare metadata for specific services in one place and with a minimum of effort. An example is the preparation of metadata for [BIRK](http://www.wayf.dk/en/component/content/article/412) to allow the publication of (proxy) IdPs to eduGAIN and Kalmar2 and internal operational metadata. Hitherto this has not been feasible due to the complexity of the un-consolidated metadata handling.

- To be able to export metadata from our JANUS database without dependency on quirks in SSP's handling of metadata in PHP and XML format.

- To be able to do general metadata exploration and quality assurance. PHPH includes Ian Young's XSLT metadata testing rules and allows WAYF to write new rules that can be shared among federations or use rules created by others.

- The current approval process for metadata registered in WAYF's own metadata repository - JANUS - is manual. We expect to be able to use the merge/replace facilities in PHPH to be able to let our partners update their metadata at will, while still keeping the WAYF approved parts fixed.

## Table of Contents


<!--- toc-placeholder --->


## Overview

PHPH consists of a back-end program and a web front-end.

The back-end runs at regular intervals or when an event occurs and

- collects metadata from a list of configured sources and check the schema, signature and general validity of the metadata. The downloading happens in parallel guided by the given cacheDuration and validUntil attributes of the specific feed.

- merges and filters/modifies the metadata according to a configuration optionally using simple PHP filters

- and finally publishes the signed metadata as 'static' metadata xml files.

- publishes complete test metadata sets where all the certificates (ie. public keys) are replaced with one test certificate allowing complete 'impersonating' in a testing environment.

The web front-end allows a user to:

- edit the ARP for and approve interfederation (pt. eduGAIN and Kalmar2) entities for use in WAYF

- monitor the result of the last run of the back-end program and optionally start it with specific parameters

- monitor the configuration as a graph showing the dependencies between the sources, filters and the published result

- explore the content of all metadata including the intermediate results

The web front-end is open for public exploration at [https://phph.wayf.dk](https://phph.wayf.dk) - only authorized users can make changes, but to allow all users to see the same interface, actions by unauthorized users will have no effect on the back-end even though the interface elements for initiating these actions are visible and enabled.

## Web Front-end

The web front-end has 4 tabs:

### Overview Tab

The Overview Tab is the main view of the front-end. It allows a user to explore the feeds that are the basis for the approval process. The master view list all entities and the user can filter, search and sort the entities. A detail view is presented when the user clicks on a specific entity.

#### "Search Language"

The Overview Tab provides a search field which makes it possible to search for /filter entities based on the summary data for each entity. The specific contents of the summary is configuration dependent, but the summary view (see below) shows what is available. The search field supports a very simple 'search language':

- only entities that match all terms is shown (ie. implied AND)

- words (\w+) provides a truncated search among the summary keywords

- colon delimited words (\w+):(\w*) provides a search in the $1 field of the summary. If $2 is empty or $1 is a boolean field the search is for a 'truthy' value. If $2 is not empty the search is a truncated search in field $1 depending on the type of field $1. If the values of field $1 is an array the search is successfull if just one value is found (implied OR). The fieldname $1 is case sensitive. The value $2 is case insensitive.

- By using the  delimiter :! the result of that term is negated.

- By using the delimiter (\w+):~(\w*),(\w*) you can make range searches eg: registrationInstant:

- It is possible to use javascript regular expression special chars - eg. $ to un-truncate a search - in values ($2).

- Common searches can be configured as easy accessible buttons

#### Detail View

The detail view has 5 - 7 collapsable elements:

 - an overview with general information about the entity

 - an optional Log section with information on the approval history of the entity (SPs only)

 - an optional Attributes section that shows which attributes the entity has requested and which it has been granted. Unsupported attributes the entity has requested is also shown. Authenticated users can persist changes. The Ticket ID parameter is uset to link log entries to the description in the organisations change management system. (SPs only)

  - a Metadata ("flat") view that shows the entity's metadata in a tabular format with the "flat" format keys as labels.

  - a Metadata ("XML") view that show the entity's metadata in native XML

  - a Schema Errors view that shows the schema errors for en entity.

  - a Metadata Errors view that shows the result of running the metadata thru Ian Young's XSLT rules for metadata. The rules are possibly augmented with additional rules from WAYF and others.

  - a Summary view that shows which information the rendering engine and the search engine for the overview has access to.

### Tail Tab

The Tail Tab allows a user to see the log entries from the last run of the back-end and (authenticated users only) initiate a new run with specific parameters. The Tail view is split in two:

  - the summary view that shows the last logentry for each feed - typically a summary entry

  - the log view that shows alle the monitor log entries for the last run.

The user can filter the log view by host, status, tag or feed by clicking on a a value in the summary view.

The logentries contains a log tag (typically a timestamp) that allows an operator to find the actual syslog entries if need be.

### Config Tab

The Config Tab shows the configuration and the dependencies between sources, temporaries and puslished feeds as a graphviz graph. Mainly for debugging configuration files.

### Debug Tab

The Debug Tab shows all the metadata feeds/files in the system with numbers for SPs and IdPs and the delta of the last modification time. For sources the modification date is when the cacheDuration runs out and thus the show delta it typically negative. A user can get an overview of a feed/file by clicking on it's name and a XML view by clicking on the XML link next to it's name.

### MDQ

In addition the front-end also presents a MDQ interface to the published metadata sets at https://phph.wayf.dk/mdq/&lt;feed&gt;/

To allow the MDQ service to be used from BIRK, which looks up entities by Location/endpoint the MDQ service also responds to (for selected feeds only) requests where the sha1 'transformed' Identifier is the sha1 of a Location of an EndPointType.

## Back-end

The back-end PHPH is typically run by using a small driver that collects parameters and configuration and then calls md.php:

    $md = new md($logtag);
    $md->config(g::$config);
    $md->getMetadataSources(g::$now, 'http'); // all in parallel non-blocking
    $md->getMetadataSources(g::$now, 'file'); // blocking local files
    $md->preparemetadata(g::$now);
    $md->export_destinations(g::$now);

### Backend-end command line parameters

The md class currently uses g::$config for configuration parameters and g::$options for command line options. The following command line options are used:

PHPH uses longopts and unless otherwise noted presence on the commandline truthifies a default false value.


- `forcerefresh` - renew all sources, regardless of cache validity

- `prepareonly` - do not update any sources, regardless of cache validity

- `nojanus` - do not get metadata from JANUS, a WAYF specific option to the driver to save time when debugging

- `silent` - do not print the messages from schema- and metadatachecks

- `config` - the name of the configuration file. Requires a value

## Configuration

PHPH is configured in a common file for the front- and back-end in PHP ini format. The configuration has the following parts:

- global

  General configuration parameters for PHP

- [SAML]

   The SAML metadata necessary for running PHPH as a SAML service provider

- [defaults]

  Parameters that are the default keys/value for each feed. Each of the parameters can be overridden by specifying the corresponding key for the feed itself

- [destinations]

 The specification of each feed and it's parameters. Unless explicitly overidden all default parameters are inherited from the `defaults` config section

- [summaryfields]

 List of keys and corresponding xpaths for extracting information from a feed about the entities in it. The information is available in a per feed json file. This is used to speed up the display of feed overviews.

- [feeds]

    List of feeds (from the destination section) that is merged into the main approve interface for PHPH. These are merged into one to allow an administrator to obtain an overview of all the approved entities to limit the possibility of 'collisions' where the 'same' entity is approved from different feeds.

- [attributesupport]

 The list of supported attributes in the federation that uses PHPH. Allows an administrator to select which attributes should be requested (and always marked as required) for an approved entity. PHPH uses SAML2.0 uri format internally, even though this list for readability reasons is in basic format.

- [entitycategories]

 A list of shorthands for selected entity categories. This is to allow short labels eg. in the overview tab of PHPH.

### Configuration directory structure

For running PHPH access to the following directories should be specified in the configuration. Unless noted as global they can be specified on a per-feed basis. If specified as a relative name they ar prefixed by g::$options['basepath'].

- `approvedpath` - global where the approved entities are kept in a per-feed metadata subset file. The directory and the files must be writable by the user running the web-front-end.

- `schemapath` - global. The schema definitions neccessary to check metadata files. Default `schemas`

- `templatepath` - global. The html templates for the frontend. Default `templates`

- `rulespath` - global. Ian's XSLT rules suite. Default  `_rules`

- `cachepath` - Cache of all feeds, temporary files and summaries. All files are prefixed with their 'type' (`feed`, `tmp`, `approved`, `summary`, `sha1toentitymap`) and named after the feed. Default `cache`

- `publishpath` - where to publish the public accessible metadata. They actual name of the published file is specified in a per-feed parameter `filename`.

- `testpublishpath` - where to publish the test metadata. They `filename` parameter is used as the filename.

- `certspath` - Where to find certificates and private keys for signing metadata and verifying signatures in metadata. Certificates are named by the the `certname` config parameter. Private keys are named by the sha1 (in hex) of the modulus of the key pair. When using the HSM in conjunction with goEleven the private keys contains an url that names the actual key in the relevant partition on the HSM. Default `certs`.

## Installation Directory Structure

As distributed the PHPH directory has the following subdirectories:

- `_rules` - the default `rulespath`

- `approved` - the default `approvedpath`

- `cache` - the default `cachepath`

- `config` - the default config directory NOT

- `doc` - documentation

- `library` - the generic PHPH libraries

- `local` - the Libraries and script specific to an installation of PHPH

- `metadataping` - source and binary for the metadataping Go program including the driver script that actually calls the backend.

- `public` - the web root for the frontend

- `schemas` - the default `schemapath`

- `templates` - the default `templatespath`

- `test` - test scripts and data

## Libraries

PHPH uses a the follwing typically static classes:

### Flatten.php

Implements the conversion between the 'flat' format and XML.

One of the goals for PHPH was to be able to generate standard XML metadata from JANUS. Janus uses a 'flat' key/value format in it's database and PHPH provides a configurable method for mapping between this format and XML. The mapping takes advantage of the fact that SAML2 metadata is 'very' well formed data/protocol XML as opposed to 'document' XML which can contain elements in text elements.

Here is an example of a mapping for the AttributeConsumingService element:

    AttributeConsumingService:#:index = '/md:AttributeConsumingService[#]/@index'
    AttributeConsumingService:#:isDefault = '/md:AttributeConsumingService[#]/@isDefault'
    AttributeConsumingService:#:ServiceName:# = '/md:AttributeConsumingService[#]/md:ServiceName[#]'
    AttributeConsumingService:#:ServiceName:#:lang = '/md:AttributeConsumingService[#]/md:ServiceName[#]/@xml:lang'
    AttributeConsumingService:#:ServiceDescription:# = '/md:AttributeConsumingService[#]/md:ServiceDescription[#]'
    AttributeConsumingService:#:ServiceDescription:#:lang = '/md:AttributeConsumingService[#]/md:ServiceDescription[#]/@xml:lang'

    AttributeConsumingService:#:RequestedAttribute:#:FriendlyName = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@FriendlyName'
    AttributeConsumingService:#:RequestedAttribute:#:Name = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@Name'
    AttributeConsumingService:#:RequestedAttribute:#:NameFormat = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@NameFormat'
    AttributeConsumingService:#:RequestedAttribute:#:isRequired = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@isRequired'

The #s represent actual numbers and is used to represent a deep hieracial structure using only 'flat' keys - like a represenation of a hierarchal file structure using only full paths. The left column is the 'flat' format template and the right colomn is the corresponding PHPH XPath template. The `flatten` class is able to do two-wayf conversions between these two formats.

Besides being used in JANUS the flat format is also well suited as an automatically generated web form editing interface for metadata. Except for the read-only detail view in the web front-end this is not yet used in PHPH.

### Softquery.php

To allow convenient generation of XML the softquery class supports a `query` function which takes an XPath object, a context (DOMElement object) and a (subset of) XPath query as parameters. The `query` always returns an element that satisfies the query - generating the elements in the XPath on the fly as needed.

### G.php

Small utility class to keep application 'global' things in one place

### Phphfrontend.php

The web front-end

### Phphbackend.php

The main PHPH back-end class

### Pseudoca.PHP

Utility for creating entity specific self signed certificates.

### Samlmdxmap.php

The mapping table between metadata in
the flat format and XML (in XPath notation)

### Sfpc.php

Transactional safe file\_put\_contents

### Sporto.php

Simple SAML service provider

### SAMLxmldsig.php

SAML specific (metadata/responses/assertions) implementation of `XML-Signature Syntax and Processing`. Currently only supports sha1 and sha256 as digest methods and RSA-SHA1 and RSA-SHA256 as signature methods. Signs and check signature of SAML metadata or protocol messages. Supports signing by HSM using the [goeleven](https://github.com/wayf-dk/goeleven) REST interface.

### Xp.php

PHPH operates mainly on DOMDocuments and DOMObjects, but as XPaths is used though out as well the main data structure is the XPath object. It contains a reference to the DOMDocument to which it belongs so it simplifies the code to use XPaths objects as the basic datastructure. Xp.php generates SAML namespace prepared XPath objects from XML files or strings.

## Test

During development and under deployment the correct function of PHPH can be verified by running the test.sh script in the `test` directory. It runs a series of unittest for the basic libraries and an integration test for the backend using local data. The frontend is not tested automatically.

Unittests for the following classes are defined:

- `flatten`, `samlxmldsig`, `softquery`, `xp`.

## Running PHPH

To prevent race contitions only one instance of the back-end is allowed to run at a time. The back-end is meant to be run at regular intervals initiated eg. by a cron job. But it is also possible to run it on-demand eg. after receiving an update event from JANUS. To allow request for a run to queue up a metadata-ping http service is running at a specified port. All requests for starting the back-end including the cron job must use this service.

The actual command to be run as the back-end service is passed to the metadata-ping service as an environment variable METADATA\_RUN. The default interface the service listens to is localhost:9000, but it can be changed by setting an environment variable METADATA\_INTERFACE.

The directories mentioned in the configuration must be writable by the user running the back-end service. The direcories used for the approvedpaths must be writable by the web-server user.

### Metadata ping

To avoid race conditions only one instance of the back-end is allowed to run at a time. .

To manage this a metadata ping daemon in Go is provided. It listens on a port on localhost and queues pings -


## Logging and monitoring

PHPH logs to syslog using the configured syslogident as ident and local0 as the facility. The logentries meant for monitoring are in the following format (which syslog prefixes with a timestamp and a hostname):

`metadata: CRITICAL: 1426785354 Call wayf-kalmar2 [signature] validuntil: +1 days 17/4 [dev]`

- `metadata` is the ident

- `CRITICAL` is the status and can be PENDING, OK, WARNING, OR CRITICAL

- `1426785354` is the logtag an opaque string that is somewhat unique for a specific run of the PHPH back-end. It allows an operator to search for all the logentries pertaining to a specific run given a monitoring interface that only shows a status overview.

- `Call` is a tag that allows the monitoring interface to recognise log entries from PHPH that are ment to be shown in the monitor.

- `wayf-kalmar2` is the feed tag that allows the monitoring interface to show one status line for each feed from the eternal stream of loglines from PHPH.

- `[signature]` is a comma separated error summary - which might be empty if no errors occured. The current possible errors are: schema, signature, metadata and no-cert (meaning that a certificate/public key for checking the signature was not available.)

- the rest of the line is a free form text message

The log entry shown is a summary entry for one feed intended to be shown in the monitoring interface. Longer and more specific messages - alwayf with a log tag - is availabe in the syslog destination.

## Event propagation

Some logevents carries information that is relevant to metadata consumers to expedite the update of changed metadata. These events are available as stream from port 9999 in the format json:

metadataupdated source PHPH feeds: feed1, feed2.
metadataupdated source PHPH entities: ent1, ent2


## WAYF specific adaptions

The `local` directory contains the specific customizations for using PHPH in WAYF:

- `wayffilters.php` - the php filters that can be used to filter/change the metadata for an entity. The classname of the filters is specified in the configuration as `filterclass` on per feed basis.

- `janux2xml.php` - extract metadata from the JANUS database and transforms it to standard metadata in XML

- `wayfbackend.php` - the 'driver' for the WAYF specific backend.

- `wayffrontend.php` - the 'driver' for the WAYF specific frontend.

## Internals

### Naming of keys

To be able to find a private key belonging to a given certificate/public key private keys are identitied by the hexcoded sha1 of the modulus of the key corresponding to running the command: ` openssl x509 -modulus -noout -in <cert> | openssl sha1`. The modulus is is common for private and public keys and thus allows a 'naming by content' scheme.

This also allows WAYF to issue multiple certificates for one key pair while still being able to name the common key given only a certificate.

It is a generel convention in all of WAYF's systems that if multiple certificates is present in metadata - eg. in a key rollover period - the first certificate is the current one. Ie. the the first one is used to generate the name of the private key which should be used to do the actual signing.

### Formats - Flat vs. XML

One of the goals for PHPH was to be able to generate standard XML metadata from JANUS. Janus uses a 'flat' key/value format in it's database and PHPH provides a configurable method for mapping between this format and XML. The mapping takes advantage of the fact that SAML2 metadata is 'very' well formed data/protocol XML as opposed to 'document' XML which can contain elements in text elements.

Here is an example of a mapping for the AttributeConsumingService element:

    AttributeConsumingService:#:index = '/md:AttributeConsumingService[#]/@index'
    AttributeConsumingService:#:isDefault = '/md:AttributeConsumingService[#]/@isDefault'
    AttributeConsumingService:#:ServiceName:# = '/md:AttributeConsumingService[#]/md:ServiceName[#]'
    AttributeConsumingService:#:ServiceName:#:lang = '/md:AttributeConsumingService[#]/md:ServiceName[#]/@xml:lang'
    AttributeConsumingService:#:ServiceDescription:# = '/md:AttributeConsumingService[#]/md:ServiceDescription[#]'
    AttributeConsumingService:#:ServiceDescription:#:lang = '/md:AttributeConsumingService[#]/md:ServiceDescription[#]/@xml:lang'

    AttributeConsumingService:#:RequestedAttribute:#:FriendlyName = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@FriendlyName'
    AttributeConsumingService:#:RequestedAttribute:#:Name = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@Name'
    AttributeConsumingService:#:RequestedAttribute:#:NameFormat = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@NameFormat'
    AttributeConsumingService:#:RequestedAttribute:#:isRequired = '/md:AttributeConsumingService[#]/md:RequestedAttribute[#]/@isRequired'

The #s represent actual numbers and is used to represent a deep hieracial structure using only 'flat' keys - like a represenation of a hierarchal file structure using only full paths. The left column is the 'flat' format template and the right colomn is the corresponding PHPH XPath template. The `flatten` class is able to do two-wayf conversions between these two formats.

Besides being used in JANUS the flat format is also well suited as an automatically generated web form editing interface for metadata. Except for the read-only detail view in the web front-end this is not yet used in PHPH.



