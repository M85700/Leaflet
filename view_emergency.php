<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = $_SESSION['user'];
$emergency_id = $_GET['id'] ?? 0;

// Get emergency details
$stmt = $conn->prepare("SELECT e.*, 
    s.name AS sector_name,
    et.name AS type_name,
    creator.full_name as creator_name
    FROM emergencies e
    LEFT JOIN sectors s ON e.sector_id = s.id
    LEFT JOIN emergency_types et ON e.emergency_type_id = et.id
    LEFT JOIN users creator ON e.created_by = creator.id
    WHERE e.id = :id");
$stmt->execute([':id' => $emergency_id]);
$emergency = $stmt->fetch();

if (!$emergency) {
    header('Location: emergencies.php');
    exit;
}

// Check sector access permissions
// Super admin, admin, and inspection have access to all emergencies
if (!in_array($user['role'], ['super_admin', 'admin', 'inspection'])) {
    $allowed_sectors = [];
    
    // Get user's allowed sectors
    if (in_array($user['role'], ['sector_manager', 'supervisor'])) {
        $allowed_sectors = getUserSectorIds($user['id']);
    } elseif (!empty($user['sector_id'])) {
        $allowed_sectors = [$user['sector_id']];
    }
    
    // For supervisor, also allow if they created or are assigned to this emergency
    $has_access = false;
    if ($user['role'] === 'supervisor') {
        $has_access = ($emergency['created_by'] == $user['id']) || 
                     ($emergency['assigned_to'] == $user['id']) ||
                     (!empty($allowed_sectors) && in_array($emergency['sector_id'], $allowed_sectors));
    } else {
        // sector_manager: check sector access only
        // SECURITY: Block if no sectors assigned OR sector mismatch
        $has_access = !empty($allowed_sectors) && in_array($emergency['sector_id'], $allowed_sectors);
    }
    
    if (!$has_access) {
        $_SESSION['error'] = 'You do not have permission to view this emergency';
        header('Location: emergencies.php');
        exit;
    }
}

// Auto-transition removed - execution starts manually via "Start Execution" button in execute_emergency.php

// Calculate Response Time and Execution Time
$response_time = null;
$execution_time = null;

if ($emergency['execution_started_at']) {
    // Response Time: created_at → execution_started_at
    $created = new DateTime($emergency['created_at']);
    $started = new DateTime($emergency['execution_started_at']);
    $response_interval = $created->diff($started);
    
    $response_time = '';
    if ($response_interval->d > 0) $response_time .= $response_interval->d . 'd ';
    if ($response_interval->h > 0) $response_time .= $response_interval->h . 'h ';
    if ($response_interval->i > 0) $response_time .= $response_interval->i . 'm';
    if ($response_time === '') $response_time = '<1m';
    
    // Execution Time: execution_started_at → updated_at (for completed emergencies)
    if ($emergency['status'] === 'completed') {
        $updated = new DateTime($emergency['updated_at']);
        $execution_interval = $started->diff($updated);
        
        $execution_time = '';
        if ($execution_interval->d > 0) $execution_time .= $execution_interval->d . 'd ';
        if ($execution_interval->h > 0) $execution_time .= $execution_interval->h . 'h ';
        if ($execution_interval->i > 0) $execution_time .= $execution_interval->i . 'm';
        if ($execution_time === '') $execution_time = '<1m';
    }
}

// Get execution summary
$summary_stmt = $conn->prepare("SELECT * FROM execution_summary WHERE emergency_id = :id LIMIT 1");
$summary_stmt->execute([':id' => $emergency_id]);
$execution_summary = $summary_stmt->fetch();

// Calculate summaries using aggregation functions
$workforce_summary = calculateWorkforceSummary($emergency_id);
$equipment_summary = calculateEquipmentSummary($emergency_id);
$materials_summary = calculateMaterialsSummary($emergency_id);
$photo_counts = calculatePhotoCounts($emergency_id);

// Get detailed data
$detailed_workforce = getDetailedWorkforce($emergency_id);
$detailed_equipment = getDetailedEquipment($emergency_id);
$detailed_materials = getDetailedMaterials($emergency_id);

// Get photos
$photos_stmt = $conn->prepare("SELECT * FROM execution_photos WHERE emergency_id = :id ORDER BY category, id");
$photos_stmt->execute([':id' => $emergency_id]);
$photos = $photos_stmt->fetchAll();

$page_title = 'Emergency Report - ' . $emergency['emergency_code'];
include 'includes/header.php';
?>

<style>
/* Print-friendly A4 Format Styles */
@media print {
    @page {
        size: A4 portrait;
        margin: 15mm;
    }
    
    body {
        font-size: 11pt;
        line-height: 1.4;
    }
    
    .sidebar, .page-header, .action-buttons, .no-print {
        display: none !important;
    }
    
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
        max-width: 100% !important;
    }
    
    .gov-report {
        box-shadow: none !important;
        border: 1px solid #000 !important;
        page-break-after: always;
    }
    
    table {
        page-break-inside: avoid;
        border-collapse: collapse !important;
        width: 100%;
    }
    
    table th, table td {
        border: 1px solid #000 !important;
        padding: 6px !important;
    }
    
    .photo-grid img {
        max-width: 100px;
        max-height: 100px;
    }
}

/* Government Report Styles */
.gov-report {
    background: white;
    padding: 2.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}

.gov-report-header {
    text-align: center;
    border-bottom: 3px solid #1e293b;
    padding-bottom: 1.5rem;
    margin-bottom: 2rem;
}

.gov-section-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: #1e293b;
    margin: 2rem 0 1rem 0;
    padding: 0.5rem 0.75rem;
    background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
    color: white;
    border-radius: 4px;
}

.gov-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.gov-info-item {
    display: flex;
    padding: 0.75rem;
    background: #f8fafc;
    border-radius: 4px;
    border-left: 3px solid #2563eb;
}

.gov-info-label {
    font-weight: 700;
    color: #475569;
    min-width: 140px;
}

.gov-info-value {
    color: #1e293b;
    flex: 1;
}

/* Summary Tables */
.summary-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.summary-table thead {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    color: white;
}

.summary-table th {
    padding: 0.75rem;
    text-align: left;
    font-weight: 600;
    border: 1px solid #475569;
}

.summary-table td {
    padding: 0.65rem 0.75rem;
    border: 1px solid #e2e8f0;
    background: white;
}

.summary-table tbody tr:nth-child(even) {
    background: #f8fafc;
}

.summary-table tbody tr:hover {
    background: #e0f2fe;
}

.summary-table tfoot {
    background: #f1f5f9;
    font-weight: 700;
}

.summary-table tfoot td {
    border-top: 2px solid #1e293b;
    padding: 0.75rem;
}

.overtime-highlight {
    background: #fee2e2 !important;
    color: #991b1b;
    font-weight: 600;
}

.detail-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
}

.detail-table th {
    background: #f1f5f9;
    padding: 0.5rem;
    text-align: left;
    border: 1px solid #cbd5e1;
    font-weight: 600;
}

.detail-table td {
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
}

.photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.photo-item {
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
}

.photo-item img {
    width: 100%;
    height: 150px;
    object-fit: cover;
}

@media (max-width: 768px) {
    .gov-info-grid {
        grid-template-columns: 1fr;
    }
    
    .summary-table {
        font-size: 0.85rem;
    }
    
    .summary-table th,
    .summary-table td {
        padding: 0.5rem;
    }
}
</style>

<div class="dashboard-wrapper page-with-toggle">
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Sidebar Toggle Button -->
    <button class="sidebar-toggle-btn" id="sidebarToggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header no-print">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-file-alt"></i>
                    Emergency Execution Report
                </h1>
                <p class="page-subtitle">Report Code: <?php echo htmlspecialchars($emergency['emergency_code']); ?></p>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center;">
                <?php 
                // PROTECTION: Show appropriate buttons based on emergency status and user role
                $is_completed = ($emergency['status'] === 'completed');
                $can_modify = ($user['role'] === 'super_admin' || !$is_completed);
                ?>
                
                <a href="emergencies.php" class="btn btn-secondary no-print">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                
                <!-- Execution Page Button - Always visible, but text changes for completed -->
                <?php if ($is_completed && !$can_modify): ?>
                <a href="execute_emergency.php?id=<?php echo $emergency_id; ?>" class="btn btn-secondary no-print">
                    <i class="fas fa-eye"></i> View Execution (Read-Only)
                </a>
                <?php else: ?>
                <a href="execute_emergency.php?id=<?php echo $emergency_id; ?>" class="btn btn-danger no-print">
                    <i class="fas fa-clipboard-list"></i> Execution Page
                </a>
                <?php endif; ?>
                
                <!-- Edit Button - Only for admin/super_admin and not completed (except super_admin) -->
                <?php if (in_array($user['role'], ['super_admin', 'admin']) && $can_modify): ?>
                <a href="edit_emergency.php?id=<?php echo $emergency_id; ?>" class="btn btn-secondary no-print">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <?php endif; ?>
                
                <button onclick="window.print()" class="btn btn-primary no-print">
                    <i class="fas fa-print"></i> Print A4
                </button>
            </div>
        </div>

        <!-- FORMAL GOVERNMENT REPORT -->
        <div class="gov-report">
            <!-- Report Header -->
            <div class="gov-report-header">
                <div style="display: flex; align-items: center; justify-content: center; gap: 1rem; margin-bottom: 1rem;">
                    <i class="fas fa-building" style="font-size: 2.5rem; color: #2563eb;"></i>
                    <div>
                        <h1 style="margin: 0; font-size: 1.5rem; color: #1e293b;">PUBLIC SERVICES DEPARTMENT</h1>
                        <p style="margin: 0; color: #64748b;">Ras Al Khaimah - United Arab Emirates</p>
                    </div>
                </div>
                <h2 style="font-size: 1.75rem; font-weight: 700; color: #1e293b; margin: 0.5rem 0;">
                    EMERGENCY EXECUTION REPORT
                </h2>
                <p style="font-size: 1rem; color: #64748b; margin: 0;">
                    Official Documentation & Analysis
                </p>
            </div>

            <!-- Section 1: Emergency Information -->
            <h3 class="gov-section-title">1. EMERGENCY INFORMATION</h3>
            <div class="gov-info-grid">
                <div class="gov-info-item">
                    <span class="gov-info-label">Emergency Code:</span>
                    <span class="gov-info-value" style="color: #ef4444; font-weight: 700;"><?php echo htmlspecialchars($emergency['emergency_code']); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Date Reported:</span>
                    <span class="gov-info-value"><?php echo formatDate($emergency['created_at']); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Emergency Type:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($emergency['type_name'] ?? $emergency['emergency_type'] ?? 'N/A'); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Severity Level:</span>
                    <span class="gov-info-value"><?php echo getSeverityBadge($emergency['severity_level'] ?? 'medium'); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Status:</span>
                    <span class="gov-info-value"><?php echo getStatusBadge($emergency['status']); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Sector:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($emergency['sector_name'] ?? 'N/A'); ?></span>
                </div>
                <?php if ($response_time): ?>
                <div class="gov-info-item">
                    <span class="gov-info-label">Response Time:</span>
                    <span class="gov-info-value" style="color: #2563eb; font-weight: 600;">
                        <i class="fas fa-clock"></i> <?php echo $response_time; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($execution_time): ?>
                <div class="gov-info-item">
                    <span class="gov-info-label">Execution Time:</span>
                    <span class="gov-info-value" style="color: #10b981; font-weight: 600;">
                        <i class="fas fa-hourglass-half"></i> <?php echo $execution_time; ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="gov-info-item" style="grid-column: 1 / -1;">
                    <span class="gov-info-label">Location:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($emergency['location_address'] ?? 'N/A'); ?></span>
                </div>
            </div>

            <!-- Section 2: Execution Summary -->
            <?php if ($execution_summary): ?>
            <h3 class="gov-section-title">2. EXECUTION SUMMARY</h3>
            <div class="gov-info-grid">
                <div class="gov-info-item">
                    <span class="gov-info-label">Start Date:</span>
                    <span class="gov-info-value"><?php echo isset($emergency['execution_started_at']) && $emergency['execution_started_at'] ? formatDate($emergency['execution_started_at']) : 'N/A'; ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">End Date:</span>
                    <span class="gov-info-value"><?php echo isset($emergency['execution_ended_at']) && $emergency['execution_ended_at'] ? formatDate($emergency['execution_ended_at']) : 'N/A'; ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Executor Name:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($execution_summary['executor_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Total Work Hours:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($execution_summary['total_hours'] ?? '0'); ?> hours</span>
                </div>
                <div class="gov-info-item" style="grid-column: 1 / -1;">
                    <span class="gov-info-label">Work Description:</span>
                    <span class="gov-info-value"><?php echo nl2br(htmlspecialchars($execution_summary['work_description'] ?? 'N/A')); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section 3: Employees Summary (Aggregated) -->
            <?php if (!empty($workforce_summary)): ?>
            <h3 class="gov-section-title">3. EMPLOYEES SUMMARY (Aggregated by Employee ID)</h3>
            <div style="overflow-x: auto; width: 100%;">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Employee ID</th>
                        <th style="width: 35%;">Job Title</th>
                        <th style="width: 20%;">Work Days</th>
                        <th style="width: 20%;">Total Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_hours = 0;
                    foreach ($workforce_summary as $worker): 
                        $total_hours += $worker['total_hours'];
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($worker['employee_id']); ?></strong></td>
                        <td><?php echo htmlspecialchars($worker['job_title']); ?></td>
                        <td style="text-align: center;"><?php echo $worker['work_days']; ?></td>
                        <td style="text-align: center;"><strong><?php echo number_format($worker['total_hours'], 1); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right;">TOTAL:</td>
                        <td style="text-align: center;"><strong><?php echo number_format($total_hours, 1); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php endif; ?>

            <!-- Section 4: Equipment Summary (Aggregated) -->
            <?php if (!empty($equipment_summary)): ?>
            <h3 class="gov-section-title">4. EQUIPMENT SUMMARY (Aggregated by Type & Unit)</h3>
            <div style="overflow-x: auto; width: 100%;">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th style="width: 40%;">Equipment Type</th>
                        <th style="width: 20%;">Unit</th>
                        <th style="width: 20%;">Total Quantity</th>
                        <th style="width: 20%;">Usage Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($equipment_summary as $equip): 
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($equip['equipment_type']); ?></strong></td>
                        <td><?php echo htmlspecialchars($equip['unit']); ?></td>
                        <td style="text-align: center;"><strong><?php echo number_format($equip['total_quantity'], 1); ?></strong></td>
                        <td style="text-align: center;"><?php echo $equip['usage_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <!-- Section 5: Materials Summary (Aggregated) -->
            <?php if (!empty($materials_summary)): ?>
            <h3 class="gov-section-title">5. MATERIALS SUMMARY (Aggregated by Type & Unit)</h3>
            <div style="overflow-x: auto; width: 100%;">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th style="width: 40%;">Material Type</th>
                        <th style="width: 20%;">Unit</th>
                        <th style="width: 20%;">Total Quantity</th>
                        <th style="width: 20%;">Usage Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($materials_summary as $mat): 
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($mat['material_type']); ?></strong></td>
                        <td><?php echo htmlspecialchars($mat['unit']); ?></td>
                        <td style="text-align: center;"><strong><?php echo number_format($mat['total_quantity'], 1); ?></strong></td>
                        <td style="text-align: center;"><?php echo $mat['usage_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <!-- Section 6: Photo Summary -->
            <?php if (array_sum($photo_counts) > 0): ?>
            <h3 class="gov-section-title">6. PHOTOGRAPHIC DOCUMENTATION SUMMARY</h3>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                <div style="background: #ecfdf5; padding: 1rem; border-radius: 6px; border-left: 4px solid #10b981; text-align: center;">
                    <div style="font-size: 0.875rem; color: #064e3b; font-weight: 600;">Before Execution</div>
                    <div style="font-size: 2rem; color: #059669; font-weight: 700;"><?php echo $photo_counts['before']; ?></div>
                    <div style="font-size: 0.75rem; color: #047857;">photos</div>
                </div>
                <div style="background: #fef3c7; padding: 1rem; border-radius: 6px; border-left: 4px solid #f59e0b; text-align: center;">
                    <div style="font-size: 0.875rem; color: #78350f; font-weight: 600;">During Execution</div>
                    <div style="font-size: 2rem; color: #d97706; font-weight: 700;"><?php echo $photo_counts['during']; ?></div>
                    <div style="font-size: 0.75rem; color: #b45309;">photos</div>
                </div>
                <div style="background: #dbeafe; padding: 1rem; border-radius: 6px; border-left: 4px solid #2563eb; text-align: center;">
                    <div style="font-size: 0.875rem; color: #1e3a8a; font-weight: 600;">After Execution</div>
                    <div style="font-size: 2rem; color: #1d4ed8; font-weight: 700;"><?php echo $photo_counts['after']; ?></div>
                    <div style="font-size: 0.75rem; color: #1e40af;">photos</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Separator -->
            <div style="border-top: 3px double #cbd5e1; margin: 3rem 0;"></div>

            <!-- Section 7: Detailed Employees Data -->
            <?php if (!empty($detailed_workforce)): ?>
            <h3 class="gov-section-title">7. DETAILED EMPLOYEES DATA (Chronological)</h3>
            <div style="overflow-x: auto; width: 100%;">
            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employee ID</th>
                        <th>Job Title</th>
                        <th style="text-align: center;">Hours Worked</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailed_workforce as $worker): ?>
                    <tr>
                        <td><?php echo formatDate($worker['created_at']); ?></td>
                        <td><strong><?php echo htmlspecialchars($worker['employee_id']); ?></strong></td>
                        <td><?php echo htmlspecialchars($worker['job_title']); ?></td>
                        <td style="text-align: center;"><?php echo number_format($worker['hours'], 1); ?></td>
                        <td><?php echo htmlspecialchars($worker['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <!-- Section 8: Detailed Equipment Data -->
            <?php if (!empty($detailed_equipment)): ?>
            <h3 class="gov-section-title">8. DETAILED EQUIPMENT DATA (Chronological)</h3>
            <div style="overflow-x: auto; width: 100%;">
            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Equipment Type</th>
                        <th>Code</th>
                        <th>Number</th>
                        <th style="text-align: center;">Quantity</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailed_equipment as $equip): ?>
                    <tr>
                        <td><?php echo formatDate($equip['created_at']); ?></td>
                        <td><strong><?php echo htmlspecialchars($equip['equipment_type']); ?></strong></td>
                        <td><?php echo htmlspecialchars($equip['equipment_code'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($equip['equipment_number'] ?? '-'); ?></td>
                        <td style="text-align: center;"><?php echo number_format($equip['quantity'], 1); ?></td>
                        <td><?php echo htmlspecialchars($equip['unit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <!-- Section 9: Detailed Materials Data -->
            <?php if (!empty($detailed_materials)): ?>
            <h3 class="gov-section-title">9. DETAILED MATERIALS DATA (Chronological)</h3>
            <div style="overflow-x: auto; width: 100%;">
            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Material Type</th>
                        <th style="text-align: center;">Quantity</th>
                        <th>Unit</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailed_materials as $mat): ?>
                    <tr>
                        <td><?php echo formatDate($mat['created_at']); ?></td>
                        <td><strong><?php echo htmlspecialchars($mat['material_type']); ?></strong></td>
                        <td style="text-align: center;"><?php echo number_format($mat['quantity'], 1); ?></td>
                        <td><?php echo htmlspecialchars($mat['unit']); ?></td>
                        <td><?php echo htmlspecialchars($mat['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <!-- Section 10: Photo Documentation -->
            <?php if (!empty($photos)): ?>
            <h3 class="gov-section-title">10. PHOTOGRAPHIC DOCUMENTATION (Visual Evidence)</h3>
            <?php
            $categories = ['before' => 'Before Execution', 'during' => 'During Execution', 'after' => 'After Execution'];
            foreach ($categories as $cat_key => $cat_label):
                $cat_photos = array_filter($photos, function($p) use ($cat_key) { return $p['category'] === $cat_key; });
                if (!empty($cat_photos)):
            ?>
            <h4 style="font-size: 1rem; font-weight: 600; color: #475569; margin: 1rem 0 0.75rem 0;">
                <i class="fas fa-camera"></i> <?php echo $cat_label; ?>
            </h4>
            <div class="photo-grid">
                <?php foreach ($cat_photos as $photo): ?>
                <div class="photo-item">
                    <img src="<?php echo htmlspecialchars($photo['photo_path']); ?>" 
                         alt="<?php echo $cat_label; ?> photo">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; endforeach; ?>
            <?php endif; ?>

            <!-- Completion Status -->
            <?php if ($emergency['status'] === 'completed'): ?>
            <h3 class="gov-section-title">11. COMPLETION STATUS</h3>
            <div style="background: #ecfdf5; padding: 1.5rem; border-radius: 6px; border-left: 4px solid #10b981; text-align: center;">
                <i class="fas fa-check-circle" style="font-size: 3rem; color: #10b981;"></i>
                <p style="margin: 0.5rem 0 0 0; color: #065f46; font-weight: 600; font-size: 1.125rem;">EXECUTION COMPLETED</p>
                <p style="margin: 0.25rem 0 0 0; color: #047857; font-size: 0.875rem;">Emergency marked as completed on <?php echo formatDate($emergency['updated_at'] ?? $emergency['created_at']); ?></p>
            </div>
            <?php endif; ?>

            <!-- Report Footer -->
            <div style="margin-top: 3rem; padding-top: 2rem; border-top: 2px solid #e2e8f0; text-align: center; color: #64748b; font-size: 0.875rem;">
                <p style="margin: 0;"><i class="fas fa-shield-alt"></i> <strong>Official Document</strong></p>
                <p style="margin: 0.25rem 0 0 0;">Public Services Department - Emergency Management System</p>
                <p style="margin: 0.25rem 0 0 0;">Generated on: <?php echo date('F d, Y h:i A'); ?></p>
                <p style="margin: 0.5rem 0 0 0; font-size: 0.75rem;">Report ID: <?php echo htmlspecialchars($emergency['emergency_code']); ?></p>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<!-- Auto-refresh script for real-time updates -->
<script>
// Auto-refresh when execution data is updated
let lastCheckTime = Date.now();

function checkForUpdates() {
    fetch('ajax/check_execution_updates.php?id=<?php echo $emergency_id; ?>&last_check=' + lastCheckTime)
        .then(response => response.json())
        .then(data => {
            if (data.has_updates) {
                // Reload page to show new data
                location.reload();
            }
            lastCheckTime = Date.now();
        })
        .catch(error => console.error('Update check failed:', error));
}

// Check for updates every 30 seconds
setInterval(checkForUpdates, 30000);
</script>
