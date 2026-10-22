<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('kb.manage');
$id = input_int('id');
$article = $id ? DB::row('SELECT * FROM kb_articles WHERE id = ?', [$id]) : null;
if ($id && !$article) {
    abort(404);
}
$categories = ContentService::categories(false);
$errors = [];

$values = [
    'title'       => $article['title'] ?? '',
    'slug'        => $article['slug'] ?? '',
    'summary'     => $article['summary'] ?? '',
    'body'        => $article['body'] ?? '',
    'tags'        => $article['tags'] ?? '',
    'category_id' => (string) ($article['category_id'] ?? ''),
    'status'      => $article['status'] ?? 'draft',
];

if (is_post()) {
    require_csrf();
    $values = [
        'title'       => trim((string) input('title')),
        'slug'        => trim((string) input('slug')),
        'summary'     => trim((string) input('summary')),
        'body'        => trim((string) input('body')),
        'tags'        => trim((string) input('tags')),
        'category_id' => (string) input_int('category_id'),
        'status'      => input('status') === 'published' ? 'published' : 'draft',
    ];
    $values['slug'] = slugify($values['slug'] !== '' ? $values['slug'] : $values['title']);

    if (mb_strlen($values['title']) < 5 || mb_strlen($values['title']) > 200) {
        $errors['title'] = 'The title must be between 5 and 200 characters.';
    }
    if (DB::value('SELECT id FROM kb_articles WHERE slug = ? AND id <> ?', [$values['slug'], $id])) {
        $errors['slug'] = 'Another article already uses this URL slug.';
    }
    if (mb_strlen($values['summary']) < 20 || mb_strlen($values['summary']) > 500) {
        $errors['summary'] = 'The summary must be between 20 and 500 characters.';
    }
    if (mb_strlen($values['body']) < 50 || mb_strlen($values['body']) > 60000) {
        $errors['body'] = 'The article body must be between 50 and 60,000 characters.';
    }
    if (mb_strlen($values['tags']) > 255) {
        $errors['tags'] = 'Tags must be 255 characters or fewer.';
    }
    $categoryId = (int) $values['category_id'];
    if ($categoryId && !DB::value('SELECT id FROM content_categories WHERE id = ?', [$categoryId])) {
        $errors['category_id'] = 'Please choose a valid category.';
    }

    if (!$errors) {
        $data = [
            'title'       => $values['title'],
            'slug'        => $values['slug'],
            'summary'     => $values['summary'],
            'body'        => $values['body'],
            'tags'        => $values['tags'] ?: null,
            'category_id' => $categoryId ?: null,
            'status'      => $values['status'],
        ];
        if ($values['status'] === 'published' && empty($article['published_at'])) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
        if ($article) {
            DB::update('kb_articles', $data, 'id = ?', [$id]);
            AuditLog::record('article_updated', 'Updated article: ' . str_limit($data['title'], 120));
            flash('success', 'Article saved.');
        } else {
            $id = DB::insert('kb_articles', $data + ['author_id' => (int) $user['id']]);
            AuditLog::record('article_created', 'Created knowledge article: ' . str_limit($data['title'], 120));
            flash('success', 'Article created.');
        }
        redirect('admin/article_form.php?id=' . $id);
    }
}

$pageTitle = $article ? 'Edit article' : 'New article';
$activeNav = 'articles';
if ($article) {
    $pageActions = '<a class="btn ns-btn-ghost" target="_blank" rel="noopener" href="' . e(url('article.php?slug=' . rawurlencode($article['slug']))) . '"><i class="fa-solid fa-eye me-2"></i>Preview</a>';
}
require dirname(__DIR__) . '/includes/layout/admin_header.php';

$err = static fn (string $f) => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
$cls = static fn (string $f) => isset($errors[$f]) ? ' is-invalid' : '';
?>
<form method="post" novalidate class="row g-4">
    <?= csrf_field() ?>
    <div class="col-xl-8">
        <div class="ns-card">
            <div class="mb-3">
                <label class="form-label" for="title">Title</label>
                <input class="form-control form-control-lg<?= $cls('title') ?>" id="title" name="title" maxlength="200" value="<?= e($values['title']) ?>" required>
                <?= $err('title') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="summary">Summary</label>
                <textarea class="form-control<?= $cls('summary') ?>" id="summary" name="summary" rows="2" maxlength="500" required><?= e($values['summary']) ?></textarea>
                <?= $err('summary') ?>
            </div>
            <div class="mb-0">
                <label class="form-label" for="body">Content</label>
                <textarea class="form-control ns-mono<?= $cls('body') ?>" id="body" name="body" rows="20" required><?= e($values['body']) ?></textarea>
                <?= $err('body') ?>
                <div class="form-text">Formatting: <code>## Heading</code>, <code>- bullet</code>, <code>1. step</code>, <code>**bold**</code>. Leave a blank line between paragraphs. HTML is not allowed.</div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="ns-card mb-4">
            <div class="mb-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="draft" <?= $values['status'] === 'draft' ? 'selected' : '' ?>>Draft (hidden)</option>
                    <option value="published" <?= $values['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="category_id">Category</label>
                <select class="form-select<?= $cls('category_id') ?>" id="category_id" name="category_id">
                    <option value="">Uncategorised</option>
                    <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>" <?= $values['category_id'] === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
                </select>
                <?= $err('category_id') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="tags">Tags</label>
                <input class="form-control<?= $cls('tags') ?>" id="tags" name="tags" maxlength="255" value="<?= e($values['tags']) ?>" placeholder="router, wifi, slow">
                <?= $err('tags') ?>
            </div>
            <div class="mb-4">
                <label class="form-label" for="slug">URL slug</label>
                <input class="form-control<?= $cls('slug') ?>" id="slug" name="slug" maxlength="180" value="<?= e($values['slug']) ?>" placeholder="generated from the title">
                <?= $err('slug') ?>
            </div>
            <div class="d-grid gap-2">
                <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save article</button>
                <a class="btn ns-btn-ghost" href="<?= e(url('admin/articles.php')) ?>">Back to list</a>
            </div>
        </div>
        <?php if ($article): ?>
            <div class="ns-card">
                <dl class="ns-dl mb-0">
                    <dt>Views</dt><dd><?= (int) $article['view_count'] ?></dd>
                    <dt>Created</dt><dd><?= e(format_dt($article['created_at'])) ?></dd>
                    <dt>Updated</dt><dd><?= e(format_dt($article['updated_at'])) ?></dd>
                    <dt>Published</dt><dd><?= e(format_dt($article['published_at'])) ?></dd>
                </dl>
            </div>
        <?php endif; ?>
    </div>
</form>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
