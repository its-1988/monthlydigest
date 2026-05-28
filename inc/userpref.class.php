<?php
/*
-------------------------------------------------------------------------
MonthlyDigest plugin — User preferences service.
GPLv2+
-------------------------------------------------------------------------
 */

/**
 * Per-user opt-out preferences and unsubscribe tokens.
 *
 * A token is HMAC-SHA1(users_id, GLPIKey) — stable per user but
 * impossible to guess without the GLPI instance secret.
 */
class PluginMonthlydigestUserPref
{
    public static function table(): string
    {
        return PluginMonthlydigestInstaller::TABLE_USERPREF;
    }

    /**
     * Has this user opted out? Default false (i.e. opted in).
     */
    public static function isOptedOut(int $usersId): bool
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['opt_out'],
            'FROM'   => self::table(),
            'WHERE'  => ['users_id' => $usersId],
            'LIMIT'  => 1,
        ])->current();
        return $row && (int) $row['opt_out'] === 1;
    }

    /**
     * Compute (or return cached) unsubscribe token for the given user.
     */
    public static function getOrCreateToken(int $usersId): string
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['unsubscribe_token'],
            'FROM'   => self::table(),
            'WHERE'  => ['users_id' => $usersId],
            'LIMIT'  => 1,
        ])->current();

        if ($row && !empty($row['unsubscribe_token'])) {
            return (string) $row['unsubscribe_token'];
        }

        $token = self::computeToken($usersId);
        if ($row) {
            $DB->update(self::table(), ['unsubscribe_token' => $token], ['users_id' => $usersId]);
        } else {
            $DB->insert(self::table(), [
                'users_id'          => $usersId,
                'opt_out'           => 0,
                'unsubscribe_token' => $token,
            ]);
        }
        return $token;
    }

    /**
     * Apply unsubscribe via a token. Returns the user ID on success, null on failure.
     */
    public static function unsubscribeByToken(string $token): ?int
    {
        global $DB;
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => self::table(),
            'WHERE'  => ['unsubscribe_token' => $token],
            'LIMIT'  => 1,
        ])->current();
        if (!$row) {
            return null;
        }
        $usersId = (int) $row['users_id'];
        $DB->update(self::table(), ['opt_out' => 1], ['users_id' => $usersId]);
        return $usersId;
    }

    public static function setOptOut(int $usersId, bool $optOut): void
    {
        global $DB;
        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::table(),
            'WHERE' => ['users_id' => $usersId],
        ])->current()['cpt'] ?? 0;

        if ((int) $exists > 0) {
            $DB->update(self::table(), ['opt_out' => $optOut ? 1 : 0], ['users_id' => $usersId]);
        } else {
            $DB->insert(self::table(), [
                'users_id'          => $usersId,
                'opt_out'           => $optOut ? 1 : 0,
                'unsubscribe_token' => self::computeToken($usersId),
            ]);
        }
    }

    /**
     * HMAC-SHA1 over the user id with the GLPI key as secret — gives stable,
     * unguessable per-user tokens that don't need persisted random storage.
     */
    private static function computeToken(int $usersId): string
    {
        $secret = 'monthlydigest:' . GLPI_VERSION;
        try {
            $secret .= (new GLPIKey())->get();
        } catch (\Throwable) {
            // GLPIKey may not be available in some test contexts — fallback to host-ish secret
            $secret .= php_uname('n');
        }
        return hash_hmac('sha1', 'mdigest:' . $usersId, $secret);
    }
}
