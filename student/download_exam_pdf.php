<?php
require_once '../config.php';
require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : null;

// Récupérer les détails de l'examen
$query = "
    SELECT e.id as exam_id, e.title as exam_title, m.name as module_name,
           q.id as question_id, q.question_text, q.points as max_points,
           sa.points_earned, sa.answer_text, q.correct_answer,
           u.name as student_name, u.cne,
           ea.final_grade, ea.completed_at
    FROM exams e
    JOIN modules m ON e.module_id = m.id
    JOIN questions q ON e.id = q.exam_id
    LEFT JOIN student_answers sa ON q.id = sa.question_id AND sa.student_id = ?
    JOIN users u ON u.id = ?
    JOIN exam_attempts ea ON ea.exam_id = e.id AND ea.student_id = ?
    WHERE e.id = ? AND ea.status = 'completed'
    ORDER BY q.order_num";

$stmt = $pdo->prepare($query);
$stmt->execute([$student_id, $student_id, $student_id, $exam_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    header("Location: dashboard.php");
    exit();
}

// Créer un nouveau document PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Définir les informations du document
$pdf->SetCreator('ExamEnLigne');
$pdf->SetAuthor('ExamEnLigne');
$pdf->SetTitle('Résultats Examen - ' . $results[0]['exam_title']);

// Définir les marges
$pdf->SetMargins(15, 15, 15);

// Ajouter une nouvelle page
$pdf->AddPage();

// En-tête
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Résultats d\'Examen', 0, 1, 'C');
$pdf->Ln(5);

// Informations de l'examen
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Détails de l\'examen:', 0, 1);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, 'Titre: ' . $results[0]['exam_title'], 0, 1);
$pdf->Cell(0, 8, 'Module: ' . $results[0]['module_name'], 0, 1);
$pdf->Cell(0, 8, 'Étudiant: ' . $results[0]['student_name'] . ' (CNE: ' . $results[0]['cne'] . ')', 0, 1);
$pdf->Cell(0, 8, 'Note finale: ' . number_format($results[0]['final_grade'], 2) . '/20', 0, 1);
$pdf->Cell(0, 8, 'Date de soumission: ' . date('d/m/Y H:i', strtotime($results[0]['completed_at'])), 0, 1);
$pdf->Ln(5);

// Questions et réponses
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Questions et Réponses:', 0, 1);
$pdf->Ln(2);

foreach ($results as $index => $row) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->MultiCell(0, 8, 'Question ' . ($index + 1) . ':', 0, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 8, $row['question_text'], 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'Votre réponse:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell(0, 8, $row['answer_text'] ?? 'Aucune réponse', 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'Points: ' . number_format($row['points_earned'] ?? 0, 2) . '/' . number_format($row['max_points'], 2), 0, 1);
    
    $pdf->Ln(5);
}

// Générer le PDF
$pdf->Output('resultat_examen.pdf', 'D');
?>
