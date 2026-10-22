<?php $unread = Notifier::unreadCount((int) Auth::id()); ?>
<div class="dropdown" data-ns-notifications>
    <button class="btn ns-icon-btn position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="<?= e(__('notify.title')) ?>">
        <i class="fa-regular fa-bell"></i>
        <span class="ns-count-badge <?= $unread ? '' : 'd-none' ?>" data-ns-notif-count><?= $unread > 99 ? '99+' : $unread ?></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end ns-dropdown ns-notif-menu p-0">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <strong><?= e(__('notify.title')) ?></strong>
            <button type="button" class="btn btn-link btn-sm p-0" data-ns-notif-readall><?= e(__('notify.mark_all')) ?></button>
        </div>
        <div class="ns-notif-list" data-ns-notif-list>
            <div class="p-3 text-center text-muted small"><span class="spinner-border spinner-border-sm"></span></div>
        </div>
    </div>
</div>
