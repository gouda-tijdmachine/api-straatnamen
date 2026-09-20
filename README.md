# Gouda Tijdmachine Straatnamen API

API geeft toegang tot de [Gouda Tijdmachine Knowledge Graph](http://yasgui.org/short/J6vjIuQZTV), specifiek de Goudse straten.

## Testing

Run the API test suite:

```bash
cd tests && bash run-api-tests.sh
```

Test results will be generated in `tests/qa-results/index.html`. The script runs against production by default; override with `BASE_URL=http://127.0.0.1:8099 bash run-api-tests.sh`. Note that the suite ends with `POST /clear_cache`.

## Caching

SPARQL-resultaten worden gecachet in Redis (`api/classes/CacheService.php`), zodat de triplestore niet bij elk request wordt bevraagd.

| Wat | Waarde |
|---|---|
| Opslag | Redis, lokaal `127.0.0.1:6379` of via de omgevingsvariabele `REDIS_URL` (`redis://` of `rediss://` voor TLS) |
| Database | `CACHE_REDIS_DATABASE` (3) |
| Sleutel | `API-STRAAT:<md5(query + offset + datasetversie)>` |
| Waarde | de ruwe JSON-response van het SPARQL-endpoint |
| TTL resultaten | `CACHE_DURATION_SECONDS` (14 dagen) |
| TTL datasetversie | 5 minuten (`SparqlService::VERSION_TTL_SECONDS`) |
| Aan/uit | `CACHE_ENABLED` in `api/config.php` |

### Datasetversie

De *datasetversie* is de hoogste wijzigingsdatum (`schema:sdDatePublished`) over alle straten en de daaraan gekoppelde afbeeldingen. Die ene, goedkope query wordt maar 5 minuten gecachet en telt mee in de cache-sleutel van alle andere queries.

Het gevolg:

- Zolang er niets wijzigt in de store blijven resultaten 14 dagen in de cache staan.
- Wijzigt er iets, dan verandert de datasetversie en daarmee elke sleutel. Data én `Last-Modified` verversen dan samen, uiterlijk 5 minuten na de wijziging. De response-body en de `Last-Modified`-header lopen zo nooit uit de pas.
- Oude sleutels worden niet actief opgeruimd; ze verlopen vanzelf na 14 dagen.
- Ook een gewijzigde querytekst (bijvoorbeeld na een code-aanpassing) levert een nieuwe sleutel op, dus na een deploy is de cache legen niet nodig.

Is de datasetversie niet op te halen, dan vallen de queries terug op een sleutel zonder versie.

### Cache omzeilen en legen

- `?no_cache` op een request slaat het lezen uit de cache over. Het resultaat wordt wel weggeschreven, dus dit ververst de betreffende cache-entries.
- `POST /clear_cache` verwijdert alle sleutels met de prefix `API-STRAAT:`.

### Redis niet bereikbaar

Mislukt de verbinding met Redis, dan wordt dat gelogd (`error_log`) en draait de API ongecachet door: elk request gaat dan rechtstreeks naar het SPARQL-endpoint.
