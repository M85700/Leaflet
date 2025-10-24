<?php
/**
 * PSD Portal - Secure Configuration
 * Version: 5.0 - PRODUCTION READY WITH SECURITY ENHANCEMENTS
 */

// =====================================================
// ENVIRONMENT DETECTION
// =====================================================
$is_production = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'psd.m85.ae') !== false);

// =====================================================
// ERROR HANDLING - SECURE FOR PRODUCTION
// =====================================================
if ($is_production) {
    // Production: Hide errors, log them
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/php-errors.log');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    // Development: Show errors for debugging
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// =====================================================
// TIMEZONE
// =====================================================
date_default_timezone_set('Asia/Dubai');

// =====================================================
// SECURITY HEADERS
// =====================================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(self), camera=(), microphone=()");
header("Content-Security-Policy: default-src 'self' https://cdnjs.cloudflare.com https://*.tile.openstreetmap.org; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https://*.tile.openstreetmap.org;");

// =====================================================
// CACHE CONTROL - Prevent browser caching
// =====================================================
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// =====================================================
// SESSION SECURITY CONFIGURATION
// =====================================================
if (session_status() === PHP_SESSION_NONE) {
    // Enhanced session security
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $is_production ? 1 : 0); // HTTPS only in production
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_lifetime', 0); // Session cookie
    ini_set('session.gc_maxlifetime', 3600); // 1 hour
    
    session_start();
}

// =====================================================
// DATABASE CONFIGURATION - SECURE WITH SECRETS
// =====================================================
if ($is_production) {
    // Production: MySQL Database (REQUIRES environment variables)
    define('DB_HOST', getenv('DB_HOST'));
    define('DB_NAME', getenv('DB_NAME'));
    define('DB_USER', getenv('DB_USER'));
    define('DB_PASS', getenv('DB_PASSWORD'));
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATE', 'utf8mb4_unicode_ci');
    
    // Validate required environment variables
    if (!DB_HOST || !DB_NAME || !DB_USER || !DB_PASS) {
        error_log("CRITICAL: Missing required database environment variables");
        die("Database configuration error. Please contact system administrator.");
    }
    
    $db_type = 'mysql';
} else {
    // Development: PostgreSQL Database (Replit)
    define('DB_HOST', getenv('PGHOST') ?: 'localhost');
    define('DB_NAME', getenv('PGDATABASE') ?: 'psd_portal');
    define('DB_USER', getenv('PGUSER') ?: 'postgres');
    define('DB_PASS', getenv('PGPASSWORD') ?: '');
    define('DB_PORT', getenv('PGPORT') ?: '5432');
    $db_type = 'pgsql';
}

// =====================================================
// DATABASE CONNECTION
// =====================================================
try {
    if ($db_type === 'mysql') {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE " . DB_COLLATE
        ];
        
        $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        $conn->exec("SET time_zone = '+04:00'");
    } else {
        // PostgreSQL connection
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        $conn->exec("SET TIME ZONE 'Asia/Dubai'");
    }
    
    $pdo = $conn; // Alias for compatibility
    
} catch (PDOException $e) {
    // Secure error message (don't expose details in production)
    if ($is_production) {
        error_log("Database Connection Failed: " . $e->getMessage());
        die("Service temporarily unavailable. Please contact support.");
    } else {
        die("Database Connection Failed: " . $e->getMessage() . 
            "<br><br>Please check your database credentials in config.php");
    }
}

// =====================================================
// APPLICATION SETTINGS
// =====================================================
define('SITE_URL', $is_production ? 'http://psd.m85.ae/' : 'http://localhost:5000/');
define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('UPLOAD_URL', SITE_URL . 'uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 MB
define('ALLOWED_ROLES', ['super_admin', 'admin', 'sector_manager', 'supervisor', 'inspection']);
define('RECORDS_PER_PAGE', 10);

// Session secret for additional security
define('SESSION_SECRET', getenv('SESSION_SECRET') ?: 'change_this_in_production_' . bin2hex(random_bytes(16)));

// =====================================================
// HELPER FUNCTIONS
// =====================================================

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user']['id'] ?? null;
}

/**
 * Require login for protected pages
 */
function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

/**
 * Calculate response time between two dates
 */
function calculateResponseTime($start, $end) {
    $start_time = strtotime($start);
    $end_time = strtotime($end);
    $diff = $end_time - $start_time;
    
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    
    return [
        'total_minutes' => floor($diff / 60),
        'hours' => $hours,
        'minutes' => $minutes,
        'formatted' => sprintf('%d:%02d', $hours, $minutes)
    ];
}

/**
 * Format duration in hours and minutes
 */
function formatDuration($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%d:%02d', $hours, $mins);
}

// =====================================================
// CREATE REQUIRED DIRECTORIES
// =====================================================
$required_dirs = [
    __DIR__ . '/logs',
    __DIR__ . '/uploads/emergencies',
    __DIR__ . '/uploads/executions',
    __DIR__ . '/uploads/markers',
    __DIR__ . '/uploads/profiles',
    __DIR__ . '/cache'
];

foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

// Create .htaccess for logs and cache directories
$htaccess_content = "Deny from all";
$protected_dirs = [__DIR__ . '/logs', __DIR__ . '/cache'];

foreach ($protected_dirs as $dir) {
    $htaccess_file = $dir . '/.htaccess';
    if (!file_exists($htaccess_file)) {
        file_put_contents($htaccess_file, $htaccess_content);
    }
}
