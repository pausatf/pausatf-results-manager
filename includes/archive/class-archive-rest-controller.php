<?php
/**
 * REST API for published archive releases (pausatf/v1).
 *
 * Routes (unchanged URLs from the earlier function-based routes, plus a paginated listing):
 *   GET /pausatf/v1/archive/releases                         published releases, newest first
 *   GET /pausatf/v1/archive/releases/{id}                    one published release with collection counts
 *   GET /pausatf/v1/archive/releases/{id}/{collection}       one collection of a published release, paginated
 *   GET /pausatf/v1/archive/review/{id}                      private candidate inspection (archive roles only)
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use InvalidArgumentException;
use Throwable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ArchiveRestController extends WP_REST_Controller {
    private const MAX_PAGE = 100000;

    public function __construct() {
        $this->namespace = 'pausatf/v1';
        $this->rest_base = 'archive/releases';
    }

    public function register_routes(): void {
        $id = ['id' => ['description' => __('Release identifier.', 'pausatf-results'), 'type' => 'integer', 'minimum' => 1, 'required' => true]];
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_items'], 'permission_callback' => '__return_true', 'args' => $this->get_collection_params()],
            'schema' => [$this, 'get_public_item_schema'],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[1-9][0-9]*)', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_item'], 'permission_callback' => '__return_true', 'args' => $id],
            'schema' => [$this, 'get_public_item_schema'],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[1-9][0-9]*)/(?P<collection>' . implode('|', ArchiveBundle::ENTITIES) . ')', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_collection'], 'permission_callback' => '__return_true',
             'args' => $id + $this->pagination_args()],
        ]);
        register_rest_route($this->namespace, '/archive/review/(?P<id>[1-9][0-9]*)', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_candidate'], 'permission_callback' => [$this, 'candidate_permissions_check'], 'args' => $id],
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    public function get_collection_params(): array {
        return $this->pagination_args();
    }

    /** @return array<string,array<string,mixed>> */
    private function pagination_args(): array {
        return [
            'page' => ['description' => __('Page of the result set.', 'pausatf-results'), 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE, 'default' => 1],
            'per_page' => ['description' => __('Items per page.', 'pausatf-results'), 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
        ];
    }

    /** @param WP_REST_Request<array<string,mixed>> $request */
    public function get_items($request): WP_REST_Response|WP_Error {
        return $this->guard(function () use ($request): WP_REST_Response {
            [$page, $per_page] = $this->page($request);
            $workflow = ArchiveModule::workflow();
            $total = $workflow->published_count();
            $items = array_map(fn(array $row): array => $this->prepare_for_response_data($row, $request), $workflow->published_index(($page - 1) * $per_page, $per_page));
            $response = new WP_REST_Response($items);
            $response->header('X-WP-Total', (string) $total);
            $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
            return $response;
        });
    }

    /** @param WP_REST_Request<array<string,mixed>> $request */
    public function get_item($request): WP_REST_Response|WP_Error {
        return $this->guard(function () use ($request): WP_REST_Response|WP_Error {
            $release = ArchiveModule::workflow()->published(ArchiveModule::id($request['id']));
            if ($release === null) { return new WP_Error('archive_not_found', __('Published archive release not found.', 'pausatf-results'), ['status' => 404]); }
            $release['collections'] = array_map('count', $release['entities']);
            unset($release['entities']);
            return new WP_REST_Response($release);
        });
    }

    /** @param WP_REST_Request<array<string,mixed>> $request */
    public function get_collection(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->guard(function () use ($request): WP_REST_Response|WP_Error {
            $release = ArchiveModule::workflow()->published(ArchiveModule::id($request['id']));
            if ($release === null) { return new WP_Error('archive_not_found', __('Published archive release not found.', 'pausatf-results'), ['status' => 404]); }
            $kind = $request->get_param('collection');
            if (!is_string($kind) || !in_array($kind, ArchiveBundle::ENTITIES, true)) { throw new InvalidArgumentException('Invalid collection.'); }
            [$page, $per_page] = $this->page($request);
            $entities = $release['entities'];
            unset($release['entities']);
            $release += ['collection' => $kind, 'total' => count($entities[$kind]), 'page' => $page, 'per_page' => $per_page,
                         'records' => array_slice($entities[$kind], ($page - 1) * $per_page, $per_page)];
            $response = new WP_REST_Response($release);
            $response->header('X-WP-Total', (string) $release['total']);
            $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($release['total'] / $per_page)));
            return $response;
        });
    }

    /** @param WP_REST_Request<array<string,mixed>> $request */
    public function candidate_permissions_check(WP_REST_Request $request): bool|WP_Error {
        return ArchiveModule::action_allowed('inspect')
            ? true
            : new WP_Error('archive_forbidden', __('Archive access required.', 'pausatf-results'), ['status' => rest_authorization_required_code()]);
    }

    /** @param WP_REST_Request<array<string,mixed>> $request */
    public function get_candidate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->guard(function () use ($request): WP_REST_Response|WP_Error {
            $row = ArchiveModule::workflow()->inspect(ArchiveModule::id($request['id']));
            return $row === null ? new WP_Error('archive_not_found', __('Archive candidate not found.', 'pausatf-results'), ['status' => 404]) : new WP_REST_Response($row);
        });
    }

    /**
     * @param array<string,mixed> $row
     * @param WP_REST_Request<array<string,mixed>> $request
     * @return array<string,mixed>
     */
    private function prepare_for_response_data(array $row, WP_REST_Request $request): array {
        $data = [
            'id' => (int) $row['id'], 'competition_key' => (string) $row['competition_key'],
            'published_at' => $row['published_at'], 'source_url' => $row['source_url'],
            'bundle_sha256' => (string) $row['bundle_sha256'],
        ];
        return $this->filter_response_by_context($data, $request['context'] ?? 'view');
    }

    /** @return array<string,mixed> */
    public function get_item_schema(): array {
        if ($this->schema) { return $this->add_additional_fields_schema($this->schema); }
        $this->schema = [
            '$schema' => 'http://json-schema.org/draft-04/schema#', 'title' => 'pausatf-archive-release', 'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readonly' => true, 'context' => ['view', 'embed']],
                'competition_key' => ['type' => 'string', 'readonly' => true, 'context' => ['view', 'embed']],
                'published_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'readonly' => true, 'context' => ['view', 'embed']],
                'source_url' => ['type' => ['string', 'null'], 'format' => 'uri', 'readonly' => true, 'context' => ['view', 'embed']],
                'bundle_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'readonly' => true, 'context' => ['view', 'embed']],
                'source_sha256' => ['type' => 'string', 'readonly' => true, 'context' => ['view']],
                'supersedes_id' => ['type' => ['integer', 'null'], 'readonly' => true, 'context' => ['view']],
                'collections' => ['type' => 'object', 'readonly' => true, 'context' => ['view'], 'additionalProperties' => ['type' => 'integer']],
                'record_ratification' => ['type' => 'string', 'readonly' => true, 'context' => ['view']],
            ],
        ];
        return $this->add_additional_fields_schema($this->schema);
    }

    /**
     * @param WP_REST_Request<array<string,mixed>> $request
     * @return array{int,int}
     */
    private function page(WP_REST_Request $request): array {
        $page = ArchiveModule::id($request->get_param('page') ?? 1);
        $per_page = ArchiveModule::id($request->get_param('per_page') ?? 50);
        if ($page > self::MAX_PAGE || $per_page > 100) { throw new InvalidArgumentException('Invalid archive page.'); }
        return [$page, $per_page];
    }

    private function guard(callable $operation): WP_REST_Response|WP_Error {
        try {
            return $operation();
        } catch (InvalidArgumentException) {
            return new WP_Error('archive_invalid_request', __('Invalid archive request.', 'pausatf-results'), ['status' => 400]);
        } catch (Throwable) {
            return new WP_Error('archive_unavailable', __('Archive is unavailable.', 'pausatf-results'), ['status' => 503]);
        }
    }
}
