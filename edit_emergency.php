<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = $_SESSION['user'];

// Only admin and super_admin can edit
if (!in_array($user['role'], ['super_admin', 'admin'])) {
    header('Location: emergencies.php');
    exit;
}

$emergency_id = $_GET['id'] ?? 0;

// Get emergency details
$stmt = $conn->prepare("SELECT * FROM emergencies WHERE id = :id");
$stmt->execute([':id' => $emergency_id]);
$emergency = $stmt->fetch();

if (!$emergency) {
    header('Location: emergencies.php');
    exit;
}

// PROTECTION: Completed emergencies cannot be edited (except by super_admin)
if ($emergency['status'] === 'completed' && $user['role'] !== 'super_admin') {
    $_SESSION['error'] = 'Cannot edit completed emergencies. Only Super Admin can modify completed reports.';
    header('Location: view_emergency.php?id=' . $emergency_id);
    exit;
}

// Get sectors
$sectors_stmt = $conn->query("SELECT * FROM sectors ORDER BY name");
$sectors = $sectors_stmt->fetchAll();

// Get emergency types
$types_stmt = $conn->query("SELECT * FROM emergency_types ORDER BY name");
$emergency_types = $types_stmt->fetchAll();

$page_title = 'Edit Emergency';
include 'includes/header.php';
?>

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
                    <i class="fas fa-edit"></i>
                    Edit Emergency
                </h1>
                <p class="page-subtitle">Emergency Code: <?php echo htmlspecialchars($emergency['emergency_code']); ?></p>
            </div>
            <a href="emergencies.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <div class="content-section">
            <form id="editEmergencyForm" method="POST" action="ajax/update_emergency.php">
                <input type="hidden" name="emergency_id" value="<?php echo $emergency_id; ?>">
                
                <div class="form-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <div class="form-group">
                        <label class="form-label">Emergency Type *</label>
                        <select name="emergency_type_id" class="form-input" required>
                            <option value="">Select Type</option>
                            <?php foreach ($emergency_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo $emergency['emergency_type_id'] == $type['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sector *</label>
                        <select name="sector_id" class="form-input" required>
                            <?php foreach ($sectors as $sector): ?>
                            <option value="<?php echo $sector['id']; ?>" <?php echo $emergency['sector_id'] == $sector['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sector['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-input" required>
                            <option value="in_progress" <?php echo in_array($emergency['status'], ['executing', 'in_progress']) ? 'selected' : ''; ?>>In Progress</option>
                            <option value="hold" <?php echo $emergency['status'] === 'hold' ? 'selected' : ''; ?>>Hold</option>
                            <option value="completed" <?php echo $emergency['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Severity Level *</label>
                        <select name="severity_level" class="form-input" required>
                            <option value="medium" <?php echo $emergency['severity_level'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $emergency['severity_level'] === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="critical" <?php echo $emergency['severity_level'] === 'critical' ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-input" required value="<?php echo htmlspecialchars($emergency['title']); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description *</label>
                    <textarea name="description" class="form-input" rows="4" required><?php echo htmlspecialchars($emergency['description']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-input" value="<?php echo htmlspecialchars($emergency['address'] ?? ''); ?>">
                </div>
                
                <div class="form-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <div class="form-group">
                        <label class="form-label">Latitude</label>
                        <input type="text" name="latitude" class="form-input" value="<?php echo $emergency['latitude']; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Longitude</label>
                        <input type="text" name="longitude" class="form-input" value="<?php echo $emergency['longitude']; ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid #e2e8f0;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Emergency
                    </button>
                    <a href="emergencies.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
document.getElementById('editEmergencyForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    fetch('ajax/update_emergency.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            alert('Emergency updated successfully!');
            window.location.href = 'emergencies.php';
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Emergency';
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('An error occurred');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Emergency';
    });
});
</script>
