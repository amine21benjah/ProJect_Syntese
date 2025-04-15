<?php
session_start();
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}

// Récupérer les notifications non lues
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND read_at IS NULL 
    ORDER BY created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Marquer les notifications comme lues
if (!empty($notifications)) {
    $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$_SESSION['user_id']]);
}

// Récupérer le statut actuel de l'utilisateur
$stmt = $pdo->prepare("
    SELECT u.*, sg.status as group_status 
    FROM users u 
    LEFT JOIN group_students sg ON u.id = sg.student_id 
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Si l'utilisateur est déjà approuvé, le rediriger vers son tableau de bord
if ($user['status'] === 'approved' && (!isset($user['group_status']) || $user['group_status'] === 'approved')) {
    header("Location: " . $user['user_type'] . "/dashboard.php");
    exit();
}

// Message spécifique selon le type d'utilisateur
$message = '';
$submessage = '';
$show_join_groups = false;

if ($user['user_type'] === 'student') {
    if ($user['status'] === 'pending_group') {
        $message = "En attente de rejoindre un groupe";
        $submessage = "Votre compte a été créé avec succès. Veuillez rejoindre un groupe pour accéder à la plateforme.";
        $show_join_groups = true;
    }
} else if ($user['user_type'] === 'teacher') {
    $message = "En attente d'approbation";
    $submessage = "Votre compte est en attente d'approbation par l'administrateur. Vous recevrez un email une fois votre compte approuvé.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>En attente d'approbation - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Animation de chargement -->
    <style>
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .animate-spin {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8 text-center">
            <!-- Afficher les notifications -->
            <?php if (!empty($notifications)): ?>
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4">
                    <?php foreach ($notifications as $notification): ?>
                        <p class="mb-2"><?php echo htmlspecialchars($notification['message']); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Icône de chargement -->
            <div class="flex justify-center">
                <svg class="animate-spin h-16 w-16 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>

            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    <?php echo htmlspecialchars($message); ?>
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    <?php echo htmlspecialchars($submessage); ?>
                </p>
            </div>

            <?php if ($show_join_groups): ?>
                <div class="mt-8">
                    <a href="student/join_groups.php" 
                       class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Rejoindre un groupe
                    </a>
                </div>
            <?php endif; ?>

            <div class="mt-4">
                <p class="text-sm text-gray-500">
                    Connecté en tant que <?php echo htmlspecialchars($user['name']); ?>
                </p>
            </div>

            <div class="mt-6">
                <form action="logout.php" method="POST">
                    <button type="submit"
                            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Se déconnecter
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Auto-refresh toutes les 30 secondes -->
    <script>
        // Fonction pour recharger la page
        function checkStatus() {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    if (html.includes('dashboard.php')) {
                        window.location.href = '<?php echo $user['user_type']; ?>/dashboard.php';
                    } else {
                        document.documentElement.innerHTML = html;
                    }
                });
        }

        // Vérifier toutes les 30 secondes
        setInterval(checkStatus, 30000);
    </script>
</body>
</html>
