<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

$exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : null;
$teacher_id = $_SESSION['user_id'];

if (!$exam_id) {
    header("Location: exams.php");
    exit();
}

// Vérifier que l'examen appartient à ce professeur
$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND teacher_id = ?");
$stmt->execute([$exam_id, $teacher_id]);
if (!$stmt->fetch()) {
    header("Location: exams.php");
    exit();
}

// Récupérer les détails de l'examen et les notes des étudiants
$query = "
    SELECT 
        u.cne, u.name as student_name,
        e.title as exam_title,
        m.name as module_name,
        g.name as group_name,
        q.question_text,
        q.points as max_points,
        sa.points_earned,
        ea.final_grade
    FROM users u
    JOIN group_students gs ON u.id = gs.student_id
    JOIN exams e ON e.group_id = gs.group_id
    JOIN modules m ON e.module_id = m.id
    JOIN study_groups g ON e.group_id = g.id
    JOIN questions q ON e.id = q.exam_id
    LEFT JOIN student_answers sa ON q.id = sa.question_id AND sa.student_id = u.id
    JOIN exam_attempts ea ON ea.exam_id = e.id AND ea.student_id = u.id
    WHERE e.id = ? AND ea.status = 'completed'
    ORDER BY u.name, q.order_num";

$stmt = $pdo->prepare($query);
$stmt->execute([$exam_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    header("Location: exams.php");
    exit();
}

// Organiser les données par étudiant et calculer les totaux
$students = [];
$total_max_points = 0;
$first_student = true;

foreach ($results as $row) {
    $cne = $row['cne'];
    if (!isset($students[$cne])) {
        $students[$cne] = [
            'name' => $row['student_name'],
            'grades' => [],
            'total_earned' => 0,
            'total_possible' => 0
        ];
    }
    
    // Calculer les totaux pour chaque étudiant
    $points_earned = $row['points_earned'] ?? 0;
    $max_points = $row['max_points'];
    
    $students[$cne]['grades'][] = [
        'question' => $row['question_text'],
        'points_earned' => $points_earned,
        'max_points' => $max_points
    ];
    
    $students[$cne]['total_earned'] += $points_earned;
    $students[$cne]['total_possible'] += $max_points;
    
    // Calculer le total maximum une seule fois
    if ($first_student) {
        $total_max_points += $max_points;
    }
}
$first_student = false;

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau des notes - <?php echo htmlspecialchars($results[0]['exam_title']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white shadow-lg rounded-lg p-6 mb-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($results[0]['exam_title']); ?></h1>
                    <p class="text-gray-600">Module: <?php echo htmlspecialchars($results[0]['module_name']); ?></p>
                    <p class="text-gray-600">Groupe: <?php echo htmlspecialchars($results[0]['group_name']); ?></p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNE</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                            <?php foreach ($students[array_key_first($students)]['grades'] as $index => $grade): ?>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="whitespace-normal" style="max-width: 200px;">
                                        <span class="font-bold">Question <?php echo $index + 1; ?></span><br>
                                        <span class="text-gray-700 normal-case font-normal">
                                            <?php echo nl2br(htmlspecialchars($grade['question'])); ?>
                                        </span><br>
                                        <span class="text-gray-400">(<?php echo number_format($grade['max_points'], 1); ?> pts)</span>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Total<br>
                                <span class="text-gray-400">(<?php echo number_format($total_max_points, 1); ?> pts)</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($students as $cne => $student): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($cne); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['name']); ?></td>
                                <?php foreach ($student['grades'] as $grade): ?>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <?php echo number_format($grade['points_earned'], 1); ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="px-6 py-4 text-center whitespace-nowrap font-bold">
                                    <?php echo number_format($student['total_earned'], 1); ?>/<?php echo number_format($student['total_possible'], 1); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="text-center mt-6">
            <a href="exams.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700">
                Retour aux examens
            </a>
        </div>
    </div>
</body>
</html>
