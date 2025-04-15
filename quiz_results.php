<?php
session_start();
require_once 'config.php';

// Vérifier si l'ID du quiz est fourni
if (!isset($_GET['quiz_id'])) {
    header('Location: quizzes.php');
    exit();
}

$quiz_id = $_GET['quiz_id'];

// Obtenir les détails du quiz
$stmt = $pdo->prepare("SELECT q.*, u.name as creator_name
                       FROM quizzes q 
                       LEFT JOIN users u ON q.creator_id = u.id
                       WHERE q.id = ?");
$stmt->execute([$quiz_id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    header('Location: quizzes.php');
    exit();
}

// Obtenir les questions et les réponses correctes
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
            'text' => $row['question_text'],
            'options' => [],
            'correct_options' => []
        ];
    }
    if ($row['option_id']) {
        $questions[$row['question_id']]['options'][$row['option_id']] = $row['option_text'];
        if ($row['is_correct']) {
            $questions[$row['question_id']]['correct_options'][] = $row['option_id'];
        }
    }
}

// Récupérer les résultats temporaires de la session
$temp_results = isset($_SESSION['temp_quiz_results']) && $_SESSION['temp_quiz_results']['quiz_id'] == $quiz_id 
    ? $_SESSION['temp_quiz_results'] 
    : null;

$score = $temp_results ? $temp_results['score'] : 0;
$user_answers = $temp_results ? $temp_results['answers'] : [];
$completed_at = $temp_results ? $temp_results['completed_at'] : null;

// Effacer les résultats temporaires après les avoir récupérés
if ($temp_results) {
    unset($_SESSION['temp_quiz_results']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($quiz['title']); ?> - Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .results-container {
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
        }
        .score-card {
            background-color: white;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .score {
            font-size: 48px;
            font-weight: bold;
            color: #333;
        }
        .question-card {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .answer-correct {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin: 5px 0;
        }
        .answer-incorrect {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin: 5px 0;
        }
        .answer-missing {
            background-color: #fff3cd;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin: 5px 0;
        }
        .timestamp {
            color: #6c757d;
            font-size: 0.9em;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container results-container">
        <h1 class="mb-4"><?php echo htmlspecialchars($quiz['title']); ?> - Results</h1>
        
        <div class="score-card">
            <div class="score"><?php echo number_format($score, 1); ?>%</div>
            <?php if ($completed_at): ?>
                <p class="timestamp">Quiz completed on <?php echo date('F j, Y, g:i a', strtotime($completed_at)); ?></p>
            <?php endif; ?>
        </div>

        <h2 class="mb-4">Question Review</h2>
        
        <?php foreach ($questions as $question_id => $question): ?>
            <div class="question-card">
                <h5 class="mb-3">Question</h5>
                <p class="mb-4"><?php echo htmlspecialchars($question['text']); ?></p>
                
                <h6>Options:</h6>
                <?php foreach ($question['options'] as $option_id => $option_text): 
                    $is_correct = in_array($option_id, $question['correct_options']);
                    $was_selected = isset($user_answers[$question_id]) && 
                                  (is_array($user_answers[$question_id]) ? 
                                   in_array($option_id, $user_answers[$question_id]) : 
                                   $user_answers[$question_id] == $option_id);
                    
                    if ($was_selected && $is_correct) {
                        $class = 'answer-correct';
                        $icon = '✓';
                    } elseif ($was_selected && !$is_correct) {
                        $class = 'answer-incorrect';
                        $icon = '✗';
                    } elseif (!$was_selected && $is_correct) {
                        $class = 'answer-missing';
                        $icon = '!';
                    } else {
                        $class = '';
                        $icon = '';
                    }
                ?>
                    <div class="<?php echo $class; ?>">
                        <?php if ($icon): ?>
                            <strong><?php echo $icon; ?></strong>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($option_text); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        
        <div class="text-center mt-4">
            <a href="quizzes.php" class="btn btn-primary">Back to Quizzes</a>
        </div>
    </div>
</body>
</html>
