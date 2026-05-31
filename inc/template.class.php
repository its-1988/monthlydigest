<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — Native NotificationTemplate adapter.
GPLv2+

Since v1.1.0 templates are stored in GLPI's standard notification tables:
   - glpi_notificationtemplates
   - glpi_notificationtemplatetranslations  (one row per language)
   - glpi_notifications
   - glpi_notifications_notificationtemplates

The admin edits content via the standard GLPI UI:
   Setup → Notifications → Notification templates → "Monthly Ticket Digest"

We do NOT use NotificationEvent::raiseEvent because per-user idempotency,
opt-out and zero-skip logic live in our DigestSender. Instead we resolve
the right translation by language and substitute ##tag## placeholders
ourselves before passing the body to QueuedNotification.
-------------------------------------------------------------------------
 */

class PluginMonthlydigestTemplate
{
    /** Stable name of the NotificationTemplate row we create on install. */
    public const NOTIFICATION_NAME = 'Monthly Ticket Digest';

    /** Event id used in the Notification row + on QueuedNotification.add. */
    public const EVENT = 'monthly_digest';

    /** Item type the notification "is about" — the recipient user. */
    public const ITEMTYPE = 'User';

    /**
     * The seed file paths that populate glpi_notificationtemplatetranslations
     * on install. Keys are GLPI language codes ('' = default, used as fallback).
     *
     * @return array<string, array{subject:string, html_path:string, text_path:string}>
     */
    public static function seedTranslations(): array
    {
        $base = realpath(__DIR__ . '/../templates/seed') ?: '';
        return [
            // Default / fallback (English content)
            '' => [
                'subject'   => 'Your tickets — ##period_label##',
                'html_path' => $base . '/digest_html_en.html',
                'text_path' => $base . '/digest_text_en.txt',
            ],
            'en_GB' => [
                'subject'   => 'Your tickets — ##period_label##',
                'html_path' => $base . '/digest_html_en.html',
                'text_path' => $base . '/digest_text_en.txt',
            ],
            'ru_RU' => [
                'subject'   => 'Ваши заявки — ##period_label##',
                'html_path' => $base . '/digest_html_ru.html',
                'text_path' => $base . '/digest_text_ru.txt',
            ],
        ];
    }

    /**
     * Look up the NotificationTemplate row id (the parent template object).
     */
    public static function getTemplateId(): ?int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => [
                'name'     => self::NOTIFICATION_NAME,
                'itemtype' => self::ITEMTYPE,
            ],
            'LIMIT'  => 1,
        ])->current();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Look up the Notification row id (event ↔ template binding).
     */
    public static function getNotificationId(): ?int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notifications',
            'WHERE'  => [
                'event'    => self::EVENT,
                'itemtype' => self::ITEMTYPE,
            ],
            'LIMIT'  => 1,
        ])->current();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Resolve the best translation row for a given language. Falls back to the
     * default '' language and finally to the English shipped seed if the DB
     * has somehow been wiped.
     *
     * @return array{subject:string, content_html:string, content_text:string}|null
     */
    public static function getTranslation(?string $language = null): ?array
    {
        global $DB;
        $tid = self::getTemplateId();
        if ($tid === null) {
            return self::fallbackToSeed($language);
        }

        // Try requested language first, then '' (GLPI's default convention).
        $candidates = [];
        if ($language !== null && $language !== '') {
            $candidates[] = $language;
        }
        $candidates[] = '';

        foreach ($candidates as $lang) {
            $row = $DB->request([
                'SELECT' => ['subject', 'content_html', 'content_text'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => [
                    'notificationtemplates_id' => $tid,
                    'language'                 => $lang,
                ],
                'LIMIT'  => 1,
            ])->current();
            if ($row) {
                return [
                    'subject'      => (string) $row['subject'],
                    'content_html' => (string) $row['content_html'],
                    'content_text' => (string) $row['content_text'],
                ];
            }
        }

        return self::fallbackToSeed($language);
    }

    /**
     * Substitute ##key## placeholders. Accepts a flat array of scalar values.
     * Unknown tags are left intact (matches GLPI core behaviour) so admins
     * can spot typos in their templates.
     *
     * @param array<string, scalar> $vars
     */
    public static function substitute(string $body, array $vars): string
    {
        if ($body === '' || $vars === []) {
            return $body;
        }
        $search = $replace = [];
        foreach ($vars as $key => $value) {
            $search[]  = '##' . $key . '##';
            $replace[] = (string) $value;
        }
        return str_replace($search, $replace, $body);
    }

    /**
     * Build the standard GLPI edit URL for the template (Setup → Notifications →
     * Notification templates → "Monthly Ticket Digest"). Returns null if the
     * template wasn't installed yet.
     */
    public static function editTemplateUrl(): ?string
    {
        global $CFG_GLPI;
        $id = self::getTemplateId();
        if ($id === null) {
            return null;
        }
        $base = (string) ($CFG_GLPI['root_doc'] ?? '');
        return $base . '/front/notificationtemplate.form.php?id=' . $id;
    }

    /**
     * Build the standard GLPI Notification edit URL (Setup → Notifications →
     * Notifications → "Monthly Ticket Digest").
     */
    public static function editNotificationUrl(): ?string
    {
        global $CFG_GLPI;
        $id = self::getNotificationId();
        if ($id === null) {
            return null;
        }
        $base = (string) ($CFG_GLPI['root_doc'] ?? '');
        return $base . '/front/notification.form.php?id=' . $id;
    }

    /**
     * Documented placeholder tags — surfaced on the plugin's settings page.
     *
     * @return array<int, array{name:string, description:string}>
     */
    public static function availableTags(): array
    {
        return [
            ['name' => 'user.id',           'description' => __('Recipient user id', 'monthlydigest')],
            ['name' => 'user.name',         'description' => __('Display name (firstname + realname or login)', 'monthlydigest')],
            ['name' => 'user.firstname',    'description' => __('First name', 'monthlydigest')],
            ['name' => 'user.realname',     'description' => __('Surname', 'monthlydigest')],
            ['name' => 'period_label',      'description' => __('Localised period label (e.g. "April 2026" or "Mar–Apr 2026")', 'monthlydigest')],
            ['name' => 'stats.created',     'description' => __('Tickets created in period', 'monthlydigest')],
            ['name' => 'stats.solved',      'description' => __('Tickets solved in period', 'monthlydigest')],
            ['name' => 'stats.closed',      'description' => __('Tickets closed in period', 'monthlydigest')],
            ['name' => 'stats.open',        'description' => __('Tickets still open at period end', 'monthlydigest')],
            ['name' => 'stats.period',      'description' => __('YYYY-MM key of period start month', 'monthlydigest')],
            ['name' => 'stats.months_back', 'description' => __('Window size (1, 2 or 3 months)', 'monthlydigest')],
            ['name' => 'glpi_url',          'description' => __('Configured base URL of the GLPI instance', 'monthlydigest')],
            ['name' => 'cta_url',           'description' => __('"My tickets" deep link (glpi_url + /front/ticket.php)', 'monthlydigest')],
            ['name' => 'unsubscribe_url',   'description' => __('Tokenised opt-out URL for the recipient', 'monthlydigest')],
            ['name' => 'lang',              'description' => __('User\'s GLPI language code, short form (e.g. "ru")', 'monthlydigest')],
            ['name' => 'locale',            'description' => __('User\'s full GLPI language code (e.g. "ru_RU")', 'monthlydigest')],
        ];
    }

    /**
     * Read the shipped seed for a given language; used both at install time
     * and as a last-resort fallback if the DB rows are missing.
     *
     * @return array{subject:string, content_html:string, content_text:string}|null
     */
    private static function fallbackToSeed(?string $language): ?array
    {
        $seeds = self::seedTranslations();
        $candidates = [];
        if ($language !== null && $language !== '' && isset($seeds[$language])) {
            $candidates[] = $language;
        }
        $candidates[] = '';

        foreach ($candidates as $lang) {
            if (!isset($seeds[$lang])) {
                continue;
            }
            $seed = $seeds[$lang];
            if (!is_file($seed['html_path']) || !is_file($seed['text_path'])) {
                continue;
            }
            return [
                'subject'      => $seed['subject'],
                'content_html' => (string) file_get_contents($seed['html_path']),
                'content_text' => (string) file_get_contents($seed['text_path']),
            ];
        }
        return null;
    }
}
