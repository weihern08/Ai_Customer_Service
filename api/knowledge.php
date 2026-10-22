<?php
/**
 * GET /api/knowledge.php?q=router&limit=5  -> public live search across articles and FAQ
 */
define('NS_API', true);
define('NS_PASSIVE', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$query = mb_substr(trim((string) input('q')), 0, 120);
$limit = max(1, min(10, input_int('limit', 5)));
if (mb_strlen($query) < 2) {
    json_response(['ok' => true, 'articles' => [], 'faqs' => []]);
}

$articles = array_map(static fn ($a) => [
    'title'    => $a['title'],
    'summary'  => str_limit($a['summary'], 120),
    'category' => $a['category_name'],
    'url'      => url('article.php?slug=' . rawurlencode($a['slug'])),
], ContentService::articles($query, null, ' LIMIT ' . $limit));

$faqs = array_map(static fn ($f) => [
    'title'    => $f['question'],
    'summary'  => str_limit($f['answer'], 120),
    'category' => $f['category_name'],
    'url'      => url('faq.php?q=' . rawurlencode($query) . '#faq-' . $f['id']),
], array_slice(ContentService::faqs($query), 0, $limit));

json_response(['ok' => true, 'articles' => $articles, 'faqs' => $faqs]);
