<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Check if exam_id and student_id are provided
if (!isset($_GET['exam_id']) || !isset($_GET['student_id'])) {
    header("Location: grade_exams.php");
    exit();
}

$exam_id = $_GET['exam_id'];
$student_id = $_GET['student_id'];
$teacher_id = $_SESSION['user_id'];

// Get exam details
$stmt = $pdo->prepare("
    SELECT e.*, sg.name as group_name, u.name as student_name
    FROM exams e
    JOIN study_groups sg ON e.group_id = sg.id
    JOIN users u ON u.id = ?
    WHERE e.id = ? AND e.teacher_id = ?
");
$stmt->execute([$student_id, $exam_id, $teacher_id]);
$exam = $stmt->fetch();

if (!$exam) {
    header("Location: grade_exams.php");
    exit();
}

// Function to calculate score for QCM questions
function calculate_qcm_score($question_id, $selected_choices, $pdo) {
    $stmt = $pdo->prepare("SELECT id, is_correct FROM question_choices WHERE question_id = ?");
    $stmt->execute([$question_id]);
    $choices = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Convert selected_choices string to array if it's a string
    if (is_string($selected_choices)) {
        $selected_choices = explode(',', $selected_choices);
    }

    // If no answers selected, return 0
    if (empty($selected_choices)) {
        return 0;
    }

    $correct_choices = array_keys(array_filter($choices));
    $incorrect_choices = array_keys(array_filter($choices, fn($v) => !$v));

    $selected_correct = array_intersect($selected_choices, $correct_choices);
    $selected_incorrect = array_intersect($selected_choices, $incorrect_choices);

    // If all correct answers are selected and no incorrect ones
    if (count($selected_correct) === count($correct_choices) && count($selected_incorrect) === 0) {
        return 1; // Full points
    }
    // If both correct and incorrect answers are selected
    elseif (count($selected_correct) > 0 && count($selected_incorrect) > 0) {
        return 0.5; // Half points
    }
    // If only incorrect answers or no answers selected
    else {
        return 0; // No points
    }
}

// Get all questions with their types and exam attempt status
$stmt = $pdo->prepare("
    SELECT 
        q.id as question_id,
        q.question_type,
        q.question_text,
        q.points as max_points,
        q.order_num,
        sa.id as answer_id,
        sa.answer_text,
        sa.selected_choices,
        sa.score,
        sa.points_earned,
        ea.status as attempt_status
    FROM questions q
    LEFT JOIN student_answers sa ON q.id = sa.question_id AND sa.student_id = ?
    LEFT JOIN exam_attempts ea ON ea.exam_id = ? AND ea.student_id = ?
    WHERE q.exam_id = ?
    ORDER BY q.order_num ASC
");
$stmt->execute([$student_id, $exam_id, $student_id, $exam_id]);
$questions = $stmt->fetchAll();

// Get attempt status from the first question (they all have the same status)
$attempt_status = !empty($questions) ? ($questions[0]['attempt_status'] ?? 'completed') : 'completed';

// Process QCM answers automatically
foreach ($questions as $question) {
    if ($question['question_type'] === 'QCM' && !empty($question['selected_choices'])) {
        $score = calculate_qcm_score($question['question_id'], $question['selected_choices'], $pdo);
        $points_earned = $score * $question['max_points'];
        
        // Update the score and points_earned for QCM question
        $stmt = $pdo->prepare("
            UPDATE student_answers 
            SET score = ?,
                points_earned = ?,
                graded_at = NOW()
            WHERE question_id = ? AND student_id = ?
        ");
        $stmt->execute([$score * 20, $points_earned, $question['question_id'], $student_id]);
    }
}

// Process grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scores'])) {
    try {
        $pdo->beginTransaction();

        foreach ($_POST['scores'] as $answer_id => $points) {
            // Get the question details
            $stmt = $pdo->prepare("
                SELECT q.points as max_points, q.question_type, sa.selected_choices
                FROM student_answers sa
                JOIN questions q ON sa.question_id = q.id
                WHERE sa.id = ?
            ");
            $stmt->execute([$answer_id]);
            $question = $stmt->fetch();
            
            if ($question['question_type'] === 'QCM') {
                // For QCM, calculate score based on selected choices
                $score = calculate_qcm_score($question['question_id'], $question['selected_choices'], $pdo);
                $points_earned = $score * $question['max_points'];
            } else {
                // For manual questions, use the points directly
                $points_earned = floatval($points);
                $score = ($points_earned / $question['max_points']) * 20;
            }
            
            // Update the score and points
            $stmt = $pdo->prepare("
                UPDATE student_answers 
                SET score = ?, 
                    points_earned = ?,
                    graded_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$score, $points_earned, $answer_id]);
        }

        $pdo->commit();
        
        // Redirect back with success message
        header("Location: exam_status.php?exam_id=" . $exam_id . "&message=success");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Une erreur s'est produite lors de l'enregistrement des notes.";
    }
}

// Ajouter une fonction pour vérifier si l'examen est terminé
function isExamFinished($exam, $attempt_status) {
    return $attempt_status === 'completed' || 
           $attempt_status === 'time_expired' || 
           strtotime($exam['start_datetime']) + ($exam['duration'] * 60) < time();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correction - <?php echo htmlspecialchars($exam['title']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-gray-800 p-4">
        <div class="container mx-auto flex justify-between items-center">
            <a href="exam_status.php?exam_id=<?php echo $exam_id; ?>" class="text-white hover:text-gray-300">
                <i class="fas fa-arrow-left mr-2"></i>Retour au statut de l'examen
            </a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white shadow rounded-lg p-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">
                <?php echo htmlspecialchars($exam['title']); ?>
            </h1>
            <div class="text-gray-600">
                <p>Étudiant: <?php echo htmlspecialchars($exam['student_name']); ?></p>
                <p>Groupe: <?php echo htmlspecialchars($exam['group_name']); ?></p>
            </div>
        </div>

        <!-- Questions Section -->
        <div class="bg-white shadow rounded-lg p-6 mt-6">
            <h2 class="text-xl font-bold mb-4">Questions</h2>
            <form method="POST" class="space-y-6">
            <?php 
            $total_points = 0;
            $total_possible = 0;
            $question_number = 1;
            foreach ($questions as $question): 
                $total_possible += $question['max_points'];
            ?>
                <div class="border rounded-lg p-4 mb-4">
                    <h3 class="text-lg font-semibold mb-2">
                        Question <?php echo $question_number; ?>: <span class="text-sm text-gray-500">(<?php echo $question['max_points']; ?> points)</span>
                    <br>
                        <?php echo htmlspecialchars($question['question_text']); ?>
                    </h3>

                    <?php if ($question['question_type'] === 'QCM'): 
                        // Get all choices for this question
                        $stmt = $pdo->prepare("SELECT id, choice_text, is_correct FROM question_choices WHERE question_id = ? ORDER BY id");
                        $stmt->execute([$question['question_id']]);
                        $choices = $stmt->fetchAll();

                        // Get student's selected choices
                        $selected_choices = explode(',', $question['selected_choices'] ?? '');

                        foreach ($choices as $choice):
                            $is_selected = in_array($choice['id'], $selected_choices);
                            $choice_class = '';
                            $icon = '';
                            
                            if ($is_selected && $choice['is_correct']) {
                                $choice_class = 'bg-green-100 text-green-800';
                                $icon = '✓';
                            } elseif ($is_selected && !$choice['is_correct']) {
                                $choice_class = 'bg-red-100 text-red-800';
                                $icon = '✗';
                            } elseif (!$is_selected && $choice['is_correct']) {
                                $choice_class = 'bg-yellow-100 text-yellow-800';
                                $icon = '!';
                            }
                    ?>
                            <div class="flex items-center space-x-2 my-2 p-2 rounded-md <?php echo $choice_class; ?>">
                                <span class="font-bold"><?php echo $icon; ?></span>
                                <span><?php echo htmlspecialchars($choice['choice_text']); ?></span>
                            </div>
                    <?php 
                        endforeach;
                        
                        $score = calculate_qcm_score($question['question_id'], $selected_choices, $pdo);
                        $points = $score * $question['max_points'];
                        $total_points += $points;
                    ?>
                        <div class="mt-2 font-medium text-gray-700">
                            Points: <?php echo number_format($points, 2); ?>/<?php echo number_format($question['max_points'], 2); ?>
                        </div>
                        <input type="hidden" name="scores[<?php echo $question['answer_id']; ?>]" value="<?php echo $points; ?>">
                    <?php else: ?>
                        <div class="mt-2">
                            <p class="font-medium">Réponse de l'étudiant:</p>
                            <div class="mt-1 p-3 bg-gray-50 rounded-md">
                                <?php echo nl2br(htmlspecialchars($question['answer_text'] ?? '')); ?>
                            </div>
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Note sur <?php echo $question['max_points']; ?>:</label>
                                <input type="number" name="scores[<?php echo $question['answer_id']; ?>]" 
                                       value="<?php echo isset($question['points_earned']) ? $question['points_earned'] : ''; ?>" 
                                       min="0" max="<?php echo $question['max_points']; ?>" step="0.25"
                                       class="mt-1 p-2 border rounded-md w-24"
                                       data-max="<?php echo $question['max_points']; ?>"
                                       onchange="updateTotal()">
                                <span class="ml-2">/ <?php echo $question['max_points']; ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php 
                $question_number++;
                endforeach; 
            ?>

            <!-- Total Score -->
            <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                <h3 class="text-lg font-bold text-blue-900">Score Total</h3>
                <p class="text-blue-800" id="totalScore">
                    <?php echo number_format($total_points, 2); ?> / <?php echo number_format($total_possible, 2); ?> points
                    (<?php echo number_format(($total_points / $total_possible) * 20, 2); ?>/20)
                </p>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                    Enregistrer les notes
                </button>
            </div>
            </form>
        </div>
    </div>

    <script>
    function updateTotal() {
        let total = 0;
        
        // Get QCM points from hidden inputs
        document.querySelectorAll('input[type="hidden"]').forEach(input => {
            if (input.value) {
                total += parseFloat(input.value);
            }
        });
        
        // Add manual question points
        document.querySelectorAll('input[type="number"]').forEach(input => {
            if (input.value) {
                total += parseFloat(input.value);
            }
        });
        
        const totalPossible = <?php echo $total_possible; ?>;
        const finalScore = (total / totalPossible) * 20;
        
        document.getElementById('totalScore').innerHTML = 
            total.toFixed(2) + ' / ' + totalPossible.toFixed(2) + ' points (' + finalScore.toFixed(2) + '/20)';
    }

    // Call updateTotal on page load to ensure initial total is correct
    document.addEventListener('DOMContentLoaded', updateTotal);
    </script>
</body>
</html>
