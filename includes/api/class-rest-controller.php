<?php
/**
 * REST API Controller - 2026 Modern Implementation
 *
 * Base controller with JSON Schema validation, proper error handling,
 * caching headers, and OpenAPI-compatible responses.
 *
 * @package PAUSATF\Results\API
 * @since 3.0.0
 * @requires PHP 8.4
 */

declare(strict_types=1);

namespace PAUSATF\Results\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract base REST controller with modern patterns
 */
abstract class RestController extends WP_REST_Controller
{
    /**
     * API namespace
     */
    protected string $namespace = 'pausatf/v1';

    /**
     * Default cache duration in seconds
     */
    protected int $cache_duration = 3600;

    /**
     * Register routes - must be implemented by child classes
     */
    abstract public function register_routes(): void;

    /**
     * Get item schema - must be implemented by child classes
     *
     * @return array<string, mixed> JSON Schema array.
     */
    abstract public function get_item_schema(): array;

    /**
     * Create a standardized success response
     *
     * @param mixed $data Response data.
     * @param int $status HTTP status code.
     * @param array<string, string> $headers Additional headers.
     * @return WP_REST_Response
     */
    protected function success(mixed $data, int $status = 200, array $headers = []): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);

        // Add standard headers
        $response->header('X-PAUSATF-API-Version', '3.0');
        $response->header('X-Content-Type-Options', 'nosniff');

        // Add cache headers for GET requests
        if ($status === 200) {
            $response->header('Cache-Control', "public, max-age={$this->cache_duration}");
            $response->header('ETag', $this->generate_etag($data));
        }

        // Add custom headers
        foreach ($headers as $key => $value) {
            $response->header($key, $value);
        }

        return $response;
    }

    /**
     * Create a standardized error response
     *
     * @param string $code Error code.
     * @param string $message Error message.
     * @param int $status HTTP status code.
     * @param array<string, mixed> $additional Additional error data.
     * @return WP_Error
     */
    protected function error(
        string $code,
        string $message,
        int $status = 400,
        array $additional = []
    ): WP_Error {
        return new WP_Error(
            $code,
            $message,
            array_merge(['status' => $status], $additional)
        );
    }

    /**
     * Create a paginated response
     *
     * @param array<int, mixed> $items Items to return.
     * @param int $total Total items available.
     * @param int $page Current page.
     * @param int $per_page Items per page.
     * @param WP_REST_Request $request Original request.
     * @return WP_REST_Response
     */
    protected function paginated(
        array $items,
        int $total,
        int $page,
        int $per_page,
        WP_REST_Request $request
    ): WP_REST_Response {
        $total_pages = (int) ceil($total / $per_page);

        $response = $this->success($items);

        // Standard pagination headers
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $total_pages);

        // Add link headers for navigation
        $links = $this->generate_pagination_links($request, $page, $total_pages);
        if (!empty($links)) {
            $response->header('Link', implode(', ', $links));
        }

        return $response;
    }

    /**
     * Generate pagination links
     *
     * @param WP_REST_Request $request Original request.
     * @param int $current_page Current page.
     * @param int $total_pages Total pages.
     * @return array<int, string> Link header values.
     */
    private function generate_pagination_links(
        WP_REST_Request $request,
        int $current_page,
        int $total_pages
    ): array {
        $links = [];
        $base_url = rest_url($this->namespace . '/' . $this->rest_base);
        $params = $request->get_query_params();

        // First page
        if ($current_page > 1) {
            $params['page'] = 1;
            $links[] = '<' . add_query_arg($params, $base_url) . '>; rel="first"';
        }

        // Previous page
        if ($current_page > 1) {
            $params['page'] = $current_page - 1;
            $links[] = '<' . add_query_arg($params, $base_url) . '>; rel="prev"';
        }

        // Next page
        if ($current_page < $total_pages) {
            $params['page'] = $current_page + 1;
            $links[] = '<' . add_query_arg($params, $base_url) . '>; rel="next"';
        }

        // Last page
        if ($current_page < $total_pages) {
            $params['page'] = $total_pages;
            $links[] = '<' . add_query_arg($params, $base_url) . '>; rel="last"';
        }

        return $links;
    }

    /**
     * Generate ETag for caching
     *
     * @param mixed $data Data to hash
     * @return string ETag value
     */
    private function generate_etag(mixed $data): string
    {
        return '"' . md5(wp_json_encode($data)) . '"';
    }

    /**
     * Validate request against schema
     *
     * @param WP_REST_Request $request Request to validate.
     * @param array<string, mixed> $schema Schema to validate against.
     * @return true|WP_Error True if valid, WP_Error otherwise.
     */
    protected function validate_request(WP_REST_Request $request, array $schema): true|WP_Error
    {
        $data = $request->get_json_params() ?: $request->get_body_params();

        foreach ($schema['required'] ?? [] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return $this->error(
                    'missing_required_field',
                    sprintf(__('Missing required field: %s', 'pausatf-results'), $field),
                    400,
                    ['field' => $field]
                );
            }
        }

        foreach ($schema['properties'] ?? [] as $field => $rules) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            // Type validation
            $expected_type = $rules['type'] ?? 'string';
            if (!$this->validate_type($value, $expected_type)) {
                return $this->error(
                    'invalid_field_type',
                    sprintf(__('Field %s must be of type %s', 'pausatf-results'), $field, $expected_type),
                    400,
                    ['field' => $field, 'expected_type' => $expected_type]
                );
            }

            // Format validation
            if (isset($rules['format'])) {
                $format_valid = match ($rules['format']) {
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'uri', 'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
                    'date' => (bool) strtotime($value),
                    'date-time' => (bool) \DateTime::createFromFormat(\DateTimeInterface::ATOM, $value),
                    default => true,
                };

                if (!$format_valid) {
                    return $this->error(
                        'invalid_field_format',
                        sprintf(__('Field %s has invalid format', 'pausatf-results'), $field),
                        400,
                        ['field' => $field, 'format' => $rules['format']]
                    );
                }
            }

            // Enum validation
            if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                return $this->error(
                    'invalid_enum_value',
                    sprintf(__('Field %s must be one of: %s', 'pausatf-results'), $field, implode(', ', $rules['enum'])),
                    400,
                    ['field' => $field, 'allowed_values' => $rules['enum']]
                );
            }

            // Range validation
            if (isset($rules['minimum']) && $value < $rules['minimum']) {
                return $this->error(
                    'value_below_minimum',
                    sprintf(__('Field %s must be at least %s', 'pausatf-results'), $field, $rules['minimum']),
                    400
                );
            }

            if (isset($rules['maximum']) && $value > $rules['maximum']) {
                return $this->error(
                    'value_above_maximum',
                    sprintf(__('Field %s must be at most %s', 'pausatf-results'), $field, $rules['maximum']),
                    400
                );
            }
        }

        return true;
    }

    /**
     * Validate value type
     *
     * @param mixed $value Value to check
     * @param string $type Expected type
     * @return bool Whether type matches
     */
    private function validate_type(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_numeric($value) && (int) $value == $value),
            'number' => is_numeric($value),
            'boolean' => is_bool($value) || $value === 'true' || $value === 'false' || $value === 1 || $value === 0,
            'array' => is_array($value),
            'object' => is_object($value) || (is_array($value) && array_keys($value) !== range(0, count($value) - 1)),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * Get collection params with pagination
     *
     * @return array<string, array<string, mixed>> Collection parameters.
     */
    public function get_collection_params(): array
    {
        return [
            'page' => [
                'description' => __('Current page of the collection.', 'pausatf-results'),
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'description' => __('Maximum number of items to return per page.', 'pausatf-results'),
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'description' => __('Search term to filter results.', 'pausatf-results'),
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'description' => __('Field to order results by.', 'pausatf-results'),
                'type' => 'string',
                'default' => 'id',
                'enum' => ['id', 'name', 'date', 'modified'],
            ],
            'order' => [
                'description' => __('Order direction.', 'pausatf-results'),
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
            ],
        ];
    }

    /**
     * Check if current user can access endpoint
     *
     * @param string $capability Required capability
     * @return bool|WP_Error True if allowed, WP_Error otherwise
     */
    protected function check_permission(string $capability): bool|WP_Error
    {
        if (!current_user_can($capability)) {
            return $this->error(
                'rest_forbidden',
                __('You do not have permission to access this resource.', 'pausatf-results'),
                403
            );
        }

        return true;
    }

    /**
     * Handle OPTIONS request for CORS preflight
     *
     * @return WP_REST_Response Response with CORS headers
     */
    public function handle_preflight(): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 204);
        $response->header('Access-Control-Allow-Origin', get_home_url());
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-WP-Nonce');
        $response->header('Access-Control-Allow-Credentials', 'true');
        $response->header('Access-Control-Max-Age', '86400');

        return $response;
    }
}
