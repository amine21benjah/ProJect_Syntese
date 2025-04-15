<?php
session_start();
require_once '../config.php';

// Vérifier si l'utilisateur est connecté et est un professeur
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Vérifier si l'ID de l'examen est fourni
if (!isset($_GET['exam_id'])) {
    header("Location: exams.php?message=error_no_exam_id");
    exit();
}

$exam_id = $_GET['exam_id'];

try {
    // Vérifier si l'examen appartient au professeur
    $stmt = $pdo->prepare("
        SELECT id 
        FROM exams 
        WHERE id = ? AND teacher_id = ?
    ");
    $stmt->execute([$exam_id, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        header("Location: exams.php?message=error_unauthorized");
        exit();
    }

    $pdo->beginTransaction();

    // Delete related data first
    // Delete student answers
    $stmt = $pdo->prepare("DELETE FROM student_answers WHERE exam_id = ?");
    $stmt->execute([$exam_id]);

    // Delete exam attempts
    $stmt = $pdo->prepare("DELETE FROM exam_attempts WHERE exam_id = ?");
    $stmt->execute([$exam_id]);

    // Delete question choices
    $stmt = $pdo->prepare("
        DELETE qc FROM question_choices qc
        INNER JOIN questions q ON q.id = qc.question_id
        WHERE q.exam_id = ?
    ");
    $stmt->execute([$exam_id]);

    // Delete questions
    $stmt = $pdo->prepare("DELETE FROM questions WHERE exam_id = ?");
    $stmt->execute([$exam_id]);

    // Finally delete the exam
    $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ?");
    $stmt->execute([$exam_id]);

    $pdo->commit();
    header("Location: exams.php?message=exam_deleted");
    exit();

} catch (Exception $e) {
    $pdo->rollBack();
    header("Location: exams.php?message=error_deleting_exam");
    exit();
}
?>
