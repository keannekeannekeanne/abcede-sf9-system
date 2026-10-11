<?php
require_once __DIR__ . '/../../config/database.php';

/**
 * Tiny key-value settings store (table `settings`). Used for small,
 * admin-configurable values that must NOT be hard-coded into templates —
 * e.g. the default School Head name shown on the SF9 (Requirement 5.3).
 *
 * Reading a setting is available to any logged-in page (SF9 preview needs
 * it). WRITING a setting must only ever be reached through an
 * Auth::requireAdmin()-gated endpoint — see public/api/admin.php.
 */
final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = get_db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null && $val !== '' ? $val : $default;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = get_db()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }

    /** All settings as key => value, for the Admin Panel Settings tab. */
    public static function all(): array
    {
        $stmt = get_db()->query('SELECT setting_key, setting_value FROM settings');
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    }
}
