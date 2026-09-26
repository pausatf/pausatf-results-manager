<?php

declare(strict_types=1);

namespace Tests\Api;

use ApiTester;

/**
 * REST API tests for routes registered by the plugin.
 */
class RestApiCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Accept', 'application/json');
    }

    public function wordPressRestApiIsAvailable(ApiTester $I): void
    {
        $I->sendGet('/wp-json/');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function pluginNamespaceIsRegistered(ApiTester $I): void
    {
        $I->sendGet('/wp-json/');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['namespaces' => ['pausatf/v1']]);
    }

    public function canGetDivisions(ApiTester $I): void
    {
        $I->sendGet('/wp-json/pausatf/v1/divisions');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function canGetSeasons(ApiTester $I): void
    {
        $I->sendGet('/wp-json/pausatf/v1/seasons');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['seasons' => []]);
    }

    public function canGetLeaderboard(ApiTester $I): void
    {
        $I->sendGet('/wp-json/pausatf/v1/leaderboard');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['leaders' => [], 'count' => 0]);
    }

    public function canGetEventResults(ApiTester $I): void
    {
        $I->sendGet('/wp-json/pausatf/v1/events/0/results');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['results' => [], 'count' => 0]);
    }

    public function canSearchAthletes(ApiTester $I): void
    {
        $I->sendGet('/wp-json/pausatf/v1/athletes/search', ['q' => 'Runner']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }

    public function unauthenticatedCannotImportResults(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/wp-json/pausatf/v1/import', json_encode(['url' => 'https://example.org/results.csv']));
        $I->seeResponseCodeIs(401);
    }

    public function unauthenticatedCannotImportCanonicalEvent(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/wp-json/pausatf/v1/events/import', json_encode(['name' => 'Unauthorized event']));
        $I->seeResponseCodeIs(401);
    }
}
