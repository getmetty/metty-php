<?php

declare(strict_types=1);

namespace Metty\Client\Catalog;

use Metty\Client\Exception\ConfigurationException;

/**
 * Produkt tak, ako ho prijíma `/catalog/products`.
 *
 * `create()` pokrýva bežný zápis a nevyplnené polia vynechá. Pri `PATCH` je rozdiel medzi
 * vynechaným poľom (ostáva) a poľom s hodnotou `null` (vymaže sa), preto sa taká zmena zapisuje
 * priamo cez konštruktor: `new CatalogProduct('sku-1', ['brand' => null])`.
 */
final class CatalogProduct implements \JsonSerializable
{
    public const FIELDS = ['name', 'url', 'price', 'list_price', 'currency', 'in_stock', 'brand', 'category', 'description', 'image', 'params'];

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly array $fields = [],
    ) {
        if (trim($id) === '') {
            throw new ConfigurationException('The product id must not be empty.');
        }

        $unknown = array_diff(array_keys($fields), self::FIELDS);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'Unknown product fields: %s. Supported are: %s.',
                implode(', ', $unknown),
                implode(', ', self::FIELDS),
            ));
        }
    }

    /**
     * @param array<string, scalar> $params facetovateľné atribúty, napr. `['farba' => 'modrá']`
     */
    public static function create(
        string $id,
        string $name,
        string $url,
        ?float $price = null,
        ?bool $inStock = null,
        ?string $brand = null,
        ?string $category = null,
        ?string $image = null,
        ?string $description = null,
        ?float $listPrice = null,
        ?string $currency = null,
        array $params = [],
    ): self {
        $fields = ['name' => $name, 'url' => $url];

        foreach ([
            'price' => $price,
            'in_stock' => $inStock,
            'brand' => $brand,
            'category' => $category,
            'image' => $image,
            'description' => $description,
            'list_price' => $listPrice,
            'currency' => $currency,
        ] as $field => $value) {
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        if ($params !== []) {
            $fields['params'] = $params;
        }

        return new self($id, $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id] + $this->fields;
    }
}
