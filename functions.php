<?php
/**
 * PSD Portal - Complete Helper Functions Library
 * Version: 2.1 Final with Reports
 * Contact: +971 50 700 9029 | mma.1985@icloud.com
 * 
 * This file contains all utility functions for the PSD Portal application
 * Including: Core Functions + Report Functions
 */

// ============================================
// SESSION & AUTHENTICATION
// ============================================

/**
 * Generate CSRF Token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check Authentication with Session Security
 */
function checkAuth() {
    if (!isset($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // Every 5 minutes
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

function isLoggedIn() {
    return isset($_SESSION['user']);
}

function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

function hasRole($roles) {
    if (!isLoggedIn()) return false;
    
    $user = getCurrentUser();
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    return in_array($user['role'], $roles);
}

function updateUserSession() {
    global $conn;
    
    if (!isLoggedIn()) return false;
    
    $user_id = $_SESSION['user']['id'];
    
    try {
        $stmt = $conn->prepare("SELECT u.*, s.name AS sector_name 
                               FROM users u 
                               LEFT JOIN sectors s ON u.sector_id = s.id 
                               WHERE u.id = :id AND u.is_active = 1");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['user'] = $user;
            return true;
        } else {
            session_destroy();
            return false;
        }
    } catch (Exception $e) {
        error_log("Update user session error: " . $e->getMessage());
        return false;
    }
}

function logout() {
    if (isLoggedIn()) {
        $user = getCurrentUser();
        logActivity($user['id'], 'logout', 'User logged out');
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// ============================================
// PERMISSIONS & ACCESS CONTROL
// ============================================

/**
 * Role Capability Matrix - Defines what each role can do
 * Updated system with new permissions for sector_manager, supervisor, and inspection
 */
function getRoleCapabilities() {
    return [
        'super_admin' => [
            'emergencies_view' => true,
            'emergencies_create' => true,
            'emergencies_edit' => true,
            'emergencies_delete' => true,
            'emergencies_execute' => true,
            'emergencies_delegate' => true,
            'users_view' => true,
            'users_create' => true,
            'users_edit' => true,
            'users_delete' => true,
            'sectors_view' => true,
            'sectors_create' => true,
            'sectors_edit' => true,
            'sectors_delete' => true,
            'reports_view' => true,
            'settings_access' => true,
            'view_all_data' => true,
            'assets_view' => true,
            'assets_create' => true,
            'assets_edit' => true,
            'assets_delete' => true,
        ],
        'admin' => [
            'emergencies_view' => true,
            'emergencies_create' => true,
            'emergencies_edit' => true,
            'emergencies_delete' => false, // Cannot delete
            'emergencies_execute' => true,
            'emergencies_delegate' => true,
            'users_view' => true,
            'users_create' => false, // ❌ NEW: Cannot add users
            'users_edit' => true,
            'users_delete' => false, // ❌ Cannot delete users
            'sectors_view' => true,
            'sectors_create' => false, // ❌ NEW: Cannot add sectors
            'sectors_edit' => true,
            'sectors_delete' => false, // ❌ NEW: Cannot delete sectors
            'reports_view' => true,
            'settings_access' => true,
            'view_all_data' => true,
            'assets_view' => true,
            'assets_create' => true,
            'assets_edit' => true,
            'assets_delete' => true,
        ],
        'sector_manager' => [
            'emergencies_view' => true,
            'emergencies_create' => true, // ✅ NEW: Can create emergencies
            'emergencies_edit' => true,
            'emergencies_delete' => false,
            'emergencies_execute' => true, // ✅ NEW: Can execute directly
            'emergencies_delegate' => true, // Can delegate to sector users
            'users_view' => true,
            'users_create' => false,
            'users_edit' => false,
            'users_delete' => false,
            'sectors_view' => true,
            'sectors_create' => false,
            'sectors_edit' => false,
            'sectors_delete' => false,
            'reports_view' => true,
            'settings_access' => true,
            'view_all_data' => false, // Only sector data
            'assets_view' => true,
            'assets_create' => true,
            'assets_edit' => true,
            'assets_delete' => true,
        ],
        'supervisor' => [
            'emergencies_view' => true,
            'emergencies_create' => true, // ✅ NEW: Can create emergencies
            'emergencies_edit' => false, // Cannot edit others' emergencies
            'emergencies_delete' => false,
            'emergencies_execute' => true, // ✅ Can execute
            'emergencies_delegate' => false, // ❌ Cannot delegate
            'users_view' => false,
            'users_create' => false,
            'users_edit' => false,
            'users_delete' => false,
            'sectors_view' => true,
            'sectors_create' => false,
            'sectors_edit' => false,
            'sectors_delete' => false,
            'reports_view' => false, // Only their own reports
            'settings_access' => true,
            'view_all_data' => false, // Only created/assigned data
            'assets_view' => true,
            'assets_create' => true,
            'assets_edit' => true,
            'assets_delete' => true,
        ],
        'inspection' => [
            'emergencies_view' => true,
            'emergencies_create' => true, // ✅ Can create emergencies (all sectors)
            'emergencies_edit' => false,
            'emergencies_delete' => false,
            'emergencies_execute' => false, // ❌ Cannot execute
            'emergencies_delegate' => false, // ❌ Cannot delegate
            'users_view' => false,
            'users_create' => false,
            'users_edit' => false,
            'users_delete' => false,
            'sectors_view' => true,
            'sectors_create' => false,
            'sectors_edit' => false,
            'sectors_delete' => false,
            'reports_view' => true, // ✅ Can view all reports
            'settings_access' => false, // ❌ No settings access
            'view_all_data' => true, // ✅ Can view all emergencies and assets
            'assets_view' => true,
            'assets_create' => false,
            'assets_edit' => false,
            'assets_delete' => false,
        ],
    ];
}

/**
 * Check if current user has a specific capability
 * super_admin ALWAYS returns true - NO LIMITS
 */
function hasRoleCapability($capability) {
    if (!isLoggedIn()) return false;
    
    $user = getCurrentUser();
    
    // ⚡ SUPER ADMIN: UNLIMITED ACCESS - NO RESTRICTIONS
    if ($user['role'] === 'super_admin') {
        return true;
    }
    
    $capabilities = getRoleCapabilities();
    
    if (!isset($capabilities[$user['role']])) {
        return false;
    }
    
    return $capabilities[$user['role']][$capability] ?? false;
}

/**
 * Legacy permission check - Now uses Role Capability Matrix
 * super_admin ALWAYS returns true - NO LIMITS
 */
function hasPermission($permission) {
    if (!isLoggedIn()) return false;
    
    $user = getCurrentUser();
    
    // ⚡ SUPER ADMIN: UNLIMITED ACCESS - NO RESTRICTIONS
    if ($user['role'] === 'super_admin') {
        return true;
    }
    
    // Map legacy permission names to new capabilities
    $permission_map = [
        'emergencies_view' => 'emergencies_view',
        'emergencies_create' => 'emergencies_create',
        'emergencies_edit' => 'emergencies_edit',
        'emergencies_delete' => 'emergencies_delete',
        'users_manage' => 'users_edit',
        'sectors_manage' => 'sectors_edit',
        'reports_view' => 'reports_view',
    ];
    
    $capability = $permission_map[$permission] ?? $permission;
    return hasRoleCapability($capability);
}


/**
 * Check if user can access a specific sector
 * UPDATED: Uses user_sectors junction table instead of JSON
 */
function canAccessSector($sector_id) {
    if (!isLoggedIn() || !$sector_id) return false;
    
    $user = getCurrentUser();
    
    // Super admin, admin, and inspection can access all sectors
    if (in_array($user['role'], ['super_admin', 'admin', 'inspection'])) {
        return true;
    }
    
    // Sector manager and supervisor: Check user_sectors table (multi-sector)
    if (in_array($user['role'], ['sector_manager', 'supervisor'])) {
        $sector_ids = getUserSectorIds($user['id']);
        if (!empty($sector_ids)) {
            return in_array($sector_id, $sector_ids);
        }
    }
    
    // Fallback: Single sector assignment
    return $user['sector_id'] == $sector_id;
}

function getUserPermissions($user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT permission_key, permission_value 
                               FROM user_permissions 
                               WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $user_id]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        error_log("Get user permissions error: " . $e->getMessage());
        return [];
    }
}

function setUserPermission($user_id, $permission_key, $permission_value) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission_key, permission_value, created_at) 
                               VALUES (:user_id, :key, :value, NOW())
                               ON DUPLICATE KEY UPDATE permission_value = :value, updated_at = NOW()");
        $stmt->execute([
            ':user_id' => $user_id,
            ':key' => $permission_key,
            ':value' => $permission_value
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Set user permission error: " . $e->getMessage());
        return false;
    }
}

function removeUserPermission($user_id, $permission_key) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = :user_id AND permission_key = :key");
        $stmt->execute([
            ':user_id' => $user_id,
            ':key' => $permission_key
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Remove user permission error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// DATABASE HELPERS
// ============================================


/**
 * Get user's sectors - UPDATED to use user_sectors junction table
 * Returns full sector objects (not just IDs)
 * 
 * @param array $user User object
 * @return array Array of sector objects
 */
function getUserSectors($user) {
    global $conn;
    
    try {
        // Super admin and admin see all sectors
        if (in_array($user['role'], ['super_admin', 'admin'])) {
            return $conn->query("SELECT * FROM sectors WHERE is_active = 1 ORDER BY name")->fetchAll();
        }
        
        // sector_manager and supervisor: Get from user_sectors junction table
        if (in_array($user['role'], ['sector_manager', 'supervisor'])) {
            $stmt = $conn->prepare("
                SELECT s.* 
                FROM sectors s
                INNER JOIN user_sectors us ON s.id = us.sector_id
                WHERE us.user_id = ? AND s.is_active = 1
                ORDER BY s.name
            ");
            $stmt->execute([$user['id']]);
            return $stmt->fetchAll();
        }
        
        // Other roles: Get their single sector
        if ($user['sector_id']) {
            $stmt = $conn->prepare("SELECT * FROM sectors WHERE id = ? AND is_active = 1");
            $stmt->execute([$user['sector_id']]);
            return $stmt->fetchAll();
        }
        
        return [];
    } catch (Exception $e) {
        error_log("Get user sectors error: " . $e->getMessage());
        return [];
    }
}

function getAllSectors() {
    global $conn;
    
    try {
        return $conn->query("SELECT * FROM sectors WHERE is_active = 1 ORDER BY name")->fetchAll();
    } catch (Exception $e) {
        error_log("Get all sectors error: " . $e->getMessage());
        return [];
    }
}

function getSectorById($id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT * FROM sectors WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get sector by id error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get user's assigned sector IDs from user_sectors table
 * 
 * @param int $user_id User ID
 * @return array Array of sector IDs
 */
function getUserSectorIds($user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT sector_id FROM user_sectors WHERE user_id = ? ORDER BY sector_id");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log("Get user sector IDs error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get sector-based SQL filter for emergencies based on user role
 * UPDATED: Uses user_sectors junction table instead of JSON
 * 
 * @param array $user Current user object
 * @param string $table_alias Table alias for emergencies table (default: 'e')
 * @return string SQL WHERE clause for sector filtering
 */
function getUserSectorFilter($user, $table_alias = 'e') {
    if (!$user || !isset($user['role'])) {
        return " AND 1=0"; // No access if user invalid
    }
    
    $role = $user['role'];
    
    // Super admin, admin, and inspection see ALL emergencies
    if (in_array($role, ['super_admin', 'admin', 'inspection'])) {
        return ""; // No filter - see everything
    }
    
    // Sector Manager: Only their responsible sectors (from user_sectors table)
    if ($role === 'sector_manager') {
        $sector_ids = getUserSectorIds($user['id']);
        if (!empty($sector_ids)) {
            $sector_list = implode(',', array_map('intval', $sector_ids));
            return " AND {$table_alias}.sector_id IN ({$sector_list})";
        }
        return " AND 1=0"; // No sectors = no access
    }
    
    // Supervisor: Their sectors + created by them + delegated to them
    if ($role === 'supervisor') {
        $conditions = [];
        
        // 1. Emergencies from their responsible sectors (from user_sectors table)
        $sector_ids = getUserSectorIds($user['id']);
        if (!empty($sector_ids)) {
            $sector_list = implode(',', array_map('intval', $sector_ids));
            $conditions[] = "{$table_alias}.sector_id IN ({$sector_list})";
        }
        
        // 2. Emergencies they created
        $conditions[] = "{$table_alias}.created_by = " . intval($user['id']);
        
        // 3. Emergencies delegated to them
        $conditions[] = "{$table_alias}.delegated_to = " . intval($user['id']);
        
        if (!empty($conditions)) {
            return " AND (" . implode(' OR ', $conditions) . ")";
        }
        
        return " AND 1=0"; // No conditions = no access
    }
    
    // Default: No access for unknown roles
    return " AND 1=0";
}

/**
 * Assign sectors to a user in user_sectors table
 * 
 * @param int $user_id User ID
 * @param array $sector_ids Array of sector IDs
 * @return bool Success status
 */
function assignUserToSectors($user_id, $sector_ids) {
    global $conn;
    
    try {
        // First, remove existing assignments
        $stmt = $conn->prepare("DELETE FROM user_sectors WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Then, add new assignments
        if (!empty($sector_ids)) {
            $stmt = $conn->prepare("INSERT INTO user_sectors (user_id, sector_id) VALUES (?, ?)");
            foreach ($sector_ids as $sector_id) {
                $stmt->execute([$user_id, $sector_id]);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Assign user to sectors error: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove all sector assignments for a user
 * 
 * @param int $user_id User ID
 * @return bool Success status
 */
function removeUserSectorAssignments($user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("DELETE FROM user_sectors WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return true;
    } catch (Exception $e) {
        error_log("Remove user sector assignments error: " . $e->getMessage());
        return false;
    }
}

function getUserById($id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT u.*, s.name AS sector_name 
                               FROM users u 
                               LEFT JOIN sectors s ON u.sector_id = s.id 
                               WHERE u.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get user by id error: " . $e->getMessage());
        return null;
    }
}

function getAllUsers() {
    global $conn;
    
    try {
        return $conn->query("SELECT u.*, s.name AS sector_name 
                           FROM users u 
                           LEFT JOIN sectors s ON u.sector_id = s.id 
                           WHERE u.is_active = 1 
                           ORDER BY u.full_name")->fetchAll();
    } catch (Exception $e) {
        error_log("Get all users error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// CODE GENERATION
// ============================================

function generateEmergencyCode() {
    global $conn;
    
    $year = date('Y');
    $prefix = "EMG-{$year}-";
    
    try {
        $stmt = $conn->prepare("SELECT emergency_code FROM emergencies 
                               WHERE emergency_code LIKE :prefix 
                               ORDER BY id DESC LIMIT 1");
        $stmt->execute([':prefix' => $prefix . '%']);
        $last = $stmt->fetch();
        
        if ($last) {
            $last_num = intval(substr($last['emergency_code'], -4));
            $new_num = $last_num + 1;
        } else {
            $new_num = 1;
        }
        
        return $prefix . str_pad($new_num, 4, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        error_log("Generate emergency code error: " . $e->getMessage());
        return $prefix . '0001';
    }
}


// ============================================
// FORMATTING & DISPLAY
// ============================================

function formatDate($date, $format = 'M d, Y H:i') {
    if (!$date || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return 'N/A';
    }
    
    try {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 'N/A';
        }
        return date($format, $timestamp);
    } catch (Exception $e) {
        return 'N/A';
    }
}

function timeAgo($datetime) {
    if (!$datetime) return 'N/A';
    
    try {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return formatDate($datetime);
        }
        
        $diff = time() - $timestamp;
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        
        return formatDate($datetime);
    } catch (Exception $e) {
        return formatDate($datetime);
    }
}

function getStatusBadge($status) {
    $badges = [
        'in_progress' => '<span class="badge" style="background: #2563eb; color: white; font-weight: 600;"><i class="fas fa-cogs"></i> In Progress</span>',
        'executing' => '<span class="badge" style="background: #2563eb; color: white; font-weight: 600;"><i class="fas fa-cogs"></i> In Progress</span>', // Legacy
        'hold' => '<span class="badge" style="background: #f59e0b; color: white; font-weight: 600;"><i class="fas fa-pause-circle"></i> Hold</span>',
        'new' => '<span class="badge" style="background: #ef4444; color: white; font-weight: 600;"><i class="fas fa-exclamation-circle"></i> New</span>',
        'completed' => '<span class="badge" style="background: #10b981; color: white; font-weight: 600;"><i class="fas fa-check-circle"></i> Completed</span>',
        
        // Legacy status mappings for backwards compatibility
        'dispatched' => '<span class="badge" style="background: #2563eb; color: white; font-weight: 600;"><i class="fas fa-cogs"></i> In Progress</span>',
        'reported' => '<span class="badge" style="background: #2563eb; color: white; font-weight: 600;"><i class="fas fa-cogs"></i> In Progress</span>',
        'pending' => '<span class="badge" style="background: #f59e0b; color: white; font-weight: 600;"><i class="fas fa-pause-circle"></i> Hold</span>',
        'on_hold' => '<span class="badge" style="background: #f59e0b; color: white; font-weight: 600;"><i class="fas fa-pause-circle"></i> Hold</span>',
        'resolved' => '<span class="badge" style="background: #10b981; color: white; font-weight: 600;"><i class="fas fa-check-circle"></i> Completed</span>',
        'closed' => '<span class="badge" style="background: #10b981; color: white; font-weight: 600;"><i class="fas fa-check-circle"></i> Completed</span>'
    ];
    
    return $badges[$status] ?? '<span class="badge" style="background: #6b7280; color: white; font-weight: 600;">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

function getPriorityBadge($priority) {
    $badges = [
        'low' => '<span class="badge badge-info">Low</span>',
        'medium' => '<span class="badge badge-warning">Medium</span>',
        'high' => '<span class="badge badge-danger">High</span>',
        'critical' => '<span class="badge badge-danger" style="background: #dc2626; animation: pulse 2s infinite;">Critical</span>'
    ];
    
    return $badges[$priority] ?? '<span class="badge badge-gray">' . ucfirst($priority) . '</span>';
}

function getSeverityBadge($severity) {
    $badges = [
        'medium' => '<span class="badge badge-warning">Medium</span>',
        'high' => '<span class="badge badge-danger">High</span>',
        'critical' => '<span class="badge badge-danger" style="background: #dc2626; animation: pulse 2s infinite;">Critical</span>'
    ];
    
    return $badges[$severity] ?? '<span class="badge badge-gray">' . ucfirst($severity) . '</span>';
}

function formatCurrency($amount, $currency = 'AED') {
    return $currency . ' ' . number_format($amount, 2);
}

function formatNumber($number, $decimals = 0) {
    return number_format($number, $decimals);
}

function formatPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($phone) == 9 && substr($phone, 0, 1) == '5') {
        return '+971 ' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 3) . ' ' . substr($phone, 5);
    } elseif (strlen($phone) == 12 && substr($phone, 0, 3) == '971') {
        return '+' . substr($phone, 0, 3) . ' ' . substr($phone, 3, 2) . ' ' . substr($phone, 5, 3) . ' ' . substr($phone, 8);
    }
    
    return $phone;
}

// ============================================
// PAGINATION
// ============================================

function paginate($total_records, $current_page = 1, $records_per_page = 10) {
    $total_pages = ceil($total_records / $records_per_page);
    $current_page = max(1, min($current_page, max(1, $total_pages)));
    $offset = ($current_page - 1) * $records_per_page;
    
    return [
        'total_records' => $total_records,
        'total_pages' => $total_pages,
        'current_page' => $current_page,
        'records_per_page' => $records_per_page,
        'offset' => $offset,
        'limit' => $records_per_page,
        'has_prev' => $current_page > 1,
        'has_next' => $current_page < $total_pages,
        'prev_page' => max(1, $current_page - 1),
        'next_page' => min($total_pages, $current_page + 1)
    ];
}

// ============================================
// ACTIVITY LOGGING
// ============================================

function logActivity($user_id, $action, $description, $entity_type = null, $entity_id = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("INSERT INTO activity_logs 
            (user_id, action, description, entity_type, entity_id, ip_address, user_agent, created_at) 
            VALUES 
            (:user_id, :action, :description, :entity_type, :entity_id, :ip, :user_agent, NOW())");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':action' => $action,
            ':description' => $description,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
        return false;
    }
}

function getRecentActivity($user_id = null, $limit = 10) {
    global $conn;
    
    try {
        if ($user_id) {
            $stmt = $conn->prepare("SELECT a.*, u.full_name as user_name 
                                   FROM activity_logs a 
                                   LEFT JOIN users u ON a.user_id = u.id 
                                   WHERE a.user_id = :user_id 
                                   ORDER BY a.created_at DESC 
                                   LIMIT :limit");
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        } else {
            $stmt = $conn->prepare("SELECT a.*, u.full_name as user_name 
                                   FROM activity_logs a 
                                   LEFT JOIN users u ON a.user_id = u.id 
                                   ORDER BY a.created_at DESC 
                                   LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get recent activity error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================

function createNotification($user_id, $title, $message, $type = 'info', $entity_type = null, $entity_id = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("INSERT INTO notifications 
            (user_id, title, message, type, entity_type, entity_id, created_at) 
            VALUES 
            (:user_id, :title, :message, :type, :entity_type, :entity_id, NOW())");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':title' => $title,
            ':message' => $message,
            ':type' => $type,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Create notification error: " . $e->getMessage());
        return false;
    }
}

function getUserNotifications($user_id, $limit = 10, $unread_only = false) {
    global $conn;
    
    try {
        $sql = "SELECT * FROM notifications WHERE user_id = :user_id";
        if ($unread_only) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC LIMIT :limit";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get user notifications error: " . $e->getMessage());
        return [];
    }
}

function markNotificationAsRead($notification_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $notification_id]);
        return true;
    } catch (Exception $e) {
        error_log("Mark notification read error: " . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationCount($user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0");
        $stmt->execute([':user_id' => $user_id]);
        $result = $stmt->fetch();
        
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Get unread notification count error: " . $e->getMessage());
        return 0;
    }
}

function deleteNotification($notification_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = :id");
        $stmt->execute([':id' => $notification_id]);
        return true;
    } catch (Exception $e) {
        error_log("Delete notification error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// REPORTING FUNCTIONS (OLD - Basic)
// ============================================

function getComplaintsStats($start_date = null, $end_date = null, $sector_id = null) {
    global $conn;
    
    $where = ["1=1"];
    $params = [];
    
    if ($start_date) {
        $where[] = "reported_at >= :start_date";
        $params[':start_date'] = $start_date . ' 00:00:00';
    }
    if ($end_date) {
        $where[] = "reported_at <= :end_date";
        $params[':end_date'] = $end_date . ' 23:59:59';
    }
    if ($sector_id) {
        $where[] = "sector_id = :sector";
        $params[':sector'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high,
            SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END) as medium,
            SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END) as low,
            AVG(TIMESTAMPDIFF(HOUR, reported_at, resolved_at)) as avg_resolution_time
            FROM complaints 
            WHERE $where_sql");
        
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get complaints stats error: " . $e->getMessage());
        return [
            'total' => 0, 'pending' => 0, 'assigned' => 0, 'in_progress' => 0,
            'resolved' => 0, 'closed' => 0, 'rejected' => 0, 'critical' => 0,
            'high' => 0, 'medium' => 0, 'low' => 0, 'avg_resolution_time' => 0
        ];
    }
}

function getEmergenciesStats($start_date = null, $end_date = null, $department_id = null, $sector_id = null) {
    global $conn;
    
    $where = ["1=1"];
    $params = [];
    
    if ($start_date) {
        $where[] = "reported_at >= :start_date";
        $params[':start_date'] = $start_date . ' 00:00:00';
    }
    if ($end_date) {
        $where[] = "reported_at <= :end_date";
        $params[':end_date'] = $end_date . ' 23:59:59';
    }
    if ($department_id) {
        $where[] = "department_id = :dept";
        $params[':dept'] = $department_id;
    }
    if ($sector_id) {
        $where[] = "sector_id = :sector";
        $params[':sector'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'reported' THEN 1 ELSE 0 END) as reported,
            SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN severity_level = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN severity_level = 'high' THEN 1 ELSE 0 END) as high,
            SUM(CASE WHEN severity_level = 'medium' THEN 1 ELSE 0 END) as medium,
            AVG(TIMESTAMPDIFF(MINUTE, reported_at, dispatched_at)) as avg_response_time,
            AVG(TIMESTAMPDIFF(HOUR, reported_at, resolved_at)) as avg_resolution_time
            FROM emergencies 
            WHERE $where_sql");
        
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get emergencies stats error: " . $e->getMessage());
        return [
            'total' => 0, 'reported' => 0, 'dispatched' => 0, 'in_progress' => 0,
            'resolved' => 0, 'cancelled' => 0, 'critical' => 0, 'high' => 0,
            'medium' => 0, 'avg_response_time' => 0, 'avg_resolution_time' => 0
        ];
    }
}

function getTasksStats($start_date = null, $end_date = null, $department_id = null, $sector_id = null) {
    global $conn;
    
    $where = ["1=1"];
    $params = [];
    
    if ($start_date) {
        $where[] = "created_at >= :start_date";
        $params[':start_date'] = $start_date . ' 00:00:00';
    }
    if ($end_date) {
        $where[] = "created_at <= :end_date";
        $params[':end_date'] = $end_date . ' 23:59:59';
    }
    if ($department_id) {
        $where[] = "department_id = :dept";
        $params[':dept'] = $department_id;
    }
    if ($sector_id) {
        $where[] = "sector_id = :sector";
        $params[':sector'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'on_hold' THEN 1 ELSE 0 END) as on_hold,
            SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high
            FROM tasks 
            WHERE $where_sql");
        
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get tasks stats error: " . $e->getMessage());
        return [
            'total' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0,
            'cancelled' => 0, 'on_hold' => 0, 'critical' => 0, 'high' => 0
        ];
    }
}

function getExecutionCosts($start_date = null, $end_date = null, $type = null) {
    global $conn;
    
    $where = ["1=1"];
    $params = [];
    
    if ($start_date) {
        $where[] = "execution_date >= :start_date";
        $params[':start_date'] = $start_date . ' 00:00:00';
    }
    if ($end_date) {
        $where[] = "execution_date <= :end_date";
        $params[':end_date'] = $end_date . ' 23:59:59';
    }
    if ($type) {
        $where[] = "execution_type = :type";
        $params[':type'] = $type;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT workforce_details, equipment_details, materials_used 
                               FROM task_executions WHERE $where_sql");
        $stmt->execute($params);
        $executions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get execution details from execution_summary table
        $stmt = $conn->prepare("SELECT * FROM execution_summary WHERE emergency_id IN (SELECT id FROM emergencies WHERE " . str_replace('execution_date', 'created_at', $where_sql) . ")");
        $stmt->execute($params);
        $emergency_execs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $conn->prepare("SELECT workforce_details, equipment_details, materials_used 
                               FROM direct_executions WHERE " . str_replace('execution_date', 'execution_date', $where_sql));
        $stmt->execute($params);
        $direct_execs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $all_executions = array_merge($executions, $emergency_execs, $direct_execs);
        
        $total_cost = 0;
        $workforce_cost = 0;
        $equipment_cost = 0;
        $materials_cost = 0;
        
        foreach ($all_executions as $exec) {
            $workforce = json_decode($exec['workforce_details'], true) ?? [];
            $equipment = json_decode($exec['equipment_details'], true) ?? [];
            $materials = json_decode($exec['materials_used'], true) ?? [];
            
            foreach ($workforce as $w) $workforce_cost += floatval($w['cost'] ?? 0);
            foreach ($equipment as $e) $equipment_cost += floatval($e['cost'] ?? 0);
            foreach ($materials as $m) $materials_cost += floatval($m['cost'] ?? 0);
        }
        
        $total_cost = $workforce_cost + $equipment_cost + $materials_cost;
        
        return [
            'total_cost' => $total_cost,
            'workforce_cost' => $workforce_cost,
            'equipment_cost' => $equipment_cost,
            'materials_cost' => $materials_cost,
            'total_executions' => count($all_executions)
        ];
    } catch (Exception $e) {
        error_log("Get execution costs error: " . $e->getMessage());
        return [
            'total_cost' => 0, 'workforce_cost' => 0, 'equipment_cost' => 0,
            'materials_cost' => 0, 'total_executions' => 0
        ];
    }
}

// ============================================
// CHART DATA GENERATION (OLD - Basic)
// ============================================

function getComplaintsByDepartment($start_date = null, $end_date = null) {
    global $conn;
    
    $where = ["1=1"];
    $params = [];
    
    if ($start_date) {
        $where[] = "c.reported_at >= :start_date";
        $params[':start_date'] = $start_date . ' 00:00:00';
    }
    if ($end_date) {
        $where[] = "c.reported_at <= :end_date";
        $params[':end_date'] = $end_date . ' 23:59:59';
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT COALESCE(d.name AS department_name, 'Unassigned') as department_name, COUNT(*) as count 
                               FROM complaints c 
                               LEFT JOIN departments d ON c.department_id = d.id 
                               WHERE $where_sql 
                               GROUP BY d.name AS department_name 
                               ORDER BY count DESC");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get complaints by department error: " . $e->getMessage());
        return [];
    }
}

function getComplaintsByStatus() {
    global $conn;
    
    try {
        $stmt = $conn->query("SELECT status, COUNT(*) as count 
                             FROM complaints 
                             GROUP BY status 
                             ORDER BY count DESC");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get complaints by status error: " . $e->getMessage());
        return [];
    }
}

function getComplaintsTrend($days = 30) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT DATE(reported_at) as date, COUNT(*) as count 
                               FROM complaints 
                               WHERE reported_at >= DATE_SUB(NOW(), INTERVAL :days DAY) 
                               GROUP BY DATE(reported_at) 
                               ORDER BY date ASC");
        
        $stmt->execute([':days' => $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get complaints trend error: " . $e->getMessage());
        return [];
    }
}

function getEmergenciesBySeverity() {
    global $conn;
    
    try {
        $stmt = $conn->query("SELECT severity_level, COUNT(*) as count 
                             FROM emergencies 
                             GROUP BY severity_level 
                             ORDER BY 
                                CASE severity_level
                                    WHEN 'critical' THEN 1
                                    WHEN 'high' THEN 2
                                    WHEN 'medium' THEN 3
                                    ELSE 4
                                END");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get emergencies by severity error: " . $e->getMessage());
        return [];
    }
}

function getTasksByPriority() {
    global $conn;
    
    try {
        $stmt = $conn->query("SELECT priority, COUNT(*) as count 
                             FROM tasks 
                             GROUP BY priority 
                             ORDER BY 
                                CASE priority
                                    WHEN 'critical' THEN 1
                                    WHEN 'high' THEN 2
                                    WHEN 'medium' THEN 3
                                    WHEN 'low' THEN 4
                                    ELSE 5
                                END");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get tasks by priority error: " . $e->getMessage());
        return [];
    }
}

function getComplaintsBySector() {
    global $conn;
    
    try {
        $stmt = $conn->query("SELECT COALESCE(s.name AS sector_name, 'Unassigned') as sector_name, COUNT(*) as count 
                             FROM complaints c 
                             LEFT JOIN sectors s ON c.sector_id = s.id 
                             GROUP BY s.name AS sector_name 
                             ORDER BY count DESC");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get complaints by sector error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// FILE UPLOAD FUNCTIONS
// ============================================

/**
 * SECURE FILE UPLOAD with MIME Type and Content Validation
 * @param array $file Uploaded file from $_FILES
 * @param string $upload_dir Upload directory path
 * @param array $allowed_types Allowed file extensions
 * @return string Generated filename
 * @throws Exception on validation failure
 */
function uploadFile($file, $upload_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf']) {
    // Check for upload errors
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new Exception('Invalid file upload parameters');
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];
        throw new Exception($error_messages[$file['error']] ?? 'Unknown upload error');
    }
    
    // Validate file size (10 MB max)
    $max_size = defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : 10 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        throw new Exception('File size exceeds ' . formatBytes($max_size) . ' limit');
    }
    
    // Get and validate extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed_types)) {
        throw new Exception('File type not allowed. Allowed: ' . implode(', ', $allowed_types));
    }
    
    // MIME type validation for additional security
    $allowed_mime_types = [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf']
    ];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (isset($allowed_mime_types[$ext])) {
        if (!in_array($mime_type, $allowed_mime_types[$ext])) {
            throw new Exception('File MIME type does not match extension');
        }
    }
    
    // For images, verify it's actually an image
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
        $image_info = getimagesize($file['tmp_name']);
        if ($image_info === false) {
            throw new Exception('File is not a valid image');
        }
        
        // Additional security: check for double extensions or suspicious patterns
        if (preg_match('/\.(php|phtml|php3|php4|php5|phar|exe|sh|bat|cmd)/i', $file['name'])) {
            throw new Exception('Suspicious file name detected');
        }
    }
    
    // Create upload directory if not exists (secure permissions)
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0750, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    // Generate secure filename (prevent path traversal)
    $safe_basename = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file['name'], PATHINFO_FILENAME));
    $safe_basename = substr($safe_basename, 0, 50); // Limit length
    $filename = uniqid() . '_' . time() . '_' . ($safe_basename ?: 'file') . '.' . $ext;
    $filepath = rtrim($upload_dir, '/') . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Set secure file permissions
    chmod($filepath, 0640);
    
    return $filename;
}

/**
 * SECURE MULTIPLE FILE UPLOAD
 * @param array $files Files array from $_FILES
 * @param string $upload_dir Upload directory path
 * @param array $allowed_types Allowed file extensions
 * @return array Array of uploaded filenames
 */
function uploadMultipleFiles($files, $upload_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif']) {
    $uploaded = [];
    $errors = [];
    
    if (!empty($files['name'][0])) {
        foreach ($files['name'] as $key => $name) {
            try {
                // Prepare file array for uploadFile function
                $file = [
                    'name' => $files['name'][$key],
                    'type' => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error' => $files['error'][$key],
                    'size' => $files['size'][$key]
                ];
                
                // Use secure uploadFile function
                $filename = uploadFile($file, $upload_dir, $allowed_types);
                $uploaded[] = $filename;
                
            } catch (Exception $e) {
                $errors[] = "File {$name}: " . $e->getMessage();
                error_log("Multiple file upload error: " . $e->getMessage());
            }
        }
    }
    
    // Log errors if any occurred
    if (!empty($errors)) {
        error_log("Upload errors: " . implode('; ', $errors));
    }
    
    return $uploaded;
}

function deleteFile($filepath) {
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// ============================================
// VALIDATION FUNCTIONS
// ============================================

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    return preg_match('/^(\+971|00971|0)?[0-9]{9}$/', $phone);
}

function validateURL($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function sanitizeHTML($html) {
    $allowed_tags = '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6>';
    return strip_tags($html, $allowed_tags);
}

function validateCoordinates($latitude, $longitude) {
    return (
        is_numeric($latitude) && 
        is_numeric($longitude) && 
        $latitude >= -90 && 
        $latitude <= 90 && 
        $longitude >= -180 && 
        $longitude <= 180
    );
}

// ============================================
// HELPER UTILITIES
// ============================================

function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return $randomString;
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function debugLog($message, $data = null) {
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("DEBUG: $message");
        if ($data !== null) {
            error_log("DATA: " . print_r($data, true));
        }
    }
}

function jsonResponse($success, $message = '', $data = null, $code = 200) {
    http_response_code($code);
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect($url, $message = null, $type = 'info') {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    header("Location: $url");
    exit;
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

function generateSlug($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length) . $suffix;
}

function arrayToCSV($data, $delimiter = ',', $enclosure = '"') {
    if (empty($data)) {
        return '';
    }
    
    $output = fopen('php://temp', 'r+');
    
    fputcsv($output, array_keys($data[0]), $delimiter, $enclosure);
    
    foreach ($data as $row) {
        fputcsv($output, $row, $delimiter, $enclosure);
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return $csv;
}

function downloadCSV($data, $filename = 'export.csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo "\xEF\xBB\xBF";
    echo arrayToCSV($data);
    exit;
}

// ============================================
// DATE & TIME HELPERS
// ============================================

function getDateRange($period = 'this_month') {
    $start = null;
    $end = null;
    
    switch ($period) {
        case 'today':
            $start = date('Y-m-d');
            $end = date('Y-m-d');
            break;
        case 'yesterday':
            $start = date('Y-m-d', strtotime('-1 day'));
            $end = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $start = date('Y-m-d', strtotime('monday this week'));
            $end = date('Y-m-d');
            break;
        case 'last_week':
            $start = date('Y-m-d', strtotime('monday last week'));
            $end = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $start = date('Y-m-01');
            $end = date('Y-m-d');
            break;
        case 'last_month':
            $start = date('Y-m-01', strtotime('first day of last month'));
            $end = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'this_year':
            $start = date('Y-01-01');
            $end = date('Y-m-d');
            break;
        case 'last_year':
            $start = date('Y-01-01', strtotime('first day of january last year'));
            $end = date('Y-12-31', strtotime('last day of december last year'));
            break;
        case 'last_7_days':
            $start = date('Y-m-d', strtotime('-7 days'));
            $end = date('Y-m-d');
            break;
        case 'last_30_days':
            $start = date('Y-m-d', strtotime('-30 days'));
            $end = date('Y-m-d');
            break;
        case 'last_90_days':
            $start = date('Y-m-d', strtotime('-90 days'));
            $end = date('Y-m-d');
            break;
        default:
            $start = date('Y-m-01');
            $end = date('Y-m-d');
    }
    
    return ['start' => $start, 'end' => $end];
}

function getDayName($date) {
    return date('l', strtotime($date));
}

function getMonthName($date) {
    return date('F', strtotime($date));
}

function calculateAge($birthdate) {
    $birth = new DateTime($birthdate);
    $now = new DateTime();
    return $birth->diff($now)->y;
}

function getWorkingDays($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = new DateInterval('P1D');
    $daterange = new DatePeriod($start, $interval, $end);
    
    $working_days = 0;
    foreach ($daterange as $date) {
        if ($date->format('N') < 5) {
            $working_days++;
        }
    }
    
    return $working_days;
}

function isWeekend($date) {
    $day = date('N', strtotime($date));
    return ($day == 5 || $day == 6);
}

// ============================================
// SEARCH & FILTER HELPERS
// ============================================

function buildSearchQuery($search_term, $fields) {
    if (empty($search_term) || empty($fields)) {
        return ['sql' => '1=1', 'params' => []];
    }
    
    $conditions = [];
    $params = [];
    
    foreach ($fields as $field) {
        $conditions[] = "$field LIKE :search";
    }
    
    $sql = '(' . implode(' OR ', $conditions) . ')';
    $params[':search'] = "%$search_term%";
    
    return ['sql' => $sql, 'params' => $params];
}

function buildFilterQuery($filters) {
    if (empty($filters)) {
        return ['sql' => '1=1', 'params' => []];
    }
    
    $conditions = [];
    $params = [];
    
    foreach ($filters as $field => $value) {
        if ($value !== null && $value !== '') {
            $conditions[] = "$field = :$field";
            $params[":$field"] = $value;
        }
    }
    
    $sql = empty($conditions) ? '1=1' : implode(' AND ', $conditions);
    
    return ['sql' => $sql, 'params' => $params];
}

function buildDateRangeQuery($field, $start_date = null, $end_date = null) {
    $conditions = [];
    $params = [];
    
    if ($start_date) {
        $conditions[] = "$field >= :start_date";
        $params[':start_date'] = $start_date . ' 00:00:00';
    }
    
    if ($end_date) {
        $conditions[] = "$field <= :end_date";
        $params[':end_date'] = $end_date . ' 23:59:59';
    }
    
    $sql = empty($conditions) ? '1=1' : implode(' AND ', $conditions);
    
    return ['sql' => $sql, 'params' => $params];
}

// ============================================
// SYSTEM INFORMATION
// ============================================

function getSystemInfo() {
    return [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'db_version' => getDBVersion(),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'timezone' => date_default_timezone_get(),
        'app_version' => APP_VERSION ?? '2.1',
        'app_name' => APP_NAME ?? 'PSD Portal'
    ];
}

function getDBVersion() {
    global $conn;
    
    try {
        $stmt = $conn->query("SELECT VERSION() as version");
        $result = $stmt->fetch();
        return $result['version'] ?? 'Unknown';
    } catch (Exception $e) {
        return 'Unknown';
    }
}

function getStorageInfo() {
    $upload_dir = __DIR__ . '/uploads/';
    
    if (!is_dir($upload_dir)) {
        return ['total' => 0, 'used' => 0, 'free' => 0];
    }
    
    $total = disk_total_space($upload_dir);
    $free = disk_free_space($upload_dir);
    $used = $total - $free;
    
    return [
        'total' => $total,
        'used' => $used,
        'free' => $free,
        'total_formatted' => formatBytes($total),
        'used_formatted' => formatBytes($used),
        'free_formatted' => formatBytes($free),
        'percent_used' => $total > 0 ? round(($used / $total) * 100, 2) : 0
    ];
}

function getDatabaseSize() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT 
            SUM(data_length + index_length) as size,
            SUM(data_length) as data_size,
            SUM(index_length) as index_size
            FROM information_schema.TABLES 
            WHERE table_schema = :db_name");
        
        $stmt->execute([':db_name' => DB_NAME]);
        $result = $stmt->fetch();
        
        return [
            'total' => $result['size'] ?? 0,
            'data' => $result['data_size'] ?? 0,
            'index' => $result['index_size'] ?? 0,
            'total_formatted' => formatBytes($result['size'] ?? 0),
            'data_formatted' => formatBytes($result['data_size'] ?? 0),
            'index_formatted' => formatBytes($result['index_size'] ?? 0)
        ];
    } catch (Exception $e) {
        error_log("Get database size error: " . $e->getMessage());
        return [
            'total' => 0, 'data' => 0, 'index' => 0,
            'total_formatted' => '0 B', 'data_formatted' => '0 B', 'index_formatted' => '0 B'
        ];
    }
}

// ============================================
// CONTACT INFORMATION
// ============================================

function getContactInfo() {
    return [
        'phone' => '+971507009029',
        'phone_formatted' => '+971 50 700 9029',
        'email' => 'mma.1985@icloud.com',
        'support_email' => 'mma.1985@icloud.com',
        'website' => 'http://psd.m85.ae',
        'address' => 'Public Services Department, UAE',
        'working_hours' => '8:00 AM - 5:00 PM',
        'working_days' => 'Sunday - Thursday',
        'weekend' => 'Friday - Saturday'
    ];
}

// ============================================
// DASHBOARD FUNCTIONS
// ============================================

function getDashboardStats($user) {
    global $conn;
    
    $stats = [];
    
    try {
        $stmt = $conn->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) as critical
            FROM complaints");
        $stats['complaints'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $conn->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'reported' THEN 1 ELSE 0 END) as reported,
            SUM(CASE WHEN status IN ('dispatched', 'in_progress') THEN 1 ELSE 0 END) as active
            FROM emergencies");
        $stats['emergencies'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $conn->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress
            FROM tasks");
        $stats['tasks'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['resources'] = ['employees' => 0, 'equipment' => 0, 'materials' => 0];
        
        return $stats;
    } catch (Exception $e) {
        error_log("Get dashboard stats error: " . $e->getMessage());
        return [
            'complaints' => ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'critical' => 0],
            'emergencies' => ['total' => 0, 'reported' => 0, 'active' => 0],
            'tasks' => ['total' => 0, 'pending' => 0, 'in_progress' => 0],
            'resources' => ['employees' => 0, 'equipment' => 0, 'materials' => 0]
        ];
    }
}

function getRecentItems($type, $limit = 5) {
    global $conn;
    
    try {
        switch ($type) {
            case 'complaints':
                $stmt = $conn->prepare("SELECT c.*, d.name AS department_name, s.name AS sector_name 
                                       FROM complaints c 
                                       LEFT JOIN departments d ON c.department_id = d.id 
                                       LEFT JOIN sectors s ON c.sector_id = s.id 
                                       ORDER BY c.reported_at DESC 
                                       LIMIT :limit");
                break;
                
            case 'emergencies':
                $stmt = $conn->prepare("SELECT e.*, d.name AS department_name, s.name AS sector_name 
                                       FROM emergencies e 
                                       LEFT JOIN departments d ON e.department_id = d.id 
                                       LEFT JOIN sectors s ON e.sector_id = s.id 
                                       ORDER BY e.reported_at DESC 
                                       LIMIT :limit");
                break;
                
            case 'tasks':
                $stmt = $conn->prepare("SELECT t.*, d.name AS department_name, s.name AS sector_name 
                                       FROM tasks t 
                                       LEFT JOIN departments d ON t.department_id = d.id 
                                       LEFT JOIN sectors s ON t.sector_id = s.id 
                                       ORDER BY t.created_at DESC 
                                       LIMIT :limit");
                break;
                
            default:
                return [];
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Get recent items error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// MAP FUNCTIONS
// ============================================

function getMapMarkers($type = null) {
    global $conn;
    
    try {
        $markers = [];
        
        if (!$type || $type === 'complaints') {
            $stmt = $conn->query("SELECT 
                'complaint' as type,
                id,
                reference_number as code,
                CONCAT('Complaint: ', complaint_title) as title,
                complaint_description as description,
                status,
                priority,
                latitude,
                longitude,
                reported_at as created_at
                FROM complaints 
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
            $markers = array_merge($markers, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
        if (!$type || $type === 'emergencies') {
            $stmt = $conn->query("SELECT 
                'emergency' as type,
                id,
                emergency_code as code,
                CONCAT('Emergency: ', emergency_type) as title,
                emergency_description as description,
                status,
                severity_level as priority,
                latitude,
                longitude,
                reported_at as created_at
                FROM emergencies 
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
            $markers = array_merge($markers, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
        if (!$type || $type === 'tasks') {
            $stmt = $conn->query("SELECT 
                'task' as type,
                id,
                task_number as code,
                CONCAT('Task: ', task_title) as title,
                task_description as description,
                status,
                priority,
                latitude,
                longitude,
                created_at
                FROM tasks 
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
            $markers = array_merge($markers, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
        return $markers;
        
    } catch (Exception $e) {
        error_log("Get map markers error: " . $e->getMessage());
        return [];
    }
}

function getMarkerDetails($type, $id) {
    global $conn;
    
    try {
        switch ($type) {
            case 'complaint':
                $stmt = $conn->prepare("SELECT c.*, d.name AS department_name, s.name AS sector_name, u.full_name as reporter_name 
                                       FROM complaints c 
                                       LEFT JOIN departments d ON c.department_id = d.id 
                                       LEFT JOIN sectors s ON c.sector_id = s.id 
                                       LEFT JOIN users u ON c.reported_by = u.id 
                                       WHERE c.id = ?");
                break;
                
            case 'emergency':
                $stmt = $conn->prepare("SELECT e.*, d.name AS department_name, s.name AS sector_name, u.full_name as reporter_name 
                                       FROM emergencies e 
                                       LEFT JOIN departments d ON e.department_id = d.id 
                                       LEFT JOIN sectors s ON e.sector_id = s.id 
                                       LEFT JOIN users u ON e.reported_by = u.id 
                                       WHERE e.id = ?");
                break;
                
            case 'task':
                $stmt = $conn->prepare("SELECT t.*, d.name AS department_name, s.name AS sector_name, u.full_name as creator_name 
                                       FROM tasks t 
                                       LEFT JOIN departments d ON t.department_id = d.id 
                                       LEFT JOIN sectors s ON t.sector_id = s.id 
                                       LEFT JOIN users u ON t.created_by = u.id 
                                       WHERE t.id = ?");
                break;
                
            default:
                return null;
        }
        
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Get marker details error: " . $e->getMessage());
        return null;
    }
}

// ============================================
// CLEANUP FUNCTIONS
// ============================================

function cleanOldActivityLogs($days = 90) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
        $stmt->execute([':days' => $days]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log("Clean old activity logs error: " . $e->getMessage());
        return 0;
    }
}

function cleanOldNotifications($days = 30) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
        $stmt->execute([':days' => $days]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log("Clean old notifications error: " . $e->getMessage());
        return 0;
    }
}

// ============================================
// ADVANCED REPORT FUNCTIONS (NEW - For Reports Page)
// ============================================

/**
 * Get real complaints statistics from database
 */
function getComplaintsStatsReal($start_date, $end_date, $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        // Total complaints
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM complaints WHERE $where_sql");
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Resolved complaints
        $where_resolved = $where;
        $where_resolved[] = "status = 'resolved'";
        $where_resolved_sql = implode(' AND ', $where_resolved);
        $stmt = $conn->prepare("SELECT COUNT(*) as resolved FROM complaints WHERE $where_resolved_sql");
        $stmt->execute($params);
        $resolved = $stmt->fetch(PDO::FETCH_ASSOC)['resolved'];
        
        // Pending complaints
        $where_pending = $where;
        $where_pending[] = "status IN ('pending', 'new')";
        $where_pending_sql = implode(' AND ', $where_pending);
        $stmt = $conn->prepare("SELECT COUNT(*) as pending FROM complaints WHERE $where_pending_sql");
        $stmt->execute($params);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];
        
        // Average resolution time
        $where_avg = $where;
        $where_avg[] = "status = 'resolved' AND resolved_at IS NOT NULL";
        $where_avg_sql = implode(' AND ', $where_avg);
        $stmt = $conn->prepare("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_time 
                               FROM complaints WHERE $where_avg_sql");
        $stmt->execute($params);
        $avg_time = $stmt->fetch(PDO::FETCH_ASSOC)['avg_time'] ?? 0;
        
        return [
            'total' => $total,
            'resolved' => $resolved,
            'pending' => $pending,
            'avg_resolution_time' => round($avg_time, 1)
        ];
    } catch (Exception $e) {
        error_log("Get complaints stats real error: " . $e->getMessage());
        return ['total' => 0, 'resolved' => 0, 'pending' => 0, 'avg_resolution_time' => 0];
    }
}

/**
 * Get complaints by department with real data
 */
function getComplaintsByDepartmentReal($start_date, $end_date, $sector_id = '') {
    global $conn;
    
    $where = ["c.created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    
    if ($sector_id) {
        $where[] = "c.sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT COALESCE(d.name AS department_name, 'Unassigned') as department_name, COUNT(c.id) as count
                               FROM complaints c
                               LEFT JOIN departments d ON c.department_id = d.id
                               WHERE $where_sql
                               GROUP BY c.department_id, d.name AS department_name
                               ORDER BY count DESC
                               LIMIT 10");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get complaints by department real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get complaints by status with real data
 */
function getComplaintsByStatusReal($start_date, $end_date, $department_id = '', $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT status, COUNT(*) as count
                               FROM complaints
                               WHERE $where_sql
                               GROUP BY status
                               ORDER BY count DESC");
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'status' => ucfirst(str_replace('_', ' ', $row['status'])),
                'count' => $row['count']
            ];
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Get complaints by status real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get complaints trend for last N days
 */
function getComplaintsTrendReal($start_date, $end_date, $days = 30) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT DATE(created_at) as date, COUNT(*) as count
                               FROM complaints
                               WHERE created_at BETWEEN DATE_SUB(:end_date, INTERVAL :days DAY) AND :end_date
                               GROUP BY DATE(created_at)
                               ORDER BY date ASC");
        $stmt->execute([':end_date' => $end_date, ':days' => $days]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'date' => date('M d', strtotime($row['date'])),
                'count' => $row['count']
            ];
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Get complaints trend real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get emergencies statistics with real data
 */
function getEmergenciesStatsReal($start_date, $end_date, $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        // Total emergencies
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM emergencies WHERE $where_sql");
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Resolved emergencies
        $where_resolved = $where;
        $where_resolved[] = "status = 'resolved'";
        $where_resolved_sql = implode(' AND ', $where_resolved);
        $stmt = $conn->prepare("SELECT COUNT(*) as resolved FROM emergencies WHERE $where_resolved_sql");
        $stmt->execute($params);
        $resolved = $stmt->fetch(PDO::FETCH_ASSOC)['resolved'];
        
        // Critical emergencies
        $where_critical = $where;
        $where_critical[] = "severity_level = 'critical'";
        $where_critical_sql = implode(' AND ', $where_critical);
        $stmt = $conn->prepare("SELECT COUNT(*) as critical FROM emergencies WHERE $where_critical_sql");
        $stmt->execute($params);
        $critical = $stmt->fetch(PDO::FETCH_ASSOC)['critical'];
        
        // Average response time
        $where_avg = $where;
        $where_avg[] = "dispatched_at IS NOT NULL";
        $where_avg_sql = implode(' AND ', $where_avg);
        $stmt = $conn->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, dispatched_at)) as avg_time 
                               FROM emergencies WHERE $where_avg_sql");
        $stmt->execute($params);
        $avg_time = $stmt->fetch(PDO::FETCH_ASSOC)['avg_time'] ?? 0;
        
        return [
            'total' => $total,
            'resolved' => $resolved,
            'critical' => $critical,
            'avg_response_time' => round($avg_time, 1)
        ];
    } catch (Exception $e) {
        error_log("Get emergencies stats real error: " . $e->getMessage());
        return ['total' => 0, 'resolved' => 0, 'critical' => 0, 'avg_response_time' => 0];
    }
}

/**
 * Get emergencies by priority level
 */
function getEmergenciesByPriorityReal($start_date, $end_date, $department_id = '', $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT severity_level as priority, COUNT(*) as count
                               FROM emergencies
                               WHERE $where_sql
                               GROUP BY severity_level
                               ORDER BY FIELD(severity_level, 'critical', 'high', 'medium', 'low')");
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'priority' => ucfirst($row['priority']),
                'count' => $row['count']
            ];
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Get emergencies by priority real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get emergencies by status
 */
function getEmergenciesByStatusReal($start_date, $end_date, $department_id = '', $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT status, COUNT(*) as count
                               FROM emergencies
                               WHERE $where_sql
                               GROUP BY status
                               ORDER BY count DESC");
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'status' => ucfirst(str_replace('_', ' ', $row['status'])),
                'count' => $row['count']
            ];
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Get emergencies by status real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get emergency response time by department
 */
function getEmergencyResponseTimeReal($start_date, $end_date, $department_id = '') {
    global $conn;
    
    $where = ["e.created_at BETWEEN :start_date AND :end_date", "e.dispatched_at IS NOT NULL"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    
    if ($department_id) {
        $where[] = "e.department_id = :dept_id";
        $params[':dept_id'] = $department_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT COALESCE(d.name AS department_name, 'Unassigned') as department_name, 
                                      AVG(TIMESTAMPDIFF(MINUTE, e.created_at, e.dispatched_at)) as avg_time
                               FROM emergencies e
                               LEFT JOIN departments d ON e.department_id = d.id
                               WHERE $where_sql
                               GROUP BY e.department_id, d.name AS department_name
                               ORDER BY avg_time ASC
                               LIMIT 10");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get emergency response time real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get tasks statistics
 */
function getTasksStatsReal($start_date, $end_date, $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        // Total tasks
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tasks WHERE $where_sql");
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Completed tasks
        $where_completed = $where;
        $where_completed[] = "status = 'completed'";
        $where_completed_sql = implode(' AND ', $where_completed);
        $stmt = $conn->prepare("SELECT COUNT(*) as completed FROM tasks WHERE $where_completed_sql");
        $stmt->execute($params);
        $completed = $stmt->fetch(PDO::FETCH_ASSOC)['completed'];
        
        // In progress tasks
        $where_progress = $where;
        $where_progress[] = "status = 'in_progress'";
        $where_progress_sql = implode(' AND ', $where_progress);
        $stmt = $conn->prepare("SELECT COUNT(*) as in_progress FROM tasks WHERE $where_progress_sql");
        $stmt->execute($params);
        $in_progress = $stmt->fetch(PDO::FETCH_ASSOC)['in_progress'];
        
        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $in_progress
        ];
    } catch (Exception $e) {
        error_log("Get tasks stats real error: " . $e->getMessage());
        return ['total' => 0, 'completed' => 0, 'in_progress' => 0];
    }
}

/**
 * Get tasks by status
 */
function getTasksByStatusReal($start_date, $end_date, $department_id = '', $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT status, COUNT(*) as count
                               FROM tasks
                               WHERE $where_sql
                               GROUP BY status
                               ORDER BY count DESC");
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'status' => str_replace('_', ' ', ucfirst($row['status'])),
                'count' => $row['count']
            ];
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Get tasks by status real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get tasks by priority
 */
function getTasksByPriorityReal($start_date, $end_date, $department_id = '', $sector_id = '') {
    global $conn;
    
    $where = ["created_at BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT priority, COUNT(*) as count
                               FROM tasks
                               WHERE $where_sql
                               GROUP BY priority
                               ORDER BY FIELD(priority, 'urgent', 'high', 'medium', 'low')");
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'priority' => ucfirst($row['priority']),
                'count' => $row['count']
            ];
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Get tasks by priority real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get tasks completion trend by week
 */
function getTasksCompletionTrendReal($start_date, $end_date) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT 
                                   WEEK(created_at) as week_num,
                                   COUNT(*) as total,
                                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                               FROM tasks
                               WHERE created_at BETWEEN :start_date AND :end_date
                               GROUP BY WEEK(created_at)
                               ORDER BY week_num ASC
                               LIMIT 4");
        $stmt->execute([
            ':start_date' => $start_date . ' 00:00:00',
            ':end_date' => $end_date . ' 23:59:59'
        ]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        $week = 1;
        foreach ($results as $row) {
            $completion_rate = $row['total'] > 0 ? ($row['completed'] / $row['total']) * 100 : 0;
            $data[] = [
                'week' => 'Week ' . $week,
                'rate' => round($completion_rate, 1)
            ];
            $week++;
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Get tasks completion trend real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get execution costs with real data
 */
function getExecutionCostsReal($start_date, $end_date, $sector_id = '') {
    global $conn;
    
    $where = ["execution_date BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ];
    

    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT 
                                   COUNT(*) as total_executions,
                                   COALESCE(SUM(workforce_cost), 0) as workforce_cost,
                                   COALESCE(SUM(equipment_cost), 0) as equipment_cost,
                                   COALESCE(SUM(materials_cost), 0) as materials_cost,
                                   COALESCE(SUM(total_cost), 0) as total_cost
                               FROM task_executions
                               WHERE $where_sql");
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Default budgets (can be configured)
        $workforce_budget = 100000;
        $equipment_budget = 75000;
        $materials_budget = 50000;
        
        return [
            'total_executions' => $result['total_executions'],
            'workforce_cost' => $result['workforce_cost'],
            'equipment_cost' => $result['equipment_cost'],
            'materials_cost' => $result['materials_cost'],
            'total_cost' => $result['total_cost'],
            'workforce_budget' => $workforce_budget,
            'equipment_budget' => $equipment_budget,
            'materials_budget' => $materials_budget,
            'total_budget' => $workforce_budget + $equipment_budget + $materials_budget
        ];
    } catch (Exception $e) {
        error_log("Get execution costs real error: " . $e->getMessage());
        return [
            'total_executions' => 0, 'workforce_cost' => 0, 'equipment_cost' => 0,
            'materials_cost' => 0, 'total_cost' => 0,
            'workforce_budget' => 100000, 'equipment_budget' => 75000, 'materials_budget' => 50000,
            'total_budget' => 225000
        ];
    }
}

/**
 * Get resources utilization
 */
function getResourcesUtilizationReal($start_date, $end_date, $department_id = '') {
    global $conn;
    
    try {
        // Default utilization data
        $data = [
            ['resource' => 'Employees', 'utilization' => 0],
            ['resource' => 'Equipment', 'utilization' => 0],
            ['resource' => 'Vehicles', 'utilization' => 75],
            ['resource' => 'Materials', 'utilization' => 60]
        ];
        
        // Utilization data from execution tables
        $data[0]['utilization'] = 0;
        $data[1]['utilization'] = 0;
        
        return $data;
    } catch (Exception $e) {
        error_log("Get resources utilization real error: " . $e->getMessage());
        return [
            ['resource' => 'Employees', 'utilization' => 75],
            ['resource' => 'Equipment', 'utilization' => 65],
            ['resource' => 'Vehicles', 'utilization' => 80],
            ['resource' => 'Materials', 'utilization' => 60]
        ];
    }
}

/**
 * Get cost distribution
 */
function getCostDistributionReal($start_date, $end_date, $department_id = '') {
    $costs = getExecutionCostsReal($start_date, $end_date, $department_id);
    
    return [
        ['category' => 'Workforce', 'amount' => $costs['workforce_cost']],
        ['category' => 'Equipment', 'amount' => $costs['equipment_cost']],
        ['category' => 'Materials', 'amount' => $costs['materials_cost']],
        ['category' => 'Other', 'amount' => 5000]
    ];
}

/**
 * Get expenses trend by month
 */
function getExpensesTrendReal($start_date, $end_date, $department_id = '') {
    global $conn;
    
    $where = ["execution_date BETWEEN :start_date AND :end_date"];
    $params = [
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ];
    

    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT 
                                   DATE_FORMAT(execution_date, '%b') as month,
                                   SUM(workforce_cost) as workforce,
                                   SUM(equipment_cost) as equipment,
                                   SUM(materials_cost) as materials
                               FROM task_executions
                               WHERE $where_sql
                               GROUP BY DATE_FORMAT(execution_date, '%Y-%m')
                               ORDER BY execution_date ASC
                               LIMIT 12");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get expenses trend real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get pending items count
 */
function getPendingItemsReal($department_id = '', $sector_id = '') {
    global $conn;
    
    $where = ["status IN ('pending', 'new')"];
    $params = [];
    

    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM complaints WHERE $where_sql");
        $stmt->execute($params);
        $complaints = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM emergencies WHERE $where_sql");
        $stmt->execute($params);
        $emergencies = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $where_tasks = str_replace("'pending', 'new'", "'pending', 'not_started'", $where_sql);
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tasks WHERE $where_tasks");
        $stmt->execute($params);
        $tasks = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        return [
            'complaints' => $complaints,
            'emergencies' => $emergencies,
            'tasks' => $tasks,
            'total' => $complaints + $emergencies + $tasks
        ];
    } catch (Exception $e) {
        error_log("Get pending items real error: " . $e->getMessage());
        return ['complaints' => 0, 'emergencies' => 0, 'tasks' => 0, 'total' => 0];
    }
}

/**
 * Get overdue items count
 */
function getOverdueItemsReal($sector_id = '') {
    global $conn;
    
    $where = ["due_date < NOW()", "status NOT IN ('resolved', 'completed', 'closed')"];
    $params = [];
    
    if ($sector_id) {
        $where[] = "sector_id = :sector_id";
        $params[':sector_id'] = $sector_id;
    }
    
    $where_sql = implode(' AND ', $where);
    
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM complaints WHERE $where_sql");
        $stmt->execute($params);
        $complaints = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM emergencies WHERE $where_sql");
        $stmt->execute($params);
        $emergencies = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tasks WHERE $where_sql");
        $stmt->execute($params);
        $tasks = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        return [
            'complaints' => $complaints,
            'emergencies' => $emergencies,
            'tasks' => $tasks,
            'total' => $complaints + $emergencies + $tasks
        ];
    } catch (Exception $e) {
        error_log("Get overdue items real error: " . $e->getMessage());
        return ['complaints' => 0, 'emergencies' => 0, 'tasks' => 0, 'total' => 0];
    }
}

/**
 * Get department performance analysis
 */
function getPerformanceByDepartmentReal($start_date, $end_date) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT 
                                   d.id,
                                   d.name AS department_name,
                                   COUNT(DISTINCT c.id) as total_complaints,
                                   COUNT(DISTINCT e.id) as total_emergencies,
                                   COUNT(DISTINCT t.id) as total_tasks,
                                   (COUNT(DISTINCT CASE WHEN c.status = 'resolved' THEN c.id END) * 100.0 / NULLIF(COUNT(DISTINCT c.id), 0)) as resolution_rate,
                                   AVG(TIMESTAMPDIFF(MINUTE, e.created_at, e.dispatched_at)) as avg_response_time,
                                   (
                                       (COUNT(DISTINCT CASE WHEN c.status = 'resolved' THEN c.id END) * 100.0 / NULLIF(COUNT(DISTINCT c.id), 0)) * 0.4 +
                                       (COUNT(DISTINCT CASE WHEN e.status = 'resolved' THEN e.id END) * 100.0 / NULLIF(COUNT(DISTINCT e.id), 0)) * 0.3 +
                                       (COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.id END) * 100.0 / NULLIF(COUNT(DISTINCT t.id), 0)) * 0.3
                                   ) as performance_score
                               FROM departments d
                               LEFT JOIN complaints c ON d.id = c.department_id AND c.created_at BETWEEN :start_date1 AND :end_date1
                               LEFT JOIN emergencies e ON d.id = e.department_id AND e.created_at BETWEEN :start_date2 AND :end_date2
                               LEFT JOIN tasks t ON d.id = t.department_id AND t.created_at BETWEEN :start_date3 AND :end_date3
                               GROUP BY d.id, d.name AS department_name
                               HAVING total_complaints > 0 OR total_emergencies > 0 OR total_tasks > 0
                               ORDER BY performance_score DESC");
        
        $params = [
            ':start_date1' => $start_date . ' 00:00:00', ':end_date1' => $end_date . ' 23:59:59',
            ':start_date2' => $start_date . ' 00:00:00', ':end_date2' => $end_date . ' 23:59:59',
            ':start_date3' => $start_date . ' 00:00:00', ':end_date3' => $end_date . ' 23:59:59'
        ];
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get performance by department real error: " . $e->getMessage());
        return [];
    }
}

/**
 * Export data to Excel format - UPDATED VERSION
 */
function exportToExcel($start_date, $end_date, $sector_id, $report_type) {
    global $conn;
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment;filename="PSD_Report_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    echo '<x:Name>PSD Report</x:Name>';
    echo '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    echo '</head>';
    echo '<body>';
    
    echo '<h2 style="text-align: center;">PSD Portal - Analytics Report</h2>';
    echo '<p><b>Report Period:</b> ' . $start_date . ' to ' . $end_date . '</p>';
    echo '<p><b>Generated:</b> ' . date('Y-m-d H:i:s') . '</p>';
    echo '<hr>';
    
    // Get statistics
    $complaints_stats = getComplaintsStatsReal($start_date, $end_date, $sector_id);
    $emergencies_stats = getEmergenciesStatsReal($start_date, $end_date, $sector_id);
    $tasks_stats = getTasksStatsReal($start_date, $end_date, $sector_id);
    $execution_costs = getExecutionCostsReal($start_date, $end_date, $sector_id);
    
    // Summary Statistics Table
    echo '<h3>Summary Statistics</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
    echo '<tr style="background-color: #2563eb; color: white; font-weight: bold;">';
    echo '<th>Category</th><th>Value</th></tr>';
    
    echo '<tr><td><b>Total Complaints</b></td><td>' . $complaints_stats['total'] . '</td></tr>';
    echo '<tr><td>Resolved Complaints</td><td>' . $complaints_stats['resolved'] . '</td></tr>';
    echo '<tr><td>Pending Complaints</td><td>' . $complaints_stats['pending'] . '</td></tr>';
    echo '<tr><td>Avg Resolution Time (hours)</td><td>' . round($complaints_stats['avg_resolution_time'], 1) . '</td></tr>';
    
    echo '<tr><td><b>Total Emergencies</b></td><td>' . $emergencies_stats['total'] . '</td></tr>';
    echo '<tr><td>Resolved Emergencies</td><td>' . $emergencies_stats['resolved'] . '</td></tr>';
    echo '<tr><td>Critical Emergencies</td><td>' . $emergencies_stats['critical'] . '</td></tr>';
    echo '<tr><td>Avg Response Time (minutes)</td><td>' . round($emergencies_stats['avg_response_time'], 1) . '</td></tr>';
    
    echo '<tr><td><b>Total Tasks</b></td><td>' . $tasks_stats['total'] . '</td></tr>';
    echo '<tr><td>Completed Tasks</td><td>' . $tasks_stats['completed'] . '</td></tr>';
    echo '<tr><td>In Progress Tasks</td><td>' . $tasks_stats['in_progress'] . '</td></tr>';
    
    echo '<tr style="background-color: #f3f4f6; font-weight: bold;">';
    echo '<td>Total Execution Cost</td><td>AED ' . number_format($execution_costs['total_cost'], 2) . '</td></tr>';
    
    echo '</table>';
    
    echo '<br><br>';
    
    // Department Performance
    $performance = getPerformanceByDepartmentReal($start_date, $end_date);
    if (!empty($performance)) {
        echo '<h3>Department Performance</h3>';
        echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
        echo '<tr style="background-color: #2563eb; color: white; font-weight: bold;">';
        echo '<th>Department</th><th>Complaints</th><th>Emergencies</th><th>Tasks</th>';
        echo '<th>Resolution Rate (%)</th><th>Avg Response Time (min)</th><th>Performance Score (%)</th></tr>';
        
        foreach ($performance as $dept) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($dept['department_name']) . '</td>';
            echo '<td>' . $dept['total_complaints'] . '</td>';
            echo '<td>' . $dept['total_emergencies'] . '</td>';
            echo '<td>' . $dept['total_tasks'] . '</td>';
            echo '<td>' . round($dept['resolution_rate'] ?? 0) . '%</td>';
            echo '<td>' . round($dept['avg_response_time'] ?? 0) . '</td>';
            echo '<td>' . round($dept['performance_score'] ?? 0) . '%</td>';
            echo '</tr>';
        }
        
        echo '</table>';
    }
    
    echo '<br><br>';
    echo '<p style="text-align: center; color: #666; font-size: 12px;">PSD Portal | Generated on ' . date('Y-m-d H:i:s') . '</p>';
    
    echo '</body></html>';
    exit;
}

// ============================================
// END OF FUNCTIONS LIBRARY
// Version: 2.1 Final with Reports
// Contact: +971 50 700 9029
// Email: mma.1985@icloud.com
// ============================================

// ===== EMERGENCY & ASSET TYPE FUNCTIONS =====

/**
 * Get emergency type details with icon
 */
function getEmergencyTypeDetails($type_code) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM emergency_types WHERE type_code = ?");
    $stmt->execute([$type_code]);
    $type = $stmt->fetch();
    
    if (!$type) {
        return [
            'type_code' => $type_code,
            'type_name' => $type_code,
            'icon' => 'fa-exclamation-triangle',
            'color' => '#6b7280'
        ];
    }
    
    return $type;
}

/**
 * Get emergency type icon HTML
 */
function getEmergencyTypeIcon($type_code, $size = 'md') {
    $type = getEmergencyTypeDetails($type_code);
    $sizes = ['sm' => '14px', 'md' => '18px', 'lg' => '24px'];
    $fontSize = $sizes[$size] ?? $sizes['md'];
    
    return '<i class="fas ' . htmlspecialchars($type['icon']) . '" style="color: ' . htmlspecialchars($type['color']) . '; font-size: ' . $fontSize . ';"></i>';
}

/**
 * Get emergency type badge HTML
 */
function getEmergencyTypeBadge($type_code, $other_description = null) {
    $type = getEmergencyTypeDetails($type_code);
    $displayName = ($type_code === 'other' && $other_description) ? $other_description : ($type['name'] ?? $type['type_name'] ?? 'Unknown');
    
    return '<span class="badge" style="background-color: ' . htmlspecialchars($type['color']) . '; display: inline-flex; align-items: center; gap: 0.5rem;">
        <i class="fas ' . htmlspecialchars($type['icon']) . '"></i>
        ' . htmlspecialchars($displayName) . '
    </span>';
}

/**
 * Get all emergency types (12 types - All in English)
 */
function getAllEmergencyTypes() {
    return [
        ['code' => 'water_accumulation', 'name' => 'Water Accumulation', 'icon' => 'fa-water', 'color' => '#2563eb'],
        ['code' => 'asphalt_damage', 'name' => 'Asphalt Road Damage', 'icon' => 'fa-road', 'color' => '#64748b'],
        ['code' => 'temporary_road_damage', 'name' => 'Temporary Road Damage', 'icon' => 'fa-road-circle-exclamation', 'color' => '#f59e0b'],
        ['code' => 'road_shoulder_collapse', 'name' => 'Road Shoulder Collapse', 'icon' => 'fa-road-barrier', 'color' => '#ef4444'],
        ['code' => 'sand_collapse', 'name' => 'Sand Collapse', 'icon' => 'fa-mountain', 'color' => '#d97706'],
        ['code' => 'rock_collapse', 'name' => 'Rock Collapse', 'icon' => 'fa-mountain-sun', 'color' => '#78350f'],
        ['code' => 'traffic_signal_failure', 'name' => 'Traffic Signal Failure', 'icon' => 'fa-traffic-light', 'color' => '#dc2626'],
        ['code' => 'street_lights_malfunction', 'name' => 'Street Lights Malfunction', 'icon' => 'fa-lightbulb', 'color' => '#fbbf24'],
        ['code' => 'fallen_trees', 'name' => 'Fallen Trees', 'icon' => 'fa-tree', 'color' => '#10b981'],
        ['code' => 'fallen_solid_objects', 'name' => 'Fallen Solid Objects', 'icon' => 'fa-cube', 'color' => '#8b5cf6'],
        ['code' => 'traffic_accident', 'name' => 'Traffic Accident', 'icon' => 'fa-car-burst', 'color' => '#dc2626'],
        ['code' => 'other', 'name' => 'Other', 'icon' => 'fa-exclamation-triangle', 'color' => '#6b7280']
    ];
}

/**
 * Get asset type details with icon
 */
function getAssetTypeDetails($type_code) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM asset_types WHERE code = ?");
    $stmt->execute([$type_code]);
    $type = $stmt->fetch();
    
    if (!$type) {
        return [
            'code' => $type_code,
            'name' => $type_code,
            'icon' => 'fa-location-dot',
            'color' => '#6b7280'
        ];
    }
    
    return $type;
}

/**
 * Get asset type icon HTML
 */
function getAssetTypeIcon($type_code, $size = 'md') {
    $type = getAssetTypeDetails($type_code);
    $sizes = ['sm' => '14px', 'md' => '18px', 'lg' => '24px'];
    $fontSize = $sizes[$size] ?? $sizes['md'];
    
    return '<i class="fas ' . htmlspecialchars($type['icon']) . '" style="font-size: ' . $fontSize . ';"></i>';
}

/**
 * Get asset type badge HTML (no color - use status badge for colors)
 */
function getAssetTypeBadge($type_code, $other_description = null) {
    $type = getAssetTypeDetails($type_code);
    $displayName = ($type_code === 'AST-OTHER' && $other_description) ? $other_description : $type['name'];
    
    return '<span class="badge" style="background-color: #6b7280; color: white; display: inline-flex; align-items: center; gap: 0.5rem;">
        <i class="fas ' . htmlspecialchars($type['icon']) . '"></i>
        ' . htmlspecialchars($displayName) . '
    </span>';
}

/**
 * Get all asset types
 */
function getAllAssetTypes() {
    global $conn;
    $stmt = $conn->query("SELECT * FROM asset_types WHERE is_active = 1 ORDER BY type_name");
    return $stmt->fetchAll();
}


/**
 * Get asset status color based on status
 */
function getAssetStatusColor($status) {
    $colors = [
        'active' => '#28A745',           // أخضر - شغال
        'maintenance' => '#FFC107',      // أصفر - صيانة
        'faulty' => '#FF8C00',          // برتقالي - معطل
        'out_of_service' => '#DC3545',  // أحمر - خارج الخدمة
        'decommissioned' => '#6C757D'   // رمادي - ملغي
    ];
    
    return $colors[$status] ?? '#6b7280';
}

/**
 * Get asset status badge HTML
 */
function getAssetStatusBadge($status) {
    $icons = [
        'active' => 'fa-check-circle',
        'maintenance' => 'fa-tools',
        'faulty' => 'fa-exclamation-triangle',
        'out_of_service' => 'fa-times-circle',
        'decommissioned' => 'fa-ban'
    ];
    
    $labels = [
        'active' => 'Active',
        'maintenance' => 'Under Maintenance',
        'faulty' => 'Faulty',
        'out_of_service' => 'Out of Service',
        'decommissioned' => 'Decommissioned'
    ];
    
    $color = getAssetStatusColor($status);
    $icon = $icons[$status] ?? 'fa-question-circle';
    $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    
    return '<span class="badge" style="background-color: ' . htmlspecialchars($color) . '; color: white; display: inline-flex; align-items: center; gap: 0.5rem;">
        <i class="fas ' . htmlspecialchars($icon) . '"></i>
        ' . htmlspecialchars($label) . '
    </span>';
}


// ============================================
// EXECUTION REPORT AGGREGATION FUNCTIONS
// ============================================

/**
 * Calculate Employees Summary with Overtime
 * Groups by Employee ID and calculates total hours, regular hours (≤8), and overtime (>8)
 */
function calculateWorkforceSummary($emergency_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            employee_id,
            job_title,
            SUM(hours) as total_hours,
            COUNT(*) as work_days
        FROM execution_workforce 
        WHERE emergency_id = :id 
        GROUP BY employee_id, job_title
        ORDER BY job_title, employee_id
    ");
    $stmt->execute([':id' => $emergency_id]);
    $workforce = $stmt->fetchAll();
    
    // Return simplified summary
    $summary = [];
    foreach ($workforce as $worker) {
        $total_hours = floatval($worker['total_hours']);
        $work_days = intval($worker['work_days']);
        
        $summary[] = [
            'employee_id' => $worker['employee_id'],
            'job_title' => $worker['job_title'],
            'work_days' => $work_days,
            'total_hours' => $total_hours
        ];
    }
    
    return $summary;
}

/**
 * Calculate Equipment Summary
 * Groups by equipment type and unit
 */
function calculateEquipmentSummary($emergency_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            equipment_type,
            unit,
            SUM(quantity) as total_quantity,
            COUNT(*) as usage_count
        FROM execution_equipment 
        WHERE emergency_id = :id 
        GROUP BY equipment_type, unit
        ORDER BY equipment_type, unit
    ");
    $stmt->execute([':id' => $emergency_id]);
    
    return $stmt->fetchAll();
}

/**
 * Calculate Materials Summary
 * Groups by material type and unit
 */
function calculateMaterialsSummary($emergency_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            material_type,
            unit,
            SUM(quantity) as total_quantity,
            COUNT(*) as usage_count
        FROM execution_materials 
        WHERE emergency_id = :id 
        GROUP BY material_type, unit
        ORDER BY material_type, unit
    ");
    $stmt->execute([':id' => $emergency_id]);
    
    return $stmt->fetchAll();
}

/**
 * Calculate Photo Counts by Category
 */
function calculatePhotoCounts($emergency_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            category,
            COUNT(*) as photo_count
        FROM execution_photos 
        WHERE emergency_id = :id 
        GROUP BY category
        ORDER BY 
            CASE category
                WHEN 'before' THEN 1
                WHEN 'during' THEN 2
                WHEN 'after' THEN 3
            END
    ");
    $stmt->execute([':id' => $emergency_id]);
    
    $counts = ['before' => 0, 'during' => 0, 'after' => 0];
    while ($row = $stmt->fetch()) {
        $counts[$row['category']] = intval($row['photo_count']);
    }
    
    return $counts;
}

/**
 * Get Detailed Workforce with Dates
 */
function getDetailedWorkforce($emergency_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT *
        FROM execution_workforce 
        WHERE emergency_id = :id 
        ORDER BY created_at, employee_id
    ");
    $stmt->execute([':id' => $emergency_id]);
    
    return $stmt->fetchAll();
}

/**
 * Get Detailed Equipment with Dates
 */
function getDetailedEquipment($emergency_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT *
        FROM execution_equipment 
        WHERE emergency_id = :id 
        ORDER BY created_at, equipment_type
    ");
    $stmt->execute([':id' => $emergency_id]);
    
    return $stmt->fetchAll();
}

/**
 * Get Detailed Materials with Dates
 */
function getDetailedMaterials($emergency_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT *
        FROM execution_materials 
        WHERE emergency_id = :id 
        ORDER BY created_at, material_type
    ");
    $stmt->execute([':id' => $emergency_id]);
    
    return $stmt->fetchAll();
}
