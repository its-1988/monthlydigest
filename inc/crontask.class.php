<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Native CronTask handler.
GPLv2+
-------------------------------------------------------------------------
 */

/**
 * Daily cron that fires the actual digest dispatch on the configured
 * day of month (default 1st). Idempotent — re-running on the same day
 * is safe thanks to PluginMonthlydigestSentLog::wasSent().
 */
class PluginMonthlydigestCrontask extends CommonDBTM
{
    public static function cronInfo($name): array
    {
        return [
            'description' => __('Send monthly ticket digest emails', 'monthlydigest'),
            'parameter'   => __('Max users processed per run (0 = unlimited)', 'monthlydigest'),
        ];
    }

    /**
     * @return int 0: nothing, 1: done, -1: error
     */
    public static function cronMonthlyDigestSend(CronTask $task): int
    {
        $config = Config::getConfigurationValues('plugin:monthlydigest');
        if ((int) ($config['enabled'] ?? 0) !== 1) {
            $task->log('plugin disabled — exiting');
            return 0;
        }

        $today = (int) date('j');
        $sendDay = max(1, min(28, (int) ($config['send_day_of_month'] ?? 1)));
        if ($today !== $sendDay) {
            $task->log("not the send day (today=$today, configured=$sendDay)");
            return 0;
        }

        $monthsBack = max(1, min(3, (int) ($config['period_months'] ?? 1)));
        $period = PluginMonthlydigestStatsBuilder::previousPeriodKey($monthsBack);
        $task->log("preparing digest for period $period (window=$monthsBack months)");

        $userIds = (int) ($config['include_zero_users'] ?? 0) === 1
            ? PluginMonthlydigestStatsBuilder::allActiveRequesterIds()
            : PluginMonthlydigestStatsBuilder::userIdsWithActivity($period, $monthsBack);

        $maxPerRun = max(0, (int) $task->fields['param']);
        $sender = new PluginMonthlydigestDigestSender($config);
        $processed = 0;
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($userIds as $uid) {
            if ($maxPerRun > 0 && $processed >= $maxPerRun) {
                $task->log("hit per-run cap ($maxPerRun) — will resume on next run");
                break;
            }
            $status = $sender->dispatch($uid, $period);
            match ($status) {
                PluginMonthlydigestSentLog::STATUS_QUEUED  => $queued++,
                PluginMonthlydigestSentLog::STATUS_FAILED  => $failed++,
                default                                     => $skipped++,
            };
            $processed++;
            $task->addVolume(1);
        }

        $task->log("processed=$processed queued=$queued skipped=$skipped failed=$failed");
        return $processed > 0 ? 1 : 0;
    }
}
