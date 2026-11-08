<?php

declare(strict_types=1);

namespace App\Domains\Platform\Lifecycle;

use Illuminate\Support\Facades\Schema;

/**
 * The tables holding a hotel's data (those with a hotel_id column).
 */
final class HotelTables
{
    /** Kept after deletion: invoices are legal records; the closure itself proves the deletion. */
    public const KEEP = ['invoices', 'hotel_closures', 'payment_checkouts', 'pending_signups', 'legal_acceptances', 'subscriptions'];

    /** Never exported: secrets and internal platform records. */
    public const NOT_EXPORTED = ['whatsapp_accounts', 'hotel_notes', 'support_sessions', 'email_identities', 'payment_checkouts', 'jobs', 'failed_jobs'];

    /**
     * Tables to empty when a hotel is deleted, children before parents so
     * foreign keys never block: rows of tables without hotel_id that point
     * at a hotel table first, then hotel tables in dependency order.
     *
     * @return list<array{0: string, 1: array{column: string, parent: string, parent_column: string}|null}>
     */
    public static function deletionOrder(): array
    {
        $tables = array_values(array_diff(self::all(), self::KEEP));
        $children = [];

        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if (in_array($name, $tables, true) || in_array($name, self::KEEP, true) || $name === 'hotels' || Schema::hasColumn($name, 'hotel_id')) {
                continue;
            }
            foreach (Schema::getForeignKeys($name) as $fk) {
                if (in_array($fk['foreign_table'], $tables, true) && count($fk['columns']) === 1) {
                    $children[] = [$name, ['column' => $fk['columns'][0], 'parent' => $fk['foreign_table'], 'parent_column' => $fk['foreign_columns'][0]]];
                }
            }
        }

        // Which hotel tables each hotel table points at.
        $refs = [];
        foreach ($tables as $table) {
            $refs[$table] = array_values(array_unique(array_filter(
                array_map(fn (array $fk): string => $fk['foreign_table'], Schema::getForeignKeys($table)),
                fn (string $parent): bool => $parent !== $table && in_array($parent, $tables, true),
            )));
        }

        $ordered = [];
        $remaining = $tables;
        while ($remaining !== []) {
            $next = array_values(array_filter($remaining, fn (string $t): bool => ! collect($remaining)->contains(fn (string $other): bool => $other !== $t && in_array($t, $refs[$other], true))));
            if ($next === []) {
                $next = $remaining; // a cycle: delete the rest as is
            }
            foreach ($next as $t) {
                $ordered[] = [$t, null];
            }
            $remaining = array_values(array_diff($remaining, $next));
        }

        return [...$children, ...$ordered];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $tables = array_map(fn (array $t): string => $t['name'], Schema::getTables());

        return array_values(array_filter($tables, fn (string $t): bool => $t !== 'hotels' && Schema::hasColumn($t, 'hotel_id')));
    }
}
