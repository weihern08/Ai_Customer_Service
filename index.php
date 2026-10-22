<?php
require __DIR__ . '/includes/bootstrap.php';

$pageTitle = __('home.title');
$activeNav = 'home';
$popularArticles = ContentService::popularArticles(4);
$popularFaqs = ContentService::popularFaqs(5);
$assistant = (string) Settings::get('ai_assistant_name', 'Nova');
$multilingual = Lang::isMultilingual();
$suggestions = [__('chat.suggest.plans'), __('chat.suggest.slow'), __('chat.suggest.refund'), __('chat.suggest.hours')];

require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="ns-hero">
    <div class="ns-hero-grid" aria-hidden="true"></div>
    <div class="ns-hero-glow" aria-hidden="true"></div>
    <div class="ns-hero-glow ns-hero-glow-2" aria-hidden="true"></div>
    <div class="container-xl position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="ns-eyebrow"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= e(__('home.eyebrow')) ?></span>
                <h1 class="ns-hero-title"><?= e(__('home.headline')) ?> <span class="ns-gradient-text"><?= e(__('home.headline_accent')) ?></span></h1>
                <p class="ns-hero-lead"><?= e(__('home.lead', ['name' => $assistant])) ?></p>

                <form class="ns-search" action="<?= e(url('knowledge.php')) ?>" method="get" role="search" data-ns-live-search>
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <label class="visually-hidden" for="heroSearch"><?= e(__('home.search_label')) ?></label>
                    <input id="heroSearch" type="search" name="q" placeholder="<?= e(__('home.search_placeholder')) ?>" autocomplete="off" maxlength="120">
                    <button class="btn ns-btn-primary" type="submit"><?= e(__('common.search')) ?></button>
                    <div class="ns-search-results" data-ns-search-results hidden></div>
                </form>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a class="btn ns-btn-cta btn-lg" href="<?= e(url('chat.php')) ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i><?= e(__('home.cta_chat', ['name' => $assistant])) ?></a>
                    <a class="btn ns-btn-ghost btn-lg" href="<?= e(url('ticket.php?new=1')) ?>"><i class="fa-solid fa-ticket me-2"></i><?= e(__('ticket.new')) ?></a>
                </div>
                <div class="ns-hero-stats">
                    <div><strong>24/7</strong><span><?= e(__('home.stat_ai')) ?></span></div>
                    <?php if ($multilingual): ?>
                        <div><strong>3</strong><span><?= e(__('home.stat_lang')) ?></span></div>
                    <?php else: ?>
                        <div><strong><i class="fa-solid fa-shield-halved"></i></strong><span><?= e(__('home.stat_verified')) ?></span></div>
                    <?php endif; ?>
                    <div><strong><i class="fa-solid fa-headset"></i></strong><span><?= e(__('home.stat_human')) ?></span></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ns-preview-wrap" aria-hidden="true">
                <span class="ns-float-badge ns-float-badge-1"><i class="fa-solid fa-circle-check"></i><?= e(__('home.badge_verified')) ?></span>
                <?php if ($multilingual): ?>
                    <span class="ns-float-badge ns-float-badge-2"><i class="fa-solid fa-language"></i>EN · BM · 中文</span>
                <?php else: ?>
                    <span class="ns-float-badge ns-float-badge-2"><i class="fa-solid fa-microphone"></i><?= e(__('home.badge_voice')) ?></span>
                <?php endif; ?>
                <span class="ns-float-badge ns-float-badge-3"><i class="fa-solid fa-headset"></i><?= e(__('home.badge_agent')) ?></span>
                <div class="ns-preview-card">
                    <div class="ns-preview-head">
                        <span class="ns-ai-avatar"><i class="fa-solid fa-star-of-life"></i></span>
                        <div>
                            <strong><?= e($assistant) ?></strong>
                            <small><span class="ns-dot-online"></span><?= e(__('chat.status_ai')) ?></small>
                        </div>
                    </div>
                    <div class="ns-preview-body">
                        <div class="ns-bubble ns-bubble-user"><?= e(__('home.preview_q')) ?></div>
                        <div class="ns-bubble ns-bubble-ai">
                            <?= e(__('home.preview_a')) ?>
                            <div class="ns-preview-meta">
                                <span class="ns-confidence ns-confidence-high"><i class="fa-solid fa-shield-halved"></i> <?= e(__('chat.confidence')) ?> 94%</span>
                                <span class="ns-source-chip"><i class="fa-solid fa-book"></i> FAQ</span>
                            </div>
                        </div>
                        <div class="ns-bubble ns-bubble-user"><?= e(__('home.preview_q2')) ?></div>
                        <div class="ns-typing"><span></span><span></span><span></span></div>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container-xl ns-section ns-channels">
    <div class="row g-4">
        <?php
        $channels = [
            ['chat.php', 'fa-wand-magic-sparkles', 'ns-tile-violet', __('home.ch_chat_title', ['name' => $assistant]), __($multilingual ? 'home.ch_chat_text' : 'home.ch_chat_text_en')],
            ['knowledge.php', 'fa-book-open', 'ns-tile-teal', __('nav.knowledge'), __('home.ch_kb_text')],
            ['faq.php', 'fa-circle-question', 'ns-tile-amber', __('nav.faq'), __('home.ch_faq_text')],
            ['ticket.php', 'fa-ticket', 'ns-tile-coral', __('home.ch_ticket_title'), __('home.ch_ticket_text')],
        ];
        foreach ($channels as [$href, $icon, $tone, $title, $text]): ?>
            <div class="col-sm-6 col-lg-3" data-ns-reveal>
                <a class="ns-channel-card <?= e($tone) ?>" href="<?= e(url($href)) ?>">
                    <span class="ns-channel-icon"><i class="fa-solid <?= e($icon) ?>"></i></span>
                    <h2><?= e($title) ?></h2>
                    <p><?= e($text) ?></p>
                    <span class="ns-channel-link"><?= e(__('common.open')) ?> <i class="fa-solid fa-arrow-right"></i></span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="ns-section ns-section-tint">
    <div class="container-xl">
        <div class="ns-section-head">
            <span class="ns-eyebrow ns-eyebrow-soft"><?= e(__('home.services_eyebrow')) ?></span>
            <h2><?= e(__('home.services_title')) ?></h2>
            <p><?= e(__('home.services_lead')) ?></p>
        </div>
        <div class="row g-4">
            <?php
            $services = [
                ['fa-wifi', 'NovaFibre', __('home.svc_fibre')],
                ['fa-cloud', 'NovaCloud Drive', __('home.svc_cloud')],
                ['fa-house-signal', 'NovaHome', __('home.svc_home')],
            ];
            foreach ($services as [$icon, $name, $text]): ?>
                <div class="col-md-4" data-ns-reveal>
                    <div class="ns-service-card">
                        <i class="fa-solid <?= e($icon) ?> ns-service-watermark" aria-hidden="true"></i>
                        <span class="ns-service-icon"><i class="fa-solid <?= e($icon) ?>"></i></span>
                        <h3><?= e($name) ?></h3>
                        <p><?= e($text) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="container-xl ns-section">
    <div class="row g-4" data-ns-reveal>
        <div class="col-lg-7">
            <div class="ns-panel h-100">
                <div class="ns-panel-head">
                    <h2><i class="fa-solid fa-fire-flame-curved"></i><?= e(__('home.popular_articles')) ?></h2>
                    <a href="<?= e(url('knowledge.php')) ?>"><?= e(__('common.view_all')) ?></a>
                </div>
                <?php if (!$popularArticles): ?>
                    <p class="ns-empty"><?= e(__('kb.empty')) ?></p>
                <?php endif; ?>
                <div class="ns-article-list">
                    <?php foreach ($popularArticles as $article): ?>
                        <a class="ns-article-row" href="<?= e(url('article.php?slug=' . rawurlencode($article['slug']))) ?>">
                            <span class="ns-article-icon"><i class="fa-solid <?= e($article['category_icon'] ?: 'fa-file-lines') ?>"></i></span>
                            <span>
                                <strong><?= e($article['title']) ?></strong>
                                <small><?= e(str_limit($article['summary'], 110)) ?></small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="ns-panel h-100">
                <div class="ns-panel-head">
                    <h2><i class="fa-solid fa-circle-question"></i><?= e(__('home.popular_faqs')) ?></h2>
                    <a href="<?= e(url('faq.php')) ?>"><?= e(__('common.view_all')) ?></a>
                </div>
                <ol class="ns-faq-quick">
                    <?php foreach ($popularFaqs as $faq): ?>
                        <li><a href="<?= e(url('faq.php#faq-' . $faq['id'])) ?>"><?= e($faq['question']) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </div>
</section>

<section class="ns-section ns-section-dark">
    <div class="container-xl">
        <div class="ns-section-head ns-section-head-light">
            <span class="ns-eyebrow"><?= e(__('home.how_eyebrow')) ?></span>
            <h2><?= e(__('home.how_title', ['name' => $assistant])) ?></h2>
        </div>
        <div class="row g-4 ns-steps">
            <?php foreach ([[$multilingual ? 'fa-language' : 'fa-brain', 'how1'], ['fa-book-bookmark', 'how2'], ['fa-shield-halved', 'how3'], ['fa-people-arrows', 'how4']] as $i => [$icon, $key]): ?>
                <div class="col-sm-6 col-lg-3" data-ns-reveal>
                    <div class="ns-step">
                        <span class="ns-step-no">0<?= $i + 1 ?></span>
                        <i class="fa-solid <?= e($icon) ?>"></i>
                        <h3><?= e(__("home.{$key}_title")) ?></h3>
                        <p><?= e(__($key === 'how1' && !$multilingual ? 'home.how1_text_en' : "home.{$key}_text")) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="container-xl ns-section">
    <div class="ns-contact-band" data-ns-reveal>
        <div class="ns-contact-intro">
            <span class="ns-contact-icon"><i class="fa-solid fa-headset"></i></span>
            <div>
                <h2><?= e(__('home.contact_title')) ?></h2>
                <p class="mb-0"><?= e(__('home.contact_lead')) ?></p>
            </div>
        </div>
        <div class="ns-contact-grid">
            <div><i class="fa-solid fa-clock"></i><span><?= nl2br_e((string) Settings::get('business_hours')) ?></span></div>
            <div><i class="fa-solid fa-phone"></i><span><?= e(Settings::get('support_phone')) ?></span></div>
            <div><i class="fa-solid fa-envelope"></i><span><?= e(Settings::get('support_email')) ?></span></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($suggestions as $suggestion): ?>
                <a class="ns-chip" href="<?= e(url('chat.php?q=' . rawurlencode($suggestion))) ?>"><?= e($suggestion) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/layout/portal_footer.php'; ?>
