<?php
// Render a standard empty-state block for pages with no rows to show yet.
function render_empty_state(string $title, string $message, ?string $actionUrl = null, ?string $actionLabel = null): void
{
    echo '<div class="empty-state" aria-live="polite">';
    echo '<h3>' . h($title) . '</h3>';
    echo '<p>' . h($message) . '</p>';
    if ($actionUrl !== null && $actionLabel !== null) {
        echo '<p><a class="button" href="' . h(url($actionUrl)) . '">' . h($actionLabel) . '</a></p>';
    }
    echo '</div>';
}
