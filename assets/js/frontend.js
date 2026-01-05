/**
 * PAUSATF Results Manager - Frontend JavaScript
 */

(function($) {
    'use strict';

    // Initialize when DOM is ready
    $(document).ready(function() {
        initFilters();
        initSorting();
        initShowMore();
    });

    /**
     * Initialize table filters
     */
    function initFilters() {
        var $container = $('.pausatf-interactive-results');
        var $table = $container.find('.pausatf-table');
        var $rows = $table.find('tbody tr');
        var $countDisplay = $container.find('.pausatf-visible-count');

        // Division filter
        $container.on('change', '#pausatf-division-filter', function() {
            filterResults();
        });

        // Search filter (with debounce)
        var searchTimeout;
        $container.on('input', '#pausatf-search-filter', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(filterResults, 300);
        });

        function filterResults() {
            var division = $('#pausatf-division-filter').val().toLowerCase();
            var search = $('#pausatf-search-filter').val().toLowerCase();

            var visibleCount = 0;

            $rows.each(function() {
                var $row = $(this);
                var rowDivision = $row.data('division').toLowerCase();
                var rowName = $row.data('name');
                var rowClub = $row.data('club');

                var matchesDivision = !division || rowDivision === division;
                var matchesSearch = !search ||
                    rowName.indexOf(search) !== -1 ||
                    rowClub.indexOf(search) !== -1;

                if (matchesDivision && matchesSearch) {
                    $row.removeClass('hidden');
                    visibleCount++;
                } else {
                    $row.addClass('hidden');
                }
            });

            $countDisplay.text(visibleCount);
        }
    }

    /**
     * Initialize table sorting
     */
    function initSorting() {
        var $table = $('.pausatf-table.pausatf-sortable');

        $table.find('th.sortable').on('click', function() {
            var $th = $(this);
            var $tbody = $table.find('tbody');
            var sortKey = $th.data('sort');
            var isAsc = $th.hasClass('sort-asc');

            // Remove sort classes from other headers
            $table.find('th').removeClass('sort-asc sort-desc');

            // Toggle sort direction
            if (isAsc) {
                $th.addClass('sort-desc');
            } else {
                $th.addClass('sort-asc');
            }

            var direction = isAsc ? -1 : 1;

            // Get column index
            var columnIndex = $th.index();

            // Sort rows
            var $rows = $tbody.find('tr').get();

            $rows.sort(function(a, b) {
                var aVal = $(a).find('td').eq(columnIndex).text().trim();
                var bVal = $(b).find('td').eq(columnIndex).text().trim();

                // Handle numeric sorting for place, points, time
                if (sortKey === 'place' || sortKey === 'points') {
                    aVal = parseFloat(aVal) || 999999;
                    bVal = parseFloat(bVal) || 999999;
                    return (aVal - bVal) * direction;
                }

                if (sortKey === 'time') {
                    aVal = timeToSeconds(aVal);
                    bVal = timeToSeconds(bVal);
                    return (aVal - bVal) * direction;
                }

                // String sorting
                return aVal.localeCompare(bVal) * direction;
            });

            // Re-append rows in sorted order
            $.each($rows, function(idx, row) {
                $tbody.append(row);
            });
        });
    }

    /**
     * Convert time string to seconds for sorting
     */
    function timeToSeconds(time) {
        if (!time || time === '-') return 999999;

        var parts = time.split(':');
        if (parts.length === 3) {
            return parseInt(parts[0]) * 3600 + parseInt(parts[1]) * 60 + parseInt(parts[2]);
        } else if (parts.length === 2) {
            return parseInt(parts[0]) * 60 + parseInt(parts[1]);
        }
        return 999999;
    }

    /**
     * Initialize show more pagination
     */
    function initShowMore() {
        var $container = $('.pausatf-interactive-results');
        var $table = $container.find('.pausatf-table');
        var $rows = $table.find('tbody tr');
        var perPage = 50;
        var currentPage = 1;

        // Initially hide rows beyond perPage
        if ($rows.length > perPage) {
            $rows.slice(perPage).addClass('pagination-hidden');
        }

        $container.on('click', '[data-action="show-more"]', function() {
            currentPage++;
            var showUpTo = currentPage * perPage;

            $rows.slice(0, showUpTo).removeClass('pagination-hidden');

            if (showUpTo >= $rows.length) {
                $(this).hide();
            }
        });
    }

    /**
     * AJAX filter results (alternative to client-side)
     */
    window.pausatfAjaxFilter = function(eventId, division, search) {
        var $container = $('.pausatf-interactive-results[data-event-id="' + eventId + '"]');

        $container.addClass('loading');

        $.post(pausatfFrontend.ajaxUrl, {
            action: 'pausatf_filter_results',
            nonce: pausatfFrontend.nonce,
            event_id: eventId,
            division: division,
            search: search
        }, function(response) {
            $container.removeClass('loading');

            if (response.success) {
                $container.find('tbody').html(response.data.html);
                $container.find('.pausatf-visible-count').text(response.data.count);
            }
        });
    };

})(jQuery);
