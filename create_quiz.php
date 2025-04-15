<?php
session_start();
require_once 'config.php';
require_once 'includes/quiz_functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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
    
    if (empty($questions)) {
        $message = 'Please add at least one question to the quiz.';
    } else {
        $quiz_id = createQuizWithQuestions($title, $description, $_SESSION['user_id'], $duration, $questions, $options, $correct_answers);
        if ($quiz_id) {
            // Redirect based on user type
            if ($_SESSION['user_type'] === 'admin') {
                header('Location: admin/quizzes.php?message=Quiz created successfully');
            } else {
                header('Location: quizzes.php?message=Quiz created successfully');
            }
            exit();
        } else {
            $message = 'Error creating quiz. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Quiz - ExamEnLigne</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .question-card {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .option-row {
            margin-bottom: 0.75rem;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="form-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Create New Quiz</h2>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back
                </a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-danger">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="quizForm">
                <!-- Quiz Details Section -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Quiz Details</h5>
                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="duration" class="form-label">Duration (minutes)</label>
                            <input type="number" class="form-control" id="duration" name="duration" min="1" required>
                        </div>
                    </div>
                </div>

                <!-- Questions Section -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Questions</h5>
                            <button type="button" class="btn btn-primary btn-sm" onclick="addQuestion()">
                                <i class="fas fa-plus me-2"></i>Add Question
                            </button>
                        </div>
                        <div id="questionsContainer">
                            <!-- Questions will be added here dynamically -->
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create Quiz</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Question Template -->
    <template id="questionTemplate">
        <div class="question-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="flex-grow-1 me-3">
                    <label class="form-label">Question Text</label>
                    <input type="text" class="form-control" name="questions[]" required>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeQuestion(this)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="options-container">
                <label class="form-label d-block">Options (Select the correct answer)</label>
                <div class="option-row">
                    <div class="input-group">
                        <div class="input-group-text">
                            <input class="form-check-input mt-0" type="radio" name="correct_answers[0]" value="0" required>
                        </div>
                        <input type="text" class="form-control" name="options[0][]" required placeholder="Option 1">
                    </div>
                </div>
                <div class="option-row">
                    <div class="input-group">
                        <div class="input-group-text">
                            <input class="form-check-input mt-0" type="radio" name="correct_answers[0]" value="1" required>
                        </div>
                        <input type="text" class="form-control" name="options[0][]" required placeholder="Option 2">
                    </div>
                </div>
                <div class="option-row">
                    <div class="input-group">
                        <div class="input-group-text">
                            <input class="form-check-input mt-0" type="radio" name="correct_answers[0]" value="2" required>
                        </div>
                        <input type="text" class="form-control" name="options[0][]" required placeholder="Option 3">
                    </div>
                </div>
                <div class="option-row">
                    <div class="input-group">
                        <div class="input-group-text">
                            <input class="form-check-input mt-0" type="radio" name="correct_answers[0]" value="3" required>
                        </div>
                        <input type="text" class="form-control" name="options[0][]" required placeholder="Option 4">
                    </div>
                </div>
            </div>
        </div>
    </template>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let questionCount = 0;

        function addQuestion() {
            const template = document.getElementById('questionTemplate');
            const container = document.getElementById('questionsContainer');
            const clone = template.content.cloneNode(true);
            
            // Update name attributes for the new question
            const inputs = clone.querySelectorAll('input[type="radio"]');
            inputs.forEach(input => {
                input.name = `correct_answers[${questionCount}]`;
            });
            
            const options = clone.querySelectorAll('input[name^="options"]');
            options.forEach(option => {
                option.name = `options[${questionCount}][]`;
            });
            
            container.appendChild(clone);
            questionCount++;
        }

        function removeQuestion(button) {
            button.closest('.question-card').remove();
        }

        // Add first question by default
        document.addEventListener('DOMContentLoaded', function() {
            addQuestion();
        });
    </script>
</body>
</html>
