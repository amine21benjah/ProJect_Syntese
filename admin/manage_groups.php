<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_group'])) {
        $group_code = strtoupper($_POST['group_code']);
        $teacher_id = $_POST['teacher_id'];
        
        // Validate group code format (2 letters + 3 numbers)
        if (!preg_match('/^[A-Z]{2}\d{3}$/', $group_code)) {
            $error = "Le code du groupe doit contenir 2 lettres suivies de 3 chiffres (ex: DD102)";
        } else {
            // Check if group code already exists
            $stmt = $pdo->prepare("SELECT id FROM study_groups WHERE code = ?");
            $stmt->execute([$group_code]);
            
            if ($stmt->fetch()) {
                $error = "Ce code de groupe existe déjà";
            } else {
                $stmt = $pdo->prepare("INSERT INTO study_groups (code, name, teacher_id) VALUES (?, ?, ?)");
                if ($stmt->execute([$group_code, $group_code, $teacher_id])) {
                    $success = "Groupe créé avec succès";
                } else {
                    $error = "Erreur lors de la création du groupe";
                }
            }
        }
    } elseif (isset($_POST['delete_group'])) {
        $group_id = $_POST['group_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Delete student_groups entries
            $stmt = $pdo->prepare("DELETE FROM student_groups WHERE group_id = ?");
            $stmt->execute([$group_id]);
            
            // Delete group
            $stmt = $pdo->prepare("DELETE FROM study_groups WHERE id = ?");
            $stmt->execute([$group_id]);
            
            $pdo->commit();
            $success = "Groupe supprimé avec succès";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur lors de la suppression du groupe";
        }
    }
}

// Get all teachers
$stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE user_type = 'teacher' AND status = 'approved'");
$stmt->execute();
$teachers = $stmt->fetchAll();

// Get all groups with teacher names
$stmt = $pdo->prepare("
    SELECT g.*, u.name as teacher_name, 
           COUNT(DISTINCT sg.student_id) as student_count
    FROM study_groups g
    JOIN users u ON g.teacher_id = u.id
    LEFT JOIN student_groups sg ON g.id = sg.group_id
    GROUP BY g.id
    ORDER BY g.code
");
$stmt->execute();
$groups = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Groupes - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Gestion des Groupes</span>
                </div>
                <div class="flex items-center">
                    <a href="dashboard.php" class="text-gray-700 hover:text-blue-600 mr-4">
                        Retour au tableau de bord
                    </a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                        Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <!-- Create Group Form -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Créer un nouveau groupe</h3>
                <form method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="group_code" class="block text-sm font-medium text-gray-700">
                                Code du groupe (ex: DD102)
                            </label>
                            <input type="text" name="group_code" id="group_code" required
                                   pattern="[A-Za-z]{2}[0-9]{3}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                   placeholder="DD102">
                        </div>
                        <div>
                            <label for="teacher_id" class="block text-sm font-medium text-gray-700">
                                Enseignant
                            </label>
                            <select name="teacher_id" id="teacher_id" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Sélectionnez un enseignant</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>">
                                        <?php echo htmlspecialchars($teacher['name']); ?> 
                                        (<?php echo htmlspecialchars($teacher['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" name="create_group"
                                class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                            Créer le groupe
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Groups List -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Liste des groupes</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Code
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Enseignant
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Nombre d'étudiants
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($groups as $group): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo htmlspecialchars($group['code']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo htmlspecialchars($group['teacher_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo $group['student_count']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <form method="POST" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce groupe ?');">
                                            <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                            <button type="submit" name="delete_group"
                                                    class="text-red-600 hover:text-red-900">
                                                Supprimer
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
