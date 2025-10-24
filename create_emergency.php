<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

if (!hasPermission('emergencies_create')) {
    die('Access denied');
}

global $conn;
$user = getCurrentUser();
$page_title = 'Create Emergency';

// Enable maps (Leaflet)
$use_maps = true;

// Get all emergency types
$emergency_types = getAllEmergencyTypes();

// Get sectors
$sectors = $conn->query("SELECT id, name, name AS sector_name, code FROM sectors WHERE is_active = 1 ORDER BY name")->fetchAll();

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
                    <i class="fas fa-exclamation-triangle"></i>
                    Create New Emergency Report
                </h1>
                <p class="page-subtitle">Report a new emergency incident</p>
            </div>
            <a href="emergencies.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <div class="content-section">
            <form id="createEmergencyForm" method="POST" action="ajax/create_emergency.php">
                <div class="form-grid">
                    <!-- Emergency Type -->
                    <div class="form-group full-width">
                        <label class="form-label required">Emergency Type</label>
                        <div class="emergency-type-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                            <?php foreach ($emergency_types as $type): ?>
                            <div class="emergency-type-option" style="position: relative;">
                                <input type="radio" 
                                       name="emergency_type" 
                                       value="<?php echo $type['code']; ?>" 
                                       id="type_<?php echo $type['code']; ?>"
                                       <?php echo $type['code'] === 'other' ? 'data-show-description="true"' : ''; ?>
                                       style="position: absolute; opacity: 0;">
                                <label for="type_<?php echo $type['code']; ?>" 
                                       class="type-label" 
                                       style="display: flex; align-items: center; gap: 0.75rem; padding: 1rem; border: 2px solid #e5e7eb; border-radius: 0.5rem; cursor: pointer; transition: all 0.2s;">
                                    <div class="type-icon" style="font-size: 24px; color: <?php echo $type['color']; ?>;">
                                        <i class="fas <?php echo $type['icon']; ?>"></i>
                                    </div>
                                    <div class="type-info" style="flex: 1;">
                                        <div style="font-weight: 600; color: #1f2937;"><?php echo $type['name']; ?></div>
                                    </div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Other Type Description (shown when "Other" is selected) -->
                    <div class="form-group full-width" id="otherTypeContainer" style="display: none;">
                        <label class="form-label required">Specify Other Type</label>
                        <input type="text" 
                               name="other_type_description" 
                               id="otherTypeDescription"
                               class="form-control" 
                               placeholder="Enter emergency type">
                    </div>

                    <!-- Title (Auto-filled from Emergency Type - Hidden) -->
                    <input type="hidden" name="title" id="emergencyTitle" value="" required>

                    <!-- Description -->
                    <div class="form-group full-width">
                        <label class="form-label required">Description</label>
                        <textarea name="description" class="form-control" rows="4" required></textarea>
                    </div>

                    <!-- Severity -->
                    <div class="form-group">
                        <label class="form-label required">Severity</label>
                        <select name="severity" class="form-control" required>
                            <option value="">Select...</option>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <!-- Sector -->
                    <div class="form-group">
                        <label class="form-label">Sector</label>
                        <select name="sector_id" class="form-control">
                            <option value="">Select...</option>
                            <?php foreach ($sectors as $sector): ?>
                            <option value="<?php echo $sector['id']; ?>">
                                <?php echo htmlspecialchars($sector['sector_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Location Section -->
                    <div class="form-group full-width">
                        <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-map-marker-alt"></i>
                            Location
                        </h3>
                    </div>

                    <!-- Map -->
                    <div class="form-group full-width">
                        <div style="display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap;">
                            <button type="button" id="useCurrentLocation" class="btn btn-primary">
                                <i class="fas fa-location-arrow"></i> Use Current Location
                            </button>
                            <button type="button" id="pickOnMap" class="btn btn-secondary">
                                <i class="fas fa-map-marked-alt"></i> Pick on Map
                            </button>
                        </div>
                        <div id="map"></div>
                    </div>

                    <!-- Latitude & Longitude -->
                    <div class="form-group">
                        <label class="form-label required">Latitude</label>
                        <input type="text" name="latitude" id="latitude" class="form-control" required readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Longitude</label>
                        <input type="text" name="longitude" id="longitude" class="form-control" required readonly>
                    </div>

                    <!-- Address -->
                    <div class="form-group full-width">
                        <label class="form-label">Address</label>
                        <input type="text" name="location_address" id="locationAddress" class="form-control">
                    </div>

                    <!-- Photos Section -->
                    <div class="form-group full-width">
                        <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-camera"></i>
                            Documentation Photos (Before)
                        </h3>
                        <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 1rem;">
                            Take photos of the emergency scene before any action is taken
                        </p>

                        <div id="photosList" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;"></div>

                        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                            <button type="button" onclick="capturePhoto()" class="btn btn-secondary">
                                <i class="fas fa-camera"></i> Take Photo
                            </button>
                            <button type="button" onclick="document.getElementById('photoUpload').click()" class="btn btn-secondary">
                                <i class="fas fa-upload"></i> Upload Photo
                            </button>
                        </div>

                        <input type="file" id="photoUpload" accept="image/*" multiple style="display: none;" onchange="handlePhotoUpload(event)">
                        <input type="file" id="cameraCapture" accept="image/*" capture="environment" style="display: none;" onchange="handlePhotoUpload(event)">
                    </div>
                </div>

                <div class="form-actions" style="margin-top: 2rem; display: flex; gap: 1rem; justify-content: flex-end;">
                    <a href="emergencies.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-save"></i> Create Report
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<style>
/* Emergency Type Selection Styles */
.emergency-type-option input[type="radio"]:checked + .type-label {
    border-color: var(--primary-blue);
    background-color: var(--primary-blue-light);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.type-label:hover {
    border-color: var(--primary-blue);
    background-color: var(--gray-50);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Initialize map centered on Ras Al Khaimah (after Leaflet is loaded)
document.addEventListener('DOMContentLoaded', function() {
const map = L.map('map').setView([25.7896, 55.9433], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let marker = null;

// Handle emergency type selection and auto-fill title
document.querySelectorAll('input[name="emergency_type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const otherContainer = document.getElementById('otherTypeContainer');
        const otherDescription = document.getElementById('otherTypeDescription');
        const titleField = document.getElementById('emergencyTitle');

        // Get the selected type label text
        const selectedLabel = this.closest('.emergency-type-option').querySelector('.type-info div').textContent;

        if (this.value === 'other') {
            otherContainer.style.display = 'block';
            otherDescription.required = true;
            // Title will be filled when user types in "other type description"
            titleField.value = '';
        } else {
            otherContainer.style.display = 'none';
            otherDescription.required = false;
            otherDescription.value = '';
            // Auto-fill title with emergency type name
            titleField.value = selectedLabel;
        }
    });
});

// Handle "Other Type Description" to auto-fill title
document.getElementById('otherTypeDescription').addEventListener('input', function() {
    const titleField = document.getElementById('emergencyTitle');
    const selectedType = document.querySelector('input[name="emergency_type"]:checked');

    if (selectedType && selectedType.value === 'other') {
        // Use the custom description as title
        titleField.value = this.value;
    }
});

// Use current location
document.getElementById('useCurrentLocation').addEventListener('click', function() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            updateLocation(lat, lng);
            map.setView([lat, lng], 16);
        }, function() {
            alert('Unable to get current location');
        });
    } else {
        alert('Browser does not support geolocation');
    }
});

// Pick on map
document.getElementById('pickOnMap').addEventListener('click', function() {
    alert('Click on the map to select location');
    map.on('click', function(e) {
        updateLocation(e.latlng.lat, e.latlng.lng);
    });
});

// Update location
function updateLocation(lat, lng) {
    document.getElementById('latitude').value = lat.toFixed(8);
    document.getElementById('longitude').value = lng.toFixed(8);

    if (marker) {
        map.removeLayer(marker);
    }

    marker = L.marker([lat, lng]).addTo(map);
    marker.bindPopup('Selected Location').openPopup();

    // Reverse geocode
    fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`)
        .then(res => res.json())
        .then(data => {
            document.getElementById('locationAddress').value = data.display_name || '';
        });
}

// Photos handling
let capturedPhotos = [];

function capturePhoto() {
    document.getElementById('cameraCapture').click();
}

function handlePhotoUpload(event) {
    const files = Array.from(event.target.files);
    files.forEach(file => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const photoData = {
                file: file,
                dataUrl: e.target.result,
                name: file.name
            };
            capturedPhotos.push(photoData);
            displayPhotos();
        };
        reader.readAsDataURL(file);
    });
    event.target.value = ''; // Reset input
}

function displayPhotos() {
    const photosList = document.getElementById('photosList');
    photosList.innerHTML = '';

    capturedPhotos.forEach((photo, index) => {
        const photoCard = document.createElement('div');
        photoCard.style.cssText = 'position: relative; border: 2px solid #e5e7eb; border-radius: 8px; overflow: hidden;';
        photoCard.innerHTML = `
            <img src="${photo.dataUrl}" alt="Photo ${index + 1}" style="width: 100%; height: 150px; object-fit: cover;">
            <button type="button" onclick="removePhoto(${index})" style="position: absolute; top: 8px; right: 8px; background: rgba(239, 68, 68, 0.9); color: white; border: none; border-radius: 50%; width: 28px; height: 28px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-times"></i>
            </button>
        `;
        photosList.appendChild(photoCard);
    });
}

function removePhoto(index) {
    capturedPhotos.splice(index, 1);
    displayPhotos();
}

// Form submission
document.getElementById('createEmergencyForm').addEventListener('submit', function(e) {
    e.preventDefault();

    // Validate emergency type is selected
    const emergencyTypeSelected = document.querySelector('input[name="emergency_type"]:checked');
    if (!emergencyTypeSelected) {
        alert('Please select an emergency type');
        return;
    }

    // Validate title is filled (should be auto-filled from type)
    const titleField = document.getElementById('emergencyTitle');
    if (!titleField.value.trim()) {
        alert('Please select an emergency type');
        return;
    }

    const formData = new FormData(this);

    // Add photos to formData
    capturedPhotos.forEach((photo, index) => {
        formData.append(`photos[]`, photo.file);
    });

    // Show loading message
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

    fetch('ajax/create_emergency.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Emergency report created successfully');
            window.location.href = 'emergencies.php';
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(err => {
        console.error(err);
        alert('System error occurred');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

// Sidebar Toggle Function
function toggleSidebar() {
    document.body.classList.toggle('fullscreen-mode');

    // Save preference to localStorage
    const isFullscreen = document.body.classList.contains('fullscreen-mode');
    localStorage.setItem('sidebarFullscreen', isFullscreen ? 'true' : 'false');
}

// Restore sidebar state on page load
window.addEventListener('DOMContentLoaded', function() {
    const savedState = localStorage.getItem('sidebarFullscreen');
    if (savedState === 'true') {
        document.body.classList.add('fullscreen-mode');
    }
});
});
