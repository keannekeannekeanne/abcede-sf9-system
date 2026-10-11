<?php
/**
 * HTTP plumbing shared by pages and JSON endpoints.
 *
 *  - HttpException : throw it anywhere; the active handler turns it into the
 *                    right status code + message (JSON for API, HTML for pages).
 *  - Json          : bootstraps an endpoint so it can ONLY ever answer with JSON.
 *  - Page          : friendly HTML error page for normal (non-API) pages.
 *
 * Why this exists: with display_errors on (the XAMPP default) any PHP warning
 * or uncaught exception is printed as "<br /><b>Warning</b>: ..." straight
 * into the response body, and fetch(...).then(r => r.json()) then fails with
 * `Unexpected token '<', "<br /> <b>"... is not valid JSON`.
 * Json::boot() makes that impossible: nothing but our JSON envelope is sent.
 */

final class HttpException extends RuntimeException
{
    private int $status;
    private array $extra;

    public function __construct(int $status, string $message, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->extra  = $extra;
    }

    public function status(): int { return $this->status; }
    public function extra(): array { return $this->extra; }
}

final class Json
{
    private static bool $responded = false;

    /** Call first thing in every endpoint under public/api/. */
    public static function boot(): void
    {
        if (!defined('SF9_API')) {
            define('SF9_API', true);
        }

        // Errors are LOGGED, never printed into the response body.
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        ini_set('log_errors', '1');

        // Catch anything that is accidentally echoed (stray whitespace, BOMs, notices).
        ob_start();

        // Turn warnings/notices into exceptions so they are handled (and logged)
        // instead of silently corrupting the output. Deprecations are just logged.
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                error_log("PHP deprecated: $str in $file:$line");
                return true;
            }
            throw new ErrorException($str, 0, $no, $file, $line);
        });

        set_exception_handler([self::class, 'handleThrowable']);
        register_shutdown_function([self::class, 'handleShutdown']);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    public static function ok($data = null, string $message = 'Operation completed successfully.', int $status = 200): void
    {
        self::send(true, $message, $data, $status);
    }

    public static function fail(string $message, int $status = 400, array $extra = []): void
    {
        self::send(false, $message, null, $status, $extra);
    }

    /** Request body decoded as JSON; 400 when it is present but malformed. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(400, 'The request body is not valid JSON.');
        }
        return $decoded;
    }

    public static function requireMethod(string ...$allowed): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new HttpException(405, 'Method not allowed.');
        }
        return $method;
    }

    public static function handleThrowable(Throwable $e): void
    {
        $status  = 500;
        $message = 'Something went wrong on the server. Please try again.';
        $extra   = [];

        if ($e instanceof HttpException) {
            $status  = $e->status();
            $message = $e->getMessage();
            $extra   = $e->extra();
        } else {
            error_log(sprintf('[sf9] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            if (getenv('SF9_DEBUG') === '1') {
                $extra['debug'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            }
        }

        self::send(false, $message, null, $status, $extra);
    }

    public static function handleShutdown(): void
    {
        if (self::$responded) {
            return;
        }
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            error_log(sprintf('[sf9] fatal: %s in %s:%d', $err['message'], $err['file'], $err['line']));
            self::send(false, 'Something went wrong on the server. Please try again.', null, 500, [], false);
        }
    }

    private static function send(bool $success, string $message, $data, int $status, array $extra = [], bool $doExit = true): void
    {
        self::$responded = true;

        // Drop (and log) anything that was echoed before us.
        $stray = '';
        while (ob_get_level() > 0) {
            $stray .= (string) ob_get_clean();
        }
        if (trim($stray) !== '') {
            error_log('[sf9] discarded unexpected output before JSON: ' . substr(strip_tags($stray), 0, 300));
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $payload = ['success' => $success, 'message' => $message];
        if ($success) {
            $payload['data'] = $data ?? new stdClass();
        } else {
            $payload = array_merge($payload, $extra);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        echo $json !== false ? $json : '{"success":false,"message":"Could not encode the response."}';

        if ($doExit) {
            exit;
        }
    }
}

final class Page
{
    /** Friendly HTML errors for normal pages (403/404/500) instead of raw text or stack traces. */
    public static function boot(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            $status  = 500;
            $message = 'Something went wrong on the server. Please try again.';
            if ($e instanceof HttpException) {
                $status  = $e->status();
                $message = $e->getMessage();
            } else {
                error_log(sprintf('[sf9] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: text/html; charset=utf-8');
            }
            $titles = [401 => 'Please log in', 403 => 'Access denied', 404 => 'Not found', 419 => 'Session expired', 503 => 'Service unavailable'];
            $title  = $titles[$status] ?? 'Something went wrong';
            $base   = defined('SF9_ROOT_PAGE') ? 'public/' : '';   // root index.php sits above public/
            $msg    = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            $ttl    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
               . '<meta name="viewport" content="width=device-width, initial-scale=1">'
               . '<title>' . $ttl . '</title>'
               . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
               . '<link href="' . $base . 'assets/css/app.css" rel="stylesheet"></head>'
               . '<body class="error-page"><main class="error-card">'
               . '<div class="error-code">' . (int) $status . '</div>'
               . '<h1 class="h4 mb-2">' . $ttl . '</h1>'
               . '<p class="text-secondary mb-4">' . $msg . '</p>'
               . '<a class="btn btn-primary" href="' . $base . 'dashboard.php">Back to dashboard</a>'
               . '</main></body></html>';
            exit;
        });
    }
}
