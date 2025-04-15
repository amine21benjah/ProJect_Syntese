<?php
session_start();
require_once '../config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['student', 'teacher'])) {
    header("Location: ../login.php");
    exit();
}

// Récupérer les détails de l'examen et les réponses
$exam_id = $_GET['exam_id'] ?? null;
$student_id = $_GET['student_id'] ?? $_SESSION['user_id'];

if (!$exam_id) {
    header("Location: dashboard.php");
    exit();
}

// Récupérer les détails de l'examen et les réponses
$stmt = $pdo->prepare("
    SELECT 
        e.*,
        g.name as group_name,
        u.name as student_name,
        t.name as teacher_name,
        ea.status as attempt_status,
        COALESCE(SUM(sa.points_earned), 0) as total_score,
        (SELECT SUM(points) FROM questions WHERE exam_id = e.id) as total_possible
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    JOIN group_students gs ON g.id = gs.group_id
    JOIN users u ON gs.student_id = u.id AND u.id = ?
    JOIN users t ON e.teacher_id = t.id
    LEFT JOIN exam_attempts ea ON ea.exam_id = e.id AND ea.student_id = ?
    LEFT JOIN student_answers sa ON sa.exam_id = e.id AND sa.student_id = ?
    WHERE e.id = ?
    GROUP BY e.id, g.name, u.name, t.name, ea.status
");

$stmt->execute([$student_id, $student_id, $student_id, $exam_id]);
$exam = $stmt->fetch();

// Vérifier si l'examen existe
if (!$exam) {
    header("Location: dashboard.php?message=exam_not_found");
    exit();
}

// Récupérer les questions et réponses
$stmt = $pdo->prepare("
    SELECT 
        q.*,
        COALESCE(
            (SELECT answer_text 
             FROM student_answers sa2 
             WHERE sa2.question_id = q.id 
             AND sa2.student_id = ? 
             AND sa2.exam_id = ? 
             ORDER BY submitted_at DESC 
             LIMIT 1),
            sa.answer_text
        ) as answer_text,
        COALESCE(
            (SELECT selected_choices 
             FROM student_answers sa2 
             WHERE sa2.question_id = q.id 
             AND sa2.student_id = ? 
             AND sa2.exam_id = ? 
             ORDER BY submitted_at DESC 
             LIMIT 1),
            sa.selected_choices
        ) as selected_choices,
        COALESCE(
            (SELECT points_earned 
             FROM student_answers sa2 
             WHERE sa2.question_id = q.id 
             AND sa2.student_id = ? 
             AND sa2.exam_id = ? 
             ORDER BY submitted_at DESC 
             LIMIT 1),
            sa.points_earned,
            0
        ) as score
    FROM questions q
    LEFT JOIN student_answers sa ON q.id = sa.question_id 
        AND sa.student_id = ? 
        AND sa.exam_id = ?
    WHERE q.exam_id = ?
    ORDER BY q.order_num, q.id
");

$stmt->execute([
    $student_id, $exam_id,
    $student_id, $exam_id,
    $student_id, $exam_id,
    $student_id, $exam_id,
    $exam_id
]);
$questions = $stmt->fetchAll();

// Si aucune tentative n'est enregistrée mais que l'examen est terminé
if (!$exam['attempt_status'] && strtotime($exam['start_datetime']) + ($exam['duration'] * 60) < time()) {
    $exam['attempt_status'] = 'time_expired';
}

// Afficher la vue des résultats
include 'view_result_template.php';
