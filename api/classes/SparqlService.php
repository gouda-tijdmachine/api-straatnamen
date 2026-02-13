<?php

declare(strict_types=1);

class SparqlService
{
    private CacheService $cache;

    public function __construct()
    {
        $this->cache = new CacheService();
    }

    public function get_street_index($q, $limit, $offset): array
    {
        $search = '';
        if (!empty($q)) {
            $search = '  FILTER regex(STR(?naam), "' . $q . '", "i")' . "\n";
        }

        return $this->SPARQL(
            '
SELECT ?identifier ?naam ?geometry ?type (GROUP_CONCAT(DISTINCT ?altname;
    separator=", ") AS ?naam_alt) WHERE {
  ?identifier a gtm:Straat;
              o:item_set ?itemset ;
              sdo:name ?naam .
  FILTER(?itemset IN (
    <https://n2t.net/ark:/60537/biWGGg>,
    <https://n2t.net/ark:/60537/bd75pg>
  ))

  BIND(
    IF(?itemset = <https://n2t.net/ark:/60537/biWGGg>, "heden", "verdwenen")
    AS ?type
  )

' . $search . '              
  OPTIONAL { ?identifier geo:hasGeometry/geo:asWKT ?geometry }
  OPTIONAL { ?identifier sdo:alternateName ?altname }
}
GROUP BY ?identifier ?naam ?geometry ?type
ORDER BY ?naam
LIMIT ' . $limit . ' OFFSET ' . $offset
        );
    }

    public function get_street($streetidentifier): array
    {
        return $this->SPARQL('
SELECT * WHERE {
  BIND(<' . $streetidentifier . '> AS ?identifier)
  ?identifier a gtm:Straat ;
              o:item_set ?itemset ;
              sdo:name ?naam .
  FILTER(?itemset IN (
    <https://n2t.net/ark:/60537/biWGGg>,
    <https://n2t.net/ark:/60537/bd75pg>
  ))

  BIND(
    IF(?itemset = <https://n2t.net/ark:/60537/biWGGg>, "heden", "verdwenen")
    AS ?type
  )

  OPTIONAL {
    ?identifier sdo:mentions ?vermeldingen  
  }
  OPTIONAL {
    ?identifier gtm:genoemdNaar ?genoemd_naar 
  }
  OPTIONAL {
    ?identifier gtm:ligging ?ligging 
  }
  OPTIONAL {
    ?identifier geo:hasGeometry/geo:asWKT ?geometry 
  }
  OPTIONAL {
    ?identifier sdo:alternateName ?alt_names 
  }
}
');
    }

    public function get_photos_street($streetidentifier, $limit = 50): array
    {
        return $this->SPARQL(
            '
SELECT * WHERE {
  BIND( <' . $streetidentifier . '> AS ?straat)
    ?identifier sdo:spatialCoverage/gtm:straat ?straat ;
      sdo:name ?titel ;
      sdo:url ?url ;
      sdo:dateCreated/rico:hasBeginningDate/rico:normalizedDateValue ?datering ;
      o:primary_media/o:source ?iiif_info_json ;
      o:media/sdo:thumbnailUrl ?thumbnail .
    OPTIONAL { ?identifier gtm:informatieAuteursRechten ?informatie_auteursrechten }
    OPTIONAL { ?identifier sdo:creator ?vervaardiger }  
}
ORDER BY ASC(?datering) ?titel
LIMIT ' . $limit
        );
    }

    #--------------------

    private function doSPARQLcall($sparqlQueryString, $offset): ?string
    {
        if ($offset > 0) {
            $sparqlQueryString .= " OFFSET " . $offset;
        }
        $url = SPARQL_ENDPOINT . '?query=' . urlencode($sparqlQueryString);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_USERAGENT, SPARQL_CURL_UA);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $headers = ['Accept: application/sparql-results+json'];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log("SPARQL call failed: " . curl_error($ch));
            curl_close($ch);

            return null;
        }

        curl_close($ch);

        return $response;
    }

    private function getSPARQLresults($sparqlQueryString, $offset = 0): ?array
    {
        $cache_key = md5($sparqlQueryString . $offset);
        $contents = $this->cache->get($cache_key);
        if (!$contents) {
            $contents = $this->doSPARQLcall($sparqlQueryString, $offset);
            if ($contents === null) {
                return null;
            }
            $this->cache->put($cache_key, $contents);
        }

        $result = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());

            return null;
        }

        return $result;
    }

    private function SPARQL($sparqlQueryString, $bLog = SPARQL_LOG): array
    {
        $sparqlQueryString = preg_replace('/  /', ' ', SPARQL_PREFIX . $sparqlQueryString);

        if ($bLog == 1) {
            error_log("-1- " . $sparqlQueryString);
        }
        if ($bLog == 2) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
            $callerFunction = $trace[1]['function'];
            $callerArgs = $trace[1]['args'];
            file_put_contents("sparql.log", "-------------\n\n" . $callerFunction . " > " . print_r($callerArgs, true) . "\n\n" . $sparqlQueryString . "\n\n", FILE_APPEND);
        }

        $sparqlResult = $this->getSPARQLresults($sparqlQueryString);

        if ($sparqlResult === null) {
            return [];
        }

        if ($bLog == 1) {
            error_log("-2- " . json_encode($sparqlResult));
        }
        if ($bLog == 2) {
            file_put_contents("sparql.log", json_encode($sparqlResult, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
        }

        return $sparqlResult["results"]["bindings"] ?? [];
    }

}
