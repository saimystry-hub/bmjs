<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validators.php';

require_login();
require_role(['admin', 'super_admin']);

if (is_post()) {
    csrf_require();
}

if (is_post() && ($_POST['action'] ?? '') === 'add_class') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $nextClassId = isset($_POST['next_class_id']) && $_POST['next_class_id'] !== '' ? (int) $_POST['next_class_id'] : null;
    $errors = [];
    if ($name === '' || mb_strlen($name) > 30) {
        $errors[] = 'Class name must be 1 to 30 characters long.';
    }
    if (!empty($errors)) {
        foreach ($errors as $error) {
            flash_add('error', $error);
        }
    } else {
        $existing = db()->prepare('SELECT id FROM classes WHERE name = :name LIMIT 1');
        $existing->execute([':name' => $name]);
        if ($existing->fetch()) {
            flash_add('error', 'That class name already exists.');
        } else {
            $map = [];
            foreach (db()->query('SELECT id, next_class_id FROM classes')->fetchAll() as $row) {
                $map[(int) $row['id']] = $row['next_class_id'] !== null ? (int) $row['next_class_id'] : null;
            }
            if (would_create_class_cycle($map, 0, $nextClassId)) {
                flash_add('error', 'That would make the classes go in a circle.');
            } else {
                $insert = db()->prepare('INSERT INTO classes (name, sort_order, next_class_id) VALUES (:name, :sort_order, :next_class_id)');
                $insert->execute([
                    ':name' => $name,
                    ':sort_order' => $sortOrder,
                    ':next_class_id' => $nextClassId,
                ]);
                log_action('class_created', 'classes', (int) db()->lastInsertId(), null, ['name' => $name, 'next_class_id' => $nextClassId]);
                flash_add('success', 'Class added.');
                redirect('admin/classes/index.php');
            }
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'link_by_order') {
    $classes = db()->query('SELECT * FROM classes ORDER BY sort_order, id')->fetchAll();
    $ordered = [];
    foreach ($classes as $class) {
        $ordered[] = (int) $class['id'];
    }
    foreach ($classes as $index => $class) {
        $next = $index + 1 < count($classes) ? (int) $classes[$index + 1]['id'] : null;
        db()->prepare('UPDATE classes SET next_class_id = :next_class_id WHERE id = :id')->execute([
            ':next_class_id' => $next,
            ':id' => (int) $class['id'],
        ]);
    }
    log_action('class_links_updated', 'classes', null, null, ['linked_by_order' => true, 'count' => count($classes)]);
    flash_add('success', 'The next-class links were updated.');
    redirect('admin/classes/index.php');
}

$classes = db()->query('SELECT * FROM classes ORDER BY sort_order, id')->fetchAll();
$nextMap = [];
foreach ($classes as $class) {
    $nextMap[(int) $class['id']] = $class['next_class_id'] !== null ? (int) $class['next_class_id'] : null;
}
$duplicateMap = [];
foreach ($classes as $class) {
    $nextId = $class['next_class_id'] !== null ? (int) $class['next_class_id'] : null;
    if ($nextId === null) {
        continue;
    }
    $duplicateMap[$nextId][] = (int) $class['id'];
}

$maxOrder = 0;
foreach ($classes as $class) {
    $maxOrder = max($maxOrder, (int) $class['sort_order']);
}
$page_title = 'Classes';
$page_description = 'Set the class order and next-class links.';
require BASE_PATH . '/components/header.php';
$tabs = ['Years' => 'admin/classes/years.php', 'Classes' => 'admin/classes/index.php', 'Sections' => 'admin/classes/sections.php', 'Subjects' => 'admin/classes/subjects.php', 'Class subjects' => 'admin/classes/class_subjects.php'];
require BASE_PATH . '/components/tabs.php';
?>
<section class="card">
    <h2 class="card-title">Classes</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Order</th><th>Next class</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <?php $nextClassName = 'None (students graduate)'; $nextClassId = $class['next_class_id'] !== null ? (int) $class['next_class_id'] : null; if ($nextClassId !== null) { $nextClassName = db()->prepare('SELECT name FROM classes WHERE id = :id LIMIT 1'); $nextClassName->execute([':id' => $nextClassId]); $nextRow = $nextClassName->fetch(); $nextClassName = $nextRow ? $nextRow['name'] : 'None (students graduate)'; } $status = ''; if (isset($duplicateMap[$class['id']])) { $status = 'Warning: more than one class points here.'; } ?>
                    <tr>
                        <td><?= h($class['name']) ?></td>
                        <td><?= h((string) $class['sort_order']) ?></td>
                        <td><?= h($nextClassName) ?></td>
                        <td><?= $status !== '' ? '<span class="badge grade-d">Warning</span>' : '<span class="badge grade-a">OK</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="card">
    <h2 class="card-title">Add class</h2>
    <form method="post" action="<?= h(url('admin/classes/index.php')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_class">
        <div class="form-field">
            <label for="class-name">Class name</label>
            <input id="class-name" name="name" type="text" maxlength="30" required>
        </div>
        <div class="form-field">
            <label for="class-order">Order number</label>
            <input id="class-order" name="sort_order" type="number" min="0" value="<?= h((string) ($maxOrder + 1)) ?>">
        </div>
        <div class="form-field">
            <label for="class-next">Next class</label>
            <select id="class-next" name="next_class_id">
                <option value="">None (students graduate)</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= h((string) $class['id']) ?>"><?= h($class['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="button" type="submit">Add class</button>
    </form>
</section>
<section class="card">
    <h2 class="card-title">Link next classes by order</h2>
    <form method="post" action="<?= h(url('admin/classes/index.php')) ?>" data-confirm="Update the link order for all classes?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="link_by_order">
        <button class="button button-secondary" type="submit">Link next classes by order</button>
    </form>
</section>
<?php require BASE_PATH . '/components/footer.php'; ?>
