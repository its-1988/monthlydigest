<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Settings save endpoint.
GPLv2+

The form posts here (not to /front/config.form.php of GLPI core) so that
after saving we redirect back to our own settings page instead of GLPI's
generic Setup → Config landing page.

CSRF is validated by Symfony's CheckCsrfListener BEFORE this file runs.
The token in $_POST['_glpi_csrf_token'] is the PHP-generated token
(Session::getNewCSRFToken) that was added to $_SESSION['glpicsrftokens']
during the render pass.
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$allowed = array_keys(PluginMonthlydigestConfig::defaults());

$clean = [];
foreach ($allowed as $key) {
    if (!array_key_exists($key, $_POST)) {
        // Missing key for a known bool/number — coerce to 0
        $clean[$key] = '0';
        continue;
    }
    $raw = $_POST[$key];
    if (is_array($raw)) {
        $clean[$key] = '';
        continue;
    }
    // Integer-coerce known numeric fields
    if (in_array($key, [
        'enabled', 'send_day_of_month', 'send_hour', 'batch_size',
        'test_mode', 'min_tickets_to_send', 'include_zero_users',
        'period_months',
    ], true)) {
        $clean[$key] = (string) (int) $raw;
    } else {
        $clean[$key] = trim((string) $raw);
    }
}

// Range / format validation
$clean['send_day_of_month'] = (string) max(1, min(28, (int) $clean['send_day_of_month']));
$clean['send_hour']         = (string) max(0, min(23, (int) $clean['send_hour']));
$clean['batch_size']        = (string) max(1, (int) $clean['batch_size']);
$clean['period_months']     = (string) max(1, min(3, (int) $clean['period_months']));

if ($clean['test_recipient'] !== ''
    && !filter_var($clean['test_recipient'], FILTER_VALIDATE_EMAIL)) {
    Session::addMessageAfterRedirect(
        __s('Test recipient is not a valid email — saved blank.', 'monthlydigest'),
        false,
        WARNING
    );
    $clean['test_recipient'] = '';
}

Config::setConfigurationValues('plugin:monthlydigest', $clean);

Session::addMessageAfterRedirect(__s('Monthly Digest settings saved.', 'monthlydigest'));

Html::redirect(Plugin::getWebDir('monthlydigest') . '/front/config.form.php');
