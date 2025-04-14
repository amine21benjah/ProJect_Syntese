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
    header("Location: view_exams.php");
    exit();
}

$exam_id = $_GET['exam_id'];
$student_id = $_GET['student_id'];

// Get exam details
$stmt = $pdo->prepare("
    SELECT e.*, sg.name as group_name
    FROM exams e
    JOIN study_groups sg ON e.group_id = sg.id
    WHERE e.id = ? AND e.teacher_id = ?
");
$stmt->execute([$exam_id, $_SESSION['user_id']]);
$exam = $stmt->fetch();

if (!$exam) {
    header("Location: view_exams.php");
    exit();
}

// Get student details
$stmt = $pdo->prepare("
    SELECT u.*, 
    COALESCE(
        (
            SELECT SUM(points_earned)
            FROM student_answers sa
            JOIN questions q ON sa.question_id = q.id
            WHERE sa.student_id = u.id 
            AND sa.exam_id = ?
        ), 0
    ) as total_points
    FROM users u
    WHERE u.id = ?
");
$stmt->execute([$exam_id, $student_id]);
$student = $stmt->fetch();

// Get questions and student answers
$stmt = $pdo->prepare("
    SELECT 
        q.*,
        sa.selected_choices,
        sa.answer_text,
        sa.points_earned,
        GROUP_CONCAT(DISTINCT CONCAT(qc.id, ':', qc.choice_text) SEPARATOR '|||') as choices
    FROM questions q
    LEFT JOIN student_answers sa ON q.id = sa.question_id AND sa.student_id = ?
    LEFT JOIN question_choices qc ON q.id = qc.question_id
    WHERE q.exam_id = ?
    GROUP BY q.id, sa.id, sa.selected_choices, sa.answer_text
    ORDER BY q.order_num, q.id
");
$stmt->execute([$student_id, $exam_id]);
$questions = $stmt->fetchAll();

// Calculate total points possible
$total_possible = array_sum(array_column($questions, 'points'));
$final_score = ($student['total_points'] / $total_possible) * 20;

// Generate HTML content
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Relevé de Notes - <?php echo htmlspecialchars($student['name']); ?></title>
    <style>
        :root {
            --primary-color: #1a237e;
            --secondary-color: #0d47a1;
            --accent-color: #2962ff;
            --text-color: #333;
            --border-color: #e0e0e0;
        }
        
        body { 
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 40px;
            font-size: 14px;
            color: var(--text-color);
            line-height: 1.6;
            background-color: #f8f9fa;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 3px solid var(--primary-color);
            padding-bottom: 30px;
            background: linear-gradient(to bottom, #fff, #f8f9fa);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .logo {
            width: 180px;
            height: auto;
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .arabic-text {
            font-family: "Traditional Arabic", "Amiri", Arial, sans-serif;
            font-size: 28px;
            margin: 15px 0;
            color: var(--primary-color);
            text-shadow: 1px 1px 1px rgba(0,0,0,0.1);
        }

        .french-text {
            font-size: 13px;
            margin: 8px 0;
            color: var(--secondary-color);
            letter-spacing: 0.5px;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 25px 0;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        th, td {
            border: 1px solid var(--border-color);
            padding: 12px 15px;
            text-align: left;
            background-color: #fff;
        }

        th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
        }

        .student-info {
            background-color: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 25px 0;
        }

        .student-info div {
            margin: 12px 0;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .student-info strong {
            color: var(--primary-color);
            min-width: 150px;
            display: inline-block;
        }

        .question {
            margin: 25px 0;
            padding: 20px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid var(--accent-color);
        }

        .note {
            color: var(--accent-color);
            font-weight: 600;
            margin-top: 10px;
            padding: 8px;
            border-top: 1px solid var(--border-color);
        }

        h1 {
            color: var(--primary-color);
            text-align: center;
            font-size: 28px;
            margin: 30px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .answer-choices {
            margin: 15px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .question-points {
            font-size: 16px;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .choice {
            margin: 8px 0;
            padding: 8px 16px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
        }

        .choice.selected {
            background-color: #e8f5e9;
            border-color: #c8e6c9;
        }

        .choice-marker {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 20px;
            color: #495057;
        }

        .choice-text {
            flex: 1;
            display: flex;
            align-items: center;
        }

        .answer-status {
            margin-left: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            background-color: #e9ecef;
            color: #495057;
        }

        .legend {
            margin-top: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            font-size: 13px;
        }

        .legend-item {
            margin: 8px 0;
            padding: 4px 8px;
            display: flex;
            align-items: center;
            color: #495057;
        }

        .legend-item:last-child {
            border-bottom: none;
        }

        .correct-answer-mark {
            font-size: 16px;
            font-weight: bold;
            color: #2e7d32;
            margin-left: 8px;
        }

        .text-answer {
            margin: 15px 0;
            padding: 15px;
            background-color: rgba(41, 98, 255, 0.05);
            border-radius: 6px;
        }
        
        .answer-content {
            margin-top: 10px;
            padding: 10px;
            background-color: white;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        
        .points {
            display: inline-block;
            padding: 4px 12px;
            background-color: rgba(41, 98, 255, 0.1);
            border-radius: 4px;
            font-weight: 600;
        }

        @media print {
            body { 
                margin: 0;
                background-color: white;
            }
            .no-print { 
                display: none; 
            }
            .question, .student-info {
                box-shadow: none;
                border: 1px solid var(--border-color);
            }
            .header {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="../assets/img/logo-OFPPT-en-Arabe.jpg" alt="OFPPT Logo" class="logo">
        <div class="arabic-text">مكتب التكوين المهني وإنعاش الشغل</div>
        <div class="french-text">Office de la Formation Professionnelle et de la Promotion du Travail</div>
        <div class="french-text">INSTITUT SPECIALISE DE TECHNOLOGIE APPLIQUEE IFRANE</div>
    </div>

    <h1 style="text-align: center;">Relevé de Notes</h1>

    <div class="student-info">
        <div><strong>Nom et prénom:</strong> <?php echo htmlspecialchars($student['name']); ?></div>
        <div><strong>CIN:</strong> <?php echo htmlspecialchars($student['cne']); ?></div>
        <div><strong>Contrôle:</strong> CC (Contrôle N°1)</div>
        <div><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($exam['start_datetime'])); ?></div>
        <div><strong>Note finale:</strong> <?php echo number_format($final_score, 1); ?>/20</div>
    </div>

    <?php 
    $question_number = 1;
    foreach ($questions as $question): 
    ?>
        <div class="question">
            <strong>Question <?php echo $question_number; ?>: </strong>
            <?php echo htmlspecialchars($question['question_text']); ?>
            
            <?php if ($question['question_type'] === 'QCM'): ?>
                <div class="answer-choices">
                    <div class="question-points">
                        Points: <?php echo number_format($question['points_earned'] ?? 0, 1); ?>/<?php echo number_format($question['points'], 1); ?>
                    </div>
                    <?php 
                    // Parse choices with their IDs
                    $choices_data = [];
                    foreach (explode('|||', $question['choices'] ?? '') as $choice_info) {
                        list($id, $text) = explode(':', $choice_info, 2);
                        $choices_data[$id] = $text;
                    }
                    
                    // Debug output
                    echo "<!-- Debug:\n";
                    echo "Selected choices raw: " . print_r($question['selected_choices'], true) . "\n";
                    echo "Choices data: " . print_r($choices_data, true) . "\n";
                    echo "-->\n";
                    
                    // Convert selected_choices to array
                    $selected_choices = $question['selected_choices'];
                    $selected_ids = !empty($selected_choices) ? explode(',', $selected_choices) : [];
                    
                    // Show all choices
                    foreach ($choices_data as $choice_id => $choice_text): 
                        $isSelected = in_array($choice_id, $selected_ids);
                    ?>
                        <div class="choice <?php echo $isSelected ? 'selected' : ''; ?>">
                            <span class="choice-marker">
                                <?php 
                                if ($isSelected) {
                                    echo '☒'; // Checked box for student selection
                                } else {
                                    echo '☐'; // Empty box for unselected
                                }
                                ?>
                            </span>
                            <span class="choice-text">
                                <?php echo htmlspecialchars($choice_text); ?>
                            </span>
                        </div>
                    <?php 
                    endforeach; 
                    ?>
                    <div class="legend">
                        <div class="legend-item">
                            <span>☒ = Réponse sélectionnée par l'étudiant</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-answer">
                    <strong>Réponse de l'étudiant:</strong> 
                    <div class="answer-content">
                        <?php echo nl2br(htmlspecialchars($question['answer_text'] ?? 'Aucune réponse')); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="note">
                <span class="points">Points: <?php echo number_format($question['points_earned'] ?? 0, 1); ?>/<?php echo number_format($question['points'], 1); ?></span>
            </div>
        </div>
    <?php 
        $question_number++;
    endforeach; 
    ?>

    <div style="text-align: right; margin-top: 30px;">
        Page 1 sur 1
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
