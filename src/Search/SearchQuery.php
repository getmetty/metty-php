<?php

declare(strict_types=1);

namespace Metty\Client\Search;

use Metty\Client\Exception\ConfigurationException;

/**
 * Stavač parametrov `GET /search`.
 *
 * Facetová sémantika kopíruje server: `filter()` je OR v rámci poľa a AND medzi poľami,
 * `filterAll()` je AND aj v rámci poľa a `exclude()` je negácia.
 */
final class SearchQuery
{
    private const SORTABLE = ['price', 'name', 'brand', 'availability'];

    /** @var list<string> */
    private array $any = [];

    /** @var list<string> */
    private array $all = [];

    /** @var list<string> */
    private array $none = [];

    /** @var array<string, int> */
    private array $facets = [];

    /** @var list<string> */
    private array $hitFields = [];

    /** @var list<string> */
    private array $removeFields = [];

    private int $size = 10;

    private ?int $from = null;

    private ?int $page = null;

    private ?string $sort = null;

    private function __construct(
        private readonly string $query,
    ) {}

    public static function for(string $query = ''): self
    {
        return new self($query);
    }

    public function filter(string $facet, string $value): self
    {
        $this->any[] = $facet . ':' . $value;

        return $this;
    }

    public function filterAll(string $facet, string $value): self
    {
        $this->all[] = $facet . ':' . $value;

        return $this;
    }

    public function exclude(string $facet, string $value): self
    {
        $this->none[] = $facet . ':' . $value;

        return $this;
    }

    public function range(string $facet, ?float $min, ?float $max): self
    {
        if ($min === null && $max === null) {
            throw new ConfigurationException('A range needs at least one bound.');
        }

        $this->any[] = sprintf('%s:%s|%s', $facet, $min ?? '', $max ?? '');

        return $this;
    }

    public function missing(string $facet): self
    {
        $this->any[] = $facet . ':value_missing';

        return $this;
    }

    public function facet(string $facet, int $limit = 10): self
    {
        $this->facets[$facet] = $limit;

        return $this;
    }

    public function size(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function page(int $page): self
    {
        $this->page = $page;
        $this->from = null;

        return $this;
    }

    public function from(int $from): self
    {
        $this->from = $from;
        $this->page = null;

        return $this;
    }

    public function sortBy(string $field, string $direction = 'asc'): self
    {
        if (!in_array($field, self::SORTABLE, true)) {
            throw new ConfigurationException(sprintf('Sorting supports only: %s.', implode(', ', self::SORTABLE)));
        }

        $this->sort = $field . ':' . strtolower($direction);

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function onlyFields(array $fields): self
    {
        $this->hitFields = $fields;

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function withoutFields(array $fields): self
    {
        $this->removeFields = $fields;

        return $this;
    }

    /**
     * @return array<string, scalar|array<int, string>|null>
     */
    public function toQueryParameters(): array
    {
        $facets = [];
        foreach ($this->facets as $facet => $limit) {
            $facets[] = $facet . ':' . $limit;
        }

        return array_filter([
            'q' => $this->query,
            'size' => $this->size,
            'page' => $this->page,
            'from' => $this->from,
            'sort' => $this->sort,
            'f' => $this->any === [] ? null : $this->any,
            'f_must' => $this->all === [] ? null : $this->all,
            'neg_f' => $this->none === [] ? null : $this->none,
            'facets' => $facets === [] ? null : implode(',', $facets),
            'hit_fields' => $this->hitFields === [] ? null : implode(',', $this->hitFields),
            'remove_fields' => $this->removeFields === [] ? null : implode(',', $this->removeFields),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
