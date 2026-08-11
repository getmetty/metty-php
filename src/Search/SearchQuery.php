<?php

declare(strict_types=1);

namespace Metty\Client\Search;

use Metty\Client\Exception\ConfigurationException;

/**
 * Builder for the `GET /search` parameters.
 *
 * Facets are ordinary fields from the catalog: `facet('colour', 'white')` means AND across fields
 * and OR within the values of one field. Server boundaries (`per_page`, the 200 result window, the
 * list of sorts) are checked here so that the error arrives before the request instead of as a
 * `422` from the server.
 */
final class SearchQuery
{
    /** Paging ends at the first 200 results; nothing deeper is ranked. */
    public const MAX_WINDOW = 200;

    public const MAX_PER_PAGE = 100;

    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'name_asc'];

    public const SECTIONS = ['facets', 'categories', 'suggestions'];

    private const RESERVED = ['key', 'q', 'page', 'per_page', 'category', 'price_min', 'price_max', 'sort', 'include'];

    /** @var list<string> */
    private array $categories = [];

    /** @var array<string, list<string>> */
    private array $facets = [];

    private ?float $priceMin = null;

    private ?float $priceMax = null;

    private int $page = 1;

    private int $perPage = 24;

    private ?string $sort = null;

    /** @var list<string> */
    private array $include = [];

    private function __construct(
        private readonly string $query,
    ) {}

    public static function for(string $query = ''): self
    {
        return new self($query);
    }

    /**
     * A category path in the shape the product's `category` is returned in, e.g. `Tools > Drills`.
     */
    public function category(string $path): self
    {
        $this->categories[] = $path;

        return $this;
    }

    public function facet(string $field, string $value): self
    {
        if (in_array($field, self::RESERVED, true)) {
            throw new ConfigurationException(sprintf('The name "%s" is a reserved search parameter and cannot be a facet.', $field));
        }

        $this->facets[$field][] = $value;

        return $this;
    }

    public function priceRange(?float $min, ?float $max): self
    {
        if ($min === null && $max === null) {
            throw new ConfigurationException('A price range needs at least one bound.');
        }

        $this->priceMin = $min;
        $this->priceMax = $max;

        return $this;
    }

    public function page(int $page): self
    {
        if ($page < 1) {
            throw new ConfigurationException('The page must be at least 1.');
        }

        $this->page = $page;

        return $this;
    }

    public function perPage(int $perPage): self
    {
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new ConfigurationException(sprintf('The per_page must be between 1 and %d.', self::MAX_PER_PAGE));
        }

        $this->perPage = $perPage;

        return $this;
    }

    public function sortBy(string $sort): self
    {
        if (!in_array($sort, self::SORTS, true)) {
            throw new ConfigurationException(sprintf('Sorting supports only: %s.', implode(', ', self::SORTS)));
        }

        $this->sort = $sort;

        return $this;
    }

    public function withSections(string ...$sections): self
    {
        foreach ($sections as $section) {
            if (!in_array($section, self::SECTIONS, true)) {
                throw new ConfigurationException(sprintf('Unknown section "%s"; supported are: %s.', $section, implode(', ', self::SECTIONS)));
            }
        }

        $this->include = array_values($sections);

        return $this;
    }

    /**
     * @return array<string, scalar|array<int, string>|null>
     */
    public function toQueryParameters(): array
    {
        if ($this->page * $this->perPage > self::MAX_WINDOW) {
            throw new ConfigurationException(sprintf('The requested page is beyond the first %d results.', self::MAX_WINDOW));
        }

        $parameters = [
            'q' => $this->query,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'category' => $this->categories === [] ? null : $this->categories,
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'sort' => $this->sort,
            'include' => $this->include === [] ? null : implode(',', $this->include),
        ];

        foreach ($this->facets as $field => $values) {
            $parameters[$field] = $values;
        }

        return array_filter($parameters, static fn (mixed $value): bool => $value !== null);
    }
}
