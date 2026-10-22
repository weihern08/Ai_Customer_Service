        </main>
        <footer class="ns-admin-footer">
            <span><?= e(Settings::get('site_name')) ?> v<?= e(APP_VERSION) ?></span>
            <span>Signed in as <?= e(Auth::user()['email'] ?? '') ?></span>
        </footer>
    </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="nsToasts" aria-live="polite"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($useCharts)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
