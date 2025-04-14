<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['request_reset'])) {
        $identifier = $_POST['identifier'];
        $user_type = $_POST['user_type'];
        
        // Check if user exists
        if ($user_type == 'student') {
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE cne = ? AND user_type = 'student'");
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? AND user_type = 'teacher'");
        }
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $stmt = $pdo->prepare("
                INSERT INTO password_resets (user_id, token, expires_at, user_type) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$user['id'], $token, $expires, $user_type]);
            
            $_SESSION['reset_token'] = $token;
            header("Location: reset_password.php?token=" . $token);
            exit();
        } else {
            $error = $user_type == 'student' ? 
                    "Aucun étudiant trouvé avec ce CNE" : 
                    "Aucun enseignant trouvé avec cet email";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Mot de passe oublié
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    Entrez vos informations pour réinitialiser votre mot de passe
                </p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form class="mt-8 space-y-6" method="POST">
                <div>
                    <label for="user_type" class="block text-sm font-medium text-gray-700">
                        Je suis
                    </label>
                    <select name="user_type" id="user_type" required onchange="updateIdentifierLabel()"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="student">Étudiant</option>
                        <option value="teacher">Enseignant</option>
                    </select>
                </div>

                <div>
                    <label id="identifier_label" for="identifier" class="block text-sm font-medium text-gray-700">
                        CNE
                    </label>
                    <input type="text" name="identifier" id="identifier" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <button type="submit" name="request_reset"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Réinitialiser le mot de passe
                    </button>
                </div>

                <div class="text-center">
                    <a href="index.html" class="text-sm text-blue-600 hover:text-blue-500">
                        Retour à la connexion
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateIdentifierLabel() {
            const userType = document.getElementById('user_type').value;
            const label = document.getElementById('identifier_label');
            const input = document.getElementById('identifier');
            
            if (userType === 'student') {
                label.textContent = 'CNE';
                input.type = 'text';
                input.placeholder = 'Entrez votre CNE';
            } else {
                label.textContent = 'Email';
                input.type = 'email';
                input.placeholder = 'Entrez votre email';
            }
        }
    </script>
</body>
</html>
