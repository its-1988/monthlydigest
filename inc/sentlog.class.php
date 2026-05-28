<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Sent log service (idempotency).
GPLv2+
-------------------------------------------------------------------------
 */

/**
 * Records that a digest has been queued/sent for a (user, period).
 * Used to make cron runs idempotent: if a row already exists, we skip.
 */
class PluginMonthlydigestSentLog
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public static function table(): string
    {
        return PluginMonthlydigestInstaller::TABLE_SENT_LOG;
    }

    public static function wasSent(int $usersId, string $period): bool
    {
        global $DB;
        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::table(),
            'WHERE' => ['users_id' => $usersId, 'period' => $period],
        ])->current();
        return (int) ($row['cpt'] ?? 0) > 0;
    }

    public static function record(
        int $usersId,
        string $period,
        string $status,
        string $recipient = '',
        string $subject = '',
        ?string $error = null
    ): void {
        global $DB;
        $DB->insert(self::table(), [
            'users_id'  => $usersId,
            'period'    => $period,
            'status'    => $status,
            'recipient' => mb_substr($recipient, 0, 255),
            'subject'   => mb_substr($subject, 0, 255),
            'error'     => $error,
        ]);
    }
}
