<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Backward-compat stub for v1.0.x.
GPLv2+

In v1.0.2 the "Send test" button posted to this URL. Browsers may still
have that HTML cached. We register this path as STATELESS in setup.php
so the Symfony CheckCsrfListener doesn't reject POSTs to it, and we
simply redirect to the new unified endpoint.
-------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

// Stateless registration means session isn't started; we can't use
// addMessageAfterRedirect or Html::redirect (they need session). Send a
// raw 302 instead.
$dest = '/marketplace/monthlydigest/front/config.form.php?action=sendtest&confirm=1';
if (class_exists('Plugin')) {
    $dest = Plugin::getWebDir('monthlydigest')
          . '/front/config.form.php?action=sendtest&confirm=1';
}
header('Location: ' . $dest);
exit;
