<?php
require_once 'config.php';
require_once 'functions.php';

checkAuth();

global $conn;
$user = $_SESSION['user'];
$success_message = '';
$error_message = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = sanitizeInput($_POST['full_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    
    try {
        $conn->beginTransaction();
        
        // Handle profile image upload
        $profile_image = $user['profile_image'] ?? null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($ext, $allowed)) {
                $filename = 'profile_' . $user['id'] . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $filename)) {
                    // Delete old image
                    if ($profile_image && file_exists($profile_image)) {
                        unlink($profile_image);
                    }
                    $profile_image = $upload_dir . $filename;
                }
            }
        }
        
        $stmt = $conn->prepare("UPDATE users SET full_name = :name, email = :email, phone = :phone, profile_image = :image WHERE id = :id");
        $stmt->execute([
            ':name' => $full_name,
            ':email' => $email,
            ':phone' => $phone,
            ':image' => $profile_image,
            ':id' => $user['id']
        ]);
        
        $conn->commit();
        
        // Update session
        updateUserSession();
        $user = $_SESSION['user'];
        
        $success_message = 'Profile updated successfully!';
        logActivity($user['id'], 'update_profile', 'Updated profile information');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = 'Error updating profile: ' . $e->getMessage();
        error_log("Profile update error: " . $e->getMessage());
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $error_message = 'New passwords do not match!';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'Password must be at least 6 characters long!';
    } elseif (!verifyPassword($current_password, $user['password'])) {
        $error_message = 'Current password is incorrect!';
    } else {
        try {
            $hashed = hashPassword($new_password);
            
            $stmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");
            $stmt->execute([
                ':password' => $hashed,
                ':id' => $user['id']
            ]);
            
            $success_message = 'Password changed successfully!';
            logActivity($user['id'], 'change_password', 'Changed password');
            
        } catch (Exception $e) {
            $error_message = 'Error changing password: ' . $e->getMessage();
            error_log("Password change error: " . $e->getMessage());
        }
    }
}

$page_title = 'Settings';
include 'includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="fas fa-cog"></i>
                    Settings
                </h1>
                <p class="page-subtitle">Manage your account settings and preferences</p>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: var(--spacing-xl);">
            <!-- Profile Settings -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-user"></i>
                        Profile Information
                    </h2>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div style="text-align: center; margin-bottom: var(--spacing-xl);">
                        <div style="width: 120px; height: 120px; margin: 0 auto; position: relative;">
                            <?php if (isset($user['profile_image']) && $user['profile_image']): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 4px solid var(--primary-blue);">
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; background: linear-gradient(135deg, var(--primary-blue), var(--secondary-cyan)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 3rem; font-weight: 700;">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <label for="profile_image" style="position: absolute; bottom: 0; right: 0; width: 40px; height: 40px; background: var(--primary-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: white; box-shadow: var(--shadow-lg);">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" id="profile_image" name="profile_image" accept="image/*" style="display: none;">
                        </div>
                        <div style="margin-top: var(--spacing-md); font-weight: 600; color: var(--gray-700);">
                            <?php echo htmlspecialchars($user['role']); ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user"></i> Full Name
                        </label>
                        <input type="text" class="form-input" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> Email
                        </label>
                        <input type="email" class="form-input" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-phone"></i> Phone
                        </label>
                        <input type="tel" class="form-input" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-map-marked"></i> Sector
                        </label>
                        <input type="text" class="form-input" value="<?php echo htmlspecialchars($user['sector_name'] ?? 'N/A'); ?>" disabled>
                    </div>

                    <button type="submit" name="update_profile" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>

            <!-- Password Change -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-lock"></i>
                        Change Password
                    </h2>
                </div>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-key"></i> Current Password
                        </label>
                        <input type="password" class="form-input" name="current_password" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock"></i> New Password
                        </label>
                        <input type="password" class="form-input" name="new_password" minlength="6" required>
                        <small style="color: var(--gray-600); font-size: 0.875rem;">Minimum 6 characters</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock"></i> Confirm New Password
                        </label>
                        <input type="password" class="form-input" name="confirm_password" minlength="6" required>
                    </div>

                    <button type="submit" name="change_password" class="btn btn-danger btn-block">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>

                <!-- Account Info -->
                <div style="margin-top: var(--spacing-xl); padding: var(--spacing-lg); background: var(--gray-50); border-radius: var(--radius-lg);">
                    <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: var(--spacing-md);">Account Information</h3>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: var(--spacing-sm); font-size: 0.875rem;">
                        <span style="color: var(--gray-600);">Username:</span>
                        <span style="font-weight: 600;"><?php echo htmlspecialchars($user['username']); ?></span>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-bottom: var(--spacing-sm); font-size: 0.875rem;">
                        <span style="color: var(--gray-600);">Last Login:</span>
                        <span style="font-weight: 600;"><?php echo formatDate($user['last_login']); ?></span>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-bottom: var(--spacing-sm); font-size: 0.875rem;">
                        <span style="color: var(--gray-600);">Account Created:</span>
                        <span style="font-weight: 600;"><?php echo formatDate($user['created_at']); ?></span>
                    </div>

                    <div style="display: flex; justify-content: space-between; font-size: 0.875rem;">
                        <span style="color: var(--gray-600);">Status:</span>
                        <span class="badge badge-success"><?php echo ucfirst($user['is_active']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity Log -->
        <div class="content-section" style="margin-top: var(--spacing-xl);">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-history"></i>
                    Recent Activity
                </h2>
            </div>

            <?php
            $recent_activity = getRecentActivity($user['id'], 10);
            
            if (empty($recent_activity)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-history"></i></div>
                    <div class="empty-state-title">No recent activity</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Description</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $activity): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo strtoupper(str_replace('_', ' ', $activity['action'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                <td><?php echo timeAgo($activity['created_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include 'includes/mobile_navbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Preview profile image before upload
document.getElementById('profile_image').addEventListener('change', function(e) {
    if (e.target.files && e.target.files[0]) {
        const reader = new FileReader();
        reader.onload = function(event) {
            const img = document.querySelector('img[src*="profile"]') || document.querySelector('img');
            if (img) {
                img.src = event.target.result;
            } else {
                const div = document.querySelector('div[style*="linear-gradient"]');
                if (div) {
                    const newImg = document.createElement('img');
                    newImg.src = event.target.result;
                    newImg.style.cssText = 'width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 4px solid var(--primary-blue);';
                    div.parentElement.replaceChild(newImg, div);
                }
            }
        };
        reader.readAsDataURL(e.target.files[0]);
    }
});

// Password confirmation validation
const newPassword = document.querySelector('input[name="new_password"]');
const confirmPassword = document.querySelector('input[name="confirm_password"]');

confirmPassword.addEventListener('input', function() {
    if (this.value !== newPassword.value) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});
</script>