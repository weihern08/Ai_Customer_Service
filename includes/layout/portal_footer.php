<?php
/** Customer portal footer. Optional: $hideFooter (bool), $pageScripts (array of asset paths) */
?>
</main>
<?php if (empty($hideFooter)): ?>
<footer class="ns-footer">
    <div class="container-xl">
        <div class="row g-4">
            <div class="col-lg-4">
                <a class="ns-brand ns-brand-light mb-3 d-inline-flex" href="<?= e(url('index.php')) ?>">
                    <?php require __DIR__ . '/logo.php'; ?>
                    <span class="ns-brand-text">Nova<span>Serve</span></span>
                </a>
                <p class="ns-footer-muted mb-2"><?= e(Settings::get('company_tagline')) ?></p>
                <p class="ns-footer-muted small mb-0"><?= e(Settings::get('company_name')) ?></p>
            </div>
            <div class="col-6 col-lg-2">
                <h2 class="ns-footer-title"><?= e(__('footer.help')) ?></h2>
                <ul class="list-unstyled ns-footer-links">
                    <li><a href="<?= e(url('chat.php')) ?>"><?= e(__('nav.chat')) ?></a></li>
                    <li><a href="<?= e(url('knowledge.php')) ?>"><?= e(__('nav.knowledge')) ?></a></li>
                    <li><a href="<?= e(url('faq.php')) ?>"><?= e(__('nav.faq')) ?></a></li>
                    <li><a href="<?= e(url('ticket.php?new=1')) ?>"><?= e(__('ticket.new')) ?></a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-3">
                <h2 class="ns-footer-title"><?= e(__('footer.contact')) ?></h2>
                <ul class="list-unstyled ns-footer-links">
                    <li><i class="fa-solid fa-envelope me-2"></i><?= e(Settings::get('support_email')) ?></li>
                    <li><i class="fa-solid fa-phone me-2"></i><?= e(Settings::get('support_phone')) ?></li>
                    <li><i class="fa-brands fa-whatsapp me-2"></i><?= e(Settings::get('support_whatsapp')) ?></li>
                    <li class="d-flex"><i class="fa-solid fa-location-dot me-2 mt-1"></i><span><?= e(Settings::get('company_address')) ?></span></li>
                </ul>
            </div>
            <div class="col-lg-3">
                <h2 class="ns-footer-title"><?= e(__('footer.hours')) ?></h2>
                <p class="ns-footer-muted mb-2"><?= nl2br_e((string) Settings::get('business_hours')) ?></p>
                <p class="ns-footer-muted small mb-0"><i class="fa-solid fa-robot me-1"></i><?= e(__('footer.ai_247')) ?></p>
            </div>
        </div>
        <div class="ns-footer-bottom">
            <span>© <?= date('Y') ?> <?= e(Settings::get('company_name')) ?>. <?= e(__('footer.fictional')) ?></span>
            <span><?= e(__('footer.made_with')) ?></span>
        </div>
    </div>
</footer>
<?php endif; ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="nsToasts" aria-live="polite"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
