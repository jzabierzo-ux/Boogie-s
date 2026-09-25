<?php
session_start();
require_once '../db_supabase.php';

/*
|--------------------------------------------------------------------------
| ADMIN ONLY ACCESS
|--------------------------------------------------------------------------
*/
$current_role = strtolower(trim($_SESSION['role'] ?? ''));

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $current_role !== 'admin'
) {
    header("Location: ../admin_login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| PHILIPPINES TIMEZONE
|--------------------------------------------------------------------------
*/
date_default_timezone_set('Asia/Manila');

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');

/*
|--------------------------------------------------------------------------
| BASE QUERY
|--------------------------------------------------------------------------
*/
$query = "
    SELECT
        aal.id,
        aal.user_id,
        aal.action,
        aal.status,
        aal.ip_address,
        aal.user_agent,
        aal.created_at,
        u.full_name,
        u.email,
        u.role
    FROM admin_account_logs aal
    LEFT JOIN users u
        ON u.id = aal.user_id
    WHERE 1=1
";

$params = [];

/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/
if ($search !== '') {
    $query .= "
        AND (
            u.full_name ILIKE :search
            OR u.email ILIKE :search
            OR CAST(aal.ip_address AS TEXT) ILIKE :search
            OR aal.action ILIKE :search
            OR aal.status ILIKE :search
        )
    ";

    $params[':search'] = '%' . $search . '%';
}

/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/
if ($status_filter === 'SUCCESS' || $status_filter === 'FAILED') {
    $query .= " AND aal.status = :status";
    $params[':status'] = $status_filter;
}

/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/
$query .= "
    ORDER BY aal.created_at DESC, aal.id DESC
";

$result = $pdo->prepare($query);
$result->execute($params);
$rows = $result->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/
$total_logs = 0;
$success_logs = 0;
$failed_logs = 0;

$count_stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_logs,
        COUNT(*) FILTER (WHERE status = 'SUCCESS') AS success_logs,
        COUNT(*) FILTER (WHERE status = 'FAILED') AS failed_logs
    FROM admin_account_logs
");

$count_data = $count_stmt->fetch(PDO::FETCH_ASSOC);

if ($count_data) {
    $total_logs = (int)($count_data['total_logs'] ?? 0);
    $success_logs = (int)($count_data['success_logs'] ?? 0);
    $failed_logs = (int)($count_data['failed_logs'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| ADMIN NOTIFICATIONS
|--------------------------------------------------------------------------
*/
$admin_notifications = [];
$unread_count = 0;

try {
    $admin_notif_stmt = $pdo->prepare("
        SELECT id, message, created_at
        FROM admin_notifications
        WHERE is_read = 0
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $admin_notif_stmt->execute();
    $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread_count = count($admin_notifications);
} catch (PDOException $e) {
    $admin_notifications = [];
    $unread_count = 0;
}

?>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Account Logs | Boogie's Pet Care</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        .page {
            padding: 30px;
            max-width: 1500px;
            margin: auto;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .title-area h1 {
            margin: 0;
            font-size: 28px;
            color: #0f172a;
        }

        .title-area p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 8px;
            background: #0f172a;
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }

        .back-btn:hover {
            opacity: 0.9;
        }

        /*
        ------------------------------------------
        SUMMARY CARDS
        ------------------------------------------
        */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
            border: 1px solid #e2e8f0;
        }

        .summary-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
        }

        /*
        ------------------------------------------
        FILTER AREA
        ------------------------------------------
        */

        .filter-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-input,
        .status-select {
            padding: 11px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            background: white;
        }

        .search-input {
            flex: 1;
            min-width: 240px;
        }

        .search-input:focus,
        .status-select:focus {
            border-color: #6366f1;
        }

        .filter-btn {
            border: none;
            background: #6366f1;
            color: white;
            padding: 11px 18px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }

        .clear-btn {
            display: inline-flex;
            align-items: center;
            padding: 11px 18px;
            border-radius: 8px;
            background: #e2e8f0;
            color: #334155;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
        }

        /*
        ------------------------------------------
        TABLE
        ------------------------------------------
        */

        .table-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th {
            background: #0f172a;
            color: white;
            text-align: left;
            padding: 14px 16px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
            vertical-align: middle;
        }

        tr:hover td {
            background: #f8fafc;
        }

        .user-name {
            font-weight: 700;
            color: #0f172a;
        }

        .user-email {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        .role-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #ede9fe;
            color: #5b21b6;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .action-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 11px;
            font-weight: 700;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }

        .status-success {
            background: #dcfce7;
            color: #166534;
        }

        .status-failed {
            background: #fee2e2;
            color: #b91c1c;
        }

        .ip-address {
            font-family: Consolas, monospace;
            color: #475569;
            font-size: 12px;
        }

        .date-time {
            white-space: nowrap;
        }

        .browser-info {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #64748b;
            font-size: 11px;
        }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #64748b;
        }

        .empty-state i {
            font-size: 42px;
            margin-bottom: 12px;
            color: #94a3b8;
        }

        /* ------------------------------------------
           ADMIN NOTIFICATIONS
           ------------------------------------------ */
        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notif-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }

        .notif-bell-btn {
            width: 42px;
            height: 42px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .notif-bell-btn:hover {
            background: #f8fafc;
        }

        .notif-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: #e11d48;
            color: white;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 18px;
        }

        .notif-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 48px;
            width: 320px;
            background: white;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.12);
            border-radius: 10px;
            z-index: 2000;
            text-align: left;
            overflow: hidden;
        }

        .notif-dropdown.show {
            display: block;
        }

        .notif-header {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 800;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #0f172a;
        }

        .notif-body {
            max-height: 300px;
            overflow-y: auto;
        }

        .notif-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            color: #334155;
            line-height: 1.4;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-empty {
            padding: 20px;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
        }

        .mark-read-btn {
            font-size: 11px;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 700;
        }

        .mark-read-btn:hover {
            text-decoration: underline;
        }

        /*
        ------------------------------------------
        RESPONSIVE
        ------------------------------------------
        */

        @media (max-width: 900px) {

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .page {
                padding: 20px;
            }

        }

    </style>

</head>

<body>

<div class="page">

    <!-- HEADER -->
    <div class="top-bar">

        <div class="title-area">

            <h1>
                <i class="fa-solid fa-clock-rotate-left"></i>
                Account Logs
            </h1>

            <p>
                Monitor administrator login activity and access attempts.
            </p>

        </div>

        <div class="top-actions">

            <div class="notif-wrapper" onclick="toggleNotif(event)">
                <button
                    type="button"
                    class="notif-bell-btn"
                    aria-label="Notifications"
                >
                    <i class="fa-solid fa-bell"></i>

                    <span
                        id="admin-notif-badge"
                        class="notif-badge"
                        style="display: <?php echo $unread_count > 0 ? 'inline-flex' : 'none'; ?>;"
                    >
                        <?php echo $unread_count; ?>
                    </span>
                </button>

                <div
                    class="notif-dropdown"
                    id="notifBox"
                    onclick="event.stopPropagation()"
                >
                    <div class="notif-header">
                        Alerts

                        <a
                            href="mark_notifications_read.php"
                            id="mark-read-link"
                            class="mark-read-btn"
                            style="display: <?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;"
                        >
                            Mark all read
                        </a>
                    </div>

                    <div
                        class="notif-body"
                        id="admin-notif-list"
                    >
                        <?php if ($unread_count > 0): ?>

                            <?php foreach ($admin_notifications as $notif): ?>

                                <div class="notif-item">
                                    <i
                                        class="fa-solid fa-circle-exclamation"
                                        style="color: #e11d48; margin-right: 5px;"
                                    ></i>

                                    <?php
                                    echo htmlspecialchars(
                                        (string)($notif['message'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>

                                    <br>

                                    <small style="color:#94a3b8;font-size:11px;">
                                        <?php
                                        echo !empty($notif['created_at'])
                                            ? date(
                                                'M d, g:i A',
                                                strtotime(
                                                    (string)$notif['created_at']
                                                )
                                            )
                                            : '';
                                        ?>
                                    </small>
                                </div>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <div class="notif-empty">
                                No new notifications.
                            </div>

                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <a
                href="admindashboard.php"
                class="back-btn"
            >
                <i class="fa-solid fa-arrow-left"></i>
                Back to Dashboard
            </a>

        </div>

    </div>


    <!-- SUMMARY -->
    <div class="summary-grid">

        <div class="summary-card">

            <div class="summary-label">
                TOTAL LOGS
            </div>

            <div class="summary-number">
                <?php echo number_format($total_logs); ?>
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-label">
                SUCCESSFUL LOGINS
            </div>

            <div class="summary-number">
                <?php echo number_format($success_logs); ?>
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-label">
                FAILED ATTEMPTS
            </div>

            <div class="summary-number">
                <?php echo number_format($failed_logs); ?>
            </div>

        </div>

    </div>


    <!-- FILTER -->
    <div class="filter-card">

        <form
            method="GET"
            class="filter-form"
        >

            <input
                type="text"
                name="search"
                class="search-input"
                placeholder="Search name, email, IP, action..."
                value="<?php echo htmlspecialchars($search); ?>"
            >


            <select
                name="status"
                class="status-select"
            >

                <option value="all"
                    <?php echo $status_filter === 'all' ? 'selected' : ''; ?>
                >
                    All Status
                </option>

                <option value="SUCCESS"
                    <?php echo $status_filter === 'SUCCESS' ? 'selected' : ''; ?>
                >
                    Successful
                </option>

                <option value="FAILED"
                    <?php echo $status_filter === 'FAILED' ? 'selected' : ''; ?>
                >
                    Failed
                </option>

            </select>


            <button
                type="submit"
                class="filter-btn"
            >
                <i class="fa-solid fa-magnifying-glass"></i>
                Search
            </button>


            <a
                href="?"
                class="clear-btn"
                title="Clear search and status filters"
            >
                <i class="fa-solid fa-rotate-left"></i>
                Clear
            </a>

        </form>

    </div>


    <!-- TABLE -->
    <div class="table-card">

        <div class="table-wrapper">

            <?php if (!empty($rows)): ?>

                <table>

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>Admin Account</th>

                            <th>Role</th>

                            <th>Action</th>

                            <th>Status</th>

                            <th>IP Address</th>

                            <th>Date & Time</th>

                            <th>Browser</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($rows as $row): ?>

                            <tr>

                                <td>
                                    <?php echo (int)$row['id']; ?>
                                </td>


                                <td>

                                    <?php if (!empty($row['full_name'])): ?>

                                        <div class="user-name">
                                            <?php
                                            echo htmlspecialchars(
                                                $row['full_name']
                                            );
                                            ?>
                                        </div>

                                        <div class="user-email">
                                            <?php
                                            echo htmlspecialchars(
                                                $row['email'] ?? ''
                                            );
                                            ?>
                                        </div>

                                    <?php else: ?>

                                        <div class="user-name">
                                            Unknown Account
                                        </div>

                                        <div class="user-email">
                                            Unrecognized login attempt
                                        </div>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if (!empty($row['role'])): ?>

                                        <span class="role-badge">
                                            <?php
                                            echo htmlspecialchars(
                                                $row['role']
                                            );
                                            ?>
                                        </span>

                                    <?php else: ?>

                                        <span style="color:#94a3b8;">
                                            N/A
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <span class="action-badge">
                                        <?php
                                        echo htmlspecialchars(
                                            $row['action']
                                        );
                                        ?>
                                    </span>

                                </td>


                                <td>

                                    <?php if ($row['status'] === 'SUCCESS'): ?>

                                        <span
                                            class="status-badge status-success"
                                        >
                                            <i class="fa-solid fa-circle-check"></i>
                                            SUCCESS
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="status-badge status-failed"
                                        >
                                            <i class="fa-solid fa-circle-xmark"></i>
                                            FAILED
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <span class="ip-address">

                                        <?php
                                        echo htmlspecialchars(
                                            $row['ip_address'] ?? 'UNKNOWN'
                                        );
                                        ?>

                                    </span>

                                </td>


                                <td class="date-time">

                                    <?php
                                    echo date(
                                        'M d, Y h:i A',
                                        strtotime($row['created_at'])
                                    );
                                    ?>

                                </td>


                                <td>

                                    <div
                                        class="browser-info"
                                        title="<?php
                                            echo htmlspecialchars(
                                                $row['user_agent'] ?? ''
                                            );
                                        ?>"
                                    >

                                        <?php
                                        echo htmlspecialchars(
                                            $row['user_agent'] ?? 'Unknown'
                                        );
                                        ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php else: ?>

                <div class="empty-state">

                    <i class="fa-solid fa-clock-rotate-left"></i>

                    <h3>No Account Logs Found</h3>

                    <p>
                        No administrator login activity has been recorded yet.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<script>
(function () {

    function renderAdminNotifications(data) {
        const badge = document.getElementById('admin-notif-badge');
        const notifList = document.getElementById('admin-notif-list');
        const markReadBtn = document.getElementById('mark-read-link');

        if (!badge || !notifList) return;

        const unread = Number(
            data && data.unread ? data.unread : 0
        );

        badge.style.display = unread > 0
            ? 'inline-flex'
            : 'none';

        badge.textContent = unread;

        if (markReadBtn) {
            markReadBtn.style.display = unread > 0
                ? 'inline-block'
                : 'none';
        }

        notifList.innerHTML =
            (data && data.html)
                ? data.html
                : '<div class="notif-empty">No new notifications.</div>';
    }

    function fetchAdminNotifs() {
        fetch('get_admin_notifs.php', {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin'
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error(
                    'Notification request failed: HTTP ' +
                    response.status
                );
            }

            return response.json();
        })
        .then(renderAdminNotifications)
        .catch(function (error) {
            console.error(
                'Error fetching admin notifications:',
                error
            );
        });
    }

    function toggleNotif(event) {
        event.stopPropagation();

        const notifBox =
            document.getElementById('notifBox');

        if (notifBox) {
            notifBox.classList.toggle('show');
        }
    }

    window.toggleNotif = toggleNotif;

    document.addEventListener(
        'click',
        function (event) {
            if (!event.target.closest('.notif-wrapper')) {
                const notifBox =
                    document.getElementById('notifBox');

                if (
                    notifBox &&
                    notifBox.classList.contains('show')
                ) {
                    notifBox.classList.remove('show');
                }
            }
        }
    );

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            fetchAdminNotifs();
            setInterval(fetchAdminNotifs, 3000);
        }
    );

})();
</script>

</body>

</html>