<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = $_SESSION['user'];

// Check permission - only super_admin, admin, and sector_manager can access reports
if (!hasRoleCapability('reports_view')) {
    $_SESSION['error'] = 'You do not have permission to view reports';
    header('Location: dashboard.php');
    exit;
}

// Get filters
$report_type = $_GET['type'] ?? 'analytics';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$job_number = $_GET['job_number'] ?? '';
$emergency_code = $_GET['emergency_code'] ?? '';

// Get sector filter using helper function
$sector_filter = getUserSectorFilter($user, 'e');

$page_title = 'Reports & Analytics';
include 'includes/header.php';
?>

<style>
.report-filters {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
}

.report-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid #e2e8f0;
    overflow-x: auto;
}

.report-tab {
    padding: 0.875rem 1.5rem;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    color: #64748b;
    transition: all 0.3s;
    white-space: nowrap;
}

.report-tab:hover {
    color: #2563eb;
}

.report-tab.active {
    color: #2563eb;
    border-bottom-color: #2563eb;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    border-radius: 8px;
    overflow: hidden;
}

.report-table thead {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    color: white;
}

.report-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #475569;
}

.report-table td {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    word-wrap: break-word;
    max-width: 300px;
}

.report-table tbody tr:hover {
    background: #f8fafc;
}

.report-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

.summary-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    text-align: center;
}

.summary-value {
    font-size: 2.5rem;
    font-weight: 700;
    color: #2563eb;
    margin: 0.5rem 0;
}

.summary-label {
    font-size: 0.875rem;
    color: #64748b;
    font-weight: 600;
}

.chart-container {
    background: white;
    padding: clamp(1rem, 2vw, 2rem);
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
    /* منع الخروج من القالب */
    max-width: 100%;
    overflow: hidden;
    contain: layout;
    /* تأكد من أن Canvas responsive */
    position: relative;
}

.chart-container canvas {
    max-width: 100% !important;
    height: auto !important;
    display: block;
    margin: 0 auto;
}

.chart-title {
    font-size: clamp(1rem, 1.25vw, 1.25rem);
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 1.5rem;
    text-align: center;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .report-summary {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
}

@media (max-width: 992px) {
    .report-summary {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .report-tabs {
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
    }
}

@media (max-width: 768px) {
    .report-summary {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    
    .summary-card {
        padding: 1rem;
    }
    
    .summary-value {
        font-size: 1.5rem;
    }
    
    .summary-label {
        font-size: 0.8rem;
    }
    
    .report-table th,
    .report-table td {
        padding: 0.5rem;
        font-size: 0.875rem;
    }
    
    .chart-container {
        margin-bottom: 1.5rem;
    }
    
    h2 {
        font-size: 1.25rem !important;
        word-wrap: break-word;
    }
}

@media (max-width: 480px) {
    .report-summary {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start !important;
    }
    
    .page-header > div {
        width: 100%;
    }
    
    .page-header .btn {
        font-size: 0.875rem;
        padding: 0.5rem 1rem;
    }
}

@media print {
    .sidebar, .page-header, .report-filters, .report-tabs, .no-print {
        display: none !important;
    }
    
    .report-table th, .report-table td {
        padding: 0.5rem;
        font-size: 0.85rem;
    }
}
</style>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header no-print">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-chart-line"></i>
                    Reports & Analytics Dashboard
                </h1>
                <p class="page-subtitle">Comprehensive emergency management analytics and reporting</p>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print
                </button>
                <button onclick="exportToExcel()" class="btn btn-primary">
                    <i class="fas fa-file-excel"></i> Export
                </button>
            </div>
        </div>

        <!-- Report Tabs -->
        <div class="report-tabs no-print">
            <button class="report-tab <?php echo $report_type === 'analytics' ? 'active' : ''; ?>" 
                    onclick="switchTab('analytics')">
                <i class="fas fa-chart-pie"></i> Analytics Dashboard
            </button>
            <button class="report-tab <?php echo $report_type === 'workforce' ? 'active' : ''; ?>" 
                    onclick="switchTab('workforce')">
                <i class="fas fa-users"></i> Employees Report
            </button>
            <button class="report-tab <?php echo $report_type === 'equipment' ? 'active' : ''; ?>" 
                    onclick="switchTab('equipment')">
                <i class="fas fa-truck"></i> Equipment Report
            </button>
            <button class="report-tab <?php echo $report_type === 'materials' ? 'active' : ''; ?>" 
                    onclick="switchTab('materials')">
                <i class="fas fa-boxes"></i> Materials Report
            </button>
            <button class="report-tab <?php echo $report_type === 'assets' ? 'active' : ''; ?>" 
                    onclick="switchTab('assets')">
                <i class="fas fa-map-pin"></i> Assets Status
            </button>
        </div>

        <!-- Filters -->
        <div class="report-filters no-print">
            <form method="GET" id="filterForm">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; align-items: end;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-input" style="height: 48px;" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-input" style="height: 48px;" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    
                    <?php if ($report_type === 'workforce'): ?>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Employee ID</label>
                        <input type="text" name="job_number" class="form-input" style="height: 48px;" placeholder="Search by employee ID" value="<?php echo htmlspecialchars($job_number); ?>">
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Emergency Code</label>
                        <input type="text" name="emergency_code" class="form-input" style="height: 48px;" placeholder="EMG-YYYY-#####" value="<?php echo htmlspecialchars($emergency_code); ?>">
                    </div>
                    
                    <div class="form-group" style="display: flex; align-items: flex-end; gap: 0.5rem; margin-bottom: 0;">
                        <button type="submit" class="btn btn-primary" style="flex: 1; height: 48px;">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="reports.php?type=<?php echo $report_type; ?>" class="btn btn-secondary" style="height: 48px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <?php
        // ========================================
        // ANALYTICS DASHBOARD TAB
        // ========================================
        if ($report_type === 'analytics') {
            // Get emergency statistics with sector filter
            $emerg_stats = $conn->query("SELECT 
                COUNT(*) as total_emergencies,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_count,
                SUM(CASE WHEN status IN ('executing', 'in_progress') THEN 1 ELSE 0 END) as in_progress_count,
                SUM(CASE WHEN status = 'hold' THEN 1 ELSE 0 END) as hold_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
                FROM emergencies e
                WHERE 1=1 {$sector_filter}")->fetch();
            
            // Emergency by type with sector filter
            $emerg_by_type = $conn->query("SELECT 
                et.name as emergency_type,
                COUNT(*) as count
                FROM emergencies e
                LEFT JOIN emergency_types et ON e.emergency_type_id = et.id
                WHERE 1=1 {$sector_filter}
                GROUP BY et.name
                ORDER BY count DESC")->fetchAll();
            
            // Emergency by sector with sector filter
            $emerg_by_sector = $conn->query("SELECT 
                s.name as sector_name,
                COUNT(e.id) as count
                FROM emergencies e
                LEFT JOIN sectors s ON e.sector_id = s.id
                WHERE 1=1 {$sector_filter}
                GROUP BY s.id, s.name
                ORDER BY count DESC")->fetchAll();
            
            // Workforce statistics
            $workforce_stats = $conn->query("SELECT 
                COUNT(*) as total_entries,
                SUM(hours) as total_hours,
                COUNT(DISTINCT employee_id) as unique_employees
                FROM execution_workforce")->fetch();
            
            // Equipment statistics  
            $equipment_stats = $conn->query("SELECT 
                COUNT(*) as total_entries,
                SUM(quantity) as total_quantity,
                COUNT(DISTINCT equipment_type) as unique_types
                FROM execution_equipment")->fetch();
            
            // Materials statistics
            $materials_stats = $conn->query("SELECT 
                COUNT(*) as total_entries,
                SUM(quantity) as total_quantity,
                COUNT(DISTINCT material_type) as unique_types
                FROM execution_materials")->fetch();
            
            // Calculate Average Response Time and Execution Time with sector filter
            $time_stats = $conn->query("SELECT 
                AVG(TIMESTAMPDIFF(HOUR, e.created_at, e.execution_started_at)) as avg_response_hours,
                AVG(TIMESTAMPDIFF(HOUR, e.execution_started_at, e.updated_at)) as avg_execution_hours,
                COUNT(CASE WHEN e.execution_started_at IS NOT NULL THEN 1 END) as started_count,
                COUNT(CASE WHEN e.status = 'completed' AND e.execution_started_at IS NOT NULL THEN 1 END) as completed_count
                FROM emergencies e
                WHERE e.execution_started_at IS NOT NULL {$sector_filter}")->fetch();
            
            // Calculate averages (convert to days and hours for display)
            $avg_response_hours = $time_stats['avg_response_hours'] ?? 0;
            $avg_execution_hours = $time_stats['avg_execution_hours'] ?? 0;
            
            // Convert to days and hours
            $avg_response_days = floor($avg_response_hours / 24);
            $avg_response_hours_remaining = round($avg_response_hours % 24, 1);
            $avg_execution_days = floor($avg_execution_hours / 24);
            $avg_execution_hours_remaining = round($avg_execution_hours % 24, 1);
            
            // Assets statistics
            $assets_stats = $conn->query("SELECT 
                COUNT(*) as total_assets,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_count
                FROM map_markers")->fetch();
            
            ?>
            
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin-bottom: 1.5rem;">
                <i class="fas fa-chart-pie"></i> Emergency Management Analytics
            </h2>
            
            <!-- Emergency Statistics -->
            <div class="report-summary">
                <div class="summary-card">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: #2563eb; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Total Emergencies</div>
                    <div class="summary-value"><?php echo number_format($emerg_stats['total_emergencies']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-circle" style="font-size: 2rem; color: #ef4444; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">New</div>
                    <div class="summary-value" style="color: #ef4444;"><?php echo number_format($emerg_stats['new_count']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-circle" style="font-size: 2rem; color: #f59e0b; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">In Progress</div>
                    <div class="summary-value" style="color: #f59e0b;"><?php echo number_format($emerg_stats['in_progress_count']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-circle" style="font-size: 2rem; color: #ef4444; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">On Hold</div>
                    <div class="summary-value" style="color: #ef4444;"><?php echo number_format($emerg_stats['hold_count']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-circle-check" style="font-size: 2rem; color: #10b981; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Completed</div>
                    <div class="summary-value" style="color: #10b981;"><?php echo number_format($emerg_stats['completed_count']); ?></div>
                </div>
            </div>
            
            <!-- Performance Metrics -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 2rem 0 1.5rem 0;">
                <i class="fas fa-clock"></i> Performance Metrics
            </h2>
            
            <div class="report-summary">
                <div class="summary-card" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                    <i class="fas fa-stopwatch" style="font-size: 2rem; color: white; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label" style="color: rgba(255, 255, 255, 0.9);">Average Response Time</div>
                    <div class="summary-value" style="color: white;">
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
                    <div style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.8); margin-top: 0.5rem;">
                        <i class="fas fa-info-circle"></i> Report to execution start
                    </div>
                </div>
                
                <div class="summary-card" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);">
                    <i class="fas fa-hourglass-half" style="font-size: 2rem; color: white; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label" style="color: rgba(255, 255, 255, 0.9);">Average Execution Time</div>
                    <div class="summary-value" style="color: white;">
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
                    <div style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.8); margin-top: 0.5rem;">
                        <i class="fas fa-info-circle"></i> Execution start to completion
                    </div>
                </div>
            </div>
            
            <!-- Charts Row 1 -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; max-width: 100%; overflow: hidden;">
                <!-- Emergency by Type Chart -->
                <div class="chart-container" style="min-width: 0;">
                    <div class="chart-title"><i class="fas fa-chart-bar"></i> Emergencies by Type</div>
                    <canvas id="chartByType"></canvas>
                </div>
                
                <!-- Emergency by Sector Chart -->
                <div class="chart-container" style="min-width: 0;">
                    <div class="chart-title"><i class="fas fa-chart-pie"></i> Emergencies by Sector</div>
                    <canvas id="chartBySector"></canvas>
                </div>
            </div>
            
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 2rem 0 1.5rem 0;">
                <i class="fas fa-briefcase"></i> Resource Utilization
            </h2>
            
            <!-- Resource Statistics -->
            <div class="report-summary">
                <div class="summary-card">
                    <i class="fas fa-users" style="font-size: 2rem; color: #3b82f6; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Employee Entries</div>
                    <div class="summary-value" style="color: #3b82f6;"><?php echo number_format($workforce_stats['total_entries']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-clock" style="font-size: 2rem; color: #f59e0b; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Total Hours</div>
                    <div class="summary-value" style="color: #f59e0b;"><?php echo number_format($workforce_stats['total_hours'], 1); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-user-tie" style="font-size: 2rem; color: #8b5cf6; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Unique Employees</div>
                    <div class="summary-value" style="color: #8b5cf6;"><?php echo number_format($workforce_stats['unique_employees']); ?></div>
                </div>
            </div>
            
            <div class="report-summary" style="margin-top: 1rem;">
                <div class="summary-card">
                    <i class="fas fa-truck" style="font-size: 2rem; color: #10b981; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Equipment Usages</div>
                    <div class="summary-value" style="color: #10b981;"><?php echo number_format($equipment_stats['total_entries']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-boxes" style="font-size: 2rem; color: #ec4899; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Materials Usages</div>
                    <div class="summary-value" style="color: #ec4899;"><?php echo number_format($materials_stats['total_entries']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-wrench" style="font-size: 2rem; color: #06b6d4; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Equipment Types</div>
                    <div class="summary-value" style="color: #06b6d4;"><?php echo number_format($equipment_stats['unique_types']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-cubes" style="font-size: 2rem; color: #14b8a6; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Material Types</div>
                    <div class="summary-value" style="color: #14b8a6;"><?php echo number_format($materials_stats['unique_types']); ?></div>
                </div>
            </div>
            
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 2rem 0 1.5rem 0;">
                <i class="fas fa-map-pin"></i> Assets Status Overview
            </h2>
            
            <!-- Assets Statistics -->
            <div class="report-summary">
                <div class="summary-card">
                    <i class="fas fa-map-marker-alt" style="font-size: 2rem; color: #2563eb; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Total Assets</div>
                    <div class="summary-value"><?php echo number_format($assets_stats['total_assets']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-circle-check" style="font-size: 2rem; color: #10b981; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Active</div>
                    <div class="summary-value" style="color: #10b981;"><?php echo number_format($assets_stats['active_count']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-circle-xmark" style="font-size: 2rem; color: #ef4444; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Inactive</div>
                    <div class="summary-value" style="color: #ef4444;"><?php echo number_format($assets_stats['inactive_count']); ?></div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-screwdriver-wrench" style="font-size: 2rem; color: #f59e0b; margin-bottom: 0.5rem;"></i>
                    <div class="summary-label">Under Maintenance</div>
                    <div class="summary-value" style="color: #f59e0b;"><?php echo number_format($assets_stats['maintenance_count']); ?></div>
                </div>
            </div>
            
            <!-- Asset Status Chart -->
            <div class="chart-container" style="max-width: 600px; margin: 2rem auto;">
                <div class="chart-title"><i class="fas fa-chart-pie"></i> Asset Status Distribution</div>
                <canvas id="chartAssetStatus"></canvas>
            </div>
            
            <!-- Top Emergency Types Table -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 2rem 0 1.5rem 0;">
                <i class="fas fa-ranking-star"></i> Top Emergency Types
            </h2>
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
                        $max_count = !empty($emerg_by_type) ? $emerg_by_type[0]['count'] : 1;
                        foreach ($emerg_by_type as $row): 
                            $percentage = ($row['count'] / $max_count) * 100;
                        ?>
                        <tr>
                            <td><strong>#<?php echo $rank++; ?></strong></td>
                            <td><?php echo htmlspecialchars($row['emergency_type']); ?></td>
                            <td style="text-align: center;"><strong><?php echo number_format($row['count']); ?></strong></td>
                            <td>
                                <div style="background: #e5e7eb; border-radius: 4px; height: 24px; overflow: hidden;">
                                    <div style="background: linear-gradient(90deg, #2563eb, #3b82f6); width: <?php echo $percentage; ?>%; height: 100%; display: flex; align-items: center; justify-content: flex-end; padding: 0 8px; color: white; font-size: 0.75rem; font-weight: 600;">
                                        <?php echo number_format(($row['count'] / $emerg_stats['total_emergencies']) * 100, 1); ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Top Sectors Table -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1f2937; margin: 2rem 0 1.5rem 0;">
                <i class="fas fa-ranking-star"></i> Top Sectors by Emergency Count
            </h2>
            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Sector Name</th>
                            <th style="text-align: center;">Emergency Count</th>
                            <th style="width: 50%;">Distribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        $max_count = !empty($emerg_by_sector) ? $emerg_by_sector[0]['count'] : 1;
                        foreach ($emerg_by_sector as $row): 
                            $percentage = ($row['count'] / $max_count) * 100;
                        ?>
                        <tr>
                            <td><strong>#<?php echo $rank++; ?></strong></td>
                            <td><?php echo htmlspecialchars($row['sector_name']); ?></td>
                            <td style="text-align: center;"><strong><?php echo number_format($row['count']); ?></strong></td>
                            <td>
                                <div style="background: #e5e7eb; border-radius: 4px; height: 24px; overflow: hidden;">
                                    <div style="background: linear-gradient(90deg, #10b981, #14b8a6); width: <?php echo $percentage; ?>%; height: 100%; display: flex; align-items: center; justify-content: flex-end; padding: 0 8px; color: white; font-size: 0.75rem; font-weight: 600;">
                                        <?php echo number_format(($row['count'] / $emerg_stats['total_emergencies']) * 100, 1); ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
            <script>
            // Emergency by Type Chart
            const ctxType = document.getElementById('chartByType');
            new Chart(ctxType, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($emerg_by_type, 'emergency_type')); ?>,
                    datasets: [{
                        label: 'Number of Emergencies',
                        data: <?php echo json_encode(array_column($emerg_by_type, 'count')); ?>,
                        backgroundColor: '#2563eb',
                        borderColor: '#1e40af',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    }
                }
            });
            
            // Emergency by Sector Chart
            const ctxSector = document.getElementById('chartBySector');
            new Chart(ctxSector, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($emerg_by_sector, 'sector_name')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($emerg_by_sector, 'count')); ?>,
                        backgroundColor: [
                            '#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                            '#ec4899', '#06b6d4', '#14b8a6', '#f97316'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
            
            // Asset Status Chart
            const ctxAsset = document.getElementById('chartAssetStatus');
            new Chart(ctxAsset, {
                type: 'doughnut',
                data: {
                    labels: ['Active', 'Inactive', 'Under Maintenance'],
                    datasets: [{
                        data: [
                            <?php echo $assets_stats['active_count']; ?>,
                            <?php echo $assets_stats['inactive_count']; ?>,
                            <?php echo $assets_stats['maintenance_count']; ?>
                        ],
                        backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
            </script>
            
        <?php
        // ========================================
        // EMPLOYEES REPORT TAB
        // ========================================
        } elseif ($report_type === 'workforce') {
            $sql = "SELECT 
                        w.created_at as work_date,
                        w.employee_id,
                        w.job_title,
                        w.hours,
                        e.emergency_code,
                        e.title as emergency_title,
                        s.name as sector_name
                    FROM execution_workforce w
                    INNER JOIN emergencies e ON w.emergency_id = e.id
                    LEFT JOIN sectors s ON e.sector_id = s.id
                    WHERE 1=1 {$sector_filter}";
            
            $params = [];
            
            if ($start_date) {
                $sql .= " AND DATE(w.created_at) >= :start_date";
                $params[':start_date'] = $start_date;
            }
            if ($end_date) {
                $sql .= " AND DATE(w.created_at) <= :end_date";
                $params[':end_date'] = $end_date;
            }
            if ($job_number) {
                $sql .= " AND w.employee_id LIKE :job_number";
                $params[':job_number'] = "%{$job_number}%";
            }
            if ($emergency_code) {
                $sql .= " AND e.emergency_code LIKE :emergency_code";
                $params[':emergency_code'] = "%{$emergency_code}%";
            }
            
            $sql .= " ORDER BY w.created_at DESC, w.employee_id";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            // Calculate totals
            $total_hours = 0;
            foreach ($results as $row) {
                $total_hours += $row['hours'];
            }
            ?>
            
            <!-- Summary Cards -->
            <div class="report-summary">
                <div class="summary-card">
                    <div class="summary-label">Total Records</div>
                    <div class="summary-value"><?php echo count($results); ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Total Hours Worked</div>
                    <div class="summary-value" style="color: #2563eb;"><?php echo number_format($total_hours, 1); ?></div>
                </div>
            </div>
            
            <!-- Workforce Table -->
            <div class="table-responsive">
                <table class="report-table" id="reportTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee Name</th>
                            <th>Job Title</th>
                            <th style="text-align: center;">Hours Worked</th>
                            <th>Emergency Code</th>
                            <th>Emergency Title</th>
                            <th>Sector</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: #64748b;">
                                <i class="fas fa-info-circle"></i> No workforce records found
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo formatDate($row['work_date']); ?></td>
                            <td><strong><?php echo htmlspecialchars($row['employee_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                            <td style="text-align: center;"><strong><?php echo number_format($row['hours'], 1); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['emergency_code']); ?></td>
                            <td><?php echo htmlspecialchars($row['emergency_title']); ?></td>
                            <td><?php echo htmlspecialchars($row['sector_name']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($results)): ?>
                    <tfoot>
                        <tr style="background: #f1f5f9; font-weight: 700;">
                            <td colspan="3" style="text-align: right;">TOTAL:</td>
                            <td style="text-align: center;"><?php echo number_format($total_hours, 1); ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            
        <?php
        // ========================================
        // EQUIPMENT REPORT TAB
        // ========================================
        } elseif ($report_type === 'equipment') {
            $sql = "SELECT 
                        eq.created_at as usage_date,
                        eq.equipment_type,
                        eq.equipment_code,
                        eq.equipment_number,
                        eq.quantity,
                        eq.unit,
                        eq.notes,
                        e.emergency_code,
                        e.title as emergency_title,
                        s.name as sector_name
                    FROM execution_equipment eq
                    INNER JOIN emergencies e ON eq.emergency_id = e.id
                    LEFT JOIN sectors s ON e.sector_id = s.id
                    WHERE 1=1 {$sector_filter}";
            
            $params = [];
            
            if ($start_date) {
                $sql .= " AND DATE(eq.created_at) >= :start_date";
                $params[':start_date'] = $start_date;
            }
            if ($end_date) {
                $sql .= " AND DATE(eq.created_at) <= :end_date";
                $params[':end_date'] = $end_date;
            }
            if ($emergency_code) {
                $sql .= " AND e.emergency_code LIKE :emergency_code";
                $params[':emergency_code'] = "%{$emergency_code}%";
            }
            
            $sql .= " ORDER BY eq.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            $total_quantity = 0;
            foreach ($results as $row) {
                $total_quantity += $row['quantity'];
            }
            ?>
            
            <!-- Summary Cards -->
            <div class="report-summary">
                <div class="summary-card">
                    <div class="summary-label">Total Records</div>
                    <div class="summary-value"><?php echo count($results); ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Total Quantity</div>
                    <div class="summary-value" style="color: #10b981;"><?php echo number_format($total_quantity, 2); ?></div>
                </div>
            </div>
            
            <!-- Equipment Table -->
            <div class="table-responsive">
                <table class="report-table" id="reportTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Equipment Type</th>
                            <th>Code</th>
                            <th>Number</th>
                            <th style="text-align: center;">Quantity</th>
                            <th>Unit</th>
                            <th>Emergency Code</th>
                            <th>Emergency Title</th>
                            <th>Sector</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 2rem; color: #64748b;">
                                <i class="fas fa-info-circle"></i> No equipment records found
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo formatDate($row['usage_date']); ?></td>
                            <td><strong><?php echo htmlspecialchars($row['equipment_type']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['equipment_code'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['equipment_number'] ?? '-'); ?></td>
                            <td style="text-align: center;"><strong><?php echo number_format($row['quantity'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['unit']); ?></td>
                            <td><?php echo htmlspecialchars($row['notes']); ?></td>
                            <td><?php echo htmlspecialchars($row['emergency_code']); ?></td>
                            <td><?php echo htmlspecialchars($row['emergency_title']); ?></td>
                            <td><?php echo htmlspecialchars($row['sector_name']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
        <?php
        // ========================================
        // MATERIALS REPORT TAB
        // ========================================
        } elseif ($report_type === 'materials') {
            $sql = "SELECT 
                        m.created_at as usage_date,
                        m.material_type,
                        m.quantity,
                        m.unit,
                        m.notes,
                        e.emergency_code,
                        e.title as emergency_title,
                        s.name as sector_name
                    FROM execution_materials m
                    INNER JOIN emergencies e ON m.emergency_id = e.id
                    LEFT JOIN sectors s ON e.sector_id = s.id
                    WHERE 1=1 {$sector_filter}";
            
            $params = [];
            
            if ($start_date) {
                $sql .= " AND DATE(m.created_at) >= :start_date";
                $params[':start_date'] = $start_date;
            }
            if ($end_date) {
                $sql .= " AND DATE(m.created_at) <= :end_date";
                $params[':end_date'] = $end_date;
            }
            if ($emergency_code) {
                $sql .= " AND e.emergency_code LIKE :emergency_code";
                $params[':emergency_code'] = "%{$emergency_code}%";
            }
            
            $sql .= " ORDER BY m.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            $total_quantity = 0;
            foreach ($results as $row) {
                $total_quantity += $row['quantity'];
            }
            ?>
            
            <!-- Summary Cards -->
            <div class="report-summary">
                <div class="summary-card">
                    <div class="summary-label">Total Records</div>
                    <div class="summary-value"><?php echo count($results); ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Total Quantity</div>
                    <div class="summary-value" style="color: #ec4899;"><?php echo number_format($total_quantity, 2); ?></div>
                </div>
            </div>
            
            <!-- Materials Table -->
            <div class="table-responsive">
                <table class="report-table" id="reportTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Material Type</th>
                            <th style="text-align: center;">Quantity</th>
                            <th>Unit</th>
                            <th>Notes</th>
                            <th>Emergency Code</th>
                            <th>Emergency Title</th>
                            <th>Sector</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: #64748b;">
                                <i class="fas fa-info-circle"></i> No materials records found
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo formatDate($row['usage_date']); ?></td>
                            <td><strong><?php echo htmlspecialchars($row['material_type']); ?></strong></td>
                            <td style="text-align: center;"><strong><?php echo number_format($row['quantity'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['unit']); ?></td>
                            <td><?php echo htmlspecialchars($row['notes']); ?></td>
                            <td><?php echo htmlspecialchars($row['emergency_code']); ?></td>
                            <td><?php echo htmlspecialchars($row['emergency_title']); ?></td>
                            <td><?php echo htmlspecialchars($row['sector_name']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
        <?php
        // ========================================
        // ASSETS STATUS TAB
        // ========================================
        } elseif ($report_type === 'assets') {
            $sql = "SELECT 
                        m.id,
                        m.marker_name,
                        m.asset_type_code,
                        at.name as asset_type_name,
                        m.latitude,
                        m.longitude,
                        m.status,
                        m.description,
                        m.created_at,
                        s.name as sector_name
                    FROM map_markers m
                    LEFT JOIN asset_types at ON m.asset_type_code = at.code
                    LEFT JOIN sectors s ON m.sector_id = s.id
                    WHERE 1=1
                    ORDER BY m.status, m.created_at DESC";
            
            $stmt = $conn->query($sql);
            $results = $stmt->fetchAll();
            
            $active_count = 0;
            $inactive_count = 0;
            $maintenance_count = 0;
            foreach ($results as $row) {
                if ($row['status'] === 'active') $active_count++;
                elseif ($row['status'] === 'inactive') $inactive_count++;
                elseif ($row['status'] === 'maintenance') $maintenance_count++;
            }
            ?>
            
            <!-- Summary Cards -->
            <div class="report-summary">
                <div class="summary-card">
                    <div class="summary-label">Total Assets</div>
                    <div class="summary-value"><?php echo count($results); ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Active</div>
                    <div class="summary-value" style="color: #10b981;"><?php echo $active_count; ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Inactive</div>
                    <div class="summary-value" style="color: #ef4444;"><?php echo $inactive_count; ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Under Maintenance</div>
                    <div class="summary-value" style="color: #f59e0b;"><?php echo $maintenance_count; ?></div>
                </div>
            </div>
            
            <!-- Assets Table -->
            <div class="table-responsive">
                <table class="report-table" id="reportTable">
                    <thead>
                        <tr>
                            <th>Asset Name</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th style="text-align: center;">Status</th>
                            <th>Description</th>
                            <th>Sector</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: #64748b;">
                                <i class="fas fa-info-circle"></i> No assets found
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($results as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['marker_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['asset_type_name']); ?></td>
                            <td><?php echo number_format($row['latitude'], 6) . ', ' . number_format($row['longitude'], 6); ?></td>
                            <td style="text-align: center;">
                                <?php 
                                $badge_class = $row['status'] === 'active' ? 'success' : ($row['status'] === 'maintenance' ? 'warning' : 'danger');
                                ?>
                                <span class="badge badge-<?php echo $badge_class; ?>"><?php echo ucfirst($row['status']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                            <td><?php echo htmlspecialchars($row['sector_name']); ?></td>
                            <td><?php echo formatDate($row['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
        <?php } ?>

    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
function switchTab(type) {
    window.location.href = 'reports.php?type=' + type;
}

function exportToExcel() {
    const table = document.getElementById('reportTable');
    if (!table) {
        alert('No data to export');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        
        csv.push(row.join(','));
    }
    
    const csvContent = '\uFEFF' + csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'report_<?php echo $report_type; ?>_<?php echo date('Y-m-d'); ?>.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
