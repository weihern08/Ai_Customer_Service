<?php
require __DIR__ . '/includes/bootstrap.php';

$user = Auth::requireLogin();
$id = input_int('id');
$ticket = $id ? TicketService::find($id) : null;

// Staff manage tickets in the admin console
if ($ticket && Auth::can('tickets.view') && (int) $ticket['customer_id'] !== (int) $user['id']) {
    redirect('admin/ticket.php?id=' . $id);
}
if (!Auth::can('portal.tickets')) {
    abort(403);
}
if (!$ticket || (int) $ticket['customer_id'] !== (int) $user['id']) {
    abort(404);
}

$errors = [];
if (post_too_large()) {
    flash('danger', __('upload.too_large', ['name' => '', 'mb' => FileUploader::maxBytes() / 1048576]));
    redirect('ticket_view.php?id=' . $id);
}
if (is_post()) {
    require_csrf();
    if ($ticket['status'] === 'closed') {
        flash('warning', __('ticket.closed_no_reply'));
        redirect('ticket_view.php?id=' . $id);
    }
    $body = trim((string) input('body'));
    if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
        $errors['body'] = __('validation.reply');
    }
    $files = FileUploader::collect('attachments');
    if ($uploadErrors = FileUploader::validate($files)) {
        $errors['attachments'] = implode(' ', $uploadErrors);
    }
    if (!$errors) {
        TicketService::addReply($ticket, $user, $body, false, $files);
        flash('success', __('ticket.reply_sent'));
        redirect('ticket_view.php?id=' . $id . '#latest');
    }
    remember_input(['body' => $body]);
}

$replies = TicketService::replies($id, false);
$files = TicketService::filesByReply($id);
$steps = ['open', 'in_progress', 'resolved', 'closed'];
$currentStep = array_search($ticket['status'] === 'pending' ? 'in_progress' : $ticket['status'], $steps, true);

$pageTitle = $ticket['ticket_no'];
$activeNav = 'tickets';
require __DIR__ . '/includes/layout/portal_header.php';

$renderFiles = static function (array $list): void {
    if (!$list) {
        return;
    }
    echo '<div class="ns-attachments">';
    foreach ($list as $file) {
        $icon = str_starts_with($file['mime_type'], 'image/') ? 'fa-file-image' : ($file['mime_type'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-lines');
        echo '<a class="ns-attachment" href="' . e(url('download.php?id=' . $file['id'])) . '"><i class="fa-solid ' . $icon . '"></i><span>'
            . e($file['original_name']) . '</span><small>' . e(format_bytes((int) $file['size_bytes'])) . '</small></a>';
    }
    echo '</div>';
};
?>
<section class="container-xl ns-section pt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb ns-breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('ticket.php')) ?>"><?= e(__('ticket.title')) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($ticket['ticket_no']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="ns-panel mb-4">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <span class="ns-ticket-no"><?= e($ticket['ticket_no']) ?></span>
                    <div class="d-flex gap-2"><?= badge('priority', $ticket['priority']) ?><?= badge('status', $ticket['status']) ?></div>
                </div>
                <h1 class="h3 mb-3"><?= e($ticket['subject']) ?></h1>
                <ol class="ns-progress" aria-label="<?= e(__('ticket.progress')) ?>">
                    <?php foreach ($steps as $i => $step): ?>
                        <li class="<?= $currentStep !== false && $i <= $currentStep ? 'done' : '' ?> <?= $i === $currentStep ? 'current' : '' ?>">
                            <span><?= $currentStep !== false && $i < $currentStep ? '<i class="fa-solid fa-check"></i>' : $i + 1 ?></span><?= e(__('status.' . $step)) ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($ticket['status'] === 'pending'): ?>
                    <div class="alert alert-warning small mb-0 mt-3"><i class="fa-solid fa-hourglass-half me-2"></i><?= e(__('ticket.pending_note')) ?></div>
                <?php elseif ($ticket['status'] === 'resolved'): ?>
                    <div class="alert alert-success small mb-0 mt-3"><i class="fa-solid fa-circle-check me-2"></i><?= e(__('ticket.resolved_note')) ?></div>
                <?php endif; ?>
            </div>

            <div class="ns-thread">
                <div class="ns-thread-item ns-thread-customer">
                    <span class="ns-avatar ns-avatar-sm"><?= e(initials($ticket['customer_name'])) ?></span>
                    <div class="ns-thread-body">
                        <div class="ns-thread-head"><strong><?= e($ticket['customer_name']) ?></strong><small><?= e(format_dt($ticket['created_at'])) ?></small></div>
                        <div class="ns-thread-text"><?= nl2br_e($ticket['description']) ?></div>
                        <?php $renderFiles($files[0] ?? []); ?>
                    </div>
                </div>
                <?php foreach ($replies as $i => $reply):
                    $isStaff = $reply['author_role'] !== 'customer'; ?>
                    <div class="ns-thread-item <?= $isStaff ? 'ns-thread-staff' : 'ns-thread-customer' ?>" <?= $i === count($replies) - 1 ? 'id="latest"' : '' ?>>
                        <span class="ns-avatar ns-avatar-sm <?= $isStaff ? 'ns-avatar-staff' : '' ?>"><?= $isStaff ? '<i class="fa-solid fa-headset"></i>' : e(initials((string) $reply['author_name'])) ?></span>
                        <div class="ns-thread-body">
                            <div class="ns-thread-head">
                                <strong><?= e($reply['author_name'] ?? __('ticket.deleted_user')) ?></strong>
                                <?php if ($isStaff): ?><span class="ns-badge ns-badge-staff"><?= e(__('ticket.staff_badge')) ?></span><?php endif; ?>
                                <small><?= e(format_dt($reply['created_at'])) ?></small>
                            </div>
                            <div class="ns-thread-text"><?= nl2br_e($reply['body']) ?></div>
                            <?php $renderFiles($files[(int) $reply['id']] ?? []); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($ticket['status'] !== 'closed'): ?>
                <div class="ns-panel mt-4">
                    <div class="ns-panel-head"><h2><i class="fa-solid fa-reply"></i><?= e(__('ticket.add_reply')) ?></h2></div>
                    <form method="post" enctype="multipart/form-data" novalidate data-ns-submit-once>
                        <?= csrf_field() ?>
                        <label class="visually-hidden" for="body"><?= e(__('ticket.add_reply')) ?></label>
                        <textarea class="form-control mb-3 <?= isset($errors['body']) ? 'is-invalid' : '' ?>" id="body" name="body" rows="4" maxlength="5000" required placeholder="<?= e(__('ticket.reply_placeholder')) ?>"><?= e(old('body')) ?></textarea>
                        <?php if (isset($errors['body'])): ?><div class="invalid-feedback d-block mb-2"><?= e($errors['body']) ?></div><?php endif; ?>
                        <?php $uploadError = $errors['attachments'] ?? null; require __DIR__ . '/includes/layout/upload_field.php'; ?>
                        <div class="text-end mt-3">
                            <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-paper-plane me-2"></i><?= e(__('ticket.send_reply')) ?></button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="ns-empty-state ns-empty-state-sm mt-4">
                    <i class="fa-solid fa-lock"></i>
                    <p class="mb-2"><?= e(__('ticket.closed_no_reply')) ?></p>
                    <a class="btn ns-btn-primary btn-sm" href="<?= e(url('ticket.php?new=1')) ?>"><?= e(__('ticket.new')) ?></a>
                </div>
            <?php endif; ?>
        </div>
        <aside class="col-lg-4">
            <div class="ns-panel">
                <div class="ns-panel-head"><h2><i class="fa-solid fa-circle-info"></i><?= e(__('ticket.details')) ?></h2></div>
                <dl class="ns-dl">
                    <dt><?= e(__('field.status')) ?></dt><dd><?= badge('status', $ticket['status']) ?></dd>
                    <dt><?= e(__('field.priority')) ?></dt><dd><?= badge('priority', $ticket['priority']) ?></dd>
                    <dt><?= e(__('field.category')) ?></dt><dd><?= e($ticket['category_name'] ?? '—') ?></dd>
                    <dt><?= e(__('ticket.assigned')) ?></dt><dd><?= e($ticket['assignee_name'] ?? __('ticket.unassigned')) ?></dd>
                    <dt><?= e(__('ticket.created_at')) ?></dt><dd><?= e(format_dt($ticket['created_at'])) ?></dd>
                    <dt><?= e(__('ticket.updated_at')) ?></dt><dd><?= e(format_dt($ticket['updated_at'])) ?></dd>
                </dl>
                <?php if ($ticket['chat_session_id']): ?>
                    <a class="btn ns-btn-ghost w-100 mt-2" href="<?= e(url('chat.php?session=' . $ticket['chat_session_id'])) ?>"><i class="fa-solid fa-comments me-2"></i><?= e(__('ticket.view_chat')) ?></a>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</section>
<?php clear_old(); require __DIR__ . '/includes/layout/portal_footer.php'; ?>
