<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Reset a customised email template to shipped default.
GPLv2+

GET endpoint with `?key=...&confirm=1` — the JS `confirm()` on the source
link is the safety net. CheckCsrfListener does not validate GET.
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$keys = PluginMonthlydigestTemplate::listKeys();
$key  = (string) ($_GET['key'] ?? '');
$pluginUrl = Plugin::getWebDir('monthlydigest');

if (!array_key_exists($key, $keys)) {
    Html::redirect($pluginUrl . '/front/config.form.php');
}
if (($_GET['confirm'] ?? '') !== '1') {
    Html::redirect($pluginUrl . '/front/template.form.php?key=' . urlencode($key));
}

PluginMonthlydigestTemplate::reset($key);
Session::addMessageAfterRedirect(
    __s('Template reset to shipped default.', 'monthlydigest')
);

Html::redirect($pluginUrl . '/front/template.form.php?key=' . urlencode($key));
