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
$filter = $_GET['filter'] ?? 'all';

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
    header("Location: exams.php?message=error_unauthorized");
    exit();
}

// Récupérer tous les étudiants du groupe avec leur statut d'examen
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.name as student_name,
        u.cne,
        COALESCE(ea.status, 'not_started') as exam_status,
        ea.started_at,
        ea.completed_at,
        COALESCE(
            (SELECT SUM(score) FROM student_answers WHERE student_id = u.id AND exam_id = ?),
            0
        ) as total_score,
        (
            SELECT COUNT(DISTINCT question_id) 
            FROM student_answers 
            WHERE student_id = u.id AND exam_id = ?
        ) as answered_questions,
        (SELECT COUNT(*) FROM questions WHERE exam_id = ?) as total_questions
    FROM group_students gs
    INNER JOIN users u ON gs.student_id = u.id
    LEFT JOIN exam_attempts ea ON u.id = ea.student_id AND ea.exam_id = ?
    WHERE gs.group_id = ? 
    AND gs.status = 'approved'
    ORDER BY 
        CASE 
            WHEN ea.status IS NULL THEN 3
            WHEN ea.status = 'completed' THEN 1
            WHEN ea.status = 'in_progress' THEN 2
            ELSE 3
        END,
        u.name ASC
");
$stmt->execute([$exam_id, $exam_id, $exam_id, $exam_id, $exam['group_id']]);
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
        <!-- En-tête avec informations de l'examen -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-2">
                <?php echo htmlspecialchars($exam['title']); ?>
            </h2>
            <p class="text-gray-600">
                Groupe: <?php echo htmlspecialchars($exam['group_name']); ?>
            </p>
        </div>

        <!-- Statistiques -->
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white shadow rounded-lg p-4">
                <div class="text-sm text-gray-500">Total des étudiants</div>
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

        <!-- Filtres -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <div class="flex space-x-4">
                <button onclick="filterStudents('all')" 
                        class="filter-btn active px-4 py-2 rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200">
                    Tous
                </button>
                <button onclick="filterStudents('completed')" 
                        class="filter-btn px-4 py-2 rounded-md bg-green-100 text-green-700 hover:bg-green-200">
                    Terminé
                </button>
                <button onclick="filterStudents('in_progress')" 
                        class="filter-btn px-4 py-2 rounded-md bg-yellow-100 text-yellow-700 hover:bg-yellow-200">
                    En cours
                </button>
                <button onclick="filterStudents('not_started')" 
                        class="filter-btn px-4 py-2 rounded-md bg-red-100 text-red-700 hover:bg-red-200">
                    Non commencé
                </button>
            </div>
        </div>

        <!-- Tableau des étudiants -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNE</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Questions répondues</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Temps passé</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($students as $student): ?>
                        <tr data-status="<?php echo $student['exam_status']; ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($student['student_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($student['cne']); ?>
                            </td>
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
                                <?php if ($student['exam_status'] === 'completed'): ?>
                                    <?php echo number_format($student['total_score'], 1); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
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
    </div>

    <script>
        function filterStudents(status) {
            // Mettre à jour les boutons
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('ring-2', 'ring-offset-2');
            });
            event.target.classList.add('ring-2', 'ring-offset-2');

            // Filtrer les lignes
            document.querySelectorAll('tbody tr').forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Initialiser avec le filtre de l'URL
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const filter = urlParams.get('filter') || 'all';
            const button = document.querySelector(`button[onclick="filterStudents('${filter}')"]`);
            if (button) {
                button.click();
            }
        });
    </script>
</body>
</html>