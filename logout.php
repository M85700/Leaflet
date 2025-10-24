<?php
require_once 'config.php';

// Log the logout activity before destroying session
if (isset($_SESSION['user'])) {
    try {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, created_at) 
                               VALUES (:user_id, 'logout', 'User logged out', :ip, NOW())");
        $stmt->execute([
            ':user_id' => $_SESSION['user']['id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log("Logout log error: " . $e->getMessage());
    }
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php?message=' . urlencode('You have been logged out successfully'));
exit;
?>