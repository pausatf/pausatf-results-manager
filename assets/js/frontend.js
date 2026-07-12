/**
 * PAUSATF Results Manager Frontend JavaScript
 *
 * Modern ES2024 implementation without jQuery dependency.
 * Uses native DOM APIs, async/await, and module patterns.
 *
 * @package PAUSATF\Results
 * @since 3.0.0
 */

/**
 * Utility functions
 */
const utils = {
    /**
     * Debounce function execution
     * @param {Function} func Function to debounce
     * @param {number} wait Wait time in ms
     * @returns {Function} Debounced function
     */
    debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    },

    /**
     * Throttle function execution
     * @param {Function} func Function to throttle
     * @param {number} limit Time limit in ms
     * @returns {Function} Throttled function
     */
    throttle(func, limit = 100) {
        let inThrottle;
        return function executedFunction(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => (inThrottle = false), limit);
            }
        };
    },

    /**
     * Format time from seconds to HH:MM:SS or MM:SS
     * @param {number} seconds Time in seconds
     * @returns {string} Formatted time
     */
    formatTime(seconds) {
        if (seconds == null || isNaN(seconds)) return '--:--';

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);

        if (hours > 0) {
            return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }
        return `${minutes}:${String(secs).padStart(2, '0')}`;
    },

    /**
     * Parse time string to seconds
     * @param {string} time Time string (HH:MM:SS or MM:SS)
     * @returns {number} Seconds
     */
    parseTime(time) {
        if (!time || time === '-') return 999999;

        const parts = time.split(':').map(Number);
        if (parts.length === 3) {
            return parts[0] * 3600 + parts[1] * 60 + parts[2];
        } else if (parts.length === 2) {
            return parts[0] * 60 + parts[1];
        }
        return 999999;
    },

    /**
     * Fetch with timeout and error handling
     * @param {string} url URL to fetch
     * @param {RequestInit} options Fetch options
     * @param {number} timeout Timeout in ms
     * @returns {Promise<Response>}
     */
    async fetchWithTimeout(url, options = {}, timeout = 10000) {
        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), timeout);

        try {
            const response = await fetch(url, {
                ...options,
                signal: controller.signal,
            });
            clearTimeout(id);
            return response;
        } catch (error) {
            clearTimeout(id);
            if (error.name === 'AbortError') {
                throw new Error('Request timed out');
            }
            throw error;
        }
    },
};

/**
 * Results Table Component
 * Handles sorting, filtering, and pagination of results tables
 */
class ResultsTable {
    #container;
    #table;
    #rows;
    #countDisplay;
    #sortColumn = 'place';
    #sortDirection = 'asc';
    #currentFilters = {};
    #perPage = 50;
    #currentPage = 1;

    /**
     * @param {HTMLElement} container Container element
     */
    constructor(container) {
        this.#container = container;
        this.#table = container.querySelector('.pausatf-table');
        this.#rows = this.#table ? Array.from(this.#table.querySelectorAll('tbody tr')) : [];
        this.#countDisplay = container.querySelector('.pausatf-visible-count');

        if (this.#table) {
            this.#init();
        }
    }

    #init() {
        this.#bindFilters();
        this.#bindSorting();
        this.#initPagination();
    }

    #bindFilters() {
        // Division filter
        const divisionFilter = this.#container.querySelector('#pausatf-division-filter');
        if (divisionFilter) {
            divisionFilter.addEventListener('change', () => {
                this.#currentFilters.division = divisionFilter.value.toLowerCase();
                this.#applyFilters();
            });
        }

        // Search filter with debounce
        const searchFilter = this.#container.querySelector('#pausatf-search-filter');
        if (searchFilter) {
            const debouncedSearch = utils.debounce((value) => {
                this.#currentFilters.search = value.toLowerCase();
                this.#applyFilters();
            }, 300);

            searchFilter.addEventListener('input', (e) => {
                debouncedSearch(e.target.value);
            });
        }
    }

    #applyFilters() {
        let visibleCount = 0;

        this.#rows.forEach((row) => {
            const rowDivision = (row.dataset.division || '').toLowerCase();
            const rowName = (row.dataset.name || '').toLowerCase();
            const rowClub = (row.dataset.club || '').toLowerCase();

            const matchesDivision =
                !this.#currentFilters.division || rowDivision === this.#currentFilters.division;

            const matchesSearch =
                !this.#currentFilters.search ||
                rowName.includes(this.#currentFilters.search) ||
                rowClub.includes(this.#currentFilters.search);

            if (matchesDivision && matchesSearch) {
                row.classList.remove('hidden');
                visibleCount++;
            } else {
                row.classList.add('hidden');
            }
        });

        if (this.#countDisplay) {
            this.#countDisplay.textContent = String(visibleCount);
        }

        this.#announce(`Showing ${visibleCount} results`);
    }

    #bindSorting() {
        const sortableHeaders = this.#table.querySelectorAll('th.sortable');

        sortableHeaders.forEach((th) => {
            th.addEventListener('click', () => this.#handleSort(th));

            // Keyboard accessibility
            th.setAttribute('tabindex', '0');
            th.setAttribute('role', 'button');
            th.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.#handleSort(th);
                }
            });
        });
    }

    #handleSort(th) {
        const sortKey = th.dataset.sort;
        const isAsc = th.classList.contains('sort-asc');

        // Remove sort classes from all headers
        this.#table.querySelectorAll('th').forEach((header) => {
            header.classList.remove('sort-asc', 'sort-desc');
            header.removeAttribute('aria-sort');
        });

        // Toggle sort direction
        if (isAsc) {
            th.classList.add('sort-desc');
            th.setAttribute('aria-sort', 'descending');
            this.#sortDirection = 'desc';
        } else {
            th.classList.add('sort-asc');
            th.setAttribute('aria-sort', 'ascending');
            this.#sortDirection = 'asc';
        }

        this.#sortColumn = sortKey;
        const columnIndex = th.cellIndex;
        const direction = isAsc ? -1 : 1;

        // Sort rows
        const tbody = this.#table.querySelector('tbody');
        this.#rows.sort((a, b) => {
            const aCell = a.cells[columnIndex];
            const bCell = b.cells[columnIndex];

            if (!aCell || !bCell) return 0;

            let aVal = aCell.textContent.trim();
            let bVal = bCell.textContent.trim();

            // Numeric sorting for place, points
            if (sortKey === 'place' || sortKey === 'points') {
                const aNum = parseFloat(aVal) || 999999;
                const bNum = parseFloat(bVal) || 999999;
                return (aNum - bNum) * direction;
            }

            // Time sorting
            if (sortKey === 'time') {
                const aTime = utils.parseTime(aVal);
                const bTime = utils.parseTime(bVal);
                return (aTime - bTime) * direction;
            }

            // String sorting
            return aVal.localeCompare(bVal) * direction;
        });

        // Re-append sorted rows
        this.#rows.forEach((row) => tbody.appendChild(row));

        this.#announce(`Table sorted by ${sortKey}, ${this.#sortDirection}ending`);
    }

    #initPagination() {
        if (this.#rows.length > this.#perPage) {
            // Initially hide rows beyond perPage
            this.#rows.slice(this.#perPage).forEach((row) => {
                row.classList.add('pagination-hidden');
            });
        }

        const showMoreBtn = this.#container.querySelector('[data-action="show-more"]');
        if (showMoreBtn) {
            showMoreBtn.addEventListener('click', () => this.#showMore(showMoreBtn));
        }
    }

    #showMore(button) {
        this.#currentPage++;
        const showUpTo = this.#currentPage * this.#perPage;

        this.#rows.slice(0, showUpTo).forEach((row) => {
            row.classList.remove('pagination-hidden');
        });

        if (showUpTo >= this.#rows.length) {
            button.hidden = true;
        }
    }

    #announce(message) {
        let liveRegion = this.#container.querySelector('.pausatf-sr-announce');

        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.className = 'pausatf-sr-announce';
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', 'polite');
            liveRegion.style.cssText =
                'position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);';
            this.#container.appendChild(liveRegion);
        }

        liveRegion.textContent = message;
    }
}

/**
 * Athlete Search Component
 * Async search with autocomplete
 */
class AthleteSearch {
    #input;
    #dropdown;
    #cache = new Map();
    #abortController = null;

    /**
     * @param {HTMLInputElement} input Search input element
     */
    constructor(input) {
        this.#input = input;
        this.#init();
    }

    #init() {
        this.#createDropdown();
        this.#bindEvents();
    }

    #createDropdown() {
        this.#dropdown = document.createElement('div');
        this.#dropdown.className = 'pausatf-athlete-search-dropdown';
        this.#dropdown.setAttribute('role', 'listbox');
        this.#dropdown.hidden = true;
        this.#input.parentNode.appendChild(this.#dropdown);
    }

    #bindEvents() {
        const debouncedSearch = utils.debounce((value) => this.#search(value), 300);

        this.#input.addEventListener('input', (e) => {
            const value = e.target.value.trim();
            if (value.length < 2) {
                this.#hideDropdown();
                return;
            }
            debouncedSearch(value);
        });

        this.#input.addEventListener('keydown', (e) => this.#handleKeydown(e));
        this.#input.addEventListener('blur', () => {
            setTimeout(() => this.#hideDropdown(), 200);
        });
    }

    async #search(query) {
        // Check cache first
        if (this.#cache.has(query)) {
            this.#showResults(this.#cache.get(query));
            return;
        }

        // Cancel previous request
        if (this.#abortController) {
            this.#abortController.abort();
        }
        this.#abortController = new AbortController();

        const apiBase = window.pausatfFrontend?.apiBase || '/wp-json/pausatf/v1';
        const nonce = window.pausatfFrontend?.nonce || '';

        try {
            const response = await fetch(`${apiBase}/athletes/search?q=${encodeURIComponent(query)}`, {
                signal: this.#abortController.signal,
                headers: {
                    'X-WP-Nonce': nonce,
                },
            });

            if (!response.ok) throw new Error('Search failed');

            const athletes = await response.json();
            this.#cache.set(query, athletes);
            this.#showResults(athletes);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Athlete search error:', error);
            }
        }
    }

    #showResults(athletes) {
        this.#dropdown.replaceChildren();
        if (athletes.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'pausatf-no-results';
            empty.textContent = 'No athletes found';
            this.#dropdown.appendChild(empty);
        } else {
            // Build nodes with textContent: athlete names are user-supplied
            // and must never be interpolated into HTML.
            for (const athlete of athletes) {
                const option = document.createElement('div');
                option.className = 'pausatf-athlete-option';
                option.setAttribute('role', 'option');
                option.tabIndex = -1;
                option.dataset.id = String(athlete.id);
                option.dataset.name = athlete.name;

                const nameEl = document.createElement('span');
                nameEl.className = 'pausatf-athlete-name';
                nameEl.textContent = athlete.name;

                const metaEl = document.createElement('span');
                metaEl.className = 'pausatf-athlete-meta';
                metaEl.textContent = `${athlete.event_count} events`;

                option.append(nameEl, metaEl);
                option.addEventListener('click', () => this.#selectAthlete(option));
                this.#dropdown.appendChild(option);
            }
        }

        this.#dropdown.hidden = false;
    }

    #hideDropdown() {
        this.#dropdown.hidden = true;
    }

    #selectAthlete(option) {
        this.#input.value = option.dataset.name;
        this.#hideDropdown();

        this.#input.dispatchEvent(
            new CustomEvent('athlete-selected', {
                bubbles: true,
                detail: {
                    id: option.dataset.id,
                    name: option.dataset.name,
                },
            })
        );
    }

    #handleKeydown(e) {
        if (this.#dropdown.hidden) return;

        const options = this.#dropdown.querySelectorAll('.pausatf-athlete-option');
        const current = this.#dropdown.querySelector('.pausatf-athlete-option:focus');
        const currentIndex = current ? Array.from(options).indexOf(current) : -1;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                options[(currentIndex + 1) % options.length]?.focus();
                break;

            case 'ArrowUp':
                e.preventDefault();
                options[(currentIndex - 1 + options.length) % options.length]?.focus();
                break;

            case 'Enter':
                if (current) {
                    e.preventDefault();
                    this.#selectAthlete(current);
                }
                break;

            case 'Escape':
                this.#hideDropdown();
                break;
        }
    }
}

/**
 * AJAX Filter (for server-side filtering of large datasets)
 * Replaces the old jQuery-based pausatfAjaxFilter
 */
async function ajaxFilter(eventId, division, search) {
    const container = document.querySelector(`.pausatf-interactive-results[data-event-id="${eventId}"]`);
    if (!container) return;

    container.classList.add('loading');

    const apiBase = window.pausatfFrontend?.ajaxUrl || '/wp-admin/admin-ajax.php';
    const nonce = window.pausatfFrontend?.nonce || '';

    try {
        const formData = new FormData();
        formData.append('action', 'pausatf_filter_results');
        formData.append('nonce', nonce);
        formData.append('event_id', eventId);
        formData.append('division', division);
        formData.append('search', search);

        const response = await fetch(apiBase, {
            method: 'POST',
            body: formData,
        });

        const data = await response.json();

        if (data.success) {
            container.querySelector('tbody').innerHTML = data.data.html;
            const countDisplay = container.querySelector('.pausatf-visible-count');
            if (countDisplay) {
                countDisplay.textContent = data.data.count;
            }
        }
    } catch (error) {
        console.error('AJAX filter error:', error);
    } finally {
        container.classList.remove('loading');
    }
}

/**
 * Initialize all components when DOM is ready
 */
function init() {
    // Results Tables
    document.querySelectorAll('.pausatf-interactive-results').forEach((container) => {
        new ResultsTable(container);
    });

    // Athlete Search
    document.querySelectorAll('.pausatf-athlete-search').forEach((input) => {
        new AthleteSearch(input);
    });

    // Print functionality
    document.querySelectorAll('.pausatf-print-results').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    // Share functionality
    document.querySelectorAll('.pausatf-share-results').forEach((button) => {
        button.addEventListener('click', async () => {
            const url = window.location.href;
            const title = document.title;

            if (navigator.share) {
                try {
                    await navigator.share({ title, url });
                } catch (e) {
                    // User cancelled
                }
            } else {
                await navigator.clipboard.writeText(url);
                const originalText = button.textContent;
                button.textContent = 'Link copied!';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 2000);
            }
        });
    });
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

// Expose for backwards compatibility with old jQuery-based API
window.pausatfAjaxFilter = ajaxFilter;

// Export for module usage
export { ResultsTable, AthleteSearch, ajaxFilter, utils };
