<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = getCurrentUser();

// Check if user has permission to view assets
if (!hasRoleCapability('assets_view')) {
    die('Access denied - You do not have permission to view assets');
}

$page_title = 'Map Markers - Assets Management';

// Enable maps (Leaflet)
$use_maps = true;

// Get asset types from database (no color column!)
$asset_types = $conn->query("SELECT id, name, code, icon FROM asset_types WHERE is_active = 1 ORDER BY id")->fetchAll();

// Get sectors available to user (for sector dropdown)
$user_sectors = getUserSectors($user);

// Build sector-based query filter
$sector_filter = "";
$query_params = [];

if (in_array($user['role'], ['super_admin', 'admin', 'inspection'])) {
    // Super admin, admin, and inspection see all assets
    $sector_filter = "";
} elseif (in_array($user['role'], ['sector_manager', 'supervisor'])) {
    // Sector manager and supervisor see only their sectors (from user_sectors table)
    $sector_ids = getUserSectorIds($user['id']);
    if (!empty($sector_ids)) {
        $placeholders = implode(',', array_fill(0, count($sector_ids), '?'));
        $sector_filter = " WHERE m.sector_id IN ($placeholders)";
        $query_params = $sector_ids;
    } else {
        // No sectors assigned - no access
        $sector_filter = " WHERE 1=0";
    }
} else {
    // No sectors assigned - no access
    $sector_filter = " WHERE 1=0";
}

// Get all map markers (filtered by user's sectors)
$sql = "SELECT m.*,
        u.full_name as created_by_name,
        at.name AS type_name, at.icon,
        s.name as sector_name
        FROM map_markers m
        LEFT JOIN users u ON m.created_by = u.id
        LEFT JOIN asset_types at ON m.asset_type_code = at.code
        LEFT JOIN sectors s ON m.sector_id = s.id
        {$sector_filter}
        ORDER BY m.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($query_params);
$markers = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-map-pin"></i>
                    Map Markers - Assets Management
                </h1>
                <p class="page-subtitle">Manage fixed infrastructure assets on the map</p>
            </div>
            <?php if (hasRoleCapability('assets_create')): ?>
            <button onclick="openAddMarkerModal()" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add New Asset
            </button>
            <?php endif; ?>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 2rem;">
            <div class="stat-card blue">
                <div class="stat-card-body">
                    <div class="stat-card-title">Total Assets</div>
                    <div class="stat-card-value"><?php echo count($markers); ?></div>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-card-body">
                    <div class="stat-card-title">Active</div>
                    <div class="stat-card-value"><?php echo count(array_filter($markers, function($m) { return isset($m['status']) && $m['status'] === 'active'; })); ?></div>
                </div>
            </div>
            <div class="stat-card yellow">
                <div class="stat-card-body">
                    <div class="stat-card-title">Maintenance</div>
                    <div class="stat-card-value"><?php echo count(array_filter($markers, function($m) { return isset($m['status']) && $m['status'] === 'maintenance'; })); ?></div>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-card-body">
                    <div class="stat-card-title">Issues</div>
                    <div class="stat-card-value"><?php echo count(array_filter($markers, function($m) { return isset($m['status']) && in_array($m['status'], ['faulty', 'out_of_service']); })); ?></div>
                </div>
            </div>
        </div>

        <!-- Map Container -->
        <div class="content-section" style="margin-bottom: 2rem;">
            <div id="assetsMap" style="height: 500px; border-radius: var(--radius-lg); overflow: hidden;"></div>
        </div>

        <!-- Assets Table -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list"></i>
                    All Assets (<?php echo count($markers); ?>)
                </h2>
            </div>
            
            <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table class="data-table" style="min-width: 1200px;">
                    <thead>
                        <tr>
                            <th style="min-width: 120px;">Type</th>
                            <th style="min-width: 150px;">Name</th>
                            <th style="min-width: 200px;">Description</th>
                            <th style="min-width: 150px;">Location</th>
                            <th style="min-width: 120px;">Status</th>
                            <th style="min-width: 120px;">Created By</th>
                            <th style="min-width: 120px;">Created At</th>
                            <th style="min-width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($markers)): ?>
                        <tr>
                            <td colspan="8" class="text-center">No assets found</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($markers as $marker): ?>
                        <tr>
                            <td>
                                <?php echo getAssetTypeBadge($marker['asset_type_code'], $marker['other_type_description']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($marker['marker_name']); ?></td>
                            <td><?php echo htmlspecialchars(substr($marker['description'] ?? '', 0, 50)) . '...'; ?></td>
                            <td>
                                <small><?php echo number_format($marker['latitude'], 6); ?>, <?php echo number_format($marker['longitude'], 6); ?></small>
                            </td>
                            <td>
                                <?php echo getAssetStatusBadge($marker['status'] ?? 'active'); ?>
                            </td>
                            <td><?php echo htmlspecialchars($marker['created_by_name'] ?? 'N/A'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($marker['created_at'])); ?></td>
                            <td>
                                <div style="display: flex; gap: 0.5rem;">
                                    <?php 
                                    // Check if user can edit this asset (must have permission AND asset must be in user's sectors)
                                    $can_edit = hasRoleCapability('assets_edit');
                                    $can_delete = hasRoleCapability('assets_delete');
                                    
                                    // For sector_manager and supervisor, also check sector ownership
                                    if (in_array($user['role'], ['sector_manager', 'supervisor']) && !empty($marker['sector_id'])) {
                                        $can_edit = $can_edit && canAccessSector($marker['sector_id']);
                                        $can_delete = $can_delete && canAccessSector($marker['sector_id']);
                                    }
                                    ?>
                                    <?php if ($can_edit): ?>
                                    <button onclick='openEditModal(<?php echo json_encode($marker); ?>)' class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                    <button onclick="showDeleteConfirmation(<?php echo $marker['id']; ?>)" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Add Marker Modal -->
<div id="addMarkerModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Add New Asset</h3>
            <button onclick="closeAddMarkerModal()" class="btn-close">&times;</button>
        </div>
        <form id="addMarkerForm">
            <div class="modal-body">
                <!-- Asset Type -->
                <div class="form-group">
                    <label class="form-label required">Asset Type</label>
                    <div class="asset-type-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                        <?php foreach ($asset_types as $type): ?>
                        <div class="asset-type-option">
                            <input type="radio" 
                                   name="asset_type_code" 
                                   value="<?php echo $type['code']; ?>" 
                                   id="asset_<?php echo $type['code']; ?>"
                                   <?php echo $type['code'] === 'AST-OTHER' ? 'data-show-description="true"' : ''; ?>
                                   style="position: absolute; opacity: 0;">
                            <label for="asset_<?php echo $type['code']; ?>" 
                                   class="type-label" 
                                   style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 0.5rem; cursor: pointer;">
                                <i class="fas <?php echo $type['icon']; ?>" style="font-size: 20px; color: #6b7280;"></i>
                                <span><?php echo $type['name']; ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Other Type Description -->
                <div class="form-group" id="otherAssetContainer" style="display: none;">
                    <label class="form-label required">Specify Other Type</label>
                    <input type="text" name="other_type_description" id="otherAssetDescription" class="form-control">
                </div>

                <!-- Asset Name -->
                <div class="form-group">
                    <label class="form-label required">Asset Name</label>
                    <input type="text" name="marker_name" class="form-control" required>
                </div>

                <!-- Sector Selection -->
                <?php if (!empty($user_sectors)): ?>
                <div class="form-group">
                    <label class="form-label required">Sector</label>
                    <select name="sector_id" class="form-control" required>
                        <option value="">Select Sector</option>
                        <?php foreach ($user_sectors as $sector): ?>
                        <option value="<?php echo $sector['id']; ?>"><?php echo htmlspecialchars($sector['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Description -->
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>

                <!-- Map -->
                <div class="form-group">
                    <label class="form-label required">Location</label>
                    <button type="button" id="useCurrentLocationAsset" class="btn btn-sm btn-primary" style="margin-bottom: 1rem;">
                        <i class="fas fa-location-arrow"></i> Use My Current Location
                    </button>
                    <div id="assetMap" style="height: 300px; border-radius: 0.5rem; overflow: hidden;"></div>
                </div>

                <!-- Coordinates -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label required">Latitude</label>
                        <input type="text" name="latitude" id="assetLatitude" class="form-control" required readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Longitude</label>
                        <input type="text" name="longitude" id="assetLongitude" class="form-control" required readonly>
                    </div>
                </div>

                <!-- Address -->
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="location_address" id="assetAddress" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeAddMarkerModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Marker Modal -->
<div id="editMarkerModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Edit Asset</h3>
            <button onclick="closeEditModal()" class="btn-close">&times;</button>
        </div>
        <form id="editMarkerForm">
            <input type="hidden" name="marker_id" id="editMarkerId">
            <div class="modal-body">
                <!-- Asset Name -->
                <div class="form-group">
                    <label class="form-label required">Asset Name</label>
                    <input type="text" name="marker_name" id="editMarkerName" class="form-control" required>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                </div>

                <!-- Status -->
                <div class="form-group">
                    <label class="form-label required">Status</label>
                    <select name="status" id="editStatus" class="form-control" required>
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="faulty">Faulty</option>
                        <option value="out_of_service">Out of Service</option>
                        <option value="decommissioned">Decommissioned</option>
                    </select>
                </div>

                <!-- Address -->
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="location_address" id="editAddress" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- Custom Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3 style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
            <button onclick="closeDeleteConfirmation()" class="btn-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size: 1.1rem; margin: 1.5rem 0;">Are you sure you want to delete this asset?</p>
            <p style="color: #6b7280;">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeDeleteConfirmation()" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="confirmDelete()" class="btn btn-danger">
                <i class="fas fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<style>
.asset-type-option input[type="radio"]:checked + .type-label {
    border-color: var(--primary-blue);
    background-color: var(--primary-blue-light);
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}
</style>

<script>
// Main map - Initialize after Leaflet loads
document.addEventListener('DOMContentLoaded', function() {
const assetsMap = L.map('assetsMap').setView([25.7896, 55.9433], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(assetsMap);

// Status colors mapping
const statusColors = {
    'active': '#28A745',           // أخضر - شغال
    'maintenance': '#FFC107',      // أصفر - صيانة
    'faulty': '#FF8C00',          // برتقالي - معطل
    'out_of_service': '#DC3545',  // أحمر - خارج الخدمة
    'decommissioned': '#6C757D'   // رمادي - ملغي
};

// Load markers
const markers = <?php echo json_encode($markers); ?>;
if (markers && markers.length > 0) {
    markers.forEach(marker => {
        // Get color from status (not from type!)
        const markerColor = statusColors[marker.status] || '#6b7280';
        const markerIcon = marker.icon || 'fa-circle-question';
        const markerTypeName = marker.type_name || 'Unknown';
        const markerStatus = marker.status || 'unknown';
        
        const icon = L.divIcon({
            html: `<div style="background: ${markerColor}; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; border: 2px solid white;">
                <i class="fas ${markerIcon}" style="font-size: 14px;"></i>
            </div>`,
            className: '',
            iconSize: [30, 30]
        });
        
        L.marker([marker.latitude, marker.longitude], { icon: icon })
            .addTo(assetsMap)
            .bindPopup(`<strong>${marker.marker_name}</strong><br>Type: ${markerTypeName}<br>Status: ${markerStatus}`);
    });
} else {
    // No markers - map still displays
    console.log('No markers to display');
}

let assetMap, assetMarker;

function openAddMarkerModal() {
    document.getElementById('addMarkerModal').classList.add('active');
    setTimeout(() => {
        if (!assetMap) {
            assetMap = L.map('assetMap').setView([25.7896, 55.9433], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(assetMap);
            
            assetMap.on('click', function(e) {
                updateAssetLocation(e.latlng.lat, e.latlng.lng);
            });
        }
        assetMap.invalidateSize();
    }, 100);
}

function closeAddMarkerModal() {
    document.getElementById('addMarkerModal').classList.remove('active');
}

function updateAssetLocation(lat, lng) {
    document.getElementById('assetLatitude').value = lat.toFixed(8);
    document.getElementById('assetLongitude').value = lng.toFixed(8);
    
    if (assetMarker) assetMap.removeLayer(assetMarker);
    assetMarker = L.marker([lat, lng]).addTo(assetMap);
    
    fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`)
        .then(res => res.json())
        .then(data => {
            document.getElementById('assetAddress').value = data.display_name || '';
        });
}

// Asset type selection
document.querySelectorAll('input[name="asset_type_code"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const otherContainer = document.getElementById('otherAssetContainer');
        const otherDescription = document.getElementById('otherAssetDescription');
        
        if (this.value === 'AST-OTHER') {
            otherContainer.style.display = 'block';
            otherDescription.required = true;
        } else {
            otherContainer.style.display = 'none';
            otherDescription.required = false;
        }
    });
});

// Current location
document.getElementById('useCurrentLocationAsset')?.addEventListener('click', function() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            updateAssetLocation(lat, lng);
            assetMap.setView([lat, lng], 16);
        });
    }
});

// Form submission
document.getElementById('addMarkerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('ajax/create_map_marker.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Asset added successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
});

// Delete Confirmation
let deleteMarkerId = null;

function showDeleteConfirmation(id) {
    deleteMarkerId = id;
    document.getElementById('deleteConfirmModal').classList.add('active');
}

function closeDeleteConfirmation() {
    deleteMarkerId = null;
    document.getElementById('deleteConfirmModal').classList.remove('active');
}

function confirmDelete() {
    if (!deleteMarkerId) return;
    
    fetch('ajax/delete_map_marker.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: deleteMarkerId})
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

// Edit Modal
function openEditModal(marker) {
    document.getElementById('editMarkerId').value = marker.id;
    document.getElementById('editMarkerName').value = marker.marker_name || '';
    document.getElementById('editDescription').value = marker.description || '';
    document.getElementById('editStatus').value = marker.status || 'active';
    document.getElementById('editAddress').value = marker.location_address || '';
    document.getElementById('editMarkerModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editMarkerModal').classList.remove('active');
}

// Edit Form Submission
document.getElementById('editMarkerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('ajax/update_map_marker.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
});
});
</script>
