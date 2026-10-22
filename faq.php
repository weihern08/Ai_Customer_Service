<?php
require __DIR__ . '/includes/bootstrap.php';

$query = mb_substr(trim((string) input('q')), 0, 120);
$categoryId = input_int('category') ?: null;
$categories = array_filter(ContentService::categories(), static fn ($c) => (int) $c['faq_count'] > 0);
$faqs = ContentService::faqs($query, $categoryId);
$popular = ContentService::popularFaqs(6);

$grouped = [];
foreach ($faqs as $faq) {
    $grouped[$faq['category_name'] ?? __('faq.general')][] = $faq;
}

$pageTitle = __('faq.title');
$activeNav = 'faq';
require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="ns-page-hero">
    <span class="ns-hero-art" aria-hidden="true"><i class="fa-solid fa-circle-question"></i></span>
    <div class="container-xl">
        <span class="ns-eyebrow ns-eyebrow-soft"><i class="fa-solid fa-circle-question"></i> <?= e(__('nav.faq')) ?></span>
        <h1><?= e(__('faq.title')) ?></h1>
        <p><?= e(__('faq.lead')) ?></p>
        <form class="ns-search ns-search-compact" method="get" role="search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <label class="visually-hidden" for="faqSearch"><?= e(__('faq.search')) ?></label>
            <input id="faqSearch" type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(__('faq.search')) ?>" maxlength="120">
            <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= (int) $categoryId ?>"><?php endif; ?>
            <button class="btn ns-btn-primary" type="submit"><?= e(__('common.search')) ?></button>
        </form>
    </div>
</section>

<section class="container-xl ns-section pt-4">
    <div class="ns-pill-row mb-4" role="navigation" aria-label="<?= e(__('common.categories')) ?>">
        <a class="ns-pill <?= !$categoryId ? 'active' : '' ?>" href="<?= e(url('faq.php' . ($query ? '?q=' . rawurlencode($query) : ''))) ?>"><?= e(__('common.all')) ?></a>
        <?php foreach ($categories as $cat): ?>
            <a class="ns-pill <?= $categoryId === (int) $cat['id'] ? 'active' : '' ?>" href="<?= e(url('faq.php?' . http_build_query(array_filter(['category' => $cat['id'], 'q' => $query])))) ?>">
                <i class="fa-solid <?= e($cat['icon']) ?>"></i><?= e($cat['name']) ?> <span><?= (int) $cat['faq_count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <?php if ($query !== ''): ?>
                <p class="text-muted"><?= e(__('common.results_for', ['n' => count($faqs), 'q' => $query])) ?></p>
            <?php endif; ?>

            <?php if (!$faqs): ?>
                <div class="ns-empty-state">
                    <i class="fa-regular fa-face-meh"></i>
                    <h2><?= e(__('faq.empty_title')) ?></h2>
                    <p><?= e(__('faq.empty_text')) ?></p>
                    <a class="btn ns-btn-primary" href="<?= e(url('chat.php' . ($query ? '?q=' . rawurlencode($query) : ''))) ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i><?= e(__('common.ask_nova', ['name' => Settings::get('ai_assistant_name')])) ?></a>
                </div>
            <?php endif; ?>

            <?php foreach ($grouped as $groupName => $items): ?>
                <h2 class="ns-group-title"><?= e($groupName) ?></h2>
                <div class="accordion ns-accordion mb-4" id="faqGroup<?= md5($groupName) ?>">
                    <?php foreach ($items as $faq): ?>
                        <div class="accordion-item" id="faq-<?= (int) $faq['id'] ?>">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqBody<?= (int) $faq['id'] ?>" aria-expanded="false" aria-controls="faqBody<?= (int) $faq['id'] ?>" data-ns-faq-view="<?= (int) $faq['id'] ?>">
                                    <?= e($faq['question']) ?>
                                </button>
                            </h3>
                            <div id="faqBody<?= (int) $faq['id'] ?>" class="accordion-collapse collapse">
                                <div class="accordion-body ns-rich">
                                    <?= render_rich_text($faq['answer']) ?>
                                    <div class="ns-faq-foot">
                                        <span><i class="fa-regular fa-eye me-1"></i><?= e(__('common.views', ['n' => (int) $faq['view_count']])) ?></span>
                                        <a href="<?= e(url('chat.php?q=' . rawurlencode($faq['question']))) ?>"><i class="fa-solid fa-wand-magic-sparkles me-1"></i><?= e(__('faq.ask_followup')) ?></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <aside class="col-lg-4 ns-sticky-aside">
            <div class="ns-panel mb-4">
                <div class="ns-panel-head"><h2><i class="fa-solid fa-fire-flame-curved"></i><?= e(__('faq.popular')) ?></h2></div>
                <ol class="ns-faq-quick">
                    <?php foreach ($popular as $item): ?>
                        <li><a href="#faq-<?= (int) $item['id'] ?>" data-ns-open-faq="<?= (int) $item['id'] ?>"><?= e($item['question']) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <div class="ns-help-card">
                <h2><?= e(__('common.still_need_help')) ?></h2>
                <p><?= e(__('faq.help_text')) ?></p>
                <a class="btn ns-btn-light w-100 mb-2" href="<?= e(url('chat.php')) ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i><?= e(__('common.ask_nova', ['name' => Settings::get('ai_assistant_name')])) ?></a>
                <a class="btn ns-btn-outline-light w-100" href="<?= e(url('ticket.php?new=1')) ?>"><i class="fa-solid fa-ticket me-2"></i><?= e(__('ticket.new')) ?></a>
            </div>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/includes/layout/portal_footer.php'; ?>
