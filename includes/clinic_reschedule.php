<?php
/**
 * Clinic-initiated appointment rescheduling.
 * Keeps booking/payment status and the customer's one-time reschedule counter unchanged.
 */

if (!function_exists('boogieClinicReschedule')) {
    function boogieClinicReschedule(
        PDO $pdo,
        int $appointmentId,
        string $newDateInput,
        string $newTimeInput,
        string $reasonInput,
        string $actorRole
    ): array {
        $timezone = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->format('Y-m-d');
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reasonInput));

        $fail = static function (string $message): array {
            return ['success' => false, 'message' => $message];
        };

        if ($appointmentId <= 0) {
            return $fail('Invalid booking reference.');
        }
        if ($reason === '' || (function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason)) > 250) {
            return $fail('Enter a reason for the clinic-initiated reschedule (maximum 250 characters).');
        }

        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $newDateInput, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $dateIsValid = $dateObject !== false
            && $dateObject->format('Y-m-d') === $newDateInput
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
        if (!$dateIsValid) {
            return $fail('Choose a valid new appointment date.');
        }
        if ($newDateInput < $today) {
            return $fail('The new appointment date cannot be in the past.');
        }

        $timeInput = trim($newTimeInput);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $timeInput)) {
            return $fail('Choose a valid appointment time.');
        }
        $timeObject = DateTimeImmutable::createFromFormat('!H:i:s', strlen($timeInput) === 5 ? ($timeInput . ':00') : $timeInput, $timezone);
        if ($timeObject === false) {
            return $fail('Choose a valid appointment time.');
        }
        $newTime = $timeObject->format('H:i:s');
        $role = strtolower(trim($actorRole));

        try {
            $pdo->beginTransaction();

            $bookingStmt = $pdo->prepare("SELECT a.*, p.name AS pet_name,
                    u.full_name AS customer_name, u.contact_number
                FROM public.appointments a
                LEFT JOIN public.pets p ON p.id = a.pet_id
                LEFT JOIN public.users u ON u.id = a.user_id
                WHERE a.id = :id
                LIMIT 1
                FOR UPDATE OF a");
            $bookingStmt->execute([':id' => $appointmentId]);
            $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                $pdo->rollBack();
                return $fail('Booking not found.');
            }

            $service = trim((string)($booking['service'] ?? ''));
            $serviceLower = strtolower($service);
            $serviceSpecific = preg_replace('/^[^-]+\s*-\s*/', '', $service, 1);
            $serviceSpecific = is_string($serviceSpecific) ? trim($serviceSpecific) : $service;
            if (str_starts_with($serviceLower, 'grooming')) {
                $category = 'Grooming';
            } elseif (str_starts_with($serviceLower, 'vet services')) {
                $category = 'Vet Services';
            } elseif (str_starts_with($serviceLower, 'pet hotel')) {
                $category = 'Pet Hotel';
            } else {
                $pdo->rollBack();
                return $fail('This booking service is not supported for clinic-initiated rescheduling.');
            }

            $roleAllowed = in_array($role, ['admin', 'administrator', 'manager', 'receptionist'], true)
                || ($role === 'groomer' && $category === 'Grooming')
                || ($role === 'pet_hotel_staff' && $category === 'Pet Hotel')
                || (in_array($role, ['vet', 'veterinarian'], true) && $category === 'Vet Services');
            if (!$roleAllowed) {
                $pdo->rollBack();
                return $fail('Your account is not authorized to reschedule this service.');
            }

            $currentStatus = strtolower(trim((string)($booking['booking_status'] ?? 'pending')));
            if (!in_array($currentStatus, ['confirmed', 'rescheduled'], true)) {
                $pdo->rollBack();
                return $fail('Only confirmed or already-rescheduled bookings can be moved by the clinic.');
            }

            $isBoarding = $category === 'Pet Hotel' && stripos($service, 'boarding') !== false;
            if ($isBoarding) {
                if ($newTime !== '09:00:00') {
                    $pdo->rollBack();
                    return $fail('Pet Boarding check-in is fixed at 9:00 AM.');
                }
                if ($newDateInput === $today && $now->format('H:i:s') >= '09:00:00') {
                    $pdo->rollBack();
                    return $fail('The 9:00 AM Pet Boarding check-in time has already passed today. Choose another date.');
                }
            } else {
                $allowedTimes = [
                    '10:00:00', '11:00:00', '12:00:00', '13:00:00',
                    '14:00:00', '15:00:00', '16:00:00', '17:00:00'
                ];
                if (!in_array($newTime, $allowedTimes, true)) {
                    $pdo->rollBack();
                    return $fail('Choose an available appointment time between 10:00 AM and 5:00 PM.');
                }
                if ($newDateInput === $today && $newTime <= $now->format('H:i:s')) {
                    $pdo->rollBack();
                    return $fail('The new appointment time must still be in the future.');
                }
            }

            // Veterinary Services operate on Wednesdays and Saturdays too; availability is determined by capacity, operating hours, and active adjustments below.

            $oldDate = substr((string)($booking['appointment_date'] ?? ''), 0, 10);
            $oldTime = substr((string)($booking['appointment_time'] ?? ''), 0, 8);
            if ($oldDate === $newDateInput && $oldTime === $newTime) {
                $pdo->rollBack();
                return $fail('Choose a different date or time from the current appointment.');
            }

            $oldCheckout = substr((string)($booking['checkout_date'] ?? ''), 0, 10);
            $newCheckout = null;
            if ($isBoarding) {
                if ($oldCheckout === '' || $oldCheckout <= $oldDate) {
                    $oldCheckout = (new DateTimeImmutable($oldDate, $timezone))->modify('+1 day')->format('Y-m-d');
                }
                $nights = max(1, (int)(new DateTimeImmutable($oldDate, $timezone))->diff(new DateTimeImmutable($oldCheckout, $timezone))->days);
                $newCheckout = $dateObject->modify('+' . $nights . ' days')->format('Y-m-d');
            }

            // Serialize simultaneous moves/bookings for the affected service/date.
            $lockDates = [$newDateInput];
            if ($isBoarding && $newCheckout !== null) {
                $lockDates = [];
                for ($day = $dateObject; $day->format('Y-m-d') < $newCheckout; $day = $day->modify('+1 day')) {
                    $lockDates[] = $day->format('Y-m-d');
                }
            }
            $lockStmt = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key)::bigint)');
            foreach ($lockDates as $lockDate) {
                // Match the existing project locks: the date-wide key serializes walk-ins,
                // while the category key serializes customer online bookings/reschedules.
                $lockStmt->execute([':lock_key' => 'boogie-capacity|' . $lockDate]);
                $lockStmt->execute([':lock_key' => 'boogie-capacity|' . $lockDate . '|' . $category]);
            }

            $activeSql = "LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')";
            $durationMinutes = 60;
            if ($category === 'Grooming' && stripos($service, 'Full Grooming Package') !== false) {
                $durationMinutes = 120;
            } elseif ($category === 'Vet Services') {
                $durationMinutes = 30;
            } elseif ($category === 'Pet Hotel' && !$isBoarding) {
                $pickup = substr((string)($booking['pickup_time'] ?? ''), 0, 8);
                if (preg_match('/^\d{2}:\d{2}/', $pickup)) {
                    $startMinute = ((int)substr($newTime, 0, 2) * 60) + (int)substr($newTime, 3, 2);
                    $pickupMinute = ((int)substr($pickup, 0, 2) * 60) + (int)substr($pickup, 3, 2);
                    if ($pickupMinute <= $startMinute) {
                        $pdo->rollBack();
                        return $fail('The new daycare start time must be earlier than the expected pickup time.');
                    }
                    $durationMinutes = max(30, $pickupMinute - $startMinute);
                }
            }

            $manualAdjustments = [];
            $availabilityTableExists = (bool)$pdo->query("SELECT to_regclass('public.availability_adjustments')")->fetchColumn();
            $adjustmentRangeEnd = $isBoarding && $newCheckout !== null
                ? $newCheckout
                : $dateObject->modify('+1 day')->format('Y-m-d');
            if ($availabilityTableExists) {
                $adjustmentStmt = $pdo->prepare("SELECT service_category,
                        COALESCE(specific_service, '') AS specific_service,
                        start_date::text AS start_date, end_date::text AS end_date,
                        start_time::text AS start_time, end_time::text AS end_time,
                        LOWER(BTRIM(adjustment_type)) AS adjustment_type,
                        quantity
                    FROM public.availability_adjustments
                    WHERE is_active = TRUE
                      AND start_date < CAST(:range_end AS date)
                      AND end_date > CAST(:range_start AS date)");
                $adjustmentStmt->execute([
                    ':range_start' => $newDateInput,
                    ':range_end' => $adjustmentRangeEnd,
                ]);
                $manualAdjustments = $adjustmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $parseMinute = static function (string $value): ?int {
                $value = trim($value);
                if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches)) return null;
                $hour = (int)$matches[1];
                $minute = (int)$matches[2];
                if ($hour > 23 || $minute > 59) return null;
                return ($hour * 60) + $minute;
            };
            $overlaps = static function (int $startA, int $endA, int $startB, int $endB): bool {
                return $startA < $endB && $startB < $endA;
            };
            $newStartMinute = ((int)substr($newTime, 0, 2) * 60) + (int)substr($newTime, 3, 2);
            $newEndMinute = $newStartMinute + $durationMinutes;
            if (!$isBoarding && $newEndMinute > (18 * 60)) {
                $pdo->rollBack();
                return $fail('The selected service would finish after the 6:00 PM closing time. Choose an earlier time.');
            }

            if ($isBoarding) {
                $boardingRowsStmt = $pdo->prepare("SELECT id, appointment_date::text AS appointment_date,
                        COALESCE(checkout_date, appointment_date + 1)::text AS checkout_date,
                        service
                    FROM public.appointments
                    WHERE id <> :id AND {$activeSql}
                      AND appointment_date < CAST(:checkout_date AS date)
                      AND COALESCE(checkout_date, appointment_date + 1) > CAST(:checkin_date AS date)");
                $boardingRowsStmt->execute([
                    ':id' => $appointmentId,
                    ':checkout_date' => $newCheckout,
                    ':checkin_date' => $newDateInput,
                ]);
                $overlappingBookings = $boardingRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                for ($day = $dateObject; $day->format('Y-m-d') < $newCheckout; $day = $day->modify('+1 day')) {
                    $dayString = $day->format('Y-m-d');
                    $dailyTotal = 0;
                    $hotelCount = 0;
                    foreach ($overlappingBookings as $existing) {
                        $existingStart = substr((string)($existing['appointment_date'] ?? ''), 0, 10);
                        $existingEnd = substr((string)($existing['checkout_date'] ?? ''), 0, 10);
                        $existingService = strtolower((string)($existing['service'] ?? ''));
                        $existingIsBoarding = str_starts_with($existingService, 'pet hotel') && str_contains($existingService, 'boarding');
                        $activeOnDay = $existingIsBoarding
                            ? ($existingStart <= $dayString && $existingEnd > $dayString)
                            : ($existingStart === $dayString);
                        if ($activeOnDay) {
                            $dailyTotal++;
                            if (str_starts_with($existingService, 'pet hotel')) $hotelCount++;
                        }
                    }

                    $manualOccupied = 0;
                    $manualHotelOccupied = 0;
                    $manualHotelReduced = 0;
                    $manualBlocked = false;
                    foreach ($manualAdjustments as $adjustment) {
                        $adjustStart = substr((string)($adjustment['start_date'] ?? ''), 0, 10);
                        $adjustEnd = substr((string)($adjustment['end_date'] ?? ''), 0, 10);
                        if ($adjustStart > $dayString || $adjustEnd <= $dayString) continue;
                        $type = strtolower(trim((string)($adjustment['adjustment_type'] ?? '')));
                        $adjustmentCategory = trim((string)($adjustment['service_category'] ?? ''));
                        $specific = trim((string)($adjustment['specific_service'] ?? ''));
                        $quantity = max(1, (int)($adjustment['quantity'] ?? 1));
                        $startVal = trim((string)($adjustment['start_time'] ?? ''));
                        $endVal = trim((string)($adjustment['end_time'] ?? ''));
                        $adjStart = ($startVal === '' && $endVal === '') ? 0 : $parseMinute($startVal);
                        $adjEnd = ($startVal === '' && $endVal === '') ? 1440 : $parseMinute($endVal);
                        if ($adjStart === null || $adjEnd === null || !$overlaps(0, 1440, $adjStart, $adjEnd)) continue;
                        if ($type === 'occupied') {
                            $manualOccupied += $quantity;
                            if ($adjustmentCategory === 'Pet Hotel') $manualHotelOccupied += $quantity;
                        } elseif ($adjustmentCategory === 'Pet Hotel' && in_array($type, ['blocked', 'capacity_reduced'], true)) {
                            if ($specific !== '' && strcasecmp($specific, $serviceSpecific) !== 0) continue;
                            if ($type === 'capacity_reduced') {
                                $manualHotelReduced += $quantity;
                            } else {
                                $manualBlocked = true;
                            }
                        }
                    }
                    $dailyTotal += $manualOccupied;
                    $hotelCount += $manualHotelOccupied + $manualHotelReduced;
                    if ($manualBlocked || $hotelCount >= 6 || $dailyTotal >= 20) {
                        $pdo->rollBack();
                        return $fail('Pet Boarding has no room available for the entire new stay. Check room capacity and Admin availability adjustments for ' . date('M d, Y', strtotime($dayString)) . '.');
                    }
                }
            } else {
                $rowsStmt = $pdo->prepare("SELECT a.appointment_date::text AS appointment_date,
                        a.appointment_time::text AS appointment_time,
                        a.checkout_date::text AS checkout_date,
                        a.pickup_time::text AS pickup_time,
                        a.service
                    FROM public.appointments a
                    WHERE {$activeSql}
                      AND a.id <> :id
                      AND (a.appointment_date = CAST(:target_date AS date)
                           OR (LOWER(COALESCE(a.service, '')) LIKE 'pet hotel%boarding%'
                               AND a.appointment_date < CAST(:target_before AS date)
                               AND COALESCE(a.checkout_date, a.appointment_date + 1) > CAST(:target_active AS date)))");
                $rowsStmt->execute([
                    ':id' => $appointmentId,
                    ':target_date' => $newDateInput,
                    ':target_before' => $newDateInput,
                    ':target_active' => $newDateInput,
                ]);
                $existingRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $dailyTotal = 0;
                $categoryCount = 0;
                $overlapCount = 0;
                foreach ($existingRows as $existing) {
                    $existingService = trim((string)($existing['service'] ?? ''));
                    $existingServiceLower = strtolower($existingService);
                    $existingDate = substr((string)($existing['appointment_date'] ?? ''), 0, 10);
                    $existingCheckout = substr((string)($existing['checkout_date'] ?? ''), 0, 10);
                    $existingIsBoarding = str_starts_with($existingServiceLower, 'pet hotel') && str_contains($existingServiceLower, 'boarding');
                    if ($existingIsBoarding && $existingCheckout === '') {
                        $existingCheckout = (new DateTimeImmutable($existingDate, $timezone))->modify('+1 day')->format('Y-m-d');
                    }
                    $activeOnDate = $existingIsBoarding
                        ? ($existingDate <= $newDateInput && $existingCheckout > $newDateInput)
                        : ($existingDate === $newDateInput);
                    if (!$activeOnDate) continue;
                    $dailyTotal++;
                    if (str_starts_with($existingServiceLower, strtolower($category))) $categoryCount++;

                    $sameCategoryAndNotBoarding = str_starts_with($existingServiceLower, strtolower($category)) && !$existingIsBoarding && $existingDate === $newDateInput;
                    if (!$sameCategoryAndNotBoarding) continue;
                    $existingTime = substr((string)($existing['appointment_time'] ?? ''), 0, 5);
                    if (!preg_match('/^\d{2}:\d{2}$/', $existingTime)) continue;
                    $existingStart = ((int)substr($existingTime, 0, 2) * 60) + (int)substr($existingTime, 3, 2);
                    if ($category === 'Grooming') {
                        $existingDuration = stripos($existingService, 'Full Grooming Package') !== false ? 120 : 60;
                    } elseif ($category === 'Vet Services') {
                        $existingDuration = 30;
                    } else {
                        $existingPickup = substr((string)($existing['pickup_time'] ?? ''), 0, 5);
                        $existingPickupMinute = preg_match('/^\d{2}:\d{2}$/', $existingPickup)
                            ? ((int)substr($existingPickup, 0, 2) * 60) + (int)substr($existingPickup, 3, 2)
                            : ($existingStart + 60);
                        $existingDuration = max(60, $existingPickupMinute - $existingStart);
                    }
                    $existingEnd = $existingStart + $existingDuration;
                    if ($overlaps($newStartMinute, $newEndMinute, $existingStart, $existingEnd)) $overlapCount++;
                }

                $globalDailyLimit = 20;
                $categoryDailyLimit = $category === 'Grooming' ? 10 : ($category === 'Vet Services' ? 30 : 6);
                $capacity = $category === 'Pet Hotel' ? 6 : 2;
                $manualOccupiedGlobal = 0;
                $manualOccupiedCategory = 0;
                $manualOverlapUnits = 0;
                $manualBlockedOverlap = false;
                $manualReducedUnits = 0;
                foreach ($manualAdjustments as $adjustment) {
                    $adjustStartDate = substr((string)($adjustment['start_date'] ?? ''), 0, 10);
                    $adjustEndDate = substr((string)($adjustment['end_date'] ?? ''), 0, 10);
                    if ($adjustStartDate > $newDateInput || $adjustEndDate <= $newDateInput) continue;
                    $type = strtolower(trim((string)($adjustment['adjustment_type'] ?? '')));
                    $adjustmentCategory = trim((string)($adjustment['service_category'] ?? ''));
                    $specific = trim((string)($adjustment['specific_service'] ?? ''));
                    $quantity = max(1, (int)($adjustment['quantity'] ?? 1));
                    $startValue = trim((string)($adjustment['start_time'] ?? ''));
                    $endValue = trim((string)($adjustment['end_time'] ?? ''));
                    $adjStart = ($startValue === '' && $endValue === '') ? 0 : $parseMinute($startValue);
                    $adjEnd = ($startValue === '' && $endValue === '') ? 1440 : $parseMinute($endValue);
                    if ($adjStart === null || $adjEnd === null || !$overlaps($newStartMinute, $newEndMinute, $adjStart, $adjEnd)) continue;
                    if ($type === 'occupied') {
                        $manualOccupiedGlobal += $quantity;
                        if ($adjustmentCategory === $category) {
                            $manualOccupiedCategory += $quantity;
                            $manualOverlapUnits += $quantity;
                        }
                    } elseif ($adjustmentCategory === $category && in_array($type, ['blocked', 'capacity_reduced'], true)) {
                        if ($specific !== '' && strcasecmp($specific, $serviceSpecific) !== 0) continue;
                        if ($type === 'capacity_reduced') {
                            $manualReducedUnits += $quantity;
                        } else {
                            $manualBlockedOverlap = true;
                        }
                    }
                }

                if ($category === 'Pet Hotel') {
                    $categoryDailyLimit = 6;
                }
                if (($dailyTotal + $manualOccupiedGlobal) >= $globalDailyLimit
                    || ($categoryCount + $manualOccupiedCategory + $manualReducedUnits) >= $categoryDailyLimit) {
                    $pdo->rollBack();
                    return $fail($category . ' has reached its available daily capacity on ' . date('M d, Y', strtotime($newDateInput)) . '. Choose another date.');
                }
                if ($manualBlockedOverlap) {
                    $pdo->rollBack();
                    return $fail('The selected time is blocked by an Admin availability adjustment. Choose another time or date.');
                }
                $effectiveCapacity = max(0, $capacity - $manualReducedUnits);
                if (($overlapCount + $manualOverlapUnits) >= $effectiveCapacity) {
                    $pdo->rollBack();
                    return $fail('The selected time is already full or unavailable. Choose another time.');
                }
            }

            if ($isBoarding) {
                $updateStmt = $pdo->prepare("UPDATE public.appointments
                    SET appointment_date = :new_date,
                        appointment_time = :new_time,
                        checkout_date = CAST(:new_checkout AS date)
                    WHERE id = :id
                      AND LOWER(BTRIM(COALESCE(booking_status, ''))) IN ('confirmed', 'rescheduled')");
                $updateStmt->execute([
                    ':new_date' => $newDateInput,
                    ':new_time' => $newTime,
                    ':new_checkout' => $newCheckout,
                    ':id' => $appointmentId,
                ]);
            } else {
                $updateStmt = $pdo->prepare("UPDATE public.appointments
                    SET appointment_date = :new_date,
                        appointment_time = :new_time
                    WHERE id = :id
                      AND LOWER(BTRIM(COALESCE(booking_status, ''))) IN ('confirmed', 'rescheduled')");
                $updateStmt->execute([
                    ':new_date' => $newDateInput,
                    ':new_time' => $newTime,
                    ':id' => $appointmentId,
                ]);
            }

            if ($updateStmt->rowCount() !== 1) {
                $pdo->rollBack();
                return $fail('The booking could not be moved. Its status may have changed; refresh and try again.');
            }

            $petName = trim((string)($booking['pet_name'] ?? '')) ?: 'your pet';
            $customerId = (int)($booking['user_id'] ?? 0);
            $contactNumber = trim((string)($booking['contact_number'] ?? ''));
            $oldDateTime = ($oldDate !== '' ? date('M d, Y', strtotime($oldDate)) : 'the original date')
                . ($oldTime !== '' ? ' at ' . date('g:i A', strtotime($oldTime)) : '');
            $newDateTime = date('M d, Y', strtotime($newDateInput)) . ' at ' . date('g:i A', strtotime($newTime));
            // Keep the in-app notification within a conservative 250-character limit
            // because deployments may have a VARCHAR(255) notifications.message column.
            // Put the allowance/payment assurance before the optional reason so it remains visible.
            $message = 'Clinic moved your ' . $service . ' appointment for ' . $petName
                . ' to ' . $newDateTime
                . '. This clinic-initiated change does not use your one-time reschedule allowance; payment is unchanged. Reason: '
                . $reason . '.';
            $message = function_exists('mb_substr')
                ? mb_substr($message, 0, 250, 'UTF-8')
                : substr($message, 0, 250);

            if ($customerId > 0) {
                $readTypeStmt = $pdo->prepare("SELECT data_type FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = 'notifications' AND column_name = 'is_read' LIMIT 1");
                $readTypeStmt->execute();
                $readType = strtolower(trim((string)$readTypeStmt->fetchColumn()));
                $readValue = $readType === 'boolean' ? 'FALSE' : '0';
                $notificationStmt = $pdo->prepare("INSERT INTO public.notifications
                    (user_id, title, message, type, is_read, created_at)
                    VALUES (:user_id, :title, :message, 'booking', {$readValue}, NOW())");
                $notificationStmt->execute([
                    ':user_id' => $customerId,
                    ':title' => 'Appointment Rescheduled by Clinic',
                    ':message' => $message,
                ]);
            }

            // A staff-initiated move is also surfaced to the Admin dashboard.
            if ($role !== 'admin' && $role !== 'administrator') {
                $pdo->exec('SAVEPOINT clinic_reschedule_admin_notif');
                try {
                    $adminMessage = 'Staff clinic-rescheduled Booking #' . $appointmentId . ' (' . $service . ', ' . $petName . ') to ' . $newDateTime . '. Reason: ' . $reason;
                    $adminMessage = function_exists('mb_substr') ? mb_substr($adminMessage, 0, 250, 'UTF-8') : substr($adminMessage, 0, 250);
                    $adminNotifStmt = $pdo->prepare('INSERT INTO public.admin_notifications (message) VALUES (:message)');
                    $adminNotifStmt->execute([':message' => $adminMessage]);
                    $pdo->exec('RELEASE SAVEPOINT clinic_reschedule_admin_notif');
                } catch (Throwable $notificationError) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT clinic_reschedule_admin_notif');
                    $pdo->exec('RELEASE SAVEPOINT clinic_reschedule_admin_notif');
                    error_log('Clinic reschedule Admin notification failed for booking #' . $appointmentId . ': ' . $notificationError->getMessage());
                }
            }

            $pdo->commit();

            // SMS is best-effort after the database commit; it must not undo the move.
            if ($contactNumber !== '' && strtoupper($contactNumber) !== 'N/A') {
                try {
                    $smsFile = __DIR__ . '/iprog_sms.php';
                    if (is_file($smsFile)) {
                        require_once $smsFile;
                    }
                    if (function_exists('sendIPROGSMS')) {
                        $smsMessage = "Notice: Boogie's Pet Care has moved your {$service} appointment for {$petName} from {$oldDateTime} to {$newDateTime} due to staff/service availability. Reason: {$reason}. This clinic-initiated change does not use your one-time customer reschedule. Your payment remains unchanged.";
                        $smsResult = sendIPROGSMS($contactNumber, $smsMessage);
                        if (empty($smsResult['success'])) {
                            error_log('Clinic reschedule SMS failed for booking #' . $appointmentId . ': ' . (string)($smsResult['response'] ?? 'Unknown error'));
                        }
                    }
                } catch (Throwable $smsError) {
                    error_log('Clinic reschedule SMS exception for booking #' . $appointmentId . ': ' . $smsError->getMessage());
                }
            }

            return [
                'success' => true,
                'message' => 'Appointment rescheduled by the clinic to ' . $newDateTime . '. Customer reschedule allowance and payment were not changed.'
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Clinic-initiated reschedule failed for booking #' . $appointmentId . ': ' . $e->getMessage());
            return $fail('Unable to reschedule this booking right now. Check the PHP error log and try again.');
        }
    }
}
