<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

/**
 * Turns ids from the database or a request (strings, ints, junk) into a clean list of positive ints.
 */
final class Ids
{
    /**
     * @param iterable<mixed> $values
     * @return list<int>
     */
    public static function of(iterable $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = self::one($value);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** One id, or 0 when it isn't a positive whole number. */
    public static function one(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
