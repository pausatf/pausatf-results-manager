<?php
/**
 * Semantic Web (RDF/SPARQL) Admin View
 *
 * @package PAUSATF\Results
 */

use PAUSATF\Results\FeatureManager;
use PAUSATF\Results\SPARQLEndpoint;

if (!defined('ABSPATH')) {
    exit;
}

// Check if feature is enabled
if (!FeatureManager::is_enabled('rdf_support')) {
    echo '<div class="wrap"><h1>' . esc_html__('Semantic Web', 'pausatf-results') . '</h1>';
    echo '<div class="notice notice-warning"><p>';
    printf(
        esc_html__('RDF & Linked Data feature is not enabled. Enable it in the %sFeatures%s settings.', 'pausatf-results'),
        '<a href="' . esc_url(admin_url('admin.php?page=pausatf-results-settings&tab=features')) . '">',
        '</a>'
    );
    echo '</p></div></div>';
    return;
}

$base_url = trailingslashit(home_url());
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
?>

<div class="wrap pausatf-semantic-wrap">
    <h1><?php esc_html_e('Semantic Web (RDF/SPARQL)', 'pausatf-results'); ?></h1>

    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg('tab', 'overview')); ?>"
           class="nav-tab <?php echo $current_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Overview', 'pausatf-results'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'sparql')); ?>"
           class="nav-tab <?php echo $current_tab === 'sparql' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('SPARQL Query', 'pausatf-results'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'export')); ?>"
           class="nav-tab <?php echo $current_tab === 'export' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Export RDF', 'pausatf-results'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'documentation')); ?>"
           class="nav-tab <?php echo $current_tab === 'documentation' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Documentation', 'pausatf-results'); ?>
        </a>
    </nav>

    <?php if ($current_tab === 'overview'): ?>
        <!-- Overview Tab -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Linked Data Endpoints', 'pausatf-results'); ?></h2>
            <p class="description">
                <?php esc_html_e('Your results data is available as Linked Data using standard vocabularies.', 'pausatf-results'); ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Endpoint', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Description', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Formats', 'pausatf-results'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>rdf/events</code></td>
                        <td><?php esc_html_e('All events as RDF', 'pausatf-results'); ?></td>
                        <td>Turtle, RDF/XML, JSON-LD, N-Triples</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>rdf/events/{id}</code></td>
                        <td><?php esc_html_e('Single event with results', 'pausatf-results'); ?></td>
                        <td>Turtle, RDF/XML, JSON-LD, N-Triples</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>rdf/athletes</code></td>
                        <td><?php esc_html_e('All athletes as RDF', 'pausatf-results'); ?></td>
                        <td>Turtle, RDF/XML, JSON-LD, N-Triples</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>rdf/athletes/{id}</code></td>
                        <td><?php esc_html_e('Single athlete with results', 'pausatf-results'); ?></td>
                        <td>Turtle, RDF/XML, JSON-LD, N-Triples</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>rdf/results</code></td>
                        <td><?php esc_html_e('All results as RDF', 'pausatf-results'); ?></td>
                        <td>Turtle, RDF/XML, JSON-LD, N-Triples</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>rdf/ontology</code></td>
                        <td><?php esc_html_e('PAUSATF ontology definition', 'pausatf-results'); ?></td>
                        <td>Turtle, RDF/XML, JSON-LD</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>rdf/void</code></td>
                        <td><?php esc_html_e('VoID dataset description', 'pausatf-results'); ?></td>
                        <td>Turtle, RDF/XML</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($base_url); ?>sparql</code></td>
                        <td><?php esc_html_e('SPARQL query endpoint', 'pausatf-results'); ?></td>
                        <td>JSON, XML, CSV, TSV</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Vocabularies Used', 'pausatf-results'); ?></h2>

            <div class="pausatf-vocab-grid">
                <div class="pausatf-vocab-card">
                    <h3>Schema.org</h3>
                    <p><?php esc_html_e('Standard vocabulary for structured data. Used for events, people, and organizations.', 'pausatf-results'); ?></p>
                    <code>http://schema.org/</code>
                    <ul>
                        <li>schema:SportsEvent</li>
                        <li>schema:Person</li>
                        <li>schema:SportsTeam</li>
                        <li>schema:SportsOrganization</li>
                    </ul>
                </div>

                <div class="pausatf-vocab-card">
                    <h3>FOAF</h3>
                    <p><?php esc_html_e('Friend of a Friend vocabulary for describing people and their relationships.', 'pausatf-results'); ?></p>
                    <code>http://xmlns.com/foaf/0.1/</code>
                    <ul>
                        <li>foaf:Person</li>
                        <li>foaf:name</li>
                        <li>foaf:givenName</li>
                        <li>foaf:familyName</li>
                    </ul>
                </div>

                <div class="pausatf-vocab-card">
                    <h3>Dublin Core</h3>
                    <p><?php esc_html_e('Metadata vocabulary for resource description.', 'pausatf-results'); ?></p>
                    <code>http://purl.org/dc/terms/</code>
                    <ul>
                        <li>dcterms:identifier</li>
                        <li>dcterms:created</li>
                        <li>dcterms:modified</li>
                        <li>dcterms:creator</li>
                    </ul>
                </div>

                <div class="pausatf-vocab-card">
                    <h3>PAUSATF Ontology</h3>
                    <p><?php esc_html_e('Custom vocabulary for athletics-specific concepts.', 'pausatf-results'); ?></p>
                    <code>https://www.pausatf.org/ontology/</code>
                    <ul>
                        <li>pausatf:Athlete</li>
                        <li>pausatf:CompetitionResult</li>
                        <li>pausatf:AgeDivision</li>
                        <li>pausatf:timeInSeconds</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Content Negotiation', 'pausatf-results'); ?></h2>
            <p><?php esc_html_e('The RDF endpoints support content negotiation via the Accept header:', 'pausatf-results'); ?></p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Accept Header', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Format', 'pausatf-results'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>text/turtle</code></td>
                        <td>Turtle (default)</td>
                    </tr>
                    <tr>
                        <td><code>application/rdf+xml</code></td>
                        <td>RDF/XML</td>
                    </tr>
                    <tr>
                        <td><code>application/ld+json</code></td>
                        <td>JSON-LD</td>
                    </tr>
                    <tr>
                        <td><code>application/n-triples</code></td>
                        <td>N-Triples</td>
                    </tr>
                </tbody>
            </table>

            <p><?php esc_html_e('Or use the format query parameter:', 'pausatf-results'); ?>
                <code>?format=turtle|rdfxml|jsonld|ntriples</code>
            </p>
        </div>

    <?php elseif ($current_tab === 'sparql'): ?>
        <!-- SPARQL Query Tab -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('SPARQL Query Interface', 'pausatf-results'); ?></h2>
            <p class="description">
                <?php esc_html_e('Execute SPARQL queries against the results database. Supports SELECT, CONSTRUCT, ASK, and DESCRIBE.', 'pausatf-results'); ?>
            </p>

            <div class="pausatf-sparql-interface">
                <div class="pausatf-sparql-editor">
                    <label for="sparql-query"><?php esc_html_e('Query:', 'pausatf-results'); ?></label>
                    <textarea id="sparql-query" rows="15" class="large-text code">PREFIX schema: <http://schema.org/>
PREFIX pausatf: <https://www.pausatf.org/ontology/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>

SELECT ?event ?name ?date
WHERE {
    ?event a schema:SportsEvent ;
           schema:name ?name .
    OPTIONAL { ?event schema:startDate ?date }
}
ORDER BY DESC(?date)
LIMIT 20</textarea>
                </div>

                <div class="pausatf-sparql-controls">
                    <select id="sparql-format">
                        <option value="json">JSON</option>
                        <option value="xml">XML</option>
                        <option value="csv">CSV</option>
                        <option value="tsv">TSV</option>
                    </select>
                    <button type="button" class="button button-primary" id="sparql-execute">
                        <span class="dashicons dashicons-controls-play"></span>
                        <?php esc_html_e('Execute Query', 'pausatf-results'); ?>
                    </button>
                    <span class="spinner" style="float: none;"></span>
                </div>

                <div class="pausatf-sparql-results" id="sparql-results" style="display: none;">
                    <h3><?php esc_html_e('Results', 'pausatf-results'); ?></h3>
                    <div class="pausatf-sparql-stats" id="sparql-stats"></div>
                    <div class="pausatf-sparql-output" id="sparql-output"></div>
                </div>
            </div>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Example Queries', 'pausatf-results'); ?></h2>

            <div class="pausatf-examples-grid">
                <?php
                $examples = SPARQLEndpoint::get_example_queries();
                foreach ($examples as $example):
                ?>
                <div class="pausatf-example-card">
                    <h4><?php echo esc_html($example['title']); ?></h4>
                    <p><?php echo esc_html($example['description']); ?></p>
                    <button type="button" class="button pausatf-load-example"
                            data-query="<?php echo esc_attr($example['query']); ?>">
                        <?php esc_html_e('Load Example', 'pausatf-results'); ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php elseif ($current_tab === 'export'): ?>
        <!-- Export Tab -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Export RDF Data', 'pausatf-results'); ?></h2>
            <p class="description">
                <?php esc_html_e('Download your complete dataset in various RDF formats.', 'pausatf-results'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Data to Export', 'pausatf-results'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="export_events" value="1" checked>
                            <?php esc_html_e('Events', 'pausatf-results'); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="export_athletes" value="1" checked>
                            <?php esc_html_e('Athletes', 'pausatf-results'); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="export_results" value="1" checked>
                            <?php esc_html_e('Results', 'pausatf-results'); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="export_ontology" value="1" checked>
                            <?php esc_html_e('Include Ontology', 'pausatf-results'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Format', 'pausatf-results'); ?></th>
                    <td>
                        <select name="export_format" id="export-format">
                            <option value="turtle">Turtle (.ttl)</option>
                            <option value="rdfxml">RDF/XML (.rdf)</option>
                            <option value="jsonld">JSON-LD (.jsonld)</option>
                            <option value="ntriples">N-Triples (.nt)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <p>
                <a href="<?php echo esc_url($base_url . 'rdf/events'); ?>" class="button" download>
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Download Events', 'pausatf-results'); ?>
                </a>
                <a href="<?php echo esc_url($base_url . 'rdf/athletes'); ?>" class="button" download>
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Download Athletes', 'pausatf-results'); ?>
                </a>
                <a href="<?php echo esc_url($base_url . 'rdf/results'); ?>" class="button" download>
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Download Results', 'pausatf-results'); ?>
                </a>
            </p>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Quick Links', 'pausatf-results'); ?></h2>

            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('SPARQL Endpoint', 'pausatf-results'); ?></strong></td>
                        <td>
                            <code><?php echo esc_html($base_url . 'sparql'); ?></code>
                            <a href="<?php echo esc_url($base_url . 'sparql'); ?>" target="_blank" class="button button-small">
                                <?php esc_html_e('Open', 'pausatf-results'); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('VoID Description', 'pausatf-results'); ?></strong></td>
                        <td>
                            <code><?php echo esc_html($base_url . 'rdf/void'); ?></code>
                            <a href="<?php echo esc_url($base_url . 'rdf/void'); ?>" target="_blank" class="button button-small">
                                <?php esc_html_e('Open', 'pausatf-results'); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Ontology', 'pausatf-results'); ?></strong></td>
                        <td>
                            <code><?php echo esc_html($base_url . 'rdf/ontology'); ?></code>
                            <a href="<?php echo esc_url($base_url . 'rdf/ontology'); ?>" target="_blank" class="button button-small">
                                <?php esc_html_e('Open', 'pausatf-results'); ?>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    <?php elseif ($current_tab === 'documentation'): ?>
        <!-- Documentation Tab -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('SPARQL Query Language', 'pausatf-results'); ?></h2>

            <h3><?php esc_html_e('Supported Query Forms', 'pausatf-results'); ?></h3>
            <ul>
                <li><strong>SELECT</strong> - <?php esc_html_e('Returns tabular results', 'pausatf-results'); ?></li>
                <li><strong>CONSTRUCT</strong> - <?php esc_html_e('Returns RDF triples', 'pausatf-results'); ?></li>
                <li><strong>ASK</strong> - <?php esc_html_e('Returns boolean (true/false)', 'pausatf-results'); ?></li>
                <li><strong>DESCRIBE</strong> - <?php esc_html_e('Returns description of resources', 'pausatf-results'); ?></li>
            </ul>

            <h3><?php esc_html_e('Supported Features', 'pausatf-results'); ?></h3>
            <ul>
                <li>PREFIX declarations</li>
                <li>Triple patterns</li>
                <li>OPTIONAL patterns</li>
                <li>FILTER (regex, comparison, contains)</li>
                <li>ORDER BY (ASC/DESC)</li>
                <li>LIMIT and OFFSET</li>
                <li>DISTINCT</li>
            </ul>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Available Prefixes', 'pausatf-results'); ?></h2>

            <pre class="pausatf-code-block">PREFIX rdf: &lt;http://www.w3.org/1999/02/22-rdf-syntax-ns#&gt;
PREFIX rdfs: &lt;http://www.w3.org/2000/01/rdf-schema#&gt;
PREFIX xsd: &lt;http://www.w3.org/2001/XMLSchema#&gt;
PREFIX schema: &lt;http://schema.org/&gt;
PREFIX foaf: &lt;http://xmlns.com/foaf/0.1/&gt;
PREFIX dc: &lt;http://purl.org/dc/elements/1.1/&gt;
PREFIX dcterms: &lt;http://purl.org/dc/terms/&gt;
PREFIX skos: &lt;http://www.w3.org/2004/02/skos/core#&gt;
PREFIX pausatf: &lt;https://www.pausatf.org/ontology/&gt;
PREFIX usatf: &lt;https://www.usatf.org/ontology/&gt;</pre>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('PAUSATF Ontology Classes', 'pausatf-results'); ?></h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Class', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Description', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Parent Class', 'pausatf-results'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>pausatf:AthleticsCompetition</code></td>
                        <td><?php esc_html_e('An athletics competition event', 'pausatf-results'); ?></td>
                        <td>schema:SportsEvent</td>
                    </tr>
                    <tr>
                        <td><code>pausatf:Athlete</code></td>
                        <td><?php esc_html_e('A competitor in athletics events', 'pausatf-results'); ?></td>
                        <td>schema:Person</td>
                    </tr>
                    <tr>
                        <td><code>pausatf:CompetitionResult</code></td>
                        <td><?php esc_html_e('The result of an athlete in a competition', 'pausatf-results'); ?></td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td><code>pausatf:AgeDivision</code></td>
                        <td><?php esc_html_e('An age-based competition division', 'pausatf-results'); ?></td>
                        <td>skos:Concept</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('PAUSATF Ontology Properties', 'pausatf-results'); ?></h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Property', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Domain', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Range', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Description', 'pausatf-results'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>pausatf:inEvent</code></td>
                        <td>CompetitionResult</td>
                        <td>AthleticsCompetition</td>
                        <td><?php esc_html_e('The event this result is from', 'pausatf-results'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pausatf:athlete</code></td>
                        <td>CompetitionResult</td>
                        <td>Athlete</td>
                        <td><?php esc_html_e('The athlete who achieved this result', 'pausatf-results'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pausatf:overallPlace</code></td>
                        <td>CompetitionResult</td>
                        <td>xsd:integer</td>
                        <td><?php esc_html_e('Overall finishing place', 'pausatf-results'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pausatf:divisionPlace</code></td>
                        <td>CompetitionResult</td>
                        <td>xsd:integer</td>
                        <td><?php esc_html_e('Place within age division', 'pausatf-results'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pausatf:timeInSeconds</code></td>
                        <td>CompetitionResult</td>
                        <td>xsd:integer</td>
                        <td><?php esc_html_e('Finishing time in seconds', 'pausatf-results'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pausatf:displayTime</code></td>
                        <td>CompetitionResult</td>
                        <td>xsd:string</td>
                        <td><?php esc_html_e('Human-readable time display', 'pausatf-results'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pausatf:division</code></td>
                        <td>CompetitionResult</td>
                        <td>AgeDivision</td>
                        <td><?php esc_html_e('The age division for this result', 'pausatf-results'); ?></td>
                    </tr>
                    <tr>
                        <td><code>pausatf:competitionAge</code></td>
                        <td>CompetitionResult</td>
                        <td>xsd:integer</td>
                        <td><?php esc_html_e('Age of athlete at time of competition', 'pausatf-results'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.pausatf-semantic-wrap {
    max-width: 1200px;
}

.pausatf-vocab-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.pausatf-vocab-card {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
}

.pausatf-vocab-card h3 {
    margin-top: 0;
    color: #2271b1;
}

.pausatf-vocab-card code {
    display: block;
    margin: 10px 0;
    padding: 5px;
    background: #fff;
    border: 1px solid #ddd;
    font-size: 11px;
}

.pausatf-vocab-card ul {
    margin: 10px 0 0 20px;
    font-size: 13px;
}

.pausatf-sparql-interface {
    margin-top: 15px;
}

.pausatf-sparql-editor textarea {
    font-family: monospace;
    font-size: 13px;
}

.pausatf-sparql-controls {
    margin: 15px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.pausatf-sparql-controls .button-primary {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.pausatf-sparql-results {
    margin-top: 20px;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.pausatf-sparql-stats {
    margin-bottom: 10px;
    font-size: 13px;
    color: #666;
}

.pausatf-sparql-output {
    overflow-x: auto;
}

.pausatf-sparql-output table {
    border-collapse: collapse;
    width: 100%;
    background: #fff;
}

.pausatf-sparql-output th,
.pausatf-sparql-output td {
    padding: 8px 12px;
    border: 1px solid #ddd;
    text-align: left;
    font-size: 13px;
}

.pausatf-sparql-output th {
    background: #f0f0f1;
    font-weight: 600;
}

.pausatf-examples-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.pausatf-example-card {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
}

.pausatf-example-card h4 {
    margin: 0 0 10px 0;
}

.pausatf-example-card p {
    margin: 0 0 10px 0;
    font-size: 13px;
    color: #666;
}

.pausatf-code-block {
    background: #23282d;
    color: #f1f1f1;
    padding: 15px;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 12px;
    line-height: 1.6;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Execute SPARQL query
    $('#sparql-execute').on('click', function() {
        var $btn = $(this);
        var $spinner = $btn.siblings('.spinner');
        var query = $('#sparql-query').val();
        var format = $('#sparql-format').val();

        if (!query.trim()) {
            alert('Please enter a SPARQL query');
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.ajax({
            url: '<?php echo esc_url(home_url('/sparql')); ?>',
            method: 'POST',
            data: { query: query, format: format },
            success: function(response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                displayResults(response, format);
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');

                var error = 'Query failed';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.error) error = resp.error;
                } catch(e) {}

                $('#sparql-results').show();
                $('#sparql-stats').text('Error');
                $('#sparql-output').html('<div style="color: #d63638;">' + error + '</div>');
            }
        });
    });

    function displayResults(data, format) {
        $('#sparql-results').show();

        // Handle different result types
        if (data.boolean !== undefined) {
            // ASK result
            $('#sparql-stats').text('ASK query result');
            $('#sparql-output').html('<strong>' + (data.boolean ? 'true' : 'false') + '</strong>');
        } else if (data.head && data.results) {
            // SELECT result
            var bindings = data.results.bindings;
            var vars = data.head.vars;

            $('#sparql-stats').text(bindings.length + ' result(s)');

            var html = '<table><thead><tr>';
            vars.forEach(function(v) {
                html += '<th>' + v + '</th>';
            });
            html += '</tr></thead><tbody>';

            bindings.forEach(function(binding) {
                html += '<tr>';
                vars.forEach(function(v) {
                    var val = binding[v];
                    html += '<td>';
                    if (val) {
                        if (val.type === 'uri') {
                            html += '<a href="' + val.value + '" target="_blank">' + val.value + '</a>';
                        } else {
                            html += val.value;
                        }
                    }
                    html += '</td>';
                });
                html += '</tr>';
            });

            html += '</tbody></table>';
            $('#sparql-output').html(html);
        } else if (data.triples) {
            // CONSTRUCT/DESCRIBE result
            $('#sparql-stats').text(data.triples.length + ' triple(s)');
            $('#sparql-output').html('<pre>' + JSON.stringify(data.triples, null, 2) + '</pre>');
        } else {
            $('#sparql-output').html('<pre>' + JSON.stringify(data, null, 2) + '</pre>');
        }
    }

    // Load example queries
    $('.pausatf-load-example').on('click', function() {
        var query = $(this).data('query');
        $('#sparql-query').val(query);
        $('html, body').animate({
            scrollTop: $('#sparql-query').offset().top - 50
        }, 300);
    });
});
</script>
