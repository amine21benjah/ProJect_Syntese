<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Create group_teachers table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS group_teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        teacher_id INT NOT NULL,
        FOREIGN KEY (group_id) REFERENCES study_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

// Create notifications table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) NOT NULL,
        created_at DATETIME NOT NULL,
        read_at DATETIME DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_type (user_id, type)
    )
");

// Get pending teachers
$stmt = $pdo->query("SELECT * FROM users WHERE user_type = 'teacher' AND status = 'pending'");
$pending_teachers = $stmt->fetchAll();

// Get all teachers
$stmt = $pdo->query("SELECT * FROM users WHERE user_type = 'teacher'");
$all_teachers = $stmt->fetchAll();

// Get all groups with their teachers
$stmt = $pdo->query("
    SELECT g.*, GROUP_CONCAT(t.name SEPARATOR ', ') as teacher_names, GROUP_CONCAT(t.id SEPARATOR ',') as teacher_ids
    FROM study_groups g
    LEFT JOIN group_teachers gt ON g.id = gt.group_id
    LEFT JOIN users t ON gt.teacher_id = t.id
    GROUP BY g.id
");
$groups = $stmt->fetchAll();

// Get pending students for each group
$pending_students = [];
$stmt = $pdo->query("
    SELECT u.*, sg.status as group_status, g.name as group_name, sg.group_id 
    FROM users u 
    JOIN group_students sg ON u.id = sg.student_id 
    JOIN study_groups g ON sg.group_id = g.id 
    WHERE (u.status = 'pending' OR sg.status = 'pending') AND u.user_type = 'student'
");
while ($row = $stmt->fetch()) {
    $pending_students[$row['group_id']][] = $row;
}

// Get all students
$stmt = $pdo->query("
    SELECT u.*, sg.group_id, g.name as group_name 
    FROM users u 
    LEFT JOIN group_students sg ON u.id = sg.student_id 
    LEFT JOIN study_groups g ON sg.group_id = g.id 
    WHERE u.user_type = 'student'
");
$all_students = $stmt->fetchAll();

// Handle delete teacher
if (isset($_POST['delete_teacher'])) {
    $teacher_id = $_POST['teacher_id'];

    try {
        $pdo->beginTransaction();

        // 1. Delete student answers for all exams of this teacher
        $stmt = $pdo->prepare("
            DELETE sa FROM student_answers sa
            JOIN questions q ON sa.question_id = q.id
            JOIN exams e ON q.exam_id = e.id
            JOIN modules m ON e.module_id = m.id
            WHERE m.teacher_id = ?
        ");
        $stmt->execute([$teacher_id]);

        // 2. Delete question choices
        $stmt = $pdo->prepare("
            DELETE qc FROM question_choices qc
            JOIN questions q ON qc.question_id = q.id
            JOIN exams e ON q.exam_id = e.id
            JOIN modules m ON e.module_id = m.id
            WHERE m.teacher_id = ?
        ");
        $stmt->execute([$teacher_id]);

        // 3. Delete questions
        $stmt = $pdo->prepare("
            DELETE q FROM questions q
            JOIN exams e ON q.exam_id = e.id
            JOIN modules m ON e.module_id = m.id
            WHERE m.teacher_id = ?
        ");
        $stmt->execute([$teacher_id]);

        // 4. Delete exams
        $stmt = $pdo->prepare("
            DELETE e FROM exams e
            JOIN modules m ON e.module_id = m.id
            WHERE m.teacher_id = ?
        ");
        $stmt->execute([$teacher_id]);

        // 5. Delete group_modules associations
        $stmt = $pdo->prepare("
            DELETE gm FROM group_modules gm
            JOIN modules m ON gm.module_id = m.id
            WHERE m.teacher_id = ?
        ");
        $stmt->execute([$teacher_id]);

        // 6. Delete modules
        $stmt = $pdo->prepare("DELETE FROM modules WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);

        // 7. Delete group_teachers associations
        $stmt = $pdo->prepare("DELETE FROM group_teachers WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);

        // 8. Finally delete the teacher
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$teacher_id]);

        $pdo->commit();
        header("Location: dashboard.php?message=teacher_deleted&section=teachers");
        exit();
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Erreur lors de la suppression de l'enseignant : " . $e->getMessage();
    }
}

// Handle update teacher
if (isset($_POST['update_teacher'])) {
    $teacher_id = $_POST['teacher_id'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $status = $_POST['status'];
    $password = $_POST['password'];
    
    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, status = ?, password = ? WHERE id = ?");
        $stmt->execute([$name, $email, $status, $hashed_password, $teacher_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, status = ? WHERE id = ?");
        $stmt->execute([$name, $email, $status, $teacher_id]);
    }
    
    header("Location: dashboard.php?message=teacher_updated&section=teachers");
    exit();
}

// Handle create group
if (isset($_POST['create_group'])) {
    $group_name = $_POST['group_name'];
    $group_description = $_POST['group_description'];
    $teacher_ids = $_POST['teacher_ids'];

    if (count($teacher_ids) < 1) {
        $error = "Chaque groupe doit avoir au moins un enseignant.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO study_groups (name, description) VALUES (?, ?)");
        $stmt->execute([$group_name, $group_description]);
        $group_id = $pdo->lastInsertId();

        foreach ($teacher_ids as $teacher_id) {
            $stmt = $pdo->prepare("INSERT INTO group_teachers (group_id, teacher_id) VALUES (?, ?)");
            $stmt->execute([$group_id, $teacher_id]);
        }

        header("Location: dashboard.php?message=group_created&section=groups");
        exit();
    }
}

// Handle update group
if (isset($_POST['update_group'])) {
    $group_id = $_POST['group_id'];
    $group_name = $_POST['group_name'];
    $group_description = $_POST['group_description'];
    $teacher_ids = $_POST['teacher_ids'];

    if (count($teacher_ids) < 1) {
        $error = "Chaque groupe doit avoir au moins un enseignant.";
    } else {
        $stmt = $pdo->prepare("UPDATE study_groups SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$group_name, $group_description, $group_id]);

        $stmt = $pdo->prepare("DELETE FROM group_teachers WHERE group_id = ?");
        $stmt->execute([$group_id]);

        foreach ($teacher_ids as $teacher_id) {
            $stmt = $pdo->prepare("INSERT INTO group_teachers (group_id, teacher_id) VALUES (?, ?)");
            $stmt->execute([$group_id, $teacher_id]);
        }

        header("Location: dashboard.php?message=group_updated&section=groups");
        exit();
    }
}

// Handle delete group
if (isset($_POST['delete_group'])) {
    $group_id = $_POST['group_id'];

    try {
        $pdo->beginTransaction();

        // 1. Delete student answers for exams in this group
        $stmt = $pdo->prepare("
            DELETE sa FROM student_answers sa
            JOIN questions q ON sa.question_id = q.id
            JOIN exams e ON q.exam_id = e.id
            WHERE e.group_id = ?
        ");
        $stmt->execute([$group_id]);

        // 2. Delete question choices
        $stmt = $pdo->prepare("
            DELETE qc FROM question_choices qc
            JOIN questions q ON qc.question_id = q.id
            JOIN exams e ON q.exam_id = e.id
            WHERE e.group_id = ?
        ");
        $stmt->execute([$group_id]);

        // 3. Delete questions
        $stmt = $pdo->prepare("
            DELETE q FROM questions q
            JOIN exams e ON q.exam_id = e.id
            WHERE e.group_id = ?
        ");
        $stmt->execute([$group_id]);

        // 4. Delete exams
        $stmt = $pdo->prepare("DELETE FROM exams WHERE group_id = ?");
        $stmt->execute([$group_id]);

        // 5. Delete group_students associations
        $stmt = $pdo->prepare("DELETE FROM group_students WHERE group_id = ?");
        $stmt->execute([$group_id]);

        // 6. Delete group_modules associations
        $stmt = $pdo->prepare("DELETE FROM group_modules WHERE group_id = ?");
        $stmt->execute([$group_id]);

        // 7. Delete group_teachers associations
        $stmt = $pdo->prepare("DELETE FROM group_teachers WHERE group_id = ?");
        $stmt->execute([$group_id]);

        // 8. Finally delete the group
        $stmt = $pdo->prepare("DELETE FROM study_groups WHERE id = ?");
        $stmt->execute([$group_id]);

        $pdo->commit();
        header("Location: dashboard.php?message=group_deleted&section=groups");
        exit();
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Erreur lors de la suppression du groupe : " . $e->getMessage();
    }
}

// Handle student approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve_student'])) {
        $student_id = $_POST['student_id'];
        $group_id = $_POST['group_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Update student_groups status
            $stmt = $pdo->prepare("
                UPDATE group_students 
                SET status = 'approved' 
                WHERE student_id = ? AND group_id = ?
            ");
            $stmt->execute([$student_id, $group_id]);
            
            // Update user status to approved
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'approved' 
                WHERE id = ?
            ");
            $stmt->execute([$student_id]);
            
            $pdo->commit();
            header("Location: dashboard.php?message=student_approved&section=students");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur lors de l'approbation : " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_student'])) {
        $student_id = $_POST['student_id'];
        $group_id = $_POST['group_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Update student_groups status
            $stmt = $pdo->prepare("
                UPDATE group_students 
                SET status = 'rejected' 
                WHERE student_id = ? AND group_id = ?
            ");
            $stmt->execute([$student_id, $group_id]);
            
            // Update user status back to pending_group
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'pending_group' 
                WHERE id = ?
            ");
            $stmt->execute([$student_id]);
            
            $pdo->commit();
            header("Location: dashboard.php?message=student_rejected&section=students");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur lors du rejet : " . $e->getMessage();
        }
    }

    // Handle student switch between groups
    if (isset($_POST['switch_student'])) {
        $student_id = $_POST['student_id'];
        $new_group_id = $_POST['new_group_id'];

        try {
            $pdo->beginTransaction();
            
            // Update student_groups to switch group
            $stmt = $pdo->prepare("
                UPDATE group_students 
                SET group_id = ? 
                WHERE student_id = ? AND group_id = ?
            ");
            $stmt->execute([$new_group_id, $student_id, $_POST['current_group_id']]);
            
            $pdo->commit();
            header("Location: dashboard.php?message=student_switched&section=students");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur lors du changement de groupe : " . $e->getMessage();
        }
    }

    // Handle delete student
    if (isset($_POST['delete_student'])) {
        $student_id = $_POST['student_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Delete related records in student_answers
            $stmt = $pdo->prepare("DELETE FROM student_answers WHERE student_id = ?");
            $stmt->execute([$student_id]);

            // Delete related records in group_students
            $stmt = $pdo->prepare("DELETE FROM group_students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            
            // Delete the student
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            
            $pdo->commit();
            header("Location: dashboard.php?message=student_deleted&section=students");
            exit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur lors de la suppression : " . $e->getMessage();
        }
    }

    // Handle update student
    if (isset($_POST['update_student'])) {
        $student_id = $_POST['student_id'];
        $name = $_POST['name'];
        $email = $_POST['email'];
        $status = $_POST['status'];

        // Update student information
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, status = ? WHERE id = ?");
        $stmt->execute([$name, $email, $status, $student_id]);

        // If status is changed to pending, update group_students status and create notification
        if ($status === 'pending') {
            // Update group_students status
            $stmt = $pdo->prepare("UPDATE group_students SET status = 'pending' WHERE student_id = ?");
            $stmt->execute([$student_id]);

            // Create notification
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, type, created_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE message = VALUES(message), created_at = NOW(), read_at = NULL
            ");
            $stmt->execute([
                $student_id, 
                'Votre statut a été mis à jour. Veuillez attendre l\'approbation.', 
                'status_update'
            ]);
        }

        header("Location: dashboard.php?message=" . urlencode("Informations de l'étudiant mises à jour avec succès"));
        exit();
    }
}

// Handle student registration
if (isset($_POST['register_student'])) {
    $cne = $_POST['cne'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $group_id = $_POST['group_id'];

    try {
        // Check if the student is already enrolled in another group
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE student_id = (SELECT id FROM users WHERE cne = ?)");
        $stmt->execute([$cne]);
        $is_enrolled = $stmt->fetchColumn();

        if ($is_enrolled > 0) {
            $error = "L'étudiant est déjà inscrit dans un autre groupe.";
        } else {
            $pdo->beginTransaction();

            // Insert student into users table
            $stmt = $pdo->prepare("INSERT INTO users (cne, name, email, password, user_type, status) VALUES (?, ?, ?, ?, 'student', 'pending')");
            $stmt->execute([$cne, $name, $email, $password]);
            $student_id = $pdo->lastInsertId();

            // Insert student into group_students table
            $stmt = $pdo->prepare("INSERT INTO group_students (student_id, group_id, status) VALUES (?, ?, 'pending')");
            $stmt->execute([$student_id, $group_id]);

            $pdo->commit();
            header("Location: dashboard.php?message=student_registered&section=students");
            exit();
        }
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Erreur lors de l'inscription de l'étudiant : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Admin Dashboard</span>
                </div>
                <div class="flex items-center">
                    <a href="quizzes.php" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 mr-4 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Créer un Quiz
                    </a>
                    <span class="text-gray-700 mr-4">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Déconnexion</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-4">
        <div class="flex space-x-4">
            <button data-section="home" onclick="showSection('home')" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                Accueil
            </button>
            <button data-section="teachers" onclick="showSection('teachers')" class="relative bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                Enseignants
                <?php if (count($pending_teachers) > 0): ?>
                    <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center">
                        <?php echo count($pending_teachers); ?>
                    </span>
                <?php endif; ?>
            </button>
            <button data-section="groups" onclick="showSection('groups')" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                Groupes
            </button>
            <button data-section="students" onclick="showSection('students')" class="relative bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                Étudiants
                <?php if (!empty($pending_students)): ?>
                    <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center">
                        <?php 
                        $total_pending = 0;
                        foreach ($pending_students as $group_students) {
                            $total_pending += count($group_students);
                        }
                        echo $total_pending;
                        ?>
                    </span>
                <?php endif; ?>
            </button>
        </div>
    </div>

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

        <!-- Home Section -->
        <div id="homeSection" class="hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                <!-- Statistiques des enseignants -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Enseignants</h3>
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-600">Total</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo count($all_teachers); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">En attente</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo count($pending_teachers); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Statistiques des groupes -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Groupes</h3>
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-600">Total</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo count($groups); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Actifs</p>
                            <p class="text-2xl font-bold text-green-600"><?php 
                                $active_groups = array_filter($groups, function($group) {
                                    return !empty($group['teacher_names']);
                                });
                                echo count($active_groups);
                            ?></p>
                        </div>
                    </div>
                </div>

                <!-- Statistiques des étudiants -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Étudiants</h3>
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-600">Total</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo count($all_students); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">En attente</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php 
                                $total_pending = 0;
                                foreach ($pending_students as $group_students) {
                                    $total_pending += count($group_students);
                                }
                                echo $total_pending;
                            ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Activité récente -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Activité récente</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php if (count($pending_teachers) > 0): ?>
                            <div class="flex items-center text-yellow-600">
                                <svg class="h-5 w-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                </svg>
                                <span><?php echo count($pending_teachers); ?> enseignant(s) en attente d'approbation</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($total_pending > 0): ?>
                            <div class="flex items-center text-yellow-600">
                                <svg class="h-5 w-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                </svg>
                                <span><?php echo $total_pending; ?> étudiant(s) en attente d'approbation</span>
                            </div>
                        <?php endif; ?>

                        <?php 
                        $groups_without_teachers = array_filter($groups, function($group) {
                            return empty($group['teacher_names']);
                        });
                        if (count($groups_without_teachers) > 0): 
                        ?>
                            <div class="flex items-center text-red-600">
                                <svg class="h-5 w-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <span><?php echo count($groups_without_teachers); ?> groupe(s) sans enseignant</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Teachers Section -->
        <div id="teachersSection" class="hidden">
            <!-- Pending Teachers Section -->
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-4 py-5 sm:p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Enseignants en attente d'approbation</h2>
                    <?php if (count($pending_teachers) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNE</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($pending_teachers as $teacher): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($teacher['cne']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($teacher['name']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($teacher['email']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <form action="process_teacher.php" method="POST" class="inline">
                                                    <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                                    <button type="submit" name="action" value="approve" class="bg-green-600 text-white px-3 py-1 rounded-md hover:bg-green-700 mr-2">Approuver</button>
                                                    <button type="submit" name="action" value="reject" class="bg-red-600 text-white px-3 py-1 rounded-md hover:bg-red-700">Rejeter</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500">Aucun enseignant en attente d'approbation.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- All Teachers Section -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Tous les enseignants</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNE</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($all_teachers as $teacher): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($teacher['cne']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($teacher['name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($teacher['email']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php echo $teacher['status'] == 'approved' ? 'bg-green-100 text-green-800' : 
                                                    ($teacher['status'] == 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                                <?php echo ucfirst($teacher['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <form method="POST" class="inline-block">
                                                <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                                <button type="submit" name="delete_teacher" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Supprimer</button>
                                            </form>
                                            <button onclick="openUpdateModal(<?php echo $teacher['id']; ?>, '<?php echo htmlspecialchars($teacher['name']); ?>', '<?php echo htmlspecialchars($teacher['email']); ?>', '<?php echo htmlspecialchars($teacher['status']); ?>')" class="bg-yellow-600 text-white px-4 py-2 rounded-md hover:bg-yellow-700">Modifier</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Groups Section -->
        <div id="groupsSection" class="hidden">
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-4 py-5 sm:p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Groupes d'étude</h2>
                    <button onclick="openCreateGroupModal()" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 mb-4">Créer un groupe</button>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enseignants</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($groups as $group): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($group['name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($group['description']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($group['teacher_names']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <form method="POST" class="inline-block">
                                                <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                <button type="submit" name="delete_group" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Supprimer</button>
                                            </form>
                                            <button onclick="openUpdateGroupModal(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['name']); ?>', '<?php echo htmlspecialchars($group['description']); ?>', '<?php echo htmlspecialchars($group['teacher_ids']); ?>')" class="bg-yellow-600 text-white px-4 py-2 rounded-md hover:bg-yellow-700">Modifier</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students Section -->
        <div id="studentsSection" class="hidden">
            <!-- Pending Students Section -->
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-4 py-5 sm:p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Étudiants en attente d'approbation</h2>
                    <?php if (!empty($pending_students)): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNE</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($pending_students as $group_id => $students): ?>
                                        <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['cne']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['name']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['group_name']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <form method="POST" class="inline-block">
                                                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                        <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
                                                        <button type="submit" name="approve_student" class="bg-green-600 text-white px-3 py-1 rounded-md hover:bg-green-700 mr-2">
                                                            Approuver
                                                        </button>
                                                        <button type="submit" name="reject_student" class="bg-red-600 text-white px-3 py-1 rounded-md hover:bg-red-700">
                                                            Rejeter
                                                        </button>
                                                    </form>
                                                    <button onclick="openSwitchGroupModal(<?php echo $student['id']; ?>, <?php echo $group_id; ?>)" class="bg-blue-600 text-white px-3 py-1 rounded-md hover:bg-blue-700">
                                                        Changer de groupe
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500">Aucun étudiant en attente d'approbation.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- All Students Section -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Tous les étudiants</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNE</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($all_students as $student): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['cne']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['group_name'] ?? 'No Group'); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php echo $student['status'] == 'approved' ? 'bg-green-100 text-green-800' : 
                                                    ($student['status'] == 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                                <?php echo ucfirst($student['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <form method="POST" class="inline-block">
                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                <button type="submit" name="delete_student" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Supprimer</button>
                                            </form>
                                            <button onclick="openUpdateStudentModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', '<?php echo htmlspecialchars($student['status']); ?>', <?php echo $student['group_id']; ?>)" class="bg-yellow-600 text-white px-4 py-2 rounded-md hover:bg-yellow-700">Modifier</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de mise à jour -->
        <div id="updateModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                    <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Modifier l'enseignant</h3>
                                <div class="mt-2">
                                    <form method="POST" id="updateForm">
                                        <input type="hidden" name="teacher_id" id="updateTeacherId">
                                        <div>
                                            <label for="name" class="block text-sm font-medium text-gray-700">Nom</label>
                                            <input type="text" name="name" id="updateName" required
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-2">
                                            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                            <input type="email" name="email" id="updateEmail" required
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-2">
                                            <label for="status" class="block text-sm font-medium text-gray-700">Statut</label>
                                            <select name="status" id="updateStatus" required
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="pending">En attente</option>
                                                <option value="approved">Approuvé</option>
                                                <option value="blocked">Bloqué</option>
                                            </select>
                                        </div>
                                        <div class="mt-2">
                                            <label for="password" class="block text-sm font-medium text-gray-700">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                                            <input type="password" name="password" id="updatePassword"
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-4">
                                            <button type="submit" name="update_teacher" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Mettre à jour</button>
                                            <button type="button" onclick="closeUpdateModal()" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de création de groupe -->
        <div id="createGroupModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                    <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Créer un groupe</h3>
                                <div class="mt-2">
                                    <form method="POST" id="createGroupForm">
                                        <div>
                                            <label for="group_name" class="block text-sm font-medium text-gray-700">Nom du groupe</label>
                                            <input type="text" name="group_name" id="createGroupName" required
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-2">
                                            <label for="group_description" class="block text-sm font-medium text-gray-700">Description</label>
                                            <textarea name="group_description" id="createGroupDescription" required rows="3"
                                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                        </div>
                                        <div class="mt-2">
                                            <label for="teacher_ids" class="block text-sm font-medium text-gray-700">Enseignants</label>
                                            <select name="teacher_ids[]" id="createTeacherIds" required multiple
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <?php foreach ($all_teachers as $teacher): ?>
                                                    <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mt-4">
                                            <button type="submit" name="create_group" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Créer</button>
                                            <button type="button" onclick="closeCreateGroupModal()" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de mise à jour de groupe -->
        <div id="updateGroupModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                    <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Modifier le groupe</h3>
                                <div class="mt-2">
                                    <form method="POST" id="updateGroupForm">
                                        <input type="hidden" name="group_id" id="updateGroupId">
                                        <div>
                                            <label for="group_name" class="block text-sm font-medium text-gray-700">Nom du groupe</label>
                                            <input type="text" name="group_name" id="updateGroupName" required
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-2">
                                            <label for="group_description" class="block text-sm font-medium text-gray-700">Description</label>
                                            <textarea name="group_description" id="updateGroupDescription" required rows="3"
                                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                        </div>
                                        <div class="mt-2">
                                            <label for="teacher_ids" class="block text-sm font-medium text-gray-700">Enseignants</label>
                                            <select name="teacher_ids[]" id="updateTeacherIds" required multiple
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <?php foreach ($all_teachers as $teacher): ?>
                                                    <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mt-4">
                                            <button type="submit" name="update_group" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Mettre à jour</button>
                                            <button type="button" onclick="closeUpdateGroupModal()" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de changement de groupe -->
        <div id="switchGroupModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                    <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Changer de groupe</h3>
                                <div class="mt-2">
                                    <form method="POST" id="switchGroupForm">
                                        <input type="hidden" name="student_id" id="switchStudentId">
                                        <input type="hidden" name="current_group_id" id="switchCurrentGroupId">
                                        <div>
                                            <label for="new_group_id" class="block text-sm font-medium text-gray-700">Nouveau groupe</label>
                                            <select name="new_group_id" id="switchNewGroupId" required
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <?php foreach ($groups as $group): ?>
                                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mt-4">
                                            <button type="submit" name="switch_student" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Changer</button>
                                            <button type="button" onclick="closeSwitchGroupModal()" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de mise à jour d'étudiant -->
        <div id="updateStudentModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                    <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Modifier l'étudiant</h3>
                                <div class="mt-2">
                                    <form method="POST" id="updateStudentForm">
                                        <input type="hidden" name="student_id" id="updateStudentId">
                                        <input type="hidden" name="current_group_id" id="updateCurrentGroupId">
                                        <div>
                                            <label for="name" class="block text-sm font-medium text-gray-700">Nom</label>
                                            <input type="text" name="name" id="updateStudentName" required
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-2">
                                            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                            <input type="email" name="email" id="updateStudentEmail" required
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-2">
                                            <label for="status" class="block text-sm font-medium text-gray-700">Statut</label>
                                            <select name="status" id="updateStudentStatus" required
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="pending">En attente</option>
                                                <option value="approved">Approuvé</option>
                                                <option value="blocked">Bloqué</option>
                                            </select>
                                        </div>
                                        <div class="mt-2">
                                            <label for="password" class="block text-sm font-medium text-gray-700">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                                            <input type="password" name="password" id="updateStudentPassword"
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="mt-2">
                                            <label for="new_group_id" class="block text-sm font-medium text-gray-700">Nouveau groupe</label>
                                            <select name="new_group_id" id="updateNewGroupId" required
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <?php foreach ($groups as $group): ?>
                                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mt-4">
                                            <button type="submit" name="update_student" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Mettre à jour</button>
                                            <button type="button" onclick="closeUpdateStudentModal()" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        function openUpdateModal(id, name, email, status) {
            document.getElementById('updateTeacherId').value = id;
            document.getElementById('updateName').value = name;
            document.getElementById('updateEmail').value = email;
            document.getElementById('updateStatus').value = status;
            document.getElementById('updateModal').classList.remove('hidden');
        }

        function closeUpdateModal() {
            document.getElementById('updateModal').classList.add('hidden');
        }

        function openCreateGroupModal() {
            document.getElementById('createGroupModal').classList.remove('hidden');
        }

        function closeCreateGroupModal() {
            document.getElementById('createGroupModal').classList.add('hidden');
        }

        function openUpdateGroupModal(id, name, description, teacher_ids) {
            document.getElementById('updateGroupId').value = id;
            document.getElementById('updateGroupName').value = name;
            document.getElementById('updateGroupDescription').value = description;
            
            // Fix for teacher_ids handling
            const teacherSelect = document.getElementById('updateTeacherIds');
            if (teacher_ids && teacher_ids !== 'null') {
                const selectedTeachers = teacher_ids.split(',').filter(id => id.trim() !== '');
                // Reset all options
                Array.from(teacherSelect.options).forEach(option => {
                    option.selected = selectedTeachers.includes(option.value);
                });
            } else {
                // Reset all options to unselected if no teachers
                Array.from(teacherSelect.options).forEach(option => {
                    option.selected = false;
                });
            }
            
            document.getElementById('updateGroupModal').classList.remove('hidden');
        }

        function closeUpdateGroupModal() {
            document.getElementById('updateGroupModal').classList.add('hidden');
        }

        function openSwitchGroupModal(student_id, current_group_id) {
            document.getElementById('switchStudentId').value = student_id;
            document.getElementById('switchCurrentGroupId').value = current_group_id;
            document.getElementById('switchGroupModal').classList.remove('hidden');
        }

        function closeSwitchGroupModal() {
            document.getElementById('switchGroupModal').classList.add('hidden');
        }

        function openUpdateStudentModal(id, name, email, status, current_group_id) {
            document.getElementById('updateStudentId').value = id;
            document.getElementById('updateStudentName').value = name;
            document.getElementById('updateStudentEmail').value = email;
            document.getElementById('updateStudentStatus').value = status;
            document.getElementById('updateCurrentGroupId').value = current_group_id;
            document.getElementById('updateStudentModal').classList.remove('hidden');
        }

        function closeUpdateStudentModal() {
            document.getElementById('updateStudentModal').classList.add('hidden');
        }

        // Show teachers section by default when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Get section from URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const section = urlParams.get('section') || 'home'; // default to home instead of teachers
            showSection(section);
        });

        function showSection(sectionName) {
            // Hide all sections
            document.getElementById('homeSection').classList.add('hidden');
            document.getElementById('teachersSection').classList.add('hidden');
            document.getElementById('groupsSection').classList.add('hidden');
            document.getElementById('studentsSection').classList.add('hidden');

            // Show selected section
            document.getElementById(sectionName + 'Section').classList.remove('hidden');

            // Update active button state (optional)
            const navButtons = document.querySelectorAll('[data-section]');
            navButtons.forEach(button => {
                if (button.dataset.section === sectionName) {
                    button.classList.add('bg-blue-800');
                } else {
                    button.classList.remove('bg-blue-800');
                }
            });
        }
    </script>
</body>
</html>