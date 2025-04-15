<?php
require_once '../config.php';

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
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de l'examen</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white shadow-lg rounded-lg p-6 mb-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($results[0]['exam_title']); ?></h1>
                <p class="text-gray-600">Module: <?php echo htmlspecialchars($results[0]['module_name']); ?></p>
                <p class="text-gray-600">Étudiant: <?php echo htmlspecialchars($results[0]['student_name']); ?> (CNE: <?php echo htmlspecialchars($results[0]['cne']); ?>)</p>
                <p class="text-gray-600">Note finale: <?php echo number_format($results[0]['final_grade'], 2); ?>/20</p>
                <p class="text-gray-600">Date de soumission: <?php echo date('d/m/Y H:i', strtotime($results[0]['completed_at'])); ?></p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Question</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Votre réponse</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Réponse correcte</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Note obtenue</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Note maximale</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($results as $row): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-normal">
                                <div class="text-sm text-gray-900">
                                    <?php echo nl2br(htmlspecialchars($row['question_text'])); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-normal">
                                <div class="text-sm <?php echo ($row['points_earned'] == $row['max_points']) ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo nl2br(htmlspecialchars($row['answer_text'] ?? 'Aucune réponse')); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-normal">
                                <div class="text-sm text-gray-900">
                                    <?php echo nl2br(htmlspecialchars($row['correct_answer'])); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="text-sm <?php echo ($row['points_earned'] == $row['max_points']) ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo number_format($row['points_earned'] ?? 0, 2); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="text-sm text-gray-900">
                                    <?php echo number_format($row['max_points'], 2); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="text-center mt-6 space-x-4">
            <a href="dashboard.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700">
                Retour au tableau de bord
            </a>
            <a href="download_exam_pdf.php?exam_id=<?php echo $exam_id; ?>" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                Télécharger en PDF
            </a>
        </div>
    </div>
</body>
</html>
