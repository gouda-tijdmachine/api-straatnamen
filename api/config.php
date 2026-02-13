<?php

declare(strict_types=1);

define('CACHE_DURATION_SECONDS', 1209600);  // 14 dagen = 14*24*3600 seconden
define('CACHE_ENABLED', true);
define('SPARQL_ENDPOINT', 'https://sparql.goudatijdmachine.nl');
define('SPARQL_CURL_UA', 'api-straatnamen');
define('SPARQL_LOG', 0);

define('SPARQL_PREFIX', 'PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX geo: <http://www.opengis.net/ont/geosparql#>
PREFIX geof: <http://www.opengis.net/def/function/geosparql/>
PREFIX gtm: <https://www.goudatijdmachine.nl/def#>
PREFIX hg: <http://rdf.histograph.io/>
PREFIX o: <http://omeka.org/s/vocabs/o#>
PREFIX osm: <https://osm2rdf.cs.uni-freiburg.de/rdf#>
PREFIX owl: <http://www.w3.org/2002/07/owl#>
PREFIX picom: <https://personsincontext.org/model#>
PREFIX pnv: <https://w3id.org/pnv#>
PREFIX prov: <http://www.w3.org/ns/prov#>
PREFIX roar: <https://w3id.org/roar#>
PREFIX sdo: <https://schema.org/>
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX rico: <https://www.ica.org/standards/RiC/ontology#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
');

// Production-safe defaults
// ini_set('display_errors', '0');
// ini_set('display_startup_errors', '0');
// error_reporting(E_ALL);

// Send logs to stderr
// ini_set('log_errors', '1');
// ini_set('error_log', 'php://stderr');

