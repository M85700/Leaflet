<?php
require_once 'config.php';
require_once 'functions.php';

requireLogin();

global $conn;
$user = getCurrentUser();

// Check permission to view sectors
// super_admin ALWAYS has access (unlimited)
if (!hasRoleCapability('sectors_view')) {
    redirect('dashboard.php', 'Access denied', 'error');
}

$sectors = $conn->query("SELECT s.*, s.name AS sector_name, s.code AS sector_code 
    FROM sectors s 
    ORDER BY s.name")->fetchAll();

$users = $conn->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();

$page_title = 'Sectors Management';
include 'includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <header class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-map-marked-alt"></i>
                    Sectors Management
                </h1>
            </div>
            <div>
                <?php if ($user['role'] === 'super_admin'): ?>
                    <button type="button" class="btn btn-primary" onclick="showAddSectorModal()">
                        <i class="fas fa-plus"></i> Add Sector
                    </button>
                <?php else: ?>
                    <span class="badge badge-info" style="padding: 0.75rem 1rem;">
                        <i class="fas fa-info-circle"></i> Only Super Admin can add sectors
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <section class="content-section">
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
                <?php foreach ($sectors as $sector): ?>
                    <div style="background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid #10b981;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                            <div>
                                <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem; color: #1f2937;">
                                    <?php echo htmlspecialchars($sector['sector_name']); ?>
                                </h3>
                                <div style="font-size: 0.875rem; color: #6b7280; font-family: monospace;">
                                    <?php echo htmlspecialchars($sector['sector_code']); ?>
                                </div>
                            </div>
                            <?php if ($sector['is_active']): ?>
                                <span style="background: #10b981; color: white; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">
                                    Active
                                </span>
                            <?php else: ?>
                                <span style="background: #ef4444; color: white; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">
                                    Inactive
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($sector['description'])): ?>
                            <p style="font-size: 0.875rem; color: #6b7280; margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($sector['description']); ?>
                            </p>
                        <?php endif; ?>

                        <div style="display: flex; gap: 0.5rem;">
                            <button type="button" class="btn btn-sm btn-primary" onclick="editSector(<?php echo $sector['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <?php if ($user['role'] === 'super_admin'): ?>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteSector(<?php echo $sector['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>

<!-- Sector Modal -->
<div id="sectorModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; max-width: 500px; width: 90%; padding: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700;">
                <span id="sectorModalTitle">Add Sector</span>
            </h2>
            <button type="button" onclick="closeSectorModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="sectorForm" method="POST" action="ajax/manage_sector.php">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="sector_id" id="sectorId">

            <div class="form-group">
                <label class="form-label">Sector Name <span style="color: red;">*</span></label>
                <input type="text" class="form-input" name="sector_name" id="sectorName" required>
            </div>

            <div class="form-group">
                <label class="form-label">Sector Code <span style="color: red;">*</span></label>
                <input type="text" class="form-input" name="sector_code" id="sectorCode" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea class="form-input" name="description" id="sectorDescription" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <select class="form-input" name="is_active" id="sectorIsActive">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeSectorModal()">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}
</style>

<script>
function showAddSectorModal() {
    document.getElementById('sectorModal').style.display = 'flex';
    document.getElementById('sectorModalTitle').textContent = 'Add Sector';
    document.getElementById('sectorForm').reset();
    document.querySelector('[name="action"]').value = 'create';
}

function closeSectorModal() {
    document.getElementById('sectorModal').style.display = 'none';
}

function editSector(id) {
    fetch('ajax/get_sector.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('sectorModal').style.display = 'flex';
                document.getElementById('sectorModalTitle').textContent = 'Edit Sector';
                document.getElementById('sectorId').value = data.sector.id;
                document.getElementById('sectorName').value = data.sector.name;
                document.getElementById('sectorCode').value = data.sector.code;
                document.getElementById('sectorDescription').value = data.sector.description || '';
                document.getElementById('sectorIsActive').value = data.sector.is_active;
                document.querySelector('[name="action"]').value = 'update';
            }
        });
}

function deleteSector(id) {
    if (confirm('Are you sure you want to delete this sector?')) {
        fetch('ajax/manage_sector.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=delete&sector_id=' + id
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Error deleting sector');
            }
        });
    }
}

document.getElementById('sectorForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('ajax/manage_sector.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Error saving sector');
        }
    });
});
</script>

<?php include 'includes/scripts.php'; ?>
