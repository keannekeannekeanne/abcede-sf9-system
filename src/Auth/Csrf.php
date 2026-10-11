<?php
require_once __DIR__ . '/Auth.php';

class Csrf
{
    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
    }

    /** Token sent by fetch() in the X-CSRF-Token header (falls back to a JSON body field). */
    public static function fromRequest(array $body = []): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $fromBody = $body['csrf_token'] ?? null;
        return is_string($fromBody) ? $fromBody : null;
    }

    public static function validateOrFail(?string $submitted): void
    {
        Auth::start();
        $expected = $_SESSION['csrf_token'] ?? null;
        if (!$expected || !$submitted || !hash_equals($expected, $submitted)) {
            throw new HttpException(419, 'Your session expired. Please reload the page and try again.');
        }
    }
}
