<?php
declare(strict_types=1);

/**
 * Retrieval step of the RAG pipeline.
 * Finds the FAQ entries and knowledge-base articles most relevant to a set of keywords.
 *
 * 1. Candidate rows are fetched with parameterised LIKE filters.
 * 2. Each candidate is scored in PHP (title/question hits weigh more than body hits).
 * 3. A relevance value between 0 and 1 is returned for the confidence calculation.
 */
final class KnowledgeRetriever
{
    private const MIN_RELEVANCE = 0.28;

    public static function search(array $keywords, int $limit = 3): array
    {
        $keywords = array_values(array_unique(array_filter($keywords, static fn ($k) => mb_strlen($k) >= 2)));
        $keywords = array_slice($keywords, 0, 8);
        if (!$keywords) {
            return [];
        }

        $results = array_merge(self::searchFaq($keywords), self::searchArticles($keywords));
        usort($results, static fn ($a, $b) => $b['relevance'] <=> $a['relevance']);
        // Keep only results that are reasonably close to the best match
        $cutoff = max(self::MIN_RELEVANCE, ($results[0]['relevance'] ?? 0) * 0.6);
        $results = array_filter($results, static fn ($r) => $r['relevance'] >= $cutoff);
        return array_slice($results, 0, $limit);
    }

    private static function searchFaq(array $keywords): array
    {
        [$where, $params] = self::likeConditions($keywords, ['f.question', 'f.answer', 'f.keywords']);
        $rows = DB::all(
            "SELECT f.id, f.question, f.answer, f.keywords
             FROM faq_entries f
             LEFT JOIN content_categories c ON c.id = f.category_id
             WHERE f.is_active = 1 AND (c.id IS NULL OR c.is_active = 1) AND ($where)
             LIMIT 40",
            $params
        );
        $results = [];
        foreach ($rows as $row) {
            $relevance = self::score($keywords, [
                [$row['question'], 3.0],
                [(string) $row['keywords'], 2.5],
                [$row['answer'], 1.0],
            ]);
            $results[] = [
                'type'      => 'faq',
                'id'        => (int) $row['id'],
                'title'     => $row['question'],
                'content'   => $row['answer'],
                'url'       => 'faq.php#faq-' . $row['id'],
                'relevance' => $relevance,
            ];
        }
        return $results;
    }

    private static function searchArticles(array $keywords): array
    {
        [$where, $params] = self::likeConditions($keywords, ['a.title', 'a.summary', 'a.tags', 'a.body']);
        $rows = DB::all(
            "SELECT a.id, a.title, a.slug, a.summary, a.tags, a.body
             FROM kb_articles a
             LEFT JOIN content_categories c ON c.id = a.category_id
             WHERE a.status = 'published' AND (c.id IS NULL OR c.is_active = 1) AND ($where)
             LIMIT 40",
            $params
        );
        $results = [];
        foreach ($rows as $row) {
            $relevance = self::score($keywords, [
                [$row['title'], 3.0],
                [(string) $row['tags'], 2.5],
                [$row['summary'], 1.5],
                [$row['body'], 1.0],
            ]) * 0.97; // FAQs are slightly preferred: they are short, direct answers
            $results[] = [
                'type'      => 'article',
                'id'        => (int) $row['id'],
                'title'     => $row['title'],
                'content'   => $row['summary'] . "\n" . self::excerpt($row['body'], $keywords),
                'url'       => 'article.php?slug=' . rawurlencode($row['slug']),
                'relevance' => $relevance,
            ];
        }
        return $results;
    }

    /** Builds "(col1 LIKE ? OR col2 LIKE ? ...)" for each keyword with unique positional params. */
    private static function likeConditions(array $keywords, array $columns): array
    {
        $parts = [];
        $params = [];
        foreach ($keywords as $keyword) {
            foreach ($columns as $column) {
                $parts[] = "$column LIKE ?";
                $params[] = '%' . addcslashes($keyword, '%_\\') . '%';
            }
        }
        return [implode(' OR ', $parts), $params];
    }

    /**
     * Relevance = 60% keyword coverage + 40% weighted field strength.
     *
     * @param array<array{0:string,1:float}> $fields
     */
    private static function score(array $keywords, array $fields): float
    {
        $matched = 0;
        $weighted = 0.0;
        $maxWeight = max(array_column($fields, 1));
        foreach ($keywords as $keyword) {
            $best = 0.0;
            foreach ($fields as [$text, $weight]) {
                if (mb_stripos((string) $text, $keyword) !== false) {
                    $best = max($best, $weight);
                }
            }
            if ($best > 0) {
                $matched++;
                $weighted += $best;
            }
        }
        $count = count($keywords);
        $coverage = $matched / $count;
        $strength = $weighted / ($count * $maxWeight);
        return round(0.6 * $coverage + 0.4 * $strength, 3);
    }

    /** Picks the paragraphs of an article that mention the keywords (keeps the prompt small). */
    private static function excerpt(string $body, array $keywords, int $maxChars = 1200): string
    {
        $paragraphs = preg_split('/\n\s*\n/', $body) ?: [$body];
        $scored = [];
        foreach ($paragraphs as $i => $paragraph) {
            $hits = 0;
            foreach ($keywords as $keyword) {
                if (mb_stripos($paragraph, $keyword) !== false) {
                    $hits++;
                }
            }
            $scored[$i] = $hits;
        }
        arsort($scored);
        $picked = [];
        $length = 0;
        foreach (array_keys($scored) as $i) {
            if ($length + mb_strlen($paragraphs[$i]) > $maxChars && $picked) {
                break;
            }
            $picked[$i] = $paragraphs[$i];
            $length += mb_strlen($paragraphs[$i]);
        }
        ksort($picked);
        return mb_substr(implode("\n\n", $picked), 0, $maxChars);
    }
}
