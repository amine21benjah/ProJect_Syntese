<?php
session_start();
require_once '../config.php';

// Vérifier si l'utilisateur est connecté et est un professeur
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Récupérer les groupes du professeur
$stmt = $pdo->prepare("
    SELECT DISTINCT g.id, g.name
    FROM study_groups g
    JOIN group_teachers gt ON g.id = gt.group_id
    WHERE gt.teacher_id = ?
    ORDER BY g.name
");
$stmt->execute([$_SESSION['user_id']]);
$groups = $stmt->fetchAll();

// Filtrer par groupe si un groupe est sélectionné
$selected_group = $_GET['group_id'] ?? 'all';
$where_clause = "WHERE e.teacher_id = ?";
$params = [$_SESSION['user_id']];

if ($selected_group !== 'all') {
    $where_clause .= " AND g.id = ?";
    $params[] = $selected_group;
}

// Modifier la requête principale pour inclure le filtre de groupe
$stmt = $pdo->prepare("
    SELECT 
        e.id,
        e.title,
        e.start_datetime,
        e.duration,
        g.name as group_name,
        COUNT(DISTINCT sa.student_id) as submissions_count,
        COUNT(DISTINCT gs.student_id) as total_students,
        CASE 
            WHEN NOW() < e.start_datetime THEN 'not_started'
            WHEN NOW() BETWEEN e.start_datetime AND DATE_ADD(e.start_datetime, INTERVAL e.duration MINUTE) THEN 'in_progress'
            ELSE 'ended'
        END as status,
        (
            SELECT COUNT(*)
            FROM student_answers sa2
            WHERE sa2.exam_id = e.id
            AND sa2.score IS NULL
        ) as pending_grades
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    LEFT JOIN student_answers sa ON e.id = sa.exam_id
    JOIN group_students gs ON g.id = gs.group_id AND gs.status = 'approved'
    {$where_clause}
    GROUP BY e.id, e.title, e.start_datetime, e.duration, g.name
    ORDER BY e.start_datetime DESC
");
$stmt->execute($params);
$exams = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Noter les Examens - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Noter les Examens</span>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                        Tableau de bord
                    </a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                        Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="mb-6">
            <form method="GET" class="flex items-center space-x-4">
                <select name="group_id" 
                        class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        onchange="this.form.submit()">
                    <option value="all">Tous les groupes</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?php echo $group['id']; ?>" 
                                <?php echo $selected_group == $group['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($group['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <dt class="text-sm font-medium text-gray-500 truncate">
                        Total des examens
                    </dt>
                    <dd class="mt-1 text-3xl font-semibold text-gray-900">
                        <?php echo count($exams); ?>
                    </dd>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <dt class="text-sm font-medium text-gray-500 truncate">
                        Copies à noter
                    </dt>
                    <dd class="mt-1 text-3xl font-semibold text-yellow-600">
                        <?php 
                        $total_pending = array_sum(array_column($exams, 'pending_grades'));
                        echo $total_pending;
                        ?>
                    </dd>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <dt class="text-sm font-medium text-gray-500 truncate">
                        Taux de participation
                    </dt>
                    <dd class="mt-1 text-3xl font-semibold text-green-600">
                        <?php 
                        $total_submissions = array_sum(array_column($exams, 'submissions_count'));
                        $total_students = array_sum(array_column($exams, 'total_students'));
                        echo $total_students > 0 ? 
                            round(($total_submissions / $total_students) * 100) . '%' : 
                            '0%';
                        ?>
                    </dd>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Titre
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Groupe
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Date
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Statut
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Soumissions
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            À noter
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($exams as $exam): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($exam['title']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($exam['group_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo date('d/m/Y H:i', strtotime($exam['start_datetime'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $status_text = '';
                                $status_class = '';
                                switch ($exam['status']) {
                                    case 'not_started':
                                        $status_text = 'À venir';
                                        $status_class = 'bg-yellow-100 text-yellow-800';
                                        break;
                                    case 'in_progress':
                                        $status_text = 'En cours';
                                        $status_class = 'bg-green-100 text-green-800';
                                        break;
                                    case 'ended':
                                        $status_text = 'Terminé';
                                        $status_class = 'bg-gray-100 text-gray-800';
                                        break;
                                }
                                ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo $exam['submissions_count']; ?> / <?php echo $exam['total_students']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($exam['pending_grades'] > 0): ?>
                                    <span class="text-red-600 font-medium"><?php echo $exam['pending_grades']; ?></span>
                                <?php else: ?>
                                    <span class="text-green-600">Tout noté</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <?php if ($exam['submissions_count'] > 0): ?>
                                    <a href="grade_exam.php?exam_id=<?php echo $exam['id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900">
                                        Noter les copies
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400">Aucune copie</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
