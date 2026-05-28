<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Unified settings page + sendtest action.
GPLv2+

Both the form display AND the "Send test digest" action live on this one
URL. Reasons:
  - Save: posts to GLPI core's /front/config.form.php with config_context
    so prepareInputForUpdate() handles plugin save automatically.
  - Send test: GET with ?action=sendtest&confirm=1 — GLPI 11.0.7's
    CheckCsrfListener skips GET requests, so no CSRF dance is needed.
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', READ);

// === GET ?action=sendtest&confirm=1 → fire the test digest ===
if (
    ($_GET['action'] ?? '') === 'sendtest'
    && ($_GET['confirm'] ?? '') === '1'
) {
    Session::checkRight('config', UPDATE);

    $cfg = Config::getConfigurationValues('plugin:monthlydigest');
    $monthsBack = max(1, min(3, (int) ($cfg['period_months'] ?? 1)));
    $userId = (int) Session::getLoginUserID();
    $period = PluginMonthlydigestStatsBuilder::previousPeriodKey($monthsBack);

    // Drop idempotency marker so the admin can re-run
    global $DB;
    $DB->delete(PluginMonthlydigestSentLog::table(), [
        'users_id' => $userId,
        'period'   => $period,
    ]);

    try {
        $sender = new PluginMonthlydigestDigestSender();
        $status = $sender->dispatch($userId, $period);
        Session::addMessageAfterRedirect(
            sprintf(__s('Test digest for %s: %s', 'monthlydigest'), $period, $status)
        );
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect(
            sprintf(__s('Test digest failed: %s', 'monthlydigest'), $e->getMessage()),
            false,
            ERROR
        );
        Toolbox::logError('MonthlyDigest sendtest failed - ' . $e->getMessage());
    }

    Html::redirect(Plugin::getWebDir('monthlydigest') . '/front/config.form.php');
}

// === Otherwise: render the settings page ===
Html::header(
    PluginMonthlydigestConfig::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

PluginMonthlydigestConfig::showConfigForm();

Html::footer();
