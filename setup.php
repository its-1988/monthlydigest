<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin for GLPI
Copyright (C) 2026 — GPLv2+
-------------------------------------------------------------------------
 */

define('PLUGIN_MONTHLYDIGEST_VERSION', '1.0.9');
define('PLUGIN_MONTHLYDIGEST_MIN_GLPI', '11.0.0');
define('PLUGIN_MONTHLYDIGEST_MAX_GLPI', '11.1');

/**
 * Init plugin hooks.
 */
function plugin_init_monthlydigest(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['monthlydigest'] = true;

    // Mark the legacy v1.0.2 send-test endpoint as stateless so the Symfony
    // `CheckCsrfListener` skips it. This lets cached browser HTML (POST form
    // to sendnow.php) still work — the stub just redirects to the unified
    // config.form.php endpoint.
    if (class_exists(\Glpi\Http\SessionManager::class)
        && method_exists(\Glpi\Http\SessionManager::class, 'registerPluginStatelessPath')) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath(
            'monthlydigest',
            '#^/front/sendnow\.php$#'
        );
    }

    // Daily cron — checks today's day-of-month vs config and dispatches
    $PLUGIN_HOOKS['cron']['monthlydigest'] = ['PluginMonthlydigestCrontask'];

    // Console commands
    $PLUGIN_HOOKS['console_command']['monthlydigest'] = [
        'PluginMonthlydigestSendCommand',
    ];

    // Settings live on a standalone plugin page (Setup → Plugins → Configure).
    // We intentionally do NOT register `addtabon => Config` — that nests our
    // form inside GLPI core's Config form (templates/pages/setup/general/base_form.html.twig)
    // which breaks CSRF + POST routing in GLPI 11.0.7.
    if (Session::haveRightsOr('config', [READ, UPDATE])) {
        $PLUGIN_HOOKS['config_page']['monthlydigest'] = 'front/config.form.php';
    }
}

/**
 * Plugin metadata.
 */
function plugin_version_monthlydigest(): array
{
    return [
        'name'         => __('Monthly Ticket Digest', 'monthlydigest'),
        'version'      => PLUGIN_MONTHLYDIGEST_VERSION,
        'author'       => 'GLPI community',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MONTHLYDIGEST_MIN_GLPI,
                'max' => PLUGIN_MONTHLYDIGEST_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_monthlydigest_check_prerequisites(): bool
{
    if (
        version_compare(GLPI_VERSION, PLUGIN_MONTHLYDIGEST_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_MONTHLYDIGEST_MAX_GLPI, 'ge')
    ) {
        echo sprintf(
            'MonthlyDigest requires GLPI >= %s and < %s',
            PLUGIN_MONTHLYDIGEST_MIN_GLPI,
            PLUGIN_MONTHLYDIGEST_MAX_GLPI
        );
        return false;
    }
    return true;
}

function plugin_monthlydigest_check_config(): bool
{
    return true;
}
