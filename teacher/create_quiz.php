<?php
session_start();
require_once '../config.php';
require_once '../includes/quiz_functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header('Location: ../login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug - Log POST data
    error_log('POST Data: ' . print_r($_POST, true));
    
    $title = $_POST['title'];
    $description = $_POST['description'];
    $duration = $_POST['duration'];
    $questions = isset($_POST['questions']) ? $_POST['questions'] : [];
    $options = isset($_POST['options']) ? $_POST['options'] : [];
    $correct_answers = isset($_POST['correct_answers']) ? $_POST['correct_answers'] : [];

    // Debug - Log processed data
    error_log('Questions: ' . print_r($questions, true));
    error_log('Options: ' . print_r($options, true));
    error_log('Correct Answers: ' . print_r($correct_answers, true));

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
        .option-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .option-input {
            flex-grow: 1;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <h2>Create Quiz</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-danger"><?php echo $message; ?></div>
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
                            <label class="form-label">Options</label>
                            <div class="option-list">
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="addOption(${questionCount})">
                            <i class="fas fa-plus"></i> Add Option
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('questionsContainer').insertAdjacentHTML('beforeend', questionHtml);
            // Add two options by default
            addOption(questionCount);
            addOption(questionCount);
        }

        function addOption(questionNum) {
            const optionsContainer = document.querySelector(`#optionsContainer${questionNum} .option-list`);
            const optionCount = optionsContainer.children.length;
            
            const optionHtml = `
                <div class="option-row">
                    <div class="input-group">
                        <div class="input-group-text">
                            <input type="checkbox" name="correct_answers[${questionNum-1}][]" value="${optionCount}" 
                                   class="form-check-input mt-0" aria-label="Correct answer">
                        </div>
                        <input type="text" name="options[${questionNum-1}][]" class="form-control" 
                               placeholder="Option text" required>
                        <button type="button" class="btn btn-outline-danger" onclick="removeOption(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            optionsContainer.insertAdjacentHTML('beforeend', optionHtml);
        }

        function removeOption(button) {
            const optionRow = button.closest('.option-row');
            const optionList = optionRow.parentElement;
            if (optionList.children.length > 2) {
                optionRow.remove();
            } else {
                alert('Each question must have at least 2 options');
            }
        }

        function removeQuestion(questionNum) {
            document.getElementById(`question${questionNum}`).remove();
        }

        // Add first question by default
        document.addEventListener('DOMContentLoaded', function() {
            addQuestion();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
