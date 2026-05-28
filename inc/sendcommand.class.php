<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — CLI send command.
GPLv2+
-------------------------------------------------------------------------
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually trigger the digest run from CLI.
 *
 *   php bin/console monthlydigest:send
 *   php bin/console monthlydigest:send --period=2026-05
 *   php bin/console monthlydigest:send --user=42 --dry-run
 *   php bin/console monthlydigest:send --force      # ignore today != send_day_of_month
 */
class PluginMonthlydigestSendCommand extends Command
{
    protected static $defaultName = 'monthlydigest:send';

    protected function configure(): void
    {
        $this
            ->setDescription('Send the monthly ticket digest to active users')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period YYYY-MM (default: previous month)')
            ->addOption('user',   'u', InputOption::VALUE_REQUIRED, 'Single user id (default: all eligible)')
            ->addOption('force',  'f', InputOption::VALUE_NONE,     'Ignore day-of-month + idempotency log')
            ->addOption('dry-run','d', InputOption::VALUE_NONE,     'Show what would be sent, do not queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Config::getConfigurationValues('plugin:monthlydigest');
        $monthsBack = max(1, min(3, (int) ($config['period_months'] ?? 1)));
        $period = (string) ($input->getOption('period')
            ?: PluginMonthlydigestStatsBuilder::previousPeriodKey($monthsBack));
        $force  = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $userId = $input->getOption('user') ? (int) $input->getOption('user') : null;

        $output->writeln("<info>== MonthlyDigest send ==</info>");
        $output->writeln("period: <info>$period</info>"
            . ($force ? " <comment>(force)</comment>" : "")
            . ($dryRun ? " <comment>(dry-run)</comment>" : ""));

        if (!$force && (int) ($config['enabled'] ?? 0) !== 1) {
            $output->writeln('<error>plugin disabled in config — use --force to override</error>');
            return Command::FAILURE;
        }

        if ($userId !== null) {
            $userIds = [$userId];
        } else {
            $userIds = (int) ($config['include_zero_users'] ?? 0) === 1
                ? PluginMonthlydigestStatsBuilder::allActiveRequesterIds()
                : PluginMonthlydigestStatsBuilder::userIdsWithActivity($period, $monthsBack);
        }
        $output->writeln('candidates: <info>' . count($userIds) . '</info>');

        $sender = new PluginMonthlydigestDigestSender($config);
        $counts = ['queued' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($userIds as $uid) {
            if ($force) {
                // Skip the idempotency guard by inserting a marker-less dispatch path:
                // simplest is to delete any prior log row for this user/period.
                global $DB;
                $DB->delete(PluginMonthlydigestSentLog::table(), [
                    'users_id' => $uid, 'period' => $period,
                ]);
            }
            if ($dryRun) {
                $preview = $sender->renderPreview($uid, $period);
                $output->writeln(sprintf(
                    '  user=%d → %s : %s',
                    $uid,
                    $preview['recipient'] ?: '<no email>',
                    $preview['subject']
                ));
                continue;
            }
            $status = $sender->dispatch($uid, $period);
            $output->writeln("  user=$uid : <comment>$status</comment>");
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        if (!$dryRun) {
            $output->writeln('');
            $output->writeln(sprintf(
                'Result: queued=<info>%d</info> skipped=<comment>%d</comment> failed=<error>%d</error>',
                $counts['queued']  ?? 0,
                $counts['skipped'] ?? 0,
                $counts['failed']  ?? 0,
            ));
        }
        return Command::SUCCESS;
    }
}
