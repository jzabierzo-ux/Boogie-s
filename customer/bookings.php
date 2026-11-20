<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

require_once __DIR__ . '/../db_supabase.php';
require_once __DIR__ . '/../includes/iprog_sms.php';

date_default_timezone_set('Asia/Manila');

/**
 * Format notification timestamps consistently in Philippine time.
 * Supabase/PostgreSQL timestamp strings may include an explicit offset (e.g. +00).
 * If no offset is present, treat created_at as UTC, then convert to Asia/Manila.
 */
function formatMyScheduleNotificationTime($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }

    $rawTimestamp = trim((string)$value);

    try {
        $hasExplicitTimezone = preg_match('/(?:Z|[+-]\\d{2}(?::?\\d{2})?|UTC|GMT)$/i', $rawTimestamp) === 1;
        $timestamp = $hasExplicitTimezone
            ? new DateTimeImmutable($rawTimestamp)
            : new DateTimeImmutable($rawTimestamp, new DateTimeZone('UTC'));

        return $timestamp
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('M d, Y h:i A');
    } catch (Throwable $e) {
        error_log('Unable to format notification timestamp: ' . $e->getMessage());
        return '';
    }
}

// ============================================================
// ACCESS CONTROL
// ============================================================

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$full_name = $_SESSION['user_name']
    ?? $_SESSION['full_name']
    ?? 'User';

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Shared CSRF token used by cancellation and rescheduling forms.
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Same-file AJAX endpoint: refresh the customer's unread count and recent list.
// The user_id condition is applied to both queries so customers only receive their own data.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['ajax'] ?? '') === 'notifications') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0, must-revalidate');
    header('Pragma: no-cache');

    if ($user_id <= 0) {
        http_response_code(401);
        echo json_encode(['unread' => null, 'notifications' => [], 'error' => 'Authentication required']);
        exit();
    }

    try {
        // Cast is_read to text to support PostgreSQL BOOLEAN and numeric/text columns.
        $ajax_count_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = :user_id
              AND (LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') OR is_read IS NULL)
        ");
        $ajax_count_stmt->execute([':user_id' => $user_id]);
        $ajax_unread_count = (int)($ajax_count_stmt->fetchColumn() ?? 0);

        $ajax_list_stmt = $pdo->prepare("
            SELECT
                id,
                message,
                created_at,
                CASE
                    WHEN is_read IS NULL OR LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') THEN 'false'
                    ELSE 'true'
                END AS is_read_normalized
            FROM notifications
            WHERE user_id = :user_id
            ORDER BY created_at DESC, id DESC
            LIMIT 5
        ");
        $ajax_list_stmt->execute([':user_id' => $user_id]);
        $ajax_notifications = $ajax_list_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ajax_notifications as &$ajax_notification) {
            $ajax_notification['is_read'] = strtolower(trim((string)($ajax_notification['is_read_normalized'] ?? 'false'))) === 'true';
            unset($ajax_notification['is_read_normalized']);
            $ajax_notification['time'] = formatMyScheduleNotificationTime($ajax_notification['created_at'] ?? null);
            $ajax_notification['message'] = (string)($ajax_notification['message'] ?? '');
        }
        unset($ajax_notification);

        echo json_encode([
            'unread' => $ajax_unread_count,
            'notifications' => $ajax_notifications,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('Bookings notification refresh failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['unread' => null, 'notifications' => [], 'error' => 'Unable to refresh notifications']);
    }
    exit();
}

// ============================================================
// ONE-TIME RESCHEDULE HANDLER
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reschedule_booking'])) {
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $new_date = trim((string)($_POST['reschedule_date'] ?? ''));
    $new_time = trim((string)($_POST['reschedule_time'] ?? ''));

    // Rescheduling follows the same duration-aware schedule as new bookings:
    // opening at 9:00 AM, lunch break 12:00 PM-1:00 PM, latest regular start 4:00 PM,
    // and regular-service/daycare pickup cutoff at 5:00 PM.
    $rescheduleTimeToMinutes = static function ($time): ?int {
        $value = trim((string)($time ?? ''));
        if (!preg_match('/^(?:0[9]|1[0-6]):(?:00|30):00$/', $value, $matches)) {
            return null;
        }
        return ((int)substr($value, 0, 2) * 60) + (int)substr($value, 3, 2);
    };

    $reschedulePickupToMinutes = static function ($time): ?int {
        $value = trim((string)($time ?? ''));
        if (!preg_match('/^(?:0[9]|1[0-7]):(?:00|30)(?::00)?$/', $value, $matches)) {
            return null;
        }
        return ((int)substr($value, 0, 2) * 60) + (int)substr($value, 3, 2);
    };

    $rescheduleServiceDuration = static function (string $category, string $serviceName): int {
        $name = strtolower(trim($serviceName));

        if ($category === 'Grooming') {
            if (strpos($name, 'full grooming package') !== false || preg_match('/\bfg\b/', $name)) return 120;
            if (strpos($name, 'bath & blow dry') !== false || strpos($name, 'bath and blow dry') !== false || strpos($name, 'cat bath') !== false) return 90;
            if (strpos($name, 'basic pet grooming') !== false || strpos($name, 'cat grooming (basic)') !== false) return 60;
            return 120; // Conservative default for unrecognized grooming labels.
        }

        if ($category === 'Vet Services') return 30;

        if ($category === 'Pet Hotel') {
            if (strpos($name, 'daycare') !== false) return 180; // Pickup time controls the actual daycare interval.
            if (strpos($name, 'boarding') !== false || strpos($name, 'overnight') !== false) return 30;
            return 180;
        }

        return 0;
    };

    $rescheduleAllowedTimes = static function (string $category, string $serviceName) use ($rescheduleServiceDuration): array {
        $name = strtolower(trim($serviceName));
        $openingMinutes = 9 * 60;
        $breakStartMinutes = 12 * 60;
        $breakEndMinutes = 13 * 60;
        $latestStartMinutes = 16 * 60;
        $serviceCutoffMinutes = 17 * 60;

        if ($category === 'Pet Hotel' && (strpos($name, 'boarding') !== false || strpos($name, 'overnight') !== false)) {
            return ['09:00:00'];
        }

        if ($category === 'Pet Hotel' && strpos($name, 'daycare') !== false) {
            $times = [];
            foreach ([[$openingMinutes, $breakStartMinutes], [$breakEndMinutes, $latestStartMinutes + 30]] as [$periodStart, $periodEnd]) {
                for ($start = $periodStart; $start < $periodEnd && $start <= $latestStartMinutes; $start += 30) {
                    $times[] = sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);
                }
            }
            return array_values(array_unique($times));
        }

        $duration = $rescheduleServiceDuration($category, $serviceName);
        if ($duration <= 0) return [];

        $times = [];
        foreach ([[$openingMinutes, $breakStartMinutes], [$breakEndMinutes, $serviceCutoffMinutes + 1]] as [$periodStart, $periodEnd]) {
            for (
                $start = $periodStart;
                $start < $periodEnd &&
                $start <= $latestStartMinutes &&
                ($start + $duration) <= $serviceCutoffMinutes &&
                // Do not let a regular service overlap the 12:00 PM-1:00 PM break.
                ($start + $duration) <= $periodEnd;
                $start += $duration
            ) {
                $times[] = sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);
            }
        }

        return array_values(array_unique($times));
    };

    $setRescheduleError = static function ($message) use ($pdo) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['booking_reschedule_error'] = $message;
        header("Location: bookings.php");
        exit();
    };

    // Reject forged reschedule submissions.
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (
        $sessionToken === '' ||
        $postedToken === '' ||
        !hash_equals($sessionToken, $postedToken)
    ) {
        $setRescheduleError('Your session token expired. Refresh the page and try again.');
    }

    if ($appointment_id <= 0 || $new_date === '' || $new_time === '') {
        $setRescheduleError("Please choose a valid new date and time.");
    }

    $new_date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $new_date);
    $new_date_errors = DateTimeImmutable::getLastErrors();
    if (
        $new_date_object === false ||
        $new_date_object->format('Y-m-d') !== $new_date ||
        ($new_date_errors !== false && ($new_date_errors['warning_count'] > 0 || $new_date_errors['error_count'] > 0))
    ) {
        $setRescheduleError("Please select a valid new date.");
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.*,
                p.name AS pet_name,
                u.full_name AS customer_name,
                u.contact_number
            FROM appointments a
            LEFT JOIN pets p ON a.pet_id = p.id
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.id = :appointment_id
              AND a.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            ':appointment_id' => $appointment_id,
            ':user_id' => $user_id
        ]);

        $appointment = $stmt->fetch();

        if (!$appointment) {
            $setRescheduleError("Booking not found or you do not have permission to reschedule it.");
        }

        $bookingGroupId = trim((string)($appointment['booking_group_id'] ?? ''));

        // Pet Group rescheduling moves every linked pet row in one transaction.
        // The customer selects the group's earliest start; the server assigns
        // each pet to the earliest available 30-minute start while enforcing
        // two groomers, one groomer during lunch, existing bookings and daily limits.
        if ($bookingGroupId !== '') {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $bookingGroupId)) {
                $setRescheduleError('The Pet Group booking reference is invalid. Please contact the clinic.');
            }

            try {
                $pdo->beginTransaction();

                $groupService = trim((string)($appointment['service'] ?? ''));
                if (stripos($groupService, 'Grooming -') !== 0) {
                    throw new DomainException('Only Grooming Pet Group bookings can be rescheduled online right now. Please contact the clinic for assistance.');
                }

                // Use the same category/date advisory lock as the online booking flow.
                $groupCapacityLock = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:lock_key)::bigint)");
                $groupCapacityLock->execute([
                    ':lock_key' => 'boogie-capacity|' . $new_date
                ]);

                $groupRowsStmt = $pdo->prepare("
                    SELECT a.*,
                           p.name AS pet_name,
                           u.full_name AS customer_name,
                           u.contact_number
                    FROM appointments a
                    LEFT JOIN pets p ON p.id = a.pet_id
                    LEFT JOIN users u ON u.id = a.user_id
                    WHERE a.booking_group_id = CAST(:group_id AS uuid)
                      AND a.user_id = :user_id
                    ORDER BY a.appointment_time ASC, a.id ASC
                    FOR UPDATE OF a
                ");
                $groupRowsStmt->execute([
                    ':group_id' => $bookingGroupId,
                    ':user_id' => $user_id
                ]);
                $groupRows = $groupRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $groupCount = count($groupRows);

                if ($groupCount < 2 || $groupCount > 20) {
                    throw new DomainException('The Pet Group records are incomplete or invalid. Please contact the clinic.');
                }

                $originalDate = substr((string)($groupRows[0]['appointment_date'] ?? ''), 0, 10);
                $groupService = trim((string)($groupRows[0]['service'] ?? ''));
                $hasRescheduleFlag = false;
                $petNames = [];
                $groupPetIds = [];

                foreach ($groupRows as $groupMember) {
                    $memberDate = substr((string)($groupMember['appointment_date'] ?? ''), 0, 10);
                    $memberService = trim((string)($groupMember['service'] ?? ''));
                    $memberStatus = strtoupper(trim((string)($groupMember['booking_status'] ?? '')));
                    $memberPaymentStatus = strtoupper(trim((string)($groupMember['payment_status'] ?? '')));
                    $memberRescheduleCount = (int)($groupMember['reschedule_count'] ?? 0);

                    if ($memberDate !== $originalDate || $memberService !== $groupService) {
                        throw new DomainException('The Pet Group has inconsistent dates or services. Please contact the clinic before rescheduling.');
                    }
                    if (!in_array($memberStatus, ['FOR RESCHEDULING', 'CONFIRMED'], true)) {
                        throw new DomainException('All pets in the group must still be eligible for rescheduling. A completed, cancelled, or no-show pet prevents a group reschedule.');
                    }
                    if ($memberPaymentStatus !== 'PAID') {
                        throw new DomainException('All pets in the group must have a verified payment before rescheduling.');
                    }
                    if ($memberRescheduleCount >= 1) {
                        throw new DomainException('This Pet Group has already used its one allowed reschedule.');
                    }
                    if ($memberStatus === 'FOR RESCHEDULING') {
                        $hasRescheduleFlag = true;
                    }

                    $petNames[] = trim((string)($groupMember['pet_name'] ?? 'Pet')) ?: 'Pet';
                    $groupPetIds[] = (int)($groupMember['id'] ?? 0);
                }

                if (!$hasRescheduleFlag) {
                    throw new DomainException('This Pet Group has not been marked For Rescheduling by the clinic yet.');
                }
                if ($originalDate === '' || $originalDate === '1970-01-01') {
                    throw new DomainException('The original Pet Group appointment date is invalid.');
                }

                $todayManila = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
                $nowManila = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                $todayString = $todayManila->format('Y-m-d');
                $nowString = $nowManila->format('H:i:s');
                $latestGroupRescheduleDate = (new DateTimeImmutable($originalDate, new DateTimeZone('Asia/Manila')))
                    ->modify('+3 days')->format('Y-m-d');

                if ($new_date_object < $todayManila) {
                    throw new DomainException('You cannot reschedule to a past date.');
                }
                if ($new_date > $latestGroupRescheduleDate) {
                    throw new DomainException('Group rescheduling is only allowed within 3 days after the original appointment date.');
                }
                if ($new_date === $todayString && $new_time <= $nowString) {
                    throw new DomainException('Please select a future start time for the new group schedule.');
                }
                if ($new_date === $todayString && $nowManila->format('H:i:s') >= '16:00:00') {
                    throw new DomainException('Same-day rescheduling is closed after 4:00 PM. Please choose another date.');
                }

                $durationMinutes = $rescheduleServiceDuration('Grooming', $groupService);
                $baseStartMinutes = $rescheduleTimeToMinutes($new_time);
                if ($durationMinutes <= 0 || $baseStartMinutes === null
                    || !in_array($new_time, $rescheduleAllowedTimes('Grooming', $groupService), true)) {
                    throw new DomainException('Please choose a valid group start time for this Grooming service.');
                }

                // Read active appointments on the target date, excluding this group
                // because all its members will be moved and reinserted into the schedule.
                $activeBookingSql = "LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')";
                $targetRowsStmt = $pdo->prepare("
                    SELECT appointment_date::text AS appointment_date,
                           appointment_time::text AS appointment_time,
                           checkout_date::text AS checkout_date,
                           service,
                           booking_status
                    FROM appointments
                    WHERE {$activeBookingSql}
                      AND (
                          appointment_date = CAST(:target_date AS date)
                          OR (
                              service ILIKE 'Pet Hotel%boarding%'
                              AND appointment_date < CAST(:target_date_before AS date)
                              AND COALESCE(checkout_date, appointment_date + 1) > CAST(:target_date_active AS date)
                          )
                      )
                      AND (booking_group_id IS NULL OR booking_group_id <> CAST(:group_id AS uuid))
                    ORDER BY appointment_time ASC
                ");
                $targetRowsStmt->execute([
                    ':target_date' => $new_date,
                    ':target_date_before' => $new_date,
                    ':target_date_active' => $new_date,
                    ':group_id' => $bookingGroupId
                ]);
                $targetRows = $targetRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $dailyTotal = count($targetRows);
                $groomingDailyCount = 0;
                $busyIntervals = [];
                foreach ($targetRows as $targetRow) {
                    $targetService = trim((string)($targetRow['service'] ?? ''));
                    $targetRowDate = substr((string)($targetRow['appointment_date'] ?? ''), 0, 10);
                    if ($targetRowDate === $new_date && stripos($targetService, 'Grooming -') === 0) {
                        $groomingDailyCount++;
                        $existingStart = $rescheduleTimeToMinutes((string)($targetRow['appointment_time'] ?? ''));
                        if ($existingStart !== null) {
                            $busyIntervals[] = [
                                'start' => $existingStart,
                                'end' => $existingStart + $rescheduleServiceDuration('Grooming', $targetService)
                            ];
                        }
                    }
                }

                // Include Admin-recorded occupied pets and unavailable grooming staff
                // so the reschedule cannot bypass manual capacity controls.
                $manualAdjustments = [];
                $adjustmentTableExists = (bool)$pdo->query("SELECT to_regclass('public.availability_adjustments')")->fetchColumn();
                if ($adjustmentTableExists) {
                    $adjustmentStmt = $pdo->prepare("
                        SELECT service_category, COALESCE(specific_service, '') AS specific_service,
                               start_date::text AS start_date, end_date::text AS end_date,
                               start_time::text AS start_time, end_time::text AS end_time,
                               LOWER(BTRIM(adjustment_type)) AS adjustment_type, quantity
                        FROM public.availability_adjustments
                        WHERE is_active = TRUE
                          AND start_date < CAST(:range_end AS date)
                          AND end_date > CAST(:range_start AS date)
                    ");
                    $nextDate = (new DateTimeImmutable($new_date, new DateTimeZone('Asia/Manila')))
                        ->modify('+1 day')->format('Y-m-d');
                    $adjustmentStmt->execute([
                        ':range_start' => $new_date,
                        ':range_end' => $nextDate
                    ]);
                    $manualAdjustments = $adjustmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }

                $manualGlobalOccupied = 0;
                $manualGroomingOccupied = 0;
                foreach ($manualAdjustments as $adjustment) {
                    $adjustmentStartDate = substr((string)($adjustment['start_date'] ?? ''), 0, 10);
                    $adjustmentEndDate = substr((string)($adjustment['end_date'] ?? ''), 0, 10);
                    if ($adjustmentStartDate === '' || $adjustmentEndDate === ''
                        || $adjustmentStartDate > $new_date || $adjustmentEndDate <= $new_date) {
                        continue;
                    }

                    $adjustmentType = strtolower(trim((string)($adjustment['adjustment_type'] ?? '')));
                    $adjustmentCategory = trim((string)($adjustment['service_category'] ?? ''));
                    $adjustmentService = trim((string)($adjustment['specific_service'] ?? ''));
                    $quantity = max(1, (int)($adjustment['quantity'] ?? 1));

                    if ($adjustmentType === 'occupied') {
                        $manualGlobalOccupied += $quantity;
                        if ($adjustmentCategory === 'Grooming') {
                            $manualGroomingOccupied += $quantity;
                        }
                    }

                    if ($adjustmentCategory !== 'Grooming'
                        || !in_array($adjustmentType, ['occupied', 'blocked', 'capacity_reduced'], true)) {
                        continue;
                    }
                    if (in_array($adjustmentType, ['blocked', 'capacity_reduced'], true)
                        && $adjustmentService !== ''
                        && stripos($groupService, $adjustmentService) === false) {
                        continue;
                    }

                    $rawStart = trim((string)($adjustment['start_time'] ?? ''));
                    $rawEnd = trim((string)($adjustment['end_time'] ?? ''));
                    $parseAdjustmentMinute = static function (string $value): ?int {
                        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches)) return null;
                        $hour = (int)$matches[1];
                        $minute = (int)$matches[2];
                        if ($hour > 23 || $minute > 59) return null;
                        return ($hour * 60) + $minute;
                    };
                    $adjustmentStart = $rawStart === '' ? 0 : $parseAdjustmentMinute($rawStart);
                    $adjustmentEnd = $rawEnd === '' ? 1440 : $parseAdjustmentMinute($rawEnd);
                    if ($adjustmentStart === null || $adjustmentEnd === null || $adjustmentEnd <= $adjustmentStart) {
                        continue;
                    }
                    $units = $adjustmentType === 'blocked' ? 2 : $quantity;
                    for ($unit = 0; $unit < $units; $unit++) {
                        $busyIntervals[] = ['start' => $adjustmentStart, 'end' => $adjustmentEnd];
                    }
                }

                $dailyTotal += $manualGlobalOccupied;
                $groomingDailyCount += $manualGroomingOccupied;
                if (($dailyTotal + $groupCount) > 20) {
                    throw new DomainException('The overall daily capacity of 20 pets would be exceeded by this group. Please choose another date.');
                }
                if (($groomingDailyCount + $groupCount) > 10) {
                    throw new DomainException('Grooming daily capacity would be exceeded by this group. Please choose another date.');
                }

                // A candidate interval must fit at every occupancy change. Grooming
                // capacity drops from two to one between 12:00 PM and 1:00 PM.
                $canFitInterval = static function (int $candidateStart, int $candidateEnd, array $intervals): bool {
                    if ($candidateStart < 9 * 60 || $candidateStart > 16 * 60
                        || $candidateEnd > 17 * 60
                        || ($candidateStart >= 12 * 60 && $candidateStart < 13 * 60)) {
                        return false;
                    }

                    $points = [$candidateStart, 12 * 60, 13 * 60];
                    foreach ($intervals as $interval) {
                        $intervalStart = (int)($interval['start'] ?? 0);
                        $intervalEnd = (int)($interval['end'] ?? 0);
                        if ($intervalStart < $candidateEnd && $candidateStart < $intervalEnd) {
                            if ($intervalStart > $candidateStart && $intervalStart < $candidateEnd) $points[] = $intervalStart;
                            if ($intervalEnd > $candidateStart && $intervalEnd < $candidateEnd) $points[] = $intervalEnd;
                        }
                    }
                    $points = array_values(array_unique(array_filter($points, static function ($point) use ($candidateStart, $candidateEnd) {
                        return $point >= $candidateStart && $point < $candidateEnd;
                    })));
                    sort($points, SORT_NUMERIC);

                    foreach ($points as $point) {
                        $active = 0;
                        foreach ($intervals as $interval) {
                            if ((int)($interval['start'] ?? 0) <= $point && (int)($interval['end'] ?? 0) > $point) {
                                $active++;
                            }
                        }
                        $capacity = ($point >= 12 * 60 && $point < 13 * 60) ? 1 : 2;
                        if ($active + 1 > $capacity) return false;
                    }
                    return true;
                };

                $groupSchedule = [];
                foreach ($groupRows as $memberIndex => $groupMember) {
                    $selectedStart = null;
                    $candidateStarts = [];
                    if ($memberIndex === 0) {
                        // The first pet must begin at the selected group start time.
                        $candidateStarts[] = $baseStartMinutes;
                    } else {
                        // Later pets are assigned in order to the earliest available
                        // groomer slot, in 30-minute increments from the chosen start.
                        for ($candidate = $baseStartMinutes; $candidate <= 16 * 60; $candidate += 30) {
                            $candidateStarts[] = $candidate;
                        }
                    }

                    foreach ($candidateStarts as $candidateStart) {
                        $candidateEnd = $candidateStart + $durationMinutes;
                        if (!$canFitInterval($candidateStart, $candidateEnd, $busyIntervals)) continue;
                        $selectedStart = $candidateStart;
                        $busyIntervals[] = ['start' => $candidateStart, 'end' => $candidateEnd];
                        break;
                    }

                    if ($selectedStart === null) {
                        throw new DomainException('There is not enough Grooming capacity to reschedule all pets from this start time and finish by 5:00 PM. Please choose an earlier time or another date.');
                    }
                    $groupSchedule[] = $selectedStart;
                }

                // All rows are locked and validated. Update each pet's own row with
                // its calculated start time, but commit the group as one operation.
                $groupUpdateStmt = $pdo->prepare("
                    UPDATE appointments
                    SET appointment_date = CAST(:appointment_date AS date),
                        appointment_time = CAST(:appointment_time AS time),
                        booking_status = 'Rescheduled',
                        reschedule_count = 1
                    WHERE id = :appointment_id
                      AND user_id = :user_id
                      AND booking_group_id = CAST(:group_id AS uuid)
                      AND reschedule_count = 0
                      AND LOWER(BTRIM(COALESCE(booking_status, ''))) IN ('confirmed', 'for rescheduling')
                      AND LOWER(BTRIM(COALESCE(payment_status, ''))) = 'paid'
                ");

                foreach ($groupRows as $index => $groupMember) {
                    $groupUpdateStmt->execute([
                        ':appointment_date' => $new_date,
                        ':appointment_time' => sprintf('%02d:%02d:00', intdiv($groupSchedule[$index], 60), $groupSchedule[$index] % 60),
                        ':appointment_id' => (int)$groupMember['id'],
                        ':user_id' => $user_id,
                        ':group_id' => $bookingGroupId
                    ]);
                    if ($groupUpdateStmt->rowCount() !== 1) {
                        throw new RuntimeException('Not all pet appointments in the group could be updated. The group reschedule was rolled back.');
                    }
                }

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                $formattedGroupDate = date('M d, Y', strtotime($new_date));
                $formattedGroupStart = date('g:i A', strtotime($new_time));
                $groupPetNames = implode(', ', array_values(array_filter($petNames)));
                $groupSummary = $groupCount . ' pets (' . $groupPetNames . ')';

                // One Admin notification and one customer notification for the group.
                try {
                    $adminGroupMessage = ($groupRows[0]['customer_name'] ?? $full_name)
                        . ' rescheduled Pet Group (' . $groupPetNames . ') to '
                        . $formattedGroupDate . ' starting at ' . $formattedGroupStart . '.';
                    if (function_exists('mb_substr')) {
                        $adminGroupMessage = mb_substr($adminGroupMessage, 0, 250, 'UTF-8');
                    } else {
                        $adminGroupMessage = substr($adminGroupMessage, 0, 250);
                    }
                    $adminGroupNotifStmt = $pdo->prepare('INSERT INTO admin_notifications (message) VALUES (:message)');
                    $adminGroupNotifStmt->execute([':message' => $adminGroupMessage]);
                } catch (Throwable $adminGroupNotifError) {
                    error_log('Admin group reschedule notification failed: ' . $adminGroupNotifError->getMessage());
                }

                try {
                    $customerGroupMessage = 'Your Pet Group booking for ' . $groupSummary
                        . ' (' . $groupService . ') has been rescheduled to ' . $formattedGroupDate
                        . '. Check My Schedule for each pet\'s updated time.';
                    $customerGroupNotifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
                        VALUES (:user_id, 'Pet Group Rescheduled', :message, 'booking', 0, NOW())
                    ");
                    $customerGroupNotifStmt->execute([
                        ':user_id' => $user_id,
                        ':message' => $customerGroupMessage
                    ]);
                } catch (Throwable $customerGroupNotifError) {
                    error_log('Customer group reschedule notification failed: ' . $customerGroupNotifError->getMessage());
                }

                $contactNumber = trim((string)($groupRows[0]['contact_number'] ?? ''));
                if ($contactNumber !== '' && strtoupper($contactNumber) !== 'N/A') {
                    $groupSmsMessage = 'Hi! Your Pet Group booking for ' . $groupCount . ' pets has been RESCHEDULED to '
                        . $formattedGroupDate . ' starting at ' . $formattedGroupStart
                        . '. Check My Schedule for each pet\'s updated time. - Boogie\'s Pet Care';
                    try {
                        $groupSmsResult = sendIPROGSMS($contactNumber, $groupSmsMessage);
                        if (empty($groupSmsResult['success'])) {
                            error_log('IPROG SMS failed after Pet Group reschedule: ' . ($groupSmsResult['response'] ?? 'Unknown error'));
                        }
                    } catch (Throwable $groupSmsError) {
                        error_log('IPROG SMS exception after Pet Group reschedule: ' . $groupSmsError->getMessage());
                    }
                }

                unset($_SESSION['booking_reschedule_error']);
                $_SESSION['booking_reschedule_success'] = 'Your entire Pet Group (' . $groupCount
                    . ' pets) was successfully rescheduled to ' . $formattedGroupDate
                    . '. Each pet now has its own updated Grooming time. Check My Schedule for the full schedule.';
                header('Location: bookings.php');
                exit();

            } catch (DomainException $groupValidationError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $setRescheduleError($groupValidationError->getMessage());
            } catch (PDOException $groupDatabaseError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Pet Group reschedule database error: ' . $groupDatabaseError->getMessage());
                $setRescheduleError('Unable to reschedule this Pet Group right now. Please try again or contact the clinic.');
            } catch (Throwable $groupUnexpectedError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Pet Group reschedule error: ' . $groupUnexpectedError->getMessage());
                $setRescheduleError('The Pet Group reschedule could not be completed. No group appointments were changed.');
            }
        }

        $booking_status = strtoupper(trim((string)($appointment['booking_status'] ?? '')));
        $reschedule_count = (int)($appointment['reschedule_count'] ?? 0);
        $payment_status = strtoupper(trim((string)($appointment['payment_status'] ?? '')));

        if ($booking_status !== 'FOR RESCHEDULING' || $reschedule_count >= 1) {
            $setRescheduleError("This booking is no longer eligible for rescheduling. Only one reschedule is allowed.");
        }

        if ($payment_status !== 'PAID') {
            $setRescheduleError("Only paid and verified bookings can be rescheduled.");
        }

        $today = date('Y-m-d');
        $current_time = date('H:i:s');
        $original_date = date('Y-m-d', strtotime((string)($appointment['appointment_date'] ?? '')));
        $latest_reschedule_date = date('Y-m-d', strtotime($original_date . ' +3 days'));

        if ($new_date < $today) {
            $setRescheduleError("You cannot reschedule to a past date.");
        }

        if ($original_date === '' || $original_date === '1970-01-01') {
            $setRescheduleError("The original appointment date is invalid.");
        }

        if ($new_date > $latest_reschedule_date) {
            $setRescheduleError("Rescheduling is only allowed within 3 days after the original appointment date.");
        }

        if ($new_date === $today && $new_time <= $current_time) {
            $setRescheduleError("Please select a future time for the new appointment.");
        }

        if ($new_date === $today && $current_time >= '16:00:00') {
            $setRescheduleError("Same-day rescheduling is closed after 4:00 PM because the latest appointment start time has passed. Please choose another date.");
        }

        $stored_service = (string)($appointment['service'] ?? '');
        $service_category = '';

        if (stripos($stored_service, 'Grooming') === 0) {
            $service_category = 'Grooming';
        } elseif (stripos($stored_service, 'Vet Services') === 0) {
            $service_category = 'Vet Services';
        } elseif (stripos($stored_service, 'Pet Hotel') === 0) {
            $service_category = 'Pet Hotel';
        }

        if ($service_category === '') {
            $setRescheduleError("Unable to determine the service category for this booking.");
        }

        // Enforce service-specific reschedule times on the server; hiding a time in the
        // dropdown alone is not sufficient because requests can be manually altered.
        $allowed_reschedule_times = $rescheduleAllowedTimes($service_category, $stored_service);
        if (!in_array($new_time, $allowed_reschedule_times, true)) {
            $setRescheduleError("Please select a valid time for this service. Regular services must finish by 5:00 PM, and the latest regular appointment start is 4:00 PM.");
        }

        $new_start_minutes = $rescheduleTimeToMinutes($new_time);
        if ($new_start_minutes === null) {
            $setRescheduleError("Please select a valid appointment time.");
        }

        if ($service_category === 'Pet Hotel' && stripos($stored_service, 'daycare') !== false) {
            $pickup_minutes = $reschedulePickupToMinutes($appointment['pickup_time'] ?? '');
            if ($pickup_minutes === null || $pickup_minutes <= $new_start_minutes || $pickup_minutes > (17 * 60)) {
                $setRescheduleError("Pet Daycare pickup must be later than drop-off and no later than 5:00 PM. Please contact the clinic to update the pickup time before rescheduling.");
            }
        } elseif ($service_category !== 'Pet Hotel' || (stripos($stored_service, 'boarding') === false && stripos($stored_service, 'overnight') === false)) {
            $service_duration = $rescheduleServiceDuration($service_category, $stored_service);
            if ($service_duration <= 0 || ($new_start_minutes + $service_duration) > (17 * 60)) {
                $setRescheduleError("This appointment must be scheduled so the service finishes by 5:00 PM.");
            }
        }

        // Use the same transaction lock as new bookings before counting capacity.
        $pdo->beginTransaction();
        $capacity_lock = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:lock_key)::bigint)");
        $capacity_lock->execute([
            ':lock_key' => 'boogie-capacity|' . $new_date . '|' . $service_category
        ]);

        $slot_stmt = $pdo->prepare("
            SELECT COUNT(*) AS slot_count
            FROM appointments
            WHERE appointment_date = :appointment_date
              AND appointment_time = :appointment_time
              AND service ILIKE :service_prefix
              AND id <> :appointment_id
              AND LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')
        ");
        $slot_stmt->execute([
            ':appointment_date' => $new_date,
            ':appointment_time' => $new_time,
            ':service_prefix' => $service_category . '%',
            ':appointment_id' => $appointment_id
        ]);

        $slot_count = (int)($slot_stmt->fetch()['slot_count'] ?? 0);

        if ($slot_count >= 1) {
            $setRescheduleError(
                "The " . date("g:i A", strtotime($new_time)) .
                " slot is already taken for " . $service_category . ". Please choose another time."
            );
        }

        if ($service_category === 'Vet Services') {
            $daily_stmt = $pdo->prepare("
                SELECT COUNT(*) AS vet_count
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND service ILIKE 'Vet Services%'
                  AND id <> :appointment_id
                  AND LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')
            ");
            $daily_stmt->execute([
                ':appointment_date' => $new_date,
                ':appointment_id' => $appointment_id
            ]);

            if ((int)($daily_stmt->fetch()['vet_count'] ?? 0) >= 6) {
                $setRescheduleError("Dr. Faith Casayuran is fully booked for this date.");
            }
        }

        if ($service_category === 'Grooming') {
            $daily_stmt = $pdo->prepare("
                SELECT COUNT(*) AS grooming_count
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND service ILIKE 'Grooming%'
                  AND id <> :appointment_id
                  AND LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')
            ");
            $daily_stmt->execute([
                ':appointment_date' => $new_date,
                ':appointment_id' => $appointment_id
            ]);

            if ((int)($daily_stmt->fetch()['grooming_count'] ?? 0) >= 8) {
                $setRescheduleError("Grooming services are fully booked for this date.");
            }
        }

        if ($service_category === 'Pet Hotel') {
            $daily_stmt = $pdo->prepare("
                SELECT COUNT(*) AS hotel_count
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND service ILIKE 'Pet Hotel%'
                  AND id <> :appointment_id
                  AND LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')
            ");
            $daily_stmt->execute([
                ':appointment_date' => $new_date,
                ':appointment_id' => $appointment_id
            ]);

            if ((int)($daily_stmt->fetch()['hotel_count'] ?? 0) >= 8) {
                $setRescheduleError("Pet Hotel services are fully booked for this date.");
            }
        }

        $update_stmt = $pdo->prepare("
            UPDATE appointments
            SET appointment_date = :appointment_date,
                appointment_time = :appointment_time,
                booking_status = 'Rescheduled',
                reschedule_count = 1
            WHERE id = :appointment_id
              AND user_id = :user_id
              AND booking_status = 'For Rescheduling'
              AND reschedule_count = 0
        ");

        $update_stmt->execute([
            ':appointment_date' => $new_date,
            ':appointment_time' => $new_time,
            ':appointment_id' => $appointment_id,
            ':user_id' => $user_id
        ]);

        if ($update_stmt->rowCount() !== 1) {
            $setRescheduleError("This booking was already updated or is no longer available for rescheduling.");
        }

        $formatted_date = date('M d, Y', strtotime($new_date));
        $formatted_time = date('g:i A', strtotime($new_time));

        // Notify Admin about the customer's successful reschedule.
        // A savepoint prevents an optional admin-alert failure from aborting the reschedule transaction.
        $pdo->exec('SAVEPOINT admin_reschedule_notif_savepoint');
        try {
            $admin_notif_message =
                ($appointment['customer_name'] ?? $full_name) .
                " rescheduled " .
                ($appointment['pet_name'] ?? 'a pet') .
                " (" . $stored_service . ") to " .
                $formatted_date . " at " . $formatted_time .
                ". GCash payment remains valid. (Booking #" .
                $appointment_id . ")";
            $admin_notif_stmt = $pdo->prepare('INSERT INTO admin_notifications (message) VALUES (:message)');
            $admin_notif_stmt->execute([':message' => $admin_notif_message]);
            $pdo->exec('RELEASE SAVEPOINT admin_reschedule_notif_savepoint');
        } catch (PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT admin_reschedule_notif_savepoint');
            $pdo->exec('RELEASE SAVEPOINT admin_reschedule_notif_savepoint');
            error_log(
                'Admin reschedule notification error for appointment #' .
                $appointment_id . ' | ' . $e->getMessage()
            );
        }

        $notif_stmt = $pdo->prepare("
            INSERT INTO notifications
                (user_id, title, message, type, is_read, created_at)
            VALUES
                (:user_id, :title, :message, 'booking', 0, NOW())
        ");

        $notif_stmt->execute([
            ':user_id' => $user_id,
            ':title' => 'Appointment Rescheduled',
            ':message' => 'Your appointment for ' .
                ($appointment['pet_name'] ?? 'your pet') .
                ' (' . $stored_service . ') has been rescheduled to ' .
                $formatted_date . ' at ' . $formatted_time .
                '. This was your one allowed reschedule within 3 days. Please arrive on time.'
        ]);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $contact_number = trim((string)($appointment['contact_number'] ?? ''));

        if ($contact_number !== '' && strtoupper($contact_number) !== 'N/A') {
            $sms_message =
                "Hi! Your booking for " .
                ($appointment['pet_name'] ?? 'your pet') .
                " (" . $stored_service . ") has been RESCHEDULED to " .
                $formatted_date . " at " . $formatted_time .
                ". This was your one allowed reschedule within 3 days. Please arrive on time. - Boogie's Pet Care";

            $sms_result = sendIPROGSMS($contact_number, $sms_message);

            if (empty($sms_result['success'])) {
                error_log(
                    'IPROG SMS failed after reschedule for appointment #' .
                    $appointment_id . ' | ' .
                    ($sms_result['response'] ?? 'Unknown error')
                );
            }
        }

        // Clear any previous reschedule error so a stale message
        // cannot appear after a successful reschedule.
        unset($_SESSION['booking_reschedule_error']);

        $_SESSION['booking_reschedule_success'] =
            "Appointment successfully rescheduled to " .
            $formatted_date . " at " . $formatted_time .
            ". This was your one allowed reschedule within 3 days.";

        header("Location: bookings.php");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer reschedule error: ' . $e->getMessage());
        $setRescheduleError("Unable to reschedule this appointment right now. Please try again.");
    }
}


// ============================================================
// FETCH PROFILE IMAGE
// ============================================================

$profile_image = null;

try {
    $stmt_user = $pdo->prepare("
        SELECT profile_image
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt_user->execute([
        ':user_id' => $user_id
    ]);

    $user_data = $stmt_user->fetch();

    if ($user_data) {
        $profile_image = $user_data['profile_image'] ?? null;
    }
} catch (PDOException $e) {
    $profile_image = null;
}

// Normalize the profile image value fetched from the users table (or session fallback).
// The stored value may be a bare filename, uploads/filename, or customer/uploads/filename.
$stored_profile_pic = trim((string)($profile_image ?: ($_SESSION['profile_image'] ?? '')));
$profile_pic_src = '';

if ($stored_profile_pic !== '' && basename($stored_profile_pic) !== 'default-avatar.png') {
    if (preg_match('~^https?://~i', $stored_profile_pic)) {
        // Support already-hosted profile pictures.
        $profile_pic_src = $stored_profile_pic;
    } elseif (preg_match('~^data:image/(?:png|jpe?g|gif|webp);base64,~i', $stored_profile_pic)) {
        // Support a valid image data URL if one was explicitly stored.
        $profile_pic_src = $stored_profile_pic;
    } else {
        $normalized_profile_path = ltrim($stored_profile_pic, '/');
        $normalized_profile_path = preg_replace(
            '~^(?:\./)*(?:customer/)?uploads/~i',
            '',
            $normalized_profile_path
        );
        // Profile images are uploaded as files in the root uploads directory.
        $profile_filename = basename((string)$normalized_profile_path);
        if ($profile_filename !== '' && $profile_filename !== '.' && $profile_filename !== '..') {
            $profile_pic_src = '/uploads/' . rawurlencode($profile_filename);
        }
    }
}

// ============================================================
// FETCH NOTIFICATIONS FOR HEADER
// ============================================================

$unread_count = 0;
$notifications = [];

try {
    $stmt_notif = $pdo->prepare("
        SELECT COUNT(*) AS unread
        FROM notifications
        WHERE user_id = :user_id
          AND (LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') OR is_read IS NULL)
    ");

    $stmt_notif->execute([
        ':user_id' => $user_id
    ]);

    $notif_row = $stmt_notif->fetch();

    $unread_count = (int)($notif_row['unread'] ?? 0);

    $stmt_notif_list = $pdo->prepare("
        SELECT
            id,
            message,
            created_at,
            CASE
                WHEN is_read IS NULL OR LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') THEN 'false'
                ELSE 'true'
            END AS is_read_normalized
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");

    $stmt_notif_list->execute([
        ':user_id' => $user_id
    ]);

    $notifications = $stmt_notif_list->fetchAll();

} catch (PDOException $e) {
    $unread_count = 0;
    $notifications = [];
}

// ============================================================
// SEARCH / STATUS FILTERS
// ============================================================

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');

$bookings = [];

try {
    $conditions = [
        "a.user_id = :user_id"
    ];

    $params = [
        ':user_id' => $user_id
    ];

    if ($search !== '') {
        $conditions[] = "(
            p.name ILIKE :search
            OR a.service ILIKE :search
        )";

        $params[':search'] = '%' . $search . '%';
    }

    $allowed_statuses = [
        'Pending',
        'Confirmed',
        'For Rescheduling',
        'Rescheduled',
        'Completed',
        'Cancelled',
        'No-Show'
    ];

    if ($status_filter !== 'all' && in_array($status_filter, $allowed_statuses, true)) {
        $conditions[] = "a.booking_status = :status";
        $params[':status'] = $status_filter;
    } else {
        $status_filter = 'all';
    }

    $query = "
        SELECT
            a.*,
            p.name AS joined_pet_name,
            r.id AS review_id
        FROM appointments a
        LEFT JOIN pets p
            ON a.pet_id = p.id
        LEFT JOIN reviews r
            ON a.id = r.appointment_id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY a.appointment_date DESC,
                 a.appointment_time DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // When a filtered result contains one pet from a group, load all pets from
    // that same group so the customer always sees the complete group schedule.
    $bookingGroupIds = [];
    foreach ($bookings as $bookingRow) {
        $groupId = trim((string)($bookingRow['booking_group_id'] ?? ''));
        if ($groupId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $groupId)) {
            $bookingGroupIds[$groupId] = $groupId;
        }
    }

    $bookingGroupRows = [];
    if (!empty($bookingGroupIds)) {
        $groupPlaceholders = [];
        $groupParams = [':user_id' => $user_id];
        $groupIndex = 0;
        foreach (array_values($bookingGroupIds) as $groupId) {
            $placeholder = ':group_id_' . $groupIndex++;
            $groupPlaceholders[] = 'CAST(' . $placeholder . ' AS uuid)';
            $groupParams[$placeholder] = $groupId;
        }

        $groupRowsStmt = $pdo->prepare("
            SELECT a.*, p.name AS joined_pet_name, r.id AS review_id
            FROM appointments a
            LEFT JOIN pets p ON p.id = a.pet_id
            LEFT JOIN reviews r ON r.appointment_id = a.id
            WHERE a.user_id = :user_id
              AND a.booking_group_id IN (" . implode(', ', $groupPlaceholders) . ")
            ORDER BY a.appointment_date ASC, a.appointment_time ASC, a.id ASC
        ");
        $groupRowsStmt->execute($groupParams);
        foreach ($groupRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $groupRow) {
            $groupId = trim((string)($groupRow['booking_group_id'] ?? ''));
            if ($groupId !== '') {
                $bookingGroupRows[$groupId][] = $groupRow;
            }
        }
    }

} catch (PDOException $e) {
    error_log('Customer booking list query failed: ' . $e->getMessage());
    $bookings = [];
    $bookingGroupRows = [];
}

/** Estimate the end time for a member's Grooming schedule using the same service durations as booking. */
function customerBookingDurationMinutes(string $service): int
{
    $name = strtolower(trim($service));
    if (strpos($name, 'full grooming package') !== false || preg_match('/\bfg\b/', $name)) return 120;
    if (strpos($name, 'bath & blow dry') !== false || strpos($name, 'bath and blow dry') !== false || strpos($name, 'cat bath') !== false) return 90;
    if (strpos($name, 'basic pet grooming') !== false || strpos($name, 'cat grooming (basic)') !== false) return 60;
    if (strpos($name, 'vet services') === 0) return 30;
    if (strpos($name, 'pet hotel') === 0 && strpos($name, 'daycare') !== false) return 180;
    return 120;
}

/** Read the customer-created group name saved in each appointment's remarks. */
function customerBookingGroupName(array $rows): string
{
    foreach ($rows as $row) {
        $remarks = (string)($row['remarks'] ?? '');
        if (preg_match('/GROUP BOOKING:\s*(.+?)\s+\(\d+\s+of\s+\d+\s+pets\)/i', $remarks, $matches)) {
            $name = trim($matches[1]);
            if ($name !== '') return $name;
        }
    }
    return 'Pet Group Booking';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule | Boogie's Pet Care Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        :root {
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff7d6;
            --brand-purple: #8b4bd6;
            --brand-purple-dark: #7136b4;
            --page-bg: #f5f8fb;
            --white: #ffffff;
            --text: #17324d;
            --muted: #6b7c8f;
            --line: #e3eaf1;
            --soft: #f8fafc;
            --success: #168553;
            --danger: #c73b47;
            --shadow: 0 10px 30px rgba(0,31,63,.06);
            --shadow-lg: 0 18px 42px rgba(0,31,63,.09);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            background: var(--page-bg);
            color: var(--text);
            line-height: 1.6;
            overflow-y: scroll;
            overflow-x: hidden;
        }

        /* ===== HEADER ===== */
        .promo-bar {
            background: var(--brand-blue);
            color: #fff;
            text-align: center;
            padding: 7px 16px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .2px;
            border-top: 3px solid var(--brand-yellow);
        }

        .promo-bar i {
            color: var(--brand-yellow);
            margin-right: 7px;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }

        .nav-top {
            max-width: 1320px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            padding: 14px 28px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            min-width: 225px;
        }

        .nav-logo-img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 10px;
            display: block;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }

        .logo-text b {
            font-size: 20px;
            color: var(--brand-blue);
            font-weight: 800;
        }

        .logo-text span {
            margin-top: 3px;
            color: #8c9aae;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.2px;
        }

        .user-controls {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .notification-wrapper,
        .profile-wrapper {
            position: relative;
        }

        .notification-bell {
            position: relative;
            width: 42px;
            height: 42px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .notification-bell:hover,
        .profile-trigger:hover {
            background: #f8fafc;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--danger);
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            border: 2px solid #fff;
            border-radius: 999px;
        }

        .profile-trigger {
            min-height: 42px;
            padding: 4px 8px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-img {
            width: 34px !important;
            height: 34px !important;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--brand-blue) !important;
        }

        .dropdown-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 270px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow-lg);
            display: none;
            flex-direction: column;
            z-index: 1001;
            overflow: hidden;
        }

        .dropdown-menu.active { display: flex; }

        .dropdown-header {
            padding: 14px 16px;
            background: #f8fafc;
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .dropdown-item {
            display: block;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text);
            font-size: 12px;
            border-bottom: 1px solid #eef2f5;
            transition: .18s;
        }

        .dropdown-item:hover {
            background: #f8fafc;
            color: var(--brand-blue);
        }

        .dropdown-item i {
            width: 18px;
            margin-right: 7px;
            text-align: center;
        }

        .dropdown-item.unread {
            background: #eef6ff;
            font-weight: 700;
        }

        .dropdown-item:last-child { border-bottom: 0; }
        .view-all-link { text-align: center; color: var(--brand-blue); font-weight: 800; }
        .view-all-form { margin: 0; }
        .dropdown-item.view-all-button {
            box-sizing: border-box;
            width: 100%;
            border: 0;
            border-radius: 0;
            background: transparent;
            font-family: inherit;
            cursor: pointer;
        }
        .dropdown-item.view-all-button:hover {
            background: #f8fafc;
            color: var(--brand-blue);
        }



        /* ===== ALERTS ===== */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            width: 100%;
            margin: 0 0 20px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 11px;
            line-height: 1.55;
            font-weight: 600;
        }

        .alert i {
            margin-top: 2px;
            flex: 0 0 auto;
        }

        .alert-success {
            background: #eaf8f0;
            border: 1px solid #b8e4ca;
            color: #1e7e49;
        }

        .alert-error {
            background: #fff0f1;
            border: 1px solid #efb8bd;
            color: #a92734;
        }

        /* ===== MY SCHEDULE PAGE ===== */
        main {
            width: min(1120px, 92%);
            margin: 0 auto;
            padding: 42px 0 78px;
        }

        .schedule-intro {
            margin-bottom: 25px;
        }

        .back-nav {
            margin-bottom: 14px;
        }

        .back-nav a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #748396;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
        }

        .back-nav a:hover {
            color: var(--brand-blue);
        }

        .schedule-title-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 25px;
        }

        .schedule-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe594;
            color: #8c6800;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .schedule-title h1 {
            color: var(--brand-blue);
            font-size: 34px;
            line-height: 1.18;
            font-weight: 800;
            letter-spacing: -.6px;
        }

        .schedule-title p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .book-new-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 17px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            text-decoration: none;
            font-size: 11px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(0,31,63,.10);
        }

        .book-new-btn:hover {
            background: var(--brand-blue-2);
        }

        .filter-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 7px 23px rgba(0,31,63,.04);
            margin-bottom: 23px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: minmax(0,1fr) 190px auto;
            gap: 9px;
            align-items: center;
        }

        .filter-search {
            position: relative;
        }

        .filter-search i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #98a5b4;
            font-size: 12px;
        }

        .filter-search input,
        .filter-select {
            width: 100%;
            height: 42px;
            border: 1px solid #dce5ee;
            border-radius: 10px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
            outline: none;
        }

        .filter-search input {
            padding: 0 12px 0 37px;
        }

        .filter-search input:focus,
        .filter-select:focus {
            border-color: #9bb6cc;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,31,63,.05);
        }

        .filter-select {
            padding: 0 12px;
        }

        .filter-btn {
            height: 42px;
            padding: 0 16px;
            border: 0;
            border-radius: 10px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-family: inherit;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
        }

        .filter-btn:hover {
            background: var(--brand-blue-2);
        }

        .section-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 22px;
        }

        .section-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
        }

        .section-head .kicker {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .section-head h2 {
            color: var(--brand-blue);
            font-size: 20px;
            line-height: 1.2;
            font-weight: 800;
        }

        .section-head p {
            color: var(--muted);
            font-size: 10px;
            margin-top: 5px;
        }

        .section-count {
            color: #8b99a9;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .schedule-list {
            display: grid;
            gap: 11px;
        }

        .group-schedule-card {
            grid-template-columns: 88px minmax(0,1fr) minmax(150px,auto);
            align-items: start;
            border-color: #e7d9a8;
            background: #fffdf6;
        }
        .group-booking-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            color: #8c6800;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: .55px;
        }
        .group-pet-list {
            display: grid;
            gap: 7px;
            margin-top: 12px;
        }
        .group-pet-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 11px;
            border: 1px solid #eee5c8;
            border-radius: 10px;
            background: #fff;
        }
        .group-pet-info { min-width: 0; display: grid; gap: 2px; }
        .group-pet-info strong { color: var(--brand-blue); font-size: 10px; overflow-wrap: anywhere; }
        .group-pet-info strong i { color: #9b7a12; margin-right: 5px; }
        .group-pet-info small { color: var(--muted); font-size: 8px; }
        .group-pet-actions { display: inline-flex; align-items: center; gap: 7px; flex-wrap: wrap; justify-content: flex-end; }
        .group-action-note { max-width: 180px; }

        .schedule-item {
            display: grid;
            grid-template-columns: 88px minmax(0,1fr) auto;
            gap: 17px;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #fbfcfe;
        }

        .schedule-item:hover {
            border-color: #d5e0e9;
            box-shadow: 0 6px 18px rgba(0,31,63,.045);
        }

        .date-box {
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            border: 1px solid #dfe7ee;
            text-align: center;
        }

        .date-box .date-month {
            padding: 5px 7px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .date-box .date-day {
            padding: 7px 5px 2px;
            color: var(--brand-blue);
            font-size: 24px;
            line-height: 1;
            font-weight: 800;
        }

        .date-box .date-week {
            padding: 1px 5px 8px;
            color: #8996a4;
            font-size: 8px;
            font-weight: 700;
        }

        .schedule-main {
            min-width: 0;
        }

        .schedule-service {
            color: var(--brand-blue);
            font-size: 15px;
            line-height: 1.3;
            font-weight: 800;
        }

        .schedule-details {
            display: flex;
            flex-wrap: wrap;
            gap: 11px;
            margin-top: 6px;
        }

        .schedule-detail {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--muted);
            font-size: 9px;
            font-weight: 600;
        }

        .schedule-detail i {
            color: #7f96a8;
            font-size: 10px;
        }

        .schedule-note {
            color: #8a98a7;
            font-size: 9px;
            margin-top: 7px;
        }

        .schedule-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 7px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .35px;
            white-space: nowrap;
        }

        .status-PENDING {
            background: #fff7df;
            color: #a66a00;
        }

        .status-PAID,
        .status-APPROVED,
        .status-CONFIRMED {
            background: #e7f8ef;
            color: #1e7e49;
        }

        .status-COMPLETED {
            background: #e9f6ff;
            color: #0d73a6;
        }

        .status-CANCELLED {
            background: #fee7e9;
            color: #a92734;
        }

        .status-NO-SHOW {
            background: #384452;
            color: #fff;
        }

        .status-FOR-RESCHEDULING {
            background: #eaf4ff;
            color: #1769aa;
        }

        .status-RESCHEDULED {
            background: #e7f8ef;
            color: #1e7e49;
        }

        .payment-status {
            color: #7a8998;
            font-size: 8px;
            font-weight: 700;
            text-align: right;
        }

        .schedule-action {
            display: flex;
            gap: 7px;
            align-items: center;
        }

        .btn-cancel-schedule,
        .btn-review-schedule {
            min-width: 94px;
            border-radius: 9px;
            padding: 8px 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-family: inherit;
            font-size: 9px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-cancel-schedule {
            border: 1px solid #e99aa1;
            background: #fff;
            color: var(--danger);
        }

        .btn-cancel-schedule:hover {
            background: #fff0f1;
        }

        .btn-review-schedule {
            background: var(--brand-yellow);
            border: 1px solid #e2bb00;
            color: var(--brand-blue);
        }

        .btn-review-schedule:hover {
            background: #f3bf00;
        }

        .btn-reschedule-schedule {
            min-width: 110px;
            border: 1px solid #7db6e8;
            background: #edf7ff;
            color: #1769aa;
            border-radius: 9px;
            padding: 8px 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-family: inherit;
            font-size: 9px;
            font-weight: 800;
            cursor: pointer;
        }

        .btn-reschedule-schedule:hover {
            background: #dceeff;
        }

        .rescheduled-note {
            color: #1769aa;
        }

        .feedback-schedule {
            color: #238052;
            font-size: 9px;
            font-weight: 800;
            white-space: nowrap;
        }

        .cancellation-locked-note {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            max-width: 190px;
            min-height: 34px;
            padding: 7px 9px;
            border: 1px solid #fecaca;
            border-radius: 9px;
            background: #fff1f2;
            color: #b91c1c;
            font-size: 9px;
            font-weight: 800;
            line-height: 1.4;
            white-space: normal;
            text-align: center;
        }

        .schedule-empty {
            text-align: center;
            padding: 52px 18px;
        }

        .schedule-empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin: 0 auto 13px;
            background: var(--brand-yellow-soft);
            color: #b18400;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
        }

        .schedule-empty h3 {
            color: var(--brand-blue);
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .schedule-empty p {
            max-width: 470px;
            margin: 0 auto 17px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.7;
        }

        .schedule-info {
            display: grid;
            grid-template-columns: repeat(3,1fr);
            gap: 12px;
        }

        .info-mini {
            padding: 14px;
            border-radius: 13px;
            border: 1px solid var(--line);
            background: #fbfcfe;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-mini-icon {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 11px;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .info-mini strong {
            display: block;
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 800;
        }

        .info-mini span {
            display: block;
            color: var(--muted);
            font-size: 8px;
            line-height: 1.5;
            margin-top: 2px;
        }

        @media (max-width: 980px) {
            .schedule-item {
                grid-template-columns: 82px 1fr;
            }
            .group-schedule-card {
                grid-template-columns: 82px minmax(0,1fr);
            }
            .group-schedule-card > .schedule-status {
                grid-column: 2;
            }

            .schedule-status {
                grid-column: 2;
                align-items: flex-start;
            }

            .schedule-info {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            main {
                width: 92%;
                padding-top: 30px;
            }

            .schedule-title-row {
                display: block;
            }

            .book-new-btn {
                width: 100%;
                margin-top: 15px;
                justify-content: center;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .filter-btn {
                width: 100%;
            }

            .section-card {
                padding: 18px;
            }

            .schedule-item {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .group-pet-row {
                align-items: flex-start;
                flex-direction: column;
            }
            .group-pet-actions {
                justify-content: flex-start;
            }
            .group-schedule-card > .schedule-status {
                grid-column: auto;
            }

            .date-box {
                width: 82px;
            }

            .schedule-status {
                grid-column: auto;
            }

            .schedule-action {
                flex-wrap: wrap;
            }
        }


        /* ===== RESCHEDULE MODAL ===== */
        .reschedule-modal-content {
            width: 100%;
            max-width: 500px;
            background: #fff;
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 24px 55px rgba(0,0,0,.20);
        }

        .reschedule-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 12px;
            background: #eaf4ff;
            color: #1769aa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .reschedule-help {
            background: #eef7ff;
            color: #245386;
            border: 1px solid #cfe7fb;
            padding: 11px 12px;
            border-radius: 11px;
            font-size: 9px;
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .reschedule-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* ===== CANCEL MODAL ===== */ 
        .modal-overlay {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,31,63,.58);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .modal-content {
            width: 100%;
            max-width: 450px;
            background: #fff;
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 24px 55px rgba(0,0,0,.20);
        }

        .modal-header {
            display: flex;
            gap: 13px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .warning-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 12px;
            background: #fff0f1;
            color: #dc3b45;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .modal-header h2 {
            color: var(--brand-blue);
            font-size: 18px;
            line-height: 1.2;
            font-weight: 800;
            margin: 0 0 3px;
        }

        .modal-header p {
            color: var(--muted);
            font-size: 10px;
            margin: 0;
        }

        .booking-details-box {
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 15px;
            margin-bottom: 18px;
        }

        .booking-details-box > p {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .booking-details-box h3 {
            color: var(--brand-blue);
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .detail-row {
            color: #53677b;
            font-size: 11px;
            margin-top: 3px;
        }

        .modal-form-group {
            margin-bottom: 17px;
        }

        .modal-form-group label {
            display: block;
            color: var(--text);
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .modal-form-group select {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1px solid #dce5ed;
            border-radius: 10px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
            outline: none;
        }

        .important-warning {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 12px;
            margin-bottom: 19px;
            border-radius: 11px;
            background: #fff7df;
            border: 1px solid #f3df99;
            color: #8c6207;
            font-size: 10px;
            line-height: 1.6;
        }

        .important-warning i {
            margin-top: 2px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .modal-actions button {
            flex: 1;
            min-height: 42px;
            border-radius: 10px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }

        .btn-keep {
            background: #fff;
            color: var(--text);
            border: 1px solid #cbd5e1;
        }

        .btn-confirm-cancel {
            background: #dc3b45;
            color: #fff;
            border: 0;
        }

        /* ===== FOOTER ===== */
        footer {
            width: 100%;
            background: var(--brand-blue);
            padding: 62px 28px 30px;
            color: #fff;
            border-top: 4px solid var(--brand-yellow);
            margin-top: 0;
        }

        .footer-main {
            max-width: 1180px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 42px;
            padding-bottom: 40px;
            border-bottom: 1px solid rgba(255,255,255,.12);
        }

        .footer-main h4 {
            color: var(--brand-yellow);
            margin: 0 0 15px;
            text-transform: uppercase;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: .5px;
        }

        .footer-main p,
        .footer-main a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 12px;
            line-height: 1.7;
            display: block;
            margin: 0 0 8px;
        }

        .footer-main a:hover {
            color: #fff;
        }

        .socials {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .socials a {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255,255,255,.09);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .socials a:hover {
            background: var(--brand-yellow);
            color: var(--brand-blue);
        }

        .footer-bottom {
            max-width: 1180px;
            margin: 0 auto;
            padding-top: 23px;
            color: #91a1b1;
            text-align: center;
            font-size: 11px;
        }

        .footer-bottom a {
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 700;
        }

        @media (max-width: 1000px) {
            .footer-main {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 680px) {
            .footer-main {
                grid-template-columns: 1fr;
                gap: 25px;
            }

            .modal-content {
                padding: 22px;
            }

            .modal-actions {
                flex-direction: column;
            }
        }


        /* ===== EXTRA RESPONSIVE TUNING ===== */
        @media (max-width: 980px) {
            .nav-top {
                padding-left: 20px;
                padding-right: 20px;
                gap: 14px;
            }

            .logo {
                min-width: 0;
                flex: 1 1 auto;
            }

            .logo-text {
                min-width: 0;
            }

            .logo-text b,
            .logo-text span {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .user-controls {
                flex: 0 0 auto;
            }
        }

        @media (max-width: 720px) {
            .promo-bar {
                padding: 6px 12px;
                font-size: 10px;
            }

            .nav-top {
                padding: 10px 14px;
                gap: 8px;
            }

            .nav-logo-img {
                width: 40px;
                height: 40px;
                border-radius: 9px;
            }

            .logo {
                gap: 8px;
            }

            .logo-text b {
                font-size: 16px;
            }

            .logo-text span {
                font-size: 7px;
                letter-spacing: .9px;
            }

            .user-controls {
                gap: 7px;
            }

            .notification-bell,
            .profile-trigger {
                width: 40px;
                min-width: 40px;
                height: 40px;
            }

            .notification-bell {
                padding: 0;
            }

            .profile-trigger {
                justify-content: center;
                padding: 3px;
            }

            .profile-trigger span,
            .profile-trigger > .fa-chevron-down {
                display: none;
            }

            .profile-img {
                width: 32px !important;
                height: 32px !important;
            }

            .dropdown-menu {
                top: calc(100% + 8px);
                right: 0;
                max-width: calc(100vw - 28px);
                width: 270px;
            }

            .dropdown-menu[style*="width:210px"] {
                width: 210px !important;
            }

            main {
                width: calc(100% - 24px);
                padding: 24px 0 48px;
            }

            .schedule-intro {
                margin-bottom: 18px;
            }

            .back-nav {
                margin-bottom: 10px;
            }

            .schedule-title h1 {
                font-size: 27px;
                letter-spacing: -.4px;
            }

            .schedule-title p {
                font-size: 11px;
                line-height: 1.55;
            }

            .schedule-kicker {
                font-size: 9px;
                padding: 6px 10px;
                margin-bottom: 8px;
            }

            .book-new-btn {
                width: 100%;
                min-height: 44px;
                padding: 11px 14px;
                justify-content: center;
                font-size: 11px;
            }

            .filter-card {
                padding: 11px;
                border-radius: 14px;
                margin-bottom: 17px;
            }

            .filter-row {
                gap: 8px;
            }

            .filter-search input,
            .filter-select,
            .filter-btn {
                height: 44px;
                font-size: 11px;
            }

            .filter-btn {
                width: 100%;
            }

            .section-card {
                padding: 15px;
                border-radius: 17px;
                margin-bottom: 17px;
            }

            .section-head {
                display: block;
                margin-bottom: 14px;
            }

            .section-head h2 {
                font-size: 18px;
            }

            .section-head p {
                font-size: 9px;
            }

            .section-count {
                display: inline-block;
                margin-top: 7px;
            }

            .schedule-list {
                gap: 9px;
            }

            .schedule-item {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 12px;
                border-radius: 13px;
            }

            .date-box {
                width: 90px;
            }

            .date-box .date-day {
                font-size: 22px;
            }

            .schedule-service {
                font-size: 14px;
            }

            .schedule-details {
                gap: 7px 10px;
                margin-top: 5px;
            }

            .schedule-detail {
                font-size: 9px;
            }

            .schedule-note {
                font-size: 8px;
                line-height: 1.45;
            }

            .schedule-status {
                display: flex;
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
                gap: 7px;
            }

            .payment-status {
                font-size: 8px;
                text-align: left;
            }

            .schedule-action {
                width: 100%;
                gap: 7px;
            }

            .btn-cancel-schedule,
            .btn-review-schedule,
            .btn-reschedule-schedule {
                min-width: 0;
                flex: 1 1 0;
                min-height: 42px;
                padding: 9px 10px;
                font-size: 9px;
            }

            .feedback-schedule {
                font-size: 9px;
            }

            .schedule-empty {
                padding: 40px 12px;
            }

            .schedule-empty h3 {
                font-size: 17px;
            }

            .schedule-empty p {
                font-size: 9px;
            }

            .schedule-info {
                grid-template-columns: 1fr;
                gap: 9px;
            }

            .info-mini {
                padding: 12px;
            }

            .info-mini-icon {
                width: 36px;
                height: 36px;
                flex-basis: 36px;
            }

            .modal-overlay {
                padding: 12px;
                align-items: flex-end;
            }

            .modal-content {
                width: 100%;
                max-width: 520px;
                max-height: 92vh;
                overflow-y: auto;
                padding: 18px;
                border-radius: 18px 18px 12px 12px;
            }

            .booking-details-box {
                padding: 12px;
                margin-bottom: 14px;
            }

            .modal-form-group {
                margin-bottom: 13px;
            }

            .modal-form-group select {
                height: 44px;
                font-size: 11px;
            }

            .important-warning {
                font-size: 9px;
                padding: 10px;
                margin-bottom: 14px;
            }

            .modal-actions {
                flex-direction: column;
                gap: 8px;
            }

            .modal-actions button {
                width: 100%;
                min-height: 44px;
            }

            footer {
                padding: 42px 16px 24px;
            }

            .footer-main {
                grid-template-columns: 1fr;
                gap: 22px;
                padding-bottom: 28px;
            }

            .footer-main p,
            .footer-main a {
                font-size: 11px;
            }

            .footer-bottom {
                font-size: 9px;
                line-height: 1.5;
            }
        }

        @media (max-width: 420px) {
            .logo-text b {
                font-size: 15px;
            }

            .logo-text span {
                font-size: 6.5px;
            }

            main {
                width: calc(100% - 18px);
            }

            .schedule-title h1 {
                font-size: 24px;
            }

            .schedule-title p {
                font-size: 10px;
            }

            .section-card {
                padding: 13px;
            }

            .schedule-item {
                padding: 10px;
            }

            .schedule-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .schedule-detail:last-child {
                grid-column: 1 / -1;
            }

            .btn-cancel-schedule,
            .btn-review-schedule,
            .btn-reschedule-schedule {
                font-size: 8px;
            }

            .dropdown-menu {
                max-width: calc(100vw - 18px);
            }
        }

    

        /* ===== MOBILE READABILITY IMPROVEMENTS ===== */
        @media (max-width: 720px) {
            body {
                font-size: 15px;
                -webkit-text-size-adjust: 100%;
            }

            .promo-bar {
                font-size: 11px;
                line-height: 1.45;
            }

            /* Keep the logo and account controls aligned in one compact row. */
            .nav-top {
                display: flex;
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                width: 100%;
                padding: 10px 12px;
                gap: 8px;
            }

            .logo {
                flex: 1 1 auto;
                min-width: 0;
                gap: 8px;
            }

            .logo-text b {
                font-size: 16px;
            }

            .logo-text span {
                font-size: 8px;
                letter-spacing: .7px;
            }

            .user-controls {
                display: flex;
                flex: 0 0 auto;
                align-items: center;
                gap: 7px;
            }

            .notification-bell,
            .profile-trigger {
                width: 42px;
                min-width: 42px;
                height: 42px;
                min-height: 42px;
            }

            main {
                width: calc(100% - 24px);
                padding: 22px 0 44px;
            }

            .back-nav a {
                font-size: 13px;
            }

            .schedule-kicker {
                font-size: 10px;
                line-height: 1.4;
            }

            .schedule-title h1 {
                font-size: 28px;
                line-height: 1.2;
            }

            .schedule-title p {
                font-size: 14px;
                line-height: 1.55;
            }

            .book-new-btn {
                min-height: 46px;
                font-size: 14px;
            }

            .alert {
                font-size: 13px;
                line-height: 1.55;
                padding: 13px;
            }

            .filter-card {
                padding: 13px;
            }

            .filter-search input,
            .filter-select,
            .filter-btn {
                min-height: 46px;
                height: 46px;
                font-size: 14px;
            }

            .filter-search input {
                padding-left: 38px;
            }

            .filter-search input::placeholder {
                font-size: 13px;
            }

            .section-card {
                padding: 16px;
            }

            .section-head .kicker {
                font-size: 10px;
            }

            .section-head h2 {
                font-size: 20px;
                line-height: 1.3;
            }

            .section-head p {
                font-size: 13px;
                line-height: 1.5;
            }

            .section-count {
                font-size: 11px;
            }

            .schedule-item {
                gap: 12px;
                padding: 14px;
            }

            .date-box {
                width: 92px;
            }

            .date-box .date-month {
                font-size: 11px;
            }

            .date-box .date-day {
                font-size: 25px;
            }

            .date-box .date-week {
                font-size: 10px;
            }

            .schedule-service {
                font-size: 16px;
                line-height: 1.4;
                overflow-wrap: anywhere;
            }

            .schedule-details {
                gap: 8px 12px;
            }

            .schedule-detail {
                font-size: 12px;
                line-height: 1.45;
            }

            .schedule-detail i {
                font-size: 12px;
            }

            .schedule-note {
                font-size: 12px;
                line-height: 1.5;
                overflow-wrap: anywhere;
            }

            .schedule-status {
                gap: 8px;
            }

            .status-pill {
                font-size: 10px;
                line-height: 1.35;
                padding: 6px 10px;
                white-space: normal;
            }

            .payment-status,
            .feedback-schedule {
                font-size: 12px;
                line-height: 1.45;
                text-align: left;
                white-space: normal;
            }

            .schedule-action {
                display: flex;
                flex-wrap: wrap;
                align-items: stretch;
                gap: 8px;
            }

            .btn-cancel-schedule,
            .btn-review-schedule,
            .btn-reschedule-schedule {
                min-height: 44px;
                padding: 10px 12px;
                font-size: 12px;
                line-height: 1.35;
            }

            .cancellation-locked-note {
                max-width: 100%;
                min-height: 40px;
                padding: 9px 11px;
                font-size: 12px;
            }

            .schedule-empty {
                padding: 42px 14px;
            }

            .schedule-empty h3 {
                font-size: 19px;
            }

            .schedule-empty p {
                font-size: 13px;
                line-height: 1.6;
            }

            .info-mini {
                padding: 13px;
            }

            .info-mini strong {
                font-size: 13px;
            }

            .info-mini span {
                font-size: 12px;
                line-height: 1.55;
            }

            /* Both dialogs use the same mobile bottom-sheet sizing. */
            .modal-overlay {
                padding: 10px;
                align-items: flex-end;
            }

            .modal-content,
            .reschedule-modal-content {
                width: 100%;
                max-width: 560px;
                max-height: 92vh;
                overflow-y: auto;
                padding: 18px;
                border-radius: 18px 18px 12px 12px;
            }

            .modal-header h2 {
                font-size: 19px;
                line-height: 1.3;
            }

            .modal-header p {
                font-size: 13px;
                line-height: 1.5;
            }

            .booking-details-box {
                padding: 13px;
            }

            .booking-details-box > p {
                font-size: 11px;
            }

            .booking-details-box h3 {
                font-size: 17px;
                overflow-wrap: anywhere;
            }

            .detail-row {
                font-size: 13px;
                line-height: 1.5;
            }

            .reschedule-help,
            .important-warning {
                font-size: 12px;
                line-height: 1.6;
                padding: 12px;
            }

            .reschedule-form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .modal-form-group label {
                font-size: 13px;
            }

            .modal-form-group input,
            .modal-form-group select {
                width: 100%;
                min-height: 46px;
                height: 46px;
                padding: 0 12px;
                font-size: 16px;
            }

            .modal-actions {
                flex-direction: column;
                gap: 9px;
            }

            .modal-actions button {
                width: 100%;
                min-height: 46px;
                font-size: 13px;
            }

            footer {
                padding: 40px 16px 24px;
            }

            .footer-main h4 {
                font-size: 13px;
            }

            .footer-main p,
            .footer-main a {
                font-size: 13px;
                line-height: 1.65;
            }

            .footer-bottom {
                font-size: 11px;
                line-height: 1.6;
            }

            .dropdown-header {
                font-size: 12px;
            }

            .dropdown-item {
                font-size: 13px;
                line-height: 1.5;
            }
        }

        @media (max-width: 420px) {
            .logo-text b {
                font-size: 15px;
            }

            .logo-text span {
                font-size: 7px;
            }

            main {
                width: calc(100% - 20px);
            }

            .schedule-title h1 {
                font-size: 25px;
            }

            .schedule-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .schedule-detail:last-child {
                grid-column: 1 / -1;
            }

            .btn-cancel-schedule,
            .btn-review-schedule,
            .btn-reschedule-schedule {
                font-size: 11px;
            }
        }

    </style>
</head>

<body>

    <div class="promo-bar">
        <i class="fa-solid fa-phone"></i> Need help? Call us at (046) 887 4714
    </div>

    <header>
        <div class="nav-top">
            <a href="dashboard.php" class="logo">
                <img src="bg.png" alt="Boogie's Pet Care logo" class="nav-logo-img">
                <div class="logo-text">
                    <b>Boogie's</b>
                    <span>PET CARE SERVICES</span>
                </div>
            </a>

            <div class="user-controls">
                <div class="notification-wrapper">
                    <div class="notification-bell" onclick="toggleDropdown('notifDropdown')" aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <span id="notif-badge" class="notification-badge" style="<?php echo $unread_count > 0 ? '' : 'display:none;'; ?>"><?php echo $unread_count; ?></span>
                    </div>

                    <div class="dropdown-menu" id="notifDropdown">
                        <div class="dropdown-header">Notifications</div>
                        <div id="notif-content">
                            <?php if (count($notifications) > 0): ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <?php $dropdown_is_read = strtolower(trim((string)($notif['is_read_normalized'] ?? 'false'))) === 'true'; ?>
                                    <a href="notifications.php" class="dropdown-item <?php echo $dropdown_is_read ? '' : 'unread'; ?>">
                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        <br>
                                        <small style="color:#888;font-size:10px;">
                                            <?php echo htmlspecialchars(formatMyScheduleNotificationTime($notif['created_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </small>
                                    </a>
                                <?php endforeach; ?>
                                <form method="POST" action="mark_all_read.php" class="view-all-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="dropdown-item view-all-link view-all-button">View All Notifications</button>
                                </form>
                            <?php else: ?>
                                <div class="dropdown-item" style="text-align:center;color:#888;">No new notifications.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper">
                    <div class="profile-trigger" onclick="toggleDropdown('profileDropdown')">
                        <?php if ($profile_pic_src !== ''): ?>
                            <img
                                src="<?php echo htmlspecialchars($profile_pic_src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                alt="Profile"
                                class="profile-img"
                                onerror="this.onerror=null; var icon=document.createElement('i'); icon.className='fa-solid fa-circle-user'; icon.style.fontSize='20px'; icon.style.color='var(--brand-blue)'; this.replaceWith(icon);"
                            >
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size:20px;color:var(--brand-blue);"></i>
                        <?php endif; ?>

                        <span>Hi, <?php echo htmlspecialchars($full_name); ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="dropdown-menu" id="profileDropdown" style="width:210px;">
                        <a href="edit_profile.php" class="dropdown-item">
                            <i class="fa-solid fa-user"></i> My Profile
                        </a>
                        <a href="bookings.php" class="dropdown-item">
                            <i class="fa-solid fa-calendar-check"></i> My Bookings
                        </a>
                        <a href="petprofile.php" class="dropdown-item">
                            <i class="fa-solid fa-paw"></i> My Pets
                        </a>
                        <a href="logout.php" class="dropdown-item" style="color:#dc3545;border-top:1px solid #eaeaea;">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>


    <main>
        <div class="schedule-intro">
            <div class="back-nav">
                <a href="dashboard.php">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <div class="schedule-title-row">
                <div class="schedule-title">
                    <div class="schedule-kicker">
                        <i class="fa-solid fa-calendar-days"></i>
                        Your appointment schedule
                    </div>

                    <h1>My Schedule</h1>
                    <p>See when you are expected to visit Boogie's with your pet.</p>
                </div>

                <a href="book_appointment.php" class="book-new-btn">
                    <i class="fa-solid fa-plus"></i>
                    Book New Appointment
                </a>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'success'): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                Appointment successfully booked! We look forward to seeing your pet.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <?php
                        if ($_GET['error'] == 'late_cancellation') {
                            echo "Failed to cancel: You can only cancel an appointment at least 24 hours before your scheduled time. Payments for late cancellations or no-shows are strictly non-refundable.";
                        } elseif ($_GET['error'] == 'past_date') {
                            echo "Failed to cancel: Cannot cancel a past appointment.";
                        } else {
                            echo "Failed to cancel booking. Please contact support.";
                        }
                    ?>
                </div>
            </div>
        <?php endif; ?>


        <?php if (!empty($_SESSION['booking_reschedule_success'])): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div><?php echo htmlspecialchars($_SESSION['booking_reschedule_success']); ?></div>
            </div>
            <?php unset($_SESSION['booking_reschedule_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['booking_reschedule_error'])): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div><?php echo htmlspecialchars($_SESSION['booking_reschedule_error']); ?></div>
            </div>
            <?php unset($_SESSION['booking_reschedule_error']); ?>
        <?php endif; ?>

        <div class="filter-card">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input
                            type="text"
                            name="search"
                            placeholder="Search your schedule by pet or service..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                    </div>

                    <select name="status" class="filter-select">
                        <option value="all" <?php echo ($status_filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                        <option value="Pending" <?php echo ($status_filter == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Confirmed" <?php echo ($status_filter == 'Confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="For Rescheduling" <?php echo ($status_filter == 'For Rescheduling') ? 'selected' : ''; ?>>For Rescheduling</option>
                        <option value="Rescheduled" <?php echo ($status_filter == 'Rescheduled') ? 'selected' : ''; ?>>Rescheduled</option>
                        <option value="Completed" <?php echo ($status_filter == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo ($status_filter == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="No-Show" <?php echo ($status_filter == 'No-Show') ? 'selected' : ''; ?>>No-Show</option>
                    </select>

                    <button type="submit" class="filter-btn">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                </div>
            </form>
        </div>

        <?php
            $today = date('Y-m-d');
            $upcoming = [];
            $past = [];
            $processedGroupIds = [];

            foreach ($bookings as $booking) {
                $groupId = trim((string)($booking['booking_group_id'] ?? ''));
                if ($groupId !== '') {
                    if (isset($processedGroupIds[$groupId])) continue;
                    $processedGroupIds[$groupId] = true;
                    $groupRows = $bookingGroupRows[$groupId] ?? [$booking];
                    if (empty($groupRows)) $groupRows = [$booking];
                    usort($groupRows, static function ($a, $b) {
                        return strcmp(
                            (string)($a['appointment_date'] ?? '') . ' ' . (string)($a['appointment_time'] ?? ''),
                            (string)($b['appointment_date'] ?? '') . ' ' . (string)($b['appointment_time'] ?? '')
                        );
                    });

                    $groupHasUpcoming = false;
                    foreach ($groupRows as $groupMember) {
                        $memberDate = date('Y-m-d', strtotime((string)($groupMember['appointment_date'] ?? '')));
                        $memberStatus = strtoupper(trim((string)($groupMember['booking_status'] ?? 'PENDING')));
                        if (($memberDate >= $today && !in_array($memberStatus, ['CANCELLED', 'COMPLETED', 'NO-SHOW'], true))
                            || in_array($memberStatus, ['FOR RESCHEDULING', 'RESCHEDULED'], true)) {
                            $groupHasUpcoming = true;
                            break;
                        }
                    }

                    $groupEntry = $groupRows[0];
                    $groupEntry['_is_group_booking'] = true;
                    $groupEntry['_group_rows'] = $groupRows;
                    $groupEntry['_group_name'] = customerBookingGroupName($groupRows);
                    if ($groupHasUpcoming) $upcoming[] = $groupEntry;
                    else $past[] = $groupEntry;
                    continue;
                }

                $booking_date = date('Y-m-d', strtotime((string)($booking['appointment_date'] ?? '')));
                $status = strtoupper($booking['booking_status'] ?? 'PENDING');
                if (($booking_date >= $today && !in_array($status, ['CANCELLED', 'COMPLETED', 'NO-SHOW'], true))
                    || in_array($status, ['FOR RESCHEDULING', 'RESCHEDULED'], true)) {
                    $upcoming[] = $booking;
                } else {
                    $past[] = $booking;
                }
            }

            usort($upcoming, function($a, $b) {
                $aKey = ($a['appointment_date'] ?? '') . ' ' . ($a['appointment_time'] ?? '');
                $bKey = ($b['appointment_date'] ?? '') . ' ' . ($b['appointment_time'] ?? '');
                return strcmp($aKey, $bKey);
            });

            usort($past, function($a, $b) {
                $aKey = ($a['appointment_date'] ?? '') . ' ' . ($a['appointment_time'] ?? '');
                $bKey = ($b['appointment_date'] ?? '') . ' ' . ($b['appointment_time'] ?? '');
                return strcmp($bKey, $aKey);
            });
        ?>

        <section class="section-card">
            <div class="section-head">
                <div>
                    <div class="kicker">Next visits</div>
                    <h2>Upcoming Schedule</h2>
                    <p>Your next confirmed or pending appointments.</p>
                </div>

                <div class="section-count">
                    <?php echo count($upcoming); ?> upcoming
                </div>
            </div>

            <?php if (!empty($upcoming)): ?>
                <div class="schedule-list">
                    <?php foreach ($upcoming as $row): ?>
                        <?php if (!empty($row['_is_group_booking'])): ?>
                            <?php
                                $groupRows = $row['_group_rows'] ?? [];
                                $groupName = (string)($row['_group_name'] ?? 'Pet Group Booking');
                                $groupFirst = $groupRows[0] ?? $row;
                                $groupService = (string)($groupFirst['service'] ?? 'Grooming');
                                $groupDate = (string)($groupFirst['appointment_date'] ?? '');
                                $groupTotal = 0.0;
                                $groupStatuses = [];
                                $groupPaymentStatuses = [];
                                $groupPaymentMethods = [];
                                $groupReference = '';
                                $groupStartMinute = PHP_INT_MAX;
                                $groupRescheduleEligible = count($groupRows) >= 2;
                                $groupHasRescheduleFlag = false;
                                $groupOriginalDate = substr((string)($groupFirst['appointment_date'] ?? ''), 0, 10);
                                $groupOriginalService = trim((string)($groupFirst['service'] ?? ''));
                                $groupEndMinute = 0;
                                $groupCancellationEligible = count($groupRows) >= 2 && count($groupRows) <= 20;
                                $groupEarliestAppointmentTimestamp = null;
                                $cancellationTimezone = new DateTimeZone('Asia/Manila');
                                $nowForGroupCancellation = new DateTimeImmutable('now', $cancellationTimezone);
                                foreach ($groupRows as $groupMember) {
                                    $groupTotal += (float)($groupMember['total_price'] ?? $groupMember['service_fee'] ?? 0);
                                    $memberStatusValue = trim((string)($groupMember['booking_status'] ?? 'Pending')) ?: 'Pending';
                                    $groupStatuses[strtoupper($memberStatusValue)] = $memberStatusValue;
                                    $memberPaymentStatus = trim((string)($groupMember['payment_status'] ?? 'Pending')) ?: 'Pending';
                                    $groupPaymentStatuses[strtoupper($memberPaymentStatus)] = $memberPaymentStatus;
                                    $memberPaymentMethod = trim((string)($groupMember['payment_method'] ?? 'N/A')) ?: 'N/A';
                                    $groupPaymentMethods[strtoupper($memberPaymentMethod)] = $memberPaymentMethod;
                                    $memberRescheduleStatus = strtoupper(trim((string)($groupMember['booking_status'] ?? '')));
                                    $memberReschedulePayment = strtoupper(trim((string)($groupMember['payment_status'] ?? '')));
                                    if ($memberRescheduleStatus === 'FOR RESCHEDULING') $groupHasRescheduleFlag = true;
                                    if (!in_array($memberRescheduleStatus, ['FOR RESCHEDULING', 'CONFIRMED'], true)
                                        || $memberReschedulePayment !== 'PAID'
                                        || (int)($groupMember['reschedule_count'] ?? 0) >= 1
                                        || substr((string)($groupMember['appointment_date'] ?? ''), 0, 10) !== $groupOriginalDate
                                        || trim((string)($groupMember['service'] ?? '')) !== $groupOriginalService) {
                                        $groupRescheduleEligible = false;
                                    }

                                    // The cancellation endpoint cancels all rows together. Show the
                                    // group button only when every member is still cancellable and
                                    // at least 24 hours remain before the earliest member's start.
                                    $memberCancelStatus = strtoupper(trim((string)($groupMember['booking_status'] ?? '')));
                                    if (in_array($memberCancelStatus, ['CANCELLED', 'CANCELED', 'COMPLETED', 'NO-SHOW', 'NO SHOW', 'NOSHOW'], true)
                                        || substr((string)($groupMember['appointment_date'] ?? ''), 0, 10) !== $groupOriginalDate
                                        || trim((string)($groupMember['service'] ?? '')) !== $groupOriginalService) {
                                        $groupCancellationEligible = false;
                                    }
                                    $memberCancelDate = substr((string)($groupMember['appointment_date'] ?? ''), 0, 10);
                                    $memberCancelTime = trim((string)($groupMember['appointment_time'] ?? ''));
                                    if (preg_match('/^\d{2}:\d{2}$/', $memberCancelTime)) {
                                        $memberCancelTime .= ':00';
                                    }
                                    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $memberCancelTime)) {
                                        $groupCancellationEligible = false;
                                    } else {
                                        $memberCancelDateTime = DateTimeImmutable::createFromFormat(
                                            '!Y-m-d H:i:s',
                                            $memberCancelDate . ' ' . $memberCancelTime,
                                            $cancellationTimezone
                                        );
                                        $memberCancelDateErrors = DateTimeImmutable::getLastErrors();
                                        if ($memberCancelDateTime === false
                                            || ($memberCancelDateErrors !== false && ($memberCancelDateErrors['warning_count'] > 0 || $memberCancelDateErrors['error_count'] > 0))
                                            || $memberCancelDateTime->format('Y-m-d H:i:s') !== $memberCancelDate . ' ' . $memberCancelTime) {
                                            $groupCancellationEligible = false;
                                        } else {
                                            if (($memberCancelDateTime->getTimestamp() - $nowForGroupCancellation->getTimestamp()) < 86400) {
                                                $groupCancellationEligible = false;
                                            }
                                            $memberCancelTimestamp = $memberCancelDateTime->getTimestamp();
                                            $groupEarliestAppointmentTimestamp = $groupEarliestAppointmentTimestamp === null
                                                ? $memberCancelTimestamp
                                                : min($groupEarliestAppointmentTimestamp, $memberCancelTimestamp);
                                        }
                                    }

                                    if ($groupReference === '' && trim((string)($groupMember['gcash_ref'] ?? '')) !== '') $groupReference = trim((string)$groupMember['gcash_ref']);
                                    $memberStart = strtotime((string)($groupMember['appointment_time'] ?? '09:00:00'));
                                    if ($memberStart !== false) {
                                        $minute = ((int)date('H', $memberStart) * 60) + (int)date('i', $memberStart);
                                        $groupStartMinute = min($groupStartMinute, $minute);
                                        $groupEndMinute = max($groupEndMinute, $minute + customerBookingDurationMinutes($groupService));
                                    }
                                }
                                $groupStatusDisplay = count($groupStatuses) === 1 ? reset($groupStatuses) : 'Multiple statuses';
                                $groupPaymentStatusDisplay = count($groupPaymentStatuses) === 1 ? reset($groupPaymentStatuses) : 'Mixed payment status';
                                $groupPaymentMethodDisplay = count($groupPaymentMethods) === 1 ? reset($groupPaymentMethods) : 'Multiple methods';
                                $groupStartDisplay = $groupStartMinute < PHP_INT_MAX ? date('g:i A', strtotime(sprintf('%02d:%02d:00', intdiv($groupStartMinute, 60), $groupStartMinute % 60))) : 'Time not set';
                                $groupEndDisplay = $groupEndMinute > 0 ? date('g:i A', strtotime(sprintf('%02d:%02d:00', intdiv($groupEndMinute, 60), $groupEndMinute % 60))) : '';
                                $groupRescheduleEligible = $groupRescheduleEligible
                                    && $groupHasRescheduleFlag
                                    && stripos($groupOriginalService, 'Grooming -') === 0;
                                $groupPaymentUpper = strtoupper($groupPaymentStatusDisplay);
                                $groupPaymentClass = (strpos($groupPaymentUpper, 'PAID') !== false || strpos($groupPaymentUpper, 'PENDING VERIFICATION') !== false) ? 'status-APPROVED' : 'status-PENDING';
                            ?>
                            <article class="schedule-item group-schedule-card">
                                <div class="date-box">
                                    <div class="date-month"><?php echo htmlspecialchars(date('M', strtotime($groupDate)), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="date-day"><?php echo htmlspecialchars(date('d', strtotime($groupDate)), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="date-week"><?php echo htmlspecialchars(date('l', strtotime($groupDate)), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="schedule-main">
                                    <div class="group-booking-label"><i class="fa-solid fa-people-group"></i> GROUP BOOKING · <?php echo count($groupRows); ?> PETS</div>
                                    <div class="schedule-service"><?php echo htmlspecialchars($groupName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    <div class="schedule-note"><?php echo htmlspecialchars($groupService, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> · Estimated group schedule: <?php echo htmlspecialchars($groupStartDisplay . ($groupEndDisplay !== '' ? ' – ' . $groupEndDisplay : ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="group-pet-list">
                                        <?php foreach ($groupRows as $groupMember): ?>
                                            <?php
                                                $memberPetName = (string)($groupMember['joined_pet_name'] ?? 'Unknown Pet');
                                                $memberStartRaw = (string)($groupMember['appointment_time'] ?? '');
                                                $memberStartTimestamp = $memberStartRaw !== '' ? strtotime($memberStartRaw) : false;
                                                $memberStartDisplay = $memberStartTimestamp !== false ? date('g:i A', $memberStartTimestamp) : 'Time not set';
                                                $memberDuration = customerBookingDurationMinutes((string)($groupMember['service'] ?? $groupService));
                                                $memberEndDisplay = $memberStartTimestamp !== false ? date('g:i A', $memberStartTimestamp + ($memberDuration * 60)) : '';
                                                $memberStatusDisplay = trim((string)($groupMember['booking_status'] ?? 'Pending')) ?: 'Pending';
                                                $memberPrice = (float)($groupMember['total_price'] ?? $groupMember['service_fee'] ?? 0);
                                            ?>
                                            <div class="group-pet-row">
                                                <div class="group-pet-info">
                                                    <strong><i class="fa-solid fa-paw"></i> <?php echo htmlspecialchars($memberPetName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                                                    <small><?php echo htmlspecialchars($memberStartDisplay . ($memberEndDisplay !== '' ? ' – ' . $memberEndDisplay : ''), ENT_QUOTES, 'UTF-8'); ?> · ₱<?php echo number_format($memberPrice, 2); ?></small>
                                                </div>
                                                <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $memberStatusDisplay)); ?>"><?php echo htmlspecialchars($memberStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="schedule-note"><strong>Group total:</strong> ₱<?php echo number_format($groupTotal, 2); ?> · Payment: <?php echo htmlspecialchars($groupPaymentMethodDisplay . ' - ' . $groupPaymentStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    <?php if ($groupReference !== ''): ?><div class="schedule-note"><i class="fa-solid fa-receipt"></i> GCash Reference No.: <?php echo htmlspecialchars($groupReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
                                </div>
                                <div class="schedule-status">
                                    <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $groupStatusDisplay)); ?>"><?php echo htmlspecialchars($groupStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <span class="status-pill <?php echo $groupPaymentClass; ?>"><?php echo htmlspecialchars($groupPaymentStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <?php if ($groupRescheduleEligible): ?>
                                        <button
                                            type="button"
                                            class="btn-reschedule-schedule"
                                            onclick="openRescheduleModal(
                                                <?php echo (int)($groupFirst['id'] ?? 0); ?>,
                                                <?php echo htmlspecialchars(json_encode($groupService, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode($groupName . ' (' . count($groupRows) . ' pets)', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode($groupOriginalDate, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>,
                                                '',
                                                true
                                            )"
                                        >
                                            <i class="fa-solid fa-calendar-check"></i> Reschedule Group
                                        </button>
                                    <?php else: ?>
                                        <span class="payment-status group-action-note">Group rescheduling appears here when the clinic marks the group For Rescheduling and all pets have verified payment.</span>
                                    <?php endif; ?>
                                    <?php if ($groupCancellationEligible && $groupEarliestAppointmentTimestamp !== null): ?>
                                        <button
                                            type="button"
                                            class="btn-cancel-schedule"
                                            onclick="openCancelModal(
                                                <?php echo (int)($groupFirst['id'] ?? 0); ?>,
                                                <?php echo htmlspecialchars(json_encode($groupService, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode($groupName . ' (' . count($groupRows) . ' pets)', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode(date('M d, Y \a\t g:i A', $groupEarliestAppointmentTimestamp), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>,
                                                true
                                            )"
                                        >
                                            <i class="fa-solid fa-xmark"></i> Cancel Group
                                        </button>
                                    <?php else: ?>
                                        <span class="payment-status group-action-note" role="status">Group cancellation is available only when every pet is eligible and at least 24 hours remain before the first appointment.</span>
                                    <?php endif; ?>
                                </div>
                            </article>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php
                            $apt_id = isset($row['id']) ? (int)$row['id'] : 0;
                            $display_pet = !empty($row['joined_pet_name'])
                                ? $row['joined_pet_name']
                                : (!empty($row['pet_name']) ? $row['pet_name'] : 'Unknown Pet');

                            $current_status = empty($row['booking_status'])
                                ? 'Pending'
                                : $row['booking_status'];

                            $status_upper = strtoupper($current_status);

                            $pay_status = $row['payment_status'] ?? 'Pending';
                            $pay_method = $row['payment_method'] ?? 'N/A';
                            $gcash_ref = trim((string)($row['gcash_ref'] ?? ''));
                            $is_gcash_payment = (strcasecmp(trim((string)$pay_method), 'GCash') === 0);
                            $payment_upper = strtoupper($pay_status);

                            $payment_class = (
                                $payment_upper === 'PAID' ||
                                $payment_upper === 'PENDING VERIFICATION'
                            ) ? 'status-APPROVED' : 'status-PENDING';

                            $fee = isset($row['total_price'])
                                ? floatval($row['total_price'])
                                : (isset($row['service_fee']) ? floatval($row['service_fee']) : 0);

                            $time_str = !empty($row['appointment_time'])
                                ? date('h:i A', strtotime($row['appointment_time']))
                                : 'Time not set';

                            $display_datetime = date('Y-m-d', strtotime($row['appointment_date'])) .
                                (!empty($row['appointment_time']) ? ' at ' . $time_str : '');

                            // Customers must cancel at least 24 hours before the scheduled start time.
                            // The server-side cancel_booking.php check remains the final enforcement.
                            $canCustomerCancelByCutoff = false;
                            try {
                                $cancellationTimezone = new DateTimeZone('Asia/Manila');
                                $cancelDate = substr((string)($row['appointment_date'] ?? ''), 0, 10);
                                $cancelTime = trim((string)($row['appointment_time'] ?? '')) ?: '00:00:00';
                                $scheduledDateTime = new DateTimeImmutable($cancelDate . ' ' . $cancelTime, $cancellationTimezone);
                                $nowForCancellation = new DateTimeImmutable('now', $cancellationTimezone);
                                $secondsUntilAppointment = $scheduledDateTime->getTimestamp() - $nowForCancellation->getTimestamp();
                                $canCustomerCancelByCutoff = $secondsUntilAppointment >= 86400;
                            } catch (Throwable $cancellationDateError) {
                                // Fail closed: do not offer cancellation when the scheduled time cannot be verified.
                                $canCustomerCancelByCutoff = false;
                            }
                        ?>

                        <article class="schedule-item">
                            <div class="date-box">
                                <div class="date-month"><?php echo date('M', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-day"><?php echo date('d', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-week"><?php echo date('l', strtotime($row['appointment_date'])); ?></div>
                            </div>

                            <div class="schedule-main">
                                <div class="schedule-service">
                                    <?php echo htmlspecialchars($row['service']); ?>
                                </div>

                                <div class="schedule-details">
                                    <span class="schedule-detail">
                                        <i class="fa-regular fa-clock"></i>
                                        <?php echo htmlspecialchars($time_str); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-paw"></i>
                                        <?php echo htmlspecialchars($display_pet); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-peso-sign"></i>
                                        <?php echo ($fee > 0) ? '₱' . number_format($fee, 2) : 'TBD'; ?>
                                    </span>
                                </div>

                                <div class="schedule-note">
                                    Payment: <?php echo htmlspecialchars($pay_method . ' - ' . $pay_status, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php if ($is_gcash_payment && $gcash_ref !== ''): ?>
                                    <div class="schedule-note">
                                        <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                                        GCash Reference No.: <?php echo htmlspecialchars($gcash_ref, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="schedule-status">
                                <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $current_status)); ?>">
                                    <i class="fa-solid fa-circle"></i>
                                    <?php echo htmlspecialchars($current_status); ?>
                                </span>

                                <span class="status-pill <?php echo $payment_class; ?>">
                                    <i class="fa-solid fa-wallet"></i>
                                    <?php echo htmlspecialchars($pay_status); ?>
                                </span>

                                <div class="schedule-action">
                                    <?php $reschedule_count = (int)($row['reschedule_count'] ?? 0); ?>

                                    <?php if ($status_upper === 'FOR RESCHEDULING' && $reschedule_count === 0): ?>
                                        <button
                                            type="button"
                                            class="btn-reschedule-schedule"
                                            onclick="openRescheduleModal(
                                                <?php echo $apt_id; ?>,
                                                '<?php echo addslashes(htmlspecialchars($row['service'])); ?>',
                                                '<?php echo addslashes(htmlspecialchars($display_pet)); ?>',
                                                '<?php echo htmlspecialchars(date('Y-m-d', strtotime($row['appointment_date']))); ?>',
                                                <?php echo htmlspecialchars(json_encode((string)($row['pickup_time'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                                            )"
                                        >
                                            <i class="fa-solid fa-calendar-days"></i> Reschedule
                                        </button>
                                    <?php elseif ($status_upper === 'RESCHEDULED'): ?>
                                        <span class="feedback-schedule rescheduled-note">
                                            <i class="fa-solid fa-check"></i> One-time reschedule used
                                        </span>
                                    <?php elseif ($status_upper === 'PENDING' || $status_upper === 'CONFIRMED'): ?>
                                        <?php if ($canCustomerCancelByCutoff): ?>
                                            <button
                                                type="button"
                                                class="btn-cancel-schedule"
                                                onclick="openCancelModal(
                                                    <?php echo $apt_id; ?>,
                                                    '<?php echo addslashes(htmlspecialchars($row['service'])); ?>',
                                                    '<?php echo addslashes(htmlspecialchars($display_pet)); ?>',
                                                    '<?php echo addslashes($display_datetime); ?>'
                                                )"
                                            >
                                                <i class="fa-solid fa-xmark"></i> Cancel
                                            </button>
                                        <?php else: ?>
                                            <span class="cancellation-locked-note" role="status" title="Cancellations must be made at least 24 hours before the appointment.">
                                                <i class="fa-solid fa-lock" aria-hidden="true"></i> Cannot cancel within 24 hours
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="schedule-empty">
                    <div class="schedule-empty-icon">
                        <i class="fa-regular fa-calendar-check"></i>
                    </div>

                    <h3>No upcoming appointments</h3>
                    <p>
                        Your future visits will appear here with the date, time,
                        service, pet, and booking status.
                    </p>

                    <a href="book_appointment.php" class="book-new-btn">
                        <i class="fa-solid fa-calendar-plus"></i>
                        Schedule an Appointment
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($past)): ?>
            <section class="section-card">
                <div class="section-head">
                    <div>
                        <div class="kicker">Previous visits</div>
                        <h2>Past Appointments</h2>
                        <p>Your completed, cancelled, no-show, or previous bookings.</p>
                    </div>

                    <div class="section-count">
                        <?php echo count($past); ?> records
                    </div>
                </div>

                <div class="schedule-list">
                    <?php foreach ($past as $row): ?>
                        <?php if (!empty($row['_is_group_booking'])): ?>
                            <?php
                                $groupRows = $row['_group_rows'] ?? [];
                                $groupName = (string)($row['_group_name'] ?? 'Pet Group Booking');
                                $groupFirst = $groupRows[0] ?? $row;
                                $groupService = (string)($groupFirst['service'] ?? 'Grooming');
                                $groupDate = (string)($groupFirst['appointment_date'] ?? '');
                                $groupTotal = 0.0;
                                $groupStatuses = [];
                                $groupPaymentStatuses = [];
                                $groupPaymentMethods = [];
                                $groupReference = '';
                                $groupStartMinute = PHP_INT_MAX;
                                $groupRescheduleEligible = count($groupRows) >= 2;
                                $groupHasRescheduleFlag = false;
                                $groupOriginalDate = substr((string)($groupFirst['appointment_date'] ?? ''), 0, 10);
                                $groupOriginalService = trim((string)($groupFirst['service'] ?? ''));
                                $groupEndMinute = 0;
                                foreach ($groupRows as $groupMember) {
                                    $groupTotal += (float)($groupMember['total_price'] ?? $groupMember['service_fee'] ?? 0);
                                    $memberStatusValue = trim((string)($groupMember['booking_status'] ?? 'Pending')) ?: 'Pending';
                                    $groupStatuses[strtoupper($memberStatusValue)] = $memberStatusValue;
                                    $memberPaymentStatus = trim((string)($groupMember['payment_status'] ?? 'Pending')) ?: 'Pending';
                                    $groupPaymentStatuses[strtoupper($memberPaymentStatus)] = $memberPaymentStatus;
                                    $memberPaymentMethod = trim((string)($groupMember['payment_method'] ?? 'N/A')) ?: 'N/A';
                                    $groupPaymentMethods[strtoupper($memberPaymentMethod)] = $memberPaymentMethod;
                                    $memberRescheduleStatus = strtoupper(trim((string)($groupMember['booking_status'] ?? '')));
                                    $memberReschedulePayment = strtoupper(trim((string)($groupMember['payment_status'] ?? '')));
                                    if ($memberRescheduleStatus === 'FOR RESCHEDULING') $groupHasRescheduleFlag = true;
                                    if (!in_array($memberRescheduleStatus, ['FOR RESCHEDULING', 'CONFIRMED'], true)
                                        || $memberReschedulePayment !== 'PAID'
                                        || (int)($groupMember['reschedule_count'] ?? 0) >= 1
                                        || substr((string)($groupMember['appointment_date'] ?? ''), 0, 10) !== $groupOriginalDate
                                        || trim((string)($groupMember['service'] ?? '')) !== $groupOriginalService) {
                                        $groupRescheduleEligible = false;
                                    }
                                    if ($groupReference === '' && trim((string)($groupMember['gcash_ref'] ?? '')) !== '') $groupReference = trim((string)$groupMember['gcash_ref']);
                                    $memberStart = strtotime((string)($groupMember['appointment_time'] ?? '09:00:00'));
                                    if ($memberStart !== false) {
                                        $minute = ((int)date('H', $memberStart) * 60) + (int)date('i', $memberStart);
                                        $groupStartMinute = min($groupStartMinute, $minute);
                                        $groupEndMinute = max($groupEndMinute, $minute + customerBookingDurationMinutes($groupService));
                                    }
                                }
                                $groupStatusDisplay = count($groupStatuses) === 1 ? reset($groupStatuses) : 'Multiple statuses';
                                $groupPaymentStatusDisplay = count($groupPaymentStatuses) === 1 ? reset($groupPaymentStatuses) : 'Mixed payment status';
                                $groupPaymentMethodDisplay = count($groupPaymentMethods) === 1 ? reset($groupPaymentMethods) : 'Multiple methods';
                                $groupStartDisplay = $groupStartMinute < PHP_INT_MAX ? date('g:i A', strtotime(sprintf('%02d:%02d:00', intdiv($groupStartMinute, 60), $groupStartMinute % 60))) : 'Time not set';
                                $groupEndDisplay = $groupEndMinute > 0 ? date('g:i A', strtotime(sprintf('%02d:%02d:00', intdiv($groupEndMinute, 60), $groupEndMinute % 60))) : '';
                                $groupRescheduleEligible = $groupRescheduleEligible
                                    && $groupHasRescheduleFlag
                                    && stripos($groupOriginalService, 'Grooming -') === 0;
                                $groupPaymentUpper = strtoupper($groupPaymentStatusDisplay);
                                $groupPaymentClass = (strpos($groupPaymentUpper, 'PAID') !== false || strpos($groupPaymentUpper, 'PENDING VERIFICATION') !== false) ? 'status-APPROVED' : 'status-PENDING';
                            ?>
                            <article class="schedule-item group-schedule-card">
                                <div class="date-box">
                                    <div class="date-month"><?php echo htmlspecialchars(date('M', strtotime($groupDate)), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="date-day"><?php echo htmlspecialchars(date('d', strtotime($groupDate)), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="date-week"><?php echo htmlspecialchars(date('l', strtotime($groupDate)), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="schedule-main">
                                    <div class="group-booking-label"><i class="fa-solid fa-people-group"></i> GROUP BOOKING · <?php echo count($groupRows); ?> PETS</div>
                                    <div class="schedule-service"><?php echo htmlspecialchars($groupName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    <div class="schedule-note"><?php echo htmlspecialchars($groupService, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> · Group schedule: <?php echo htmlspecialchars($groupStartDisplay . ($groupEndDisplay !== '' ? ' – ' . $groupEndDisplay : ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="group-pet-list">
                                        <?php foreach ($groupRows as $groupMember): ?>
                                            <?php
                                                $memberPetName = (string)($groupMember['joined_pet_name'] ?? 'Unknown Pet');
                                                $memberStartRaw = (string)($groupMember['appointment_time'] ?? '');
                                                $memberStartTimestamp = $memberStartRaw !== '' ? strtotime($memberStartRaw) : false;
                                                $memberStartDisplay = $memberStartTimestamp !== false ? date('g:i A', $memberStartTimestamp) : 'Time not set';
                                                $memberDuration = customerBookingDurationMinutes((string)($groupMember['service'] ?? $groupService));
                                                $memberEndDisplay = $memberStartTimestamp !== false ? date('g:i A', $memberStartTimestamp + ($memberDuration * 60)) : '';
                                                $memberStatusDisplay = trim((string)($groupMember['booking_status'] ?? 'Pending')) ?: 'Pending';
                                                $memberPrice = (float)($groupMember['total_price'] ?? $groupMember['service_fee'] ?? 0);
                                                $memberAppointmentId = (int)($groupMember['id'] ?? 0);
                                                $memberReviewId = (int)($groupMember['review_id'] ?? 0);
                                            ?>
                                            <div class="group-pet-row">
                                                <div class="group-pet-info">
                                                    <strong><i class="fa-solid fa-paw"></i> <?php echo htmlspecialchars($memberPetName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                                                    <small><?php echo htmlspecialchars($memberStartDisplay . ($memberEndDisplay !== '' ? ' – ' . $memberEndDisplay : ''), ENT_QUOTES, 'UTF-8'); ?> · ₱<?php echo number_format($memberPrice, 2); ?></small>
                                                </div>
                                                <div class="group-pet-actions">
                                                    <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $memberStatusDisplay)); ?>"><?php echo htmlspecialchars($memberStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                    <?php if (strtoupper($memberStatusDisplay) === 'COMPLETED' && $memberAppointmentId > 0 && $memberReviewId <= 0): ?>
                                                        <a href="write_review.php?appointment_id=<?php echo $memberAppointmentId; ?>" class="btn-review-schedule"><i class="fa-solid fa-star"></i> Review</a>
                                                    <?php elseif (strtoupper($memberStatusDisplay) === 'COMPLETED' && $memberReviewId > 0): ?>
                                                        <span class="feedback-schedule"><i class="fa-solid fa-check-double"></i> Feedback Sent</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="schedule-note"><strong>Group total:</strong> ₱<?php echo number_format($groupTotal, 2); ?> · Payment: <?php echo htmlspecialchars($groupPaymentMethodDisplay . ' - ' . $groupPaymentStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    <?php if ($groupReference !== ''): ?><div class="schedule-note"><i class="fa-solid fa-receipt"></i> GCash Reference No.: <?php echo htmlspecialchars($groupReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
                                </div>
                                <div class="schedule-status">
                                    <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $groupStatusDisplay)); ?>"><?php echo htmlspecialchars($groupStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <span class="status-pill <?php echo $groupPaymentClass; ?>"><?php echo htmlspecialchars($groupPaymentStatusDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <span class="payment-status">Group booking history</span>
                                </div>
                            </article>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php
                            $apt_id = isset($row['id']) ? (int)$row['id'] : 0;
                            $display_pet = !empty($row['joined_pet_name'])
                                ? $row['joined_pet_name']
                                : (!empty($row['pet_name']) ? $row['pet_name'] : 'Unknown Pet');

                            $current_status = empty($row['booking_status'])
                                ? 'Pending'
                                : $row['booking_status'];

                            $status_upper = strtoupper($current_status);

                            $pay_status = $row['payment_status'] ?? 'Pending';
                            $pay_method = $row['payment_method'] ?? 'N/A';
                            $gcash_ref = trim((string)($row['gcash_ref'] ?? ''));
                            $is_gcash_payment = (strcasecmp(trim((string)$pay_method), 'GCash') === 0);

                            $fee = isset($row['total_price'])
                                ? floatval($row['total_price'])
                                : (isset($row['service_fee']) ? floatval($row['service_fee']) : 0);

                            $time_str = !empty($row['appointment_time'])
                                ? date('h:i A', strtotime($row['appointment_time']))
                                : 'Time not set';

                            $display_datetime = date('Y-m-d', strtotime($row['appointment_date'])) .
                                (!empty($row['appointment_time']) ? ' at ' . $time_str : '');
                        ?>

                        <article class="schedule-item">
                            <div class="date-box">
                                <div class="date-month"><?php echo date('M', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-day"><?php echo date('d', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-week"><?php echo date('l', strtotime($row['appointment_date'])); ?></div>
                            </div>

                            <div class="schedule-main">
                                <div class="schedule-service">
                                    <?php echo htmlspecialchars($row['service']); ?>
                                </div>

                                <div class="schedule-details">
                                    <span class="schedule-detail">
                                        <i class="fa-regular fa-clock"></i>
                                        <?php echo htmlspecialchars($time_str); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-paw"></i>
                                        <?php echo htmlspecialchars($display_pet); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-peso-sign"></i>
                                        <?php echo ($fee > 0) ? '₱' . number_format($fee, 2) : 'TBD'; ?>
                                    </span>
                                </div>

                                <div class="schedule-note">
                                    Payment: <?php echo htmlspecialchars($pay_method . ' - ' . $pay_status, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php if ($is_gcash_payment && $gcash_ref !== ''): ?>
                                    <div class="schedule-note">
                                        <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                                        GCash Reference No.: <?php echo htmlspecialchars($gcash_ref, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="schedule-status">
                                <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $current_status)); ?>">
                                    <?php echo htmlspecialchars($current_status); ?>
                                </span>

                                <div class="schedule-action">
                                    <?php if ($status_upper === 'COMPLETED' && empty($row['review_id'])): ?>
                                        <a
                                            href="write_review.php?appointment_id=<?php echo $apt_id; ?>"
                                            class="btn-review-schedule"
                                        >
                                            <i class="fa-solid fa-star"></i> Write Review
                                        </a>
                                    <?php elseif ($status_upper === 'COMPLETED' && !empty($row['review_id'])): ?>
                                        <span class="feedback-schedule">
                                            <i class="fa-solid fa-check-double"></i> Feedback Sent
                                        </span>
                                    <?php else: ?>
                                        <span class="payment-status">
                                            Previous appointment
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="schedule-info">
            <div class="info-mini">
                <div class="info-mini-icon">
                    <i class="fa-regular fa-calendar-check"></i>
                </div>
                <div>
                    <strong>Check your schedule</strong>
                    <span>Use the upcoming section to see when you need to visit.</span>
                </div>
            </div>

            <div class="info-mini">
                <div class="info-mini-icon">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <strong>Arrive on time</strong>
                    <span>Keep your appointment time in mind for a smooth visit.</span>
                </div>
            </div>

            <div class="info-mini">
                <div class="info-mini-icon">
                    <i class="fa-solid fa-phone"></i>
                </div>
                <div>
                    <strong>Need help?</strong>
                    <span>Call Boogie's at (046) 887 4714 for assistance.</span>
                </div>
            </div>
        </section>
    </main>
    <div id="rescheduleModal" class="modal-overlay">
        <div class="reschedule-modal-content">
            <div class="modal-header">
                <div class="reschedule-icon"><i class="fa-solid fa-calendar-days"></i></div>
                <div>
                    <h2 id="rescheduleModalTitle">Reschedule Appointment</h2>
                    <p id="rescheduleModalSubtitle">You may reschedule this booking only once, within 3 days.</p>
                </div>
            </div>

            <div class="booking-details-box">
                <p>Booking Details:</p>
                <h3 id="rescheduleService">Service</h3>
                <div class="detail-row">Pet: <span id="reschedulePet" style="color: var(--text-muted);"></span></div>
            </div>

            <div class="reschedule-help" id="rescheduleHelp">
                <strong>Important:</strong> Your original verified payment stays valid and non-refundable.
                Choose an available schedule within 3 days after the original appointment. Regular services must finish by 5:00 PM;
                available start times depend on the selected service duration.
                After this reschedule is used, another missed appointment will be treated as a No-Show and the payment will be forfeited.
            </div>
            <div class="reschedule-help" id="groupRescheduleInfo" style="display:none;background:#fff8df;border-color:#f3df9c;color:#765700;">
                <strong>Group Booking:</strong> All pets in this group will move together. The selected time is the first pet's start time;
                the system will automatically assign each remaining pet to an available groomer and check the two-groomer limit,
                lunch capacity, existing bookings, daily limits, and the 5:00 PM service cutoff. Check My Schedule after rescheduling
                to see each pet's individual updated time.
            </div>

            <form method="POST" action="">
                <input type="hidden" name="reschedule_booking" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="appointment_id" id="rescheduleAppointmentId">

                <div class="reschedule-form-row">
                    <div class="modal-form-group">
                        <label>New Date *</label>
                        <input
                            type="date"
                            name="reschedule_date"
                            id="rescheduleDate"
                            min="<?php echo date('Y-m-d'); ?>"
                            max="<?php echo date('Y-m-d', strtotime('+3 days')); ?>"
                            required
                        >
                    </div>

                    <div class="modal-form-group">
                        <label>New Time *</label>
                        <select name="reschedule_time" id="rescheduleTime" required>
                            <option value="">Choose a service first</option>
                        </select>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-keep" onclick="closeRescheduleModal()">Keep Current Status</button>
                    <button type="submit" class="btn-reschedule-schedule">Confirm Reschedule</button>
                </div>
            </form>
        </div>
    </div>

    <div id="cancelModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div class="warning-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <h2 id="cancelModalTitle">Cancel Appointment</h2>
                    <p id="cancelModalSubtitle">This action cannot be undone</p>
                </div>
            </div>
            
            <div class="booking-details-box">
                <p>Booking Details:</p>
                <h3 id="modalService">Grooming</h3>
                <div class="detail-row">Pet: <span id="modalPet" style="color: var(--text-muted);"></span></div>
                <div class="detail-row">Date: <span id="modalDate" style="color: var(--text-muted);"></span></div>
            </div>

            <form action="cancel_booking.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="appointment_id" id="cancelAppointmentId">
                <div class="modal-form-group">
                    <label>Reason for Cancellation *</label>
                    <select name="cancel_reason" required>
                        <option value="">Select a reason</option>
                        <option value="Schedule Conflict">Schedule Conflict</option>
                        <option value="Pet is Unwell">Pet is Unwell</option>
                        <option value="Personal Emergency">Personal Emergency</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="important-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div id="cancelModalWarning"><strong>Important:</strong> You must cancel at least 24 hours before the scheduled appointment. Customer cancellations are disabled during the final 24 hours. No-Show or forfeited payments are non-refundable under the Terms &amp; Conditions.</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-keep" onclick="closeCancelModal()">Keep Booking</button>
                    <button type="submit" class="btn-confirm-cancel" id="cancelSubmitButton">Cancel Appointment</button>
                </div>
            </form>
        </div>
    </div>

    <footer>
        <div class="footer-main">
            <div>
                <h4><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>
                <div class="socials">
                    <a href="https://www.facebook.com/boogiespetsupplies" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="https://mail.google.com/mail/?view=cm&to=boogiespetcareservices@gmail.com" aria-label="Email" onclick="return openBoogiesGmail(event);"><i class="fa-solid fa-envelope"></i></a>
                </div>
            </div>

            <div>
                <h4>Quick Links</h4>
                <a href="home.php">Home</a>
                <a href="petservices.php">Services & Prices</a>
                <a href="contactus.php">Contact & Reviews</a>
                <a href="faqs.php">FAQs</a>
            </div>

            <div>
                <h4>Services</h4>
                <a href="grooming.php">Grooming</a>
                <a href="pethotel.php">Pet Hotel</a>
                <a href="vetclinic.php">Vet Clinic</a>
            </div>

            <div>
                <h4>Contact Us</h4>
                <p><i class="fa-solid fa-phone"></i> (046) 887 4714</p>
                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices@gmail.com</p>
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
            </div>
        </div>

        <div class="footer-bottom">
            © <?php echo date("Y"); ?> Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.
            <?php include __DIR__ . '/customer_footer.php'; ?>
        </div>
    </footer>

    <script>
        function getRescheduleDurationMinutes(service) {
            const name = String(service || '').trim().toLowerCase();

            if (name.startsWith('grooming')) {
                if (name.includes('full grooming package') || /\bfg\b/.test(name)) return 120;
                if (name.includes('bath & blow dry') || name.includes('bath and blow dry') || name.includes('cat bath')) return 90;
                if (name.includes('basic pet grooming') || name.includes('cat grooming (basic)')) return 60;
                return 120;
            }

            if (name.startsWith('vet services')) return 30;
            if (name.startsWith('pet hotel')) {
                if (name.includes('daycare')) return 180;
                if (name.includes('boarding') || name.includes('overnight')) return 30;
            }

            return 0;
        }

        function rescheduleTimeStringToMinutes(time) {
            const value = String(time || '').trim();
            const match = value.match(/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/);
            if (!match) return null;
            return (Number(match[1]) * 60) + Number(match[2]);
        }

        function populateRescheduleTimes(service, pickupTime = '') {
            const select = document.getElementById('rescheduleTime');
            const name = String(service || '').trim().toLowerCase();
            const options = [];
            const openingMinutes = 9 * 60;
            const breakStartMinutes = 12 * 60;
            const breakEndMinutes = 13 * 60;
            const latestStartMinutes = 16 * 60;
            const cutoffMinutes = 17 * 60;
            const pickupMinutes = rescheduleTimeStringToMinutes(pickupTime);

            if (name.startsWith('pet hotel') && (name.includes('boarding') || name.includes('overnight'))) {
                options.push(9 * 60);
            } else if (name.startsWith('pet hotel') && name.includes('daycare')) {
                // Keep the existing pickup time when rescheduling. Only show drop-off
                // choices earlier than that pickup, with pickup no later than 5:00 PM.
                if (pickupMinutes !== null && pickupMinutes > openingMinutes && pickupMinutes <= cutoffMinutes) {
                    for (const [periodStart, periodEnd] of [[openingMinutes, breakStartMinutes], [breakEndMinutes, latestStartMinutes + 30]]) {
                        for (let start = periodStart; start < periodEnd && start <= latestStartMinutes && start < pickupMinutes; start += 30) {
                            options.push(start);
                        }
                    }
                }
            } else {
                const duration = getRescheduleDurationMinutes(service);
                if (duration > 0) {
                    for (const [periodStart, periodEnd] of [[openingMinutes, breakStartMinutes], [breakEndMinutes, cutoffMinutes + 1]]) {
                        for (
                            let start = periodStart;
                            start < periodEnd &&
                            start <= latestStartMinutes &&
                            (start + duration) <= cutoffMinutes &&
                            // A service must finish before its operating period ends.
                            (start + duration) <= periodEnd;
                            start += duration
                        ) {
                            options.push(start);
                        }
                    }
                }
            }

            select.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = options.length ? 'Choose a time' : 'No reschedule times available';
            select.appendChild(placeholder);

            options.forEach(minutes => {
                const hour24 = Math.floor(minutes / 60);
                const minute = minutes % 60;
                const value = String(hour24).padStart(2, '0') + ':' + String(minute).padStart(2, '0') + ':00';
                const hour12 = hour24 % 12 || 12;
                const suffix = hour24 >= 12 ? 'PM' : 'AM';
                const label = String(hour12).padStart(2, '0') + ':' + String(minute).padStart(2, '0') + ' ' + suffix;
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                select.appendChild(option);
            });

            select.disabled = options.length === 0;
        }

        function openRescheduleModal(id, service, pet, originalDate, pickupTime = '', isGroup = false) {
            document.getElementById('rescheduleAppointmentId').value = id;
            document.getElementById('rescheduleService').innerText = service;
            document.getElementById('reschedulePet').innerText = pet;
            document.getElementById('rescheduleDate').value = '';

            const modalTitle = document.getElementById('rescheduleModalTitle');
            const modalSubtitle = document.getElementById('rescheduleModalSubtitle');
            const groupInfo = document.getElementById('groupRescheduleInfo');
            const submitButton = document.querySelector('#rescheduleModal button[type="submit"]');
            if (isGroup) {
                modalTitle.innerText = 'Reschedule Group Booking';
                modalSubtitle.innerText = 'All pets will be rescheduled together. This is allowed only once, within 3 days.';
                groupInfo.style.display = 'block';
                if (submitButton) submitButton.innerHTML = '<i class="fa-solid fa-calendar-check"></i> Confirm Group Reschedule';
            } else {
                modalTitle.innerText = 'Reschedule Appointment';
                modalSubtitle.innerText = 'You may reschedule this booking only once, within 3 days.';
                groupInfo.style.display = 'none';
                if (submitButton) submitButton.innerHTML = 'Confirm Reschedule';
            }
            populateRescheduleTimes(service, pickupTime);

            const dateInput = document.getElementById('rescheduleDate');
            const now = new Date();
            const todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            let maxDateStr = todayStr;

            if (originalDate) {
                const base = new Date(originalDate + 'T00:00:00');
                base.setDate(base.getDate() + 3);
                maxDateStr = base.getFullYear() + '-' + String(base.getMonth() + 1).padStart(2, '0') + '-' + String(base.getDate()).padStart(2, '0');
            }

            dateInput.min = todayStr;
            dateInput.max = maxDateStr;
            document.getElementById('rescheduleModal').style.display = 'flex';
        }

        function closeRescheduleModal() {
            document.getElementById('rescheduleModal').style.display = 'none';
        }

        function openCancelModal(id, service, pet, dateStr, isGroup = false) {
            document.getElementById('cancelAppointmentId').value = id;
            document.getElementById('modalService').innerText = service;
            document.getElementById('modalPet').innerText = pet;
            document.getElementById('modalDate').innerText = dateStr;

            const modalTitle = document.getElementById('cancelModalTitle');
            const modalSubtitle = document.getElementById('cancelModalSubtitle');
            const modalWarning = document.getElementById('cancelModalWarning');
            const submitButton = document.getElementById('cancelSubmitButton');
            if (isGroup) {
                modalTitle.innerText = 'Cancel Pet Group Booking';
                modalSubtitle.innerText = 'This will cancel every pet appointment in this group.';
                modalWarning.innerHTML = '<strong>Important:</strong> The entire group will be cancelled together. Cancellation is allowed only at least 24 hours before the earliest pet appointment. If the shared payment is Paid or Pending Verification, its status will become Refund Requested; this does not mean an automatic refund. No-Show or forfeited payments are non-refundable under the Terms &amp; Conditions.';
                submitButton.innerHTML = '<i class="fa-solid fa-xmark"></i> Cancel Entire Group';
            } else {
                modalTitle.innerText = 'Cancel Appointment';
                modalSubtitle.innerText = 'This action cannot be undone';
                modalWarning.innerHTML = '<strong>Important:</strong> You must cancel at least 24 hours before the scheduled appointment. Customer cancellations are disabled during the final 24 hours. No-Show or forfeited payments are non-refundable under the Terms &amp; Conditions.';
                submitButton.innerHTML = 'Cancel Appointment';
            }
            document.getElementById('cancelModal').style.display = 'flex';
        }
        
        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        // --- DROPDOWN LOGIC MULA SA DASHBOARD ---
        function toggleDropdown(id) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) {
                    menu.classList.remove('active');
                }
            });
            document.getElementById(id).classList.toggle('active');
        }

        window.addEventListener('click', function(e) {
            if (!document.querySelector('.notification-wrapper').contains(e.target) && 
                !document.querySelector('.profile-wrapper').contains(e.target)) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        // --- AUTOMATIC NOTIFICATION DROPDOWN + BADGE REFRESH ---
        const notifSound = new Audio('notification.mp3');
        let previousUnreadCount = <?php echo $unread_count; ?>;
        const notificationCsrfToken = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escapeNotificationHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, character => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[character]));
        }

        function renderNotificationDropdown(notifications) {
            const content = document.getElementById('notif-content');
            if (!content) return;

            if (!Array.isArray(notifications) || notifications.length === 0) {
                content.innerHTML = '<div class="dropdown-item" style="text-align:center;color:#888;">No new notifications.</div>';
                return;
            }

            const recentItems = notifications.map(notification => {
                const message = escapeNotificationHtml(notification.message);
                const time = escapeNotificationHtml(notification.time);
                const unreadClass = notification.is_read ? '' : ' unread';
                return '<a href="notifications.php" class="dropdown-item' + unreadClass + '">' +
                    message + '<br><small style="color:#888;font-size:10px;">' + time + '</small></a>';
            }).join('');

            const viewAllForm = '<form method="POST" action="mark_all_read.php" class="view-all-form">' +
                '<input type="hidden" name="csrf_token" value="' + escapeNotificationHtml(notificationCsrfToken) + '">' +
                '<button type="submit" class="dropdown-item view-all-link view-all-button">View All Notifications</button>' +
                '</form>';

            content.innerHTML = recentItems + viewAllForm;
        }

        let notificationRefreshInProgress = false;

        async function updateNotifications() {
            // Avoid extra traffic while hidden and avoid overlapping slow requests.
            if (document.hidden || notificationRefreshInProgress) return;
            notificationRefreshInProgress = true;

            try {
                const response = await fetch('bookings.php?ajax=notifications', {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Notification refresh request failed (' + response.status + ').');
                }

                const data = await response.json();
                const badge = document.getElementById('notif-badge');
                const unread = Number(data && data.unread);

                // Do not overwrite valid UI state if the server response is incomplete.
                if (!badge || !Number.isInteger(unread) || unread < 0 || !Array.isArray(data.notifications)) {
                    return;
                }

                if (unread > previousUnreadCount) {
                    notifSound.play().catch(() => {});
                }

                previousUnreadCount = unread;
                badge.innerText = String(unread);
                badge.style.display = unread > 0 ? 'inline-flex' : 'none';
                renderNotificationDropdown(data.notifications);
            } catch (error) {
                console.warn('Could not refresh notifications:', error);
            } finally {
                notificationRefreshInProgress = false;
            }
        }

        // Server-rendered notification data is already present on initial load.
        // Poll less often, and refresh once when the user returns to the tab.
        setInterval(() => {
            if (!document.hidden) updateNotifications();
        }, 15000);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) updateNotifications();
        });
    </script>
</body>
</html>
