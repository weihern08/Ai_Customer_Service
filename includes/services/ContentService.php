<?php
declare(strict_types=1);

/**
 * Read-side queries for categories, FAQ and knowledge-base articles (customer portal + APIs).
 */
final class ContentService
{
    public static function categories(bool $activeOnly = true): array
    {
        return DB::all(
            "SELECT c.*,
                (SELECT COUNT(*) FROM faq_entries f WHERE f.category_id = c.id AND f.is_active = 1) AS faq_count,
                (SELECT COUNT(*) FROM kb_articles a WHERE a.category_id = c.id AND a.status = 'published') AS article_count
             FROM content_categories c" . ($activeOnly ? ' WHERE c.is_active = 1' : '') . '
             ORDER BY c.sort_order, c.name'
        );
    }

    /**
     * Builds an AND-of-ORs search: every word must appear in at least one of the columns.
     * Returns [sql, params] using positional placeholders only.
     */
    public static function searchClause(string $query, array $columns): array
    {
        $words = array_slice(array_filter(preg_split('/\s+/u', trim($query)) ?: [], static fn ($w) => $w !== ''), 0, 6);
        if (!$words) {
            return ['1 = 1', []];
        }
        $groups = [];
        $params = [];
        foreach ($words as $word) {
            $like = '%' . addcslashes(mb_substr($word, 0, 50), '%_\\') . '%';
            $parts = [];
            foreach ($columns as $column) {
                $parts[] = "$column LIKE ?";
                $params[] = $like;
            }
            $groups[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [implode(' AND ', $groups), $params];
    }

    public static function faqs(string $query = '', ?int $categoryId = null): array
    {
        [$where, $params] = self::searchClause($query, ['f.question', 'f.answer', 'f.keywords']);
        $sql = "SELECT f.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
                FROM faq_entries f
                LEFT JOIN content_categories c ON c.id = f.category_id
                WHERE f.is_active = 1 AND (c.id IS NULL OR c.is_active = 1) AND $where";
        if ($categoryId) {
            $sql .= ' AND f.category_id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY c.sort_order, f.sort_order, f.id';
        return DB::all($sql, $params);
    }

    public static function popularFaqs(int $limit = 5): array
    {
        return DB::all(
            'SELECT f.id, f.question, f.view_count FROM faq_entries f
             LEFT JOIN content_categories c ON c.id = f.category_id
             WHERE f.is_active = 1 AND (c.id IS NULL OR c.is_active = 1)
             ORDER BY f.view_count DESC, f.id LIMIT ' . max(1, $limit)
        );
    }

    public static function articleCount(string $query = '', ?int $categoryId = null): int
    {
        [$where, $params] = self::articleFilter($query, $categoryId);
        return (int) DB::value("SELECT COUNT(*) FROM kb_articles a LEFT JOIN content_categories c ON c.id = a.category_id WHERE $where", $params);
    }

    public static function articles(string $query = '', ?int $categoryId = null, string $limitSql = ''): array
    {
        [$where, $params] = self::articleFilter($query, $categoryId);
        return DB::all(
            "SELECT a.id, a.title, a.slug, a.summary, a.tags, a.view_count, a.updated_at, a.published_at,
                    c.name AS category_name, c.icon AS category_icon
             FROM kb_articles a
             LEFT JOIN content_categories c ON c.id = a.category_id
             WHERE $where
             ORDER BY a.view_count DESC, a.updated_at DESC" . $limitSql,
            $params
        );
    }

    public static function popularArticles(int $limit = 5): array
    {
        return self::articles('', null, ' LIMIT ' . max(1, $limit));
    }

    public static function articleBySlug(string $slug, bool $includeDrafts = false): ?array
    {
        return DB::row(
            'SELECT a.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, u.full_name AS author_name
             FROM kb_articles a
             LEFT JOIN content_categories c ON c.id = a.category_id
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.slug = ?' . ($includeDrafts ? '' : " AND a.status = 'published'"),
            [$slug]
        );
    }

    /** Same category first, then articles sharing at least one tag. */
    public static function relatedArticles(array $article, int $limit = 4): array
    {
        $tags = array_slice(array_filter(array_map('trim', explode(',', (string) $article['tags']))), 0, 5);
        $tagSql = '';
        $params = [(int) $article['category_id'], (int) $article['id']];
        if ($tags) {
            $tagParts = [];
            foreach ($tags as $tag) {
                $tagParts[] = 'a.tags LIKE ?';
                $params[] = '%' . addcslashes($tag, '%_\\') . '%';
            }
            $tagSql = ' OR ' . implode(' OR ', $tagParts);
        }
        $catParam = array_shift($params);
        $selfParam = array_shift($params);
        return DB::all(
            "SELECT a.id, a.title, a.slug, a.summary FROM kb_articles a
             WHERE a.status = 'published' AND a.id <> ? AND (a.category_id = ?$tagSql)
             ORDER BY (a.category_id = ?) DESC, a.view_count DESC LIMIT " . max(1, $limit),
            [$selfParam, $catParam, ...$params, $catParam]
        );
    }

    /** Counts a view once per visitor session. */
    public static function recordView(string $type, int $id): void
    {
        $key = $type . ':' . $id;
        if (!empty($_SESSION['_viewed'][$key])) {
            return;
        }
        $_SESSION['_viewed'][$key] = true;
        $table = $type === 'faq' ? 'faq_entries' : 'kb_articles';
        // Keep updated_at unchanged: a view is not a content edit
        DB::run("UPDATE $table SET view_count = view_count + 1, updated_at = updated_at WHERE id = ?", [$id]);
    }

    private static function articleFilter(string $query, ?int $categoryId): array
    {
        [$where, $params] = self::searchClause($query, ['a.title', 'a.summary', 'a.body', 'a.tags']);
        $where = "a.status = 'published' AND (c.id IS NULL OR c.is_active = 1) AND $where";
        if ($categoryId) {
            $where .= ' AND a.category_id = ?';
            $params[] = $categoryId;
        }
        return [$where, $params];
    }
}
