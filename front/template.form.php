<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Edit a single email template (HTML or text).
GPLv2+
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

use Glpi\Application\View\TemplateRenderer;

Session::checkLoginUser();
Session::checkRight('config', READ);

$keys = PluginMonthlydigestTemplate::listKeys();
$key  = (string) ($_GET['key'] ?? PluginMonthlydigestTemplate::KEY_HTML);
if (!array_key_exists($key, $keys)) {
    Session::addMessageAfterRedirect(
        __s('Unknown template key.', 'monthlydigest'),
        false,
        ERROR
    );
    Html::redirect(Plugin::getWebDir('monthlydigest') . '/front/config.form.php');
}

$body            = PluginMonthlydigestTemplate::getBody($key);
$isCustomised    = PluginMonthlydigestTemplate::isCustomised($key);
$validationError = $_SESSION['plugin_monthlydigest_validation_error'] ?? null;
unset($_SESSION['plugin_monthlydigest_validation_error']);

$pluginUrl = Plugin::getWebDir('monthlydigest');

Html::header(
    PluginMonthlydigestConfig::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

TemplateRenderer::getInstance()->display('@monthlydigest/template_edit.html.twig', [
    'key'              => $key,
    'template_label'   => $keys[$key],
    'body'             => $body,
    'is_customised'    => $isCustomised,
    'validation_error' => $validationError,
    'csrf_token_value' => Session::getNewCSRFToken(),
    'update_url'       => $pluginUrl . '/front/template.update.php',
    'reset_url'        => $pluginUrl . '/front/template.reset.php?key=' . urlencode($key) . '&confirm=1',
    'preview_url'      => $pluginUrl . '/front/preview.php',
    'back_url'         => $pluginUrl . '/front/config.form.php',
    'can_update'       => Session::haveRight('config', UPDATE),
    'variables'        => PluginMonthlydigestTemplate::availableVariables(),
]);

Html::footer();
