<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Digest sender service.
GPLv2+
-------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

/**
 * Builds and dispatches the monthly digest email for one user via GLPI's
 * QueuedNotification (so SMTP, retries, DKIM and throttling are owned by
 * GLPI core).
 *
 *  - Renders HTML + plain-text bodies via Twig
 *  - Respects user opt-out
 *  - Respects test-mode (single hardcoded recipient)
 *  - Idempotent: skips if SentLog already has a row for (user, period)
 */
class PluginMonthlydigestDigestSender
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? Config::getConfigurationValues('plugin:monthlydigest');
    }

    /**
     * Build + queue the digest for a single user.
     *
     * @return string Status from PluginMonthlydigestSentLog::STATUS_*
     */
    public function dispatch(int $usersId, string $period): string
    {
        if (PluginMonthlydigestSentLog::wasSent($usersId, $period)) {
            return PluginMonthlydigestSentLog::STATUS_SKIPPED;
        }

        if (PluginMonthlydigestUserPref::isOptedOut($usersId)) {
            PluginMonthlydigestSentLog::record(
                $usersId,
                $period,
                PluginMonthlydigestSentLog::STATUS_SKIPPED,
                '',
                '',
                'user opted-out'
            );
            return PluginMonthlydigestSentLog::STATUS_SKIPPED;
        }

        $user = new User();
        if (!$user->getFromDB($usersId)) {
            return PluginMonthlydigestSentLog::STATUS_SKIPPED;
        }
        if ((int) ($user->fields['is_deleted'] ?? 0) === 1
            || (int) ($user->fields['is_active'] ?? 1) === 0) {
            return PluginMonthlydigestSentLog::STATUS_SKIPPED;
        }

        // Resolve recipient (test-mode overrides)
        $recipient = $this->resolveRecipient($user);
        if ($recipient === '') {
            PluginMonthlydigestSentLog::record(
                $usersId,
                $period,
                PluginMonthlydigestSentLog::STATUS_SKIPPED,
                '',
                '',
                'no recipient email'
            );
            return PluginMonthlydigestSentLog::STATUS_SKIPPED;
        }

        // Compute stats across the configured N-month window
        $monthsBack = max(1, min(3, (int) ($this->config['period_months'] ?? 1)));
        $stats = PluginMonthlydigestStatsBuilder::forUserAndPeriod($usersId, $period, $monthsBack);

        // Skip if all metrics are zero AND admin chose to suppress zero-users
        $allZero = ($stats['created'] + $stats['solved'] + $stats['closed'] + $stats['open']) === 0;
        $includeZero = (int) ($this->config['include_zero_users'] ?? 0) === 1;
        if ($allZero && !$includeZero) {
            PluginMonthlydigestSentLog::record(
                $usersId,
                $period,
                PluginMonthlydigestSentLog::STATUS_SKIPPED,
                $recipient,
                '',
                'all metrics zero'
            );
            return PluginMonthlydigestSentLog::STATUS_SKIPPED;
        }

        // Localised render in the user's preferred language (fallback EN)
        $locale = $this->resolveUserLocale($user);
        $stats['month_label'] = PluginMonthlydigestStatsBuilder::rangeLabel($period, $monthsBack, $locale);

        $vars = $this->buildTemplateVars($user, $stats, $locale);

        // Render bodies — load customisable templates from DB if present, else file
        try {
            $html = PluginMonthlydigestTemplate::renderByKey(
                PluginMonthlydigestTemplate::KEY_HTML,
                $vars
            );
            $text = PluginMonthlydigestTemplate::renderByKey(
                PluginMonthlydigestTemplate::KEY_TEXT,
                $vars
            );
        } catch (\Throwable $e) {
            PluginMonthlydigestSentLog::record(
                $usersId,
                $period,
                PluginMonthlydigestSentLog::STATUS_FAILED,
                $recipient,
                '',
                'template error: ' . $e->getMessage()
            );
            Toolbox::logError('MonthlyDigest: template render failed - ' . $e->getMessage());
            return PluginMonthlydigestSentLog::STATUS_FAILED;
        }

        $subject = $this->buildSubject($vars);
        $sender  = self::resolveSender();

        // Queue via GLPI's QueuedNotification — picked up by core cron `queuednotification`.
        // IMPORTANT (GLPI 11.0.7):
        //   - subject lives in the `name` column (NOT `subject`)
        //   - mode must be the string 'mailing' (Notification_NotificationTemplate::MODE_MAIL)
        //   - cronQueuedNotification dispatches by mode: NotificationEventMailing::send(row)
        // Refs:
        //   src/Notification_NotificationTemplate.php:53  MODE_MAIL = 'mailing'
        //   src/NotificationEventMailing.php              reads $row['name'] for the subject
        $queued = new QueuedNotification();
        $ok = $queued->add([
            'entities_id' => 0,
            'itemtype'    => 'User',
            'items_id'    => $usersId,
            'event'       => 'monthly_digest',
            'mode'        => \Notification_NotificationTemplate::MODE_MAIL,
            'name'        => $subject,
            'sender'      => $sender['email'],
            'sendername'  => $sender['name'],
            'recipient'   => $recipient,
            'recipientname' => trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? '')),
            'body_html'   => $html,
            'body_text'   => $text,
            'headers'     => '',
            'documents'   => '',
        ]);

        $status = $ok ? PluginMonthlydigestSentLog::STATUS_QUEUED
                      : PluginMonthlydigestSentLog::STATUS_FAILED;

        PluginMonthlydigestSentLog::record(
            $usersId,
            $period,
            $status,
            $recipient,
            $subject,
            $ok ? null : 'QueuedNotification::add returned false'
        );
        return $status;
    }

    /**
     * Render preview-only (no queueing). Returns ['html', 'text', 'subject', 'recipient'].
     */
    public function renderPreview(int $usersId, string $period): array
    {
        $user = new User();
        if (!$user->getFromDB($usersId)) {
            return ['html' => '', 'text' => '', 'subject' => '', 'recipient' => ''];
        }
        $monthsBack = max(1, min(3, (int) ($this->config['period_months'] ?? 1)));
        $stats = PluginMonthlydigestStatsBuilder::forUserAndPeriod($usersId, $period, $monthsBack);
        $locale = $this->resolveUserLocale($user);
        $stats['month_label'] = PluginMonthlydigestStatsBuilder::rangeLabel($period, $monthsBack, $locale);
        $vars = $this->buildTemplateVars($user, $stats, $locale);

        $html = PluginMonthlydigestTemplate::renderByKey(
            PluginMonthlydigestTemplate::KEY_HTML,
            $vars
        );
        $text = PluginMonthlydigestTemplate::renderByKey(
            PluginMonthlydigestTemplate::KEY_TEXT,
            $vars
        );
        return [
            'html'      => $html,
            'text'      => $text,
            'subject'   => $this->buildSubject($vars),
            'recipient' => $this->resolveRecipient($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTemplateVars(User $user, array $stats, string $locale): array
    {
        global $CFG_GLPI;
        $token = PluginMonthlydigestUserPref::getOrCreateToken((int) $user->getID());
        $unsubUrl = ($CFG_GLPI['url_base'] ?? '')
            . '/plugins/monthlydigest/front/unsubscribe.php?token=' . urlencode($token);

        return [
            'user'         => [
                'id'        => (int) $user->getID(),
                'firstname' => (string) ($user->fields['firstname'] ?? ''),
                'realname'  => (string) ($user->fields['realname'] ?? ''),
                'name'      => trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? ''))
                                ?: (string) $user->fields['name'],
            ],
            'stats'        => $stats,
            'period_label' => $stats['month_label'],
            'glpi_url'     => $CFG_GLPI['url_base'] ?? '',
            'unsubscribe_url' => $unsubUrl,
            'locale'       => $locale,
        ];
    }

    private function buildSubject(array $vars): string
    {
        $tpl = (string) ($this->config['subject_template'] ?? '');
        if ($tpl === '') {
            $tpl = __('Your tickets — %s', 'monthlydigest');
        }
        return sprintf($tpl, $vars['period_label']);
    }

    /**
     * Test-mode forces all mails to one recipient; otherwise use the user's primary email.
     */
    private function resolveRecipient(User $user): string
    {
        if ((int) ($this->config['test_mode'] ?? 0) === 1) {
            $r = trim((string) ($this->config['test_recipient'] ?? ''));
            return filter_var($r, FILTER_VALIDATE_EMAIL) ? $r : '';
        }
        return self::primaryEmail((int) $user->getID());
    }

    public static function primaryEmail(int $usersId): string
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => $usersId, 'is_default' => 1],
            'LIMIT'  => 1,
        ])->current();
        if ($row && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            return (string) $row['email'];
        }
        // Fallback: any email
        $row = $DB->request([
            'SELECT' => ['email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => $usersId],
            'LIMIT'  => 1,
        ])->current();
        return $row && filter_var($row['email'], FILTER_VALIDATE_EMAIL) ? (string) $row['email'] : '';
    }

    /**
     * @return array{email:string, name:string}
     */
    public static function resolveSender(): array
    {
        global $CFG_GLPI;
        return [
            'email' => (string) ($CFG_GLPI['from_email']      ?? $CFG_GLPI['admin_email'] ?? ''),
            'name'  => (string) ($CFG_GLPI['from_email_name'] ?? $CFG_GLPI['admin_email_name'] ?? 'GLPI'),
        ];
    }

    private function resolveUserLocale(User $user): string
    {
        $lang = (string) ($user->fields['language'] ?? '');
        if ($lang === '') {
            global $CFG_GLPI;
            $lang = (string) ($CFG_GLPI['language'] ?? 'en_GB');
        }
        return $lang;
    }
}
