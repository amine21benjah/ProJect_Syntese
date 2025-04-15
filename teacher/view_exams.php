<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

$module_id = $_GET['module_id'] ?? null;

if (!$module_id) {
    header("Location: dashboard.php");
    exit();
}

// Get exams for the module
$stmt = $pdo->prepare("
    SELECT e.*, g.name as group_name
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    WHERE e.module_id = ?
");
$stmt->execute([$module_id]);
$exams = $stmt->fetchAll();

// Get students who passed the exams
$students = [];
foreach ($exams as $exam) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, sa.score
        FROM student_answers sa
        JOIN users u ON sa.student_id = u.id
        WHERE sa.exam_id = ? AND sa.score >= 50
        GROUP BY u.id, u.name, sa.score
    ");
    $stmt->execute([$exam['id']]);
    $students[$exam['id']] = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exams et Étudiants</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Exams et Étudiants</span>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                        Retour au Tableau de bord
                    </a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                        Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg mb-6 p-4">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Exams pour le Module</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Titre</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durée</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date de début</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiants Passés</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($exams as $exam): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['title']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['description']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['group_name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['duration']); ?> minutes</td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['start_datetime']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (!empty($students[$exam['id']])): ?>
                                        <ul>
                                            <?php foreach ($students[$exam['id']] as $student): ?>
                                                <li><?php echo htmlspecialchars($student['name']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (!empty($students[$exam['id']])): ?>
                                        <ul>
                                            <?php foreach ($students[$exam['id']] as $student): ?>
                                                <li><?php echo htmlspecialchars($student['score']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>