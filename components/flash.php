<?php foreach (flash_all() as $flash): ?>
    <?php
    $flashType = in_array($flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true)
        ? $flash['type']
        : 'info';
    ?>
    <div class="toast toast-<?= h($flashType) ?>" role="status">
        <span><?= h($flash['message'] ?? '') ?></span>
        <button type="button" class="toast-dismiss" data-toast-dismiss aria-label="Dismiss message">×</button>
    </div>
<?php endforeach; ?>
