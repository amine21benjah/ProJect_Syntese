<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$dbname = 'exam_online_db_4';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = $pdo;
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit();
}

// Inclure le gestionnaire de session seulement si ce n'est pas déjà fait
if (!class_exists('SessionManager')) {
    require_once __DIR__ . '/includes/session_manager.php';
    $sessionManager = new SessionManager($db);

    if (isset($_SESSION['user_id']) && !$sessionManager->validateSession($_SESSION['user_id'])) {
        header('Location: logout.php?msg=session_expired');
        exit();
    }
}
?>
