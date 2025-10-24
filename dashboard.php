<?php
require_once 'config.php';
require_once 'functions.php';

requireLogin();

$user = getCurrentUser();
$user_id = $user['id'];
$user_role = $user['role'];
$is_admin = in_array($user_role, ['super_admin', 'admin']);

// Get statistics with permission filters - UPDATED to use user_sectors table
$where_conditions = [];
$params = [];

if (!in_array($user_role, ['super_admin', 'admin', 'inspection'])) {
    if (in_array($user_role, ['sector_manager', 'supervisor'])) {
        // Get user's sectors from user_sectors table
        $sector_ids = getUserSectorIds($user_id);
        if (!empty($sector_ids)) {
            $where_conditions[] = "sector_id IN (" . implode(',', array_map('intval', $sector_ids)) . ")";
        } else {
            // SECURITY: No sectors assigned = no access
            $where_conditions[] = "1=0";
        }
    } else {
        // Single sector assignment
        if ($user['sector_id']) {
            $where_conditions[] = "sector_id = " . intval($user['sector_id']);
        } else {
            // SECURITY: No sector assignment = no access
            $where_conditions[] = "1=0";
        }
    }
}

$where_sql = !empty($where_conditions) ? " WHERE " . implode(' AND ', $where_conditions) : "";

// Add global $conn declaration
global $conn;

// Overall emergencies stats
$stmt = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new,
    SUM(CASE WHEN status IN ('executing', 'in_progress') THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'hold' THEN 1 ELSE 0 END) as hold,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM emergencies" . $where_sql);
$emergencies_stats = $stmt->fetch();

// Today's statistics
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$stmt = $conn->prepare("SELECT 
    COUNT(*) as total_today,
    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_today,
    SUM(CASE WHEN status IN ('executing', 'in_progress') THEN 1 ELSE 0 END) as in_progress_today,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_today
    FROM emergencies 
    WHERE created_at BETWEEN :start AND :end" . ($where_sql ? " AND " . substr($where_sql, 7) : ""));
$stmt->execute([':start' => $today_start, ':end' => $today_end]);
$today_stats = $stmt->fetch();

// Top emergency types - with permission filter
// Modify WHERE clause to use table alias 'e'
$where_sql_alias = $where_sql ? str_replace('sector_id', 'e.sector_id', $where_sql) : '';
$top_types = $conn->query("SELECT 
    et.name as emergency_type,
    COUNT(*) as count
    FROM emergencies e
    LEFT JOIN emergency_types et ON e.emergency_type_id = et.id" . $where_sql_alias . "
    GROUP BY et.name
    ORDER BY count DESC
    LIMIT 5")->fetchAll();

// Resource utilization statistics - with sector filtering
// Sector managers should only see their sector's resource utilization
$workforce_stats = $conn->query("SELECT 
    COUNT(*) as entries,
    SUM(w.hours) as total_hours,
    COUNT(DISTINCT w.employee_id) as unique_employees
    FROM execution_workforce w
    INNER JOIN emergencies e ON w.emergency_id = e.id" . $where_sql)->fetch();

$equipment_stats = $conn->query("SELECT 
    COUNT(*) as entries
    FROM execution_equipment eq
    INNER JOIN emergencies e ON eq.emergency_id = e.id" . $where_sql)->fetch();

$materials_stats = $conn->query("SELECT 
    COUNT(*) as entries
    FROM execution_materials m
    INNER JOIN emergencies e ON m.emergency_id = e.id" . $where_sql)->fetch();

// Calculate Average Response Time and Execution Time - with sector filtering
$time_stats = $conn->query("SELECT 
    AVG(TIMESTAMPDIFF(HOUR, created_at, execution_started_at)) as avg_response_hours,
    AVG(TIMESTAMPDIFF(HOUR, execution_started_at, updated_at)) as avg_execution_hours,
    COUNT(CASE WHEN execution_started_at IS NOT NULL THEN 1 END) as started_count,
    COUNT(CASE WHEN status = 'completed' AND execution_started_at IS NOT NULL THEN 1 END) as completed_count
    FROM emergencies
    WHERE execution_started_at IS NOT NULL" . ($where_sql ? " AND " . substr($where_sql, 7) : ""))->fetch();

// Calculate averages (convert to days and hours for display)
$avg_response_hours = $time_stats['avg_response_hours'] ?? 0;
$avg_execution_hours = $time_stats['avg_execution_hours'] ?? 0;

// Convert to days and hours
$avg_response_days = floor($avg_response_hours / 24);
$avg_response_hours_remaining = round($avg_response_hours % 24, 1);
$avg_execution_days = floor($avg_execution_hours / 24);
$avg_execution_hours_remaining = round($avg_execution_hours % 24, 1);

// Recent emergencies
$recent_emergencies = $conn->query("SELECT e.*, s.name AS sector_name
    FROM emergencies e 
    LEFT JOIN sectors s ON e.sector_id = s.id
    " . $where_sql . "
    ORDER BY e.created_at DESC LIMIT 8")->fetchAll();

$page_title = 'Dashboard';
include 'includes/header.php';
?>

<style>
@keyframes blink-red {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

@keyframes blink-yellow {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

.stat-card.blink-red {
    animation: blink-red 1.5s ease-in-out infinite;
}

.stat-card.blink-yellow {
    animation: blink-yellow 1.5s ease-in-out infinite;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
}

.stat-card.red {
    border-left: 4px solid #ef4444;
}

.stat-card.yellow {
    border-left: 4px solid #f59e0b;
}

.stat-card.green {
    border-left: 4px solid #10b981;
}

.stat-card.blue {
    border-left: 4px solid #2563eb;
}

.stat-card.gray {
    border-left: 4px solid #6b7280;
}

.stat-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.stat-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-card.red .stat-card-icon {
    background: #fee2e2;
    color: #ef4444;
}

.stat-card.yellow .stat-card-icon {
    background: #fef3c7;
    color: #f59e0b;
}

.stat-card.green .stat-card-icon {
    background: #d1fae5;
    color: #10b981;
}

.stat-card.blue .stat-card-icon {
    background: #dbeafe;
    color: #2563eb;
}

.stat-card.gray .stat-card-icon {
    background: #f3f4f6;
    color: #6b7280;
}

.stat-card-body {
    margin-bottom: 1rem;
}

.stat-card-title {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.stat-card-value {
    font-size: 2.25rem;
    font-weight: 700;
    color: #1f2937;
}

.stat-card-footer {
    font-size: 0.875rem;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.section-divider {
    margin: 2.5rem 0 1.5rem 0;
    border-top: 2px solid #e5e7eb;
    padding-top: 1.5rem;
}

.section-heading {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.mini-chart {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.chart-title {
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 1rem;
}
</style>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard - Real-Time Analytics
                </h1>
                <p class="page-subtitle">
                    <i class="fas fa-calendar-day"></i> <?php echo date('l, F j, Y'); ?> 
                    <span style="margin-left: 1rem;"><i class="fas fa-clock"></i> <?php echo date('h:i A'); ?></span>
                </p>
            </div>
        </div>

        <!-- Quick Actions -->
        <?php if (hasPermission('emergencies_create') || $is_admin): ?>
        <div class="content-section" style="margin-bottom: 2rem;">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-bolt"></i>
                    Quick Actions
                </h2>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                <a href="create_emergency.php" class="btn" style="padding: 1.5rem; text-align: center; text-decoration: none; background: #ef4444; color: white; height: auto;">
                    <i class="fas fa-plus-circle" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                    <span>Report New Emergency</span>
                </a>
                
                <a href="maps.php" class="btn btn-outline" style="padding: 1.5rem; text-align: center; text-decoration: none; height: auto;">
                    <i class="fas fa-map-marked-alt" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                    <span>View Live Map</span>
                </a>
                
                <a href="reports.php" class="btn btn-outline" style="padding: 1.5rem; text-align: center; text-decoration: none; height: auto;">
                    <i class="fas fa-chart-bar" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                    <span>View Analytics & Reports</span>
                </a>

                <?php if ($is_admin): ?>
                <a href="users_management.php" class="btn btn-outline" style="padding: 1.5rem; text-align: center; text-decoration: none; height: auto;">
                    <i class="fas fa-users-cog" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                    <span>Manage Users</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Today's Statistics -->
        <div class="section-heading">
            <i class="fas fa-calendar-day" style="color: #2563eb;"></i> Today's Overview
        </div>
        
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">Today's Emergencies</div>
                    <div class="stat-card-value"><?php echo number_format($today_stats['total_today']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-clock"></i>
                    <span>Reported today</span>
                </div>
            </div>

            <div class="stat-card red <?php echo ($today_stats['new_today'] > 0) ? 'blink-red' : ''; ?>">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">New Today</div>
                    <div class="stat-card-value"><?php echo number_format($today_stats['new_today']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Awaiting response</span>
                </div>
            </div>

            <div class="stat-card yellow <?php echo ($today_stats['in_progress_today'] > 0) ? 'blink-yellow' : ''; ?>">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-cogs"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">In Progress Today</div>
                    <div class="stat-card-value"><?php echo number_format($today_stats['in_progress_today']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-spinner"></i>
                    <span>Being worked on</span>
                </div>
            </div>

            <div class="stat-card green">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">Completed Today</div>
                    <div class="stat-card-value"><?php echo number_format($today_stats['completed_today']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Resolved</span>
                </div>
            </div>
        </div>

        <!-- Overall System Status -->
        <div class="section-divider"></div>
        <div class="section-heading">
            <i class="fas fa-chart-line" style="color: #10b981;"></i> Overall System Status
        </div>
        
        <div class="stats-grid">
            <div class="stat-card red <?php echo ($emergencies_stats['new'] > 0) ? 'blink-red' : ''; ?>">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">New</div>
                    <div class="stat-card-value"><?php echo number_format($emergencies_stats['new']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Awaiting sector manager</span>
                </div>
            </div>

            <div class="stat-card yellow <?php echo ($emergencies_stats['in_progress'] > 0) ? 'blink-yellow' : ''; ?>">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-cogs"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">In Progress</div>
                    <div class="stat-card-value"><?php echo number_format($emergencies_stats['in_progress']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-clock"></i>
                    <span>Currently being handled</span>
                </div>
            </div>

            <div class="stat-card red <?php echo ($emergencies_stats['hold'] > 0) ? 'blink-red' : ''; ?>">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-pause-circle"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">On Hold</div>
                    <div class="stat-card-value"><?php echo number_format($emergencies_stats['hold']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Temporarily paused</span>
                </div>
            </div>

            <div class="stat-card green">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">Completed</div>
                    <div class="stat-card-value"><?php echo number_format($emergencies_stats['completed']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Work finished</span>
                </div>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div class="section-divider"></div>
        <div class="section-heading">
            <i class="fas fa-clock" style="color: #8b5cf6;"></i> Performance Metrics
        </div>
        
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <div class="stat-card" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white;">
                <div class="stat-card-header">
                    <div class="stat-card-icon" style="background: rgba(255, 255, 255, 0.2);">
                        <i class="fas fa-stopwatch"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title" style="color: rgba(255, 255, 255, 0.9);">Average Response Time</div>
                    <div class="stat-card-value">
                        <?php if ($avg_response_hours > 0): ?>
                            <?php if ($avg_response_days > 0): ?>
                                <?php echo $avg_response_days; ?>d <?php echo $avg_response_hours_remaining; ?>h
                            <?php else: ?>
                                <?php echo number_format($avg_response_hours, 1); ?> hours
                            <?php endif; ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stat-card-footer" style="color: rgba(255, 255, 255, 0.8);">
                    <i class="fas fa-info-circle"></i>
                    <span>Report to execution start</span>
                </div>
            </div>

            <div class="stat-card" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: white;">
                <div class="stat-card-header">
                    <div class="stat-card-icon" style="background: rgba(255, 255, 255, 0.2);">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title" style="color: rgba(255, 255, 255, 0.9);">Average Execution Time</div>
                    <div class="stat-card-value">
                        <?php if ($avg_execution_hours > 0): ?>
                            <?php if ($avg_execution_days > 0): ?>
                                <?php echo $avg_execution_days; ?>d <?php echo $avg_execution_hours_remaining; ?>h
                            <?php else: ?>
                                <?php echo number_format($avg_execution_hours, 1); ?> hours
                            <?php endif; ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stat-card-footer" style="color: rgba(255, 255, 255, 0.8);">
                    <i class="fas fa-info-circle"></i>
                    <span>Execution start to completion</span>
                </div>
            </div>
        </div>

        <!-- Resource Utilization -->
        <div class="section-divider"></div>
        <div class="section-heading">
            <i class="fas fa-briefcase" style="color: #f59e0b;"></i> Resource Utilization
        </div>
        
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">Employee Entries</div>
                    <div class="stat-card-value"><?php echo number_format($workforce_stats['entries']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-info-circle"></i>
                    <span>Total employee assignments</span>
                </div>
            </div>

            <div class="stat-card" style="border-left: 4px solid #8b5cf6;">
                <div class="stat-card-header">
                    <div class="stat-card-icon" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white;">
                        <i class="fas fa-user-tie"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">Unique Employees</div>
                    <div class="stat-card-value"><?php echo number_format($workforce_stats['unique_employees']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-info-circle"></i>
                    <span><?php echo number_format($workforce_stats['total_hours'], 1); ?> total hours</span>
                </div>
            </div>

            <div class="stat-card green">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">Equipment Usage</div>
                    <div class="stat-card-value"><?php echo number_format($equipment_stats['entries']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-wrench"></i>
                    <span>Equipment deployed</span>
                </div>
            </div>

            <div class="stat-card yellow">
                <div class="stat-card-header">
                    <div class="stat-card-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-title">Materials Usage</div>
                    <div class="stat-card-value"><?php echo number_format($materials_stats['entries']); ?></div>
                </div>
                <div class="stat-card-footer">
                    <i class="fas fa-cubes"></i>
                    <span>Materials consumed</span>
                </div>
            </div>
        </div>

        <!-- Top Emergency Types -->
        <div class="section-divider"></div>
        <div class="section-heading">
            <i class="fas fa-ranking-star" style="color: #8b5cf6;"></i> Top Emergency Types
        </div>
        
        <!-- Wrapper لمنع الخروج من القالب -->
        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Emergency Type</th>
                        <th style="text-align: center;">Count</th>
                        <th style="width: 50%;">Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    $max_count = !empty($top_types) ? $top_types[0]['count'] : 1;
                    foreach ($top_types as $type): 
                        $percentage = ($type['count'] / $max_count) * 100;
                    ?>
                    <tr>
                        <td><strong>#<?php echo $rank++; ?></strong></td>
                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type['emergency_type']))); ?></td>
                        <td style="text-align: center;"><strong><?php echo number_format($type['count']); ?></strong></td>
                        <td>
                            <!-- Progress Bar متجاوب تلقائياً -->
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo $percentage; ?>%;">
                                    <?php echo number_format($percentage, 1); ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Emergencies -->
        <div class="section-divider"></div>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Recent Emergencies
                </h2>
                <a href="emergencies.php" class="btn btn-sm btn-outline">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <?php if (empty($recent_emergencies)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <div class="empty-state-title">No emergencies</div>
                    <div class="empty-state-subtitle">New emergencies will appear here</div>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 1rem;">
                    <?php foreach ($recent_emergencies as $emergency): 
                        $status_color = [
                            'new' => '#ef4444',
                            'in_progress' => '#f59e0b',
                            'executing' => '#f59e0b', // Legacy support
                            'hold' => '#ef4444',
                            'completed' => '#10b981'
                        ][$emergency['status']] ?? '#6b7280';
                    ?>
                        <a href="view_emergency.php?id=<?php echo $emergency['id']; ?>" class="card" style="text-decoration: none; border-left: 4px solid <?php echo $status_color; ?>;">
                            <div class="card-body">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; color: var(--gray-900); margin-bottom: 0.25rem;">
                                            <?php echo htmlspecialchars($emergency['title']); ?>
                                        </div>
                                        <div style="font-size: 0.875rem; color: var(--gray-600);">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $emergency['emergency_type']))); ?>
                                        </div>
                                    </div>
                                    <?php echo getStatusBadge($emergency['status']); ?>
                                </div>
                                <div style="display: flex; gap: 1rem; font-size: 0.875rem; color: var(--gray-500); flex-wrap: wrap;">
                                    <span>
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <?php echo htmlspecialchars($emergency['sector_name'] ?? 'N/A'); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-clock"></i> 
                                        <?php echo timeAgo($emergency['created_at']); ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>
