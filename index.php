<?php

// Redirect to dashboard if logged in, otherwise to login
if (isset($_SESSION['user_id']) && isset($_SESSION['user'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
