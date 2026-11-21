<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PAUSATF\Results\RDFExporter;
use PAUSATF\Results\SPARQLEndpoint;

/**
 * The RDF and SPARQL endpoints are built from an init callback at priority 20.
 * Runs in its own process so these stubs cannot leak into other tests.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RdfRewriteRulesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pausatf_test_hooks'] = [];
        $GLOBALS['pausatf_test_rewrite_rules'] = [];
        $GLOBALS['pausatf_test_init_count'] = 0;
        $GLOBALS['pausatf_test_stored_rules'] = '';

        if (!function_exists('add_rewrite_rule')) {
            function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void {
                $GLOBALS['pausatf_test_rewrite_rules'][$regex] = $query;
            }
            function did_action(string $hook): int {
                return $hook === 'init' ? $GLOBALS['pausatf_test_init_count'] : 0;
            }
            function get_option($name, $default = false) {
                return $name === 'rewrite_rules' ? $GLOBALS['pausatf_test_stored_rules'] : $default;
            }
            function add_filter(...$args): void {
            }
            function home_url(string $path = ''): string {
                return 'https://example.test' . $path;
            }
            function trailingslashit(string $value): string {
                return rtrim($value, '/') . '/';
            }
        }

        require_once __DIR__ . '/../includes/class-feature-manager.php';
        require_once __DIR__ . '/../includes/class-rdf-exporter.php';
        require_once __DIR__ . '/../includes/class-sparql-endpoint.php';
        // Loading the files registers their own init bootstrap; start each test clean.
        $GLOBALS['pausatf_test_hooks'] = [];
    }

    /** Builds both endpoints the way the plugin's init bootstrap does. */
    private function buildEndpoints(): array
    {
        return [new RDFExporter(), new SPARQLEndpoint()];
    }

    public function testRulesAreRegisteredWhenBuiltDuringInit(): void
    {
        $GLOBALS['pausatf_test_init_count'] = 1;

        $this->buildEndpoints();

        $rules = $GLOBALS['pausatf_test_rewrite_rules'];
        self::assertSame('index.php?pausatf_rdf=events', $rules['^rdf/events/?$'] ?? null);
        self::assertSame('index.php?pausatf_rdf=void', $rules['^rdf/void/?$'] ?? null);
        self::assertSame('index.php?pausatf_sparql=1', $rules['^sparql/?$'] ?? null);
        self::assertCount(9, $rules);
        self::assertArrayNotHasKey('init', $GLOBALS['pausatf_test_hooks']);
    }

    public function testRulesWaitForInitWhenBuiltEarlier(): void
    {
        $this->buildEndpoints();

        self::assertSame([], $GLOBALS['pausatf_test_rewrite_rules']);
        self::assertCount(2, $GLOBALS['pausatf_test_hooks']['init']);
    }

    public function testStoredRulesWithoutTheEndpointsAreRebuiltOnce(): void
    {
        $GLOBALS['pausatf_test_init_count'] = 1;
        $GLOBALS['pausatf_test_stored_rules'] = ['^feed/?$' => 'index.php?feed=rss2'];

        $this->buildEndpoints();

        self::assertCount(1, $GLOBALS['pausatf_test_hooks']['wp_loaded'] ?? []);
    }

    public function testCurrentOrAbsentStoredRulesAreLeftAlone(): void
    {
        $GLOBALS['pausatf_test_init_count'] = 1;

        // Plain permalinks: WordPress stores no rule array at all.
        $this->buildEndpoints();
        $GLOBALS['pausatf_test_stored_rules'] = ['^rdf/events/?$' => 'index.php?pausatf_rdf=events'];
        $this->buildEndpoints();

        self::assertArrayNotHasKey('wp_loaded', $GLOBALS['pausatf_test_hooks']);
    }
}
