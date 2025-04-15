<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Get group ID from query parameters
if (!isset($_GET['group_id'])) {
    header("Location: dashboard.php");
    exit();
}

$group_id = $_GET['group_id'];

// Get group details
$stmt = $pdo->prepare("SELECT * FROM study_groups WHERE id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch();

if (!$group) {
    header("Location: dashboard.php");
    exit();
}

// Get students in the group
$stmt = $pdo->prepare("
    SELECT u.* 
    FROM users u
    JOIN group_students gs ON u.id = gs.student_id
    WHERE gs.group_id = ?
");
$stmt->execute([$group_id]);
$students = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Étudiants du groupe <?php echo htmlspecialchars($group['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Étudiants du groupe <?php echo htmlspecialchars($group['name']); ?></span>
                </div>
                <div class="flex items-center">
                    <a href="dashboard.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700 mr-2">Retour au tableau de bord</a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Déconnexion</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Liste des étudiants</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['email']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
