<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Stats builder service.
GPLv2+
-------------------------------------------------------------------------
 */

/**
 * Computes per-user ticket statistics for a given calendar month
 * (the user being the *requester*).
 *
 *  - created : tickets opened by the user within the period
 *  - solved  : tickets requested by the user solved within the period
 *  - closed  : tickets requested by the user closed within the period
 *  - open    : tickets requested by the user still not closed at period end
 */
class PluginMonthlydigestStatsBuilder
{
    /**
     * Stats over a window of N months ending with the previous full month.
     *
     * @param int    $usersId    GLPI user id
     * @param string $periodKey  Oldest YYYY-MM of the window
     * @param int    $monthsBack 1..12, how many months are aggregated
     *
     * @return array{
     *     created:int, solved:int, closed:int, open:int,
     *     period:string, period_start:string, period_end:string,
     *     months_back:int, month_label:string
     * }
     */
    public static function forUserAndPeriod(int $usersId, string $periodKey, int $monthsBack = 1): array
    {
        $monthsBack = max(1, min(12, $monthsBack));

        // Start = first day of $periodKey
        [$start, $afterFirst] = self::periodBounds($periodKey);
        // End = first day of the month AFTER the last month in the window
        $endMonth = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start)
            ->modify('+' . $monthsBack . ' months');
        $end = $endMonth->format('Y-m-d H:i:s');

        $created = self::countAsRequester($usersId, [
            ['glpi_tickets.date'      => ['>=', $start]],
            ['glpi_tickets.date'      => ['<',  $end]],
            'glpi_tickets.is_deleted' => 0,
        ]);

        $solved = self::countAsRequester($usersId, [
            ['glpi_tickets.solvedate' => ['>=', $start]],
            ['glpi_tickets.solvedate' => ['<',  $end]],
            'glpi_tickets.is_deleted' => 0,
        ]);

        $closed = self::countAsRequester($usersId, [
            ['glpi_tickets.closedate' => ['>=', $start]],
            ['glpi_tickets.closedate' => ['<',  $end]],
            'glpi_tickets.is_deleted' => 0,
        ]);

        $open = self::countAsRequester($usersId, [
            ['glpi_tickets.status' => ['<>', CommonITILObject::CLOSED]],
            ['glpi_tickets.date'   => ['<',  $end]],
            'glpi_tickets.is_deleted' => 0,
        ]);

        return [
            'created'      => $created,
            'solved'       => $solved,
            'closed'       => $closed,
            'open'         => $open,
            'period'       => $periodKey,
            'period_start' => $start,
            'period_end'   => $end,
            'months_back'  => $monthsBack,
            'month_label'  => self::monthLabel($periodKey),
        ];
    }

    /**
     * IDs of active, non-deleted users that requested at least one ticket
     * within the N-month period ending with $periodKey's last month.
     * Sorted ASC for stable batching.
     *
     * @return array<int, int>
     */
    public static function userIdsWithActivity(string $periodKey, int $monthsBack = 1): array
    {
        global $DB;
        $monthsBack = max(1, min(12, $monthsBack));
        [$start, $_] = self::periodBounds($periodKey);
        $end = (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start))
            ->modify('+' . $monthsBack . ' months')
            ->format('Y-m-d H:i:s');

        $res = $DB->request([
            'SELECT'   => ['glpi_tickets_users.users_id'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => ['glpi_tickets_users' => 'tickets_id', 'glpi_tickets' => 'id'],
                ],
                'glpi_users' => [
                    'ON' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id'],
                ],
            ],
            'WHERE' => [
                'glpi_tickets_users.type' => CommonITILActor::REQUESTER,
                ['glpi_tickets.date'      => ['>=', $start]],
                ['glpi_tickets.date'      => ['<',  $end]],
                'glpi_tickets.is_deleted' => 0,
                'glpi_users.is_deleted'   => 0,
                'glpi_users.is_active'    => 1,
            ],
            'ORDER' => ['glpi_tickets_users.users_id ASC'],
        ]);

        $ids = [];
        foreach ($res as $row) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    /**
     * Active users that have requested ANY ticket ever — used when admin chooses
     * to include zero-activity users in the digest run.
     *
     * @return array<int, int>
     */
    public static function allActiveRequesterIds(): array
    {
        global $DB;
        $res = $DB->request([
            'SELECT'   => ['glpi_tickets_users.users_id'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id'],
                ],
            ],
            'WHERE' => [
                'glpi_tickets_users.type' => CommonITILActor::REQUESTER,
                'glpi_users.is_deleted'   => 0,
                'glpi_users.is_active'    => 1,
            ],
            'ORDER' => ['glpi_tickets_users.users_id ASC'],
        ]);

        $ids = [];
        foreach ($res as $row) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    /**
     * "Previous month" YYYY-MM relative to today (default = now).
     */
    public static function previousMonth(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');
        return $now->modify('first day of last month')->format('Y-m');
    }

    /**
     * Identifier of the trailing period of N months ending with the previous full month.
     * Returns the FIRST (oldest) month of the window in YYYY-MM. Used as the canonical
     * key for SentLog idempotency.
     *
     * Examples (today = 2026-06-15):
     *   monthsBack=1 → "2026-05"        (May only)
     *   monthsBack=2 → "2026-04"        (Apr-May)
     *   monthsBack=3 → "2026-03"        (Mar-May)
     */
    public static function previousPeriodKey(int $monthsBack = 1, ?\DateTimeImmutable $now = null): string
    {
        $monthsBack = max(1, min(12, $monthsBack));
        $now ??= new \DateTimeImmutable('now');
        return $now
            ->modify('first day of last month')
            ->modify('-' . ($monthsBack - 1) . ' months')
            ->format('Y-m');
    }

    /**
     * Return [start, end) for the given YYYY-MM as 'Y-m-d H:i:s'.
     *
     * @return array{0:string, 1:string}
     */
    public static function periodBounds(string $periodYYYYMM): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodYYYYMM)) {
            throw new \InvalidArgumentException("Bad period: $periodYYYYMM");
        }
        $start = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $periodYYYYMM . '-01 00:00:00'
        );
        $end = $start->modify('+1 month');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * Localised label for an N-month window.
     *  - 1 month  → "April 2026"
     *  - 2 months → "March–April 2026"
     *  - 3 months → "February–April 2026"
     *  - cross-year → "December 2025–February 2026"
     */
    public static function rangeLabel(string $periodKey, int $monthsBack = 1, ?string $locale = null): string
    {
        $monthsBack = max(1, min(12, $monthsBack));
        if ($monthsBack === 1) {
            return self::monthLabel($periodKey, $locale);
        }
        [$start] = self::periodBounds($periodKey);
        $endMonth = (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start))
            ->modify('+' . ($monthsBack - 1) . ' months')
            ->format('Y-m');

        $startLabel = self::monthLabel($periodKey, $locale);
        $endLabel   = self::monthLabel($endMonth, $locale);
        return $startLabel . '–' . $endLabel;
    }

    /**
     * Localised month name (e.g. "May 2026" / "май 2026"). Uses IntlDateFormatter
     * if available, else falls back to date().
     */
    public static function monthLabel(string $periodYYYYMM, ?string $locale = null): string
    {
        [$start] = self::periodBounds($periodYYYYMM);
        $ts = strtotime($start);
        if (class_exists('IntlDateFormatter') && $locale !== null) {
            $fmt = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                null,
                null,
                'LLLL yyyy'
            );
            return (string) $fmt->format($ts);
        }
        return date('F Y', $ts);
    }

    /**
     * COUNT(*) of tickets where the given user is REQUESTER plus the extra WHERE.
     *
     * @param array<int|string, mixed> $extra
     */
    private static function countAsRequester(int $usersId, array $extra): int
    {
        global $DB;
        $where = array_merge(
            [
                'glpi_tickets_users.users_id' => $usersId,
                'glpi_tickets_users.type'     => CommonITILActor::REQUESTER,
            ],
            $extra
        );
        $row = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_tickets_users' => [
                    'ON' => ['glpi_tickets_users' => 'tickets_id', 'glpi_tickets' => 'id'],
                ],
            ],
            'WHERE'      => $where,
        ])->current();
        return (int) ($row['cpt'] ?? 0);
    }
}
