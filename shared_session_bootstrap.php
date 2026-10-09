<?php
/**
 * Shared PostgreSQL-backed PHP session storage for serverless deployments.
 * All first-party PHP entry points must load this file instead of calling
 * session_start() directly.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/db_supabase.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        error_log('Shared PHP session bootstrap: database connection unavailable.');
        http_response_code(503);
        exit('Session storage is temporarily unavailable. Please try again.');
    }

    final class BoogieSupabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
    {
        private PDO $pdo;
        private int $lifetime;

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
            $configuredLifetime = (int) (getenv('PHP_SESSION_LIFETIME') ?: 14400);
            $this->lifetime = max(900, min($configuredLifetime, 604800)); // 15 minutes to 7 days
        }

        public function open(string $path, string $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string|false
        {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT session_data
                    FROM private.php_sessions
                    WHERE session_id = :session_id
                      AND last_activity > NOW() - (CAST(:lifetime AS DOUBLE PRECISION) * INTERVAL '1 second')
                    LIMIT 1
                ");
                $stmt->bindValue(':session_id', $id, PDO::PARAM_STR);
                $stmt->bindValue(':lifetime', $this->lifetime, PDO::PARAM_INT);
                $stmt->execute();
                $data = $stmt->fetchColumn();
                return $data === false ? '' : (string) $data;
            } catch (Throwable $e) {
                error_log('Shared PHP session read failed: ' . $e->getMessage());
                return false;
            }
        }

        public function write(string $id, string $data): bool
        {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO private.php_sessions (session_id, session_data, last_activity)
                    VALUES (:session_id, :session_data, NOW())
                    ON CONFLICT (session_id) DO UPDATE
                    SET session_data = EXCLUDED.session_data,
                        last_activity = NOW()
                ");
                $stmt->bindValue(':session_id', $id, PDO::PARAM_STR);
                $stmt->bindValue(':session_data', $data, PDO::PARAM_STR);
                return $stmt->execute();
            } catch (Throwable $e) {
                error_log('Shared PHP session write failed: ' . $e->getMessage());
                return false;
            }
        }

        public function destroy(string $id): bool
        {
            try {
                $stmt = $this->pdo->prepare('DELETE FROM private.php_sessions WHERE session_id = :session_id');
                $stmt->execute([':session_id' => $id]);
                return true;
            } catch (Throwable $e) {
                error_log('Shared PHP session destroy failed: ' . $e->getMessage());
                return false;
            }
        }

        public function gc(int $max_lifetime): int|false
        {
            try {
                $stmt = $this->pdo->prepare("
                    DELETE FROM private.php_sessions
                    WHERE last_activity < NOW() - (CAST(:max_lifetime AS DOUBLE PRECISION) * INTERVAL '1 second')
                ");
                $stmt->bindValue(':max_lifetime', max(1, $max_lifetime), PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->rowCount();
            } catch (Throwable $e) {
                error_log('Shared PHP session cleanup failed: ' . $e->getMessage());
                return false;
            }
        }

        public function validateId(string $id): bool
        {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT 1
                    FROM private.php_sessions
                    WHERE session_id = :session_id
                      AND last_activity > NOW() - (CAST(:lifetime AS DOUBLE PRECISION) * INTERVAL '1 second')
                    LIMIT 1
                ");
                $stmt->bindValue(':session_id', $id, PDO::PARAM_STR);
                $stmt->bindValue(':lifetime', $this->lifetime, PDO::PARAM_INT);
                $stmt->execute();
                return (bool) $stmt->fetchColumn();
            } catch (Throwable $e) {
                error_log('Shared PHP session validation failed: ' . $e->getMessage());
                return false;
            }
        }

        public function updateTimestamp(string $id, string $data): bool
        {
            return $this->write($id, $data);
        }
    }

    // Preserve PHP's standard cookie name/path while securing cookies on HTTPS.
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || $forwardedProto === 'https'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '14400');

    $handler = new BoogieSupabaseSessionHandler($pdo);
    if (!session_set_save_handler($handler, true)) {
        error_log('Shared PHP session bootstrap: unable to register session handler.');
        http_response_code(503);
        exit('Session storage could not be initialized. Please try again.');
    }

    if (!session_start()) {
        error_log('Shared PHP session bootstrap: session_start failed.');
        http_response_code(503);
        exit('Session could not be started. Please try again.');
    }
}
