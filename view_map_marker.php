<?php
/**
 * View Map Marker (Asset) Detail Page
 * Displays detailed information about a specific asset marker
 */

require_once 'config.php';
require_once 'functions.php';

requireLogin();

global $conn;
$user = $_SESSION['user'];

// Get marker ID from URL
$marker_id = $_GET['marker_id'] ?? $_GET['id'] ?? 0;

if (!$marker_id) {
    $_SESSION['error_message'] = 'Marker ID is required';
    header('Location: maps.php');
    exit;
}

// Fetch marker data with sector info
$stmt = $conn->prepare("
    SELECT 
        m.*,
        s.name as sector_name,
        s.color as sector_color,
        at.name as asset_type_name,
        at.icon as asset_type_icon
    FROM map_markers m
    LEFT JOIN sectors s ON m.sector_id = s.id
    LEFT JOIN asset_types at ON m.asset_type_id = at.id
    WHERE m.id = :id
");

$stmt->execute([':id' => $marker_id]);
$marker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$marker) {
    $_SESSION['error_message'] = 'Asset not found';
    header('Location: maps.php');
    exit;
}

// Check sector access permissions
// Super admin, admin, and inspection have access to all assets
if (!in_array($user['role'], ['super_admin', 'admin', 'inspection'])) {
    $allowed_sectors = [];
    
    // Get user's allowed sectors
    if (in_array($user['role'], ['sector_manager', 'supervisor'])) {
        $allowed_sectors = getUserSectorIds($user['id']);
    } elseif (!empty($user['sector_id'])) {
        $allowed_sectors = [$user['sector_id']];
    }
    
    // SECURITY: Block access if no sectors assigned OR sector mismatch
    if (empty($allowed_sectors) || !in_array($marker['sector_id'], $allowed_sectors)) {
        $_SESSION['error_message'] = 'You do not have permission to view this asset';
        header('Location: maps.php');
        exit;
    }
}

$page_title = "View Asset";
$custom_back_url = 'maps.php';
$custom_back_text = 'Back to Maps';

// Enable maps (Leaflet)
$use_maps = true;
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="dashboard-wrapper">
    <main class="main-content">
        <?php include 'includes/components/back_button.php'; ?>
        
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="<?php echo htmlspecialchars($marker['asset_type_icon'] ?? 'fas fa-map-marker-alt'); ?>"></i>
                    <?php echo htmlspecialchars($marker['name']); ?>
                </h1>
                <p class="page-subtitle">Asset Details</p>
            </div>
            <a href="map_markers.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> Manage Assets
            </a>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <?php 
                echo htmlspecialchars($_SESSION['error_message']); 
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="content-section" style="max-width: 1200px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                
                <!-- Asset Information -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-info-circle"></i> Asset Information
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="detail-group">
                            <label class="detail-label">Asset Name</label>
                            <div class="detail-value"><?php echo htmlspecialchars($marker['name']); ?></div>
                        </div>
                        
                        <div class="detail-group">
                            <label class="detail-label">Asset Type</label>
                            <div class="detail-value">
                                <i class="<?php echo htmlspecialchars($marker['asset_type_icon']); ?>" style="margin-right: 0.5rem;"></i>
                                <?php echo htmlspecialchars($marker['asset_type_name'] ?? 'Unknown'); ?>
                            </div>
                        </div>
                        
                        <div class="detail-group">
                            <label class="detail-label">Sector</label>
                            <div class="detail-value">
                                <span class="badge" style="background-color: <?php echo htmlspecialchars($marker['sector_color'] ?? '#6b7280'); ?>;">
                                    <?php echo htmlspecialchars($marker['sector_name'] ?? 'No Sector'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($marker['description']): ?>
                        <div class="detail-group">
                            <label class="detail-label">Description</label>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($marker['description'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-group">
                            <label class="detail-label">Status</label>
                            <div class="detail-value">
                                <?php if ($marker['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-error">Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($marker['created_at']): ?>
                        <div class="detail-group">
                            <label class="detail-label">Created</label>
                            <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($marker['created_at'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Location Map -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-map-marked-alt"></i> Location
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="detail-group">
                            <label class="detail-label">Coordinates</label>
                            <div class="detail-value">
                                <i class="fas fa-map-pin" style="margin-right: 0.5rem;"></i>
                                Lat: <?php echo number_format($marker['latitude'], 6); ?>,
                                Lng: <?php echo number_format($marker['longitude'], 6); ?>
                            </div>
                        </div>
                        
                        <?php if ($marker['address']): ?>
                        <div class="detail-group">
                            <label class="detail-label">Address</label>
                            <div class="detail-value"><?php echo htmlspecialchars($marker['address']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Mini Map -->
                        <div id="markerMap" style="height: 300px; border-radius: 8px; margin-top: 1rem; border: 1px solid var(--gray-200);"></div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="card" style="margin-top: 2rem;">
                <div class="card-body" style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <?php if (hasRoleCapability('map_markers_edit')): ?>
                    <a href="map_markers.php?edit=<?php echo $marker['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Asset
                    </a>
                    <?php endif; ?>
                    
                    <a href="maps.php?center=<?php echo $marker['latitude']; ?>,<?php echo $marker['longitude']; ?>" class="btn btn-secondary">
                        <i class="fas fa-map"></i> View on Map
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Initialize map after Leaflet loads
document.addEventListener('DOMContentLoaded', function() {
const markerLat = <?php echo $marker['latitude']; ?>;
const markerLng = <?php echo $marker['longitude']; ?>;

const map = L.map('markerMap').setView([markerLat, markerLng], 15);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

// Add marker
const markerIcon = L.divIcon({
    className: 'custom-marker-icon',
    html: '<i class="<?php echo htmlspecialchars($marker['asset_type_icon']); ?>" style="font-size: 24px; color: <?php echo htmlspecialchars($marker['sector_color'] ?? '#2563eb'); ?>;"></i>',
    iconSize: [30, 30],
    iconAnchor: [15, 15]
});

const marker = L.marker([markerLat, markerLng], { icon: markerIcon })
    .addTo(map)
    .bindPopup('<strong><?php echo addslashes($marker['name']); ?></strong>');

// Open popup by default
marker.openPopup();
});
</script>

<style>
.detail-group {
    margin-bottom: 1.5rem;
}

.detail-group:last-child {
    margin-bottom: 0;
}

.detail-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 1rem;
    color: var(--gray-900);
    padding: 0.75rem;
    background: var(--gray-50);
    border-radius: var(--radius-md);
    border: 1px solid var(--gray-200);
}

.custom-marker-icon {
    background: white;
    border-radius: 50%;
    border: 2px solid currentColor;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

@media (max-width: 992px) {
    .content-section > div {
        grid-template-columns: 1fr !important;
    }
}
</style>

</body>
</html>
