<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

// Get student details
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$student = $stmt->fetch();

// Get exam details
if (!isset($_GET['exam_id'])) {
    header("Location: dashboard.php");
    exit();
}

$exam_id = $_GET['exam_id'];

// Check if exam exists and student is allowed to take it
$stmt = $pdo->prepare("
    SELECT e.*, g.name as group_name, t.name as teacher_name,
           CASE 
               WHEN NOW() < e.start_datetime THEN 'not_started'
               WHEN NOW() BETWEEN e.start_datetime AND DATE_ADD(e.start_datetime, INTERVAL e.duration MINUTE) THEN 'in_progress'
               WHEN EXISTS (
                   SELECT 1 
                   FROM exam_attempts ea 
                   WHERE ea.exam_id = e.id 
                   AND ea.student_id = ? 
                   AND ea.status = 'in_progress'
               ) THEN 'can_continue'
               ELSE 'ended'
           END as exam_status
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    JOIN group_students sg ON g.id = sg.group_id
    JOIN users t ON e.teacher_id = t.id
    WHERE e.id = ? AND sg.student_id = ? AND sg.status = 'approved'
    AND e.deleted = 0
");

// First parameter is for EXISTS subquery, second for e.id, third for sg.student_id
$stmt->execute([$_SESSION['user_id'], $exam_id, $_SESSION['user_id']]);
$exam = $stmt->fetch();

// Check if exam has been completed
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM exam_attempts 
    WHERE exam_id = ? 
    AND student_id = ? 
    AND status = 'completed'
");
$stmt->execute([$exam_id, $_SESSION['user_id']]);
$completed = $stmt->fetch()['count'] > 0;

// For debugging
if (!$exam) {
    error_log("Exam not found. ID: $exam_id, Student ID: " . $_SESSION['user_id']);
    
    // Check if the exam exists at all
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM exams WHERE id = ? AND deleted = 0");
    $stmt->execute([$exam_id]);
    $exam_exists = $stmt->fetch()['count'] > 0;
    
    if ($exam_exists) {
        // Check if student is in the correct group
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM exams e
            JOIN study_groups g ON e.group_id = g.id
            JOIN group_students sg ON g.id = sg.group_id
            WHERE e.id = ? AND sg.student_id = ? AND sg.status = 'approved'
        ");
        $stmt->execute([$exam_id, $_SESSION['user_id']]);
        $in_group = $stmt->fetch()['count'] > 0;
        
        if (!$in_group) {
            header("Location: dashboard.php?message=not_in_group");
            exit();
        }
    }
    
    header("Location: dashboard.php?message=exam_not_found");
    exit();
}

// Check if exam is completed
if ($completed) {
    header("Location: dashboard.php?message=exam_already_completed");
    exit();
}

// Check exam status
if ($exam['exam_status'] === 'not_started') {
    header("Location: dashboard.php?message=exam_not_started");
    exit();
} elseif ($exam['exam_status'] === 'ended' && $exam['exam_status'] !== 'can_continue') {
    header("Location: dashboard.php?message=exam_ended");
    exit();
}

// Check exam attempts and status
$stmt = $pdo->prepare("
    SELECT * FROM exam_attempts 
    WHERE student_id = ? AND exam_id = ? 
    ORDER BY started_at DESC LIMIT 1
");
$stmt->execute([$_SESSION['user_id'], $exam_id]);
$attempt = $stmt->fetch();

// Only check for 'already taken' if:
// 1. There is a completed attempt AND
// 2. The exam is not in 'can_continue' status
if ($attempt && 
    $attempt['status'] === 'completed' && 
    $exam['exam_status'] !== 'can_continue') {
    header("Location: dashboard.php?message=exam_already_taken");
    exit();
}

// If exam can be continued or is in progress, update or create attempt
if ($exam['exam_status'] === 'can_continue' || $exam['exam_status'] === 'in_progress') {
    if (!$attempt || $attempt['status'] === 'completed') {
        // Create new attempt
        $stmt = $pdo->prepare("
            INSERT INTO exam_attempts (student_id, exam_id, status, started_at)
            VALUES (?, ?, 'in_progress', NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], $exam_id]);
    } else if ($attempt['status'] === 'time_expired') {
        // Update existing attempt
        $stmt = $pdo->prepare("
            UPDATE exam_attempts 
            SET status = 'in_progress', 
                started_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$attempt['id']]);
    }
}

// Get exam questions with saved answers
$stmt = $pdo->prepare("
    SELECT q.*, sa.answer_text, sa.selected_choices
    FROM questions q
    LEFT JOIN student_answers sa ON q.id = sa.question_id 
        AND sa.student_id = ? AND sa.exam_id = ?
    WHERE q.exam_id = ?
    ORDER BY q.order_num, q.id
");
$stmt->execute([$_SESSION['user_id'], $exam_id, $exam_id]);
$questions = $stmt->fetchAll();

// Get choices for multiple choice questions
$choices = [];
foreach ($questions as $question) {
    if ($question['question_type'] == 'QCM') {
        $stmt = $pdo->prepare("SELECT * FROM question_choices WHERE question_id = ?");
        $stmt->execute([$question['id']]);
        $choices[$question['id']] = $stmt->fetchAll();
    }
}

// Calculate remaining time
$end_time = strtotime($exam['start_datetime']) + ($exam['duration'] * 60);
$remaining_minutes = ceil(($end_time - time()) / 60);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    try {
        $pdo->beginTransaction();
        
        // Check if this is a single answer save
        if (isset($_POST['action']) && $_POST['action'] === 'save_answer') {
            $question_id = $_POST['question_id'];
            $answer_text = isset($_POST['answer_text']) ? trim($_POST['answer_text']) : null;
            $selected_choices = isset($_POST['selected_choices']) ? $_POST['selected_choices'] : null;
            
            // Delete existing answer
            $stmt = $pdo->prepare("
                DELETE FROM student_answers 
                WHERE student_id = ? AND exam_id = ? AND question_id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $exam_id, $question_id]);
            
            // Insert new answer
            $stmt = $pdo->prepare("
                INSERT INTO student_answers (
                    student_id, exam_id, question_id,
                    answer_text, selected_choices,
                    submitted_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $_SESSION['user_id'],
                $exam_id,
                $question_id,
                $answer_text,
                $selected_choices
            ]);
            
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }
        
        $is_time_expired = isset($_POST['time_expired']) && $_POST['time_expired'] === 'true';
        
        // Mettre à jour le statut de la tentative
        $stmt = $pdo->prepare("
            INSERT INTO exam_attempts (student_id, exam_id, status, started_at, completed_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE status = ?, completed_at = NOW()
        ");
        $status = $is_time_expired ? 'time_expired' : 'completed';
        $stmt->execute([
            $_SESSION['user_id'],
            $exam_id,
            $status,
            $status
        ]);

        // Sauvegarder toutes les réponses disponibles
        if (isset($_POST['answers']) && is_array($_POST['answers'])) {
        foreach ($_POST['answers'] as $question_id => $answer) {
                // Ne pas ignorer les réponses vides si le temps est expiré
                if (empty($answer) && !$is_time_expired) continue;
                
                // Supprimer les anciennes réponses pour cette question
                $stmt = $pdo->prepare("
                    DELETE FROM student_answers 
                    WHERE student_id = ? AND exam_id = ? AND question_id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $exam_id, $question_id]);
                
                // Insérer la nouvelle réponse
            $stmt = $pdo->prepare("
                INSERT INTO student_answers (
                    student_id, exam_id, question_id,
                    answer_text, selected_choices,
                    score, submitted_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
                
                if (is_array($answer)) {
                    $answer_text = null;
                    $selected_choices = implode(',', array_map('intval', $answer));
                } else {
                    $answer_text = trim($answer);
                    $selected_choices = null;
                }
                
            $stmt->execute([
                $_SESSION['user_id'],
                $exam_id,
                $question_id,
                $answer_text,
                $selected_choices,
                    0 // Le score sera calculé plus tard
            ]);
            }
        }

        $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'message' => $is_time_expired ? 
                    'Le temps est écoulé. L\'examen a été automatiquement soumis.' : 
                    'Examen soumis avec succès !'
            ]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle exam submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_exam') {
    try {
        // Update exam attempt status
        $stmt = $pdo->prepare("
            UPDATE exam_attempts 
            SET status = 'completed', 
                completed_at = NOW() 
            WHERE student_id = ? 
            AND exam_id = ? 
            AND status = 'in_progress'
        ");
        $stmt->execute([$_SESSION['user_id'], $exam_id]);
        
        header('Location: dashboard.php?message=exam_submitted');
        exit();
    } catch (PDOException $e) {
        $error = "Erreur lors de la soumission de l'examen.";
    }
}

// Handle AJAX save request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_answer') {
    header('Content-Type: application/json');
    
    $question_id = $_POST['question_id'];
    $answer_text = isset($_POST['answer_text']) ? trim($_POST['answer_text']) : null;
    $selected_choices = isset($_POST['selected_choices']) ? $_POST['selected_choices'] : null;
    
    try {
        // Delete existing answer
        $stmt = $pdo->prepare("
            DELETE FROM student_answers 
            WHERE student_id = ? AND exam_id = ? AND question_id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $exam_id, $question_id]);
        
        // Insert new answer
        $stmt = $pdo->prepare("
            INSERT INTO student_answers (
                student_id, exam_id, question_id,
                answer_text, selected_choices,
                submitted_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $_SESSION['user_id'],
            $exam_id,
            $question_id,
            $answer_text,
            $selected_choices
        ]);
        
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit;
    }
}

// Après les vérifications de session et avant d'afficher l'examen
try {
    // Créer la table si elle n'existe pas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            exam_id INT NOT NULL,
            status ENUM('in_progress','completed','time_expired') NOT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            UNIQUE KEY unique_attempt (student_id, exam_id),
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Enregistrer la tentative
    $stmt = $pdo->prepare("
        INSERT INTO exam_attempts (student_id, exam_id, status, started_at)
        VALUES (?, ?, 'in_progress', NOW())
        ON DUPLICATE KEY UPDATE status = 'in_progress'
    ");
    $stmt->execute([$_SESSION['user_id'], $exam_id]);
    
} catch (PDOException $e) {
    // Log l'erreur mais continuer l'exécution
    error_log('Erreur lors de l\'enregistrement de la tentative : ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passer l'examen - <?php echo htmlspecialchars($exam['title']); ?></title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .code-editor {
            height: 300px;
            width: 100%;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
    </style>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600"><?php echo htmlspecialchars($exam['title']); ?></span>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-lg font-semibold">
                        Questions: <span id="questionCount"><?php echo count($questions); ?></span>
                    </span>
                    <div class="bg-gray-100 px-4 py-2 rounded-md">
                        <span class="text-lg font-bold text-gray-800" id="timer">
                            Temps restant: <?php echo $remaining_minutes; ?>:00
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-lg p-6">
            <div class="mb-6">
                <h2 class="text-xl font-bold mb-2"><?php echo htmlspecialchars($exam['title']); ?></h2>
                <p class="text-gray-600"><?php echo htmlspecialchars($exam['description']); ?></p>
                <p class="text-gray-600">Groupe: <?php echo htmlspecialchars($exam['group_name']); ?></p>
                <p class="text-gray-600">Durée: <?php echo $exam['duration']; ?> minutes</p>
                <p class="text-gray-600">Étudiant: <?php echo htmlspecialchars($student['name']); ?></p>
                <p class="text-gray-600">Enseignant: <?php echo htmlspecialchars($exam['teacher_name']); ?></p>
            </div>

            <form id="examForm" method="POST" action="">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="mb-6 p-6 bg-white rounded-lg shadow">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">
                                Question <?php echo $index + 1; ?> sur <?php echo count($questions); ?>
                                <span class="ml-2 text-sm text-gray-500">(<?php echo $question['points']; ?> points)</span>
                            </h3>
                            <button type="button" 
                                    onclick="saveQuestionAnswer(<?php echo $question['id']; ?>, event)"
                                    class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600 transition-colors">
                                Sauvegarder la réponse
                            </button>
                        </div>
                        
                        <div class="mb-4">
                            <?php echo htmlspecialchars($question['question_text']); ?>
                        </div>
                        
                        <?php if ($question['image_path']): ?>
                            <div class="mb-4">
                                <img src="../<?php echo htmlspecialchars($question['image_path']); ?>" 
                                     alt="Question Image" 
                                     class="max-w-full h-auto">
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($question['question_type'] === 'QCM'): ?>
                            <div class="space-y-2">
                                <?php 
                                $selected_choices = !empty($question['selected_choices']) ? 
                                    explode(',', $question['selected_choices']) : [];
                                foreach ($choices[$question['id']] as $choice): 
                                ?>
                                    <div class="flex items-center">
                                        <input type="checkbox" 
                                               name="answers[<?php echo $question['id']; ?>][]" 
                                               value="<?php echo $choice['id']; ?>"
                                               <?php echo in_array($choice['id'], $selected_choices) ? 'checked' : ''; ?>
                                               class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                        <label class="ml-2 block text-sm text-gray-900">
                                            <?php echo htmlspecialchars($choice['choice_text']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="mt-4">
                                <?php if ($question['question_type'] === 'text'): ?>
                                    <div id="editor_<?php echo $question['id']; ?>" class="code-editor"><?php echo htmlspecialchars($question['answer_text'] ?? ''); ?></div>
                                    <input type="hidden" 
                                           name="answers[<?php echo $question['id']; ?>]" 
                                           id="answer_<?php echo $question['id']; ?>"
                                           value="<?php echo htmlspecialchars($question['answer_text'] ?? ''); ?>">
                                <?php else: ?>
                                    <textarea name="answers[<?php echo $question['id']; ?>]"
                                              class="w-full h-32 p-2 border rounded-md focus:ring-blue-500 focus:border-blue-500"
                                              placeholder="Écrivez votre réponse ici..."
                                              ><?php echo htmlspecialchars($question['answer_text'] ?? ''); ?></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <div class="sticky bottom-0 bg-white p-4 border-t shadow-lg">
                    <button type="submit" id="submitExamBtn" class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Soumettre l'examen
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Function to save question answer
        function saveQuestionAnswer(questionId, event) {
            event.preventDefault();
            
            let answerData;
            const questionDiv = $(`input[name="answers[${questionId}][]"]`).closest('.space-y-2');
            
            if (questionDiv.length > 0) {
                // This is a QCM question
                const checkedBoxes = questionDiv.find('input[type="checkbox"]:checked');
                answerData = {
                    question_id: questionId,
                    selected_choices: checkedBoxes.map(function() {
                        return $(this).val();
                    }).get().join(',')
                };
            } else {
                // This is a text/code question
                const textArea = $(`textarea[name="answers[${questionId}]"]`);
                if (textArea.length > 0) {
                    answerData = {
                        question_id: questionId,
                        answer_text: textArea.val()
                    };
                } else {
                    const editorId = `editor_${questionId}`;
                    const editor = ace.edit(editorId);
                    answerData = {
                        question_id: questionId,
                        answer_text: editor.getValue()
                    };
                }
            }
            
            // Save answer via AJAX
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'save_answer',
                    ...answerData
                },
                success: function(response) {
                    const saveButton = $(event.target);
                    const originalText = saveButton.text();
                    
                    if (response.success) {
                        saveButton.text('Sauvegardé!').addClass('bg-blue-500').removeClass('bg-green-500');
                        setTimeout(() => {
                            saveButton.text(originalText).addClass('bg-green-500').removeClass('bg-blue-500');
                        }, 1000);
                    }
                }
            });
        }

        $(document).ready(function() {
            // Initialize code editors
            $('.code-editor').each(function() {
                const editor = ace.edit(this.id);
                editor.setTheme("ace/theme/monokai");
                editor.session.setMode("ace/mode/python");
                editor.setOptions({
                    fontSize: "12pt"
                });
            });

            // Handle QCM checkboxes
            $('input[type="checkbox"]').on('change', function() {
                if (!this.checked) return; // Allow unchecking anytime

                const questionDiv = $(this).closest('.space-y-2');
                const totalChoices = questionDiv.find('input[type="checkbox"]').length;
                const maxAllowed = Math.ceil(totalChoices / 2); // Use ceil for odd numbers
                const checkedBoxes = questionDiv.find('input[type="checkbox"]:checked');

                if (checkedBoxes.length > maxAllowed) {
                    // Uncheck the first checked box when a new one is checked
                    checkedBoxes.first().prop('checked', false);
                }
            });

            // Handle exam submission
            $('#examForm').on('submit', function(e) {
                e.preventDefault();
                
                if (confirm('Êtes-vous sûr de vouloir soumettre l\'examen ? Cette action est irréversible.')) {
                    $.ajax({
                        url: window.location.href,
                        method: 'POST',
                        data: {
                            action: 'submit_exam'
                        },
                        success: function(response) {
                            window.location.href = 'dashboard.php?message=exam_submitted';
                        },
                        error: function() {
                            alert('Erreur lors de la soumission de l\'examen. Veuillez réessayer.');
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
