<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('tickets.view');
$id = input_int('id');
$ticket = $id ? TicketService::find($id) : null;
if (!$ticket || !TicketService::canView($ticket, $user)) {
    abort(404);
}

if (post_too_large()) {
    flash('danger', 'The attachment is larger than the server upload limit.');
    redirect('admin/ticket.php?id=' . $id);
}

$errors = [];
if (is_post()) {
    require_csrf();
    if (!Auth::can('tickets.manage')) {
        abort(403);
    }
    $form = (string) input('form');

    if ($form === 'workflow') {
        $assignTo = Auth::can('tickets.assign') ? (input_int('assigned_to') ?: null) : ($ticket['assigned_to'] !== null ? (int) $ticket['assigned_to'] : null);
        if ($assignTo && !in_array($assignTo, array_map('intval', array_column(TicketService::staffMembers(), 'id')), true)) {
            $assignTo = $ticket['assigned_to'] !== null ? (int) $ticket['assigned_to'] : null;
        }
        $changes = TicketService::updateWorkflow($ticket, $user, (string) input('status'), (string) input('priority'), $assignTo);
        flash($changes ? 'success' : 'info', $changes ? 'Ticket updated: ' . implode(', ', $changes) . '.' : 'No changes to save.');
        // Support staff lose access once a ticket is assigned to a colleague
        redirect(TicketService::canView(TicketService::find($id), $user) ? 'admin/ticket.php?id=' . $id : 'admin/tickets.php');
    }

    if ($form === 'reply') {
        $body = trim((string) input('body'));
        $internal = input('is_internal') === '1';
        if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
            $errors['body'] = 'Please write a reply between 2 and 5,000 characters.';
        }
        $files = FileUploader::collect('attachments');
        if ($uploadErrors = FileUploader::validate($files)) {
            $errors['attachments'] = implode(' ', $uploadErrors);
        }
        if (!$errors) {
            TicketService::addReply($ticket, $user, $body, $internal, $files);
            $newStatus = (string) input('then_status');
            if (in_array($newStatus, TicketService::STATUSES, true) && $newStatus !== $ticket['status']) {
                TicketService::updateWorkflow(TicketService::find($id), $user, $newStatus, $ticket['priority'], $ticket['assigned_to'] !== null ? (int) $ticket['assigned_to'] : null);
            }
            flash('success', $internal ? 'Internal note added (not visible to the customer).' : 'Reply sent to the customer.');
            redirect('admin/ticket.php?id=' . $id . '#latest');
        }
        remember_input(['body' => $body]);
    }
}

$replies = TicketService::replies($id, true);
$files = TicketService::filesByReply($id);
$staff = TicketService::staffMembers();
$otherTickets = DB::all(
    'SELECT id, ticket_no, subject, status FROM tickets WHERE customer_id = ? AND id <> ? ORDER BY id DESC LIMIT 5',
    [(int) $ticket['customer_id'], $id]
);

$pageTitle = $ticket['ticket_no'];
$pageSubtitle = $ticket['subject'];
$activeNav = 'tickets';
require dirname(__DIR__) . '/includes/layout/admin_header.php';

$renderFiles = static function (array $list): void {
    if (!$list) {
        return;
    }
    echo '<div class="ns-attachments">';
    foreach ($list as $file) {
        echo '<a class="ns-attachment" href="' . e(url('download.php?id=' . $file['id'])) . '"><i class="fa-solid fa-paperclip"></i><span>'
            . e($file['original_name']) . '</span><small>' . e(format_bytes((int) $file['size_bytes'])) . '</small></a>';
    }
    echo '</div>';
};
?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="ns-thread">
            <div class="ns-thread-item ns-thread-customer">
                <span class="ns-avatar ns-avatar-sm"><?= e(initials($ticket['customer_name'])) ?></span>
                <div class="ns-thread-body">
                    <div class="ns-thread-head"><strong><?= e($ticket['customer_name']) ?></strong><span class="ns-badge ns-badge-muted">Original request</span><small><?= e(format_dt($ticket['created_at'])) ?></small></div>
                    <div class="ns-thread-text"><?= nl2br_e($ticket['description']) ?></div>
                    <?php $renderFiles($files[0] ?? []); ?>
                </div>
            </div>
            <?php foreach ($replies as $i => $reply):
                $isStaff = $reply['author_role'] !== 'customer';
                $class = $reply['is_internal'] ? 'ns-thread-internal' : ($isStaff ? 'ns-thread-staff' : 'ns-thread-customer'); ?>
                <div class="ns-thread-item <?= $class ?>" <?= $i === count($replies) - 1 ? 'id="latest"' : '' ?>>
                    <span class="ns-avatar ns-avatar-sm <?= $isStaff ? 'ns-avatar-staff' : '' ?>"><?= e(initials((string) ($reply['author_name'] ?? '?'))) ?></span>
                    <div class="ns-thread-body">
                        <div class="ns-thread-head">
                            <strong><?= e($reply['author_name'] ?? 'Deleted user') ?></strong>
                            <?php if ($reply['is_internal']): ?><span class="ns-badge ns-badge-internal"><i class="fa-solid fa-lock me-1"></i>Internal note</span>
                            <?php elseif ($isStaff): ?><span class="ns-badge ns-badge-staff">Staff</span><?php endif; ?>
                            <small><?= e(format_dt($reply['created_at'])) ?></small>
                        </div>
                        <div class="ns-thread-text"><?= nl2br_e($reply['body']) ?></div>
                        <?php $renderFiles($files[(int) $reply['id']] ?? []); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (Auth::can('tickets.manage')): ?>
            <div class="ns-card mt-4">
                <div class="ns-card-head"><h2>Respond</h2></div>
                <form method="post" enctype="multipart/form-data" novalidate data-ns-submit-once data-ns-reply-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="reply">
                    <div class="btn-group mb-3" role="group" aria-label="Reply type">
                        <input type="radio" class="btn-check" name="is_internal" id="typePublic" value="0" checked>
                        <label class="btn btn-outline-primary btn-sm" for="typePublic"><i class="fa-solid fa-reply me-1"></i>Reply to customer</label>
                        <input type="radio" class="btn-check" name="is_internal" id="typeInternal" value="1">
                        <label class="btn btn-outline-warning btn-sm" for="typeInternal"><i class="fa-solid fa-lock me-1"></i>Internal note</label>
                    </div>
                    <label class="visually-hidden" for="body">Message</label>
                    <textarea class="form-control mb-2 <?= isset($errors['body']) ? 'is-invalid' : '' ?>" id="body" name="body" rows="5" maxlength="5000" required placeholder="Write your reply…"><?= e(old('body')) ?></textarea>
                    <?php if (isset($errors['body'])): ?><div class="invalid-feedback d-block mb-2"><?= e($errors['body']) ?></div><?php endif; ?>
                    <p class="small text-warning mb-2 d-none" data-ns-internal-hint><i class="fa-solid fa-eye-slash me-1"></i>Internal notes are only visible to staff.</p>
                    <?php $uploadError = $errors['attachments'] ?? null; require dirname(__DIR__) . '/includes/layout/upload_field.php'; ?>
                    <div class="d-flex flex-wrap gap-2 justify-content-end align-items-center mt-3">
                        <label class="small text-muted" for="then_status">Then set status</label>
                        <select class="form-select form-select-sm w-auto" id="then_status" name="then_status">
                            <option value="">Keep current</option>
                            <?php foreach (TicketService::STATUSES as $s): ?>
                                <option value="<?= e($s) ?>"><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-paper-plane me-2"></i>Submit</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Workflow</h2></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="workflow">
                <fieldset <?= Auth::can('tickets.manage') ? '' : 'disabled' ?>>
                    <div class="mb-3">
                        <label class="form-label small" for="wfStatus">Status</label>
                        <select class="form-select" id="wfStatus" name="status">
                            <?php foreach (TicketService::STATUSES as $s): ?>
                                <option value="<?= e($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="wfPriority">Priority</label>
                        <select class="form-select" id="wfPriority" name="priority">
                            <?php foreach (TicketService::PRIORITIES as $p): ?>
                                <option value="<?= e($p) ?>" <?= $ticket['priority'] === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="wfAssign">Assigned staff</label>
                        <select class="form-select" id="wfAssign" name="assigned_to" <?= Auth::can('tickets.assign') ? '' : 'disabled' ?>>
                            <option value="">Unassigned</option>
                            <?php foreach ($staff as $member): ?>
                                <option value="<?= (int) $member['id'] ?>" <?= (int) $ticket['assigned_to'] === (int) $member['id'] ? 'selected' : '' ?>><?= e($member['full_name']) ?><?= (int) $member['id'] === (int) $user['id'] ? ' (me)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn ns-btn-primary w-100" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save changes</button>
                </fieldset>
            </form>
        </div>
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Details</h2></div>
            <dl class="ns-dl">
                <dt>Ticket number</dt><dd><?= e($ticket['ticket_no']) ?></dd>
                <dt>Category</dt><dd><?= e($ticket['category_name'] ?? '—') ?></dd>
                <dt>Created</dt><dd><?= e(format_dt($ticket['created_at'])) ?></dd>
                <dt>Updated</dt><dd><?= e(format_dt($ticket['updated_at'])) ?></dd>
                <dt>Closed</dt><dd><?= e(format_dt($ticket['closed_at'])) ?></dd>
            </dl>
            <?php if ($ticket['chat_session_id'] && Auth::can('conversations.view')): ?>
                <a class="btn btn-sm ns-btn-ghost w-100" href="<?= e(url('admin/conversation.php?id=' . $ticket['chat_session_id'])) ?>"><i class="fa-solid fa-comments me-1"></i>Linked AI conversation</a>
            <?php endif; ?>
        </div>
        <div class="ns-card">
            <div class="ns-card-head"><h2>Customer</h2><?php if (Auth::can('customers.view')): ?><a href="<?= e(url('admin/customer.php?id=' . $ticket['customer_id'])) ?>">Profile</a><?php endif; ?></div>
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="ns-avatar"><?= e(initials($ticket['customer_name'])) ?></span>
                <div><strong><?= e($ticket['customer_name']) ?></strong><br><small class="text-muted"><?= e($ticket['customer_email']) ?></small><br><small class="text-muted"><?= e($ticket['customer_phone'] ?: '') ?></small></div>
            </div>
            <?php if ($otherTickets): ?>
                <h3 class="ns-side-title">Other tickets</h3>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($otherTickets as $o): ?>
                        <li class="mb-1"><a href="<?= e(url('admin/ticket.php?id=' . $o['id'])) ?>"><?= e($o['ticket_no']) ?></a> · <?= e(str_limit($o['subject'], 30)) ?> <?= badge('status', $o['status']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php clear_old(); require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
