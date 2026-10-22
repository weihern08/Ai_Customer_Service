<?php
/**
 * GET  /api/faq.php?q=&category=   -> public FAQ search
 * POST /api/faq.php { action: "view", id } -> records an FAQ view (once per visitor session)
 */
define('NS_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (is_post()) {
    require_csrf();
    $data = json_input();
    if (($data['action'] ?? '') !== 'view') {
        json_error(__('error.invalid_request'), 400);
    }
    $id = (int) ($data['id'] ?? 0);
    if (!DB::value('SELECT id FROM faq_entries WHERE id = ? AND is_active = 1', [$id])) {
        json_error(__('error.not_found'), 404);
    }
    ContentService::recordView('faq', $id);
    json_response(['ok' => true]);
}

$query = mb_substr(trim((string) input('q')), 0, 120);
$category = input_int('category') ?: null;
$items = array_map(static fn ($f) => [
    'id'       => (int) $f['id'],
    'question' => $f['question'],
    'answer'   => $f['answer'],
    'category' => $f['category_name'],
    'views'    => (int) $f['view_count'],
], ContentService::faqs($query, $category));

json_response(['ok' => true, 'count' => count($items), 'items' => $items]);
