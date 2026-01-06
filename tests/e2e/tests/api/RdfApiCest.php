<?php

declare(strict_types=1);

namespace Tests\Api;

use ApiTester;

/**
 * RDF API Tests
 *
 * Tests the RDF export endpoints
 */
class RdfApiCest
{
    public function canGetEventsAsTurtle(ApiTester $I): void
    {
        $I->wantTo('get events as Turtle RDF');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/turtle');
        $I->seeResponseContains('@prefix');
    }

    public function canGetEventsAsRdfXml(ApiTester $I): void
    {
        $I->wantTo('get events as RDF/XML');

        $I->haveHttpHeader('Accept', 'application/rdf+xml');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'application/rdf+xml');
        $I->seeResponseContains('rdf:RDF');
    }

    public function canGetEventsAsJsonLd(ApiTester $I): void
    {
        $I->wantTo('get events as JSON-LD');

        $I->haveHttpHeader('Accept', 'application/ld+json');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'application/ld+json');
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['@context' => []]);
    }

    public function canGetEventsAsNTriples(ApiTester $I): void
    {
        $I->wantTo('get events as N-Triples');

        $I->haveHttpHeader('Accept', 'application/n-triples');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'application/n-triples');
    }

    public function canGetAthletesRdf(ApiTester $I): void
    {
        $I->wantTo('get athletes as RDF');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/athletes');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/turtle');
    }

    public function canGetResultsRdf(ApiTester $I): void
    {
        $I->wantTo('get results as RDF');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/results');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/turtle');
    }

    public function canGetOntology(ApiTester $I): void
    {
        $I->wantTo('get the PAUSATF ontology');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/ontology');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/turtle');
        $I->seeResponseContains('pausatf:Athlete');
        $I->seeResponseContains('pausatf:Event');
        $I->seeResponseContains('pausatf:Result');
    }

    public function canGetVoidDescription(ApiTester $I): void
    {
        $I->wantTo('get the VoID dataset description');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/void');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/turtle');
        $I->seeResponseContains('void:Dataset');
    }

    public function contentNegotiationWorks(ApiTester $I): void
    {
        $I->wantTo('verify content negotiation works');

        // Request without Accept header should return default (Turtle)
        $I->sendGet('/rdf/events');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/turtle');
    }

    public function schemaOrgTypesAreUsed(ApiTester $I): void
    {
        $I->wantTo('verify Schema.org types are used in RDF');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('schema:SportsEvent');
    }

    public function foafTypesAreUsed(ApiTester $I): void
    {
        $I->wantTo('verify FOAF types are used in RDF');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/athletes');

        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('foaf:');
    }

    public function dublinCoreMetadataIsIncluded(ApiTester $I): void
    {
        $I->wantTo('verify Dublin Core metadata is included');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        // Look for dcterms prefix declaration
        $I->seeResponseContains('dcterms:');
    }

    public function jsonLdHasValidContext(ApiTester $I): void
    {
        $I->wantTo('verify JSON-LD has valid @context');

        $I->haveHttpHeader('Accept', 'application/ld+json');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();

        $response = json_decode($I->grabResponse(), true);
        $I->assertArrayHasKey('@context', $response);
        $I->assertNotEmpty($response['@context']);
    }

    public function rdfContainsProperUris(ApiTester $I): void
    {
        $I->wantTo('verify RDF contains proper URIs');

        $I->haveHttpHeader('Accept', 'text/turtle');
        $I->sendGet('/rdf/events');

        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('https://www.pausatf.org/');
    }
}
