<?php
session_start();
require_once '../config.php';
require_once '../includes/quiz_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: quizzes.php');
    exit();
}

$quiz_id = $_GET['id'];
$quiz = getQuizById($quiz_id);

if (!$quiz) {
    header('Location: quizzes.php');
    exit();
}

$message = '';

// Check if user has an ongoing attempt
$sql = "SELECT id FROM quiz_attempts 
        WHERE quiz_id = ? AND user_id = ? AND status = 'in_progress'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $quiz_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$ongoing_attempt = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start_quiz'])) {
        $attempt_id = startQuizAttempt($quiz_id, $_SESSION['user_id']);
        if ($attempt_id) {
            $ongoing_attempt = ['id' => $attempt_id];
        } else {
            $message = 'Error starting quiz';
        }
    } elseif (isset($_POST['submit_quiz'])) {
        $attempt_id = $_POST['attempt_id'];
        
        foreach ($_POST['answers'] as $question_id => $answer) {
            if (isset($answer['option_id'])) {
                submitQuizAnswer($attempt_id, $question_id, null, $answer['option_id']);
            } elseif (isset($answer['text'])) {
                submitQuizAnswer($attempt_id, $question_id, $answer['text'], null);
            }
        }
        
        if (completeQuizAttempt($attempt_id)) {
            header('Location: quiz_results.php?attempt_id=' . $attempt_id);
            exit();
        } else {
            $message = 'Error submitting quiz';
        }
    }
}

// Get quiz questions if there's an ongoing attempt
if ($ongoing_attempt) {
    $sql = "SELECT q.*, GROUP_CONCAT(
                CONCAT(qo.id, ':', qo.option_text)
                SEPARATOR '|'
            ) as options
            FROM quiz_questions q
            LEFT JOIN quiz_options qo ON q.id = qo.question_id
            WHERE q.quiz_id = ?
            GROUP BY q.id
            ORDER BY q.order_num";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $questions = $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Quiz - <?php echo htmlspecialchars($quiz['title']); ?></title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <h2><?php echo htmlspecialchars($quiz['title']); ?></h2>
        <p><?php echo nl2br(htmlspecialchars($quiz['description'])); ?></p>
        <p>Duration: <?php echo $quiz['duration']; ?> minutes</p>
        
        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if (!$ongoing_attempt): ?>
            <form method="POST">
                <button type="submit" name="start_quiz" class="btn btn-primary">
                    Start Quiz
                </button>
            </form>
        <?php else: ?>
            <form method="POST" id="quizForm">
                <input type="hidden" name="attempt_id" value="<?php echo $ongoing_attempt['id']; ?>">
                
                <?php while ($question = $questions->fetch_assoc()): ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="card-title">
                                <?php echo htmlspecialchars($question['question_text']); ?>
                            </h5>
                            
                            <?php if ($question['question_type'] === 'multiple_choice'): ?>
                                <?php 
                                $options = explode('|', $question['options']);
                                foreach ($options as $option):
                                    list($option_id, $option_text) = explode(':', $option);
                                ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" 
                                               name="answers[<?php echo $question['id']; ?>][option_id]" 
                                               value="<?php echo $option_id; ?>" required>
                                        <label class="form-check-label">
                                            <?php echo htmlspecialchars($option_text); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($question['question_type'] === 'true_false'): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" 
                                           name="answers[<?php echo $question['id']; ?>][text]" 
                                           value="true" required>
                                    <label class="form-check-label">True</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" 
                                           name="answers[<?php echo $question['id']; ?>][text]" 
                                           value="false" required>
                                    <label class="form-check-label">False</label>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <textarea class="form-control" 
                                              name="answers[<?php echo $question['id']; ?>][text]" 
                                              required></textarea>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>

                <button type="submit" name="submit_quiz" class="btn btn-primary" 
                        onclick="return confirm('Are you sure you want to submit the quiz?')">
                    Submit Quiz
                </button>
            </form>

            <script>
                // Set timer
                let duration = <?php echo $quiz['duration']; ?> * 60; // Convert to seconds
                const timerDisplay = document.createElement('div');
                timerDisplay.className = 'alert alert-info mt-3';
                document.querySelector('form').insertBefore(timerDisplay, document.querySelector('form').firstChild);

                function updateTimer() {
                    const minutes = Math.floor(duration / 60);
                    const seconds = duration % 60;
                    timerDisplay.textContent = `Time remaining: ${minutes}:${seconds.toString().padStart(2, '0')}`;
                    
                    if (duration <= 0) {
                        document.getElementById('quizForm').submit();
                    } else {
                        duration--;
                        setTimeout(updateTimer, 1000);
                    }
                }

                updateTimer();
            </script>
        <?php endif; ?>
    </div>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
