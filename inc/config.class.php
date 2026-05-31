<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Settings page renderer.
GPLv2+

Pure renderer — no CommonGLPI tab integration. Called from the standalone
front/config.form.php. CSRF tokens are emitted by the Twig template via
the canonical `csrf_token()` function so they go through the same path
GLPI core uses for its own forms.
-------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

class PluginMonthlydigestConfig extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return __('Monthly Ticket Digest', 'monthlydigest');
    }

    public function getName($with_comment = 0): string
    {
        return __('Monthly Digest', 'monthlydigest');
    }

    public static function getIcon(): string
    {
        return 'ti ti-calendar-stats';
    }

    /**
     * Render the full settings page (form + actions + recent send log).
     * No `<form>` is opened by the caller — our Twig template owns both
     * forms (main + send-test) end-to-end.
     */
    public static function showConfigForm(): void
    {
        global $DB;

        $config = array_merge(self::defaults(), Config::getConfigurationValues('plugin:monthlydigest'));

        // Recent sent log (last 30 rows)
        $log = [];
        foreach ($DB->request([
            'FROM'  => PluginMonthlydigestInstaller::TABLE_SENT_LOG,
            'ORDER' => ['sent_at DESC'],
            'LIMIT' => 30,
        ]) as $row) {
            $log[] = [
                'sent_at'   => Html::convDateTime($row['sent_at']),
                'period'    => $row['period'],
                'users_id'  => (int) $row['users_id'],
                'status'    => $row['status'],
                'recipient' => $row['recipient'],
                'subject'   => $row['subject'],
                'error'     => (string) ($row['error'] ?? ''),
            ];
        }

        $optedOut = (int) ($DB->request([
            'COUNT' => 'cpt',
            'FROM'  => PluginMonthlydigestInstaller::TABLE_USERPREF,
            'WHERE' => ['opt_out' => 1],
        ])->current()['cpt'] ?? 0);

        // CSRF token MUST be generated PHP-side and passed as a Twig variable.
        // The Twig `csrf_token()` function (registered by
        // Glpi\Application\View\Extension\SecurityExtension) returned an
        // EMPTY STRING when called from a plugin's TemplateRenderer context
        // in v1.0.2–1.0.4 — caught in a real GLPI 11.0.7 deployment via
        // DevTools: `<input type="hidden" name="_glpi_csrf_token" value="">`.
        // Calling Session::getNewCSRFToken() directly from PHP works because
        // it adds the token to $_SESSION['glpicsrftokens'] *and* returns the
        // hex string for us to embed.
        $csrf_token = Session::getNewCSRFToken();

        // Standard-GLPI deep-links for editing the email content.
        // These point to GLPI core's Notification + NotificationTemplate forms
        // — the canonical place where notification bodies live.
        $notificationEditUrl = PluginMonthlydigestTemplate::editNotificationUrl();
        $templateEditUrl     = PluginMonthlydigestTemplate::editTemplateUrl();

        TemplateRenderer::getInstance()->display('@monthlydigest/config.html.twig', [
            'config'           => $config,
            // Post to OUR endpoint (not GLPI core's /front/config.form.php) so we
            // control the redirect back to the plugin's own settings page.
            // CSRF is still validated by Symfony's CheckCsrfListener because the
            // PHP-generated csrf_token_value lives in $_SESSION['glpicsrftokens'].
            'form_action'      => Plugin::getWebDir('monthlydigest') . '/front/config.update.php',
            'csrf_token_value' => $csrf_token,
            'yesno_choices'    => [0 => __('No'), 1 => __('Yes')],
            'period_choices'   => [
                1 => __('1 month (previous)', 'monthlydigest'),
                2 => __('2 months', 'monthlydigest'),
                3 => __('3 months', 'monthlydigest'),
            ],
            'sent_log'              => $log,
            'opted_out_count'       => $optedOut,
            'previous_period'       => PluginMonthlydigestStatsBuilder::previousMonth(),
            'preview_url'           => Plugin::getWebDir('monthlydigest') . '/front/preview.php',
            'sendtest_url'          => Plugin::getWebDir('monthlydigest')
                                       . '/front/config.form.php?action=sendtest&confirm=1',
            'notification_edit_url' => $notificationEditUrl,
            'template_edit_url'     => $templateEditUrl,
            'template_installed'    => $notificationEditUrl !== null && $templateEditUrl !== null,
            'available_tags'        => PluginMonthlydigestTemplate::availableTags(),
            'can_update'            => Session::haveRight('config', UPDATE),
            'plugin_version'        => PLUGIN_MONTHLYDIGEST_VERSION,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled'             => 0,
            'send_day_of_month'   => 1,
            'send_hour'           => 8,
            'batch_size'          => 100,
            'test_mode'           => 0,
            'test_recipient'      => '',
            'subject_template'    => '',
            'min_tickets_to_send' => 0,
            'include_zero_users'  => 0,
            'period_months'       => 1,
        ];
    }
}
