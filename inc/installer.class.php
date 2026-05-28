<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Installer service.
GPLv2+
-------------------------------------------------------------------------
 */

/**
 * Builds the plugin's database schema and registers the GLPI CronTask.
 *
 * Tables:
 *   - glpi_plugin_monthlydigest_sent_log : idempotency log (one row per user × period)
 *   - glpi_plugin_monthlydigest_userpref : per-user opt-out flag
 */
class PluginMonthlydigestInstaller
{
    public const TABLE_SENT_LOG  = 'glpi_plugin_monthlydigest_sent_log';
    public const TABLE_USERPREF  = 'glpi_plugin_monthlydigest_userpref';
    public const TABLE_TEMPLATES = 'glpi_plugin_monthlydigest_templates';

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

        if (!$DB->tableExists(self::TABLE_TEMPLATES)) {
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE_TEMPLATES . "` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `template_key` VARCHAR(50) NOT NULL,
                    `body`         LONGTEXT NOT NULL,
                    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
                    `updated_by`   INT UNSIGNED NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `template_key` (`template_key`)
                )
                COLLATE='utf8mb4_unicode_ci'
                ENGINE=InnoDB"
            );
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
        ]);

        // Tables intentionally preserved (history). Drop manually if desired.
        return true;
    }
}
