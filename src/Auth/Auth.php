<?php
require_once __DIR__ . '/../../config/database.php';

/**
 * Session-based authentication and server-side authorization helpers.
 * Every page that requires a login calls Auth::requireLogin() first;
 * every grade-affecting endpoint calls Auth::assertOwnsAssignment().
 */
class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        self::start();
        $pdo = get_db();

        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, first_name, last_name, role, is_active, must_change_password
             FROM users WHERE username = :u LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
            // Constant-shape response; do not reveal which part was wrong.
            return false;
        }

        session_regenerate_id(true); // prevent session fixation
        $_SESSION['user_id']             = (int) $user['id'];
        $_SESSION['username']            = $user['username'];
        $_SESSION['full_name']           = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['role']                = $user['role'];
        $_SESSION['must_change_password'] = (bool) $user['must_change_password'];

        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        self::start();
        if (self::check()) {
            return;
        }
        if (defined('SF9_API')) {
            // JSON endpoints must never redirect to the (HTML) login page:
            // fetch() would follow it and then choke on "<!doctype ...".
            throw new HttpException(401, 'Your session has expired. Please log in again.', ['code' => 'unauthenticated']);
        }
        header('Location: ../index.php');   // index.php (login) lives in the project root, one level above public/
        exit;
    }

    public static function userId(): int
    {
        self::start();
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    public static function fullName(): string
    {
        self::start();
        return $_SESSION['full_name'] ?? '';
    }

    public static function role(): string
    {
        self::start();
        return $_SESSION['role'] ?? '';
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Server-side enforcement for Admin-only pages/endpoints. Never trust a hidden button. */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            throw new HttpException(403, 'This action requires Administrator access.');
        }
    }

    public static function mustChangePassword(): bool
    {
        self::start();
        return (bool) ($_SESSION['must_change_password'] ?? false);
    }

    /**
     * The grade level (4, 5 or 6) an Administrator has assigned this teacher to, or null when
     * the teacher is not restricted to one grade (the behaviour of every account before the
     * grade-assignment feature existed). Read from the database on every request — never from
     * the session — so an Admin's change takes effect immediately. Only teaching roles are
     * ever restricted; admin/principal accounts always return null.
     */
    public static function assignedGradeLevel(?int $userId = null): ?int
    {
        static $cache = [];
        $userId = $userId ?? self::userId();
        if ($userId <= 0) {
            return null;
        }
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        try {
            $stmt = get_db()->prepare("SELECT assigned_grade_level FROM users WHERE id = ? AND role IN ('teacher','master_teacher') LIMIT 1");
            $stmt->execute([$userId]);
            $val = $stmt->fetchColumn();
        } catch (PDOException $e) {
            // Migration 004 not applied yet: behave exactly as before (no grade restriction).
            error_log('[sf9] assigned_grade_level unavailable: ' . $e->getMessage());
            $val = null;
        }
        return $cache[$userId] = ($val !== false && $val !== null) ? (int) $val : null;
    }

    /**
     * Server-side enforcement: does the logged-in teacher own this
     * teacher_assignment (section+subject+school year)? Every grade
     * read/write MUST go through this check — never trust the client.
     */
    public static function assertOwnsAssignment(int $teacherAssignmentId): array
    {
        self::requireLogin();
        $pdo = get_db();

        $stmt = $pdo->prepare(
            'SELECT ta.id, ta.teacher_id, ta.section_id, ta.subject_id, ta.school_year_id
             FROM teacher_assignments ta
             WHERE ta.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $teacherAssignmentId]);
        $assignment = $stmt->fetch();

        if (!$assignment || (int) $assignment['teacher_id'] !== self::userId()) {
            // Rendered as JSON by Json::boot() on API calls and as an HTML error page on pages.
            throw new HttpException(403, 'You are not assigned to this class/subject.');
        }

        // A teacher an Admin has assigned to one grade level cannot work in another grade's sections.
        $assigned = self::assignedGradeLevel();
        if ($assigned !== null) {
            $g = $pdo->prepare('SELECT grade_level FROM sections WHERE id = ? LIMIT 1');
            $g->execute([(int) $assignment['section_id']]);
            if ((int) $g->fetchColumn() !== $assigned) {
                throw new HttpException(403, 'This class is outside your assigned grade level (Grade ' . $assigned . ').');
            }
        }

        return $assignment;
    }
}
