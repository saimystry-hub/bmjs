<?php
if (!isset($tabs) || !is_array($tabs)) {
    $tabs = [];
}
if (!isset($active)) {
    $active = '';
}
?>
<nav class="tabs" aria-label="Section tabs">
    <?php foreach ($tabs as $label => $url): ?>
        <a class="tab <?= h($label === $active ? 'is-active' : '') ?>" href="<?= h(url($url)) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</nav>
