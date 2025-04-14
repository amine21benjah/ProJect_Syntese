<?php
session_start();
require_once '../config.php';

// Vérifier si l'utilisateur est connecté et est un enseignant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../login.php');
    exit();
}

// Vérifier si l'ID du quiz est fourni
if (!isset($_GET['id'])) {
    header('Location: quizzes.php');
    exit();
}

$quiz_id = $_GET['id'];

// Récupérer les détails du quiz
$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmt->execute([$quiz_id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    header('Location: quizzes.php');
    exit();
}

// Traiter la suppression d'une question
if (isset($_POST['delete_question'])) {
    $question_id = $_POST['question_id'];
    
    // Supprimer d'abord les options
    $stmt = $pdo->prepare("DELETE FROM quiz_options WHERE question_id = ?");
    $stmt->execute([$question_id]);
    
    // Puis supprimer la question
    $stmt = $pdo->prepare("DELETE FROM quiz_questions WHERE id = ?");
    $stmt->execute([$question_id]);
    
    header("Location: edit_quiz.php?id=" . $quiz_id);
    exit();
}

// Traiter l'ajout d'une nouvelle question
if (isset($_POST['add_question'])) {
    $question_text = $_POST['question_text'];
    $options = $_POST['options'];
    $correct_options = isset($_POST['correct_options']) ? $_POST['correct_options'] : [];
    
    // Insérer la nouvelle question
    $stmt = $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question_text) VALUES (?, ?)");
    $stmt->execute([$quiz_id, $question_text]);
    $question_id = $pdo->lastInsertId();
    
    // Insérer les options
    foreach ($options as $index => $option_text) {
        if (trim($option_text) !== '') {
            $is_correct = in_array($index, $correct_options) ? 1 : 0;
            $stmt = $pdo->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
            $stmt->execute([$question_id, $option_text, $is_correct]);
        }
    }
    
    header("Location: edit_quiz.php?id=" . $quiz_id);
    exit();
}

// Récupérer toutes les questions et options du quiz
$stmt = $pdo->prepare("
    SELECT 
        q.id as question_id,
        q.question_text,
        o.id as option_id,
        o.option_text,
        o.is_correct
    FROM quiz_questions q
    LEFT JOIN quiz_options o ON q.id = o.question_id
    WHERE q.quiz_id = ?
    ORDER BY q.id, o.id
");
$stmt->execute([$quiz_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organiser les questions et les options
$questions = [];
foreach ($results as $row) {
    if (!isset($questions[$row['question_id']])) {
        $questions[$row['question_id']] = [
            'id' => $row['question_id'],
            'text' => $row['question_text'],
            'options' => []
        ];
    }
    if ($row['option_id']) {
        $questions[$row['question_id']]['options'][] = [
            'id' => $row['option_id'],
            'text' => $row['option_text'],
            'is_correct' => $row['is_correct']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Quiz - <?php echo htmlspecialchars($quiz['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .quiz-container {
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
        }
        .question-card {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .option-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .option-row input[type="text"] {
            flex-grow: 1;
            margin-right: 10px;
        }
        .delete-btn {
            color: #dc3545;
            cursor: pointer;
        }
        .delete-btn:hover {
            color: #bd2130;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container quiz-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Edit Quiz: <?php echo htmlspecialchars($quiz['title']); ?></h1>
            <a href="quizzes.php" class="btn btn-secondary">Back to Quizzes</a>
        </div>
        
        <!-- Liste des questions existantes -->
        <div class="mb-5">
            <h2 class="mb-4">Existing Questions</h2>
            <?php if (empty($questions)): ?>
                <div class="alert alert-info">No questions added yet.</div>
            <?php else: ?>
                <?php foreach ($questions as $question): ?>
                    <div class="question-card">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h5><?php echo htmlspecialchars($question['text']); ?></h5>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                <button type="submit" name="delete_question" class="btn btn-danger btn-sm" 
                                        onclick="return confirm('Are you sure you want to delete this question?')">
                                    Delete Question
                                </button>
                            </form>
                        </div>
                        
                        <div class="options-list">
                            <?php foreach ($question['options'] as $option): ?>
                                <div class="mb-2 <?php echo $option['is_correct'] ? 'text-success' : ''; ?>">
                                    • <?php echo htmlspecialchars($option['text']); ?>
                                    <?php if ($option['is_correct']): ?>
                                        <span class="badge bg-success">Correct</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Formulaire pour ajouter une nouvelle question -->
        <div class="question-card">
            <h2 class="mb-4">Add New Question</h2>
            <form method="POST" id="addQuestionForm">
                <div class="mb-3">
                    <label for="question_text" class="form-label">Question Text</label>
                    <textarea class="form-control" id="question_text" name="question_text" rows="3" required></textarea>
                </div>
                
                <div id="options-container">
                    <label class="form-label">Options</label>
                    <div class="option-row">
                        <input type="text" class="form-control" name="options[]" required>
                        <div class="form-check ms-2">
                            <input class="form-check-input" type="checkbox" name="correct_options[]" value="0">
                            <label class="form-check-label">Correct</label>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="btn btn-secondary mt-2" onclick="addOption()">Add Option</button>
                <button type="submit" name="add_question" class="btn btn-primary mt-2">Add Question</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let optionCount = 1;
        
        function addOption() {
            const container = document.getElementById('options-container');
            const newOption = document.createElement('div');
            newOption.className = 'option-row mt-2';
            newOption.innerHTML = `
                <input type="text" class="form-control" name="options[]" required>
                <div class="form-check ms-2">
                    <input class="form-check-input" type="checkbox" name="correct_options[]" value="${optionCount}">
                    <label class="form-check-label">Correct</label>
                </div>
                <button type="button" class="btn btn-danger btn-sm ms-2" onclick="this.parentElement.remove()">Remove</button>
            `;
            container.appendChild(newOption);
            optionCount++;
        }
        
        // Validation du formulaire
        document.getElementById('addQuestionForm').addEventListener('submit', function(e) {
            const checkboxes = this.querySelectorAll('input[type="checkbox"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one correct answer.');
            }
        });
    </script>
</body>
</html>
