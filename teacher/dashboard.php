<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Add this query near the top of the file, after the session check
$stmt = $pdo->prepare("SELECT u.name, u.profile_picture FROM users u WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Handle module creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_module'])) {
    $module_name = $_POST['module_name'];
    $module_description = $_POST['module_description'];
    $group_ids = $_POST['group_ids'] ?? [];

    try {
        $pdo->beginTransaction();

        // Insert the module
        $stmt = $pdo->prepare("INSERT INTO modules (name, description, teacher_id) VALUES (?, ?, ?)");
        $stmt->execute([$module_name, $module_description, $_SESSION['user_id']]);
        $module_id = $pdo->lastInsertId();

        // Assign the module to the selected groups
        foreach ($group_ids as $group_id) {
            $stmt = $pdo->prepare("INSERT INTO group_modules (group_id, module_id) VALUES (?, ?)");
            $stmt->execute([$group_id, $module_id]);
        }

        $pdo->commit();
        header("Location: dashboard.php?message=module_created");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Erreur lors de la création du module : " . $e->getMessage();
    }
}

// Handle module deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_module'])) {
    $module_id = $_POST['module_id'];

    try {
        $pdo->beginTransaction();

        // Delete related records in group_modules
        $stmt = $pdo->prepare("DELETE FROM group_modules WHERE module_id = ?");
        $stmt->execute([$module_id]);

        // Delete related records in questions
        $stmt = $pdo->prepare("
            DELETE q FROM questions q
            JOIN exams e ON q.exam_id = e.id
            WHERE e.module_id = ?
        ");
        $stmt->execute([$module_id]);

        // Delete the module
        $stmt = $pdo->prepare("DELETE FROM modules WHERE id = ?");
        $stmt->execute([$module_id]);

        $pdo->commit();
        header("Location: dashboard.php?message=module_deleted");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Erreur lors de la suppression du module : " . $e->getMessage();
    }
}

// Get teacher's modules
$stmt = $pdo->prepare("SELECT * FROM modules WHERE teacher_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$modules = $stmt->fetchAll();

// Get teacher's groups
$stmt = $pdo->prepare("
    SELECT DISTINCT g.* 
    FROM study_groups g
    JOIN group_modules gm ON g.id = gm.group_id
    JOIN modules m ON gm.module_id = m.id
    WHERE m.teacher_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$teacher_groups = $stmt->fetchAll();

// Handle student approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve_student'])) {
        $student_id = $_POST['student_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Update user status to approved
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'approved' 
                WHERE id = ?
            ");
            $stmt->execute([$student_id]);
            
            $pdo->commit();
            header("Location: dashboard.php?message=student_approved");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur lors de l'approbation : " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_student'])) {
        $student_id = $_POST['student_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Update user status back to pending_group
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'pending_group' 
                WHERE id = ?
            ");
            $stmt->execute([$student_id]);
            
            $pdo->commit();
            header("Location: dashboard.php?message=student_rejected");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur lors du rejet : " . $e->getMessage();
        }
    }
}

// Handle module assignment to groups
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_module'])) {
    $module_id = $_POST['module_id'];
    $group_ids = $_POST['group_ids'] ?? [];

    if (count($group_ids) > 0) {
        $stmt = $pdo->prepare("DELETE FROM group_modules WHERE module_id = ?");
        $stmt->execute([$module_id]);

        foreach ($group_ids as $group_id) {
            $stmt = $pdo->prepare("INSERT INTO group_modules (group_id, module_id) VALUES (?, ?)");
            $stmt->execute([$group_id, $module_id]);
        }

        header("Location: dashboard.php?message=module_assigned");
        exit();
    } else {
        $error = "Veuillez sélectionner au moins un groupe.";
    }
}

// Get all groups
$stmt = $pdo->query("SELECT * FROM study_groups");
$groups = $stmt->fetchAll();

// Get modules with their assigned groups
$stmt = $pdo->prepare("
    SELECT m.*, GROUP_CONCAT(g.name SEPARATOR ', ') as group_names
    FROM modules m
    LEFT JOIN group_modules gm ON m.id = gm.module_id
    LEFT JOIN study_groups g ON gm.group_id = g.id
    WHERE m.teacher_id = ?
    GROUP BY m.id
");
$stmt->execute([$_SESSION['user_id']]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord Enseignant</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-4">
                    <!-- Profile Picture and Welcome Message -->
                    <div class="flex items-center space-x-3">
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars('../' . $user['profile_picture']); ?>" 
                                 alt="Profile" 
                                 class="h-12 w-12 rounded-full object-cover"
                                 onerror="this.src='../assets/images/default-avatar.png'">
                        <?php else: ?>
                            <img src="../assets/images/default-avatar.png" 
                                 alt="Default Profile" 
                                 class="h-12 w-12 rounded-full object-cover">
                        <?php endif; ?>
                        <div class="flex flex-col">
                            <span class="text-gray-600">Bienvenue,</span>
                            <span class="text-blue-600 font-semibold"><?php echo htmlspecialchars($user['name'] ?? 'Enseignant'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="exams.php" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                        Mes Examens
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
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($_GET['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-blue-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">
                                    Créer un examen
                                </dt>
                                <dd>
                                    <a href="create_exam.php" class="text-blue-600 hover:text-blue-900">
                                        Commencer →
                                    </a>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">
                                    Corriger les examens
                                </dt>
                                <dd>
                                    <a href="javascript:void(0);" class="text-green-600 hover:text-green-900">
                                        Voir les soumissions →
                                    </a>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">
                                    Gérer les modules
                                </dt>
                                <dd class="mt-1 text-3xl font-semibold text-gray-900">
                                    <a href="#" class="text-yellow-600 hover:text-yellow-800">
                                        <?php echo count($modules); ?> module(s)
                                    </a>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Remplacer la section des boutons par celle-ci -->
        <div class="flex space-x-4 mb-6">
            <button onclick="toggleSection('modules-section')" 
                    class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                Gestion des Modules
            </button>
            <button onclick="toggleSection('vos-groupes')" 
                    class="bg-yellow-600 text-white px-6 py-2 rounded-md hover:bg-yellow-700">
                Vos Groupes
            </button>
            <a href="quizzes.php" class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Créer un Quiz
            </a>
        </div>

        <!-- Nouvelle section combinée pour les modules -->
        <div id="modules-section" style="display: block;">
            <!-- Section création module -->
            <div class="bg-white shadow rounded-lg mb-6 p-4">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-medium text-gray-900">Créer un nouveau module</h2>
                    <button onclick="toggleModuleForm()" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                        <span id="toggleButtonText">Masquer le formulaire</span>
                    </button>
                </div>
                <div id="moduleForm">
                    <form method="POST">
                        <div class="mb-4">
                            <label for="module_name" class="block text-sm font-medium text-gray-700">Nom du module</label>
                            <input type="text" name="module_name" id="module_name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                        </div>
                        <div class="mb-4">
                            <label for="module_description" class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea name="module_description" id="module_description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="group_ids" class="block text-sm font-medium text-gray-700">Groupes</label>
                            <select name="group_ids[]" id="group_ids" multiple required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="create_module" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Créer</button>
                    </form>
                </div>
            </div>

            <!-- Section liste des modules -->
            <div class="bg-white shadow rounded-lg mb-6 p-4">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Liste des Modules</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($modules as $module): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($module['name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($module['description']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($module['group_names'] ?: 'Aucun groupe'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <form method="POST" class="inline-block">
                                            <input type="hidden" name="module_id" value="<?php echo $module['id']; ?>">
                                            <select name="group_ids[]" multiple required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                                <?php foreach ($groups as $group): ?>
                                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="assign_module" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 mt-2">Modifier l'examen</button>
                                        </form>
                                        <form method="POST" class="inline-block">
                                            <input type="hidden" name="module_id" value="<?php echo $module['id']; ?>">
                                            <button type="submit" name="delete_module" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 mt-2">Supprimer</button>
                                        </form>
                                        <a href="view_exams.php?module_id=<?php echo $module['id']; ?>" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 mt-2">Voir les examens</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modifiez la section "Vos Groupes" -->
        <div id="vos-groupes" style="display: none;">
            <div class="bg-white shadow rounded-lg mb-6 p-4">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Vos Groupes</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Module</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($teacher_groups as $group): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($group['name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($group['description']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $stmt = $pdo->prepare("
                                            SELECT m.name 
                                            FROM modules m
                                            JOIN group_modules gm ON m.id = gm.module_id
                                            WHERE gm.group_id = ?
                                        ");
                                        $stmt->execute([$group['id']]);
                                        $modules = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                        echo htmlspecialchars(implode(', ', $modules));
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <a href="view_students.php?group_id=<?php echo $group['id']; ?>" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Voir les étudiants</a>
                                        <a href="grade_exams.php?group_id=<?php echo $group['id']; ?>" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 ml-4">Corriger les examens</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Show modules section by default
            showModulesSection();
        });

        function showModulesSection() {
            const modulesSection = document.getElementById('modules-section');
            const groupesSection = document.getElementById('vos-groupes');
            if (modulesSection && groupesSection) {
                modulesSection.style.display = 'block';
                groupesSection.style.display = 'none';
            }
        }

        function toggleSection(sectionId) {
            // Get all sections
            const modulesSection = document.getElementById('modules-section');
            const groupesSection = document.getElementById('vos-groupes');
            
            if (modulesSection && groupesSection) {
                // Hide all sections first
                modulesSection.style.display = 'none';
                groupesSection.style.display = 'none';
                
                // Show the selected section
                const selectedSection = document.getElementById(sectionId);
                if (selectedSection) {
                    selectedSection.style.display = 'block';
                }
            }
        }

        function toggleModuleForm() {
            const form = document.getElementById('moduleForm');
            const buttonText = document.getElementById('toggleButtonText');
            
            if (form && buttonText) {
                if (form.style.display === 'none') {
                    form.style.display = 'block';
                    buttonText.textContent = 'Masquer le formulaire';
                } else {
                    form.style.display = 'none';
                    buttonText.textContent = 'Afficher le formulaire';
                }
            }
        }
    </script>
</body>
</html>
