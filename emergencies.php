<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

$user = $_SESSION['user'];
$user_role = $user['role'];

// Add global database connection
global $conn;

// Filters
$status = $_GET['status'] ?? '';
$severity = $_GET['severity'] ?? '';
$sector_id = $_GET['sector_id'] ?? '';
$search = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);

// Build query with permission filters
$sql = "SELECT e.*, 
        s.name AS sector_name,
        u.full_name as creator_name,
        et.name as emergency_type
        FROM emergencies e
        LEFT JOIN sectors s ON e.sector_id = s.id
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN emergency_types et ON e.emergency_type_id = et.id
        WHERE 1=1";

$params = [];

// Apply permission filters based on role - UPDATED to use user_sectors table
if (!in_array($user_role, ['super_admin', 'admin', 'inspection'])) {
    // sector_manager: See only emergencies in their responsible sectors
    if ($user_role === 'sector_manager') {
        $sector_ids = getUserSectorIds($user['id']);
        if (!empty($sector_ids)) {
            $sql .= " AND e.sector_id IN (" . implode(',', array_map('intval', $sector_ids)) . ")";
        } else {
            // SECURITY: No sectors assigned = no access
            $sql .= " AND 1=0";
        }
    }
    // supervisor: See emergencies in their sectors + created by them + delegated to them
    elseif ($user_role === 'supervisor') {
        $conditions = [];
        
        // Emergencies in their responsible sectors (from user_sectors table)
        $sector_ids = getUserSectorIds($user['id']);
        if (!empty($sector_ids)) {
            $conditions[] = "e.sector_id IN (" . implode(',', array_map('intval', $sector_ids)) . ")";
        }
        
        // Emergencies created by them
        $conditions[] = "e.created_by = :user_id";
        $params[':user_id'] = $user['id'];
        
        // Emergencies delegated to them (executor)
        $conditions[] = "e.assigned_to = :assigned_user";
        $params[':assigned_user'] = $user['id'];
        
        if (!empty($conditions)) {
            $sql .= " AND (" . implode(' OR ', $conditions) . ")";
        } else {
            // SECURITY: No sectors and no assignments = no access
            $sql .= " AND 1=0";
        }
    }
    // Other roles: fallback to single sector
    else {
        if (!empty($user['sector_id'])) {
            $sql .= " AND e.sector_id = :user_sector";
            $params[':user_sector'] = $user['sector_id'];
        } else {
            // SECURITY: No sector assignment = no access
            $sql .= " AND 1=0";
        }
    }
}

// Apply user filters
if ($status) {
    $sql .= " AND e.status = :status";
    $params[':status'] = $status;
}
if ($severity) {
    $sql .= " AND e.severity_level = :severity";
    $params[':severity'] = $severity;
}
if ($sector_id) {
    $sql .= " AND e.sector_id = :sector";
    $params[':sector'] = $sector_id;
}
if ($search) {
    $sql .= " AND (e.title LIKE :search OR e.description LIKE :search OR et.name LIKE :search)";
    $params[':search'] = "%$search%";
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM ($sql) as count_query";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];

$pagination = paginate($total_records, $page);

$sql .= " ORDER BY e.created_at DESC LIMIT :limit OFFSET :offset";
$params[':limit'] = $pagination['limit'];
$params[':offset'] = $pagination['offset'];

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$emergencies = $stmt->fetchAll();

// Get filter options
$sectors = getUserSectors($user);

$page_title = 'Emergencies';
include 'includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Emergency Management
                </h1>
                <p class="page-subtitle">Track and respond to emergency situations</p>
            </div>
            <div>
                <?php if (hasPermission('emergencies_create') || in_array($user_role, ['super_admin', 'admin'])): ?>
                <a href="create_emergency.php" class="btn btn-danger">
                    <i class="fas fa-plus"></i> Report Emergency
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filters -->
        <div class="unified-filter-bar">
            <h3 class="unified-filter-title">
                <i class="fas fa-filter"></i>
                Filter Emergencies
            </h3>
            
            <form method="GET" action="emergencies.php">
                <div class="unified-filter-grid">
                    <div class="form-group">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-input" name="search" placeholder="Code, Title..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-input" name="status">
                            <option value="">All Status</option>
                            <option value="new" <?php echo $status == 'new' ? 'selected' : ''; ?>>New</option>
                            <option value="executing" <?php echo $status == 'executing' ? 'selected' : ''; ?>>Executing</option>
                            <option value="hold" <?php echo $status == 'hold' ? 'selected' : ''; ?>>Hold</option>
                            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Severity</label>
                        <select class="form-input" name="severity">
                            <option value="">All Severity</option>
                            <option value="medium" <?php echo $severity == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $severity == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="critical" <?php echo $severity == 'critical' ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>

                    <?php if (!empty($sectors)): ?>
                    <div class="form-group">
                        <label class="form-label">Sector</label>
                        <select class="form-input" name="sector_id">
                            <option value="">All Sectors</option>
                            <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>" <?php echo $sector_id == $sector['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sector['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="unified-filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="emergencies.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Emergencies Table -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list"></i>
                    Emergencies (<?php echo $total_records; ?>)
                </h2>
            </div>

            <?php if (empty($emergencies)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <div class="empty-state-title">No emergencies found</div>
                    <div class="empty-state-text">Try adjusting your filters</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table" id="emergenciesTable">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Title</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Sector</th>
                                <th>Reported By</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emergencies as $emergency): ?>
                                <tr style="<?php echo $emergency['severity'] === 'critical' ? 'background: rgba(239, 68, 68, 0.05);' : ''; ?>">
                                    <td>
                                        <strong style="color: var(--secondary-red);">
                                            EMG-<?php echo str_pad($emergency['id'], 5, '0', STR_PAD_LEFT); ?>
                                        </strong>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($emergency['title'], 0, 50) . (strlen($emergency['title']) > 50 ? '...' : '')); ?></td>
                                    <td><?php echo getSeverityBadge($emergency['severity']); ?></td>
                                    <td><?php echo getStatusBadge($emergency['status']); ?></td>
                                    <td><?php echo htmlspecialchars($emergency['emergency_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($emergency['sector_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($emergency['creator_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo formatDate($emergency['created_at']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view_emergency.php?id=<?php echo $emergency['id']; ?>" class="btn btn-sm btn-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (hasPermission('emergencies_execute') || in_array($user_role, ['super_admin', 'admin', 'sector_manager', 'supervisor'])): ?>
                                            <a href="execute_emergency.php?id=<?php echo $emergency['id']; ?>" class="btn btn-sm btn-danger" title="Execution Report">
                                                <i class="fas fa-clipboard-list"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if (in_array($user_role, ['super_admin', 'admin'])): ?>
                                            <a href="edit_emergency.php?id=<?php echo $emergency['id']; ?>" class="btn btn-sm btn-secondary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter(['status' => $status, 'severity' => $severity, 'sector_id' => $sector_id, 'search' => $search])); ?>" 
                               class="pagination-btn">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $pagination['total_pages']; ?>
                        </span>

                        <?php if ($page < $pagination['total_pages']): ?>
                            <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter(['status' => $status, 'severity' => $severity, 'sector_id' => $sector_id, 'search' => $search])); ?>" 
                               class="pagination-btn">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>
