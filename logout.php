<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Get the message before destroying the session
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';

// Use the session manager to properly destroy the session
if (isset($sessionManager)) {
    $sessionManager->destroySession();
} else {
    // Fallback if session manager is not available
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
    session_unset();
    session_destroy();
}

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Build redirect URL
$redirectUrl = 'login.php';
if ($msg === 'session_expired') {
    $redirectUrl .= '?error=' . urlencode('Votre session a expiré. Veuillez vous reconnecter.');
}

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Ensure headers haven't been sent and redirect
if (!headers_sent()) {
    header("Location: " . $redirectUrl);
}
exit();
