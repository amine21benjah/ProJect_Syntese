<?php
session_start();
require_once 'config.php';

// Check if quiz ID is provided
if (!isset($_GET['id'])) {
    header('Location: quizzes.php');
    exit();
}

$quiz_id = $_GET['id'];
$_SESSION['current_exam_id'] = $quiz_id; // Pour le suivi des violations

// Get quiz details
$stmt = $pdo->prepare("SELECT q.*, u.name as creator_name, u.user_type as creator_role,
                       (SELECT COUNT(*) FROM exam_violations v WHERE v.exam_id = q.id) as violation_count
                       FROM quizzes q 
                       LEFT JOIN users u ON q.creator_id = u.id 
                       WHERE q.id = ? AND q.is_active = TRUE");
$stmt->execute([$quiz_id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    header('Location: quizzes.php');
    exit();
}

// Get quiz questions
$stmt = $pdo->prepare("SELECT q.*, o.id as option_id, o.option_text, o.is_correct
                       FROM quiz_questions q
                       LEFT JOIN quiz_options o ON q.id = o.question_id
                       WHERE q.quiz_id = ?
                       ORDER BY q.id, o.id");
$stmt->execute([$quiz_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organiser les questions et leurs options
$questions = [];
foreach ($results as $row) {
    if (!isset($questions[$row['id']])) {
        $questions[$row['id']] = [
            'id' => $row['id'],
            'question_text' => $row['question_text'],
            'options' => []
        ];
    }
    if ($row['option_id']) {
        $questions[$row['id']]['options'][$row['option_id']] = [
            'id' => $row['option_id'],
            'text' => $row['option_text'],
            'is_correct' => $row['is_correct']
        ];
    }
}

// Randomize questions order
$questions_array = array_values($questions);
shuffle($questions_array);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Quiz - <?php echo htmlspecialchars($quiz['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .quiz-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .question-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .timer {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .warning-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: #dc3545;
            color: white;
            padding: 20px;
            border-radius: 8px;
            display: none;
            z-index: 2000;
        }
        .options-list {
            list-style: none;
            padding: 0;
        }
        .option-item {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            transition: all 0.2s;
        }
        .option-item:hover {
            background-color: #f8f9fa;
            cursor: pointer;
        }
        .option-item input[type="checkbox"] {
            margin-right: 10px;
        }
        #fullscreenWarning {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            color: white;
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            font-size: 1.5em;
        }
        .teacher-controls {
            position: fixed;
            top: 20px;
            left: 20px;
            background-color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .violations-count {
            display: inline-block;
            background-color: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8em;
            margin-left: 5px;
        }
    </style>
</head>
<body class="bg-light">
    <?php if ($quiz['creator_role'] === 'teacher'): ?>
    <div class="teacher-controls">
        <a href="teacher/dashboard/view_violations.php?quiz_id=<?php echo $quiz_id; ?>" class="btn btn-warning">
            <i class="fas fa-exclamation-triangle"></i> View Violations
            <?php if ($quiz['violation_count'] > 0): ?>
                <span class="violations-count"><?php echo $quiz['violation_count']; ?></span>
            <?php endif; ?>
        </a>
    </div>
    <?php endif; ?>

    <div id="fullscreenWarning">
        <div>
            <h2>⚠️ Warning ⚠️</h2>
            <p>Please enter fullscreen mode to continue the exam.</p>
            <button onclick="enterFullscreen()" class="btn btn-primary">Enter Fullscreen</button>
        </div>
    </div>

    <div class="warning-message" id="warningMessage">
        <h4>⚠️ Warning!</h4>
        <p id="warningText"></p>
    </div>

    <div class="timer">
        Time Remaining: <span id="timer"></span>
    </div>

    <div class="container quiz-container">
        <h1 class="mb-4"><?php echo htmlspecialchars($quiz['title']); ?></h1>
        <p class="text-muted mb-4"><?php echo htmlspecialchars($quiz['description']); ?></p>

        <div class="alert alert-warning">
            <h5>⚠️ Important Instructions:</h5>
            <ul>
                <li>You must stay in fullscreen mode during the entire exam.</li>
                <li>Do not attempt to switch tabs or windows.</li>
                <li>Do not try to copy or take screenshots.</li>
                <li>Suspicious behavior will be recorded and reported.</li>
            </ul>
        </div>

        <form id="quizForm" method="POST" action="submit_quiz.php">
            <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">

            <?php $questionNum = 1; foreach ($questions_array as $question): ?>
                <div class="question-card">
                    <h5 class="mb-3">Question <?php echo $questionNum++; ?></h5>
                    <p class="mb-4"><?php echo htmlspecialchars($question['question_text']); ?></p>

                    <?php 
                    // Randomize options order
                    $options = $question['options'];
                    $option_ids = array_keys($options);
                    shuffle($option_ids);
                    ?>
                    
                    <div class="options-list">
                        <?php foreach ($option_ids as $option_id): ?>
                            <div class="option-item">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" 
                                           name="answers[<?php echo $question['id']; ?>][]" 
                                           value="<?php echo $option_id; ?>" 
                                           id="option_<?php echo $option_id; ?>">
                                    <label class="form-check-label" for="option_<?php echo $option_id; ?>">
                                        <?php echo htmlspecialchars($options[$option_id]['text']); ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg">Submit Quiz</button>
            </div>
        </form>
    </div>

    <script src="js/exam_proctor.js"></script>
    <script>
        // Fonction pour afficher un avertissement
        function showWarning(message, duration = 3000) {
            const warningDiv = document.getElementById('warningMessage');
            document.getElementById('warningText').textContent = message;
            warningDiv.style.display = 'block';
            setTimeout(() => {
                warningDiv.style.display = 'none';
            }, duration);
        }

        // Fonction pour entrer en mode plein écran
        function enterFullscreen() {
            const elem = document.documentElement;
            if (elem.requestFullscreen) {
                elem.requestFullscreen();
            } else if (elem.mozRequestFullScreen) {
                elem.mozRequestFullScreen();
            } else if (elem.webkitRequestFullscreen) {
                elem.webkitRequestFullscreen();
            } else if (elem.msRequestFullscreen) {
                elem.msRequestFullscreen();
            }
        }

        // Vérifier le mode plein écran
        function checkFullscreen() {
            if (!document.fullscreenElement) {
                document.getElementById('fullscreenWarning').style.display = 'flex';
            } else {
                document.getElementById('fullscreenWarning').style.display = 'none';
            }
        }

        // Événements pour le mode plein écran
        document.addEventListener('fullscreenchange', checkFullscreen);
        document.addEventListener('webkitfullscreenchange', checkFullscreen);
        document.addEventListener('mozfullscreenchange', checkFullscreen);
        document.addEventListener('MSFullscreenChange', checkFullscreen);

        // Timer functionality
        const duration = <?php echo $quiz['duration']; ?> * 60; // Convert minutes to seconds
        let timeLeft = duration;

        function updateTimer() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            document.getElementById('timer').textContent = 
                `${minutes}:${seconds.toString().padStart(2, '0')}`;

            if (timeLeft <= 0) {
                document.getElementById('quizForm').submit();
            } else {
                timeLeft--;
                setTimeout(updateTimer, 1000);
            }
        }

        // Initialisation
        window.onload = function() {
            // Démarrer le timer
            updateTimer();
            
            // Forcer le mode plein écran au démarrage
            enterFullscreen();
            
            // Initialiser le système de surveillance
            window.examProctor = new ExamProctor();
            
            // Désactiver le clic droit
            document.addEventListener('contextmenu', e => e.preventDefault());
            
            // Désactiver les raccourcis clavier courants
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.altKey || e.metaKey) {
                    e.preventDefault();
                    showWarning('Keyboard shortcuts are disabled during the exam');
                }
            });
        };

        // Empêcher de quitter la page
        window.onbeforeunload = function() {
            return "Are you sure you want to leave? Your progress will be lost.";
        };
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
