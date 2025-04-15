<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

// Get student's approved groups and their exams
$stmt = $pdo->prepare("
    SELECT g.*, GROUP_CONCAT(t.name SEPARATOR ', ') as teacher_names
    FROM study_groups g
    LEFT JOIN group_teachers gt ON g.id = gt.group_id
    LEFT JOIN users t ON gt.teacher_id = t.id
    JOIN group_students sg ON g.id = sg.group_id
    WHERE sg.student_id = ? AND sg.status = 'approved'
    GROUP BY g.id
");
$stmt->execute([$_SESSION['user_id']]);
$groups = $stmt->fetchAll();

// Get all exams for the student
$stmt = $pdo->prepare("
    SELECT 
        e.*,
        g.name as group_name,
        t.name as teacher_name,
        m.name as module_name,
        ea.status as attempt_status,
        CASE 
            WHEN NOW() < e.start_datetime THEN 'not_started'
            WHEN NOW() BETWEEN e.start_datetime AND DATE_ADD(e.start_datetime, INTERVAL e.duration MINUTE) THEN 'in_progress'
            WHEN EXISTS (
                SELECT 1 
                FROM exam_attempts ea2 
                WHERE ea2.exam_id = e.id 
                AND ea2.student_id = ? 
                AND ea2.status = 'in_progress'
            ) THEN 'can_continue'
            ELSE 'ended'
        END as exam_status,
        CASE 
            WHEN e.is_rattrapage = 1 THEN 'rattrapage'
            ELSE 'normal'
        END as exam_type,
        oe.title as original_exam_title,
        COALESCE(
            (SELECT SUM(sa.score)
             FROM student_answers sa
             WHERE sa.exam_id = oe.id 
             AND sa.student_id = ?), 0
        ) as original_exam_score,
        (SELECT SUM(points) FROM questions WHERE exam_id = oe.id) as original_exam_total_points
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    JOIN modules m ON e.module_id = m.id
    JOIN group_students gs ON g.id = gs.group_id
    LEFT JOIN exam_attempts ea ON e.id = ea.exam_id AND ea.student_id = ?
    LEFT JOIN users t ON e.teacher_id = t.id
    LEFT JOIN exams oe ON e.original_exam_id = oe.id
    WHERE gs.student_id = ? 
    AND gs.status = 'approved'
    AND e.deleted = 0
    AND (
        (e.is_rattrapage = 0)
        OR (
            e.is_rattrapage = 1
            AND NOT EXISTS (
                SELECT 1 
                FROM exam_attempts ea_orig
                WHERE ea_orig.exam_id = e.original_exam_id 
                AND ea_orig.student_id = gs.student_id
            )
        )
    )
    ORDER BY e.start_datetime DESC
");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
$exams = $stmt->fetchAll();

// Group exams by status
$current_exams = [];
$past_exams = [];
$future_exams = [];

foreach ($exams as $exam) {
    // Check if exam has been completed
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM exam_attempts 
        WHERE exam_id = ? 
        AND student_id = ? 
        AND status = 'completed'
    ");
    $stmt->execute([$exam['id'], $_SESSION['user_id']]);
    $completed = $stmt->fetch()['count'] > 0;
    $exam['completed'] = $completed;

    if ($exam['exam_status'] === 'in_progress' || $exam['exam_status'] === 'can_continue') {
        $current_exams[] = $exam;
    } elseif ($exam['exam_status'] === 'ended' || $completed) {
        $past_exams[] = $exam;
    } else {
        $future_exams[] = $exam;
    }
}

// Récupérer les examens passés
$stmt = $pdo->prepare("
    SELECT 
        e.*,
        m.name as module_name,
        g.name as group_name,
        COALESCE(SUM(sa.points_earned), 0) as total_score,
        (SELECT SUM(points) FROM questions WHERE exam_id = e.id) as total_possible_score,
        ea.status as attempt_status,
        ea.completed_at,
        CASE WHEN e.is_rattrapage = 1 THEN 'rattrapage' ELSE 'normal' END as exam_type
    FROM exams e
    JOIN modules m ON e.module_id = m.id
    JOIN study_groups g ON e.group_id = g.id
    LEFT JOIN student_answers sa ON e.id = sa.exam_id AND sa.student_id = ?
    LEFT JOIN exam_attempts ea ON e.id = ea.exam_id AND ea.student_id = ?
    WHERE e.group_id IN (
        SELECT group_id 
        FROM group_students 
        WHERE student_id = ? AND status = 'approved'
    )
    AND (
        (e.start_datetime + INTERVAL e.duration MINUTE) < NOW()
        OR ea.status = 'completed'
    )
    AND e.deleted = 0
    GROUP BY e.id, m.name, g.name, ea.status, ea.completed_at
    ORDER BY e.start_datetime DESC
");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
$past_exams = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Étudiant - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-4">
                    <?php
                    // Get student's profile info including profile picture
                    $stmt = $pdo->prepare("SELECT name, profile_picture FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $student = $stmt->fetch();
                    ?>
                    <!-- Profile Picture -->
                    <div class="flex-shrink-0">
                        <?php if ($student['profile_picture']): ?>
                            <img src="../<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                 alt="Profile" 
                                 class="h-10 w-10 rounded-full object-cover border-2 border-gray-200">
                        <?php else: ?>
                            <div class="h-10 w-10 rounded-full bg-blue-600 flex items-center justify-center">
                                <span class="text-white text-lg font-bold">
                                    <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Student Name and Dashboard Title -->
                    <div class="flex flex-col">
                        <span class="text-sm text-gray-500">Bienvenue,</span>
                        <span class="text-lg font-bold text-blue-600"><?php echo htmlspecialchars($student['name']); ?></span>
                    </div>
                </div>
                <div class="flex items-center">
                    <a href="quizzes.php" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 mr-4 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Créer un Quiz
                    </a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                        Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if (isset($_GET['message'])): ?>
            <div class="<?php 
                switch($_GET['message']) {
                    case 'exam_not_found':
                    case 'not_in_group':
                    case 'exam_ended':
                    case 'exam_not_started':
                        echo 'bg-red-100 border border-red-400 text-red-700';
                        break;
                    case 'exam_submitted':
                        echo 'bg-green-100 border border-green-400 text-green-700';
                        break;
                    case 'exam_already_completed':
                        echo 'bg-yellow-100 border border-yellow-400 text-yellow-700';
                        break;
                    default:
                        echo 'bg-blue-100 border border-blue-400 text-blue-700';
                }
            ?> px-4 py-3 rounded mb-4">
                <?php 
                switch($_GET['message']) {
                    case 'exam_not_found':
                        echo "L'examen n'a pas été trouvé ou n'existe plus.";
                        break;
                    case 'not_in_group':
                        echo "Vous n'êtes pas autorisé à accéder à cet examen car vous n'appartenez pas au groupe.";
                        break;
                    case 'exam_ended':
                        echo "Cet examen est terminé.";
                        break;
                    case 'exam_not_started':
                        echo "Cet examen n'a pas encore commencé.";
                        break;
                    case 'exam_submitted':
                        echo "Votre examen a été soumis avec succès.";
                        break;
                    case 'exam_already_completed':
                        echo "Vous avez déjà terminé cet examen.";
                        break;
                    default:
                        echo htmlspecialchars($_GET['message']);
                }
                ?>
            </div>
        <?php endif; ?>

        <?php if (empty($groups)): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded text-center">
                <p class="text-lg font-medium">Vous n'êtes inscrit à aucun groupe.</p>
            </div>
        <?php else: ?>
            <!-- Mes Groupes -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-900">Mes Groupes</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($groups as $group): ?>
                        <div class="border rounded-lg p-4">
                            <h3 class="font-medium text-lg mb-2"><?php echo htmlspecialchars($group['name']); ?></h3>
                            <p class="text-gray-600">Enseignant(s): <?php echo htmlspecialchars($group['teacher_names']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Examens à venir -->
        <?php if (!empty($future_exams)): ?>
            <div id="upcoming-exams-section" class="bg-white shadow rounded-lg mb-6 p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Examens à venir</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Titre</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Module</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date de début</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durée</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($future_exams as $exam): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['title']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['module_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($exam['exam_type'] === 'rattrapage'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                Rattrapage
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                Normal
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['group_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo date('d/m/Y H:i', strtotime($exam['start_datetime'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo $exam['duration']; ?> minutes</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Examens en cours -->
        <div id="active-exams-container">
            <?php if (!empty($current_exams)): ?>
                <div id="active-exams-section" class="bg-white shadow rounded-lg mb-6 p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Examens en cours</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Titre</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Module</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durée</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($current_exams as $exam): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($exam['title']); ?>
                                            <?php if (isset($exam['exam_type']) && $exam['exam_type'] === 'rattrapage'): ?>
                                                <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                    Rattrapage
                                                </span>
                                                <?php if ($exam['original_exam_title']): ?>
                                                    <span class="text-xs text-gray-500">
                                                        (Pour: <?php echo htmlspecialchars($exam['original_exam_title']); ?> - 
                                                        Score: <?php echo $exam['original_exam_score']; ?>/<?php echo $exam['original_exam_total_points']; ?>)
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($exam['module_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if ($exam['exam_type'] === 'rattrapage'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                    Rattrapage
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    Normal
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo isset($exam['group_name']) ? htmlspecialchars($exam['group_name']) : ''; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('d/m/Y H:i', strtotime($exam['start_datetime'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $exam['duration']; ?> minutes
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if ($exam['completed']): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                    Terminé
                                                </span>
                                            <?php elseif ($exam['exam_status'] === 'in_progress'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                    En cours
                                                </span>
                                            <?php elseif ($exam['exam_status'] === 'can_continue'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                    À continuer
                                                </span>
                                            <?php elseif ($exam['exam_status'] === 'ended'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                    Terminé
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                    À venir
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if ($exam['completed']): ?>
                                                <span class="text-gray-500">Examen terminé</span>
                                            <?php elseif ($exam['exam_status'] === 'in_progress'): ?>
                                                <a href="take_exam.php?exam_id=<?php echo $exam['id']; ?>" 
                                                   class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                                                    Commencer
                                                </a>
                                            <?php elseif ($exam['exam_status'] === 'can_continue'): ?>
                                                <a href="take_exam.php?exam_id=<?php echo $exam['id']; ?>" 
                                                   class="bg-yellow-500 text-white px-4 py-2 rounded-md hover:bg-yellow-600">
                                                    Continuer
                                                </a>
                                            <?php elseif ($exam['exam_status'] === 'ended'): ?>
                                                <span class="text-gray-500">Expiré</span>
                                            <?php else: ?>
                                                <span class="text-gray-500">Pas encore commencé</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Examens terminés -->
        <?php if (!empty($past_exams)): ?>
            <div class="bg-white shadow rounded-lg p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Examens terminés</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Titre</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Module</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($past_exams as $exam): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['title']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['module_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($exam['exam_type'] === 'rattrapage'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                Rattrapage
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                Normal
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo isset($exam['group_name']) ? htmlspecialchars($exam['group_name']) : ''; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo date('d/m/Y H:i', strtotime($exam['start_datetime'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_text = '';
                                        $status_color = '';
                                        
                                        if ($exam['attempt_status'] === 'completed') {
                                            $status_text = 'Terminé';
                                            $status_color = 'text-green-600';
                                        } elseif ($exam['attempt_status'] === 'time_expired' || 
                                                  strtotime($exam['start_datetime']) + ($exam['duration'] * 60) < time()) {
                                            $status_text = 'Temps expiré';
                                            $status_color = 'text-orange-600';
                                        } else {
                                            $status_text = 'Non terminé';
                                            $status_color = 'text-red-600';
                                        }
                                        ?>
                                        <span class="<?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (isset($exam['total_score'])): ?>
                                            <span class="font-medium">
                                                <?php echo number_format($exam['total_score'], 1); ?> / 
                                                <?php echo number_format($exam['total_possible_score'], 1); ?>
                                                <?php if ($exam['total_possible_score'] > 0): ?>
                                                    <span class="text-sm text-gray-500">
                                                        (<?php echo number_format(($exam['total_score'] / $exam['total_possible_score']) * 100, 1); ?>%)
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-500">Non noté</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($exam['attempt_status'] === 'completed' || $exam['attempt_status'] === 'time_expired'): ?>
                                            <div class="flex space-x-2">
                                                <a href="view_result.php?exam_id=<?php echo $exam['id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-800">
                                                    Voir les résultats
                                                </a>
                                                <a href="exam_details_table.php?exam_id=<?php echo $exam['id']; ?>" 
                                                   class="text-green-600 hover:text-green-800">
                                                    Voir le tableau détaillé
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Function to update timers
        function updateTimers() {
            const timeElements = document.querySelectorAll('.remaining-time');
            timeElements.forEach(element => {
                const endTime = parseInt(element.dataset.endTime);
                const currentTime = Math.floor(Date.now() / 1000);
                const remainingMinutes = Math.ceil((endTime - currentTime) / 60);
                element.textContent = `${remainingMinutes} minutes`;
            });
        }

        // Update timers every second
        setInterval(updateTimers, 1000);

        // Initial update
        updateTimers();
    </script>
</body>
</html>
