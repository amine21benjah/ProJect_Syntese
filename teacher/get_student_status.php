<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    exit('Non autorisé');
}

$exam_id = $_GET['exam_id'] ?? null;
if (!$exam_id) {
    exit('ID d\'examen manquant');
}

// Vérifier si l'examen appartient au professeur
$stmt = $pdo->prepare("
    SELECT e.*, g.name as group_name 
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    WHERE e.id = ? AND e.teacher_id = ?
");
$stmt->execute([$exam_id, $_SESSION['user_id']]);
$exam = $stmt->fetch();

if (!$exam) {
    exit('Examen non trouvé');
}

// Récupérer les étudiants et leur statut
$stmt = $pdo->prepare("
    SELECT 
        u.name as student_name,
        u.cne,
        COALESCE(ea.status, 'not_started') as exam_status,
        ea.started_at,
        ea.completed_at,
        (
            SELECT COUNT(DISTINCT question_id) 
            FROM student_answers 
            WHERE student_id = u.id AND exam_id = ?
        ) as answered_questions,
        (SELECT COUNT(*) FROM questions WHERE exam_id = ?) as total_questions
    FROM users u
    JOIN group_students gs ON u.id = gs.student_id
    WHERE gs.group_id = ? AND gs.status = 'approved'
    LEFT JOIN exam_attempts ea ON u.id = ea.student_id AND ea.exam_id = ?
    ORDER BY u.name
");
$stmt->execute([$exam_id, $exam_id, $exam['group_id'], $exam_id]);
$students = $stmt->fetchAll();

// Calculer les statistiques
$stats = [
    'total' => count($students),
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0
];

foreach ($students as $student) {
    $stats[$student['exam_status']]++;
}
?>

<!-- Statistiques -->
<div class="grid grid-cols-4 gap-4 mb-6">
    <div class="bg-white shadow rounded-lg p-4">
        <div class="text-sm text-gray-500">Total</div>
        <div class="text-2xl font-semibold"><?php echo $stats['total']; ?></div>
    </div>
    <div class="bg-green-50 shadow rounded-lg p-4">
        <div class="text-sm text-green-600">Terminé</div>
        <div class="text-2xl font-semibold text-green-700"><?php echo $stats['completed']; ?></div>
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
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Étudiant</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">CNE</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Questions</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Temps</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php foreach ($students as $student): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['student_name']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['cne']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php
                        $status_class = match($student['exam_status']) {
                            'completed' => 'bg-green-100 text-green-800',
                            'in_progress' => 'bg-yellow-100 text-yellow-800',
                            default => 'bg-red-100 text-red-800'
                        };
                        $status_text = match($student['exam_status']) {
                            'completed' => 'Terminé',
                            'in_progress' => 'En cours',
                            default => 'Non commencé'
                        };
                        ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php echo $student['answered_questions']; ?> / <?php echo $student['total_questions']; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php
                        if ($student['started_at'] && $student['completed_at']) {
                            $start = new DateTime($student['started_at']);
                            $end = new DateTime($student['completed_at']);
                            $duration = $start->diff($end);
                            echo $duration->format('%H:%I:%S');
                        } elseif ($student['started_at']) {
                            echo 'En cours...';
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div> 