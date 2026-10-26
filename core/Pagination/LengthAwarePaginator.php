<?php

declare(strict_types=1);

namespace Core\Pagination;

use JsonSerializable;

class LengthAwarePaginator implements JsonSerializable
{
    /**
     * @param array<int, mixed> $items
     * @param int $total
     * @param int $perPage
     * @param int $currentPage
     * @param string $path
     * @param array<string, mixed> $queryParams
     */
    public function __construct(
        protected array $items,
        protected int $total,
        protected int $perPage = 15,
        protected int $currentPage = 1,
        protected string $path = '',
        protected array $queryParams = []
    ) {
        $this->currentPage = max(1, $this->currentPage);
        $this->perPage = max(1, $this->perPage);
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function from(): ?int
    {
        if ($this->total === 0) {
            return null;
        }
        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function to(): ?int
    {
        if ($this->total === 0) {
            return null;
        }
        return min($this->currentPage * $this->perPage, $this->total);
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * Build standard pagination navigation links for frontend components.
     *
     * @return array<int, array{url: ?string, label: string, active: bool}>
     */
    public function links(): array
    {
        $lastPage = $this->lastPage();
        $links = [];

        // Previous Link
        $prevUrl = $this->currentPage > 1 ? $this->url($this->currentPage - 1) : null;
        $links[] = [
            'url' => $prevUrl,
            'label' => '&laquo; Sebelumnya',
            'active' => false,
        ];

        // Page Number Links
        for ($i = 1; $i <= $lastPage; $i++) {
            $links[] = [
                'url' => $this->url($i),
                'label' => (string) $i,
                'active' => $i === $this->currentPage,
            ];
        }

        // Next Link
        $nextUrl = $this->currentPage < $lastPage ? $this->url($this->currentPage + 1) : null;
        $links[] = [
            'url' => $nextUrl,
            'label' => 'Berikutnya &raquo;',
            'active' => false,
        ];

        return $links;
    }

    /**
     * Generate URL for a given page number preserving existing query params.
     */
    public function url(int $page): string
    {
        $params = array_merge($this->queryParams, ['page' => $page]);
        $queryString = http_build_query($params);

        if ($this->path === '') {
            return '?' . $queryString;
        }

        return $this->path . '?' . $queryString;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
            'from' => $this->from(),
            'to' => $this->to(),
            'has_more' => $this->hasMorePages(),
            'links' => $this->links(),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
