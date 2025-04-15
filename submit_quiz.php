<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['quiz_id'])) {
    header('Location: quizzes.php');
    exit();
}

$quiz_id = $_POST['quiz_id'];
$answers = isset($_POST['answers']) ? $_POST['answers'] : [];

// Récupérer les questions et les réponses correctes
$stmt = $pdo->prepare("
    SELECT 
        q.id as question_id,
        q.question_text,
        o.id as option_id,
        o.is_correct
    FROM quiz_questions q
    LEFT JOIN quiz_options o ON q.id = o.question_id
    WHERE q.quiz_id = ?
    ORDER BY q.id, o.id
");
$stmt->execute([$quiz_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organiser les questions et les réponses correctes
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
        $questions[$row['question_id']]['options'][$row['option_id']] = $row['is_correct'];
    }
}

// Fonction pour calculer le score d'une question
function calculate_question_score($selected_choices, $choices) {
    if (is_string($selected_choices)) {
        $selected_choices = explode(',', $selected_choices);
    }

    // Si aucune réponse sélectionnée, retourner 0
    if (empty($selected_choices)) {
        return 0;
    }

    $correct_choices = array_keys(array_filter($choices));
    $incorrect_choices = array_keys(array_filter($choices, fn($v) => !$v));

    $selected_correct = array_intersect($selected_choices, $correct_choices);
    $selected_incorrect = array_intersect($selected_choices, $incorrect_choices);

    // Si toutes les réponses correctes sont sélectionnées et aucune incorrecte
    if (count($selected_correct) === count($correct_choices) && count($selected_incorrect) === 0) {
        return 1; // Points complets
    }
    // Si des réponses correctes et incorrectes sont sélectionnées
    elseif (count($selected_correct) > 0 && count($selected_incorrect) > 0) {
        return 0.5; // Moitié des points
    }
    // Si seulement des réponses incorrectes ou aucune réponse
    else {
        return 0; // Aucun point
    }
}

// Calculer le score total
$total_score = 0;
$total_questions = count($questions);

foreach ($questions as $question_id => $question) {
    $user_answer = isset($answers[$question_id]) ? (array)$answers[$question_id] : [];
    $question_score = calculate_question_score($user_answer, $question['options']);
    $total_score += $question_score;
}

// Calculer le pourcentage final
$final_score = ($total_questions > 0) ? ($total_score / $total_questions) * 100 : 0;

// Stocker les résultats dans la session
$_SESSION['temp_quiz_results'] = [
    'quiz_id' => $quiz_id,
    'score' => $final_score,
    'answers' => $answers,
    'completed_at' => date('Y-m-d H:i:s')
];

// Rediriger vers la page des résultats
header("Location: quiz_results.php?quiz_id=" . $quiz_id);
exit();
?>
