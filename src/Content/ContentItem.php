<?php

declare(strict_types=1);

namespace Metty\Client\Content;

use Metty\Client\Exception\ConfigurationException;

/**
 * Objekt katalógu tak, ako ho prijíma `/v1/content`.
 *
 * Typované DTO namiesto asociatívnych polí: integrátor dostane chybu od IDE, nie z produkcie.
 */
final class ContentItem implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly string $identity,
        public readonly array $fields = [],
        public readonly ?string $generation = null,
        public readonly ?\DateTimeInterface $activeFrom = null,
        public readonly ?\DateTimeInterface $activeTo = null,
    ) {
        if (trim($identity) === '') {
            throw new ConfigurationException('The identity must not be empty.');
        }
    }

    /**
     * Vytvorí objekt s povinnými poľami produktu.
     *
     * @param array<string, mixed> $extraFields
     */
    public static function product(
        string $identity,
        string $title,
        string $webUrl,
        ?float $price = null,
        ?int $availability = null,
        ?string $brand = null,
        array $extraFields = [],
    ): self {
        $fields = ['title' => $title, 'web_url' => $webUrl] + $extraFields;

        if ($price !== null) {
            $fields['price'] = $price;
        }

        if ($availability !== null) {
            $fields['availability'] = $availability;
        }

        if ($brand !== null) {
            $fields['brand'] = $brand;
        }

        return new self($identity, $fields);
    }

    public function withGeneration(string $generation): self
    {
        return new self($this->identity, $this->fields, $generation, $this->activeFrom, $this->activeTo);
    }

    public function withActiveWindow(?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        return new self($this->identity, $this->fields, $this->generation, $from, $to);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'identity' => $this->identity,
            'type' => 'item',
            'fields' => (object) $this->fields,
        ];

        if ($this->generation !== null) {
            $payload['generation'] = $this->generation;
        }

        if ($this->activeFrom !== null) {
            $payload['active_from'] = $this->activeFrom->format(DATE_ATOM);
        }

        if ($this->activeTo !== null) {
            $payload['active_to'] = $this->activeTo->format(DATE_ATOM);
        }

        return $payload;
    }
}
