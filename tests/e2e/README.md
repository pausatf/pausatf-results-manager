# PAUSATF Results Manager E2E Tests

End-to-end tests for the PAUSATF Results Manager WordPress plugin using Docker and Codeception.

## Prerequisites

- Docker and Docker Compose
- PHP 8.2+ (for local development)
- Composer

## Quick Start

### Using Docker (Recommended)

1. Start the test environment:
   ```bash
   docker compose up -d
   ```

2. Wait for services to be ready (~30 seconds)

3. Run tests:
   ```bash
   docker compose run --rm test-runner
   ```

4. Stop the environment:
   ```bash
   docker compose down -v
   ```

### Local Development

1. Copy environment file:
   ```bash
   cp .env.example .env
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Build Codeception support files:
   ```bash
   vendor/bin/codecept build
   ```

4. Run tests:
   ```bash
   vendor/bin/codecept run
   ```

## Test Suites

### Acceptance Tests
Browser-based tests using Selenium WebDriver.

```bash
# Docker
docker compose run --rm test-runner vendor/bin/codecept run acceptance

# Local
vendor/bin/codecept run acceptance
```

### API Tests
REST API and SPARQL endpoint tests.

```bash
# Docker
docker compose run --rm test-runner vendor/bin/codecept run api

# Local
vendor/bin/codecept run api
```

## Test Structure

```
tests/e2e/
├── docker-compose.yml       # Docker services configuration
├── Dockerfile               # Test runner image
├── codeception.yml          # Codeception configuration
├── composer.json            # PHP dependencies
├── .env.example             # Environment variables template
├── scripts/
│   └── setup-wordpress.sh   # WordPress setup script
└── tests/
    ├── _bootstrap.php       # Test bootstrap
    ├── _data/
    │   └── dump.sql         # Test database fixtures
    ├── _support/
    │   ├── Helper/
    │   │   ├── Acceptance.php
    │   │   └── Api.php
    │   ├── AcceptanceTester.php
    │   └── ApiTester.php
    ├── acceptance/
    │   ├── PluginActivationCest.php
    │   ├── FeatureManagementCest.php
    │   └── AdminPagesCest.php
    └── api/
        ├── RestApiCest.php
        ├── SparqlApiCest.php
        └── RdfApiCest.php
```

## Services

| Service | Description | Port |
|---------|-------------|------|
| wordpress | WordPress instance | 8080 |
| db | MySQL 8.0 database | 3306 |
| chrome | Selenium Chrome browser | 4444 |
| mailhog | Email testing | 8025 |

## Writing Tests

### Acceptance Test Example

```php
<?php
class MyFeatureCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->loginToWordPress();
    }

    public function testSomething(AcceptanceTester $I): void
    {
        $I->wantTo('test some feature');
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results');
        $I->see('PAUSATF Results');
    }
}
```

### API Test Example

```php
<?php
class MyApiCest
{
    public function testEndpoint(ApiTester $I): void
    {
        $I->wantTo('test an API endpoint');
        $I->sendGet('/wp-json/pausatf/v1/events');
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
    }
}
```

## Debugging

### View logs
```bash
docker compose logs wordpress
docker compose logs db
```

### Access WordPress container
```bash
docker compose exec wordpress bash
```

### View test output
```bash
ls tests/_output/
```

### Screenshots on failure
Screenshots are automatically saved to `tests/_output/` when acceptance tests fail.

## CI/CD

Tests run automatically on GitHub Actions for:
- Push to `main` or `develop` branches
- Pull requests to `main` or `develop` branches

See `.github/workflows/e2e-tests.yml` for configuration.

## Troubleshooting

### Tests timeout waiting for WordPress
Increase the wait time in `scripts/setup-wordpress.sh` or ensure Docker has enough resources.

### Chrome connection refused
Ensure the Chrome container is running:
```bash
docker compose ps chrome
```

### Database connection errors
Check MySQL is healthy:
```bash
docker compose ps db
```

### Permission errors
Ensure proper file permissions on mounted volumes:
```bash
chmod -R 755 .
```
