<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Public unsubscribe endpoint.
GPLv2+
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

// Intentionally public — token is the auth — but we still use a friendly page.
$token = (string) ($_GET['token'] ?? '');
$userId = PluginMonthlydigestUserPref::unsubscribeByToken($token);

Html::nullHeader(__('Monthly Digest', 'monthlydigest'));

echo "<div class='center' style='max-width:560px;margin:80px auto;text-align:center;'>";
if ($userId !== null) {
    echo "<h2><i class='fas fa-check-circle text-success'></i> "
        . __('Unsubscribed', 'monthlydigest') . "</h2>";
    echo "<p>" . __('You will no longer receive monthly ticket digest emails.', 'monthlydigest') . "</p>";
} else {
    echo "<h2><i class='fas fa-exclamation-triangle text-danger'></i> "
        . __('Invalid or expired link', 'monthlydigest') . "</h2>";
    echo "<p>" . __('Please log in to GLPI and adjust your notification preferences manually.', 'monthlydigest') . "</p>";
}
echo "</div>";

Html::nullFooter();
