<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = $_SESSION['user'];

// Permission check: Executors can execute emergencies
// super_admin, admin: All sectors
// sector_manager, supervisor: Their sectors only
// inspection: View only (no execution)
if (!in_array($user['role'], ['super_admin', 'admin', 'sector_manager', 'supervisor'])) {
    header('Location: emergencies.php');
    exit;
}

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

// Check sector access permissions for execution
// Super admin and admin have access to all sectors
if (!in_array($user['role'], ['super_admin', 'admin'])) {
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
        $has_access = !empty($allowed_sectors) && in_array($emergency['sector_id'], $allowed_sectors);
    }
    
    if (!$has_access) {
        $_SESSION['error'] = 'You do not have permission to execute this emergency';
        header('Location: emergencies.php');
        exit;
    }
}

// PROTECTION: Completed emergencies are read-only (except for super_admin)
$is_completed = ($emergency['status'] === 'completed');
$is_readonly = ($is_completed && $user['role'] !== 'super_admin');

// Note: Page remains accessible for viewing, but will be in read-only mode

// Get execution data
$workforce_stmt = $conn->prepare("SELECT * FROM execution_workforce WHERE emergency_id = :id ORDER BY id");
$workforce_stmt->execute([':id' => $emergency_id]);
$workforce = $workforce_stmt->fetchAll();

$equipment_stmt = $conn->prepare("SELECT * FROM execution_equipment WHERE emergency_id = :id ORDER BY id");
$equipment_stmt->execute([':id' => $emergency_id]);
$equipment = $equipment_stmt->fetchAll();

$materials_stmt = $conn->prepare("SELECT * FROM execution_materials WHERE emergency_id = :id ORDER BY id");
$materials_stmt->execute([':id' => $emergency_id]);
$materials = $materials_stmt->fetchAll();

$photos_stmt = $conn->prepare("SELECT * FROM execution_photos WHERE emergency_id = :id ORDER BY category, id");
$photos_stmt->execute([':id' => $emergency_id]);
$photos = $photos_stmt->fetchAll();

// Get execution summary
$summary_stmt = $conn->prepare("SELECT * FROM execution_summary WHERE emergency_id = :id LIMIT 1");
$summary_stmt->execute([':id' => $emergency_id]);
$execution_summary = $summary_stmt->fetch();

// Get list of users for delegation 
// Delegation permissions:
// super_admin & admin: Can delegate to anyone
// sector_manager: Can delegate to users in their responsible sectors
// supervisor: Cannot delegate (must execute directly)
$can_delegate = in_array($user['role'], ['super_admin', 'admin', 'sector_manager']);
$sector_users = [];

if ($can_delegate) {
    if (in_array($user['role'], ['super_admin', 'admin'])) {
        // Admin & super_admin see all users across all sectors
        $sector_users_stmt = $conn->prepare("SELECT id, full_name, role, sector_id FROM users 
                                            WHERE is_active = 1 
                                            AND id != :current_user_id
                                            ORDER BY full_name");
        $sector_users_stmt->execute([':current_user_id' => $user['id']]);
        $sector_users = $sector_users_stmt->fetchAll();
    } 
    elseif ($user['role'] === 'sector_manager') {
        // Sector manager sees users in their responsible sectors (from user_sectors table)
        $sector_ids = getUserSectorIds($user['id']);
        if (!empty($sector_ids)) {
            $placeholders = implode(',', array_fill(0, count($sector_ids), '?'));
            
            // Find users where:
            // 1. sector_id is in the manager's sectors (for single-sector users)
            // 2. OR user_sectors contains any of the manager's sectors (for multi-sector users)
            $sql = "SELECT DISTINCT u.id, u.full_name, u.role, u.sector_id 
                    FROM users u
                    WHERE u.is_active = 1 
                    AND u.id != ?
                    AND (
                        u.sector_id IN ($placeholders)
                        OR EXISTS (
                            SELECT 1 
                            FROM user_sectors us
                            WHERE us.user_id = u.id 
                            AND us.sector_id IN ($placeholders)
                        )
                    )
                    ORDER BY u.full_name";
            
            $params = array_merge([$user['id']], $sector_ids, $sector_ids);
            $sector_users_stmt = $conn->prepare($sql);
            $sector_users_stmt->execute($params);
            $sector_users = $sector_users_stmt->fetchAll();
        }
    }
}

$page_title = 'Emergency Execution';

// Enable maps (Leaflet)
$use_maps = true;

include 'includes/header.php';
?>

<style>
/* Formal Government Report Styles */
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

.gov-report-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0.5rem 0;
}

.gov-report-subtitle {
    font-size: 1.125rem;
    color: #64748b;
    margin: 0;
}

.gov-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.gov-info-item {
    display: flex;
    align-items: baseline;
    padding: 0.75rem;
    background: #f8fafc;
    border-radius: 6px;
}

.gov-info-label {
    font-weight: 700;
    color: #475569;
    min-width: 140px;
    flex-shrink: 0;
}

.gov-info-value {
    color: #1e293b;
    flex: 1;
}

.gov-section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 2rem 0 1rem 0;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e2e8f0;
}

.execution-form-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}


@media print {
    .execution-form-section,
    .sidebar,
    .hamburger-menu,
    .btn {
        display: none !important;
    }
    
    .gov-report {
        box-shadow: none;
        border: 1px solid #000;
    }
}

@media (max-width: 768px) {
    .gov-info-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
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
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-clipboard-list"></i>
                    Emergency Execution Report <?php if ($is_readonly): ?><span style="color: #f59e0b;">(Read-Only)</span><?php endif; ?>
                </h1>
                <p class="page-subtitle">Emergency Code: <?php echo htmlspecialchars($emergency['emergency_code']); ?></p>
            </div>
            <div style="display: flex; gap: var(--spacing-md); align-items: center;">
                <?php echo getStatusBadge($emergency['status']); ?>
                <?php echo getSeverityBadge($emergency['severity_level'] ?? 'medium'); ?>
                <a href="emergencies.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
        
        <?php if ($is_readonly): ?>
        <div class="alert alert-warning" style="margin-bottom: 2rem;">
            <i class="fas fa-lock"></i> <strong>Read-Only Mode:</strong> This emergency is completed and locked. 
            You can view all execution data but cannot make any modifications. Only Super Admin can edit completed reports.
        </div>
        <?php endif; ?>

        <!-- Formal Government Report Section -->
        <div class="gov-report">
            <div class="gov-report-header">
                <div style="display: flex; align-items: center; justify-content: center; gap: 1rem; margin-bottom: 1rem;">
                    <i class="fas fa-building" style="font-size: 2.5rem; color: #2563eb;"></i>
                    <div>
                        <h1 style="margin: 0; font-size: 1.5rem; color: #1e293b;">PUBLIC SERVICES DEPARTMENT</h1>
                        <p style="margin: 0; color: #64748b;">Ras Al Khaimah</p>
                    </div>
                </div>
                <h2 class="gov-report-title">EMERGENCY EXECUTION REPORT</h2>
                <p class="gov-report-subtitle">Official Documentation</p>
            </div>

            <!-- Emergency Basic Information -->
            <h3 class="gov-section-title">1. Emergency Information</h3>
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
                    <span class="gov-info-label">Sector:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($emergency['sector_name']); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Reported By:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($emergency['creator_name']); ?></span>
                </div>
            </div>

            <div style="margin: 1.5rem 0;">
                <div class="gov-info-label" style="margin-bottom: 0.5rem;">Title:</div>
                <div style="padding: 1rem; background: #f8fafc; border-radius: 6px; font-size: 1.125rem; font-weight: 600;">
                    <?php echo htmlspecialchars($emergency['title']); ?>
                </div>
            </div>

            <div style="margin: 1.5rem 0;">
                <div class="gov-info-label" style="margin-bottom: 0.5rem;">Description:</div>
                <div style="padding: 1rem; background: #f8fafc; border-radius: 6px; line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($emergency['description'])); ?>
                </div>
            </div>

            <!-- Location Information -->
            <h3 class="gov-section-title">2. Location Details</h3>
            <div class="gov-info-grid">
                <div class="gov-info-item">
                    <span class="gov-info-label">Address:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($emergency['address'] ?? 'N/A'); ?></span>
                </div>
                <div class="gov-info-item">
                    <span class="gov-info-label">Coordinates:</span>
                    <span class="gov-info-value">
                        <?php if ($emergency['latitude'] && $emergency['longitude']): ?>
                            <?php echo $emergency['latitude']; ?>, <?php echo $emergency['longitude']; ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Location Map -->
            <?php if ($emergency['latitude'] && $emergency['longitude']): ?>
            <div style="margin-top: 1.5rem;">
                <div id="executionMap" style="height: 350px; border-radius: 8px; overflow: hidden; border: 2px solid #e2e8f0;"></div>
            </div>
            <?php endif; ?>

            <!-- Execution Summary (if exists) -->
            <?php if ($execution_summary): ?>
            <h3 class="gov-section-title">3. Execution Summary</h3>
            <div class="gov-info-grid">
                <div class="gov-info-item">
                    <span class="gov-info-label">Executor Name:</span>
                    <span class="gov-info-value"><?php echo htmlspecialchars($execution_summary['executor_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="gov-info-item" style="grid-column: 1/-1;">
                    <span class="gov-info-label">Work Description:</span>
                    <span class="gov-info-value"><?php echo nl2br(htmlspecialchars($execution_summary['work_description'] ?? 'N/A')); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Employee Details -->
            <?php if (!empty($workforce)): ?>
            <h3 class="gov-section-title">4. Employees Deployed</h3>
            <div class="table-responsive">
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Job Title</th>
                            <th>Employee ID</th>
                            <th>Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workforce as $worker): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($worker['job_title']); ?></td>
                            <td><?php echo htmlspecialchars($worker['employee_id']); ?></td>
                            <td><?php echo htmlspecialchars($worker['hours'] ?? '0'); ?> hours</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Equipment Used -->
            <?php if (!empty($equipment)): ?>
            <h3 class="gov-section-title">5. Equipment & Machinery</h3>
            <div class="table-responsive">
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Equipment Type</th>
                            <th>Code</th>
                            <th>Number</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equipment as $equip): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($equip['equipment_type']); ?></td>
                            <td><?php echo htmlspecialchars($equip['equipment_code'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($equip['equipment_number'] ?? '-'); ?></td>
                            <td><?php echo $equip['quantity']; ?></td>
                            <td><?php echo htmlspecialchars($equip['unit']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Materials Used -->
            <?php if (!empty($materials)): ?>
            <h3 class="gov-section-title">6. Materials Consumed</h3>
            <div class="table-responsive">
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Material Type</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materials as $material): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($material['material_type']); ?></td>
                            <td><?php echo $material['quantity']; ?></td>
                            <td><?php echo htmlspecialchars($material['unit']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Documentation Photos -->
            <?php if (!empty($photos)): ?>
            <h3 class="gov-section-title">7. Photographic Documentation</h3>
            <?php
            $categories = ['before' => 'Before', 'during' => 'During', 'after' => 'After'];
            foreach ($categories as $cat_key => $cat_label):
                $cat_photos = array_filter($photos, function($p) use ($cat_key) { return $p['category'] === $cat_key; });
                if (!empty($cat_photos)):
            ?>
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 1rem; font-weight: 600; color: #475569; margin-bottom: 0.75rem;">
                    <?php echo $cat_label; ?> Execution
                </h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem;">
                    <?php foreach ($cat_photos as $photo): ?>
                    <div style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
                        <img src="<?php echo htmlspecialchars($photo['photo_path']); ?>" 
                             alt="<?php echo $cat_label; ?> photo" 
                             style="width: 100%; height: 150px; object-fit: cover;">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; endforeach; ?>
            <?php endif; ?>


            <!-- Report Footer -->
            <div style="margin-top: 3rem; padding-top: 2rem; border-top: 2px solid #e2e8f0; text-align: center; color: #64748b; font-size: 0.875rem;">
                <p style="margin: 0;">This is an official document generated by the PSD Emergency Management System</p>
                <p style="margin: 0.25rem 0 0 0;">Generated on: <?php echo date('F d, Y h:i A'); ?></p>
            </div>
        </div>


        <!-- Start Execution Button (Only for NEW status) -->
        <?php if ($emergency['status'] === 'new'): ?>
        <div class="content-section" id="startExecutionSection" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; text-align: center; padding: 3rem 2rem;">
            <i class="fas fa-play-circle" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.9;"></i>
            <h2 style="color: white; font-size: 1.75rem; font-weight: 700; margin-bottom: 1rem;">Ready to Start Execution?</h2>
            <p style="font-size: 1.125rem; margin-bottom: 2rem; opacity: 0.95;">
                Click below to begin executing this emergency. This will track response time and execution time.
            </p>
            <button type="button" onclick="startExecution()" class="btn" style="background: white; color: #059669; font-size: 1.125rem; padding: 1rem 2.5rem; font-weight: 700; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <i class="fas fa-rocket"></i> Start Execution Now
            </button>
        </div>
        <?php endif; ?>

        <!-- Execution Form Section (Hidden if read-only) -->
        <?php if (!$is_readonly): ?>
        <div class="execution-form-section" id="executeForm" <?php echo $emergency['status'] === 'new' ? 'style="display: none;"' : ''; ?>>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 1.5rem;">
                <i class="fas fa-clipboard-list"></i>
                Execution Data Entry
            </h2>
            
            <form id="executionForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="emergency_id" value="<?php echo $emergency_id; ?>">
                
                <!-- Execution Summary -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem;">Execution Summary</h3>
                    <div class="form-group">
                        <label class="form-label">Executor Name *</label>
                        
                        <?php if ($can_delegate): ?>
                        <!-- Radio buttons (only for admin and sector_manager) -->
                        <div style="margin-bottom: 1rem;">
                            <label style="display: inline-flex; align-items: center; margin-right: 2rem; cursor: pointer;">
                                <input type="radio" name="executor_type" value="self" checked onchange="toggleExecutorDropdown()" style="margin-right: 0.5rem; width: auto;">
                                <span>I will execute it myself</span>
                            </label>
                            <label style="display: inline-flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="executor_type" value="delegate" onchange="toggleExecutorDropdown()" style="margin-right: 0.5rem; width: auto;">
                                <span>Delegate to someone else</span>
                            </label>
                        </div>
                        
                        <!-- Hidden input for self execution -->
                        <input type="hidden" id="selfExecutorName" value="<?php echo htmlspecialchars($user['full_name']); ?>">
                        
                        <!-- Dropdown for delegation (hidden by default) -->
                        <div id="delegateDropdownContainer" style="display: none;">
                            <select id="delegateDropdown" name="executor_name_delegate" class="form-input" style="height: 48px;">
                                <option value="">-- Select Person --</option>
                                <?php foreach ($sector_users as $sector_user): ?>
                                    <option value="<?php echo htmlspecialchars($sector_user['full_name']); ?>">
                                        <?php echo htmlspecialchars($sector_user['full_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $sector_user['role'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Final hidden input that will be submitted -->
                        <input type="hidden" name="executor_name" id="finalExecutorName" value="<?php echo htmlspecialchars($user['full_name']); ?>">
                        
                        <?php else: ?>
                        <!-- For supervisor/inspection: only self execution (no choice) -->
                        <input type="text" name="executor_name" class="form-input" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly style="height: 48px; background-color: #f3f4f6;">
                        <p style="font-size: 0.875rem; color: #6b7280; margin-top: 0.5rem;">You will execute this emergency yourself.</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Work Description *</label>
                        <textarea name="work_description" class="form-input" rows="4" required placeholder="Describe the work performed..."><?php echo htmlspecialchars($execution_summary['work_description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Employees Section -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem;">Employees</h3>
                    <div id="workforceList"></div>
                    <button type="button" onclick="addWorkforce()" class="btn btn-secondary" style="margin-top: 1rem;">
                        <i class="fas fa-plus"></i> Add Employee
                    </button>
                </div>

                <!-- Equipment Section -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem;">Equipment</h3>
                    <div id="equipmentList"></div>
                    <button type="button" onclick="addEquipment()" class="btn btn-secondary" style="margin-top: 1rem;">
                        <i class="fas fa-plus"></i> Add Equipment
                    </button>
                </div>

                <!-- Materials Section -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem;">Materials</h3>
                    <div id="materialsList"></div>
                    <button type="button" onclick="addMaterial()" class="btn btn-secondary" style="margin-top: 1rem;">
                        <i class="fas fa-plus"></i> Add Material
                    </button>
                </div>

                <!-- Photos Section -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem;">Documentation Photos</h3>
                    <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 1rem;">
                        Before photos were captured during emergency creation. Add During and After photos here.
                    </p>
                    
                    <?php foreach (['during' => 'During', 'after' => 'After'] as $cat => $label): ?>
                    <div style="margin-bottom: 1.5rem;">
                        <label class="form-label"><?php echo $label; ?> Execution (Max 5 photos)</label>
                        <div style="display: flex; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <input type="file" id="<?php echo $cat; ?>Upload" accept="image/*" multiple onchange="handlePhotoUpload('<?php echo $cat; ?>', this.files)" class="form-input" style="height: 48px;">
                        </div>
                        <div id="<?php echo $cat; ?>Preview" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.5rem;"></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Status Selection -->
                <div class="form-group" style="margin-bottom: 2rem;">
                    <label class="form-label">Execution Status *</label>
                    <select name="execution_status" class="form-input" required>
                        <option value="in_progress" <?php echo in_array($emergency['status'], ['executing', 'in_progress']) ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $emergency['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <div style="display: flex; gap: 1rem; padding-top: 1rem; border-top: 2px solid #e2e8f0;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Execution Data
                    </button>
                    <a href="emergencies.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<!-- Camera Modal -->
<div id="cameraModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Capture Photo</h3>
            <button onclick="closeCamera()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <video id="cameraVideo" autoplay playsinline style="width: 100%; border-radius: 8px; background: #000;"></video>
            <canvas id="cameraCanvas" style="display: none;"></canvas>
        </div>
        <div class="modal-footer">
            <button onclick="capturePhoto()" class="btn btn-primary">
                <i class="fas fa-camera"></i> Capture
            </button>
            <button onclick="closeCamera()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<script>
// Toggle Executor Dropdown
function toggleExecutorDropdown() {
    const executorType = document.querySelector('input[name="executor_type"]:checked').value;
    const delegateContainer = document.getElementById('delegateDropdownContainer');
    const delegateDropdown = document.getElementById('delegateDropdown');
    const finalExecutorName = document.getElementById('finalExecutorName');
    const selfExecutorName = document.getElementById('selfExecutorName').value;
    
    if (executorType === 'self') {
        delegateContainer.style.display = 'none';
        delegateDropdown.required = false;
        finalExecutorName.value = selfExecutorName;
    } else {
        delegateContainer.style.display = 'block';
        delegateDropdown.required = true;
        finalExecutorName.value = '';
    }
}

// Update final executor name when delegate is selected
document.addEventListener('DOMContentLoaded', function() {
    const delegateDropdown = document.getElementById('delegateDropdown');
    if (delegateDropdown) {
        delegateDropdown.addEventListener('change', function() {
            document.getElementById('finalExecutorName').value = this.value;
        });
    }
});

// Photo Management (Only During and After - Before is captured during creation)
let imageCollections = {
    during: [],
    after: []
};

let currentCamera = null;
let cameraStream = null;

function handlePhotoUpload(category, files) {
    const maxPhotos = 5;
    const filesArray = Array.from(files);
    
    if (imageCollections[category].length + filesArray.length > maxPhotos) {
        alert(`Maximum ${maxPhotos} photos allowed per category`);
        return;
    }
    
    filesArray.forEach(function(file) {
        if (imageCollections[category].length < maxPhotos) {
            imageCollections[category].push(file);
        }
    });
    
    renderPhotoPreview(category);
}

function renderPhotoPreview(category) {
    const container = document.getElementById(category + 'Preview');
    container.innerHTML = '';
    
    imageCollections[category].forEach(function(file, index) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const div = document.createElement('div');
            div.style.position = 'relative';
            div.innerHTML = `
                <img src="${e.target.result}" style="width: 100%; height: 100px; object-fit: cover; border-radius: 6px; border: 2px solid #e2e8f0;">
                <button type="button" onclick="removePhoto('${category}', ${index})" 
                        style="position: absolute; top: 4px; right: 4px; background: #ef4444; color: white; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

function removePhoto(category, index) {
    imageCollections[category].splice(index, 1);
    renderPhotoPreview(category);
}

function openCamera(category) {
    currentCamera = category;
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');
    
    modal.style.display = 'flex';
    
    navigator.mediaDevices.getUserMedia({ 
        video: { facingMode: 'environment' },
        audio: false
    })
    .then(function(stream) {
        cameraStream = stream;
        video.srcObject = stream;
    })
    .catch(function(err) {
        console.error('Camera error:', err);
        alert('Could not access camera. Please use upload instead.');
        closeCamera();
    });
}

function closeCamera() {
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');
    
    if (cameraStream) {
        cameraStream.getTracks().forEach(function(track) {
            track.stop();
        });
    }
    
    video.srcObject = null;
    modal.style.display = 'none';
    currentCamera = null;
}

function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');
    const context = canvas.getContext('2d');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    context.drawImage(video, 0, 0);
    
    canvas.toBlob(function(blob) {
        const file = new File([blob], `photo_${Date.now()}.jpg`, { type: 'image/jpeg' });
        
        if (imageCollections[currentCamera].length < 5) {
            imageCollections[currentCamera].push(file);
            renderPhotoPreview(currentCamera);
        } else {
            alert('Maximum 5 photos allowed per category');
        }
        
        closeCamera();
    }, 'image/jpeg', 0.9);
}

// Employee Management
let workforceCounter = 0;

function addWorkforce() {
    const container = document.getElementById('workforceList');
    const id = ++workforceCounter;
    
    const div = document.createElement('div');
    div.id = 'workforce_' + id;
    div.style.cssText = 'display: grid; grid-template-columns: 1.5fr 1fr 1fr auto; gap: 1rem; margin-bottom: 1rem; padding: 1rem; background: #f8fafc; border-radius: 6px;';
    div.innerHTML = `
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Job Title</label>
            <select name="workforce[${id}][job_title]" class="form-input" onchange="toggleOtherField(${id}, 'job', this.value)">
                <option value="">Select Job</option>
                <option value="Engineer">Engineer</option>
                <option value="Operations Supervisor">Operations Supervisor</option>
                <option value="Supervisor">Supervisor</option>
                <option value="Inspector">Inspector</option>
                <option value="Worker">Worker</option>
                <option value="Driver">Driver</option>
                <option value="Surveyor">Surveyor</option>
                <option value="Other">Other</option>
            </select>
            <input type="text" id="other_job_${id}" name="workforce[${id}][job_title_other]" class="form-input" placeholder="Specify job title" style="display:none; margin-top:0.5rem;">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Employee ID</label>
            <input type="text" name="workforce[${id}][employee_id]" class="form-input" placeholder="Employee ID">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Hours</label>
            <input type="number" step="0.5" name="workforce[${id}][hours]" class="form-input" placeholder="Hours">
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="button" onclick="removeWorkforce(${id})" class="btn btn-danger" style="padding: 0.5rem 1rem;">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(div);
}

function removeWorkforce(id) {
    document.getElementById('workforce_' + id).remove();
}

// Toggle Other Field Function
function toggleOtherField(id, type, value) {
    const otherId = 'other_' + type + '_' + id;
    const otherField = document.getElementById(otherId);
    if (otherField) {
        otherField.style.display = value === 'Other' ? 'block' : 'none';
        if (value !== 'Other') {
            otherField.value = '';
        }
    }
}

// Equipment Management
let equipmentCounter = 0;

function addEquipment() {
    const container = document.getElementById('equipmentList');
    const id = ++equipmentCounter;
    
    const div = document.createElement('div');
    div.id = 'equipment_' + id;
    div.style.cssText = 'display: grid; grid-template-columns: 1.5fr 1fr 1fr 0.8fr 1.2fr auto; gap: 1rem; margin-bottom: 1rem; padding: 1rem; background: #f8fafc; border-radius: 6px;';
    div.innerHTML = `
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Equipment Type</label>
            <select name="equipment[${id}][type]" class="form-input" onchange="toggleOtherField(${id}, 'equip', this.value)">
                <option value="">Select Type</option>
                <option value="Excavator">Excavator</option>
                <option value="Loader">Loader</option>
                <option value="Truck">Truck</option>
                <option value="Pump">Pump</option>
                <option value="Generator">Generator</option>
                <option value="Compressor">Compressor</option>
                <option value="Other">Other</option>
            </select>
            <input type="text" id="other_equip_${id}" name="equipment[${id}][type_other]" class="form-input" placeholder="Specify equipment type" style="display:none; margin-top:0.5rem;">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Code</label>
            <input type="text" name="equipment[${id}][code]" class="form-input" placeholder="Code">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Number</label>
            <input type="text" name="equipment[${id}][number]" class="form-input" placeholder="Number">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Qty</label>
            <input type="number" step="0.1" name="equipment[${id}][quantity]" class="form-input" placeholder="0">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Unit</label>
            <select name="equipment[${id}][unit]" class="form-input">
                <option value="Hours">Hours</option>
                <option value="Trips">Trips</option>
                <option value="Tons">Tons</option>
                <option value="Cubic Meters">Cubic Meters</option>
                <option value="Gallons">Gallons</option>
            </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="button" onclick="removeEquipment(${id})" class="btn btn-danger" style="padding: 0.5rem 1rem;">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(div);
}

function removeEquipment(id) {
    document.getElementById('equipment_' + id).remove();
}

// Materials Management
let materialCounter = 0;

function addMaterial() {
    const container = document.getElementById('materialsList');
    const id = ++materialCounter;
    
    const div = document.createElement('div');
    div.id = 'material_' + id;
    div.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 1rem; margin-bottom: 1rem; padding: 1rem; background: #f8fafc; border-radius: 6px;';
    div.innerHTML = `
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Material Type</label>
            <select name="materials[${id}][type]" class="form-input" onchange="toggleOtherField(${id}, 'material', this.value)">
                <option value="">Select Type</option>
                <option value="Concrete">Concrete</option>
                <option value="Asphalt">Asphalt</option>
                <option value="Sand">Sand</option>
                <option value="Gravel">Gravel</option>
                <option value="Steel">Steel</option>
                <option value="Cement">Cement</option>
                <option value="Paint">Paint</option>
                <option value="Other">Other</option>
            </select>
            <input type="text" id="other_material_${id}" name="materials[${id}][type_other]" class="form-input" placeholder="Specify material type" style="display:none; margin-top:0.5rem;">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Quantity</label>
            <input type="number" step="0.1" name="materials[${id}][quantity]" class="form-input" placeholder="0">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="margin-bottom: 0.25rem;">Unit</label>
            <input type="text" name="materials[${id}][unit]" class="form-input" placeholder="e.g. Kg, L, m³">
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="button" onclick="removeMaterial(${id})" class="btn btn-danger" style="padding: 0.5rem 1rem;">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(div);
}

function removeMaterial(id) {
    document.getElementById('material_' + id).remove();
}

// Start Execution - Change status from NEW to IN_PROGRESS
function startExecution() {
    if (!confirm('Are you sure you want to start executing this emergency? This will begin tracking execution time.')) {
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting...';
    
    fetch('ajax/start_execution.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ emergency_id: <?php echo $emergency_id; ?> })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Hide start button section and show execution form
            document.getElementById('startExecutionSection').style.display = 'none';
            document.getElementById('executeForm').style.display = 'block';
            
            // Show success message
            const successMsg = document.createElement('div');
            successMsg.className = 'alert alert-success';
            successMsg.innerHTML = '<i class="fas fa-check-circle"></i> Execution started successfully! Response time: ' + data.response_time;
            successMsg.style.marginBottom = '1.5rem';
            document.getElementById('executeForm').insertBefore(successMsg, document.getElementById('executeForm').firstChild);
            
            // Update status badge in header
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rocket"></i> Start Execution Now';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while starting execution');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-rocket"></i> Start Execution Now';
    });
}

// Form Submission
document.getElementById('executionForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Append photos
    for (let category in imageCollections) {
        imageCollections[category].forEach(function(file, index) {
            formData.append(`photos_${category}[]`, file);
        });
    }
    
    fetch('ajax/execute_emergency.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            alert('Execution data saved successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('An error occurred while saving data');
    });
});


// Initialize Map
<?php if ($emergency['latitude'] && $emergency['longitude']): ?>
document.addEventListener('DOMContentLoaded', function() {
    const map = L.map('executionMap').setView([<?php echo $emergency['latitude']; ?>, <?php echo $emergency['longitude']; ?>], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    
    const marker = L.marker([<?php echo $emergency['latitude']; ?>, <?php echo $emergency['longitude']; ?>])
        .addTo(map)
        .bindPopup('<strong><?php echo htmlspecialchars($emergency['title']); ?></strong><br><?php echo htmlspecialchars($emergency['address'] ?? ''); ?>')
        .openPopup();
});
<?php endif; ?>
</script>
