<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

/**
 * The `meta` half of the section 9.1 envelope for list endpoints.
 *
 * `limit` is capped because the page size arrives from the query string, and an
 * uncapped one is a trivial way for anyone to ask the database for everything.
 */
final class Pagination
{
    public const DEFAULT_LIMIT = 12;
    public const MAX_LIMIT = 100;

    public function __construct(
        public readonly int $page,
        public readonly int $limit,
    ) {
    }

    /** @param array<string,mixed> $query */
    public static function fromQuery(array $query, int $defaultLimit = self::DEFAULT_LIMIT): self
    {
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 1;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : $defaultLimit;

        return new self(
            max(1, $page),
            min(self::MAX_LIMIT, max(1, $limit)),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /** @return array<string,int> */
    public function meta(int $total): array
    {
        return [
            'page'       => $this->page,
            'limit'      => $this->limit,
            'total'      => $total,
            'totalPages' => $total === 0 ? 0 : (int) ceil($total / $this->limit),
        ];
    }
}
