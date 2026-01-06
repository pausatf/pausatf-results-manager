<?php

declare(strict_types=1);

namespace Tests\Api;

use ApiTester;

/**
 * REST API Tests
 *
 * Tests the WordPress REST API endpoints for the plugin
 */
class RestApiCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Accept', 'application/json');
    }

    public function wordPressRestApiIsAvailable(ApiTester $I): void
    {
        $I->wantTo('verify WordPress REST API is available');

        $I->sendGet('/wp-json/');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function pluginNamespaceIsRegistered(ApiTester $I): void
    {
        $I->wantTo('verify plugin REST API namespace is registered');

        $I->sendGet('/wp-json/');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['namespaces' => ['pausatf/v1']]);
    }

    public function canGetEvents(ApiTester $I): void
    {
        $I->wantTo('get events via REST API');

        $I->sendGet('/wp-json/pausatf/v1/events');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canGetResults(ApiTester $I): void
    {
        $I->wantTo('get results via REST API');

        $I->sendGet('/wp-json/pausatf/v1/results');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canGetAthletes(ApiTester $I): void
    {
        $I->wantTo('get athletes via REST API');

        $I->sendGet('/wp-json/pausatf/v1/athletes');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canGetClubs(ApiTester $I): void
    {
        $I->wantTo('get clubs via REST API');

        $I->sendGet('/wp-json/pausatf/v1/clubs');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canGetRecords(ApiTester $I): void
    {
        $I->wantTo('get records via REST API');

        $I->sendGet('/wp-json/pausatf/v1/records');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canGetRankings(ApiTester $I): void
    {
        $I->wantTo('get rankings via REST API');

        $I->sendGet('/wp-json/pausatf/v1/rankings');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function unauthenticatedCannotCreateEvent(ApiTester $I): void
    {
        $I->wantTo('verify unauthenticated users cannot create events');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/wp-json/pausatf/v1/events', json_encode([
            'event_name' => 'Test Event',
            'event_date' => '2024-06-01',
        ]));

        $I->seeResponseCodeIs(401);
    }

    public function authenticatedCanCreateEvent(ApiTester $I): void
    {
        $I->wantTo('create an event via REST API');

        $I->authenticate();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/wp-json/pausatf/v1/events', json_encode([
            'event_name' => 'Test Championship',
            'event_date' => '2024-06-15',
            'event_location' => 'Philadelphia, PA',
            'event_type' => 'championship',
        ]));

        $I->seeResponseCodeIsSuccessful();
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['event_name' => 'Test Championship']);
    }

    public function canFilterEventsByType(ApiTester $I): void
    {
        $I->wantTo('filter events by type');

        $I->sendGet('/wp-json/pausatf/v1/events', ['type' => 'championship']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canFilterEventsByDate(ApiTester $I): void
    {
        $I->wantTo('filter events by date range');

        $I->sendGet('/wp-json/pausatf/v1/events', [
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
        ]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function paginationWorksCorrectly(ApiTester $I): void
    {
        $I->wantTo('verify pagination works correctly');

        $I->sendGet('/wp-json/pausatf/v1/events', [
            'per_page' => 5,
            'page' => 1,
        ]);
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('X-WP-Total');
        $I->seeHttpHeader('X-WP-TotalPages');
    }

    public function canGetSingleEvent(ApiTester $I): void
    {
        $I->wantTo('get a single event by ID');

        // First create an event
        $I->authenticate();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/wp-json/pausatf/v1/events', json_encode([
            'event_name' => 'Single Event Test',
            'event_date' => '2024-07-01',
        ]));
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabResponse(), true);
        $eventId = $response['id'] ?? 1;

        // Now get the single event
        $I->sendGet("/wp-json/pausatf/v1/events/{$eventId}");
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['id' => $eventId]);
    }

    public function nonExistentResourceReturns404(ApiTester $I): void
    {
        $I->wantTo('verify non-existent resource returns 404');

        $I->sendGet('/wp-json/pausatf/v1/events/999999');
        $I->seeResponseCodeIs(404);
    }

    public function invalidRequestReturns400(ApiTester $I): void
    {
        $I->wantTo('verify invalid request returns 400');

        $I->authenticate();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/wp-json/pausatf/v1/events', json_encode([
            // Missing required fields
        ]));

        $I->seeResponseCodeIs(400);
    }
}
