<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = $_SESSION['user'];

// Check permission to view users
// super_admin ALWAYS has access (unlimited)
if (!hasRoleCapability('users_view')) {
    $_SESSION['error'] = 'You do not have permission to manage users';
    header('Location: dashboard.php');
    exit;
}

// Get all users with sector count from user_sectors table
$users = $conn->query("SELECT u.*, s.name AS sector_name,
                      (SELECT COUNT(*) FROM user_sectors us WHERE us.user_id = u.id) as sectors_count
                      FROM users u 
                      LEFT JOIN sectors s ON u.sector_id = s.id 
                      ORDER BY u.created_at DESC")->fetchAll();

$sectors = getAllSectors();

$page_title = 'Users Management';
include 'includes/header.php';
?>

<!-- Select2 CSS for Multi-Select -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
.select2-container--default .select2-selection--multiple {
    background-color: white;
    border: 1px solid #cbd5e1;
    border-radius: 0.5rem;
    min-height: 48px;
    padding: 0.25rem;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #2563eb;
    border-color: #1e40af;
    color: white;
    padding: 0.375rem 0.625rem;
    margin-top: 0.25rem;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: white;
    margin-right: 0.5rem;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    color: #fca5a5;
}
.select2-container {
    width: 100% !important;
}
.select2-dropdown {
    border-color: #cbd5e1;
    border-radius: 0.5rem;
}
</style>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-users-cog"></i>
                    Users Management
                </h1>
                <p class="page-subtitle">Manage system users and their permissions</p>
            </div>
            <div>
                <?php if ($user['role'] === 'super_admin'): ?>
                    <a href="add_user.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add User
                    </a>
                <?php else: ?>
                    <span class="badge badge-info" style="padding: 0.75rem 1rem;">
                        <i class="fas fa-info-circle"></i> Only Super Admin can add users
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list"></i>
                    All Users (<?php echo count($users); ?>)
                </h2>
            </div>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Sector</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: var(--spacing-sm);">
                                    <?php if (isset($u['profile_image']) && $u['profile_image']): ?>
                                        <img src="<?php echo htmlspecialchars($u['profile_image']); ?>" 
                                             style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                            <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td><?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge badge-info">
                                    <?php echo str_replace('_', ' ', ucwords($u['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                // Display sectors count from user_sectors table
                                $sector_count = (int)($u['sectors_count'] ?? 0);
                                
                                if ($sector_count > 0) {
                                    // Get actual sector names from user_sectors
                                    $stmt = $conn->prepare("SELECT s.name FROM user_sectors us 
                                                           JOIN sectors s ON us.sector_id = s.id 
                                                           WHERE us.user_id = ?");
                                    $stmt->execute([$u['id']]);
                                    $user_sector_names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    
                                    if (!empty($user_sector_names)) {
                                        echo '<span class="badge badge-info" style="font-size: 0.85rem;">';
                                        echo implode(', ', $user_sector_names);
                                        echo ' (' . $sector_count . ')';
                                        echo '</span>';
                                    } else {
                                        echo '<span class="badge badge-secondary">0 sectors</span>';
                                    }
                                } elseif (!empty($u['sector_name'])) {
                                    // Fallback to single sector from users table (legacy)
                                    echo '<span class="badge badge-info">' . htmlspecialchars($u['sector_name']) . '</span>';
                                } else {
                                    echo '<span class="badge badge-secondary">No sectors</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($u['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo isset($u['last_login']) && $u['last_login'] ? timeAgo($u['last_login']) : 'Never'; ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick="editUser(<?php echo $u['id']; ?>)" class="btn btn-sm btn-primary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($u['id'] != $user['id']): ?>
                                        <button onclick="toggleUserStatus(<?php echo $u['id']; ?>, '<?php echo $u['is_active']; ?>')" 
                                                class="btn btn-sm btn-warning" title="Toggle Status">
                                            <i class="fas fa-toggle-on"></i>
                                        </button>
                                        <?php if ($user['role'] === 'super_admin'): ?>
                                            <button onclick="deleteUser(<?php echo $u['id']; ?>)" class="btn btn-sm btn-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3 class="modal-title">Add New User</h3>
            <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addUserForm">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--spacing-md);">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color: red;">*</span></label>
                        <input type="text" class="form-input" name="full_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username <span style="color: red;">*</span></label>
                        <input type="text" class="form-input" name="username" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-input" name="email">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" class="form-input" name="phone" placeholder="+971 50 123 4567">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password <span style="color: red;">*</span></label>
                        <input type="password" class="form-input" name="password" required minlength="6">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Role <span style="color: red;">*</span></label>
                        <select class="form-input" name="role" required>
                            <option value="">Select Role</option>
                            <?php if ($user['role'] === 'super_admin'): ?>
                                <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                            <option value="admin">Admin</option>
                            <option value="sector_manager">Sector Manager</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="inspection">Inspection</option>
                        </select>
                    </div>


                    <!-- Sector Assignment Type -->
                    <div class="form-group">
                        <label class="form-label">Sector Assignment</label>
                        <select class="form-input" id="sector_assignment_type" onchange="toggleSectorFields()">
                            <option value="single">Single Sector</option>
                            <option value="multiple">Multiple Sectors</option>
                            <option value="all">All Sectors</option>
                        </select>
                    </div>

                    <!-- Single Sector -->
                    <div class="form-group" id="single_sector_group">
                        <label class="form-label">Sector</label>
                        <select class="form-input" name="sector_id" id="sector_id">
                            <option value="">Select Sector</option>
                            <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>"><?php echo htmlspecialchars($sector['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Multiple Sectors -->
                    <div class="form-group" id="multiple_sectors_group" style="display: none;">
                        <label class="form-label">Responsible Sectors</label>
                        <select class="form-input" name="responsible_sectors[]" id="responsible_sectors" multiple size="6" style="height: auto; min-height: 150px;">
                            <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>"><?php echo htmlspecialchars($sector['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Hold Ctrl/Cmd to select multiple</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-input" name="is_active">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitUserForm()">
                <i class="fas fa-save"></i> Save User
            </button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3 class="modal-title">Edit User</h3>
            <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editUserForm">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--spacing-md);">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color: red;">*</span></label>
                        <input type="text" class="form-input" name="full_name" id="edit_full_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username <span style="color: red;">*</span></label>
                        <input type="text" class="form-input" name="username" id="edit_username" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-input" name="email" id="edit_email">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" class="form-input" name="phone" id="edit_phone" placeholder="+971 50 123 4567">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password <small>(leave blank to keep current)</small></label>
                        <input type="password" class="form-input" name="password" id="edit_password" minlength="6">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Role <span style="color: red;">*</span></label>
                        <select class="form-input" name="role" id="edit_role" required>
                            <option value="">Select Role</option>
                            <?php if ($user['role'] === 'super_admin'): ?>
                                <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                            <option value="admin">Admin</option>
                            <option value="sector_manager">Sector Manager</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="inspection">Inspection</option>
                        </select>
                    </div>

                    <!-- Sector Assignment Type -->
                    <div class="form-group">
                        <label class="form-label">Sector Assignment</label>
                        <select class="form-input" id="edit_sector_assignment_type" onchange="toggleEditSectorFields()">
                            <option value="single">Single Sector</option>
                            <option value="multiple">Multiple Sectors</option>
                            <option value="all">All Sectors</option>
                        </select>
                    </div>

                    <!-- Single Sector -->
                    <div class="form-group" id="edit_single_sector_group">
                        <label class="form-label">Sector</label>
                        <select class="form-input" name="sector_id" id="edit_sector_id">
                            <option value="">Select Sector</option>
                            <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>"><?php echo htmlspecialchars($sector['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Multiple Sectors -->
                    <div class="form-group" id="edit_multiple_sectors_group" style="display: none; grid-column: 1 / -1;">
                        <label class="form-label">Responsible Sectors</label>
                        <select class="form-input" name="responsible_sectors[]" id="edit_responsible_sectors" multiple size="6" style="height: auto; min-height: 150px;">
                            <?php foreach ($sectors as $sector): ?>
                                <option value="<?php echo $sector['id']; ?>"><?php echo htmlspecialchars($sector['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Hold Ctrl/Cmd to select multiple</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-input" name="is_active" id="edit_is_active">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitEditUserForm()">
                <i class="fas fa-save"></i> Update User
            </button>
        </div>
    </div>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
function toggleSectorFields() {
    const type = document.getElementById('sector_assignment_type').value;
    const singleGroup = document.getElementById('single_sector_group');
    const multipleGroup = document.getElementById('multiple_sectors_group');
    
    if (type === 'single') {
        singleGroup.style.display = 'block';
        multipleGroup.style.display = 'none';
        document.getElementById('sector_id').disabled = false;
        document.getElementById('responsible_sectors').disabled = true;
    } else if (type === 'multiple') {
        singleGroup.style.display = 'none';
        multipleGroup.style.display = 'block';
        document.getElementById('sector_id').disabled = true;
        document.getElementById('responsible_sectors').disabled = false;
    } else { // all
        singleGroup.style.display = 'none';
        multipleGroup.style.display = 'none';
        document.getElementById('sector_id').disabled = true;
        document.getElementById('responsible_sectors').disabled = true;
    }
}

function submitUserForm() {
    const form = document.getElementById('addUserForm');
    const formData = new FormData(form);
    const submitBtn = event.target;
    
    // Validate required fields
    const fullName = form.querySelector('[name="full_name"]').value.trim();
    const username = form.querySelector('[name="username"]').value.trim();
    const password = form.querySelector('[name="password"]').value.trim();
    const role = form.querySelector('[name="role"]').value;
    
    if (!fullName || !username || !password || !role) {
        alert('Please fill all required fields (Name, Username, Password, Role)');
        return;
    }
    
    if (password.length < 6) {
        alert('Password must be at least 6 characters long');
        return;
    }
    
    // Handle sector assignment
    const assignmentType = document.getElementById('sector_assignment_type').value;
    formData.append('sector_assignment_type', assignmentType);
    
    // Handle multiple sectors - re-add them even if field is disabled
    if (assignmentType === 'multiple') {
        const sectorsSelect = document.getElementById('responsible_sectors');
        const selectedOptions = Array.from(sectorsSelect.selectedOptions);
        
        // Remove old entries
        formData.delete('responsible_sectors[]');
        
        // Add selected sectors
        selectedOptions.forEach(option => {
            formData.append('responsible_sectors[]', option.value);
        });
    }
    
    // Disable button and show loading
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('ajax/manage_user.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('User saved successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Save User';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving user. Check console for details.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save User';
    });
}

function editUser(id) {
    // Get user data
    const users = <?php echo json_encode($users); ?>;
    const userData = users.find(u => u.id == id);
    
    if (!userData) {
        alert('User not found');
        return;
    }
    
    // Fill form fields
    document.getElementById('edit_user_id').value = userData.id;
    document.getElementById('edit_full_name').value = userData.full_name;
    document.getElementById('edit_username').value = userData.username;
    document.getElementById('edit_email').value = userData.email || '';
    document.getElementById('edit_phone').value = userData.phone || '';
    document.getElementById('edit_password').value = ''; // Always empty for security
    document.getElementById('edit_role').value = userData.role;
    document.getElementById('edit_is_active').value = userData.is_active ? 'active' : 'inactive';
    
    // Handle sector assignment - UPDATED to fetch from user_sectors table
    // First, check if user has single sector
    if (userData.sector_id) {
        // Single sector
        document.getElementById('edit_sector_assignment_type').value = 'single';
        document.getElementById('edit_sector_id').value = userData.sector_id;
        toggleEditSectorFields();
        openModal('editUserModal');
    } else {
        // Check if user has multiple sectors assigned (from user_sectors table)
        fetch('ajax/get_user_sectors.php?user_id=' + id)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data.sector_ids && data.data.sector_ids.length > 0) {
                    // Multiple sectors
                    document.getElementById('edit_sector_assignment_type').value = 'multiple';
                    
                    // Select multiple sectors
                    const selectElement = document.getElementById('edit_responsible_sectors');
                    Array.from(selectElement.options).forEach(option => {
                        option.selected = data.data.sector_ids.includes(parseInt(option.value));
                    });
                } else {
                    // No sector assigned
                    document.getElementById('edit_sector_assignment_type').value = 'single';
                    document.getElementById('edit_sector_id').value = '';
                }
                
                // Update UI
                toggleEditSectorFields();
                
                // Open modal
                openModal('editUserModal');
            })
            .catch(error => {
                console.error('Error loading sectors:', error);
                // Fallback: show single sector mode
                document.getElementById('edit_sector_assignment_type').value = 'single';
                document.getElementById('edit_sector_id').value = '';
                toggleEditSectorFields();
                openModal('editUserModal');
            });
    }
}

function toggleEditSectorFields() {
    const type = document.getElementById('edit_sector_assignment_type').value;
    const singleGroup = document.getElementById('edit_single_sector_group');
    const multipleGroup = document.getElementById('edit_multiple_sectors_group');
    
    if (type === 'single') {
        singleGroup.style.display = 'block';
        multipleGroup.style.display = 'none';
        document.getElementById('edit_sector_id').disabled = false;
        document.getElementById('edit_responsible_sectors').disabled = true;
    } else if (type === 'multiple') {
        singleGroup.style.display = 'none';
        multipleGroup.style.display = 'block';
        document.getElementById('edit_sector_id').disabled = true;
        document.getElementById('edit_responsible_sectors').disabled = false;
    } else { // all
        singleGroup.style.display = 'none';
        multipleGroup.style.display = 'none';
        document.getElementById('edit_sector_id').disabled = true;
        document.getElementById('edit_responsible_sectors').disabled = true;
    }
}

function submitEditUserForm() {
    const form = document.getElementById('editUserForm');
    const formData = new FormData(form);
    const submitBtn = event.target;
    
    // Validate required fields
    const fullName = form.querySelector('[name="full_name"]').value.trim();
    const username = form.querySelector('[name="username"]').value.trim();
    const role = form.querySelector('[name="role"]').value;
    const password = form.querySelector('[name="password"]').value.trim();
    
    if (!fullName || !username || !role) {
        alert('Please fill all required fields (Name, Username, Role)');
        return;
    }
    
    // Validate password if provided
    if (password && password.length < 6) {
        alert('Password must be at least 6 characters long');
        return;
    }
    
    // Handle sector assignment
    const assignmentType = document.getElementById('edit_sector_assignment_type').value;
    formData.append('sector_assignment_type', assignmentType);
    
    // Handle multiple sectors - re-add them even if field is disabled
    if (assignmentType === 'multiple') {
        const sectorsSelect = document.getElementById('edit_responsible_sectors');
        const selectedOptions = Array.from(sectorsSelect.selectedOptions);
        
        // Remove old entries
        formData.delete('responsible_sectors[]');
        
        // Add selected sectors
        selectedOptions.forEach(option => {
            formData.append('responsible_sectors[]', option.value);
        });
    }
    
    // Disable button and show loading
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    fetch('ajax/manage_user.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('User updated successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Update User';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating user. Check console for details.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Update User';
    });
}

function toggleUserStatus(id, currentStatus) {
    const newStatus = currentStatus == '1' || currentStatus === 'active' ? 0 : 1;
    const action = newStatus == 1 ? 'activate' : 'deactivate';
    
    if (!confirm(`Are you sure you want to ${action} this user?`)) return;
    
    fetch('ajax/manage_user.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'toggle_status',
            user_id: id,
            status: newStatus
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Status updated successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating status');
    });
}

function deleteUser(id) {
    if (!confirm('Are you sure you want to delete this user? This action cannot be undone!')) return;
    
    fetch(`ajax/manage_user.php?delete=${id}`, {
        method: 'DELETE'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('User deleted successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while deleting user');
    });
}
</script>

<!-- Select2 JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Initialize Select2 on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 for both Add and Edit modals
    $('#responsible_sectors').select2({
        placeholder: 'Select sectors',
        allowClear: true,
        dropdownParent: $('#addUserModal')
    });
    
    $('#edit_responsible_sectors').select2({
        placeholder: 'Select sectors',
        allowClear: true,
        dropdownParent: $('#editUserModal')
    });
});
</script>