<?php
session_start();
require_once '../config.php';
require_once '../includes/quiz_functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $duration = $_POST['duration'];
    $questions = isset($_POST['questions']) ? $_POST['questions'] : [];
    $options = isset($_POST['options']) ? $_POST['options'] : [];
    $correct_answers = isset($_POST['correct_answers']) ? $_POST['correct_answers'] : [];

    if (createQuizWithQuestions($title, $description, $_SESSION['user_id'], $duration, $questions, $options, $correct_answers)) {
        header('Location: quizzes.php?message=Quiz created successfully');
        exit();
    } else {
        $message = 'Error creating quiz';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Quiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .question-container {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        .option-container {
            margin-left: 20px;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <h2>Create New Quiz</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" id="quizForm">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Quiz Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Duration (minutes)</label>
                        <input type="number" name="duration" class="form-control" required min="1">
                    </div>
                </div>
            </div>

            <div id="questionsContainer">
                <!-- Questions will be added here dynamically -->
            </div>

            <button type="button" class="btn btn-secondary mb-3" onclick="addQuestion()">
                <i class="fas fa-plus"></i> Add Question
            </button>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">Create Quiz</button>
                <a href="quizzes.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        let questionCount = 0;

        function addQuestion() {
            questionCount++;
            const questionHtml = `
                <div class="card mb-3 question-container" id="question${questionCount}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5>Question ${questionCount}</h5>
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeQuestion(${questionCount})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Question Text</label>
                            <textarea name="questions[]" class="form-control" required></textarea>
                        </div>
                        <div class="options-container" id="optionsContainer${questionCount}">
                            <!-- Options will be added here -->
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" 
                                onclick="addOption(${questionCount})">
                            <i class="fas fa-plus"></i> Add Option
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('questionsContainer').insertAdjacentHTML('beforeend', questionHtml);
            addOption(questionCount); // Add first option automatically
            addOption(questionCount); // Add second option automatically
        }

        function addOption(questionNum) {
            const optionContainer = document.getElementById(`optionsContainer${questionNum}`);
            const optionCount = optionContainer.getElementsByClassName('option-row').length + 1;
            
            const optionHtml = `
                <div class="row option-row mb-2">
                    <div class="col-md-8">
                        <input type="text" name="options[${questionNum}][]" 
                               class="form-control" placeholder="Option ${optionCount}" required>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" 
                                   name="correct_answers[${questionNum}]" 
                                   value="${optionCount - 1}" required>
                            <label class="form-check-label">Correct Answer</label>
                        </div>
                    </div>
                </div>
            `;
            optionContainer.insertAdjacentHTML('beforeend', optionHtml);
        }

        function removeQuestion(questionNum) {
            const question = document.getElementById(`question${questionNum}`);
            question.remove();
        }

        // Add first question automatically when page loads
        document.addEventListener('DOMContentLoaded', function() {
            addQuestion();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
