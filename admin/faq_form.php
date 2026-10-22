<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('faq.manage');
$id = input_int('id');
$faq = $id ? DB::row('SELECT * FROM faq_entries WHERE id = ?', [$id]) : null;
if ($id && !$faq) {
    abort(404);
}
$categories = ContentService::categories(false);
$errors = [];

$values = [
    'question'    => $faq['question'] ?? '',
    'answer'      => $faq['answer'] ?? '',
    'keywords'    => $faq['keywords'] ?? '',
    'category_id' => (string) ($faq['category_id'] ?? ''),
    'sort_order'  => (string) ($faq['sort_order'] ?? (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM faq_entries')),
    'is_active'   => (string) ($faq['is_active'] ?? '1'),
];

if (is_post()) {
    require_csrf();
    $values = [
        'question'    => trim((string) input('question')),
        'answer'      => trim((string) input('answer')),
        'keywords'    => trim((string) input('keywords')),
        'category_id' => (string) input_int('category_id'),
        'sort_order'  => (string) input_int('sort_order'),
        'is_active'   => input('is_active') === '1' ? '1' : '0',
    ];
    if (mb_strlen($values['question']) < 8 || mb_strlen($values['question']) > 255) {
        $errors['question'] = 'The question must be between 8 and 255 characters.';
    }
    if (mb_strlen($values['answer']) < 15 || mb_strlen($values['answer']) > 5000) {
        $errors['answer'] = 'The answer must be between 15 and 5,000 characters.';
    }
    if (mb_strlen($values['keywords']) > 255) {
        $errors['keywords'] = 'Keywords must be 255 characters or fewer.';
    }
    $categoryId = (int) $values['category_id'];
    if ($categoryId && !DB::value('SELECT id FROM content_categories WHERE id = ?', [$categoryId])) {
        $errors['category_id'] = 'Please choose a valid category.';
    }

    if (!$errors) {
        $data = [
            'question'    => $values['question'],
            'answer'      => $values['answer'],
            'keywords'    => $values['keywords'] ?: null,
            'category_id' => $categoryId ?: null,
            'sort_order'  => (int) $values['sort_order'],
            'is_active'   => (int) $values['is_active'],
        ];
        if ($faq) {
            DB::update('faq_entries', $data, 'id = ?', [$id]);
            AuditLog::record('faq_updated', 'Updated FAQ #' . $id . ': ' . str_limit($data['question'], 120));
            flash('success', 'FAQ updated.');
        } else {
            $id = DB::insert('faq_entries', $data + ['created_by' => (int) $user['id']]);
            AuditLog::record('faq_created', 'Created FAQ #' . $id . ': ' . str_limit($data['question'], 120));
            flash('success', 'FAQ created.');
        }
        redirect('admin/faqs.php');
    }
}

$pageTitle = $faq ? 'Edit FAQ' : 'New FAQ';
$activeNav = 'faq';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<form method="post" novalidate class="row g-4">
    <?= csrf_field() ?>
    <div class="col-xl-8">
        <div class="ns-card">
            <div class="mb-3">
                <label class="form-label" for="question">Question</label>
                <input class="form-control <?= isset($errors['question']) ? 'is-invalid' : '' ?>" id="question" name="question" maxlength="255" value="<?= e($values['question']) ?>" required>
                <?php if (isset($errors['question'])): ?><div class="invalid-feedback"><?= e($errors['question']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="answer">Answer</label>
                <textarea class="form-control ns-mono <?= isset($errors['answer']) ? 'is-invalid' : '' ?>" id="answer" name="answer" rows="10" maxlength="5000" required><?= e($values['answer']) ?></textarea>
                <?php if (isset($errors['answer'])): ?><div class="invalid-feedback"><?= e($errors['answer']) ?></div><?php endif; ?>
                <div class="form-text">Formatting: start lines with <code>- </code> for bullets or <code>1. </code> for steps. Wrap text in <code>**double asterisks**</code> for bold.</div>
            </div>
            <div class="mb-0">
                <label class="form-label" for="keywords">Search keywords</label>
                <input class="form-control <?= isset($errors['keywords']) ? 'is-invalid' : '' ?>" id="keywords" name="keywords" maxlength="255" value="<?= e($values['keywords']) ?>" placeholder="refund, money back, overcharged">
                <?php if (isset($errors['keywords'])): ?><div class="invalid-feedback"><?= e($errors['keywords']) ?></div><?php endif; ?>
                <div class="form-text">Comma-separated words customers might use. They improve AI retrieval and search.</div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="ns-card">
            <div class="mb-3">
                <label class="form-label" for="category_id">Category</label>
                <select class="form-select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" id="category_id" name="category_id">
                    <option value="">Uncategorised</option>
                    <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>" <?= $values['category_id'] === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="sort_order">Display order</label>
                <input class="form-control" type="number" id="sort_order" name="sort_order" min="0" max="9999" value="<?= e($values['sort_order']) ?>">
            </div>
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $values['is_active'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Active (visible to customers and the AI)</label>
            </div>
            <div class="d-grid gap-2">
                <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save FAQ</button>
                <a class="btn ns-btn-ghost" href="<?= e(url('admin/faqs.php')) ?>">Cancel</a>
            </div>
            <?php if ($faq): ?>
                <hr><p class="small text-muted mb-0">Views: <?= (int) $faq['view_count'] ?> · Created <?= e(format_dt($faq['created_at'])) ?> · Updated <?= e(format_dt($faq['updated_at'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
</form>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
