<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Digest sender service.
GPLv2+
-------------------------------------------------------------------------
 */

/**
 * Builds and dispatches the monthly digest email for one user via GLPI's
 * QueuedNotification (so SMTP, retries, DKIM and throttling are owned by
 * GLPI core).
 *
 *  - Resolves the right NotificationTemplate translation by user's language
 *  - Substitutes ##tag## placeholders with our stats
 *  - Respects user opt-out
 *  - Respects test-mode (single hardcoded recipient)
 *  - Idempotent: skips if SentLog already has a row for (user, period)
 *
 * NOTE: we intentionally do NOT use NotificationEvent::raiseEvent because the
 * per-user idempotency, opt-out and zero-skip logic doesn't map cleanly onto
 * NotificationTarget's batch model. We borrow the storage (NotificationTemplate
 * + translations) and the queueing pipeline (QueuedNotification → mailing cron)
 * but keep the dispatch loop here.
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

        // Resolve the right translation row from the standard GLPI tables and
        // substitute placeholders. If the translation is missing entirely we
        // fall through to the shipped seed (see PluginMonthlydigestTemplate).
        $translation = PluginMonthlydigestTemplate::getTranslation($locale);
        if ($translation === null) {
            PluginMonthlydigestSentLog::record(
                $usersId,
                $period,
                PluginMonthlydigestSentLog::STATUS_FAILED,
                $recipient,
                '',
                'no notification template translation available'
            );
            Toolbox::logError('MonthlyDigest: NotificationTemplate row missing — re-install the plugin');
            return PluginMonthlydigestSentLog::STATUS_FAILED;
        }

        $html    = PluginMonthlydigestTemplate::substitute($translation['content_html'], $vars);
        $text    = PluginMonthlydigestTemplate::substitute($translation['content_text'], $vars);
        $subject = $this->buildSubject($vars, $translation['subject']);
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
            'event'       => PluginMonthlydigestTemplate::EVENT,
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
     *
     * @return array{html:string, text:string, subject:string, recipient:string}
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

        $translation = PluginMonthlydigestTemplate::getTranslation($locale);
        if ($translation === null) {
            return ['html' => '', 'text' => '', 'subject' => '', 'recipient' => ''];
        }
        return [
            'html'      => PluginMonthlydigestTemplate::substitute($translation['content_html'], $vars),
            'text'      => PluginMonthlydigestTemplate::substitute($translation['content_text'], $vars),
            'subject'   => $this->buildSubject($vars, $translation['subject']),
            'recipient' => $this->resolveRecipient($user),
        ];
    }

    /**
     * Flat ##tag## variables map. Keys are tag names without the surrounding
     * ## markers; PluginMonthlydigestTemplate::substitute() wraps them.
     *
     * @return array<string, scalar>
     */
    private function buildTemplateVars(User $user, array $stats, string $locale): array
    {
        global $CFG_GLPI;
        $token = PluginMonthlydigestUserPref::getOrCreateToken((int) $user->getID());
        $glpiUrl  = (string) ($CFG_GLPI['url_base'] ?? '');
        $unsubUrl = $glpiUrl . '/plugins/monthlydigest/front/unsubscribe.php?token=' . urlencode($token);

        $displayName = trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? ''));
        if ($displayName === '') {
            $displayName = (string) ($user->fields['name'] ?? '');
        }

        return [
            'user.id'           => (int) $user->getID(),
            'user.name'         => $displayName,
            'user.firstname'    => (string) ($user->fields['firstname'] ?? ''),
            'user.realname'     => (string) ($user->fields['realname'] ?? ''),
            'stats.created'     => (int) $stats['created'],
            'stats.solved'      => (int) $stats['solved'],
            'stats.closed'      => (int) $stats['closed'],
            'stats.open'        => (int) $stats['open'],
            'stats.period'      => (string) $stats['period'],
            'stats.months_back' => (int) $stats['months_back'],
            'period_label'      => (string) $stats['month_label'],
            'glpi_url'          => $glpiUrl,
            'cta_url'           => $glpiUrl . '/front/ticket.php',
            'unsubscribe_url'   => $unsubUrl,
            'lang'              => substr($locale, 0, 2),
            'locale'            => $locale,
        ];
    }

    /**
     * Subject precedence:
     *   1. plugin config `subject_template` if set (admin override, %s = period)
     *   2. NotificationTemplateTranslation.subject (##tag## substituted)
     *   3. Hardcoded fallback
     *
     * @param array<string, scalar> $vars
     */
    private function buildSubject(array $vars, string $templateSubject): string
    {
        $override = trim((string) ($this->config['subject_template'] ?? ''));
        if ($override !== '') {
            return sprintf($override, (string) $vars['period_label']);
        }
        if ($templateSubject !== '') {
            return PluginMonthlydigestTemplate::substitute($templateSubject, $vars);
        }
        return sprintf(__('Your tickets — %s', 'monthlydigest'), (string) $vars['period_label']);
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
