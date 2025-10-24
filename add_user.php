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

$page_title = "Add New User";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - PSD Portal</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-user-plus"></i> Add New User
                </h1>
                <p class="page-subtitle">Create a new user account (sector permissions managed separately)</p>
            </div>
            <a href="users_management.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>

        <div class="content-section">
            <form id="addUserForm" class="form-grid">
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Username *
                    </label>
                    <input type="text" id="username" name="username" required 
                           placeholder="Enter unique username" pattern="[a-zA-Z0-9_]{3,50}">
                    <small>3-50 characters, letters, numbers, underscore only</small>
                </div>

                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i> Email *
                    </label>
                    <input type="email" id="email" name="email" required 
                           placeholder="user@example.com">
                </div>

                <div class="form-group">
                    <label for="full_name">
                        <i class="fas fa-id-card"></i> Full Name *
                    </label>
                    <input type="text" id="full_name" name="full_name" required 
                           placeholder="Enter full name" maxlength="100">
                </div>

                <div class="form-group">
                    <label for="role">
                        <i class="fas fa-user-tag"></i> Role *
                    </label>
                    <select id="role" name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin">Admin</option>
                        <option value="sector_manager">Sector Manager</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="inspection">Inspection</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Password *
                    </label>
                    <input type="password" id="password" name="password" required 
                           placeholder="Enter password" minlength="6">
                    <small>Minimum 6 characters</small>
                </div>

                <div class="form-group">
                    <label for="password_confirm">
                        <i class="fas fa-lock"></i> Confirm Password *
                    </label>
                    <input type="password" id="password_confirm" name="password_confirm" required 
                           placeholder="Re-enter password" minlength="6">
                </div>

                <div class="form-group full-width">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        <span>Account Active</span>
                    </label>
                </div>

                <div class="form-actions full-width">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Create User
                    </button>
                    <button type="reset" class="btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <div class="content-section">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Note:</strong> After creating the user, assign sector permissions in 
                    <a href="user_permissions.php">User Permissions</a> page.
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('addUserForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const password = document.getElementById('password').value;
        const passwordConfirm = document.getElementById('password_confirm').value;
        
        if (password !== passwordConfirm) {
            alert('Passwords do not match!');
            return;
        }
        
        const formData = new FormData(this);
        
        try {
            const response = await fetch('ajax/add_user.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('User created successfully!');
                window.location.href = 'user_permissions.php?user_id=' + result.user_id;
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            alert('Error creating user. Please try again.');
            console.error(error);
        }
    });
    </script>
</body>
</html>
