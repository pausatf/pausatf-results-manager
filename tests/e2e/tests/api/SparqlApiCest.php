<?php

declare(strict_types=1);

namespace Tests\Api;

use ApiTester;

/**
 * SPARQL API Tests
 *
 * Tests the SPARQL endpoint functionality
 */
class SparqlApiCest
{
    public function sparqlEndpointIsAvailable(ApiTester $I): void
    {
        $I->wantTo('verify SPARQL endpoint is available');

        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendGet('/sparql');
        $I->seeResponseCodeIsSuccessful();
    }

    public function canExecuteSelectQuery(ApiTester $I): void
    {
        $I->wantTo('execute a SELECT query');

        $query = 'SELECT ?subject ?predicate ?object WHERE { ?subject ?predicate ?object } LIMIT 10';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['head' => ['vars' => ['subject', 'predicate', 'object']]]);
    }

    public function canQueryEvents(ApiTester $I): void
    {
        $I->wantTo('query events via SPARQL');

        $query = '
            PREFIX schema: <http://schema.org/>
            PREFIX pausatf: <https://www.pausatf.org/ontology/>

            SELECT ?event ?name ?date WHERE {
                ?event a schema:SportsEvent ;
                       schema:name ?name ;
                       schema:startDate ?date .
            }
            LIMIT 10
        ';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canQueryAthletes(ApiTester $I): void
    {
        $I->wantTo('query athletes via SPARQL');

        $query = '
            PREFIX foaf: <http://xmlns.com/foaf/0.1/>
            PREFIX pausatf: <https://www.pausatf.org/ontology/>

            SELECT ?athlete ?name ?club WHERE {
                ?athlete a pausatf:Athlete ;
                         foaf:name ?name .
                OPTIONAL { ?athlete pausatf:memberOf ?club }
            }
            LIMIT 10
        ';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canQueryResults(ApiTester $I): void
    {
        $I->wantTo('query results via SPARQL');

        $query = '
            PREFIX schema: <http://schema.org/>
            PREFIX pausatf: <https://www.pausatf.org/ontology/>

            SELECT ?result ?athlete ?event ?time WHERE {
                ?result a pausatf:Result ;
                        pausatf:athlete ?athlete ;
                        pausatf:event ?event .
                OPTIONAL { ?result pausatf:time ?time }
            }
            LIMIT 10
        ';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canExecuteAskQuery(ApiTester $I): void
    {
        $I->wantTo('execute an ASK query');

        $query = '
            PREFIX schema: <http://schema.org/>

            ASK WHERE {
                ?event a schema:SportsEvent .
            }
        ';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseMatchesJsonType(['boolean' => 'boolean']);
    }

    public function canExecuteDescribeQuery(ApiTester $I): void
    {
        $I->wantTo('execute a DESCRIBE query');

        $query = '
            PREFIX pausatf: <https://www.pausatf.org/ontology/>

            DESCRIBE pausatf:
        ';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
    }

    public function queryWithFilterWorks(ApiTester $I): void
    {
        $I->wantTo('execute a query with FILTER');

        $query = '
            PREFIX schema: <http://schema.org/>
            PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

            SELECT ?event ?name ?date WHERE {
                ?event a schema:SportsEvent ;
                       schema:name ?name ;
                       schema:startDate ?date .
                FILTER (?date >= "2024-01-01"^^xsd:date)
            }
            LIMIT 10
        ';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function queryWithOrderByWorks(ApiTester $I): void
    {
        $I->wantTo('execute a query with ORDER BY');

        $query = '
            PREFIX schema: <http://schema.org/>

            SELECT ?event ?name ?date WHERE {
                ?event a schema:SportsEvent ;
                       schema:name ?name ;
                       schema:startDate ?date .
            }
            ORDER BY DESC(?date)
            LIMIT 10
        ';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function invalidQueryReturnsError(ApiTester $I): void
    {
        $I->wantTo('verify invalid query returns error');

        $query = 'INVALID SPARQL QUERY';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(400);
    }

    public function canGetResultsAsXml(ApiTester $I): void
    {
        $I->wantTo('get SPARQL results as XML');

        $query = 'SELECT ?s ?p ?o WHERE { ?s ?p ?o } LIMIT 5';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+xml');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'application/sparql-results+xml');
    }

    public function canGetResultsAsCsv(ApiTester $I): void
    {
        $I->wantTo('get SPARQL results as CSV');

        $query = 'SELECT ?s ?p ?o WHERE { ?s ?p ?o } LIMIT 5';

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'text/csv');
        $I->sendPost('/sparql', ['query' => $query]);

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/csv');
    }

    public function emptyQueryReturnsError(ApiTester $I): void
    {
        $I->wantTo('verify empty query returns error');

        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->haveHttpHeader('Accept', 'application/sparql-results+json');
        $I->sendPost('/sparql', ['query' => '']);

        $I->seeResponseCodeIs(400);
    }
}
