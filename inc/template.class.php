<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Customisable email templates.
GPLv2+

Templates can be edited from the admin UI. If a DB row exists for a key,
it overrides the file-shipped default. Otherwise the .twig file is used.

Twig rendering uses TemplateRenderer's environment so the same `__()`
function and other extensions work in both modes.
-------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

class PluginMonthlydigestTemplate
{
    public const KEY_HTML = 'digest_html';
    public const KEY_TEXT = 'digest_text';

    public static function table(): string
    {
        return PluginMonthlydigestInstaller::TABLE_TEMPLATES;
    }

    /**
     * Map of editable template keys → human label.
     *
     * @return array<string, string>
     */
    public static function listKeys(): array
    {
        return [
            self::KEY_HTML => __('HTML body', 'monthlydigest'),
            self::KEY_TEXT => __('Plain-text body', 'monthlydigest'),
        ];
    }

    /**
     * Filesystem path of the shipped default for a given key.
     */
    public static function defaultPath(string $key): string
    {
        return match ($key) {
            self::KEY_HTML => realpath(__DIR__ . '/../templates/digest_html.twig') ?: '',
            self::KEY_TEXT => realpath(__DIR__ . '/../templates/digest_text.twig') ?: '',
            default        => '',
        };
    }

    /**
     * Get the default body shipped in the plugin source.
     */
    public static function getDefault(string $key): string
    {
        $path = self::defaultPath($key);
        return ($path !== '' && is_file($path)) ? (string) file_get_contents($path) : '';
    }

    /**
     * Get the body that will actually be rendered for this key — custom row from
     * DB if present, otherwise the shipped default.
     */
    public static function getBody(string $key): string
    {
        $row = self::getRow($key);
        if ($row !== null && $row['body'] !== '') {
            return (string) $row['body'];
        }
        return self::getDefault($key);
    }

    /**
     * Persist a customised body. Empty body resets to default.
     */
    public static function setBody(string $key, string $body, ?int $usersId = null): bool
    {
        if (!array_key_exists($key, self::listKeys())) {
            return false;
        }
        global $DB;
        $existing = self::getRow($key);

        if ($body === '') {
            // Empty body = reset to default
            return self::reset($key);
        }

        $payload = [
            'body'       => $body,
            'updated_by' => $usersId,
        ];
        if ($existing !== null) {
            return (bool) $DB->update(self::table(), $payload, ['template_key' => $key]);
        }
        $payload['template_key'] = $key;
        return (bool) $DB->insert(self::table(), $payload);
    }

    /**
     * Wipe the custom row; subsequent reads fall back to the shipped file.
     */
    public static function reset(string $key): bool
    {
        if (!array_key_exists($key, self::listKeys())) {
            return false;
        }
        global $DB;
        return (bool) $DB->delete(self::table(), ['template_key' => $key]);
    }

    /**
     * True if a custom (DB) row exists for this key.
     */
    public static function isCustomised(string $key): bool
    {
        return self::getRow($key) !== null;
    }

    /**
     * Validate that a body compiles as a Twig template.
     * Returns null on success, or a human error message on failure.
     */
    public static function validate(string $body): ?string
    {
        try {
            $twig = TemplateRenderer::getInstance()->getEnvironment();
            $twig->createTemplate($body, 'monthlydigest_validate');
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Render a body (custom or default) with the given context vars.
     *
     * @param array<string, mixed> $vars
     */
    public static function renderFromString(string $body, array $vars): string
    {
        $twig = TemplateRenderer::getInstance()->getEnvironment();
        $tpl = $twig->createTemplate($body, 'monthlydigest_runtime');
        return $tpl->render($vars);
    }

    /**
     * Convenience: load by key + render in one go.
     *
     * @param array<string, mixed> $vars
     */
    public static function renderByKey(string $key, array $vars): string
    {
        return self::renderFromString(self::getBody($key), $vars);
    }

    /**
     * Describe variables available inside the digest templates.
     * Used by the admin edit page as live documentation.
     *
     * @return array<int, array{name:string, description:string}>
     */
    public static function availableVariables(): array
    {
        return [
            ['name' => 'user.id',         'description' => __('Recipient user id', 'monthlydigest')],
            ['name' => 'user.name',       'description' => __('Display name (firstname + realname or login)', 'monthlydigest')],
            ['name' => 'user.firstname',  'description' => __('First name', 'monthlydigest')],
            ['name' => 'user.realname',   'description' => __('Surname', 'monthlydigest')],
            ['name' => 'period_label',    'description' => __('Localised period label (e.g. "April 2026" or "Mar–Apr 2026")', 'monthlydigest')],
            ['name' => 'stats.created',   'description' => __('Tickets created in period', 'monthlydigest')],
            ['name' => 'stats.solved',    'description' => __('Tickets solved in period', 'monthlydigest')],
            ['name' => 'stats.closed',    'description' => __('Tickets closed in period', 'monthlydigest')],
            ['name' => 'stats.open',      'description' => __('Tickets still open at period end', 'monthlydigest')],
            ['name' => 'stats.period',    'description' => __('YYYY-MM key of period start month', 'monthlydigest')],
            ['name' => 'stats.months_back', 'description' => __('Window size (1, 2 or 3 months)', 'monthlydigest')],
            ['name' => 'glpi_url',        'description' => __('Configured base URL of the GLPI instance', 'monthlydigest')],
            ['name' => 'unsubscribe_url', 'description' => __('Tokenised opt-out URL for the recipient', 'monthlydigest')],
            ['name' => 'locale',          'description' => __('User\'s GLPI language code (e.g. "ru_RU")', 'monthlydigest')],
            ['name' => "__('text', 'monthlydigest')", 'description' => __('Translate a string using the plugin\'s message catalogue', 'monthlydigest')],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getRow(string $key): ?array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id', 'body', 'updated_at', 'updated_by'],
            'FROM'   => self::table(),
            'WHERE'  => ['template_key' => $key],
            'LIMIT'  => 1,
        ])->current();
        return $row ?: null;
    }
}
