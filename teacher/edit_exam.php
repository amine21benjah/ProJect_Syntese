<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Get exam details
if (!isset($_GET['exam_id'])) {
    header("Location: dashboard.php");
    exit();
}

$exam_id = $_GET['exam_id'];

// Check if exam exists
$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) {
    header("Location: dashboard.php");
    exit();
}

// Get existing questions
$stmt = $pdo->prepare("SELECT * FROM questions WHERE exam_id = ? ORDER BY order_num");
$stmt->execute([$exam_id]);
$questions = $stmt->fetchAll();

// Get teacher's groups assigned by admin
$stmt = $pdo->prepare("
    SELECT g.* 
    FROM study_groups g
    JOIN group_teachers gt ON g.id = gt.group_id
    WHERE gt.teacher_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$groups = $stmt->fetchAll();

// Get teacher's modules
$stmt = $pdo->prepare("
    SELECT m.*, 
           (SELECT COUNT(*) FROM exams e WHERE e.module_id = m.id) as exam_count
    FROM modules m
    WHERE m.teacher_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$modules = $stmt->fetchAll();

// Update exam details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $group_id = $_POST['group_id'];
    $module_id = $_POST['module_id'];
    $start_datetime = $_POST['start_datetime'];
    $duration = $_POST['duration'];
    $question_ids = $_POST['question_ids'];
    $question_texts = $_POST['questions'];
    $question_types = $_POST['question_types'];
    $points = $_POST['points'];
    $choices = $_POST['choices'];

    try{
        $pdo->beginTransaction();

        // Update exam
        $stmt = $pdo->prepare("UPDATE exams SET title = ?, description = ?, group_id = ?, module_id = ?, start_datetime = ?, duration = ? WHERE id = ?");
        $stmt->execute([$title, $description, $group_id, $module_id, $start_datetime, $duration, $exam_id]);

        // Update or insert questions
        foreach ($question_texts as $index => $question_text) {
            $question_id = $question_ids[$index];
            $correct_answer = $question_types[$index] == 'text' ? null : (is_array($choices[$index]) ? implode(',', $choices[$index]) : null);

            if ($question_id) {
                // Update existing question
                $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, question_type = ?, correct_answer = ?, points = ?, order_num = ? WHERE id = ?");
                $stmt->execute([$question_text, $question_types[$index], $correct_answer, $points[$index], $index + 1, $question_id]);

                // Delete existing choices for multiple choice questions
                if ($question_types[$index] == 'QCM') {
                    $stmt = $pdo->prepare("DELETE FROM question_choices WHERE question_id = ?");
                    $stmt->execute([$question_id]);

                    // Insert new choices
                    foreach ($choices[$index] as $choice_index => $choice) {
                        $is_correct = isset($_POST['correct_answers'][$index]) && in_array($choice_index, $_POST['correct_answers'][$index]) ? 1 : 0;
                        $stmt = $pdo->prepare("INSERT INTO question_choices (question_id, choice_text, is_correct) VALUES (?, ?, ?)");
                        $stmt->execute([$question_id, $choice, $is_correct]);
                    }
                }
            } else {
                // Insert new question
                $stmt = $pdo->prepare("INSERT INTO questions (exam_id, question_text, question_type, correct_answer, points, order_num) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$exam_id, $question_text, $question_types[$index], $correct_answer, $points[$index], $index + 1]);
                $question_id = $pdo->lastInsertId();

                // Insert choices for multiple choice questions
                if ($question_types[$index] == 'QCM') {
                    foreach ($choices[$index] as $choice_index => $choice) {
                        $is_correct = isset($_POST['correct_answers'][$index]) && in_array($choice_index, $_POST['correct_answers'][$index]) ? 1 : 0;
                        $stmt = $pdo->prepare("INSERT INTO question_choices (question_id, choice_text, is_correct) VALUES (?, ?, ?)");
                        $stmt->execute([$question_id, $choice, $is_correct]);
                    }
                }
            }
        }

        $pdo->commit();
        header("Location: dashboard.php?message=exam_updated");
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to update exam: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier l'examen</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="text-gray-800 hover:text-gray-600">
                        ← Retour au tableau de bord
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg p-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-4">Modifier l'examen</h1>
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form method="post" class="space-y-6">
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Titre</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($exam['title']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea id="description" name="description" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"><?php echo htmlspecialchars($exam['description']); ?></textarea>
                </div>
                <div>
                    <label for="group_id" class="block text-sm font-medium text-gray-700">Groupe</label>
                    <select name="group_id" id="group_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>" <?php echo $group['id'] == $exam['group_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($group['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="module_id" class="block text-sm font-medium text-gray-700">Module</label>
                    <select name="module_id" id="module_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <?php foreach ($modules as $module): ?>
                            <?php if ($module['exam_count'] < 4 || $module['id'] == $exam['module_id']): ?>
                                <option value="<?php echo $module['id']; ?>" <?php echo $module['id'] == $exam['module_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($module['name']); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="start_datetime" class="block text-sm font-medium text-gray-700">Date et heure de début</label>
                    <input type="datetime-local" id="start_datetime" name="start_datetime" value="<?php echo date('Y-m-d\TH:i', strtotime($exam['start_datetime'])); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="duration" class="block text-sm font-medium text-gray-700">Durée (minutes)</label>
                    <input type="number" id="duration" name="duration" value="<?php echo htmlspecialchars($exam['duration']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div id="questions-container" class="space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">Questions</h3>
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="question-block border rounded-md p-4">
                            <input type="hidden" name="question_ids[]" value="<?php echo $question['id']; ?>">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Type de question</label>
                                <select name="question_types[]" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        onchange="toggleChoicesContainer(this)">
                                    <option value="text" <?php echo $question['question_type'] == 'text' ? 'selected' : ''; ?>>Texte</option>
                                    <option value="definition" <?php echo $question['question_type'] == 'definition' ? 'selected' : ''; ?>>Définition</option>
                                    <option value="QCM" <?php echo $question['question_type'] == 'QCM' ? 'selected' : ''; ?>>QCM</option>
                                </select>
                            </div>
                            <div class="mt-2">
                                <label class="block text-sm font-medium text-gray-700">Question <?php echo $index + 1; ?></label>
                                <textarea name="questions[]" required rows="2"
                                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"><?php echo htmlspecialchars($question['question_text']); ?></textarea>
                            </div>
                            <div class="mt-2">
                                <label class="block text-sm font-medium text-gray-700">Points</label>
                                <input type="number" name="points[]" required min="1" value="<?php echo $question['points']; ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div class="mt-2 choices-container <?php echo $question['question_type'] == 'QCM' ? '' : 'hidden'; ?>">
                                <label class="block text-sm font-medium text-gray-700">Choix</label>
                                <div class="space-y-2">
                                    <?php
                                    $stmt = $pdo->prepare("SELECT * FROM question_choices WHERE question_id = ?");
                                    $stmt->execute([$question['id']]);
                                    $choices = $stmt->fetchAll();
                                    foreach ($choices as $choice_index => $choice):
                                    ?>
                                        <div class="choice-block">
                                            <input type="text" name="choices[<?php echo $index; ?>][]" value="<?php echo htmlspecialchars($choice['choice_text']); ?>"
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            <label class="inline-flex items-center mt-2">
                                                <input type="checkbox" name="correct_answers[<?php echo $index; ?>][]" value="<?php echo $choice_index; ?>" <?php echo $choice['is_correct'] ? 'checked' : ''; ?>
                                                       class="form-checkbox">
                                                <span class="ml-2">Correct</span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="button" onclick="addChoice(this)" class="mt-2 bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">Ajouter un choix</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex justify-between">
                    <button type="button" onclick="addQuestion()" 
                            class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                        Ajouter une question
                    </button>
                    <button type="submit"
                            class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                        Mettre à jour l'examen
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let questionCount = <?php echo count($questions); ?>;

        function toggleChoicesContainer(selectElement) {
            const questionBlock = selectElement.closest('.question-block');
            const choicesContainer = questionBlock.querySelector('.choices-container');
            if (selectElement.value === 'QCM') {
                choicesContainer.classList.remove('hidden');
            } else {
                choicesContainer.classList.add('hidden');
            }
        }

        function addQuestion() {
            const template = `
                <div class="question-block border rounded-md p-4">
                    <input type="hidden" name="question_ids[]" value="">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Type de question</label>
                        <select name="question_types[]" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                onchange="toggleChoicesContainer(this)">
                            <option value="text">Texte</option>
                            <option value="definition">Définition</option>
                            <option value="QCM">QCM</option>
                        </select>
                    </div>
                    <div class="mt-2">
                        <label class="block text-sm font-medium text-gray-700">Question ${questionCount + 1}</label>
                        <textarea name="questions[]" required rows="2"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    </div>
                    <div class="mt-2">
                        <label class="block text-sm font-medium text-gray-700">Points</label>
                        <input type="number" name="points[]" required min="1" value="1"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div class="mt-2 choices-container hidden">
                        <label class="block text-sm font-medium text-gray-700">Choix</label>
                        <div class="space-y-2">
                            <div class="choice-block">
                                <input type="text" name="choices[${questionCount}][]"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                       placeholder="Option 1">
                                <label class="inline-flex items-center mt-2">
                                    <input type="checkbox" name="correct_answers[${questionCount}][]" value="0"
                                           class="form-checkbox">
                                    <span class="ml-2">Correct</span>
                                </label>
                            </div>
                        </div>
                        <button type="button" onclick="addChoice(this)" class="mt-2 bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">Ajouter un choix</button>
                    </div>
                    <button type="button" onclick="removeQuestion(this)" class="mt-4 inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">Supprimer la question</button>
                </div>
            `;
            
            document.getElementById('questions-container').insertAdjacentHTML('beforeend', template);
            questionCount++;
        }

        function addChoice(button) {
            const questionBlock = button.closest('.question-block');
            const container = button.previousElementSibling;
            if (!container) return;
            
            const choiceCount = container.querySelectorAll('.choice-block').length;
            const newChoice = document.createElement('div');
            newChoice.className = 'choice-block';
            newChoice.innerHTML = `
                <input type="text" name="choices[${questionCount - 1}][]" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <label class="inline-flex items-center mt-2">
                    <input type="checkbox" name="correct_answers[${questionCount - 1}][]" value="${choiceCount}" class="form-checkbox">
                    <span class="ml-2">Correct</span>
                </label>
                <button type="button" onclick="deleteChoice(this)" class="ml-2 text-red-600 hover:text-red-800">Supprimer</button>
            `;
            if (button.parentElement === container) {
                container.insertBefore(newChoice, button);
            } else {
                container.appendChild(newChoice);
            }
        }

        function deleteChoice(button) {
            button.parentElement.remove();
        }

        function removeQuestion(button) {
            button.closest('.question-block').remove();
        }
    </script>
</body>
</html>
