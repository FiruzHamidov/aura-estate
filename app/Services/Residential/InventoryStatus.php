<?php

namespace App\Services\Residential;

/** One compatibility adapter for the two old, sometimes contradictory status fields. */
final class InventoryStatus
{
    public const PUBLICATION = ['draft', 'pending', 'published', 'rejected', 'archived'];

    public const AVAILABILITY = ['available', 'reserved', 'sold', 'withdrawn'];

    public static function building(array $row): string
    {
        return $row['publication_status'] ?? match ($row['moderation_status'] ?? null) {
            'approved' => 'published', 'pending' => 'pending', 'rejected' => 'rejected',
            'deleted' => 'archived', default => 'draft',
        };
    }

    public static function unit(array $row): array
    {
        if (isset($row['publication_status'], $row['availability_status'])) {
            return [$row['publication_status'], $row['availability_status']];
        }
        $status = $row['moderation_status'] ?? null;
        $available = (bool) ($row['is_available'] ?? false);

        return match (true) {
            in_array($status, ['available', 'approved'], true) && $available => ['published', 'available'],
            in_array($status, ['reserved', 'sold'], true) && ! $available => ['published', $status],
            $status === 'rejected' => ['rejected', 'withdrawn'],
            $status === 'deleted' => ['archived', 'withdrawn'],
            $status === 'pending' => ['pending', 'withdrawn'],
            default => ['draft', 'withdrawn'], // Contradictions require a human decision, not publication.
        };
    }

    public static function legacyBuilding(string $publication): string
    {
        return match ($publication) {
            'published' => 'approved', 'archived' => 'deleted', default => $publication
        };
    }

    public static function legacyUnit(string $publication, string $availability): array
    {
        $public = $publication === 'published' && $availability !== 'withdrawn';

        return ['moderation_status' => $public ? $availability : 'pending', 'is_available' => $public && $availability === 'available'];
    }

    public static function rooms(array $row): ?int
    {
        // Old zero was the database default and does not prove that a unit is a studio.
        return isset($row['rooms']) ? (int) $row['rooms'] : (($row['bedrooms'] ?? 0) > 0 ? (int) $row['bedrooms'] : null);
    }

    public static function validateAliases(array $input, string $publication, ?string $availability = null): void
    {
        if (! isset($input['publication_status']) && ! isset($input['availability_status'])) {
            return;
        }
        $legacy = $availability === null ? ['moderation_status' => self::legacyBuilding($publication)] : self::legacyUnit($publication, $availability);
        if (isset($input['moderation_status'])) {
            $actual = $input['moderation_status'] === 'approved' && $availability !== null ? 'available' : $input['moderation_status'];
            $valid = [$legacy['moderation_status']];
            if ($availability !== null && $publication !== 'published') {
                $valid[] = self::legacyBuilding($publication);
            }
            if (! in_array($actual, $valid, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['moderation_status' => 'Прежний статус противоречит статусу публикации или доступности. Передавайте согласованные значения либо только новые поля.']);
            }
        }
        if ($availability !== null && isset($input['is_available']) && (bool) $input['is_available'] !== $legacy['is_available']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['is_available' => 'Прежний признак доступности противоречит новым статусам.']);
        }
    }
}
