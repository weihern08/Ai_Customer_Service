<?php
require __DIR__ . '/includes/bootstrap.php';

$user = Auth::requirePermission('portal.tickets');
$userId = (int) $user['id'];
$categories = ContentService::categories();
$errors = [];

// Optional link to the AI conversation the ticket came from (must belong to this customer)
$chatId = input_int('chat');
$chatSession = $chatId ? ChatService::findForCustomer($chatId, $userId) : null;

if (post_too_large()) {
    flash('danger', __('upload.too_large', ['name' => '', 'mb' => FileUploader::maxBytes() / 1048576]));
    redirect('ticket.php?new=1');
}

if (is_post()) {
    require_csrf();
    $subject = trim((string) input('subject'));
    $description = trim((string) input('description'));
    $categoryId = input_int('category_id') ?: null;
    $priority = (string) input('priority', 'medium');
    remember_input($_POST);

    if (mb_strlen($subject) < 5 || mb_strlen($subject) > 200) {
        $errors['subject'] = __('validation.subject');
    }
    if (mb_strlen($description) < 15 || mb_strlen($description) > 5000) {
        $errors['description'] = __('validation.description');
    }
    if ($categoryId && !in_array($categoryId, array_map('intval', array_column($categories, 'id')), true)) {
        $errors['category_id'] = __('validation.category');
    }
    if (!in_array($priority, TicketService::PRIORITIES, true)) {
        $errors['priority'] = __('validation.priority');
    }
    $files = FileUploader::collect('attachments');
    if ($uploadErrors = FileUploader::validate($files)) {
        $errors['attachments'] = implode(' ', $uploadErrors);
    }

    if (!$errors) {
        $ticket = TicketService::create($user, [
            'subject'         => $subject,
            'description'     => $description,
            'category_id'     => $categoryId,
            'priority'        => $priority,
            'chat_session_id' => $chatSession['id'] ?? null,
        ], $files);
        clear_old();
        flash('success', __('ticket.created', ['no' => $ticket['ticket_no']]));
        redirect('ticket_view.php?id=' . $ticket['id']);
    }
}

$showForm = input('new') === '1' || is_post() || $chatSession;

// Ticket list with filters
$status = (string) input('status');
$search = mb_substr(trim((string) input('q')), 0, 100);
$where = 't.customer_id = ?';
$params = [$userId];
if (in_array($status, TicketService::STATUSES, true)) {
    $where .= ' AND t.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where .= ' AND (t.ticket_no LIKE ? OR t.subject LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like);
}
$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM tickets t WHERE $where", $params), 10);
$tickets = DB::all(
    "SELECT t.*, c.name AS category_name, a.full_name AS assignee_name,
            (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.is_internal = 0) AS reply_count
     FROM tickets t
     LEFT JOIN content_categories c ON c.id = t.category_id
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE $where ORDER BY t.updated_at DESC" . $pager->limitSql(),
    $params
);
$counts = [];
foreach (DB::all('SELECT status, COUNT(*) AS n FROM tickets WHERE customer_id = ? GROUP BY status', [$userId]) as $row) {
    $counts[$row['status']] = (int) $row['n'];
}

$prefillSubject = $chatSession ? __('ticket.from_chat_subject', ['title' => str_limit((string) $chatSession['title'], 120)]) : '';
$pageTitle = __('ticket.title');
$activeNav = 'tickets';
require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="ns-page-hero ns-page-hero-sm">
    <span class="ns-hero-art" aria-hidden="true"><i class="fa-solid fa-ticket"></i></span>
    <div class="container-xl d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <span class="ns-eyebrow ns-eyebrow-soft"><i class="fa-solid fa-ticket"></i> <?= e(__('ticket.support')) ?></span>
            <h1><?= e(__('ticket.title')) ?></h1>
            <p class="mb-0"><?= e(__('ticket.lead')) ?></p>
        </div>
        <?php if (!$showForm): ?>
            <a class="btn ns-btn-cta" href="<?= e(url('ticket.php?new=1')) ?>"><i class="fa-solid fa-plus me-2"></i><?= e(__('ticket.new')) ?></a>
        <?php endif; ?>
    </div>
</section>

<section class="container-xl ns-section pt-4">
    <?php if ($showForm): ?>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="ns-panel">
                    <div class="ns-panel-head"><h2><i class="fa-solid fa-pen-to-square"></i><?= e(__('ticket.new')) ?></h2></div>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?= e(__('validation.fix_errors')) ?></div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" novalidate data-ns-submit-once>
                        <?= csrf_field() ?>
                        <?php if ($chatSession): ?>
                            <input type="hidden" name="chat" value="<?= (int) $chatSession['id'] ?>">
                            <div class="alert alert-info small"><i class="fa-solid fa-link me-2"></i><?= e(__('ticket.linked_chat')) ?></div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label" for="subject"><?= e(__('field.subject')) ?></label>
                            <input class="form-control <?= isset($errors['subject']) ? 'is-invalid' : '' ?>" id="subject" name="subject" maxlength="200" required value="<?= e(old('subject', $prefillSubject)) ?>">
                            <?php if (isset($errors['subject'])): ?><div class="invalid-feedback"><?= e($errors['subject']) ?></div><?php endif; ?>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label class="form-label" for="category_id"><?= e(__('field.category')) ?></label>
                                <select class="form-select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" id="category_id" name="category_id">
                                    <option value=""><?= e(__('ticket.choose_category')) ?></option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= (int) $cat['id'] ?>" <?= old('category_id') === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="priority"><?= e(__('field.priority')) ?></label>
                                <select class="form-select" id="priority" name="priority">
                                    <?php foreach (TicketService::PRIORITIES as $p): ?>
                                        <option value="<?= e($p) ?>" <?= old('priority', 'medium') === $p ? 'selected' : '' ?>><?= e(__('priority.' . $p)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="description"><?= e(__('field.description')) ?></label>
                            <textarea class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>" id="description" name="description" rows="7" maxlength="5000" required placeholder="<?= e(__('ticket.description_hint')) ?>"><?= e(old('description')) ?></textarea>
                            <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= e($errors['description']) ?></div><?php endif; ?>
                        </div>
                        <?php $uploadError = $errors['attachments'] ?? null; require __DIR__ . '/includes/layout/upload_field.php'; ?>
                        <div class="d-flex gap-2 justify-content-end mt-4">
                            <a class="btn ns-btn-ghost" href="<?= e(url('ticket.php')) ?>"><?= e(__('common.cancel')) ?></a>
                            <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-paper-plane me-2"></i><?= e(__('ticket.submit')) ?></button>
                        </div>
                    </form>
                </div>
            </div>
            <aside class="col-lg-4 ns-sticky-aside">
                <div class="ns-panel">
                    <div class="ns-panel-head"><h2><i class="fa-solid fa-lightbulb"></i><?= e(__('ticket.tips_title')) ?></h2></div>
                    <ul class="ns-check-list ns-check-list-dark">
                        <li><?= e(__('ticket.tip1')) ?></li>
                        <li><?= e(__('ticket.tip2')) ?></li>
                        <li><?= e(__('ticket.tip3')) ?></li>
                    </ul>
                    <hr>
                    <p class="small text-muted mb-2"><?= e(__('ticket.try_ai')) ?></p>
                    <a class="btn ns-btn-ghost w-100" href="<?= e(url('chat.php')) ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i><?= e(__('common.ask_nova', ['name' => Settings::get('ai_assistant_name')])) ?></a>
                </div>
            </aside>
        </div>
    <?php else: ?>
        <div class="ns-pill-row mb-3">
            <a class="ns-pill <?= $status === '' ? 'active' : '' ?>" href="<?= e(url('ticket.php')) ?>"><?= e(__('common.all')) ?> <span><?= array_sum($counts) ?></span></a>
            <?php foreach (TicketService::STATUSES as $s): ?>
                <a class="ns-pill <?= $status === $s ? 'active' : '' ?>" href="<?= e(url('ticket.php?status=' . $s)) ?>"><?= e(__('status.' . $s)) ?> <span><?= $counts[$s] ?? 0 ?></span></a>
            <?php endforeach; ?>
        </div>
        <form class="ns-inline-search mb-4" method="get" role="search">
            <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            <i class="fa-solid fa-magnifying-glass"></i>
            <label class="visually-hidden" for="ticketSearch"><?= e(__('ticket.search')) ?></label>
            <input id="ticketSearch" class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(__('ticket.search')) ?>">
        </form>

        <?php if (!$tickets): ?>
            <div class="ns-empty-state">
                <i class="fa-regular fa-envelope-open"></i>
                <h2><?= e(__('ticket.empty_title')) ?></h2>
                <p><?= e(__('ticket.empty_text')) ?></p>
                <a class="btn ns-btn-primary" href="<?= e(url('ticket.php?new=1')) ?>"><i class="fa-solid fa-plus me-2"></i><?= e(__('ticket.new')) ?></a>
            </div>
        <?php else: ?>
            <div class="ns-ticket-list">
                <?php foreach ($tickets as $t): ?>
                    <a class="ns-ticket-row ns-ticket-row-<?= e($t['status']) ?>" href="<?= e(url('ticket_view.php?id=' . $t['id'])) ?>">
                        <div class="ns-ticket-main">
                            <span class="ns-ticket-no"><?= e($t['ticket_no']) ?></span>
                            <strong><?= e($t['subject']) ?></strong>
                            <small class="text-muted"><?= e($t['category_name'] ?? __('faq.general')) ?> · <?= e(__('ticket.updated_ago', ['time' => time_ago($t['updated_at'])])) ?></small>
                        </div>
                        <div class="ns-ticket-side">
                            <?= badge('priority', $t['priority']) ?>
                            <?= badge('status', $t['status']) ?>
                            <span class="ns-reply-count" title="<?= e(__('ticket.replies')) ?>"><i class="fa-regular fa-comment"></i> <?= (int) $t['reply_count'] ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="mt-4"><?= $pager->render() ?></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php clear_old(); require __DIR__ . '/includes/layout/portal_footer.php'; ?>
