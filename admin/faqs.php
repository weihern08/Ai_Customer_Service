<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('faq.manage');

if (is_post()) {
    require_csrf();
    $action = (string) input('action');
    $faq = DB::row('SELECT * FROM faq_entries WHERE id = ?', [input_int('id')]);
    if (!$faq) {
        flash('danger', 'FAQ not found.');
        redirect('admin/faqs.php');
    }
    if ($action === 'toggle') {
        DB::run('UPDATE faq_entries SET is_active = 1 - is_active WHERE id = ?', [(int) $faq['id']]);
        AuditLog::record('faq_updated', ($faq['is_active'] ? 'Deactivated' : 'Activated') . ' FAQ #' . $faq['id']);
        flash('success', $faq['is_active'] ? 'FAQ hidden from customers.' : 'FAQ is now visible to customers.');
    } elseif ($action === 'delete') {
        DB::run('DELETE FROM faq_entries WHERE id = ?', [(int) $faq['id']]);
        AuditLog::record('faq_deleted', 'Deleted FAQ: ' . str_limit($faq['question'], 150));
        flash('success', 'FAQ deleted.');
    } elseif ($action === 'move') {
        // Renumber the FAQs of this category, then swap the item with its neighbour
        $siblings = DB::all(
            'SELECT id FROM faq_entries WHERE category_id <=> ? ORDER BY sort_order, id',
            [$faq['category_id']]
        );
        $ids = array_map('intval', array_column($siblings, 'id'));
        $pos = array_search((int) $faq['id'], $ids, true);
        $target = input('dir') === 'up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && isset($ids[$target])) {
            [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
            DB::transaction(static function () use ($ids) {
                foreach ($ids as $order => $id) {
                    DB::run('UPDATE faq_entries SET sort_order = ? WHERE id = ?', [$order + 1, $id]);
                }
            });
            AuditLog::record('faq_updated', 'Reordered FAQ #' . $faq['id']);
        }
    }
    $returnQuery = (string) input('return_query');
    redirect('admin/faqs.php' . (preg_match('/^\?[\w%=&+.\-]*$/', $returnQuery) ? $returnQuery : ''));
}

$search = mb_substr(trim((string) input('q')), 0, 100);
$categoryId = input_int('category');
$state = (string) input('state');
[$where, $params] = ContentService::searchClause($search, ['f.question', 'f.answer', 'f.keywords']);
if ($categoryId) {
    $where .= ' AND f.category_id = ?';
    $params[] = $categoryId;
}
if ($state === 'active' || $state === 'inactive') {
    $where .= ' AND f.is_active = ?';
    $params[] = $state === 'active' ? 1 : 0;
}
$faqs = DB::all(
    "SELECT f.*, c.name AS category_name FROM faq_entries f
     LEFT JOIN content_categories c ON c.id = f.category_id
     WHERE $where ORDER BY c.sort_order, f.category_id, f.sort_order, f.id",
    $params
);
$categories = ContentService::categories(false);
$returnQuery = $_GET ? '?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'category', 'state']))) : '';

$pageTitle = 'FAQ';
$pageSubtitle = 'Short, verified answers. The AI assistant uses active FAQs as trusted knowledge.';
$activeNav = 'faq';
$pageActions = '<a class="btn ns-btn-primary" href="' . e(url('admin/faq_form.php')) . '"><i class="fa-solid fa-plus me-2"></i>New FAQ</a>';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-card mb-4">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-5"><label class="form-label small" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Question, answer or keyword"></div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="state">Visibility</label>
            <select class="form-select" id="state" name="state">
                <option value="">All</option>
                <option value="active" <?= $state === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $state === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i>Filter</button></div>
    </form>
</div>

<div class="ns-card">
    <div class="ns-card-head"><h2><?= count($faqs) ?> FAQ entries</h2><small class="text-muted">Use the arrows to reorder within a category</small></div>
    <?php if (!$faqs): ?>
        <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-circle-question"></i><p>No FAQs found.</p><a class="btn ns-btn-primary btn-sm" href="<?= e(url('admin/faq_form.php')) ?>">Create the first FAQ</a></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ns-table align-middle">
                <thead><tr><th style="width:70px">Order</th><th>Question</th><th>Category</th><th>Views</th><th>Status</th><th>Updated</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($faqs as $faq): ?>
                    <tr class="<?= $faq['is_active'] ? '' : 'ns-row-muted' ?>">
                        <td>
                            <div class="btn-group-vertical btn-group-sm">
                                <?php foreach (['up' => 'fa-chevron-up', 'down' => 'fa-chevron-down'] as $dir => $icon): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="<?= $dir ?>"><input type="hidden" name="id" value="<?= (int) $faq['id'] ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                                        <button class="btn btn-light btn-sm py-0" type="submit" aria-label="Move <?= $dir ?>"><i class="fa-solid <?= $icon ?>"></i></button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="ns-cell-wide"><strong><?= e($faq['question']) ?></strong><br><small class="text-muted"><?= e(str_limit($faq['answer'], 110)) ?></small></td>
                        <td class="small"><?= e($faq['category_name'] ?? 'Uncategorised') ?></td>
                        <td><?= (int) $faq['view_count'] ?></td>
                        <td><?= $faq['is_active'] ? badge('content', 'active') : badge('content', 'inactive') ?></td>
                        <td class="small text-nowrap"><?= e(format_dt($faq['updated_at'], 'd M Y')) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/faq_form.php?id=' . $faq['id'])) ?>" aria-label="Edit"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $faq['id'] ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                                <button class="btn btn-sm ns-btn-ghost" type="submit" title="<?= $faq['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="fa-solid <?= $faq['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                            </form>
                            <form method="post" class="d-inline" data-confirm="Delete this FAQ permanently?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $faq['id'] ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
