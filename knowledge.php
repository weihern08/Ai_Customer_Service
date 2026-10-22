<?php
require __DIR__ . '/includes/bootstrap.php';

$query = mb_substr(trim((string) input('q')), 0, 120);
$categoryId = input_int('category') ?: null;
$categories = ContentService::categories();
$activeCategory = null;
foreach ($categories as $cat) {
    if ((int) $cat['id'] === $categoryId) {
        $activeCategory = $cat;
    }
}

$pager = new Paginator(ContentService::articleCount($query, $categoryId), 8);
$articles = ContentService::articles($query, $categoryId, $pager->limitSql());
$popular = ContentService::popularArticles(5);
$isBrowsing = $query === '' && !$categoryId;

$pageTitle = __('kb.title');
$activeNav = 'knowledge';
require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="ns-page-hero">
    <span class="ns-hero-art" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span>
    <div class="container-xl">
        <span class="ns-eyebrow ns-eyebrow-soft"><i class="fa-solid fa-book-open"></i> <?= e(__('nav.knowledge')) ?></span>
        <h1><?= e($activeCategory ? $activeCategory['name'] : __('kb.title')) ?></h1>
        <p><?= e($activeCategory ? (string) $activeCategory['description'] : __('kb.lead')) ?></p>
        <form class="ns-search ns-search-compact" method="get" role="search" data-ns-live-search>
            <i class="fa-solid fa-magnifying-glass"></i>
            <label class="visually-hidden" for="kbSearch"><?= e(__('kb.search')) ?></label>
            <input id="kbSearch" type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(__('kb.search')) ?>" maxlength="120" autocomplete="off">
            <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= (int) $categoryId ?>"><?php endif; ?>
            <button class="btn ns-btn-primary" type="submit"><?= e(__('common.search')) ?></button>
            <div class="ns-search-results" data-ns-search-results hidden></div>
        </form>
    </div>
</section>

<section class="container-xl ns-section pt-4">
    <?php if ($isBrowsing): ?>
        <div class="row g-3 mb-5">
            <?php foreach ($categories as $cat): ?>
                <div class="col-sm-6 col-lg-4 col-xl-3">
                    <a class="ns-category-card" href="<?= e(url('knowledge.php?category=' . $cat['id'])) ?>">
                        <span class="ns-category-icon"><i class="fa-solid <?= e($cat['icon']) ?>"></i></span>
                        <strong><?= e($cat['name']) ?></strong>
                        <small><?= e(__('kb.counts', ['a' => (int) $cat['article_count'], 'f' => (int) $cat['faq_count']])) ?></small>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ns-pill-row mb-4">
            <a class="ns-pill" href="<?= e(url('knowledge.php')) ?>"><i class="fa-solid fa-arrow-left"></i><?= e(__('kb.all_topics')) ?></a>
            <?php if ($query !== ''): ?>
                <span class="text-muted align-self-center"><?= e(__('common.results_for', ['n' => $pager->total, 'q' => $query])) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <h2 class="ns-group-title"><?= e($isBrowsing ? __('kb.latest') : __('kb.articles')) ?></h2>
            <?php if (!$articles): ?>
                <div class="ns-empty-state">
                    <i class="fa-regular fa-folder-open"></i>
                    <h2><?= e(__('kb.empty_title')) ?></h2>
                    <p><?= e(__('kb.empty_text')) ?></p>
                    <a class="btn ns-btn-primary" href="<?= e(url('chat.php' . ($query ? '?q=' . rawurlencode($query) : ''))) ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i><?= e(__('common.ask_nova', ['name' => Settings::get('ai_assistant_name')])) ?></a>
                </div>
            <?php endif; ?>
            <div class="ns-article-cards">
                <?php foreach ($articles as $article): ?>
                    <a class="ns-article-card" href="<?= e(url('article.php?slug=' . rawurlencode($article['slug']))) ?>">
                        <span class="ns-article-cat"><i class="fa-solid <?= e($article['category_icon'] ?: 'fa-file-lines') ?>"></i><?= e($article['category_name'] ?? __('faq.general')) ?></span>
                        <h3><?= e($article['title']) ?></h3>
                        <p><?= e(str_limit($article['summary'], 170)) ?></p>
                        <span class="ns-article-meta">
                            <span><i class="fa-regular fa-clock me-1"></i><?= e(format_dt($article['updated_at'], 'd M Y')) ?></span>
                            <span><i class="fa-regular fa-eye me-1"></i><?= e(__('common.views', ['n' => (int) $article['view_count']])) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="mt-4"><?= $pager->render() ?></div>
        </div>
        <aside class="col-lg-4 ns-sticky-aside">
            <div class="ns-panel mb-4">
                <div class="ns-panel-head"><h2><i class="fa-solid fa-fire-flame-curved"></i><?= e(__('kb.popular')) ?></h2></div>
                <div class="ns-article-list">
                    <?php foreach ($popular as $item): ?>
                        <a class="ns-article-row ns-article-row-sm" href="<?= e(url('article.php?slug=' . rawurlencode($item['slug']))) ?>">
                            <span class="ns-article-icon"><i class="fa-solid <?= e($item['category_icon'] ?: 'fa-file-lines') ?>"></i></span>
                            <span><strong><?= e($item['title']) ?></strong><small><?= e(__('common.views', ['n' => (int) $item['view_count']])) ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ns-help-card">
                <h2><?= e(__('common.still_need_help')) ?></h2>
                <p><?= e(__('kb.help_text')) ?></p>
                <a class="btn ns-btn-light w-100 mb-2" href="<?= e(url('chat.php')) ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i><?= e(__('common.ask_nova', ['name' => Settings::get('ai_assistant_name')])) ?></a>
                <a class="btn ns-btn-outline-light w-100" href="<?= e(url('faq.php')) ?>"><i class="fa-solid fa-circle-question me-2"></i><?= e(__('nav.faq')) ?></a>
            </div>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/includes/layout/portal_footer.php'; ?>
