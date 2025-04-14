<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.html");
    exit();
}

$error = '';
$success = '';

// Get exam details
if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$exam_id = $_GET['id'];

// Verify that this exam belongs to the teacher
$stmt = $pdo->prepare("
    SELECT e.*, g.code as group_code
    FROM exams e
    JOIN groups g ON e.group_id = g.id
    WHERE e.id = ? AND e.teacher_id = ?
");
$stmt->execute([$exam_id, $_SESSION['user_id']]);
$exam = $stmt->fetch();

if (!$exam) {
    header("Location: dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_datetime'])) {
        $new_start_datetime = $_POST['start_datetime'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE exams 
                SET start_datetime = ?
                WHERE id = ? AND teacher_id = ?
            ");
            if ($stmt->execute([$new_start_datetime, $exam_id, $_SESSION['user_id']])) {
                $success = "Date de début mise à jour avec succès";
                $exam['start_datetime'] = $new_start_datetime;
            } else {
                $error = "Erreur lors de la mise à jour de la date";
            }
        } catch(Exception $e) {
            $error = "Erreur lors de la mise à jour: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier la date de l'examen - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex flex-col">
        <nav class="bg-white shadow-lg">
            <div class="max-w-7xl mx-auto px-4">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <span class="text-2xl font-bold text-blue-600">Modifier la date de l'examen</span>
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

        <div class="flex-grow container mx-auto px-4 py-8">
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

            <div class="bg-white shadow rounded-lg p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">
                    <?php echo htmlspecialchars($exam['title']); ?> - 
                    Groupe <?php echo htmlspecialchars($exam['group_code']); ?>
                </h2>

                <form method="POST" class="space-y-6">
                    <div>
                        <label for="start_datetime" class="block text-sm font-medium text-gray-700">
                            Nouvelle date et heure de début
                        </label>
                        <input type="text" name="start_datetime" id="start_datetime" required
                               value="<?php echo $exam['start_datetime']; ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div class="flex justify-end space-x-4">
                        <a href="dashboard.php" 
                           class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">
                            Annuler
                        </a>
                        <button type="submit" name="update_datetime"
                                class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                            Mettre à jour la date
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Initialize date/time picker
        flatpickr("#start_datetime", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            time_24hr: true,
            minDate: "today",
            locale: "fr",
            defaultDate: "<?php echo $exam['start_datetime']; ?>"
        });
    </script>
</body>
</html>
