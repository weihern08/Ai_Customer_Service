<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('kb.manage');

if (is_post()) {
    require_csrf();
    $article = DB::row('SELECT * FROM kb_articles WHERE id = ?', [input_int('id')]);
    if (!$article) {
        flash('danger', 'Article not found.');
        redirect('admin/articles.php');
    }
    $action = (string) input('action');
    if ($action === 'toggle') {
        $publish = $article['status'] !== 'published';
        DB::run(
            'UPDATE kb_articles SET status = ?, published_at = COALESCE(published_at, ?) WHERE id = ?',
            [$publish ? 'published' : 'draft', $publish ? date('Y-m-d H:i:s') : null, (int) $article['id']]
        );
        AuditLog::record('article_updated', ($publish ? 'Published' : 'Unpublished') . ' article: ' . str_limit($article['title'], 120));
        flash('success', $publish ? 'Article published.' : 'Article moved back to draft.');
    } elseif ($action === 'delete') {
        DB::run('DELETE FROM kb_articles WHERE id = ?', [(int) $article['id']]);
        AuditLog::record('article_deleted', 'Deleted article: ' . str_limit($article['title'], 120));
        flash('success', 'Article deleted.');
    }
    redirect('admin/articles.php');
}

$search = mb_substr(trim((string) input('q')), 0, 100);
$status = (string) input('status');
$categoryId = input_int('category');
[$where, $params] = ContentService::searchClause($search, ['a.title', 'a.summary', 'a.body', 'a.tags']);
if (in_array($status, ['draft', 'published'], true)) {
    $where .= ' AND a.status = ?';
    $params[] = $status;
}
if ($categoryId) {
    $where .= ' AND a.category_id = ?';
    $params[] = $categoryId;
}
$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM kb_articles a WHERE $where", $params), 15);
$articles = DB::all(
    "SELECT a.id, a.title, a.slug, a.summary, a.status, a.view_count, a.updated_at, a.tags,
            c.name AS category_name, u.full_name AS author_name
     FROM kb_articles a
     LEFT JOIN content_categories c ON c.id = a.category_id
     LEFT JOIN users u ON u.id = a.author_id
     WHERE $where ORDER BY a.updated_at DESC" . $pager->limitSql(),
    $params
);
$categories = ContentService::categories(false);

$pageTitle = 'Knowledge Base';
$pageSubtitle = 'Long-form help articles. Published articles are searchable by customers and used by the AI.';
$activeNav = 'articles';
$pageActions = '<a class="btn ns-btn-primary" href="' . e(url('admin/article_form.php')) . '"><i class="fa-solid fa-plus me-2"></i>New article</a>';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-card mb-4">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-5"><label class="form-label small" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Title, content or tag"></div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i>Filter</button></div>
    </form>
</div>

<div class="ns-card">
    <div class="ns-card-head"><h2><?= (int) $pager->total ?> article<?= $pager->total === 1 ? '' : 's' ?></h2></div>
    <?php if (!$articles): ?>
        <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-file-lines"></i><p>No articles found.</p><a class="btn ns-btn-primary btn-sm" href="<?= e(url('admin/article_form.php')) ?>">Write an article</a></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ns-table align-middle">
                <thead><tr><th>Title</th><th>Category</th><th>Author</th><th>Views</th><th>Status</th><th>Updated</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($articles as $a): ?>
                    <tr>
                        <td class="ns-cell-wide"><strong><?= e($a['title']) ?></strong><br><small class="text-muted"><?= e(str_limit($a['summary'], 100)) ?></small></td>
                        <td class="small"><?= e($a['category_name'] ?? '—') ?></td>
                        <td class="small"><?= e($a['author_name'] ?? '—') ?></td>
                        <td><?= (int) $a['view_count'] ?></td>
                        <td><?= badge('content', $a['status']) ?></td>
                        <td class="small text-nowrap"><?= e(format_dt($a['updated_at'], 'd M Y')) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm ns-btn-ghost" href="<?= e(url('article.php?slug=' . rawurlencode($a['slug']))) ?>" target="_blank" rel="noopener" title="Preview"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                            <a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/article_form.php?id=' . $a['id'])) ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button class="btn btn-sm ns-btn-ghost" type="submit" title="<?= $a['status'] === 'published' ? 'Unpublish' : 'Publish' ?>"><i class="fa-solid <?= $a['status'] === 'published' ? 'fa-eye-slash' : 'fa-upload' ?>"></i></button>
                            </form>
                            <form method="post" class="d-inline" data-confirm="Delete this article permanently?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $pager->render() ?>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
