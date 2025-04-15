<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

// Get all available groups with teacher names
$stmt = $pdo->prepare("
    SELECT g.id, g.name, g.description, 
           GROUP_CONCAT(t.name SEPARATOR ', ') as teacher_names,
           ANY_VALUE(sg.status) as status,
           CASE WHEN ANY_VALUE(sg.status) IS NOT NULL THEN 1 ELSE 0 END as is_enrolled
    FROM study_groups g
    LEFT JOIN group_teachers gt ON g.id = gt.group_id
    LEFT JOIN users t ON gt.teacher_id = t.id
    LEFT JOIN group_students sg ON g.id = sg.group_id AND sg.student_id = ?
    WHERE t.status = 'approved'
    GROUP BY g.id, g.name, g.description
    ORDER BY g.name
");
$stmt->execute([$_SESSION['user_id']]);
$groups = $stmt->fetchAll();

// Handle group enrollment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['join_group'])) {
    $group_id = $_POST['group_id'];
    
    // Check if the group exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM study_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group_exists = $stmt->fetchColumn();
    
    if ($group_exists == 0) {
        $error = "Le groupe sélectionné n'existe pas.";
    } else {
        // Check if already enrolled in any group
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE student_id = ? AND status = 'approved'");
        $stmt->execute([$_SESSION['user_id']]);
        $is_enrolled = $stmt->fetchColumn();
        
        if ($is_enrolled > 0) {
            $error = "Vous êtes déjà inscrit dans un autre groupe.";
        } else {
            try {
                // Insert new enrollment request
                $stmt = $pdo->prepare("INSERT INTO group_students (student_id, group_id, status) VALUES (?, ?, 'pending')");
                if ($stmt->execute([$_SESSION['user_id'], $group_id])) {
                    // Update user status to pending_group
                    $stmt = $pdo->prepare("UPDATE users SET status = 'pending_group' WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    header("Location: join_groups.php?message=request_sent");
                    exit();
                }
            } catch(PDOException $e) {
                $error = "Erreur lors de l'inscription au groupe: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejoindre des Groupes - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Rejoindre des Groupes</span>
                </div>
                <div class="flex items-center">
                    <a href="dashboard.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700 mr-2">Retour au tableau de bord</a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Déconnexion</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if (isset($_GET['message']) && $_GET['message'] == 'request_sent'): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                Votre demande d'inscription au groupe a été envoyée avec succès. Veuillez attendre l'approbation de l'enseignant.
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Groupes Disponibles</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($groups as $group): ?>
                        <div class="border rounded-lg p-4">
                            <h3 class="font-bold text-lg mb-2"><?php echo htmlspecialchars($group['name']); ?></h3>
                            <p class="text-gray-600 mb-4">Enseignant(s): <?php echo htmlspecialchars($group['teacher_names']); ?></p>
                            
                            <?php if (!$group['is_enrolled']): ?>
                                <form method="POST" class="mt-4">
                                    <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                    <button type="submit" name="join_group" 
                                            class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                                        Rejoindre le groupe
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="mt-4 text-center py-2 rounded-md 
                                    <?php echo $group['status'] == 'approved' ? 'bg-green-100 text-green-800' : 
                                        ($group['status'] == 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                    <?php 
                                    echo $group['status'] == 'approved' ? 'Inscrit' : 
                                        ($group['status'] == 'pending' ? 'En attente d\'approbation' : 'Demande rejetée');
                                    ?>
                                </div>
                                <?php if ($group['status'] == 'rejected'): ?>
                                    <form method="POST" class="mt-2">
                                        <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                        <button type="submit" name="join_group" 
                                                class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                                            Réessayer
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
