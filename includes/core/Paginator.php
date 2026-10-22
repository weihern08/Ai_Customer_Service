<?php
declare(strict_types=1);

/**
 * Simple page-number pagination.
 */
final class Paginator
{
    public readonly int $page;
    public readonly int $pages;
    public readonly int $offset;

    public function __construct(public readonly int $total, public readonly int $perPage = 15)
    {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min(max(1, input_int('page', 1)), $this->pages);
        $this->offset = ($this->page - 1) * $perPage;
    }

    public function limitSql(): string
    {
        return ' LIMIT ' . (int) $this->perPage . ' OFFSET ' . (int) $this->offset;
    }

    public function render(): string
    {
        if ($this->pages <= 1) {
            return '';
        }
        $html = '<nav aria-label="Pagination"><ul class="pagination ns-pagination justify-content-center mb-0">';
        $html .= $this->item($this->page - 1, '&lsaquo;', $this->page <= 1);
        $start = max(1, $this->page - 2);
        $end = min($this->pages, $this->page + 2);
        for ($i = $start; $i <= $end; $i++) {
            $html .= $this->item($i, (string) $i, false, $i === $this->page);
        }
        $html .= $this->item($this->page + 1, '&rsaquo;', $this->page >= $this->pages);
        return $html . '</ul></nav>';
    }

    private function item(int $page, string $label, bool $disabled, bool $active = false): string
    {
        $classes = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
        return '<li class="' . $classes . '"><a class="page-link" href="' . e(query_with(['page' => $page])) . '">' . $label . '</a></li>';
    }
}
