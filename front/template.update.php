<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Save customised email template body.
GPLv2+
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$keys = PluginMonthlydigestTemplate::listKeys();
$key  = (string) ($_POST['key'] ?? '');
if (!array_key_exists($key, $keys)) {
    Session::addMessageAfterRedirect(
        __s('Unknown template key.', 'monthlydigest'),
        false,
        ERROR
    );
    Html::redirect(Plugin::getWebDir('monthlydigest') . '/front/config.form.php');
}

$body = (string) ($_POST['body'] ?? '');
$pluginUrl = Plugin::getWebDir('monthlydigest');

// Empty body = reset to default
if (trim($body) === '') {
    PluginMonthlydigestTemplate::reset($key);
    Session::addMessageAfterRedirect(
        __s('Template reset to shipped default.', 'monthlydigest')
    );
    Html::redirect($pluginUrl . '/front/template.form.php?key=' . urlencode($key));
}

// Validate Twig syntax before saving
$error = PluginMonthlydigestTemplate::validate($body);
if ($error !== null) {
    // Stash error + body in session, redirect back to edit page
    $_SESSION['plugin_monthlydigest_validation_error'] = $error;
    Session::addMessageAfterRedirect(
        __s('Template not saved due to syntax error.', 'monthlydigest'),
        false,
        ERROR
    );
    Html::redirect($pluginUrl . '/front/template.form.php?key=' . urlencode($key));
}

$ok = PluginMonthlydigestTemplate::setBody($key, $body, (int) Session::getLoginUserID());
if ($ok) {
    Session::addMessageAfterRedirect(
        __s('Template saved.', 'monthlydigest')
    );
} else {
    Session::addMessageAfterRedirect(
        __s('Failed to save template.', 'monthlydigest'),
        false,
        ERROR
    );
}

Html::redirect($pluginUrl . '/front/template.form.php?key=' . urlencode($key));
