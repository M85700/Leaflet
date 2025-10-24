<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();
global $conn;
$user = $_SESSION['user'];

// ========================================
// FETCH DATA
// ========================================

// Emergencies - Filter by role and sector using helper function
try {
    $sector_filter = getUserSectorFilter($user, 'e');
    
    $sql = "SELECT 
        'emergency' as type, e.id, e.title as ref, e.title, 
        e.severity_level as priority, e.status, et.name as emergency_type, e.latitude, e.longitude, 
        e.description, e.created_at
        FROM emergencies e
        LEFT JOIN emergency_types et ON e.emergency_type_id = et.id
        WHERE e.latitude IS NOT NULL AND e.longitude IS NOT NULL
        {$sector_filter}
        ORDER BY e.created_at DESC";
    
    $emergencies = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $emergencies = [];
    error_log("Emergencies query error: " . $e->getMessage());
}

// Map Markers - Apply sector-based filtering
try {
    // Build sector filter for markers (same logic as emergencies)
    $marker_filter = getUserSectorFilter($user, 'm');
    
    $marker_sql = "SELECT 
        'marker' as type, m.id, m.marker_name as ref, m.marker_name as title,
        m.status as priority, m.status, m.latitude, m.longitude, 
        m.description, m.created_at, m.asset_type_code, at.icon as asset_icon
        FROM map_markers m
        LEFT JOIN asset_types at ON m.asset_type_code = at.code
        WHERE m.latitude IS NOT NULL AND m.longitude IS NOT NULL
        {$marker_filter}
        ORDER BY m.created_at DESC";
    
    $markers = $conn->query($marker_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $markers = [];
    error_log("Markers query error: " . $e->getMessage());
}

// Merge emergencies and markers
$all_locations = array_merge($emergencies, $markers);

$page_title = 'Live Map';

// Enable maps (Leaflet)
$use_maps = true;

include 'includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-map-marked-alt"></i> Live Map - Real-time Tracking
                </h1>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #1f2937;">
                    <?php echo count($all_locations); ?>
                </div>
                <div style="font-size: 0.875rem; color: #6b7280;">Total Locations</div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
            <div class="stat-card orange" onclick="filterMap('emergency')" style="cursor: pointer;">
                <div class="stat-card-body">
                    <div class="stat-card-title"><i class="fas fa-exclamation-triangle"></i> Emergencies</div>
                    <div class="stat-card-value"><?php echo count($emergencies); ?></div>
                </div>
            </div>
            <div class="stat-card green" onclick="filterMap('marker')" style="cursor: pointer;">
                <div class="stat-card-body">
                    <div class="stat-card-title"><i class="fas fa-map-pin"></i> Map Markers</div>
                    <div class="stat-card-value"><?php echo count($markers); ?></div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="content-section" style="margin-bottom: 1rem;">
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn btn-sm btn-primary" onclick="filterMap('all')" id="btn-all">
                    <i class="fas fa-globe"></i> All
                </button>
                <button class="btn btn-sm btn-secondary" onclick="filterMap('emergency')" id="btn-emergency">
                    <i class="fas fa-exclamation-triangle"></i> Emergencies
                </button>
                <button class="btn btn-sm btn-secondary" onclick="filterMap('marker')" id="btn-marker">
                    <i class="fas fa-map-pin"></i> Markers
                </button>
            </div>
        </div>

        <!-- Map with Legend -->
        <div class="content-section">
            <div class="map-legend-container">
                <div id="map"></div>
                
                <!-- Legend Outside Map -->
                <div id="mapLegend">
                    <h4 style="margin: 0 0 16px 0; font-weight: 700; font-size: 1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; color: #1f2937;">
                        <i class="fas fa-info-circle"></i> Legend
                    </h4>
                    
                    <!-- Status Colors -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-weight: 600; font-size: 0.9rem; margin-bottom: 12px; color: #374151;">Status</div>
                        
                        <div style="display: flex; align-items: center; margin-bottom: 8px;">
                            <div class="animate-pulse" style="width: 18px; height: 18px; border-radius: 50%; background: #ef4444; margin-right: 10px; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);"></div>
                            <span style="font-size: 0.8rem; color: #4b5563;">New / Hold (Blinking)</span>
                        </div>
                        
                        <div style="display: flex; align-items: center; margin-bottom: 8px;">
                            <div class="animate-pulse" style="width: 18px; height: 18px; border-radius: 50%; background: #f59e0b; margin-right: 10px; box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);"></div>
                            <span style="font-size: 0.8rem; color: #4b5563;">In Progress (Blinking)</span>
                        </div>
                        
                        <div style="display: flex; align-items: center; margin-bottom: 8px;">
                            <div style="width: 18px; height: 18px; border-radius: 50%; background: #10b981; margin-right: 10px; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);"></div>
                            <span style="font-size: 0.8rem; color: #4b5563;">Completed</span>
                        </div>
                    </div>
                    
                    <!-- Assets Status -->
                    <div style="padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                        <div style="font-weight: 600; font-size: 0.9rem; margin-bottom: 12px; color: #374151;">Asset Status</div>
                        
                        <div style="display: flex; align-items: center; margin-bottom: 8px;">
                            <div style="width: 18px; height: 18px; border-radius: 50%; background: #10b981; display: flex; align-items: center; justify-content: center; margin-right: 10px; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);">
                                <i class="fas fa-check" style="color: white; font-size: 9px;"></i>
                            </div>
                            <span style="font-size: 0.8rem; color: #4b5563;">Active</span>
                        </div>
                        
                        <div style="display: flex; align-items: center; margin-bottom: 8px;">
                            <div style="width: 18px; height: 18px; border-radius: 50%; background: #ef4444; display: flex; align-items: center; justify-content: center; margin-right: 10px; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);">
                                <i class="fas fa-times" style="color: white; font-size: 9px;"></i>
                            </div>
                            <span style="font-size: 0.8rem; color: #4b5563;">Inactive</span>
                        </div>
                        
                        <div style="display: flex; align-items: center;">
                            <div style="width: 18px; height: 18px; border-radius: 50%; background: #f59e0b; display: flex; align-items: center; justify-content: center; margin-right: 10px; box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);">
                                <i class="fas fa-wrench" style="color: white; font-size: 9px;"></i>
                            </div>
                            <span style="font-size: 0.8rem; color: #4b5563;">Under Maintenance</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
<script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>

<script>
const mapData = {
    locations: <?php echo json_encode($all_locations); ?>,
    markers: <?php echo json_encode($markers); ?>
};

console.log('📊 Map Data Loaded:', {
    emergencies: <?php echo count($emergencies); ?>,
    markers: <?php echo count($markers); ?>,
    total: <?php echo count($all_locations) + count($markers); ?>
});

let map, cluster, allMarkers = [];

// Emergency type icons & colors - Matching Database emergency_types table
const emergencyTypes = {
    'water_accumulation': { icon: 'fa-water', color: '#2563eb' },
    'asphalt_road_damage': { icon: 'fa-road', color: '#ef4444' },
    'temporary_road_damage': { icon: 'fa-road-circle-exclamation', color: '#f59e0b' },
    'road_shoulder_collapse': { icon: 'fa-road-barrier', color: '#dc2626' },
    'sand_collapse': { icon: 'fa-mountain', color: '#d97706' },
    'rock_collapse': { icon: 'fa-mountain-sun', color: '#78350f' },
    'traffic_signal_failure': { icon: 'fa-traffic-light', color: '#dc2626' },
    'street_lights_malfunction': { icon: 'fa-lightbulb', color: '#fbbf24' },
    'fallen_trees': { icon: 'fa-tree', color: '#10b981' },
    'fallen_solid_objects': { icon: 'fa-cube', color: '#8b5cf6' },
    'traffic_accident': { icon: 'fa-car-burst', color: '#dc2626' },
    'other': { icon: 'fa-exclamation-triangle', color: '#64748b' }
};

// Status colors - 4 Status System
const statusColors = {
    'new': '#ef4444',           // Red - New emergency awaiting manager (Blinking)
    'in_progress': '#f59e0b',   // Orange - In Progress (Blinking)
    'executing': '#f59e0b',     // Legacy support
    'hold': '#ef4444',          // Red - On Hold (Blinking)
    'completed': '#10b981'      // Green - Completed (No Blinking)
};

document.addEventListener('DOMContentLoaded', () => {
    // Initialize map - Ras Al Khaimah
    map = L.map('map').setView([25.7896, 55.9433], 11);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 19
    }).addTo(map);
    
    cluster = L.markerClusterGroup({
        maxClusterRadius: 50,
        spiderfyOnMaxZoom: true
    });
    map.addLayer(cluster);
    
    // Add all markers (locations already contains emergencies + markers)
    mapData.locations.forEach(item => {
        if (item.latitude && item.longitude) {
            let iconHtml, iconColor, iconClass;
            
            if (item.type === 'emergency') {
                // Emergency markers - icon based on type, color based on status
                const typeInfo = emergencyTypes[item.emergency_type] || { icon: 'fa-exclamation-triangle', color: '#ef4444' };
                const statusColor = statusColors[item.status] || '#ef4444';
                
                // Blinking for new/in_progress/hold only
                const blinking = (item.status === 'new' || item.status === 'in_progress' || item.status === 'executing' || item.status === 'hold') 
                    ? 'animate-pulse' : '';
                
                iconHtml = `<div class="${blinking}" style="background: ${statusColor}; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 3px 8px rgba(0,0,0,0.4);">
                    <i class="fas ${typeInfo.icon}" style="color: white; font-size: 16px;"></i>
                </div>`;
            } else {
                // Map markers (assets) - Color based on status
                const assetStatusColors = {
                    'active': '#10b981',
                    'inactive': '#ef4444',
                    'maintenance': '#f59e0b'
                };
                const assetColor = assetStatusColors[item.status] || '#10b981';
                const assetIcon = item.asset_icon || 'fa-map-pin';
                
                iconHtml = `<div style="background: ${assetColor}; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);">
                    <i class="fas ${assetIcon}" style="color: white; font-size: 14px;"></i>
                </div>`;
            }
            
            const icon = L.divIcon({
                html: iconHtml,
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                className: ''
            });
            
            const marker = L.marker([parseFloat(item.latitude), parseFloat(item.longitude)], {icon});
            marker.itemType = item.type;
            
            // Popup content with clickable link
            const typeInfo = emergencyTypes[item.emergency_type] || { icon: 'fa-circle' };
            const typeName = item.emergency_type || 'Unknown';
            const statusBadgeColor = item.status === 'completed' ? 'success' : ((item.status === 'in_progress' || item.status === 'executing') ? 'warning' : (item.status === 'hold' || item.status === 'new' ? 'danger' : 'secondary'));
            const detailsUrl = item.type === 'emergency' ? `view_emergency.php?id=${item.id}` : `view_map_marker.php?id=${item.id}`;
            
            marker.bindPopup(`
                <div style="min-width: 280px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                    <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.5rem; color: #1f2937;">${item.title || 'Item'}</div>
                    ${item.emergency_type ? `<div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">
                        <i class="fas ${typeInfo.icon}"></i> ${typeName}
                    </div>` : ''}
                    ${item.description ? `<p style="font-size: 0.875rem; margin-bottom: 0.75rem; color: #4b5563; line-height: 1.4;">${item.description.substring(0, 120)}...</p>` : ''}
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;">
                        <span class="badge badge-${statusBadgeColor}" style="text-transform: capitalize; padding: 0.375rem 0.75rem;">${item.status || 'active'}</span>
                        <a href="${detailsUrl}" style="background: #2563eb; color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.875rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; transition: background 0.2s;" onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
            `);
            
            cluster.addLayer(marker);
            allMarkers.push(marker);
        }
    });
    
    // Legend moved outside map (see HTML)
    
    // Fit bounds
    if (allMarkers.length > 0) {
        const group = L.featureGroup(allMarkers);
        map.fitBounds(group.getBounds().pad(0.1));
    }
    
    console.log('✅ Map initialized with', allMarkers.length, 'markers');
});

function filterMap(type) {
    // Update button styles
    document.querySelectorAll('[id^="btn-"]').forEach(btn => {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
    });
    document.getElementById('btn-' + type).classList.remove('btn-secondary');
    document.getElementById('btn-' + type).classList.add('btn-primary');
    
    // Filter markers
    cluster.clearLayers();
    allMarkers.forEach(marker => {
        if (type === 'all' || marker.itemType === type) {
            cluster.addLayer(marker);
        }
    });
}
</script>

<style>
/* Blinking Animation */
@keyframes pulse {
    0%, 100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.6;
        transform: scale(1.05);
    }
}

.animate-pulse {
    animation: pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

.stat-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.stat-card.red { border-left: 4px solid #ef4444; }
.stat-card.orange { border-left: 4px solid #dc2626; }
.stat-card.blue { border-left: 4px solid #8b5cf6; }
.stat-card.green { border-left: 4px solid #10b981; }
.stat-card-title {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
}
.stat-card-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
}

/* Map and Legend Responsive Layout */
@media (max-width: 968px) {
    #mapLegend {
        min-width: 100% !important;
        margin-top: 1rem;
    }
    .content-section > div {
        grid-template-columns: 1fr !important;
    }
}
</style>
