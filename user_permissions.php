<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = $_SESSION['user'];

if (!in_array($user['role'], ['super_admin', 'admin'])) {
    header('Location: dashboard.php');
    exit;
}

$selected_user_id = $_GET['user_id'] ?? null;
$selected_user = null;
$assigned_sectors = [];

$users_stmt = $conn->query("
    SELECT id, username, full_name, role, email 
    FROM users 
    WHERE role IN ('sector_manager', 'supervisor') 
    ORDER BY full_name
");
$users = $users_stmt->fetchAll();

$sectors_stmt = $conn->query("SELECT id, name, code FROM sectors ORDER BY name");
$sectors = $sectors_stmt->fetchAll();

if ($selected_user_id) {
    $stmt = $conn->prepare("SELECT id, username, full_name, role, email FROM users WHERE id = :id");
    $stmt->execute([':id' => $selected_user_id]);
    $selected_user = $stmt->fetch();
    
    if ($selected_user) {
        $assigned_sectors = getUserSectorIds($selected_user_id);
    }
}

$page_title = "User Permissions";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - PSD Portal</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-group select {
            height: 48px;
        }
        .btn {
            height: 48px;
            padding: 0 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-save {
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-save:hover {
            background: #1e40af;
        }
        .user-selector {
            background: white;
            border-radius: 8px;
            padding: clamp(1rem, 2vw, 1.5rem);
            margin-bottom: clamp(1rem, 2vw, 1.5rem);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .sectors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .sector-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .sector-card:hover {
            border-color: #2563eb;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.1);
        }
        
        .sector-card.selected {
            background: #eff6ff;
            border-color: #2563eb;
        }
        
        .sector-card input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .sector-info {
            margin-left: 0.75rem;
        }
        
        .sector-name {
            font-weight: 600;
            color: #1e293b;
            font-size: 1rem;
        }
        
        .sector-code {
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .no-user-selected {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
        
        .user-info-box {
            background: #f8fafc;
            border-left: 4px solid #2563eb;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }
        
        .stats-summary {
            display: flex;
            gap: 1.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-item i {
            color: #2563eb;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper page-with-toggle">
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Sidebar Toggle Button -->
    <button class="sidebar-toggle-btn" id="sidebarToggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-user-shield"></i> User Sector Permissions
                </h1>
                <p class="page-subtitle">Assign sector access permissions to users</p>
            </div>
            <a href="users_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>

        <div class="user-selector">
            <form method="GET" action="" style="display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
                <div class="form-group" style="flex: 1; min-width: 300px; margin: 0;">
                    <label for="user_id">
                        <i class="fas fa-user"></i> Select User
                    </label>
                    <select id="user_id" name="user_id" onchange="this.form.submit()" class="form-control">
                        <option value="">-- Choose a user to manage permissions --</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $selected_user_id == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?> 
                            (<?php echo htmlspecialchars($u['username']); ?>) - 
                            <?php echo strtoupper(str_replace('_', ' ', $u['role'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($selected_user): ?>
        <div class="content-section">
            <div class="user-info-box">
                <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3 style="margin: 0 0 0.5rem 0;">
                            <i class="fas fa-user-circle"></i> 
                            <?php echo htmlspecialchars($selected_user['full_name']); ?>
                        </h3>
                        <div class="stats-summary">
                            <div class="stat-item">
                                <i class="fas fa-user"></i>
                                <span><?php echo htmlspecialchars($selected_user['username']); ?></span>
                            </div>
                            <div class="stat-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo htmlspecialchars($selected_user['email']); ?></span>
                            </div>
                            <div class="stat-item">
                                <i class="fas fa-user-tag"></i>
                                <span><?php echo strtoupper(str_replace('_', ' ', $selected_user['role'])); ?></span>
                            </div>
                            <div class="stat-item">
                                <i class="fas fa-map-marked-alt"></i>
                                <strong id="sectorCount"><?php echo count($assigned_sectors); ?></strong> Sectors Assigned
                            </div>
                        </div>
                    </div>
                    <button onclick="savePermissions()" class="btn btn-save">
                        <i class="fas fa-save"></i> Save Permissions
                    </button>
                </div>
            </div>

            <h3 style="margin-bottom: 1rem;">
                <i class="fas fa-check-square"></i> Select Sectors
            </h3>
            <div class="sectors-grid" id="sectorsGrid">
                <?php foreach ($sectors as $sector): ?>
                <div class="sector-card <?php echo in_array($sector['id'], $assigned_sectors) ? 'selected' : ''; ?>" 
                     onclick="toggleSector(this, <?php echo $sector['id']; ?>)">
                    <label style="display: flex; align-items: center; cursor: pointer; margin: 0;">
                        <input type="checkbox" 
                               class="sector-checkbox" 
                               value="<?php echo $sector['id']; ?>" 
                               <?php echo in_array($sector['id'], $assigned_sectors) ? 'checked' : ''; ?>
                               onclick="event.stopPropagation();">
                        <div class="sector-info">
                            <div class="sector-name"><?php echo htmlspecialchars($sector['name']); ?></div>
                            <div class="sector-code">Code: <?php echo htmlspecialchars($sector['code']); ?></div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="content-section">
            <div class="no-user-selected">
                <i class="fas fa-user-slash" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                <h3>No User Selected</h3>
                <p>Please select a user from the dropdown above to manage their sector permissions.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function toggleSector(card, sectorId) {
        const checkbox = card.querySelector('input[type="checkbox"]');
        checkbox.checked = !checkbox.checked;
        card.classList.toggle('selected');
        updateSectorCount();
    }
    
    function updateSectorCount() {
        const count = document.querySelectorAll('.sector-checkbox:checked').length;
        document.getElementById('sectorCount').textContent = count;
    }
    
    async function savePermissions() {
        const userId = <?php echo json_encode($selected_user_id ? intval($selected_user_id) : null); ?>;
        if (!userId) return;
        
        const selectedSectors = Array.from(document.querySelectorAll('.sector-checkbox:checked'))
            .map(cb => cb.value);
        
        try {
            const response = await fetch('ajax/update_user_sectors.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    user_id: userId,
                    sector_ids: selectedSectors
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('Permissions saved successfully!');
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            alert('Error saving permissions. Please try again.');
            console.error(error);
        }
    }
    
    document.querySelectorAll('.sector-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            this.closest('.sector-card').classList.toggle('selected', this.checked);
            updateSectorCount();
        });
    });
    </script>
    </main>
</div>
</body>
</html>
