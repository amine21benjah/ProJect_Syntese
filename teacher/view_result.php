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

// Get student answers
$stmt = $pdo->prepare("
    SELECT 
        q.id as question_id,
        q.question_text,
        q.points as max_points,
        q.question_type,
        q.correct_answer,
        sa.answer_text,
        sa.selected_choices,
        sa.score
    FROM questions q
    LEFT JOIN student_answers sa ON q.id = sa.question_id 
        AND sa.student_id = ? AND sa.exam_id = ?
    WHERE q.exam_id = ?
    ORDER BY q.order_num
");
$stmt->execute([$student_id, $exam_id, $exam_id]);
$questions = $stmt->fetchAll();

// Calculate total score
$total_score = array_sum(array_column($questions, 'score'));
$max_score = array_sum(array_column($questions, 'max_points'));
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultats de l'examen - <?php echo htmlspecialchars($exam['title']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="grade_exams.php?exam_id=<?php echo $exam_id; ?>" class="text-gray-800 hover:text-gray-600">
                        ← Retour aux soumissions
                    </a>
                </div>
                <div class="flex items-center">
                    <button onclick="downloadPDF()" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                        Télécharger PDF
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg p-6" id="result-content">
            <!-- Header -->
            <div class="mb-6 border-b pb-4">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">
                    <?php echo htmlspecialchars($exam['title']); ?>
                </h1>
                <div class="text-gray-600">
                    <p>Étudiant: <?php echo htmlspecialchars($exam['student_name']); ?></p>
                    <p>Groupe: <?php echo htmlspecialchars($exam['group_name']); ?></p>
                    <p>Score total: <?php echo $total_score; ?> / <?php echo $max_score; ?></p>
                </div>
            </div>

            <!-- List of questions and answers -->
            <div class="space-y-6">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="border rounded-lg p-4">
                        <div class="mb-4">
                            <h3 class="text-lg font-medium text-gray-900">
                                Question (<?php echo $question['max_points']; ?> points)
                            </h3>
                            <p class="mt-1 text-gray-600"><?php echo htmlspecialchars($question['question_text']); ?></p>
                        </div>

                        <div class="mb-4">
                            <h4 class="font-medium text-gray-700">Réponse de l'étudiant:</h4>
                            <div class="mt-1 p-2 bg-blue-50 rounded">
                                <?php if ($question['question_type'] === 'text'): ?>
                                    <?php
                                    $is_correct = strtolower(trim($question['answer_text'])) === strtolower(trim($question['correct_answer']));
                                    $bg_color = $is_correct ? 'bg-green-100' : 'bg-red-100';
                                    ?>
                                    <div class="p-2 <?php echo $bg_color; ?> rounded mb-1">
                                        <?php echo nl2br(htmlspecialchars($question['answer_text'] ?? '')); ?>
                                    </div>
                                <?php else: ?>
                                    <?php
                                    $selected_choices = explode(',', $question['selected_choices']);
                                    $correct_answers = explode(',', $question['correct_answer']);
                                    $stmt = $pdo->prepare("SELECT id, choice_text FROM question_choices WHERE question_id = ?");
                                    $stmt->execute([$question['question_id']]);
                                    $choices = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($choices as $choice) {
                                        $is_selected = in_array($choice['id'], $selected_choices);
                                        $is_correct = in_array($choice['id'], $correct_answers);
                                        $bg_color = $is_selected ? ($is_correct ? 'bg-green-100' : 'bg-red-100') : 'bg-gray-100';
                                        echo "<div class='p-2 $bg_color rounded mb-1'>" . htmlspecialchars($choice['choice_text']) . "</div>";
                                    }
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-gray-700">
                                Score obtenu:
                            </label>
                            <p class="mt-1"><?php echo $question['score'] ?? 0; ?> / <?php echo $question['max_points']; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        function downloadPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            const content = document.getElementById('result-content');

            html2canvas(content).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const imgWidth = 210; // A4 width in mm
                const pageHeight = 295; // A4 height in mm
                const imgHeight = canvas.height * imgWidth / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;

                doc.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;

                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    doc.addPage();
                    doc.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                doc.save('resultats_examen.pdf');
            });
        }
    </script>
</body>
</html>