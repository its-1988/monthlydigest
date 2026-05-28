<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Admin preview of digest for a given user.
GPLv2+
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight('config', READ);

$cfg = Config::getConfigurationValues('plugin:monthlydigest');
$monthsBack = max(1, min(3, (int) ($cfg['period_months'] ?? 1)));

$userId = (int) ($_GET['user'] ?? Session::getLoginUserID());
$period = (string) ($_GET['period'] ?? PluginMonthlydigestStatsBuilder::previousPeriodKey($monthsBack));

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
    $period = PluginMonthlydigestStatsBuilder::previousPeriodKey($monthsBack);
}

$sender = new PluginMonthlydigestDigestSender();
$preview = $sender->renderPreview($userId, $period);

Html::header(
    PluginMonthlydigestConfig::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginMonthlydigestConfig'
);

echo "<div class='center' style='max-width:840px;margin:20px auto;'>";
echo "<div class='card mb-3'>";
echo "<div class='card-header'><strong>" . __('Digest preview', 'monthlydigest') . "</strong></div>";
echo "<div class='card-body'>";
echo "<dl class='row mb-0'>";
echo "<dt class='col-sm-3'>" . __('User', 'monthlydigest') . "</dt><dd class='col-sm-9'>#$userId</dd>";
echo "<dt class='col-sm-3'>" . __('Period', 'monthlydigest') . "</dt><dd class='col-sm-9'>$period</dd>";
echo "<dt class='col-sm-3'>" . __('Recipient', 'monthlydigest') . "</dt>";
echo "<dd class='col-sm-9'>" . htmlspecialchars($preview['recipient'] ?: '—') . "</dd>";
echo "<dt class='col-sm-3'>" . __('Subject', 'monthlydigest') . "</dt>";
echo "<dd class='col-sm-9'>" . htmlspecialchars($preview['subject']) . "</dd>";
echo "</dl>";
echo "</div></div>";

echo "<div class='card mb-3'><div class='card-header'><strong>HTML</strong></div>";
echo "<div class='card-body'>" . $preview['html'] . "</div></div>";

echo "<div class='card'><div class='card-header'><strong>Plain text</strong></div>";
echo "<pre class='card-body small'>" . htmlspecialchars($preview['text']) . "</pre></div>";
echo "</div>";

Html::footer();
