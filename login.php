<?php
/**
 * Login Page - SECURE VERSION with Rate Limiting
 */
require_once 'config.php';

if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

/**
 * Check and manage login attempts (Rate Limiting)
 */
function checkLoginAttempts($username) {
    $max_attempts = 5;
    $lockout_time = 900; // 15 minutes
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = md5($username . '|' . $ip);
    
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    // Clean old attempts
    foreach ($_SESSION['login_attempts'] as $k => $attempts) {
        $_SESSION['login_attempts'][$k] = array_filter($attempts, function($time) use ($lockout_time) {
            return (time() - $time) < $lockout_time;
        });
        
        if (empty($_SESSION['login_attempts'][$k])) {
            unset($_SESSION['login_attempts'][$k]);
        }
    }
    
    // Check current user attempts
    $attempts = $_SESSION['login_attempts'][$key] ?? [];
    
    if (count($attempts) >= $max_attempts) {
        $oldest_attempt = min($attempts);
        $wait_time = ceil(($lockout_time - (time() - $oldest_attempt)) / 60);
        throw new Exception("Too many failed login attempts. Please wait {$wait_time} minutes before trying again.");
    }
    
    return $attempts;
}

function recordFailedLogin($username) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = md5($username . '|' . $ip);
    
    if (!isset($_SESSION['login_attempts'][$key])) {
        $_SESSION['login_attempts'][$key] = [];
    }
    
    $_SESSION['login_attempts'][$key][] = time();
}

function clearLoginAttempts($username) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = md5($username . '|' . $ip);
    unset($_SESSION['login_attempts'][$key]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            // Check rate limiting
            checkLoginAttempts($username);
            
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username AND is_active = 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Clear failed attempts on successful login
                clearLoginAttempts($username);
                
                // Regenerate session ID for security
                session_regenerate_id(true);
                
                $_SESSION['user'] = $user;
                $_SESSION['last_regeneration'] = time();
                
                // Update last login
                $stmt = $conn->prepare("UPDATE users SET last_login = NOW(), updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $user['id']]);
                
                // Log successful login
                if (function_exists('logActivity')) {
                    logActivity($user['id'], 'login', 'User logged in successfully');
                }
                
                header('Location: dashboard.php');
                exit;
            } else {
                recordFailedLogin($username);
                $error = 'Invalid username or password';
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Login - PSD Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1rem;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            animation: slideInUp 0.5s ease;
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        
        .login-logo {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2.5rem;
        }
        
        .login-title {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        
        .login-subtitle {
            font-size: 0.95rem;
            opacity: 0.9;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .login-footer {
            padding: 1.5rem 2rem;
            background: var(--gray-50);
            text-align: center;
            font-size: 0.875rem;
            color: var(--gray-600);
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <i class="fas fa-building"></i>
            </div>
            <h1 class="login-title">PSD Portal</h1>
            <p class="login-subtitle">Public Services Department</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" autocomplete="off">
                <div class="form-group">
                    <label for="username" class="form-label">
                        <i class="fas fa-user"></i> Username
                    </label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-input" 
                        required 
                        autofocus 
                        autocomplete="username"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input" 
                        required 
                        autocomplete="current-password"
                    >
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>
        </div>
        
        <div class="login-footer">
            <p>© <?php echo date('Y'); ?> Public Services Department. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
