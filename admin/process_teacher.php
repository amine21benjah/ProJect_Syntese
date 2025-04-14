<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacher_id = $_POST['teacher_id'];
    $action = $_POST['action'];

    try {
        if ($action === 'approve') {
            // Update teacher status to approved
            $stmt = $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ? AND user_type = 'teacher'");
            $stmt->execute([$teacher_id]);
            
            // Maintenir la section 'teachers' dans l'URL
            header("Location: dashboard.php?message=teacher_approved&section=teachers");
            exit();
        } 
        elseif ($action === 'reject') {
            // Delete the teacher
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND user_type = 'teacher'");
            $stmt->execute([$teacher_id]);
            
            // Maintenir la section 'teachers' dans l'URL
            header("Location: dashboard.php?message=teacher_rejected&section=teachers");
            exit();
        }
    } catch(PDOException $e) {
        // En cas d'erreur, maintenir également la section
        header("Location: dashboard.php?error=process_failed&section=teachers");
        exit();
    }
}

// Si on arrive ici, il y a eu une erreur dans la requête
header("Location: dashboard.php?error=invalid_request&section=teachers");
exit();
?>
