<?php
require __DIR__ . '/includes/bootstrap.php';

$slug = (string) input('slug');
$canPreview = Auth::can('kb.manage');
$article = $slug !== '' ? ContentService::articleBySlug($slug, $canPreview) : null;
if (!$article) {
    abort(404);
}
if ($article['status'] === 'published') {
    ContentService::recordView('article', (int) $article['id']);
}
$related = ContentService::relatedArticles($article);
$tags = array_filter(array_map('trim', explode(',', (string) $article['tags'])));

$pageTitle = $article['title'];
$activeNav = 'knowledge';
require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="container-xl ns-section pt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb ns-breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('knowledge.php')) ?>"><?= e(__('nav.knowledge')) ?></a></li>
            <?php if ($article['category_id']): ?>
                <li class="breadcrumb-item"><a href="<?= e(url('knowledge.php?category=' . $article['category_id'])) ?>"><?= e($article['category_name']) ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?= e(str_limit($article['title'], 50)) ?></li>
        </ol>
    </nav>
    <div class="row g-4">
        <div class="ns-read-progress" aria-hidden="true"><span data-ns-read-progress></span></div>
        <article class="col-lg-8" data-ns-article>
            <div class="ns-article">
                <?php if ($article['status'] !== 'published'): ?>
                    <div class="alert alert-warning"><i class="fa-solid fa-eye-slash me-2"></i><?= e(__('kb.draft_preview')) ?></div>
                <?php endif; ?>
                <span class="ns-article-cat"><i class="fa-solid <?= e($article['category_icon'] ?: 'fa-file-lines') ?>"></i><?= e($article['category_name'] ?? __('faq.general')) ?></span>
                <h1><?= e($article['title']) ?></h1>
                <div class="ns-article-meta mb-3">
                    <span><i class="fa-regular fa-user me-1"></i><?= e($article['author_name'] ?? 'NovaServe') ?></span>
                    <span><i class="fa-regular fa-calendar me-1"></i><?= e(__('kb.published')) ?> <?= e(format_dt($article['published_at'] ?? $article['created_at'], 'd M Y')) ?></span>
                    <span><i class="fa-regular fa-clock me-1"></i><?= e(__('kb.updated')) ?> <?= e(format_dt($article['updated_at'], 'd M Y')) ?></span>
                    <span><i class="fa-regular fa-eye me-1"></i><?= e(__('common.views', ['n' => (int) $article['view_count']])) ?></span>
                </div>
                <p class="ns-article-lead"><?= e($article['summary']) ?></p>
                <div class="ns-rich"><?= render_rich_text($article['body']) ?></div>
                <?php if ($tags): ?>
                    <div class="ns-tags">
                        <?php foreach ($tags as $tag): ?>
                            <a class="ns-tag" href="<?= e(url('knowledge.php?q=' . rawurlencode($tag))) ?>">#<?= e($tag) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ns-ask-band">
                <div>
                    <strong><?= e(__('kb.ask_title')) ?></strong>
                    <p class="mb-0"><?= e(__('kb.ask_text', ['name' => Settings::get('ai_assistant_name')])) ?></p>
                </div>
                <a class="btn ns-btn-primary" href="<?= e(url('chat.php?q=' . rawurlencode(__('kb.ask_prefill', ['title' => $article['title']])))) ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i><?= e(__('common.ask_nova', ['name' => Settings::get('ai_assistant_name')])) ?></a>
            </div>
        </article>
        <aside class="col-lg-4 ns-sticky-aside">
            <div class="ns-panel mb-4">
                <div class="ns-panel-head"><h2><i class="fa-solid fa-link"></i><?= e(__('kb.related')) ?></h2></div>
                <?php if (!$related): ?><p class="ns-empty"><?= e(__('kb.no_related')) ?></p><?php endif; ?>
                <div class="ns-article-list">
                    <?php foreach ($related as $item): ?>
                        <a class="ns-article-row ns-article-row-sm" href="<?= e(url('article.php?slug=' . rawurlencode($item['slug']))) ?>">
                            <span class="ns-article-icon"><i class="fa-solid fa-file-lines"></i></span>
                            <span><strong><?= e($item['title']) ?></strong><small><?= e(str_limit($item['summary'], 80)) ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ns-help-card">
                <h2><?= e(__('common.still_need_help')) ?></h2>
                <p><?= e(__('kb.help_text')) ?></p>
                <a class="btn ns-btn-light w-100" href="<?= e(url('ticket.php?new=1')) ?>"><i class="fa-solid fa-ticket me-2"></i><?= e(__('ticket.new')) ?></a>
            </div>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/includes/layout/portal_footer.php'; ?>
