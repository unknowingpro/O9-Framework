<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Base API resource/transformer: shapes a raw row (model array) into a stable, safe response
 * representation. Subclasses implement toArray(); use make() for one item and collection() for a
 * list. Centralizing the shape here keeps response contracts consistent and prevents sensitive
 * columns from leaking by omission (the historical `unset($user['password_hash'])`-everywhere bug).
 *
 * @phpstan-consistent-constructor
 */
abstract class Resource
{
    /** @param array<string,mixed> $data */
    public function __construct(protected array $data) {}

    /** @return array<string,mixed> */
    abstract public function toArray(): array;

    /** @param array<string,mixed> $item */
    public static function make(array $item): static
    {
        return new static($item);
    }

    /**
     * @param iterable<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function collection(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = (new static((array) $item))->toArray();
        }
        return $out;
    }
}
