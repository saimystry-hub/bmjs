<?php
// Render a simple previous/next pager for list screens that need paging.
function render_pagination(int $page, int $perPage, int $total, string $baseUrl, array $params = []): void
{
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $totalPages));
    $query = $params;
    $query['page'] = $page;

    if ($totalPages <= 1) {
        return;
    }

    echo '<nav class="pagination" aria-label="Pagination">';
    if ($page > 1) {
        $previous = $query;
        $previous['page'] = $page - 1;
        echo '<a href="' . h(url($baseUrl . '?' . http_build_query($previous))) . '">Previous</a>';
    }
    echo '<span>Page ' . h((string) $page) . ' of ' . h((string) $totalPages) . '</span>';
    if ($page < $totalPages) {
        $next = $query;
        $next['page'] = $page + 1;
        echo '<a href="' . h(url($baseUrl . '?' . http_build_query($next))) . '">Next</a>';
    }
    echo '</nav>';
}
