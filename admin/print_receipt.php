<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
date_default_timezone_set('Asia/Manila');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

// Payment receipts are restricted to a verified Admin portal session.
$rawRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$currentRole = in_array($rawRole, ['admin', 'administrator'], true) ? 'admin' : $rawRole;
$sessionUserId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$isAdminSession = (
    ($_SESSION['logged_in'] ?? false) === true
    && ($_SESSION['admin_logged_in'] ?? false) === true
    && ($_SESSION['staff_logged_in'] ?? false) !== true
    && $currentRole === 'admin'
    && $sessionUserId !== false
    && $sessionUserId !== null
);

if (!$isAdminSession) {
    if (($_SESSION['logged_in'] ?? false) !== true) {
        header('Location: admin_login.php');
        exit();
    }
    http_response_code(403);
    exit('Access denied. Admin only.');
}

require_once __DIR__ . '/../db_supabase.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

// Revalidate the role against the current database record. Keep the original
// users.role value in the session; this page does not rewrite session roles.
try {
    $roleStmt = $pdo->prepare('SELECT role FROM public.users WHERE id = :user_id LIMIT 1');
    $roleStmt->execute([':user_id' => (int)$sessionUserId]);
    $databaseRole = strtolower(trim((string)$roleStmt->fetchColumn()));
    if (!in_array($databaseRole, ['admin', 'administrator'], true)) {
        http_response_code(403);
        exit('Access denied. Current Admin authorization is required.');
    }
} catch (Throwable $e) {
    error_log('Print receipt Admin role verification failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to verify Admin access right now. Please try again.');
}

$rawAppointmentId = $_GET['id'] ?? null;
$appointmentId = is_scalar($rawAppointmentId)
    ? filter_var((string)$rawAppointmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($appointmentId === false || $appointmentId === null) {
    http_response_code(400);
    exit('Invalid transaction number.');
}

function receiptEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function receiptMoney($value): string
{
    return '₱' . number_format((float)$value, 2);
}

function receiptDateTime($value, string $format = 'M d, Y g:i A'): string
{
    if ($value === null || trim((string)$value) === '') {
        return 'Not recorded';
    }

    try {
        return (new DateTimeImmutable((string)$value, new DateTimeZone('Asia/Manila')))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format($format);
    } catch (Throwable $e) {
        return 'Not recorded';
    }
}

function receiptColumnExists(array $columns, string $name): bool
{
    return isset($columns[$name]);
}

try {
    $columnsStmt = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'appointments'"
    );
    $columnsStmt->execute();
    $appointmentColumns = array_fill_keys($columnsStmt->fetchAll(PDO::FETCH_COLUMN), true);

    if (!isset($appointmentColumns['payment_status'])) {
        throw new RuntimeException('The appointment payment status field is unavailable.');
    }

    $serviceFeeExists = receiptColumnExists($appointmentColumns, 'service_fee');
    $totalPriceExists = receiptColumnExists($appointmentColumns, 'total_price');
    if ($serviceFeeExists && $totalPriceExists) {
        // Each group appointment row stores the price for that individual pet.
        $subtotalExpr = 'COALESCE(a.service_fee, 0)';
        $totalExpr = 'COALESCE(a.total_price, a.service_fee, 0)';
    } elseif ($totalPriceExists) {
        $subtotalExpr = 'COALESCE(a.total_price, 0)';
        $totalExpr = 'COALESCE(a.total_price, 0)';
    } elseif ($serviceFeeExists) {
        $subtotalExpr = 'COALESCE(a.service_fee, 0)';
        $totalExpr = 'COALESCE(a.service_fee, 0)';
    } else {
        throw new RuntimeException('No supported appointment amount field is available.');
    }

    $discountRecorded = false;
    if (isset($appointmentColumns['discount_amount'])) {
        $discountExpr = 'a.discount_amount';
        $discountRecorded = true;
    } elseif (isset($appointmentColumns['promo_discount_amount'])) {
        $discountExpr = 'a.promo_discount_amount';
        $discountRecorded = true;
    } else {
        $discountExpr = 'NULL::numeric';
    }

    $taxRecorded = false;
    if (isset($appointmentColumns['tax_amount'])) {
        $taxExpr = 'a.tax_amount';
        $taxRecorded = true;
    } elseif (isset($appointmentColumns['vat_amount'])) {
        $taxExpr = 'a.vat_amount';
        $taxRecorded = true;
    } else {
        $taxExpr = 'NULL::numeric';
    }

    $optionalExpressions = [
        'appointment_date' => isset($appointmentColumns['appointment_date']) ? 'a.appointment_date' : 'NULL::date',
        'appointment_time' => isset($appointmentColumns['appointment_time']) ? 'a.appointment_time' : 'NULL::time',
        'service' => isset($appointmentColumns['service']) ? 'a.service' : "'Service not recorded'::text",
        'appointment_type' => isset($appointmentColumns['appointment_type']) ? 'a.appointment_type' : (isset($appointmentColumns['booking_type']) ? 'a.booking_type' : "NULL::text"),
        'payment_method' => isset($appointmentColumns['payment_method']) ? 'a.payment_method' : "NULL::text",
        'gcash_ref' => isset($appointmentColumns['gcash_ref']) ? 'a.gcash_ref' : "NULL::text",
        'checkout_date' => isset($appointmentColumns['checkout_date']) ? 'a.checkout_date' : 'NULL::date',
        'pickup_time' => isset($appointmentColumns['pickup_time']) ? 'a.pickup_time' : 'NULL::time',
        'discount_percent' => isset($appointmentColumns['discount_percent']) ? 'COALESCE(a.discount_percent, 0)' : '0::numeric',
        'promo_title' => isset($appointmentColumns['promo_title']) ? 'a.promo_title' : 'NULL::text',
        'booking_group_id' => isset($appointmentColumns['booking_group_id']) ? 'a.booking_group_id' : 'NULL::text',
        'user_id' => isset($appointmentColumns['user_id']) ? 'a.user_id' : 'NULL::bigint',
    ];

    // Prefer an actual payment/verification timestamp, then the record creation
    // timestamp. Fall back to the appointment date when the schema has no such field.
    $transactionTimestampCandidates = [];
    foreach (['payment_verified_at', 'paid_at', 'payment_confirmed_at', 'created_at'] as $candidate) {
        if (isset($appointmentColumns[$candidate])) {
            $transactionTimestampCandidates[] = 'a.' . $candidate;
        }
    }
    $transactionTimestampExpr = $transactionTimestampCandidates
        ? 'COALESCE(' . implode(', ', $transactionTimestampCandidates) . ')'
        : $optionalExpressions['appointment_date'];

    $petColumnsStmt = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'pets'"
    );
    $petColumnsStmt->execute();
    $petColumns = array_fill_keys($petColumnsStmt->fetchAll(PDO::FETCH_COLUMN), true);

    $userColumnsStmt = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'users'"
    );
    $userColumnsStmt->execute();
    $userColumns = array_fill_keys($userColumnsStmt->fetchAll(PDO::FETCH_COLUMN), true);

    $petNameExpr = isset($petColumns['name']) ? 'p.name' : 'NULL::text';
    $petOwnerNameExpr = isset($petColumns['owner_name']) ? 'p.owner_name' : 'NULL::text';
    $customerNameExpr = isset($userColumns['full_name']) ? 'u.full_name' : 'NULL::text';
    $hasPetTable = $petNameExpr !== 'NULL::text' || $petOwnerNameExpr !== 'NULL::text';
    $hasUsersTable = $customerNameExpr !== 'NULL::text';

    $joins = '';
    if ($hasPetTable && isset($appointmentColumns['pet_id']) && isset($petColumns['id'])) {
        $joins .= ' LEFT JOIN public.pets p ON p.id = a.pet_id';
    } else {
        $petNameExpr = 'NULL::text';
        $petOwnerNameExpr = 'NULL::text';
    }
    if ($hasUsersTable && isset($appointmentColumns['user_id']) && isset($userColumns['id'])) {
        $joins .= ' LEFT JOIN public.users u ON u.id = a.user_id';
    } else {
        $customerNameExpr = 'NULL::text';
    }

    // Shared SELECT keeps the single-booking and group-booking receipt fields consistent.
    $selectSql = "SELECT
                    a.id,
                    a.payment_status,
                    {$subtotalExpr} AS subtotal,
                    {$discountExpr} AS discount_amount,
                    {$optionalExpressions['discount_percent']} AS discount_percent,
                    {$optionalExpressions['promo_title']} AS promo_title,
                    {$taxExpr} AS tax_amount,
                    {$totalExpr} AS total_paid,
                    {$optionalExpressions['appointment_date']} AS appointment_date,
                    {$optionalExpressions['appointment_time']} AS appointment_time,
                    {$optionalExpressions['service']} AS service,
                    {$optionalExpressions['appointment_type']} AS appointment_type,
                    {$optionalExpressions['payment_method']} AS payment_method,
                    {$optionalExpressions['gcash_ref']} AS gcash_ref,
                    {$optionalExpressions['checkout_date']} AS checkout_date,
                    {$optionalExpressions['pickup_time']} AS pickup_time,
                    {$transactionTimestampExpr} AS transaction_datetime,
                    {$optionalExpressions['booking_group_id']} AS booking_group_id,
                    {$optionalExpressions['user_id']} AS appointment_user_id,
                    {$petNameExpr} AS pet_name,
                    {$petOwnerNameExpr} AS pet_owner_name,
                    {$customerNameExpr} AS customer_name
                FROM public.appointments a
                {$joins}";

    $stmt = $pdo->prepare($selectSql . ' WHERE a.id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$appointmentId]);
    $selectedReceipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$selectedReceipt) {
        http_response_code(404);
        exit('Transaction not found.');
    }

    $groupId = trim((string)($selectedReceipt['booking_group_id'] ?? ''));
    $isGroupReceipt = false;
    $receiptItems = [$selectedReceipt];
    $receiptTransactionIds = [(int)$selectedReceipt['id']];

    if ($groupId !== '' && isset($appointmentColumns['booking_group_id'])) {
        // Group bookings are paid as one transaction, so include every appointment
        // row attached to the same group instead of printing only the clicked pet.
        $groupStmt = $pdo->prepare(
            $selectSql . ' WHERE a.booking_group_id = :group_id ORDER BY a.appointment_time ASC NULLS LAST, a.id ASC'
        );
        $groupStmt->execute([':group_id' => $groupId]);
        $groupRows = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

        // A valid Pet Group contains 2-20 individual pet appointment rows. Do not
        // silently produce a partial receipt if its linked rows are missing.
        if (count($groupRows) < 2 || count($groupRows) > 20) {
            http_response_code(409);
            exit('The Pet Group receipt is incomplete or has an invalid number of pets. Please check the group appointments.');
        }

        $selectedUserId = $selectedReceipt['appointment_user_id'] ?? null;
        foreach ($groupRows as $groupRow) {
            if (strtolower(trim((string)($groupRow['payment_status'] ?? ''))) !== 'paid') {
                http_response_code(403);
                exit('A Pet Group receipt can only be printed when all group appointments are marked Paid.');
            }
            if ($selectedUserId !== null && $groupRow['appointment_user_id'] !== null
                && (string)$groupRow['appointment_user_id'] !== (string)$selectedUserId) {
                http_response_code(409);
                exit('The Pet Group appointments do not belong to the same customer.');
            }
        }

        $isGroupReceipt = true;
        $receiptItems = $groupRows;
        $receiptTransactionIds = array_map(static function (array $row): int {
            return (int)$row['id'];
        }, $groupRows);
        $receipt = $groupRows[0];

        $receipt['subtotal'] = array_sum(array_map(static function (array $row): float {
            return (float)($row['subtotal'] ?? 0);
        }, $groupRows));
        $receipt['total_paid'] = array_sum(array_map(static function (array $row): float {
            return (float)($row['total_paid'] ?? 0);
        }, $groupRows));
        $receipt['discount_amount'] = $discountRecorded
            ? array_sum(array_map(static function (array $row): float {
                return (float)($row['discount_amount'] ?? 0);
            }, $groupRows))
            : null;

        $taxValuesComplete = $taxRecorded;
        $taxTotal = 0.0;
        foreach ($groupRows as $groupRow) {
            if (!isset($groupRow['tax_amount']) || $groupRow['tax_amount'] === '') {
                $taxValuesComplete = false;
                break;
            }
            $taxTotal += (float)$groupRow['tax_amount'];
        }
        $receipt['tax_amount'] = $taxValuesComplete ? $taxTotal : null;

        $discountPercentValues = array_values(array_unique(array_map(static function (array $row): string {
            return number_format((float)($row['discount_percent'] ?? 0), 4, '.', '');
        }, $groupRows)));
        $receipt['discount_percent'] = count($discountPercentValues) === 1
            ? (float)$discountPercentValues[0]
            : 0;

        $promoTitles = [];
        foreach ($groupRows as $groupRow) {
            $promo = trim((string)($groupRow['promo_title'] ?? ''));
            if ($promo !== '' && !in_array($promo, $promoTitles, true)) {
                $promoTitles[] = $promo;
            }
        }
        $receipt['promo_title'] = implode(', ', $promoTitles);

        // Use a recorded shared payment method/reference even when the first row is blank.
        foreach (['payment_method', 'gcash_ref', 'transaction_datetime', 'customer_name', 'pet_owner_name'] as $field) {
            if (trim((string)($receipt[$field] ?? '')) !== '') {
                continue;
            }
            foreach ($groupRows as $groupRow) {
                if (trim((string)($groupRow[$field] ?? '')) !== '') {
                    $receipt[$field] = $groupRow[$field];
                    break;
                }
            }
        }
        $receipt['appointment_time'] = null;
        $receipt['pet_name'] = count($groupRows) . ' pets';
    } else {
        $receipt = $selectedReceipt;
    }

    $paymentStatus = strtolower(trim((string)($receipt['payment_status'] ?? '')));
    if ($paymentStatus !== 'paid') {
        http_response_code(403);
        exit('A payment receipt can only be printed after the transaction is marked Paid.');
    }

    $customerName = trim((string)($receipt['customer_name'] ?? ''));
    if ($customerName === '') {
        $customerName = trim((string)($receipt['pet_owner_name'] ?? ''));
    }
    $customerName = preg_replace('/\s*\(Walk-in\)\s*$/i', '', $customerName) ?: $customerName;
    if ($customerName === '') {
        $customerName = 'Walk-in Guest';
    }

    $bookingType = $isGroupReceipt
        ? 'Pet Group Booking'
        : (trim((string)($receipt['appointment_type'] ?? '')) ?: 'Not recorded');
    $paymentMethod = trim((string)($receipt['payment_method'] ?? '')) ?: 'Not recorded';
    $paymentReference = trim((string)($receipt['gcash_ref'] ?? ''));
    if ($isGroupReceipt) {
        $groupReceiptSuffix = strtoupper(substr(str_replace('-', '', $groupId), 0, 8));
        $receiptNumber = 'BPCS-GRP-' . $groupReceiptSuffix;
    } else {
        $receiptNumber = 'BPCS-' . str_pad((string)$receipt['id'], 8, '0', STR_PAD_LEFT);
    }
} catch (Throwable $e) {
    error_log('Print receipt error for appointment #' . (int)$appointmentId . ': ' . $e->getMessage());
    http_response_code(500);
    exit('The transaction receipt could not be loaded. Please check the database and try again.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt <?php echo receiptEscape($receiptNumber); ?> | Boogie's Pet Care &amp; Services</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px 12px; background: #eef2f7; color: #172033; font-family: Arial, Helvetica, sans-serif; }
        .toolbar { width: 80mm; max-width: 100%; margin: 0 auto 12px; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 11px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .print-button { background: #001f3f; color: #fff; }
        .close-button { background: #e2e8f0; color: #1e293b; }
        .receipt { width: 80mm; max-width: 100%; margin: 0 auto; padding: 6mm 5mm; background: #fff; border: 1px solid #dbe3ed; box-shadow: 0 8px 24px rgba(15, 23, 42, .08); }
        .brand { text-align: center; border-bottom: 2px solid #f4c400; padding-bottom: 3mm; margin-bottom: 3mm; }
        .brand h1 { margin: 0 0 2px; color: #001f3f; font-size: 15px; line-height: 1.25; letter-spacing: .1px; }
        .brand p { margin: 2px 0; color: #64748b; font-size: 9.5px; line-height: 1.3; }
        .receipt-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 5px; margin-bottom: 3mm; }
        .receipt-title h2 { margin: 0; color: #001f3f; font-size: 13.5px; line-height: 1.25; }
        .payment-status { margin-top: 3px; color: #059669; font-size: 9px; font-weight: 800; letter-spacing: .3px; }
        .receipt-number { min-width: 0; text-align: right; color: #475569; font-size: 8.5px; line-height: 1.4; overflow-wrap: anywhere; }
        .details { display: grid; grid-template-columns: 1fr; gap: 0; margin-bottom: 3mm; }
        .detail { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; padding: 1.8mm 0; border-bottom: 1px solid #e8edf3; }
        .detail .label { flex: 0 0 37%; color: #64748b; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .2px; }
        .detail .value { flex: 1 1 auto; min-width: 0; color: #172033; font-size: 10px; font-weight: 700; text-align: right; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        th { padding: 2.2mm 1.5mm; background: #f1f5f9; color: #475569; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .2px; }
        td { padding: 2.5mm 1.5mm; border-bottom: 1px solid #e8edf3; font-size: 9.5px; line-height: 1.35; }
        .amount { text-align: right; white-space: nowrap; }
        .totals { width: 100%; margin: 3mm 0 0 auto; }
        .totals-row { display: flex; justify-content: space-between; gap: 10px; padding: 1.5mm 0; color: #475569; font-size: 10px; }
        .totals-row strong { color: #172033; text-align: right; }
        .grand-total { border-top: 1.5px solid #001f3f; margin-top: 1mm; padding-top: 2.5mm; font-size: 12px; font-weight: 800; color: #001f3f; }
        .notice { margin-top: 3mm; padding: 2mm; border-radius: 4px; background: #f8fafc; color: #64748b; font-size: 8px; line-height: 1.4; }
        .thank-you { margin-top: 3mm; text-align: center; color: #001f3f; font-weight: 800; font-size: 9.5px; line-height: 1.35; }
        .receipt-footer { margin-top: 1.5mm; text-align: center; color: #94a3b8; font-size: 8px; line-height: 1.35; }
        @media print {
            @page { size: 80mm 180mm; margin: 3mm; }
            html, body { width: 74mm !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
            .toolbar { display: none !important; }
            .receipt { width: 74mm !important; max-width: none !important; margin: 0 !important; padding: 0 !important; border: 0 !important; box-shadow: none !important; break-inside: avoid; page-break-inside: avoid; }
            .brand, .details, table, .totals, .notice { break-inside: avoid; page-break-inside: avoid; }
            .notice { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            /* Long Pet Group receipts may continue onto additional thermal-paper pages. */
            .group-receipt .receipt { break-inside: auto !important; page-break-inside: auto !important; }
            .group-receipt table { break-inside: auto !important; page-break-inside: auto !important; }
            .group-receipt thead { display: table-header-group; }
            .group-receipt tbody tr { break-inside: avoid; page-break-inside: avoid; }
            .group-receipt .brand,
            .group-receipt .details,
            .group-receipt .totals,
            .group-receipt .notice,
            .group-receipt .thank-you,
            .group-receipt .receipt-footer { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body class="<?php echo $isGroupReceipt ? 'group-receipt' : 'single-receipt'; ?>">
    <div class="toolbar">
        <button class="print-button" type="button" onclick="window.print()"><span aria-hidden="true">🖨</span> Print Receipt</button>
        <button class="close-button" type="button" onclick="window.close()">Close</button>
    </div>
    <main class="receipt">
        <header class="brand">
            <h1>BOOGIE'S PET CARE &amp; SERVICES</h1>
            <p>Dasmariñas Branch</p>
            <p>Pet Grooming • Veterinary Services • Pet Hotel</p>
        </header>

        <section class="receipt-title">
            <div>
                <h2>PAYMENT RECEIPT</h2>
                <div class="payment-status">PAYMENT STATUS: PAID</div>
            </div>
            <div class="receipt-number">
                <div><strong>Receipt No.:</strong> <?php echo receiptEscape($receiptNumber); ?></div>
                <div><strong>Printed:</strong> <?php echo date('M d, Y g:i A'); ?></div>
            </div>
        </section>

        <section class="details" aria-label="Transaction details">
            <div class="detail"><span class="label">Customer</span><span class="value"><?php echo receiptEscape($customerName); ?></span></div>
            <div class="detail"><span class="label"><?php echo $isGroupReceipt ? 'Pets' : 'Pet'; ?></span><span class="value"><?php echo $isGroupReceipt ? count($receiptItems) . ' pets (see itemized schedule below)' : (receiptEscape($receipt['pet_name'] ?? 'N/A') ?: 'N/A'); ?></span></div>
            <div class="detail"><span class="label">Booking Type</span><span class="value"><?php echo receiptEscape($bookingType); ?></span></div>
            <div class="detail"><span class="label">Payment Method</span><span class="value"><?php echo receiptEscape($paymentMethod); ?></span></div>
            <div class="detail"><span class="label">Transaction Date</span><span class="value"><?php echo receiptDateTime($receipt['transaction_datetime'] ?? null); ?></span></div>
            <div class="detail"><span class="label">Appointment Date</span><span class="value"><?php echo receiptDateTime($receipt['appointment_date'] ?? null, 'M d, Y'); ?></span></div>
            <div class="detail"><span class="label">Appointment Time</span><span class="value"><?php echo $isGroupReceipt ? 'Multiple times (see schedule below)' : receiptDateTime($receipt['appointment_time'] ?? null, 'g:i A'); ?></span></div>
            <?php if (!empty($receipt['checkout_date'])): ?>
                <div class="detail"><span class="label">Checkout Date</span><span class="value"><?php echo receiptDateTime($receipt['checkout_date'], 'M d, Y'); ?></span></div>
            <?php endif; ?>
            <?php if (!empty($receipt['pickup_time'])): ?>
                <div class="detail"><span class="label">Pickup Time</span><span class="value"><?php echo receiptDateTime($receipt['pickup_time'], 'g:i A'); ?></span></div>
            <?php endif; ?>
            <?php if ($paymentReference !== ''): ?>
                <div class="detail"><span class="label">GCash Reference</span><span class="value"><?php echo receiptEscape($paymentReference); ?></span></div>
            <?php endif; ?>
        </section>

        <table aria-label="Paid services">
            <thead><tr><th><?php echo $isGroupReceipt ? 'Pet / Service / Schedule' : 'Service Description'; ?></th><th class="amount">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($receiptItems as $item): ?>
                    <tr>
                        <td>
                            <?php if ($isGroupReceipt): ?>
                                <strong><?php echo receiptEscape(trim((string)($item['pet_name'] ?? '')) ?: ('Pet #' . (int)$item['id'])); ?></strong><br>
                                <?php echo receiptEscape($item['service'] ?? 'Service'); ?><br>
                                <span><?php echo receiptDateTime($item['appointment_date'] ?? null, 'M d, Y'); ?> · <?php echo receiptDateTime($item['appointment_time'] ?? null, 'g:i A'); ?></span>
                            <?php else: ?>
                                <strong><?php echo receiptEscape($item['service'] ?? 'Service'); ?></strong>
                            <?php endif; ?>
                        </td>
                        <td class="amount"><?php echo receiptMoney($item['subtotal'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <section class="totals" aria-label="Payment summary">
            <div class="totals-row"><span>Service Subtotal</span><strong><?php echo receiptMoney($receipt['subtotal'] ?? 0); ?></strong></div>
            <div class="totals-row">
                <span>Discount<?php if ((float)($receipt['discount_percent'] ?? 0) > 0): ?> (<?php echo number_format((float)$receipt['discount_percent'], 2); ?>%)<?php endif; ?></span>
                <strong><?php echo $discountRecorded ? (((float)($receipt['discount_amount'] ?? 0) > 0) ? '-' . receiptMoney($receipt['discount_amount']) : receiptMoney(0)) : 'Not recorded'; ?></strong>
            </div>
            <?php if (!empty($receipt['promo_title'])): ?><div class="totals-row"><span>Promotion</span><strong><?php echo receiptEscape($receipt['promo_title']); ?></strong></div><?php endif; ?>
            <div class="totals-row"><span>Tax / VAT</span><strong><?php echo ($taxRecorded && isset($receipt['tax_amount']) && $receipt['tax_amount'] !== null && $receipt['tax_amount'] !== '') ? receiptMoney($receipt['tax_amount']) : 'Not itemized'; ?></strong></div>
            <div class="totals-row grand-total"><span>TOTAL PAID</span><span><?php echo receiptMoney($receipt['total_paid'] ?? 0); ?></span></div>
        </section>

        <div class="notice">System-generated transaction receipt. Tax / VAT is displayed only when separately recorded; no tax rate has been assumed.</div>
        <div class="thank-you">Thank you for choosing Boogie's Pet Care &amp; Services!</div>
        <div class="receipt-footer">Transaction ID<?php echo count($receiptTransactionIds) > 1 ? 's' : ''; ?>: <?php echo receiptEscape(implode(', ', $receiptTransactionIds)); ?> · Keep this receipt for your records.</div>
    </main>
</body>
</html>
