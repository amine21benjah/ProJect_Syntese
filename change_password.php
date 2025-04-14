<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND password = ?");
        $stmt->execute([$_SESSION['user_id'], md5($current_password)]);
        
        if ($stmt->fetch()) {
            if ($new_password === $confirm_password) {
                // Update password
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($stmt->execute([md5($new_password), $_SESSION['user_id']])) {
                    $success = "Votre mot de passe a été modifié avec succès";
                } else {
                    $error = "Erreur lors de la modification du mot de passe";
                }
            } else {
                $error = "Les nouveaux mots de passe ne correspondent pas";
            }
        } else {
            $error = "Mot de passe actuel incorrect";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changer le mot de passe - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Changer le mot de passe</span>
                </div>
                <div class="flex items-center">
                    <a href="<?php echo $_SESSION['user_type']; ?>/dashboard.php" class="text-gray-700 hover:text-blue-600 mr-4">
                        Retour au tableau de bord
                    </a>
                    <a href="logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                        Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg max-w-md mx-auto">
            <div class="px-4 py-5 sm:p-6">
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

                <form method="POST" class="space-y-6">
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700">
                            Mot de passe actuel
                        </label>
                        <input type="password" name="current_password" id="current_password" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700">
                            Nouveau mot de passe
                        </label>
                        <input type="password" name="new_password" id="new_password" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">
                            Confirmer le nouveau mot de passe
                        </label>
                        <input type="password" name="confirm_password" id="confirm_password" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <button type="submit" name="change_password"
                                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Changer le mot de passe
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
