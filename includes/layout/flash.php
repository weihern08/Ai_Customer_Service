<?php
$nsFlashes = take_flashes();
$nsIcons = ['success' => 'fa-circle-check', 'danger' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info'];
if ($nsFlashes): ?>
    <div class="ns-flash-stack" role="status" aria-live="polite">
        <?php foreach ($nsFlashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show ns-flash" role="alert">
                <i class="fa-solid <?= e($nsIcons[$flash['type']] ?? 'fa-circle-info') ?> me-2"></i><?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= e(__('common.close')) ?>"></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
