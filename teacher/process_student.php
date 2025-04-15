<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['student_id']) && isset($_POST['action'])) {
    $student_id = $_POST['student_id'];
    $action = $_POST['action'];
    
    if ($action == 'approve' || $action == 'reject') {
        $status = ($action == 'approve') ? 'approved' : 'rejected';
        
        try {
            // Verify that the student belongs to one of the teacher's groups
            $stmt = $pdo->prepare("
                SELECT sg.id 
                FROM student_groups sg 
                JOIN groups g ON sg.group_id = g.id 
                WHERE sg.student_id = ? AND g.teacher_id = ?
            ");
            $stmt->execute([$student_id, $_SESSION['user_id']]);
            
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("
                    UPDATE student_groups sg 
                    JOIN groups g ON sg.group_id = g.id 
                    SET sg.status = ? 
                    WHERE sg.student_id = ? AND g.teacher_id = ?
                ");
                $stmt->execute([$status, $student_id, $_SESSION['user_id']]);
                
                header("Location: dashboard.php?message=student_" . $action . "d");
                exit();
            }
        } catch(PDOException $e) {
            header("Location: dashboard.php?error=process_failed");
            exit();
        }
    }
}

header("Location: dashboard.php");
exit();
?>
