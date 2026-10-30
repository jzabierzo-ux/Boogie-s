<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function sendAdminNotificationResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    sendAdminNotificationResponse(405, [
        'success' => false,
        'unread' => 0,
        'html' => '',
        'message' => 'Method not allowed.',
    ]);
}

$sessionFlagIsTrue = static function ($value): bool {
    return $value === true
        || $value === 1
        || $value === '1'
        || (is_string($value) && strtolower(trim($value)) === 'true');
};

$loggedIn = $sessionFlagIsTrue($_SESSION['logged_in'] ?? false);
$adminPortalLoggedIn = $sessionFlagIsTrue($_SESSION['admin_logged_in'] ?? false);
$staffPortalLoggedIn = $sessionFlagIsTrue($_SESSION['staff_logged_in'] ?? false);
$rawRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$roleAliases = [
    'administrator' => 'admin',
    'grooming staff' => 'groomer',
    'grooming staff / groomer' => 'groomer',
    'grooming_staff' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'pet hotel staff' => 'pet_hotel_staff',
    'pet hotel staff / attendant' => 'pet_hotel_staff',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
    'shop manager' => 'manager',
    'shop_manager' => 'manager',
    'front desk' => 'receptionist',
    'front desk / receptionist' => 'receptionist',
    'front_desk' => 'receptionist',
    'front_desk_receptionist' => 'receptionist',
    'staff' => 'groomer',
];
$currentRole = $roleAliases[$rawRole] ?? $rawRole;
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$isAdmin = $loggedIn && $adminPortalLoggedIn && $currentRole === 'admin';
$isServiceStaff = $loggedIn
    && $staffPortalLoggedIn
    && in_array($currentRole, ['groomer', 'pet_hotel_staff'], true);

if ((!$isAdmin && !$isServiceStaff) || $userId === false || $userId === null) {
    sendAdminNotificationResponse(403, [
        'success' => false,
        'unread' => 0,
        'html' => '<div class="notif-empty">Notifications are unavailable for this account.</div>',
        'message' => 'Unauthorized access.',
    ]);
}

// The shared session bootstrap normally loads the PDO connection already.
// Keep this include as a safe fallback for requests where a session was started earlier.
require_once __DIR__ . '/../db_supabase.php';

function formatAdminNotificationDate($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }

    try {
        $timestamp = new DateTimeImmutable(trim((string)$value), new DateTimeZone('UTC'));
        return $timestamp->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, g:i A');
    } catch (Throwable $e) {
        error_log('Notification timestamp format error: ' . $e->getMessage());
        return '';
    }
}


/**
 * Detect the existing is_read column type so unread comparisons remain
 * correct for boolean, numeric, and text-backed notification tables.
 * Table names are restricted to this code-defined allow-list.
 */
function notificationUnreadPredicate(PDO $pdo, string $tableName): string
{
    $allowedTables = ['admin_notifications', 'notifications'];
    if (!in_array($tableName, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported notifications table.');
    }

    $typeStmt = $pdo->prepare("SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = :table_name
          AND column_name = 'is_read'
        LIMIT 1");
    $typeStmt->execute([':table_name' => $tableName]);
    $dataType = strtolower(trim((string)$typeStmt->fetchColumn()));

    if ($dataType === 'boolean') {
        return 'COALESCE(is_read, FALSE) = FALSE';
    }
    if (in_array($dataType, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
        return '(is_read = 0 OR is_read IS NULL)';
    }
    if (in_array($dataType, ['text', 'character varying', 'character'], true)) {
        return "(is_read IS NULL OR LOWER(BTRIM(CAST(is_read AS TEXT))) IN ('0', 'false', 'f', 'no', 'unread', ''))";
    }

    throw new RuntimeException('Unsupported or unknown is_read column type for ' . $tableName . '.');
}

function renderAdminNotificationItems(array $notifications, bool $staffNotifications): string
{
    $htmlParts = [];
    $iconMap = [
        'success' => 'fa-circle-check',
        'completed' => 'fa-circle-check',
        'booking' => 'fa-calendar-check',
        'appointment' => 'fa-calendar-check',
        'warning' => 'fa-triangle-exclamation',
        'error' => 'fa-circle-exclamation',
        'alert' => 'fa-circle-exclamation',
        'system' => 'fa-bell',
        'announcement' => 'fa-bullhorn',
    ];

    foreach ($notifications as $notification) {
        $title = trim((string)($notification['title'] ?? ''));
        $message = trim((string)($notification['message'] ?? ''));
        $type = strtolower(trim((string)($notification['type'] ?? '')));
        $icon = $iconMap[$type] ?? 'fa-circle-exclamation';

        $item = '<div class="notif-item">';
        $item .= '<i class="fa-solid ' . $icon . '" style="color:#e11d48;margin-right:5px;" aria-hidden="true"></i>';

        if ($staffNotifications && $title !== '') {
            $item .= '<strong style="display:block;margin-bottom:3px;">'
                . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong>';
        }

        if ($message !== '') {
            $item .= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } elseif ($title !== '') {
            $item .= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } else {
            $item .= 'New notification';
        }

        $createdAt = formatAdminNotificationDate($notification['created_at'] ?? null);
        if ($createdAt !== '') {
            $item .= '<br><small style="color:#94a3b8;font-size:11px;">'
                . htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</small>';
        }

        $item .= '</div>';
        $htmlParts[] = $item;
    }

    if ($htmlParts === []) {
        $emptyText = $staffNotifications ? 'No new service notifications.' : 'No new notifications.';
        return '<div class="notif-empty">' . $emptyText . '</div>';
    }

    return implode('', $htmlParts);
}

try {
    if ($isAdmin) {
        $unreadPredicate = notificationUnreadPredicate($pdo, 'admin_notifications');
        // Window count returns the full unread total while limiting the displayed list to 20.
        $stmt = $pdo->query("SELECT id, message, created_at,
                    COUNT(*) OVER() AS total_unread
                FROM admin_notifications
                WHERE {$unreadPredicate}
                ORDER BY created_at DESC NULLS LAST, id DESC
                LIMIT 20");
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unreadCount = (int)($notifications[0]['total_unread'] ?? 0);
        $html = renderAdminNotificationItems($notifications, false);
    } else {
        // Service staff only receive unread notification rows addressed to their own user ID.
        $unreadPredicate = notificationUnreadPredicate($pdo, 'notifications');
        $stmt = $pdo->prepare("SELECT id, title, message, type, created_at,
                    COUNT(*) OVER() AS total_unread
                FROM notifications
                WHERE user_id = :user_id
                  AND {$unreadPredicate}
                ORDER BY created_at DESC NULLS LAST, id DESC
                LIMIT 20");
        $stmt->execute([':user_id' => (int)$userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unreadCount = (int)($notifications[0]['total_unread'] ?? 0);
        $html = renderAdminNotificationItems($notifications, true);
    }

    sendAdminNotificationResponse(200, [
        'success' => true,
        'unread' => $unreadCount,
        'html' => $html,
    ]);
} catch (Throwable $e) {
    error_log('Notification fetch failed: ' . $e->getMessage());
    sendAdminNotificationResponse(500, [
        'success' => false,
        'unread' => 0,
        'html' => '<div class="notif-empty">Notifications are temporarily unavailable.</div>',
        'message' => 'Unable to fetch notifications.',
    ]);
}
