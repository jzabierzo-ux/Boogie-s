<?php
session_start();
require_once '../db_connect.php';

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


/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/
if ($search !== '') {

    $search_sql = mysqli_real_escape_string(
        $conn,
        $search
    );

    $query .= "
        AND (
            u.full_name LIKE '%$search_sql%'
            OR u.email LIKE '%$search_sql%'
            OR aal.ip_address LIKE '%$search_sql%'
            OR aal.action LIKE '%$search_sql%'
            OR aal.status LIKE '%$search_sql%'
        )
    ";
}


/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/
if (
    $status_filter === 'SUCCESS' ||
    $status_filter === 'FAILED'
) {

    $status_sql = mysqli_real_escape_string(
        $conn,
        $status_filter
    );

    $query .= "
        AND aal.status = '$status_sql'
    ";
}


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/
$query .= "
    ORDER BY aal.created_at DESC, aal.id DESC
";


$result = mysqli_query($conn, $query);


/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/
$total_logs = 0;
$success_logs = 0;
$failed_logs = 0;

$count_query = mysqli_query(
    $conn,
    "
    SELECT
        COUNT(*) AS total_logs,
        SUM(status = 'SUCCESS') AS success_logs,
        SUM(status = 'FAILED') AS failed_logs
    FROM admin_account_logs
    "
);

if ($count_query) {

    $count_data = mysqli_fetch_assoc($count_query);

    $total_logs = (int)($count_data['total_logs'] ?? 0);
    $success_logs = (int)($count_data['success_logs'] ?? 0);
    $failed_logs = (int)($count_data['failed_logs'] ?? 0);
}

?>

<!DOCTYPE html>
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

        <a
            href="admindashboard.php"
            class="back-btn"
        >
            <i class="fa-solid fa-arrow-left"></i>
            Back to Dashboard
        </a>

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

            <?php if ($result && mysqli_num_rows($result) > 0): ?>

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

                        <?php while ($row = mysqli_fetch_assoc($result)): ?>

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

                        <?php endwhile; ?>

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

</body>

</html>