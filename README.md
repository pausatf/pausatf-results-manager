# PAUSATF Results Manager

## Dependency compatibility and verification

Development uses `@wordpress/scripts` 36, React/React DOM 18.3.1 and ESLint 9.39.5 constraints.
React 19 and ESLint 10 remain deferred until the affected peers support them; targeted Dependabot ignores retain
supported minor/patch updates. The ESLint override is scoped to `@wordpress/scripts`.
Do not install with forced or legacy peer resolution. The lockfile is authoritative for installed versions.
`@types/node` tracks 26.6.3 or newer; this type package does not change the Node runtime floor of 22.22.2.

```bash
mise exec node@22.22.2 -- npm ci
mise exec node@22.22.2 -- npm run build
mise exec node@22.22.2 -- npm run lint
mise exec node@22.22.2 -- npm run test:unit
```

`test:unit` permits an empty Jest suite, so success alone does not prove unit coverage.
PHPUnit and browser/API verification use the separate PHP and [E2E harnesses](tests/e2e/README.md).
Repository CI and dependency validation do not establish production deployment.

WordPress plugin to import, manage, and display PAUSATF (Pacific Association USA Track & Field) legacy competition results with full athlete tracking.

## Features

- **Multi-format HTML Parser**: Automatically detects and parses:
  - HTML tables (2008+)
  - PRE/fixed-width formatted text (1996-2007)
  - Microsoft Word-generated HTML

- **Custom Post Types**:
  - Events (results from competitions)
  - Athletes (competitor profiles with career statistics)

- **Taxonomies**:
  - Event Type (Cross Country, Road Race, Track & Field, Race Walk, Mountain/Ultra/Trail)
  - Season/Year
  - Division (Open, Masters 40+, Seniors 50+, etc.)

- **Import Options**:
  - Single URL import
  - Batch import by year
  - File upload
  - Automatic scheduled sync

- **Data Display**:
  - Shortcodes for results, athletes, and leaderboards
  - REST API for programmatic access
  - Athlete search

## Installation

1. Upload the `pausatf-results-manager` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **PAUSATF Results** in the admin menu

## Requirements

- WordPress 6.0+
- PHP 8.4+ (Composer requirement and CI runtime)

## Usage

### Shortcodes

```php
// Display results for an event
[pausatf_results event_id="123"]

// Display results filtered by year and division
[pausatf_results year="2024" division="Open"]

// Display athlete profile
[pausatf_athlete name="John Smith"]

// Display leaderboard
[pausatf_leaderboard division="Masters 40+" year="2024"]

// Athlete search form
[pausatf_search]
```

### REST API

```
GET /wp-json/pausatf/v1/events/{id}/results
GET /wp-json/pausatf/v1/athletes/search?q=smith
GET /wp-json/pausatf/v1/athletes/{name}/results
GET /wp-json/pausatf/v1/leaderboard?division=Open&year=2024
GET /wp-json/pausatf/v1/divisions
GET /wp-json/pausatf/v1/seasons
POST /wp-json/pausatf/v1/import (requires authentication)
```

### Importing Data

1. Go to **PAUSATF Results → Import**
2. Enter a URL from `https://www.pausatf.org/data/`
3. Click "Analyze" to preview the format detection
4. Click "Import Results" to import

For bulk imports, select a year and click "Start Batch Import".

## Data Source

Legacy results are imported from: https://www.pausatf.org/data/

Data spans 1994-2025 with varying HTML formats.

## Development

JavaScript development tooling requires Node.js 22.22.2 or newer and npm 10 or newer. Install the development dependencies with `npm ci`. The npm scripts provide build, lint, and Jest unit-test commands (`npm run build`, `npm run lint`, and `npm run test:unit`).

### Directory Structure

```
pausatf-results-manager/
├── pausatf-results.php           # Main plugin file
├── includes/
│   ├── class-results-importer.php
│   ├── class-athlete-database.php
│   └── parsers/
│       ├── interface-parser.php
│       ├── class-parser-detector.php
│       ├── class-parser-table.php
│       ├── class-parser-pre.php
│       └── class-parser-word.php
├── admin/
│   ├── views/
│   └── class-admin-*.php
├── public/
│   ├── class-shortcodes.php
│   └── class-rest-api.php
├── cron/
│   └── class-sync-scheduler.php
└── assets/
    ├── css/
    └── js/
```

### Adding a New Parser

1. Create a class implementing `ParserInterface`
2. Implement `can_parse()`, `parse()`, `get_priority()`, and `get_id()`
3. Register in `ParserDetector::register_default_parsers()`

## License

GPL v2 or later

## Credits

Built for the Pacific Association of USA Track & Field.
