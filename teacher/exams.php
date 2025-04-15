<?php
session_start();
require_once '../config.php';

// Vérifier si l'utilisateur est connecté et est un professeur
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Récupérer tous les examens du professeur
$stmt = $pdo->prepare("
    SELECT 
        e.*,
        g.name as group_name,
        m.name as module_name,
        (
            SELECT COUNT(DISTINCT sa.student_id)
            FROM student_answers sa
            WHERE sa.exam_id = e.id
        ) as submissions_count,
        (
            SELECT COUNT(DISTINCT gs.student_id)
            FROM group_students gs
            WHERE gs.group_id = e.group_id
            AND gs.status = 'approved'
        ) as total_students
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    LEFT JOIN modules m ON e.module_id = m.id
    WHERE e.teacher_id = ?
    ORDER BY e.start_datetime DESC
");
$stmt->execute([$_SESSION['user_id']]);
$exams = $stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Examens - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    </style>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Mes Examens</span>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                        Tableau de bord
                    </a>
                    <a href="create_exam.php" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                        Créer un Examen
                    </a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                        Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
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
                        Examens en cours
                    </dt>
                    <dd class="mt-1 text-3xl font-semibold text-green-600">
                        <?php 
                        $active_count = 0;
                        foreach ($exams as $exam) {
                            $now = new DateTime();
                            $start = new DateTime($exam['start_datetime']);
                            $end = (clone $start)->modify("+{$exam['duration']} minutes");
                            if ($now >= $start && $now <= $end) {
                                $active_count++;
                            }
                        }
                        echo $active_count;
                        ?>
                    </dd>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <dt class="text-sm font-medium text-gray-500 truncate">
                        Examens à noter
                    </dt>
                    <dd class="mt-1 text-3xl font-semibold text-yellow-600">
                        <?php 
                        $to_grade = 0;
                        foreach ($exams as $exam) {
                            if ($exam['submissions_count'] > 0) {
                                $to_grade++;
                            }
                        }
                        echo $to_grade;
                        ?>
                    </dd>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['message'])): ?>
            <?php 
            $messageClass = 'bg-green-100 border-green-400 text-green-700';
            $message = '';
            
            switch ($_GET['message']) {
                case 'exam_created':
                    $message = 'L\'examen a été créé avec succès.';
                    break;
                case 'exam_updated':
                    $message = 'L\'examen a été mis à jour avec succès.';
                    break;
                case 'exam_deleted':
                    $message = 'L\'examen a été supprimé avec succès.';
                    break;
                case 'error_no_exam_id':
                    $messageClass = 'bg-red-100 border-red-400 text-red-700';
                    $message = 'Erreur : ID de l\'examen non spécifié.';
                    break;
                case 'error_unauthorized':
                    $messageClass = 'bg-red-100 border-red-400 text-red-700';
                    $message = 'Erreur : Vous n\'êtes pas autorisé à supprimer cet examen.';
                    break;
                case 'error_deleting_exam':
                    $messageClass = 'bg-red-100 border-red-400 text-red-700';
                    $message = 'Erreur lors de la suppression de l\'examen.';
                    break;
            }
            ?>
            <div class="<?php echo $messageClass; ?> px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="mb-6 flex justify-between items-center">
            <div class="flex space-x-4">
                <select id="statusFilter" class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="all">Tous les statuts</option>
                    <option value="upcoming">À venir</option>
                    <option value="active">En cours</option>
                    <option value="completed">Terminé</option>
                </select>
                <select id="typeFilter" class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="all">Tous les types</option>
                    <option value="normal">Normal</option>
                    <option value="rattrapage">Rattrapage</option>
                </select>
                <select id="groupFilter" class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="all">Tous les groupes</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?php echo htmlspecialchars($group['name']); ?>">
                            <?php echo htmlspecialchars($group['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-center">
                <input type="text" id="searchInput" placeholder="Rechercher un examen..." 
                       class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
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
                            Module
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
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($exams as $exam): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($exam['title']); ?>
                                <?php if ($exam['is_rattrapage']): ?>
                                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        Rattrapage
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($exam['group_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($exam['module_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo date('d/m/Y H:i', strtotime($exam['start_datetime'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $now = new DateTime();
                                $start = new DateTime($exam['start_datetime']);
                                $end = (clone $start)->modify("+{$exam['duration']} minutes");
                                
                                if ($now < $start) {
                                    echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">À venir</span>';
                                } elseif ($now >= $start && $now <= $end) {
                                    echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">En cours</span>';
                                } else {
                                    echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Terminé</span>';
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center justify-between">
                                    <span class="mr-3">
                                        <?php echo $exam['submissions_count']; ?> / <?php echo $exam['total_students']; ?>
                                    </span>
                                    <div class="flex space-x-2">
                                        <a href="exam_status.php?exam_id=<?php echo $exam['id']; ?>" 
                                           class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            Statut Étudiants
                                        </a>
                                        <?php if ($exam['submissions_count'] > 0): ?>
                                            <div class="flex space-x-2">
                                                <a href="view_submissions.php?exam_id=<?php echo $exam['id']; ?>" 
                                                   class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                    Voir les copies
                                                </a>
                                                <a href="exam_grades_table.php?exam_id=<?php echo $exam['id']; ?>" 
                                                   class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                                    Tableau des notes
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php
                                        $now = new DateTime();
                                        $start = new DateTime($exam['start_datetime']);
                                        if ($now < $start): ?>
                                            <a href="edit_exam.php?exam_id=<?php echo $exam['id']; ?>" 
                                               class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                                Modifier
                                            </a>
                                            <button onclick="deleteExam(<?php echo $exam['id']; ?>)"
                                                    class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                                Supprimer
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="edit_exam.php?exam_id=<?php echo $exam['id']; ?>" 
                                   class="text-green-600 hover:text-green-900 mr-3">Modifier</a>
                                <a href="#" onclick="deleteExam(<?php echo $exam['id']; ?>)" 
                                   class="text-red-600 hover:text-red-900">Supprimer</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function deleteExam(examId) {
            Swal.fire({
                title: 'Êtes-vous sûr ?',
                text: "Cette action supprimera définitivement l'examen et toutes les données associées !",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `delete_exam.php?exam_id=${examId}`;
                }
            });
        }

        function filterExams() {
            const statusFilter = document.getElementById('statusFilter').value;
            const typeFilter = document.getElementById('typeFilter').value;
            const groupFilter = document.getElementById('groupFilter').value;
            const searchText = document.getElementById('searchInput').value.toLowerCase();
            
            document.querySelectorAll('tbody tr').forEach(row => {
                const title = row.querySelector('td:first-child').textContent.toLowerCase();
                const status = row.querySelector('td:nth-child(5) span').textContent;
                const isRattrapage = row.querySelector('td:first-child .bg-yellow-100') !== null;
                const group = row.querySelector('td:nth-child(2)').textContent.trim();
                
                let showRow = true;
                
                if (statusFilter !== 'all' && !status.toLowerCase().includes(statusFilter)) {
                    showRow = false;
                }
                
                if (typeFilter !== 'all') {
                    if (typeFilter === 'rattrapage' && !isRattrapage) showRow = false;
                    if (typeFilter === 'normal' && isRattrapage) showRow = false;
                }
                
                if (groupFilter !== 'all' && group !== groupFilter) {
                    showRow = false;
                }
                
                if (!title.includes(searchText)) {
                    showRow = false;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }

        document.getElementById('statusFilter').addEventListener('change', filterExams);
        document.getElementById('typeFilter').addEventListener('change', filterExams);
        document.getElementById('groupFilter').addEventListener('change', filterExams);
        document.getElementById('searchInput').addEventListener('input', filterExams);
    </script>
</body>
</html>