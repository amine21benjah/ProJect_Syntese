<?php
session_start();
require_once '../config.php';
require_once '../includes/quiz_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!isset($_GET['attempt_id'])) {
    header('Location: quizzes.php');
    exit();
}

$attempt_id = $_GET['attempt_id'];

// Get attempt details
$sql = "SELECT qa.*, q.title as quiz_title, q.description as quiz_description
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        WHERE qa.id = ? AND qa.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $attempt_id, $_SESSION['user_id']);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();

if (!$attempt) {
    header('Location: quizzes.php');
    exit();
}

// Get answers with questions
$sql = "SELECT qa.*, qq.question_text, qq.question_type, qq.points,
        qo.option_text as correct_option_text
        FROM quiz_answers qa
        JOIN quiz_questions qq ON qa.question_id = qq.id
        LEFT JOIN quiz_options qo ON qa.selected_option_id = qo.id
        WHERE qa.attempt_id = ?
        ORDER BY qq.order_num";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $attempt_id);
$stmt->execute();
$answers = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Results</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <h2>Quiz Results: <?php echo htmlspecialchars($attempt['quiz_title']); ?></h2>
        
        <div class="card mb-4">
            <div class="card-body">
                <h5>Summary</h5>
                <p><strong>Score:</strong> <?php echo $attempt['score']; ?></p>
                <p><strong>Start Time:</strong> <?php echo $attempt['start_time']; ?></p>
                <p><strong>End Time:</strong> <?php echo $attempt['end_time']; ?></p>
            </div>
        </div>

        <h3>Detailed Results</h3>
        <?php while ($answer = $answers->fetch_assoc()): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title"><?php echo htmlspecialchars($answer['question_text']); ?></h5>
                    
                    <p>
                        <strong>Your Answer:</strong>
                        <?php 
                        if ($answer['question_type'] === 'multiple_choice') {
                            echo htmlspecialchars($answer['correct_option_text']);
                        } else {
                            echo htmlspecialchars($answer['answer_text']);
                        }
                        ?>
                    </p>
                    
                    <p>
                        <strong>Points:</strong>
                        <?php echo $answer['points_earned']; ?> / <?php echo $answer['points']; ?>
                    </p>
                    
                    <?php if ($answer['is_correct']): ?>
                        <div class="alert alert-success">Correct!</div>
                    <?php else: ?>
                        <div class="alert alert-danger">Incorrect</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
        
        <a href="quizzes.php" class="btn btn-primary">Back to Quizzes</a>
    </div>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
