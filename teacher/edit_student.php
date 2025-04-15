<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Check if student_id is provided
if (!isset($_GET['student_id'])) {
    header("Location: dashboard.php");
    exit();
}

$student_id = $_GET['student_id'];

// Get student details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND user_type = 'student'");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: dashboard.php?message=student_not_found");
    exit();
}

// Update student details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = $_POST['password'];

    try {
        $pdo->beginTransaction();

        // Update student details
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $stmt->execute([$name, $email, $student_id]);

        // Update password if provided
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $student_id]);
        }

        $pdo->commit();
        header("Location: dashboard.php?message=student_updated");
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Erreur lors de la mise à jour de l'étudiant : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier l'Étudiant</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .form-container {
            max-width: 600px;
            margin: 0 auto;
        }
    </style>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="text-gray-800 hover:text-gray-600">
                        ← Retour au tableau de bord
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg p-6 form-container">
            <h1 class="text-2xl font-bold text-gray-900 mb-4">Modifier l'Étudiant</h1>
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form method="post" class="space-y-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Nom</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($student['name']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($student['email']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                    <input type="password" id="password" name="password"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div class="flex justify-end">
                    <button type="submit" 
                            class="bg-blue-600 text-white px-6 py-3 rounded-md hover:bg-blue-700">
                        Mettre à jour l'étudiant
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>