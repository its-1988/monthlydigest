<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin for GLPI — install/uninstall hooks.
GPLv2+
-------------------------------------------------------------------------
 */

function plugin_monthlydigest_install(): bool
{
    return PluginMonthlydigestInstaller::install();
}

function plugin_monthlydigest_uninstall(): bool
{
    return PluginMonthlydigestInstaller::uninstall();
}
