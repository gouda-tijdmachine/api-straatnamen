<?php

declare(strict_types=1);

class SparqlService
{
    private CacheService $cache;

    public function __construct()
    {
        $this->cache = new CacheService();
    }

    public function get_street_index(string $q = "", int $limit = 0, int $offset = 0, string $type = "", float $lat = 0, float $lon = 0): array
    {
        $search = '';
        if (!empty($q)) {
            $qEscaped = self::escapeForSparqlRegexLiteral($q);
            $search = '      FILTER(
        regex(STR(?naam), "' . $qEscaped . '", "i") ||
        regex(STR(COALESCE(?altname_filter, "")), "' . $qEscaped . '", "i")
      )' . "\n";
        }

        $sets = [];
        if ($type === 'huidig' || $type === 'alle') {
            $sets[] = '<https://n2t.net/ark:/60537/biWGGg>';
        }
        if ($type === 'verdwenen' || $type === 'alle') {
            $sets[] = '<https://n2t.net/ark:/60537/bd75pg>';
        }
        $filterset = '      FILTER(?itemset IN (' . implode(", ", $sets) . '))';

        if ($lon !== null && $lat !== null && $lon * $lat > 0) {
            $radiusMeters = 300.0;

            // --- convert meters -> degrees ---
            // latitude degrees are roughly constant
            $deltaLat = $radiusMeters / 111320.0;

            // longitude degrees depend on latitude
            $deltaLon = $radiusMeters / (111320.0 * cos(deg2rad($lat)));

            // --- bounding box ---
            $minLat = $lat - $deltaLat;
            $maxLat = $lat + $deltaLat;
            $minLon = $lon - $deltaLon;
            $maxLon = $lon + $deltaLon;

            // --- WKT geometries (IMPORTANT: lon lat order!) ---
            $pointWKT = sprintf(
                'POINT(%F %F)',
                $lon,
                $lat
            );

            $envWKT = sprintf(
                'POLYGON((%F %F,%F %F,%F %F,%F %F,%F %F))',
                $minLon,
                $minLat,
                $maxLon,
                $minLat,
                $maxLon,
                $maxLat,
                $minLon,
                $maxLat,
                $minLon,
                $minLat
            );
            $geo_binds = "\n" . '  BIND("' . $pointWKT . '"^^geo:wktLiteral AS ?q)
  BIND("' . $envWKT . '"^^geo:wktLiteral AS ?env)';
            $geo_select = "\n                  geo:hasGeometry/geo:asWKT ?wkt ;";
            $geo_filter = "\nFILTER(geof:sfIntersects(?wkt, ?env))";
            $geo_bind = "\nBIND (geof:distance(?wkt, ?q) AS ?distM)";
            $sort = "?distM";
            $groupby = $sort;

        } else {
            $geo_binds = '';
            $geo_select = '';
            $geo_filter = '';
            $geo_bind = '';
            $sort = "?naam";
            $groupby = '';
        }

        return $this->SPARQL(
            '
SELECT
  ?identifier ?naam ?geometry ?type
  (GROUP_CONCAT(DISTINCT STR(?altname); SEPARATOR=", ") AS ?naam_alt)
  (GROUP_CONCAT(DISTINCT STR(?vermeldingen); SEPARATOR=", ") AS ?vermeldingen_all)
  (GROUP_CONCAT(DISTINCT STR(?genoemd_naar); SEPARATOR=", ") AS ?genoemd_naar_all)
  (GROUP_CONCAT(DISTINCT STR(?ligging); SEPARATOR=", ") AS ?ligging_all)
WHERE {
  {

  SELECT DISTINCT ?identifier ?itemset ?naam ?distM
    WHERE {' . $geo_binds . '
      ?identifier a gtm:Straat ;
                  o:item_set ?itemset ; ' . $geo_select . '
                  schema:name ?naam .

      OPTIONAL { ?identifier schema:alternateName ?altname_filter }

' . $filterset . $geo_filter . '

' . $search . $geo_bind . '

    }
    ORDER BY ' . $sort . '
    LIMIT ' . $limit . ' OFFSET ' . $offset . '
  }

  BIND(
    IF(?itemset = <https://n2t.net/ark:/60537/biWGGg>, "heden", "verdwenen")
    AS ?type
  )
  OPTIONAL { ?identifier geo:hasGeometry/geo:asWKT ?geometry }
  OPTIONAL { ?identifier schema:alternateName ?altname }
  OPTIONAL { ?identifier schema:mentions ?vermeldingen }
  OPTIONAL { ?identifier gtm:genoemdNaar ?genoemd_naar }
  OPTIONAL { ?identifier gtm:ligging ?ligging }
}
GROUP BY ?identifier ?naam ?geometry ?type ' . $groupby . '
ORDER BY ' . $sort
        );
    }

    public function get_street($streetidentifier): array
    {
        return $this->SPARQL('
SELECT ?identifier ?itemset ?naam ?type ?vermeldingen ?genoemd_naar ?ligging ?problematisch ?geometry ?gewijzigd (GROUP_CONCAT(DISTINCT STR(?alt_names); SEPARATOR="|") AS ?alt_names_grouped) WHERE {
  BIND(<' . $streetidentifier . '> AS ?identifier)
  ?identifier a gtm:Straat ;
              o:item_set ?itemset ;
              schema:name ?naam .
  FILTER(?itemset IN (
    <https://n2t.net/ark:/60537/biWGGg>,
    <https://n2t.net/ark:/60537/bd75pg>
  ))

  BIND(
    IF(?itemset = <https://n2t.net/ark:/60537/biWGGg>, "heden", "verdwenen")
    AS ?type
  )

  # Laatste wijziging van de straat zelf of van een van de gekoppelde afbeeldingen.
  # schema:sdDatePublished is de Omeka "laatst gewijzigd"-datum; o:modified staat niet in de RDF.
  {
    SELECT (MAX(?d) AS ?gewijzigd) WHERE {
      { <' . $streetidentifier . '> schema:sdDatePublished ?d }
      UNION
      { ?afb schema:spatialCoverage/gtm:straat <' . $streetidentifier . '> ; schema:sdDatePublished ?d }
      UNION
      { ?afb2 schema:spatialCoverage/gtm:straat <' . $streetidentifier . '> ; o:media/schema:sdDatePublished ?d }
    }
  }

  OPTIONAL {
    ?identifier schema:mentions ?vermeldingen  
  }
  OPTIONAL {
    ?identifier gtm:genoemdNaar ?genoemd_naar 
  }
  OPTIONAL {
    ?identifier gtm:ligging ?ligging
  }
  OPTIONAL {
    ?identifier gtm:problematischeStraatnaam ?problematisch
  }
  OPTIONAL {
    ?identifier geo:hasGeometry/geo:asWKT ?geometry
  }
  OPTIONAL {
    ?identifier schema:alternateName ?alt_names 
  }
} 
GROUP BY ?identifier ?itemset ?naam ?type ?vermeldingen ?genoemd_naar ?ligging ?problematisch ?geometry ?gewijzigd
');
    }

    public function get_photos_street($streetidentifier): array
    {
        return $this->SPARQL(
            '
SELECT * WHERE {
  BIND( <' . $streetidentifier . '> AS ?straat)

  # Laatste wijziging van een van de afbeeldingen van deze straat (item of media).
  {
    SELECT (MAX(?d) AS ?gewijzigd) WHERE {
      { ?afb schema:spatialCoverage/gtm:straat <' . $streetidentifier . '> ; schema:sdDatePublished ?d }
      UNION
      { ?afb2 schema:spatialCoverage/gtm:straat <' . $streetidentifier . '> ; o:media/schema:sdDatePublished ?d }
    }
  }

    ?identifier schema:spatialCoverage/gtm:straat ?straat ;
      schema:name ?titel ;
      schema:url ?url ;
      schema:dateCreated ?datering ;
      o:primary_media/o:source ?iiif_info_json .

    # o:thumbnail_urls/o:square is uit de RDF-export verdwenen (0 triples). Optioneel houden,
    # zodat de query blijft werken en hem weer oppikt zodra de export hem teruggeeft; anders
    # leidt DataService de thumbnail af uit dezelfde IIIF-bron als ?iiif_info_json.
    OPTIONAL { ?identifier o:media/o:thumbnail_urls/o:square ?thumbnail }

    OPTIONAL { ?identifier gtm:informatieAuteursRechten ?informatie_auteursrechten }
    OPTIONAL { ?identifier schema:creator/schema:name ?vervaardiger }
}
ORDER BY ASC(?datering) ?titel
'
        );
    }

    /**
     * Hoogste wijzigingsdatum over alle straten en de daaraan gekoppelde afbeeldingen.
     *
     * Bewust dataset-breed, ook bij een gefilterde index: dat is een bovengrens, dus hooguit een
     * overbodige refresh en nooit een onterechte 304. De ETag dekt de exacte filtercombinatie.
     */
    public function get_last_modified_index(): array
    {
        return $this->SPARQL('
SELECT (MAX(?d) AS ?gewijzigd) WHERE {
  { ?straat a gtm:Straat ; schema:sdDatePublished ?d }
  UNION
  { ?afb schema:spatialCoverage/gtm:straat ?straat1 ; schema:sdDatePublished ?d }
  UNION
  { ?afb2 schema:spatialCoverage/gtm:straat ?straat2 ; o:media/schema:sdDatePublished ?d }
}
');
    }

    #--------------------

    private function doSPARQLcall($sparqlQueryString, $offset): ?string
    {
        if ($offset > 0) {
            $sparqlQueryString .= " OFFSET " . $offset;
        }
        // POST, not GET: the upstream WAF rejects any GET whose query string
        // contains the keyword "PREFIX" with a 403 HTML page.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, SPARQL_ENDPOINT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => $sparqlQueryString]));
        curl_setopt($ch, CURLOPT_USERAGENT, SPARQL_CURL_UA);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/sparql-results+json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log("SPARQL call failed: " . curl_error($ch));
            curl_close($ch);

            return null;
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            error_log("SPARQL call returned HTTP $status: " . substr((string)$response, 0, 200));
            return null;
        }

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

    /**
     * Escape user input for safe inclusion as a literal substring inside a
     * SPARQL `regex(..., "<q>", "i")` call.
     *
     * Two passes: first escape XPath regex metacharacters so the input is matched
     * literally (so a user-typed `.` doesn't act as "any char"), then escape the
     * result for a SPARQL double-quoted string literal (\ and " plus control chars).
     */
    private static function escapeForSparqlRegexLiteral(string $q): string
    {
        $regexMeta = ['\\', '.', '+', '*', '?', '(', ')', '[', ']', '{', '}', '^', '$', '|', '-', '/'];
        $regexEscaped = '';
        $len = strlen($q);
        for ($i = 0; $i < $len; $i++) {
            $c = $q[$i];
            $regexEscaped .= in_array($c, $regexMeta, true) ? '\\' . $c : $c;
        }
        return strtr($regexEscaped, [
            '\\' => '\\\\',
            '"'  => '\\"',
            "\t" => '\\t',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);
    }

}
