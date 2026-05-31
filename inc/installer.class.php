<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Installer service.
GPLv2+
-------------------------------------------------------------------------
 */

/**
 * Builds the plugin's database schema, registers the GLPI CronTask and seeds
 * the GLPI-native NotificationTemplate + Notification rows used to deliver
 * the monthly digest emails.
 *
 * Tables (plugin-owned, history):
 *   - glpi_plugin_monthlydigest_sent_log : idempotency log (one row per user × period)
 *   - glpi_plugin_monthlydigest_userpref : per-user opt-out flag
 *
 * The legacy `glpi_plugin_monthlydigest_templates` table (introduced in 1.0.9
 * for the plugin-owned editor) is DROPPED on install if present. Custom bodies
 * are now stored in GLPI's standard glpi_notificationtemplatetranslations.
 */
class PluginMonthlydigestInstaller
{
    public const TABLE_SENT_LOG  = 'glpi_plugin_monthlydigest_sent_log';
    public const TABLE_USERPREF  = 'glpi_plugin_monthlydigest_userpref';
    public const TABLE_TEMPLATES = 'glpi_plugin_monthlydigest_templates'; // legacy, dropped on upgrade

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE_SENT_LOG)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE_SENT_LOG . "` (
                    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `users_id`  INT UNSIGNED NOT NULL,
                    `period`    CHAR(7) NOT NULL COMMENT 'YYYY-MM of the digest content',
                    `sent_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `status`    VARCHAR(20) NOT NULL DEFAULT 'queued',
                    `recipient` VARCHAR(255) NOT NULL DEFAULT '',
                    `subject`   VARCHAR(255) NOT NULL DEFAULT '',
                    `error`     TEXT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `user_period` (`users_id`, `period`),
                    INDEX `period` (`period`),
                    INDEX `sent_at` (`sent_at`)
                )
                COLLATE='utf8mb4_unicode_ci'
                ENGINE=InnoDB"
            );
        }

        if (!$DB->tableExists(self::TABLE_USERPREF)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE_USERPREF . "` (
                    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `users_id`  INT UNSIGNED NOT NULL,
                    `opt_out`   TINYINT(1) NOT NULL DEFAULT 0,
                    `unsubscribe_token` CHAR(40) NOT NULL DEFAULT '',
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `users_id` (`users_id`),
                    INDEX `opt_out` (`opt_out`)
                )
                COLLATE='utf8mb4_unicode_ci'
                ENGINE=InnoDB"
            );
        }

        // Drop the legacy plugin-owned templates table (1.0.9 → 1.1.0 migration).
        // Custom bodies in this table cannot be auto-migrated reliably because
        // they used Twig syntax — the new system uses ##tag## syntax. Admins
        // are warned in the changelog to re-apply customisations via the GLPI UI.
        if ($DB->tableExists(self::TABLE_TEMPLATES)) {
            $DB->doQuery("DROP TABLE `" . self::TABLE_TEMPLATES . "`");
        }

        // Register daily CronTask
        $cron = new CronTask();
        if (!$cron->getFromDBbyName('PluginMonthlydigestCrontask', 'MonthlyDigestSend')) {
            $cron->add([
                'itemtype'  => 'PluginMonthlydigestCrontask',
                'name'      => 'MonthlyDigestSend',
                'frequency' => DAY_TIMESTAMP,
                'param'     => 0,
                'state'     => 1,
                'mode'      => 1,   // CLI
            ]);
        }

        // Seed the standard GLPI notification: template + translations + Notification.
        self::installNotification();

        return true;
    }

    public static function uninstall(): bool
    {
        $cron = new CronTask();
        if ($cron->getFromDBbyName('PluginMonthlydigestCrontask', 'MonthlyDigestSend')) {
            $cron->delete(['id' => $cron->getID()]);
        }

        Config::deleteConfigurationValues('plugin:monthlydigest', [
            'enabled',
            'send_day_of_month',
            'send_hour',
            'batch_size',
            'test_mode',
            'test_recipient',
            'subject_template',
            'min_tickets_to_send',
            'include_zero_users',
            'period_months',
        ]);

        // Remove the GLPI-native Notification + NotificationTemplate rows.
        self::uninstallNotification();

        // Plugin-owned tables intentionally preserved (history). Drop manually if desired.
        return true;
    }

    /**
     * Create (or refresh) the canonical NotificationTemplate + Notification rows.
     *
     *   glpi_notificationtemplates           → one row, name = "Monthly Ticket Digest"
     *   glpi_notificationtemplatetranslations → one row per language ('', en_GB, ru_RU)
     *   glpi_notifications                    → one row, event = "monthly_digest"
     *   glpi_notifications_notificationtemplates → link row
     *
     * Idempotent — if the rows already exist, only missing translations are
     * created. Subject + content are NOT overwritten on re-install (admin
     * customisations survive plugin file replacement).
     */
    private static function installNotification(): void
    {
        global $DB;

        // 1. NotificationTemplate row
        $templateId = PluginMonthlydigestTemplate::getTemplateId();
        if ($templateId === null) {
            $tpl = new NotificationTemplate();
            $templateId = $tpl->add([
                'name'     => PluginMonthlydigestTemplate::NOTIFICATION_NAME,
                'itemtype' => PluginMonthlydigestTemplate::ITEMTYPE,
                'comment'  => 'Monthly ticket digest — installed by the MonthlyDigest plugin. '
                              . 'Edit the subject / HTML / text bodies below to customise.',
            ]);
            if (!$templateId) {
                Toolbox::logWarning('MonthlyDigest installer: failed to create NotificationTemplate row');
                return;
            }
        }

        // 2. NotificationTemplateTranslation rows — one per shipped language.
        //    Only ADD missing ones — never overwrite an existing translation, so
        //    admin customisations survive plugin file replacement / re-install.
        foreach (PluginMonthlydigestTemplate::seedTranslations() as $lang => $seed) {
            $exists = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => [
                    'notificationtemplates_id' => $templateId,
                    'language'                 => $lang,
                ],
                'LIMIT'  => 1,
            ])->current();

            if ($exists) {
                continue;
            }

            $htmlBody = is_file($seed['html_path']) ? (string) file_get_contents($seed['html_path']) : '';
            $textBody = is_file($seed['text_path']) ? (string) file_get_contents($seed['text_path']) : '';

            $tr = new NotificationTemplateTranslation();
            $tr->add([
                'notificationtemplates_id' => $templateId,
                'language'                 => $lang,
                'subject'                  => $seed['subject'],
                'content_html'             => $htmlBody,
                'content_text'             => $textBody,
            ]);
        }

        // 3. Notification row (event ↔ template binding)
        $notificationId = PluginMonthlydigestTemplate::getNotificationId();
        if ($notificationId === null) {
            $notif = new Notification();
            $notificationId = $notif->add([
                'name'         => PluginMonthlydigestTemplate::NOTIFICATION_NAME,
                'itemtype'     => PluginMonthlydigestTemplate::ITEMTYPE,
                'event'        => PluginMonthlydigestTemplate::EVENT,
                'comment'      => 'Triggered daily by the MonthlyDigest plugin cron on the configured day-of-month.',
                'is_active'    => 1,
                'is_recursive' => 1,
                'entities_id'  => 0,
            ]);
            if (!$notificationId) {
                Toolbox::logWarning('MonthlyDigest installer: failed to create Notification row');
                return;
            }
        }

        // 4. Link Notification ↔ NotificationTemplate (mode = mailing)
        $linkExists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notifications_notificationtemplates',
            'WHERE'  => [
                'notifications_id'         => $notificationId,
                'notificationtemplates_id' => $templateId,
                'mode'                     => \Notification_NotificationTemplate::MODE_MAIL,
            ],
            'LIMIT'  => 1,
        ])->current();

        if (!$linkExists) {
            $link = new Notification_NotificationTemplate();
            $link->add([
                'notifications_id'         => $notificationId,
                'notificationtemplates_id' => $templateId,
                'mode'                     => \Notification_NotificationTemplate::MODE_MAIL,
            ]);
        }
    }

    /**
     * Remove the rows created by installNotification(). Admin-edited translations
     * are wiped — they're plugin-owned data, not user-owned history.
     */
    private static function uninstallNotification(): void
    {
        global $DB;

        $templateId     = PluginMonthlydigestTemplate::getTemplateId();
        $notificationId = PluginMonthlydigestTemplate::getNotificationId();

        if ($notificationId !== null) {
            $DB->delete('glpi_notifications_notificationtemplates', [
                'notifications_id' => $notificationId,
            ]);
            $DB->delete('glpi_notifications', ['id' => $notificationId]);
        }

        if ($templateId !== null) {
            $DB->delete('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
            ]);
            $DB->delete('glpi_notificationtemplates', ['id' => $templateId]);
        }
    }
}
