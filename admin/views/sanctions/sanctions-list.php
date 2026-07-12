<?php
/**
 * Sanctions List Admin View
 *
 * @package PAUSATF\Results\Sanctions
 */

if (!defined('ABSPATH')) {
    exit;
}

use PAUSATF\Results\SanctionsManager;

global $wpdb;

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$event_type_filter = isset($_GET['event_type']) ? sanitize_text_field($_GET['event_type']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$per_page = 20;

// Build query
$table = $wpdb->prefix . 'pausatf_sanctions';
$where = ['1=1'];
$params = [];

if ($status_filter) {
    $where[] = 'local_status = %s';
    $params[] = $status_filter;
}

if ($event_type_filter) {
    $where[] = 'event_type = %s';
    $params[] = $event_type_filter;
}

if ($search) {
    $where[] = '(event_name LIKE %s OR organizer_name LIKE %s OR organizer_email LIKE %s OR usatf_sanction_number LIKE %s)';
    $search_like = '%' . $wpdb->esc_like($search) . '%';
    $params = array_merge($params, [$search_like, $search_like, $search_like, $search_like]);
}

$where_sql = implode(' AND ', $where);

// Get total count
$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
$total_items = $params ? $wpdb->get_var($wpdb->prepare($count_query, $params)) : $wpdb->get_var($count_query);

// Get items
$offset = ($paged - 1) * $per_page;
$query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$query_params = array_merge($params, [$per_page, $offset]);
$sanctions = $wpdb->get_results($wpdb->prepare($query, $query_params), ARRAY_A);

// Get status counts
$status_counts = $wpdb->get_results(
    "SELECT local_status, COUNT(*) as count FROM {$table} GROUP BY local_status",
    OBJECT_K
);

// Calculate pagination
$total_pages = ceil($total_items / $per_page);

// Status labels and colors
$status_labels = [
    'draft' => __('Draft', 'pausatf-results'),
    'submitted' => __('Submitted', 'pausatf-results'),
    'under_review' => __('Under Review', 'pausatf-results'),
    'approved' => __('Approved', 'pausatf-results'),
    'rejected' => __('Rejected', 'pausatf-results'),
    'cancelled' => __('Cancelled', 'pausatf-results'),
];

$event_types = [
    'road' => __('Road Race', 'pausatf-results'),
    'track' => __('Track & Field', 'pausatf-results'),
    'xc' => __('Cross Country', 'pausatf-results'),
    'trail' => __('Trail', 'pausatf-results'),
    'racewalk' => __('Race Walk', 'pausatf-results'),
    'multi' => __('Multi-Day', 'pausatf-results'),
];
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Event Sanctions', 'pausatf-results'); ?></h1>

    <?php if (current_user_can('manage_sanctions')) : ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=new')); ?>" class="page-title-action">
            <?php esc_html_e('Add New', 'pausatf-results'); ?>
        </a>
    <?php endif; ?>

    <hr class="wp-header-end">

    <!-- Status Tabs -->
    <ul class="subsubsub">
        <li class="all">
            <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions')); ?>"
               class="<?php echo empty($status_filter) ? 'current' : ''; ?>">
                <?php esc_html_e('All', 'pausatf-results'); ?>
                <span class="count">(<?php echo number_format($total_items); ?>)</span>
            </a> |
        </li>
        <?php foreach ($status_labels as $status => $label) :
            $count = isset($status_counts[$status]) ? $status_counts[$status]->count : 0;
            if ($count > 0) :
        ?>
            <li class="<?php echo esc_attr($status); ?>">
                <a href="<?php echo esc_url(add_query_arg('status', $status, admin_url('admin.php?page=pausatf-sanctions'))); ?>"
                   class="<?php echo $status_filter === $status ? 'current' : ''; ?>">
                    <?php echo esc_html($label); ?>
                    <span class="count">(<?php echo number_format($count); ?>)</span>
                </a> |
            </li>
        <?php endif; endforeach; ?>
    </ul>

    <!-- Search and Filters -->
    <form method="get" action="" class="sanctions-filters">
        <input type="hidden" name="page" value="pausatf-sanctions">

        <p class="search-box">
            <label class="screen-reader-text" for="sanction-search-input">
                <?php esc_html_e('Search Sanctions', 'pausatf-results'); ?>
            </label>
            <input type="search" id="sanction-search-input" name="s"
                   value="<?php echo esc_attr($search); ?>"
                   placeholder="<?php esc_attr_e('Search events, organizers...', 'pausatf-results'); ?>">

            <select name="event_type">
                <option value=""><?php esc_html_e('All Event Types', 'pausatf-results'); ?></option>
                <?php foreach ($event_types as $type => $label) : ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected($event_type_filter, $type); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php submit_button(__('Search', 'pausatf-results'), '', '', false); ?>
        </p>
    </form>

    <!-- Sanctions Table -->
    <table class="wp-list-table widefat fixed striped sanctions-table">
        <thead>
            <tr>
                <th scope="col" class="column-event"><?php esc_html_e('Event', 'pausatf-results'); ?></th>
                <th scope="col" class="column-date"><?php esc_html_e('Date', 'pausatf-results'); ?></th>
                <th scope="col" class="column-type"><?php esc_html_e('Type', 'pausatf-results'); ?></th>
                <th scope="col" class="column-organizer"><?php esc_html_e('Organizer', 'pausatf-results'); ?></th>
                <th scope="col" class="column-status"><?php esc_html_e('Status', 'pausatf-results'); ?></th>
                <th scope="col" class="column-sanction"><?php esc_html_e('Sanction #', 'pausatf-results'); ?></th>
                <th scope="col" class="column-fee"><?php esc_html_e('Fee', 'pausatf-results'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sanctions)) : ?>
                <tr>
                    <td colspan="7">
                        <?php esc_html_e('No sanctions found.', 'pausatf-results'); ?>
                        <?php if (current_user_can('manage_sanctions')) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=new')); ?>">
                                <?php esc_html_e('Create your first sanction application.', 'pausatf-results'); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($sanctions as $sanction) : ?>
                    <tr>
                        <td class="column-event">
                            <strong>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=edit&id=' . $sanction['id'])); ?>">
                                    <?php echo esc_html($sanction['event_name']); ?>
                                </a>
                            </strong>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=edit&id=' . $sanction['id'])); ?>">
                                        <?php esc_html_e('Edit', 'pausatf-results'); ?>
                                    </a> |
                                </span>
                                <span class="view">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=view&id=' . $sanction['id'])); ?>">
                                        <?php esc_html_e('View', 'pausatf-results'); ?>
                                    </a>
                                </span>
                                <?php if (current_user_can('review_sanctions') && in_array($sanction['local_status'], ['submitted', 'under_review'])) : ?>
                                    | <span class="review">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=review&id=' . $sanction['id'])); ?>">
                                            <?php esc_html_e('Review', 'pausatf-results'); ?>
                                        </a>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="sanction-location">
                                <?php echo esc_html($sanction['event_city']); ?>, <?php echo esc_html($sanction['event_state']); ?>
                            </div>
                        </td>
                        <td class="column-date">
                            <?php
                            $event_date = strtotime($sanction['event_date']);
                            echo esc_html(date_i18n(get_option('date_format'), $event_date));
                            if ($sanction['event_end_date'] && $sanction['event_end_date'] !== $sanction['event_date']) {
                                echo ' - ' . esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_end_date'])));
                            }
                            ?>
                        </td>
                        <td class="column-type">
                            <?php echo esc_html($event_types[$sanction['event_type']] ?? $sanction['event_type']); ?>
                            <?php if ($sanction['event_distance']) : ?>
                                <br><small><?php echo esc_html($sanction['event_distance']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="column-organizer">
                            <?php echo esc_html($sanction['organizer_name']); ?>
                            <br><small><?php echo esc_html($sanction['organizer_email']); ?></small>
                        </td>
                        <td class="column-status">
                            <span class="sanction-status sanction-status-<?php echo esc_attr($sanction['local_status']); ?>">
                                <?php echo esc_html($status_labels[$sanction['local_status']] ?? $sanction['local_status']); ?>
                            </span>
                            <?php if ($sanction['national_status'] !== 'not_submitted') : ?>
                                <br>
                                <small class="national-status">
                                    <?php
                                    $national_labels = [
                                        'pending' => __('National: Pending', 'pausatf-results'),
                                        'approved' => __('National: Approved', 'pausatf-results'),
                                        'denied' => __('National: Denied', 'pausatf-results'),
                                    ];
                                    echo esc_html($national_labels[$sanction['national_status']] ?? '');
                                    ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="column-sanction">
                            <?php if ($sanction['usatf_sanction_number']) : ?>
                                <code><?php echo esc_html($sanction['usatf_sanction_number']); ?></code>
                            <?php else : ?>
                                <span class="na">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-fee">
                            <?php if ($sanction['total_fee'] > 0) : ?>
                                $<?php echo number_format($sanction['total_fee'], 2); ?>
                                <?php if ($sanction['fee_paid']) : ?>
                                    <span class="dashicons dashicons-yes-alt fee-paid" title="<?php esc_attr_e('Paid', 'pausatf-results'); ?>"></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="na">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="col" class="column-event"><?php esc_html_e('Event', 'pausatf-results'); ?></th>
                <th scope="col" class="column-date"><?php esc_html_e('Date', 'pausatf-results'); ?></th>
                <th scope="col" class="column-type"><?php esc_html_e('Type', 'pausatf-results'); ?></th>
                <th scope="col" class="column-organizer"><?php esc_html_e('Organizer', 'pausatf-results'); ?></th>
                <th scope="col" class="column-status"><?php esc_html_e('Status', 'pausatf-results'); ?></th>
                <th scope="col" class="column-sanction"><?php esc_html_e('Sanction #', 'pausatf-results'); ?></th>
                <th scope="col" class="column-fee"><?php esc_html_e('Fee', 'pausatf-results'); ?></th>
            </tr>
        </tfoot>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(
                        _n('%s item', '%s items', $total_items, 'pausatf-results'),
                        number_format($total_items)
                    ); ?>
                </span>
                <span class="pagination-links">
                    <?php
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => (int) $total_pages,
                        'current' => $paged,
                    ];
                    echo paginate_links($pagination_args);
                    ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.sanctions-filters {
    margin: 1em 0;
}
.sanctions-filters .search-box {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.sanctions-table .column-event { width: 25%; }
.sanctions-table .column-date { width: 12%; }
.sanctions-table .column-type { width: 12%; }
.sanctions-table .column-organizer { width: 18%; }
.sanctions-table .column-status { width: 12%; }
.sanctions-table .column-sanction { width: 10%; }
.sanctions-table .column-fee { width: 8%; }

.sanction-location {
    color: #646970;
    font-size: 12px;
    margin-top: 4px;
}

.sanction-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}
.sanction-status-draft { background: #e2e3e5; color: #41464b; }
.sanction-status-submitted { background: #cfe2ff; color: #084298; }
.sanction-status-under_review { background: #fff3cd; color: #664d03; }
.sanction-status-approved { background: #d1e7dd; color: #0a3622; }
.sanction-status-rejected { background: #f8d7da; color: #58151c; }
.sanction-status-cancelled { background: #d3d3d4; color: #41464b; }

.national-status {
    color: #646970;
}

.fee-paid {
    color: #00a32a;
    vertical-align: middle;
}

.na {
    color: #a7aaad;
}

@media screen and (max-width: 782px) {
    .sanctions-table .column-type,
    .sanctions-table .column-sanction {
        display: none;
    }
}
</style>
