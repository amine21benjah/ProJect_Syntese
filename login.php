<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT user_type, status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user['user_type'] === 'student') {
        header("Location: student/dashboard.php");
        exit();
    } else {
        header("Location: " . $user['user_type'] . "/dashboard.php");
        exit();
    }
}

$error = '';

// Check for error message in URL
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cne = $_POST['cne'];
    $password = $_POST['password'];

    // Get user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE cne = ?");
    $stmt->execute([$cne]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Essayer de démarrer une nouvelle session
        if (!$sessionManager->startSession($user['id'])) {
            $error = "Ce compte est déjà connecté sur un autre appareil. Veuillez d'abord vous déconnecter de l'autre appareil.";
        } else {
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_id'] = $user['id'];

            // Check if there's a redirect URL stored in session
            if (isset($_SESSION['redirect_after_login'])) {
                $redirect_url = $_SESSION['redirect_after_login'];
                unset($_SESSION['redirect_after_login']); // Clear the stored URL
                header('Location: ' . $redirect_url);
                exit();
            }

            // Vérifier le statut de l'utilisateur
            if ($user['user_type'] === 'student') {
                header("Location: student/dashboard.php");
            } else {
                switch ($user['status']) {
                    case 'approved':
                        // Redirection vers le tableau de bord approprié
                        header("Location: " . $user['user_type'] . "/dashboard.php");
                        break;
                    
                    case 'pending':
                    case 'pending_group':
                        // Redirection vers la page d'attente
                        header("Location: pending_approval.php");
                        break;
                    
                    case 'blocked':
                        session_destroy();
                        $error = "Votre compte a été bloqué. Contactez l'administrateur.";
                        break;
                    
                    default:
                        session_destroy();
                        $error = "Statut de compte invalide.";
                        break;
                }
            }
            exit();
        }
    } else {
        $error = "CNE ou mot de passe incorrect";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Connexion à votre compte
                </h2>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form class="mt-8 space-y-6" method="POST">
                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <label for="cne" class="sr-only">CNE</label>
                        <input id="cne" name="cne" type="text" required
                               class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm"
                               placeholder="CNE">
                    </div>
                    <div>
                        <label for="password" class="sr-only">Mot de passe</label>
                        <input id="password" name="password" type="password" required
                               class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm"
                               placeholder="Mot de passe">
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <!-- Removed the "Mot de passe oublié ?" link -->
                </div>

                <div>
                    <button type="submit"
                            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Se connecter
                    </button>
                </div>
            </form>

            <div class="text-center">
                <p class="text-sm text-gray-600">
                    Pas encore de compte ? 
                    <a href="register.php" class="font-medium text-blue-600 hover:text-blue-500">
                        S'inscrire
                    </a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
