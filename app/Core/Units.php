<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Unit conversion + display for body/physical metrics.
 *
 * Storage is ALWAYS SI (kg, cm). A user's `units` preference ('metric' |
 * 'imperial') only affects how a stored value is shown and how raw input is
 * interpreted on the way in — never what lands in the database. Keeping one
 * canonical unit in storage is what makes averages, charts and cross-user
 * comparisons correct without a conversion at every read.
 */
final class Units
{
    private const KG_PER_LB = 0.45359237;
    private const CM_PER_IN = 2.54;

    public static function isImperial(?string $units): bool
    {
        return $units === 'imperial';
    }

    // ── input → SI (canonical for storage) ──────────────────────────────────

    /** A weight entered in the user's unit → kg. */
    public static function toKg(float $value, ?string $units): float
    {
        $kg = self::isImperial($units) ? $value * self::KG_PER_LB : $value;

        return round($kg, 2);
    }

    /**
     * Height → cm. Metric: $primary is cm ($secondary ignored).
     * Imperial: $primary is feet, $secondary is inches.
     */
    public static function toCm(float $primary, float $secondary, ?string $units): float
    {
        if (self::isImperial($units)) {
            $inches = ($primary * 12) + $secondary;

            return round($inches * self::CM_PER_IN, 1);
        }

        return round($primary, 1);
    }

    // ── SI → display in the user's unit ─────────────────────────────────────

    /** kg → the user's weight unit, rounded for display. */
    public static function weightDisplay(?float $kg, ?string $units): ?float
    {
        if ($kg === null) {
            return null;
        }

        return self::isImperial($units) ? round($kg / self::KG_PER_LB, 1) : round($kg, 1);
    }

    /**
     * cm → the user's height unit.
     * Metric → ['cm' => 178.0]. Imperial → ['ft' => 5, 'in' => 10].
     *
     * @return array<string, int|float>
     */
    public static function heightDisplay(?float $cm, ?string $units): array
    {
        if ($cm === null) {
            return self::isImperial($units) ? ['ft' => 0, 'in' => 0] : ['cm' => 0];
        }

        if (self::isImperial($units)) {
            $totalIn = $cm / self::CM_PER_IN;
            $ft = (int) floor($totalIn / 12);
            $in = (int) round($totalIn - $ft * 12);
            if ($in === 12) {   // rounding carry: 5'12" is 6'0"
                $ft++;
                $in = 0;
            }

            return ['ft' => $ft, 'in' => $in];
        }

        return ['cm' => round($cm, 1)];
    }

    public static function weightLabel(?string $units): string
    {
        return self::isImperial($units) ? 'lb' : 'kg';
    }

    public static function heightLabel(?string $units): string
    {
        return self::isImperial($units) ? 'ft/in' : 'cm';
    }
}
