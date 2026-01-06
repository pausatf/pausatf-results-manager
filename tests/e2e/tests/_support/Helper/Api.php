<?php

declare(strict_types=1);

namespace Helper;

use Codeception\Module;

/**
 * API Test Helper
 *
 * Custom methods for REST API and SPARQL endpoint tests
 */
class Api extends Module
{
    /**
     * Set up basic auth for WordPress REST API
     */
    public function setBasicAuth(): void
    {
        $rest = $this->getModule('REST');

        $username = getenv('WP_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('WP_ADMIN_PASSWORD') ?: 'admin';

        $rest->haveHttpHeader('Authorization', 'Basic ' . base64_encode("{$username}:{$password}"));
    }

    /**
     * Send a SPARQL query
     */
    public function sendSparqlQuery(string $query, string $format = 'application/sparql-results+json'): void
    {
        $rest = $this->getModule('REST');

        $rest->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $rest->haveHttpHeader('Accept', $format);
        $rest->sendPost('/sparql', ['query' => $query]);
    }

    /**
     * Verify SPARQL results contain binding
     */
    public function seeSparqlBinding(string $variable): void
    {
        $rest = $this->getModule('REST');
        $asserts = $this->getModule('Asserts');

        $response = json_decode($rest->grabResponse(), true);
        $asserts->assertArrayHasKey('head', $response);
        $asserts->assertArrayHasKey('vars', $response['head']);
        $asserts->assertContains($variable, $response['head']['vars']);
    }

    /**
     * Verify SPARQL results have rows
     */
    public function seeSparqlResultCount(int $minCount): void
    {
        $rest = $this->getModule('REST');
        $asserts = $this->getModule('Asserts');

        $response = json_decode($rest->grabResponse(), true);
        $asserts->assertArrayHasKey('results', $response);
        $asserts->assertArrayHasKey('bindings', $response['results']);
        $asserts->assertGreaterThanOrEqual($minCount, count($response['results']['bindings']));
    }

    /**
     * Fetch RDF data and verify format
     */
    public function grabRdfData(string $endpoint, string $format = 'text/turtle'): string
    {
        $rest = $this->getModule('REST');

        $rest->haveHttpHeader('Accept', $format);
        $rest->sendGet($endpoint);

        return $rest->grabResponse();
    }

    /**
     * Verify RDF contains triple pattern
     */
    public function seeRdfTriple(string $pattern): void
    {
        $rest = $this->getModule('REST');
        $asserts = $this->getModule('Asserts');

        $response = $rest->grabResponse();
        $asserts->assertStringContainsString($pattern, $response);
    }

    /**
     * Verify REST API endpoint returns collection
     */
    public function seeApiCollection(string $endpoint): void
    {
        $rest = $this->getModule('REST');
        $asserts = $this->getModule('Asserts');

        $rest->sendGet($endpoint);
        $rest->seeResponseCodeIs(200);
        $rest->seeResponseIsJson();

        $response = json_decode($rest->grabResponse(), true);
        $asserts->assertIsArray($response);
    }

    /**
     * Create event via REST API
     */
    public function createEventViaApi(array $eventData): int
    {
        $rest = $this->getModule('REST');

        $this->setBasicAuth();
        $rest->haveHttpHeader('Content-Type', 'application/json');
        $rest->sendPost('/wp-json/pausatf/v1/events', json_encode($eventData));
        $rest->seeResponseCodeIs(201);

        $response = json_decode($rest->grabResponse(), true);
        return $response['id'] ?? 0;
    }

    /**
     * Create result via REST API
     */
    public function createResultViaApi(int $eventId, array $resultData): int
    {
        $rest = $this->getModule('REST');

        $this->setBasicAuth();
        $rest->haveHttpHeader('Content-Type', 'application/json');
        $resultData['event_id'] = $eventId;
        $rest->sendPost('/wp-json/pausatf/v1/results', json_encode($resultData));
        $rest->seeResponseCodeIs(201);

        $response = json_decode($rest->grabResponse(), true);
        return $response['id'] ?? 0;
    }

    /**
     * Delete event via REST API
     */
    public function deleteEventViaApi(int $eventId): void
    {
        $rest = $this->getModule('REST');

        $this->setBasicAuth();
        $rest->sendDelete("/wp-json/pausatf/v1/events/{$eventId}");
        $rest->seeResponseCodeIsSuccessful();
    }

    /**
     * Verify API pagination headers
     */
    public function seeApiPaginationHeaders(): void
    {
        $rest = $this->getModule('REST');

        $rest->seeHttpHeader('X-WP-Total');
        $rest->seeHttpHeader('X-WP-TotalPages');
    }

    /**
     * Verify JSON-LD context
     */
    public function seeJsonLdContext(): void
    {
        $rest = $this->getModule('REST');
        $asserts = $this->getModule('Asserts');

        $response = json_decode($rest->grabResponse(), true);
        $asserts->assertArrayHasKey('@context', $response);
    }

    /**
     * Get response as array
     */
    public function grabResponseAsArray(): array
    {
        $rest = $this->getModule('REST');

        return json_decode($rest->grabResponse(), true) ?? [];
    }
}
