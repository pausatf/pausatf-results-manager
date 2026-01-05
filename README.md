# PAUSATF Results Manager

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
- PHP 8.0+

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
