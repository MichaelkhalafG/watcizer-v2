<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Support\ManageText;
use App\Transform\Row;
use stdClass;

/**
 * What a banner is ACTUALLY doing right now (wave 4D, banners screen).
 *
 * The same idea as the promotions list, for the same reason: `is_active` is what somebody TYPED,
 * and a banner can be switched on and showing nothing because its window has closed or has not
 * opened. An operator should never have to open a row to know whether a customer can see it.
 *
 * Four states, ordered by how much they should worry the person looking at the list — the first
 * that applies is the headline:
 *
 *   1. `expired`   — the window has closed. It will not show again.
 *   2. `scheduled` — the window has not opened. Nothing is wrong.
 *   3. `inactive`  — switched off by hand.
 *   4. `running`   — on the site now.
 *
 * `expired` sits above `inactive` deliberately: a banner somebody switched off is a decision, while
 * one that quietly stopped on a date nobody remembers is a shop with an empty hero.
 */
final class BannerState
{
    public const RUNNING = 'running';

    public const SCHEDULED = 'scheduled';

    public const EXPIRED = 'expired';

    public const INACTIVE = 'inactive';

    /**
     * @param  stdClass  $row  a banner row carrying is_active, starts_at, ends_at
     * @return array{state: string, label: string, tone: string}
     */
    public static function of(stdClass $row, ?string $now = null): array
    {
        $now ??= now()->format('Y-m-d H:i:s');

        $startsAt = Row::nstr($row, 'starts_at');
        $endsAt = Row::nstr($row, 'ends_at');

        $state = match (true) {
            $endsAt !== null && $endsAt < $now => self::EXPIRED,
            $startsAt !== null && $startsAt > $now => self::SCHEDULED,
            ! Row::bool($row, 'is_active') => self::INACTIVE,
            default => self::RUNNING,
        };

        return ['state' => $state, 'label' => self::label($state), 'tone' => self::tone($state)];
    }

    /** Arabic, for a non-technical operator reading a list. */
    public static function label(string $state): string
    {
        return match ($state) {
            self::RUNNING => ManageText::t('banners.state_running', 'ظاهر الآن'),
            self::SCHEDULED => ManageText::t('banners.state_scheduled', 'مجدول'),
            self::EXPIRED => ManageText::t('banners.state_expired', 'انتهى'),
            // The banners screen already renders this state as `common.suspended`, so the row's
            // label and the filter option stay one string.
            self::INACTIVE => ManageText::t('common.suspended', 'موقوف'),
            default => $state,
        };
    }

    /**
     * `expired` is DESTRUCTIVE rather than neutral: a hero slot that stopped on a date nobody
     * remembers is the failure this column exists to surface. `inactive` is somebody's decision,
     * so it is quiet.
     */
    public static function tone(string $state): string
    {
        return match ($state) {
            self::RUNNING => 'success',
            self::SCHEDULED => 'default',
            self::EXPIRED => 'destructive',
            self::INACTIVE => 'neutral',
            default => 'outline',
        };
    }
}
