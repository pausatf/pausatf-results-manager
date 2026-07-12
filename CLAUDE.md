# PAUSATF Results Manager

## Purpose

WordPress plugin to import, manage, and display PA-USATF legacy competition results with athlete tracking. Parses multiple HTML formats (tables, PRE/fixed-width, Word-generated) spanning 1994-2025.

## Stack

| Component | Details |
|-----------|---------|
| PHP | >= 8.4 |
| WordPress | 6.0+ |
| Testing | PHPUnit 11, Codeception 5 (E2E) |
| Linting | PHPCS with WPCS 3.1, PHPStan (WordPress extension) |
| JS Build | @wordpress/scripts 28 |
| JS Lint | @wordpress/eslint-plugin |
| Node | >= 20 |
| SBOM | CycloneDX (PHP + npm) |

## Standards

- **WPCS (WordPress Coding Standards)** enforced via `phpcs --standard=WordPress`
- Escape all output (`esc_html`, `esc_attr`, `esc_url`)
- Sanitize all input (`sanitize_text_field`, etc.)
- Use nonces for form submissions
- Enqueue scripts/styles properly
- PSR-4 autoloading under `PAUSATF\Results\` namespace
- PHPStan for static analysis
- `@wordpress/scripts` for JS/CSS build and lint

## Commands

```bash
# PHP lint
composer lint
composer lint:fix

# Static analysis
composer analyze

# Tests
composer test
composer test:coverage

# E2E tests
composer test:e2e

# JS build
npm run build
npm run start    # watch mode

# JS/CSS lint
npm run lint
npm run format

# Security / SBOM
composer security:check
npm run security:check
```

## Key Conventions

- Main plugin file: `pausatf-results.php`
- Namespace: `PAUSATF\Results\*` (PSR-4 from `includes/`)
- Custom post types: Events, Athletes
- Taxonomies: Event Type, Season/Year, Division
- REST API at `/wp-json/pausatf/v1/*`
- Shortcodes: `[pausatf_results]`, `[pausatf_athlete]`, `[pausatf_leaderboard]`, `[pausatf_search]`
- New parsers implement `ParserInterface` and register in `ParserDetector`
