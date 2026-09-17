<?php
session_start();
include '../db_connect.php'; 

// --- 1. SECURITY CHECK: ALLOW ADMIN, SUPERVISOR, AND STAFF ---
$is_admin_or_supervisor = isset($_SESSION['logged_in']) && in_array($_SESSION['role'], ['admin', 'supervisor', 'staff']);
$is_staff = isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;

if (!$is_admin_or_supervisor && !$is_staff) {
    header("Location: stafflogin.php");
    exit;
}

// --- 2. DYNAMIC BACK BUTTON ---
$back_link = $is_admin_or_supervisor ? "managepet.php" : "pets.php";

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Pet ID.");
}

$pet_id = $_GET['id'];

// --- 3. FETCH PET DATA ---
$query = "SELECT * FROM pets WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $pet_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$row = mysqli_fetch_assoc($result)) {
    die("Pet not found in the database.");
}

// Map the data
$p_name = $row['name'] ?? 'Unknown';
$p_type = $row['pet_type'] ?? 'Unknown';
$p_breed = $row['breed'] ?? 'Unknown';
$p_gender = $row['gender'] ?? 'Unknown';
$p_age = $row['age'] ?? 'Unknown';
$p_weight = $row['weight'] ?? 'Unknown';
$o_name = $row['owner_name'] ?? 'Unknown';
$med_history = $row['medical_history'] ?? 'No medical history recorded.';
$special_needs = $row['special_needs'] ?? 'No special care instructions provided.';
$p_status = $row['status'] ?? 'Pending';

// --- 4. FETCH APPOINTMENT & TRANSACTION HISTORY ---
// Dito natin kukunin yung schedule at gagawan natin ng Transaction ID
$appt_query = "SELECT * FROM appointments WHERE pet_id = ? ORDER BY appointment_date DESC, appointment_time DESC";
$stmt_appt = mysqli_prepare($conn, $appt_query);
mysqli_stmt_bind_param($stmt_appt, "i", $pet_id);
mysqli_stmt_execute($stmt_appt);
$appt_result = mysqli_stmt_get_result($stmt_appt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Pet Record | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-dark: #001f3f; 
            --brand-yellow: #ffcc00; 
            --bg-light: #f4f7f6; 
            --white: #ffffff; 
            --text-main: #2d3436;
            --text-muted: #64748b; 
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif;}
        body { background: var(--bg-light); color: var(--text-main); padding: 40px; }

        .container { max-width: 1000px; margin: 0 auto; }
        
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        
        .btn-back { background: var(--white); color: var(--navy-dark); padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.05); font-size: 14px;}
        .btn-back:hover { background: var(--navy-dark); color: var(--brand-yellow); transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,31,63,0.15);}

        .card { background: var(--white); border-radius: 16px; border: none; padding: 35px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); margin-bottom: 25px; border-top: 5px solid var(--navy-dark);}
        
        .pet-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f8fafc; padding-bottom: 20px; margin-bottom: 25px; }
        .pet-title h1 { font-size: 28px; font-weight: 800; display: flex; align-items: center; gap: 15px; margin-bottom: 5px; color: var(--navy-dark);}
        .pet-title p { color: var(--text-muted); font-size: 15px; font-weight: 500;}
        
        .status-badge { background: #f1f5f9; color: var(--navy-dark); padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; border: 1px solid var(--border); display: flex; align-items: center; gap: 8px;}
        .status-badge i { color: var(--brand-yellow); font-size: 16px;}

        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 35px; }
        .info-item { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid var(--border); border-left: 4px solid var(--brand-yellow); transition: 0.2s; }
        .info-item:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.05); }
        .info-item span { display: block; font-size: 11px; text-transform: uppercase; font-weight: 800; color: var(--text-muted); margin-bottom: 5px; letter-spacing: 0.5px;}
        .info-item strong { font-size: 18px; color: var(--navy-dark); font-weight: 700;}

        .section-title { font-size: 18px; font-weight: 800; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; color: var(--navy-dark);}
        
        .text-box { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 25px; color: var(--text-main); font-size: 14px; line-height: 1.6; min-height: 100px; margin-bottom: 35px; font-weight: 500; border-left: 4px solid var(--navy-dark);}

        /* --- TRANSACTION HISTORY TABLE STYLES --- */
        .table-responsive { overflow-x: auto; background: var(--white); border: 1px solid var(--border); border-radius: 12px; }
        .history-table { width: 100%; border-collapse: collapse; text-align: left; }
        .history-table th { background: #f8fafc; padding: 15px; font-size: 12px; text-transform: uppercase; color: var(--text-muted); border-bottom: 2px solid var(--border); font-weight: 700; letter-spacing: 0.5px;}
        .history-table td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; font-weight: 500;}
        .history-table tr:hover td { background-color: #f8fafc; }
        .history-table tr:last-child td { border-bottom: none; }
        
        .trn-id { font-family: monospace; font-weight: 800; color: #0284c7; background: #e0f2fe; padding: 4px 8px; border-radius: 6px; font-size: 12px; letter-spacing: 0.5px;}
        
        .status-pill { padding: 6px 14px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05);}
        .status-completed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-confirmed { background: #dbeafe; color: #1e40af; }
        .status-no-show { background: #ffedd5; color: #ea580c; border: 1px solid #fdba74;}

        @media (max-width: 900px) {
            .info-grid { grid-template-columns: repeat(2, 1fr); }
            .pet-header { flex-direction: column; gap: 15px; }
        }
        @media (max-width: 600px) {
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header-actions">
            <a href="<?php echo $back_link; ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Directory</a>
        </div>

        <div class="card">
            <div class="pet-header">
                <div class="pet-title">
                    <h1>
                        <div style="background: #f1f5f9; width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-paw" style="color: var(--navy-dark); font-size: 24px;"></i>
                        </div>
                        <?php echo htmlspecialchars($p_name); ?>
                    </h1>
                    <p style="margin-left: 60px;"><?php echo htmlspecialchars($p_breed); ?> &bull; <?php echo htmlspecialchars($p_type); ?></p>
                </div>
                <div class="status-badge"><i class="fas fa-user-check"></i> Owner: <?php echo htmlspecialchars($o_name); ?></div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <span>Gender</span>
                    <strong><?php echo htmlspecialchars($p_gender); ?></strong>
                </div>
                <div class="info-item">
                    <span>Age</span>
                    <strong><?php echo htmlspecialchars($p_age); ?> yrs</strong>
                </div>
                <div class="info-item">
                    <span>Weight</span>
                    <strong><?php echo htmlspecialchars($p_weight); ?></strong>
                </div>
                
            </div>

            <h3 class="section-title"><i class="fas fa-notes-medical" style="color: #ef4444;"></i> Medical History / Alerts</h3>
            <div class="text-box">
                <?php echo nl2br(htmlspecialchars($med_history)); ?>
            </div>

            <h3 class="section-title"><i class="fas fa-clipboard-list" style="color: var(--navy-dark);"></i> Special Needs / Care Instructions</h3>
            <div class="text-box" style="border-left-color: var(--brand-yellow);">
                <?php echo nl2br(htmlspecialchars($special_needs)); ?>
            </div>

            <h3 class="section-title"><i class="fas fa-history" style="color: var(--navy-dark);"></i> Transaction & Schedule History</h3>
            <div class="table-responsive">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>Schedule (Date & Time)</th>
                            <th>Service Type</th>
                            <th>Service Fee</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($appt_result) > 0): ?>
                            <?php while ($appt = mysqli_fetch_assoc($appt_result)): 
                                // Gagawa tayo ng Unique Transaction ID gamit ang Year-Month at ID ng booking
                                $date_part = date('Ym', strtotime($appt['appointment_date']));
                                $trn_id = "TRN-" . $date_part . "-" . str_pad($appt['id'], 4, '0', STR_PAD_LEFT);
                                
                                // Format Date and Time
                                $sched_date = date('M d, Y', strtotime($appt['appointment_date']));
                                $sched_time = isset($appt['appointment_time']) && !empty($appt['appointment_time']) ? date('g:i A', strtotime($appt['appointment_time'])) : '';
                                
                                // Presyo (Ginamit ang in-update nating service_fee column)
                                $fee = isset($appt['service_fee']) && $appt['service_fee'] > 0 ? "₱" . number_format($appt['service_fee'], 2) : "TBD";
                                
                                // Status
                                $status = $appt['booking_status'] ?? 'Pending';
                                $status_class = str_replace(' ', '-', strtolower($status));
                            ?>
                                <tr>
                                    <td><span class="trn-id"><?php echo $trn_id; ?></span></td>
                                    <td>
                                        <strong style="color: var(--navy-dark);"><?php echo $sched_date; ?></strong><br>
                                        <?php if($sched_time): ?>
                                            <small style="color: var(--text-muted); font-weight: 600;"><i class="far fa-clock"></i> <?php echo $sched_time; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($appt['service']); ?></td>
                                    <td style="font-weight: 800; color: #10b981; font-size: 15px;"><?php echo $fee; ?></td>
                                    <td><span class="status-pill status-<?php echo $status_class; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #94a3b8; padding: 60px 30px;">
                                    <i class="fas fa-calendar-times" style="font-size: 40px; margin-bottom: 15px; opacity: 0.3;"></i><br>
                                    <span style="font-weight: 500;">No appointment or transaction history found for this pet.</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</body>
</html>