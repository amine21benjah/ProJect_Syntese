<?php
session_start();
require_once '../config.php';

// Vérifier si l'utilisateur est connecté et est un professeur
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['exam_id'])) {
    header("Location: exams.php");
    exit();
}

$exam_id = $_GET['exam_id'];

// Récupérer les informations de l'examen
$stmt = $pdo->prepare("
    SELECT e.*, g.name as group_name, m.name as module_name
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    LEFT JOIN modules m ON e.module_id = m.id
    WHERE e.id = ? AND e.teacher_id = ?
");
$stmt->execute([$exam_id, $_SESSION['user_id']]);
$exam = $stmt->fetch();

if (!$exam) {
    header("Location: exams.php");
    exit();
}

// Get total possible points for the exam
$stmt = $pdo->prepare("SELECT SUM(points) as total_points FROM questions WHERE exam_id = ?");
$stmt->execute([$exam_id]);
$max_points = $stmt->fetch()['total_points'];

// Récupérer le statut de tous les étudiants pour cet examen
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.name,
        u.cne,
        CASE
            WHEN ea.status = 'completed' THEN 'finished'
            WHEN ea.status = 'in_progress' THEN 'in_progress'
            ELSE 'not_started'
        END as exam_status,
        ea.started_at,
        ea.completed_at,
        (
            SELECT COUNT(DISTINCT question_id)
            FROM student_answers
            WHERE student_id = u.id AND exam_id = ?
        ) as answered_questions,
        (
            SELECT COUNT(*)
            FROM questions
            WHERE exam_id = ?
        ) as total_questions,
        COALESCE(
            (
                SELECT SUM(
                    CASE 
                        WHEN q.question_type = 'QCM' THEN 
                            CASE 
                                WHEN (
                                    SELECT COUNT(*) 
                                    FROM question_choices qc 
                                    WHERE qc.question_id = q.id 
                                    AND qc.is_correct = 1 
                                    AND FIND_IN_SET(qc.id, sa.selected_choices)
                                ) = (
                                    SELECT COUNT(*) 
                                    FROM question_choices qc 
                                    WHERE qc.question_id = q.id 
                                    AND qc.is_correct = 1
                                ) AND (
                                    SELECT COUNT(*) 
                                    FROM question_choices qc 
                                    WHERE qc.question_id = q.id 
                                    AND qc.is_correct = 0 
                                    AND FIND_IN_SET(qc.id, sa.selected_choices)
                                ) = 0 THEN q.points
                                WHEN (
                                    SELECT COUNT(*) 
                                    FROM question_choices qc 
                                    WHERE qc.question_id = q.id 
                                    AND qc.is_correct = 1 
                                    AND FIND_IN_SET(qc.id, sa.selected_choices)
                                ) > 0 THEN q.points * 0.5
                                ELSE 0
                            END
                        ELSE points_earned
                    END
                ) as total_points
                FROM student_answers sa
                JOIN questions q ON sa.question_id = q.id
                WHERE sa.student_id = u.id 
                AND sa.exam_id = ?
            ), 0
        ) as total_earned_points,
        COALESCE(
            (
                SELECT SUM(points_earned)
                FROM student_answers sa
                JOIN questions q ON sa.question_id = q.id
                WHERE sa.student_id = u.id 
                AND sa.exam_id = ?
                AND q.question_type = 'QCM'
            ), 0
        ) as qcm_points,
        COALESCE(
            (
                SELECT SUM(points_earned)
                FROM student_answers sa
                JOIN questions q ON sa.question_id = q.id
                WHERE sa.student_id = u.id 
                AND sa.exam_id = ?
                AND q.question_type != 'QCM'
            ), 0
        ) as other_points
    FROM users u
    JOIN group_students gs ON u.id = gs.student_id
    LEFT JOIN exam_attempts ea ON u.id = ea.student_id AND ea.exam_id = ?
    WHERE gs.group_id = ? AND gs.status = 'approved'
    ORDER BY u.name
");

$stmt->execute([$exam_id, $exam_id, $exam_id, $exam_id, $exam_id, $exam_id, $exam['group_id']]);
$students = $stmt->fetchAll();

// Calculer les statistiques
$stats = [
    'total' => count($students),
    'finished' => 0,
    'in_progress' => 0,
    'not_started' => 0,
    'total_points' => 0,
    'graded_count' => 0
];

foreach ($students as $student) {
    $stats[$student['exam_status']]++;
    if ($student['total_earned_points'] > 0) {
        $stats['total_points'] += $student['total_earned_points'];
        $stats['graded_count']++;
    }
}

// Calculate average score out of 20
$average_grade = $stats['graded_count'] > 0 ? ($stats['total_points'] / ($stats['graded_count'] * $max_points)) * 20 : 0;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statut des Étudiants - <?php echo htmlspecialchars($exam['title']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Statut des Étudiants</span>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="exams.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                        Retour aux examens
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if (isset($_GET['message']) && $_GET['message'] === 'success'): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Succès!</strong>
            <span class="block sm:inline">Les notes ont été enregistrées avec succès.</span>
        </div>
        <?php endif; ?>
        
        <!-- Informations de l'examen -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-2">
                <?php echo htmlspecialchars($exam['title']); ?>
            </h2>
            <div class="text-gray-600">
                <p>Groupe: <?php echo htmlspecialchars($exam['group_name']); ?></p>
                <p>Module: <?php echo htmlspecialchars($exam['module_name']); ?></p>
                <p>Date: <?php echo date('d/m/Y H:i', strtotime($exam['start_datetime'])); ?></p>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white shadow rounded-lg p-4">
                <div class="text-sm text-gray-500">Total des étudiants</div>
                <div class="text-2xl font-semibold"><?php echo $stats['total']; ?></div>
            </div>
            <div class="bg-green-50 shadow rounded-lg p-4">
                <div class="text-sm text-green-600">Terminé</div>
                <div class="text-2xl font-semibold text-green-700"><?php echo $stats['finished']; ?></div>
            </div>
            <div class="bg-yellow-50 shadow rounded-lg p-4">
                <div class="text-sm text-yellow-600">En cours</div>
                <div class="text-2xl font-semibold text-yellow-700"><?php echo $stats['in_progress']; ?></div>
            </div>
            <div class="bg-red-50 shadow rounded-lg p-4">
                <div class="text-sm text-red-600">Non commencé</div>
                <div class="text-2xl font-semibold text-red-700"><?php echo $stats['not_started']; ?></div>
            </div>
        </div>

        <!-- Liste des étudiants -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNE</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Questions Répondues</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Note (<?php echo $max_points; ?> pts)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Temps passé</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            
                                Télécharger PDF
                           
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($student['name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($student['cne']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php
                                    switch($student['exam_status']) {
                                        case 'finished':
                                            echo 'bg-green-100 text-green-800';
                                            break;
                                        case 'in_progress':
                                            echo 'bg-yellow-100 text-yellow-800';
                                            break;
                                        default:
                                            echo 'bg-gray-100 text-gray-800';
                                    }
                                    ?>">
                                    <?php
                                    switch($student['exam_status']) {
                                        case 'finished':
                                            echo 'Terminé';
                                            break;
                                        case 'in_progress':
                                            echo 'En cours';
                                            break;
                                        default:
                                            echo 'Non commencé';
                                    }
                                    ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo $student['answered_questions']; ?>/<?php echo $student['total_questions']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php 
                                if ($student['exam_status'] === 'finished') {
                                    echo number_format($student['total_earned_points'], 2) . ' / ' . $max_points;
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                if ($student['started_at'] && $student['completed_at']) {
                                    $start = new DateTime($student['started_at']);
                                    $end = new DateTime($student['completed_at']);
                                    $duration = $start->diff($end);
                                    echo sprintf('%02d:%02d:%02d', $duration->h, $duration->i, $duration->s);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($student['exam_status'] === 'finished'): ?>
                                    <a href="grade_exam.php?exam_id=<?php echo $exam_id; ?>&student_id=<?php echo $student['id']; ?>" 
                                       class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">
                                        Noter
                                    </a>
                                <?php elseif ($student['exam_status'] === 'in_progress'): ?>
                                    En cours...
                                <?php else: ?>
                                    Non commencé
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <a href="download_exam_pdf.php?exam_id=<?php echo $exam_id; ?>&student_id=<?php echo $student['id']; ?>" 
                                   class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                                    Télécharger PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>