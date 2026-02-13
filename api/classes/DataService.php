<?php

declare(strict_types=1);

require_once 'geoPHP.php';
require_once 'SparqlService.php';
require_once 'CacheService.php';

class DataService
{
    private SparqlService $sparqlService;
    private CacheService $cache;

    private $geoPHP;

    public function __construct()
    {
        $this->sparqlService = new SparqlService();
        $this->geoPHP = new GeoPHP();
        $this->cache = new CacheService();
    }

    public function geoJsonStreets($q = null, $limit = 2000, $offset = 0): array
    {
        $geojson = [
            "type" => "FeatureCollection",
            "features" => []
        ];

        $streets = $this->sparqlService->get_street_index($q, $limit, $offset);
        foreach ($streets as $street) {
            if (isset($street['geometry']['value'])) {
                $feature = [
                    "type" => "Feature",
                    "properties" => [
                        "identifier" => $street['identifier']['value'],
                        "naam" => $street['naam']['value'],
                        "type" => $street['type']['value']
                    ]
                ];
                $multiline = $this->geoPHP->load($street['geometry']['value'], 'wkt');
                $feature["geometry"] = json_decode($multiline->out('json'));

                $geojson["features"][] = $feature;
            }
        }

        return $geojson;

    }

    public function searchStreets($q = null, $limit = 2000, $offset = 0): array
    {
        if (!empty($q)) {
            $q = preg_replace("/[^a-zA-Z\\- ']/", '', trim($q));
        }
        $streets = [];
        foreach ($this->sparqlService->get_street_index($q, $limit, $offset) as $street) {
            $streets[] = [
                'identifier' => $street['identifier']['value'],
                'naam' => $street['naam']['value'],
                'naam_alt' => $street['naam_alt']['value'] ?? null,
            ];
        }

        return [
            'straten' => $streets,
            'aantal' => count($streets)
        ];
    }

    public function getStreet($straatidentifier): ?array
    {
        $street = $this->sparqlService->get_street($straatidentifier);

        if (empty($street)) {
            return null;
        }

        $fotos = [];
        foreach ($this->sparqlService->get_photos_street($straatidentifier) as $foto) {
            $fotos[] = [
                'identifier' => $foto['identifier']['value'] ?? '',
                'titel' => $foto['titel']['value'] ?? '',
                'thumbnail' => $foto['thumbnail']['value'] ?? '',
                'image' => !empty($foto['iiif_info_json']['value']) ? str_replace("info.json", "full/500,/0/default.jpg", $foto['iiif_info_json']['value']) : '',
                'iiif_info_json' => $foto['iiif_info_json']['value'] ?? '',
                'vervaardiger' => $foto['vervaardiger']['value'] ?? null,
                'informatie_auteursrechten' => !empty($foto['informatie_auteursrechten']['value']) ? str_replace("https://samh.nl/auteursrechten#", "", $foto['informatie_auteursrechten']['value']) : null,
                'url' => $foto['url']['value'] ?? null,
                'datering' => $foto['datering']['value'] ?? null,
                'bronbronorganisatie' => (!empty($foto['url']['value']) && strstr($foto['url']['value'], 'samh.nl') !== false) ? 'Streekarchief Midden-Holland' : 'Rijkdienst voor het Cultureel Erfgoed'
            ];
        }

        if (!empty($street[0]['geometry']['value'])) {
            $multipoint = $this->geoPHP->load($street[0]['geometry']['value'], 'wkt');
            $geometry = json_decode($multipoint->out('json'));
        } else {
            $geometry = null;
        }

        $streetData = [
            'identifier' => $straatidentifier,
            'naam' => $street[0]['naam']['value'],
            'alt_names' => $street[0]['alt_names']['value'],
            'genoemd_naar' => $street[0]['genoemd_naar']['value'],
            'ligging' => $street[0]['ligging']['value'],
            'vermeldingen' => $street[0]['vermeldingen']['value'],
            'geometry' => $geometry,
             'type' => $street[0]['type']['value'],
            'fotos' => $fotos
        ];

        return $streetData;
    }

}
