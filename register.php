<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

// Get all groups
$stmt = $pdo->query("SELECT * FROM study_groups");
$groups = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $cne = strtoupper($_POST['cne']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $user_type = $_POST['user_type'];
    $group_id = $_POST['group_id'] ?? null;

    // Handle profile picture upload
    $profile_picture = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_picture']['name'];
        $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $filesize = $_FILES['profile_picture']['size'];

        // Validate file type
        if (!in_array($filetype, $allowed)) {
            $error = "Seuls les formats JPG, JPEG, PNG et GIF sont acceptés.";
        }
        // Validate file size (max 5MB)
        elseif ($filesize > 5242880) {
            $error = "La taille de l'image ne doit pas dépasser 5MB.";
        }
        else {
            // Generate unique filename
            $new_filename = uniqid() . '.' . $filetype;
            $upload_path = 'uploads/profile_pictures/' . $new_filename;
            
            // Create directory if it doesn't exist
            if (!file_exists('uploads/profile_pictures')) {
                mkdir('uploads/profile_pictures', 0777, true);
            }

            // Move uploaded file
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                $profile_picture = $upload_path;
            } else {
                $error = "Erreur lors du téléchargement de l'image.";
            }
        }
    }

    // Validate CNE format
    if ($user_type == 'student') {
        // Vérifie le format : 1 ou 2 lettres majuscules suivies de chiffres
        if (!preg_match('/^[A-Z]{1,2}\d+$/', $cne)) {
            $error = "Le CNE doit commencer par 1 ou 2 lettres majuscules suivies de chiffres";
        }
    }

    if (empty($error)) {
        if ($password !== $confirm_password) {
            $error = "Les mots de passe ne correspondent pas";
        } else {
            // Check if email or CNE already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR cne = ?");
            $stmt->execute([$email, $cne]);
            
            if ($stmt->fetch()) {
                $error = "Cet email ou CNE existe déjà";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Insert user into users table
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, cne, password, user_type, status, profile_picture) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    // Teachers need approval, students are pending group assignment
                    $status = $user_type == 'teacher' ? 'pending' : 'pending_group';
                    
                    $stmt->execute([
                        $name, 
                        $email, 
                        $cne, 
                        password_hash($password, PASSWORD_BCRYPT), 
                        $user_type, 
                        $status,
                        $profile_picture
                    ]);
                    $user_id = $pdo->lastInsertId();

                    // Insert student into group_students table if user is a student
                    if ($user_type == 'student' && $group_id) {
                        $stmt = $pdo->prepare("INSERT INTO group_students (student_id, group_id, status) VALUES (?, ?, 'pending')");
                        $stmt->execute([$user_id, $group_id]);
                    }

                    $pdo->commit();
                    $success = "Inscription réussie! ";
                    if ($user_type == 'teacher') {
                        $success .= "Attendez l'approbation de l'administrateur.";
                        header("refresh:3;url=login.php");
                    } else {
                        $success .= "Vous pouvez maintenant rejoindre un groupe.";
                        header("refresh:3;url=login.php");
                    }
                    exit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Erreur lors de l'inscription : " . $e->getMessage();
                }
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
    <title>Inscription - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Créer un compte
                </h2>
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

            <form class="mt-8 space-y-6" method="POST" enctype="multipart/form-data">
                <div class="space-y-4">
                    <div>
                        <label for="user_type" class="block text-sm font-medium text-gray-700">
                            Type d'utilisateur
                        </label>
                        <select name="user_type" id="user_type" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                onchange="updateCNEPattern()">
                            <option value="teacher">Enseignant</option>
                            <option value="student">Étudiant</option>
                        </select>
                    </div>

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">
                            Nom complet
                        </label>
                        <input type="text" name="name" id="name" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">
                            Email
                        </label>
                        <input type="email" name="email" id="email" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="cne" class="block text-sm font-medium text-gray-700">
                            CNE
                        </label>
                        <input type="text" name="cne" id="cne" required
                               pattern="^[A-Z]{1,2}\d+$"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                               placeholder="Exemple: C12345 ou CN12345">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">
                            Mot de passe
                        </label>
                        <input type="password" name="password" id="password" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">
                            Confirmer le mot de passe
                        </label>
                        <input type="password" name="confirm_password" id="confirm_password" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div id="groupSelection" style="display: none;">
                        <label for="group_id" class="block text-sm font-medium text-gray-700">Groupe</label>
                        <select name="group_id" id="group_id" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Sélectionnez un groupe</option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="profile_picture" class="block text-sm font-medium text-gray-700">
                            Photo de profil
                        </label>
                        <div class="mt-1 flex items-center space-x-4">
                            <div class="flex items-center justify-center w-32 h-32 border-2 border-gray-300 border-dashed rounded-full">
                                <div id="preview" class="w-full h-full rounded-full overflow-hidden">
                                    <img id="preview-image" src="#" alt="" class="w-full h-full object-cover hidden">
                                    <div id="upload-icon" class="h-full flex items-center justify-center">
                                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                  d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            <input type="file" 
                                   name="profile_picture" 
                                   id="profile_picture" 
                                   accept=".jpg,.jpeg,.png,.gif"
                                   class="hidden"
                                   onchange="previewImage(this)">
                            <button type="button" 
                                    onclick="document.getElementById('profile_picture').click()"
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                Choisir une photo
                            </button>
                            <p class="text-xs text-gray-500">
                                JPG, JPEG, PNG ou GIF (max. 5MB)
                            </p>
                        </div>
                    </div>
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        S'inscrire
                    </button>
                </div>

                <div class="text-center">
                    <a href="login.php" class="text-sm text-blue-600 hover:text-blue-500">
                        Déjà inscrit ? Se connecter
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateCNEPattern() {
            const cneInput = document.getElementById('cne');
            const userType = document.getElementById('user_type').value;
            const groupSelection = document.getElementById('groupSelection');
            const groupSelect = document.getElementById('group_id');
            
            if (userType === 'student') {
                cneInput.pattern = '^[A-Z]{1,2}\\d+$';
                cneInput.placeholder = 'Exemple: C12345 ou CN12345';
                groupSelection.style.display = 'block';
                groupSelect.required = true;
            } else {
                cneInput.pattern = '.*';
                cneInput.placeholder = '';
                groupSelection.style.display = 'none';
                groupSelect.required = false;
            }
        }

        // Set default user type to teacher
        document.getElementById('user_type').value = 'teacher';
        updateCNEPattern();

        function previewImage(input) {
            const preview = document.getElementById('preview-image');
            const uploadIcon = document.getElementById('upload-icon');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    uploadIcon.classList.add('hidden');
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>
