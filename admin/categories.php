<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('categories.manage');

$editId = input_int('edit');
$editing = $editId ? DB::row('SELECT * FROM content_categories WHERE id = ?', [$editId]) : null;
$errors = [];

if (is_post()) {
    require_csrf();
    $action = (string) input('action');

    if ($action === 'delete') {
        $cat = DB::row('SELECT * FROM content_categories WHERE id = ?', [input_int('id')]);
        if ($cat) {
            DB::run('DELETE FROM content_categories WHERE id = ?', [(int) $cat['id']]);
            AuditLog::record('category_changed', 'Deleted category: ' . $cat['name']);
            flash('success', 'Category deleted. Its FAQs, articles and tickets are now uncategorised.');
        }
        redirect('admin/categories.php');
    }
    if ($action === 'toggle') {
        DB::run('UPDATE content_categories SET is_active = 1 - is_active WHERE id = ?', [input_int('id')]);
        AuditLog::record('category_changed', 'Toggled visibility of category #' . input_int('id'));
        flash('success', 'Category visibility updated.');
        redirect('admin/categories.php');
    }

    // Create or update
    $name = trim((string) input('name'));
    $slug = slugify(trim((string) input('slug')) ?: $name);
    $description = trim((string) input('description'));
    $icon = trim((string) input('icon')) ?: 'fa-folder';
    $sort = input_int('sort_order');
    $id = input_int('id');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors['name'] = 'Name must be between 2 and 100 characters.';
    }
    if (!preg_match('/^fa-[a-z0-9-]{1,50}$/', $icon)) {
        $errors['icon'] = 'Use a Font Awesome icon name such as fa-wifi.';
    }
    if (mb_strlen($description) > 255) {
        $errors['description'] = 'Description must be 255 characters or fewer.';
    }
    if (DB::value('SELECT id FROM content_categories WHERE slug = ? AND id <> ?', [$slug, $id])) {
        $errors['slug'] = 'This slug is already used by another category.';
    }
    if (!$errors) {
        $data = ['name' => $name, 'slug' => $slug, 'description' => $description ?: null, 'icon' => $icon, 'sort_order' => $sort];
        if ($id) {
            DB::update('content_categories', $data, 'id = ?', [$id]);
            AuditLog::record('category_changed', 'Updated category: ' . $name);
            flash('success', 'Category updated.');
        } else {
            DB::insert('content_categories', $data);
            AuditLog::record('category_changed', 'Created category: ' . $name);
            flash('success', 'Category created.');
        }
        redirect('admin/categories.php');
    }
    $editing = ['id' => $id, 'name' => $name, 'slug' => $slug, 'description' => $description, 'icon' => $icon, 'sort_order' => $sort];
}

$categories = DB::all(
    "SELECT c.*,
        (SELECT COUNT(*) FROM faq_entries f WHERE f.category_id = c.id) AS faqs,
        (SELECT COUNT(*) FROM kb_articles a WHERE a.category_id = c.id) AS articles,
        (SELECT COUNT(*) FROM tickets t WHERE t.category_id = c.id) AS tickets
     FROM content_categories c ORDER BY c.sort_order, c.name"
);

$pageTitle = 'Categories';
$pageSubtitle = 'Shared by the FAQ, the knowledge base and support tickets.';
$activeNav = 'categories';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="ns-card">
            <div class="table-responsive">
                <table class="table ns-table align-middle">
                    <thead><tr><th>Order</th><th>Category</th><th>FAQs</th><th>Articles</th><th>Tickets</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr class="<?= $cat['is_active'] ? '' : 'ns-row-muted' ?>">
                            <td><?= (int) $cat['sort_order'] ?></td>
                            <td><div class="d-flex align-items-center gap-2"><span class="ns-article-icon"><i class="fa-solid <?= e($cat['icon']) ?>"></i></span><div><strong><?= e($cat['name']) ?></strong><br><small class="text-muted"><?= e((string) $cat['description']) ?></small></div></div></td>
                            <td><?= (int) $cat['faqs'] ?></td>
                            <td><?= (int) $cat['articles'] ?></td>
                            <td><?= (int) $cat['tickets'] ?></td>
                            <td><?= badge('content', $cat['is_active'] ? 'active' : 'inactive') ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm ns-btn-ghost" href="?edit=<?= (int) $cat['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $cat['id'] ?>"><button class="btn btn-sm ns-btn-ghost" type="submit" title="<?= $cat['is_active'] ? 'Hide' : 'Show' ?>"><i class="fa-solid <?= $cat['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
                                <form method="post" class="d-inline" data-confirm="Delete “<?= e($cat['name']) ?>”? Linked content will become uncategorised."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $cat['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="ns-card">
            <div class="ns-card-head"><h2><?= !empty($editing['id']) ? 'Edit category' : 'Add category' ?></h2><?php if (!empty($editing['id'])): ?><a href="<?= e(url('admin/categories.php')) ?>">Cancel</a><?php endif; ?></div>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                <?php foreach ([
                    'name' => ['Name', 'text', ''],
                    'slug' => ['Slug', 'text', 'generated from the name'],
                    'description' => ['Description', 'text', ''],
                    'icon' => ['Icon (Font Awesome)', 'text', 'fa-wifi'],
                    'sort_order' => ['Display order', 'number', ''],
                ] as $field => [$label, $type, $placeholder]): ?>
                    <div class="mb-3">
                        <label class="form-label small" for="<?= e($field) ?>"><?= e($label) ?></label>
                        <input class="form-control <?= isset($errors[$field]) ? 'is-invalid' : '' ?>" type="<?= e($type) ?>" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e((string) ($editing[$field] ?? '')) ?>" placeholder="<?= e($placeholder) ?>">
                        <?php if (isset($errors[$field])): ?><div class="invalid-feedback"><?= e($errors[$field]) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button class="btn ns-btn-primary w-100" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save category</button>
            </form>
        </div>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
