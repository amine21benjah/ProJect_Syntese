<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['title', 'module_id', 'group_ids', 'start_datetime', 'duration', 'questions'];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("Le champ " . $field . " est requis.");
            }
        }

        $title = trim($_POST['title']);
        $description = trim($_POST['description'] ?? '');
        $group_id = $_POST['group_ids'][0]; // Get first selected group
        $module_id = $_POST['module_id'];
        $start_datetime = $_POST['start_datetime'];
        $duration = intval($_POST['duration']);
        $is_rattrapage = isset($_POST['exam_type']) && $_POST['exam_type'] === 'rattrapage' ? 1 : 0;
        $original_exam_id = $is_rattrapage && isset($_POST['original_exam_id']) ? $_POST['original_exam_id'] : null;

        $pdo->beginTransaction();

        // Insert exam
        $stmt = $pdo->prepare("
            INSERT INTO exams (title, description, group_id, module_id, start_datetime, duration, teacher_id, is_rattrapage, original_exam_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $description, $group_id, $module_id, $start_datetime, $duration, $_SESSION['user_id'], $is_rattrapage, $original_exam_id]);
        $exam_id = $pdo->lastInsertId();

        // Insert questions
        foreach ($_POST['questions'] as $index => $question_text) {
            if (empty(trim($question_text))) {
                continue; // Skip empty questions
            }

            $question_type = isset($_POST['question_types'][$index]) ? $_POST['question_types'][$index] : 'text';
            $points = isset($_POST['points'][$index]) ? intval($_POST['points'][$index]) : 1;

            // Insert question
            $stmt = $pdo->prepare("
                INSERT INTO questions (exam_id, question_text, question_type, points, order_num)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$exam_id, trim($question_text), $question_type, $points, $index + 1]);
            $question_id = $pdo->lastInsertId();

            // Handle choices for QCM questions
            if ($question_type === 'QCM' && isset($_POST['choices'][$index]) && is_array($_POST['choices'][$index])) {
                $choices = array_filter($_POST['choices'][$index], 'trim'); // Remove empty choices
                $correct_answers = isset($_POST['correct_answers'][$index]) ? (array)$_POST['correct_answers'][$index] : [];

                foreach ($choices as $choice_index => $choice_text) {
                    $is_correct = in_array((string)$choice_index, $correct_answers) ? 1 : 0;
                    $stmt = $pdo->prepare("
                        INSERT INTO question_choices (question_id, choice_text, is_correct)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$question_id, trim($choice_text), $is_correct]);
                }
            }
        }

        $pdo->commit();
        header("Location: exams.php?message=exam_created");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Une erreur s'est produite lors de la création de l'examen : " . $e->getMessage();
    }
}

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
           (SELECT COUNT(*) FROM exams e WHERE e.module_id = m.id AND e.is_rattrapage = 0) as normal_exam_count,
           (SELECT COUNT(*) FROM exams e WHERE e.module_id = m.id AND e.is_rattrapage = 1) as rattrapage_count
    FROM modules m
    WHERE m.teacher_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$modules = $stmt->fetchAll();

// Get original exams for rattrapage (only those that don't have a rattrapage yet)
$stmt = $pdo->prepare("
    SELECT e.* 
    FROM exams e
    LEFT JOIN exams r ON e.id = r.original_exam_id
    WHERE e.teacher_id = ? 
    AND e.is_rattrapage = 0
    AND r.id IS NULL
    AND e.start_datetime < NOW()
");
$stmt->execute([$_SESSION['user_id']]);
$original_exams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créer un Examen - ExamEnLigne</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-600">Créer un Examen</span>
                </div>
                <div class="flex items-center">
                    <a href="exams.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700 mr-2">Retour aux examens</a>
                    <a href="../logout.php" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">Déconnexion</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-lg p-6">
            <form method="POST" id="examForm" class="space-y-6" enctype="multipart/form-data" action="create_exam.php">
                <!-- Add a hidden input to detect form submission -->
                <input type="hidden" name="submit_exam" value="1">
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Titre de l'examen</label>
                    <input type="text" name="title" id="title" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="3" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Groupes
                    </label>
                    <select name="group_ids[]" multiple required class="form-multiselect mt-1 block w-full">
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>">
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
                            <?php 
                                $canCreateNormal = $module['normal_exam_count'] < 8;
                                $canCreateRattrapage = $module['rattrapage_count'] == 0;
                            ?>
                            <?php if ($canCreateNormal || $canCreateRattrapage): ?>
                                <option value="<?php echo $module['id']; ?>" 
                                        data-has-rattrapage="<?php echo !$canCreateRattrapage ? '1' : '0'; ?>"
                                        data-normal-count="<?php echo $module['normal_exam_count']; ?>">
                                    <?php echo htmlspecialchars($module['name']); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="duration" class="block text-sm font-medium text-gray-700">Durée (minutes)</label>
                    <input type="number" name="duration" id="duration" required min="1"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <label for="start_datetime" class="block text-sm font-medium text-gray-700">Date et heure de début</label>
                    <input type="datetime-local" name="start_datetime" id="start_datetime" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Type d'examen
                    </label>
                    <div class="mt-2">
                        <label class="inline-flex items-center">
                            <input type="radio" name="exam_type" value="normal" class="form-radio" checked onchange="validateExamType(this.value)">
                            <span class="ml-2">Examen normal</span>
                        </label>
                        <label class="inline-flex items-center ml-6">
                            <input type="radio" name="exam_type" value="rattrapage" class="form-radio" onchange="validateExamType(this.value)">
                            <span class="ml-2">Examen rattrapage</span>
                        </label>
                    </div>
                </div>

                <div id="rattrapage_options" class="mb-4 hidden">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Sélectionner l'examen original
                    </label>
                    <select name="original_exam_id" class="form-select mt-1 block w-full">
                        <option value="">Sélectionner un examen</option>
                        <?php foreach ($original_exams as $exam): ?>
                            <option value="<?php echo $exam['id']; ?>"><?php echo htmlspecialchars($exam['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="questions-container" class="space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">Questions</h3>
                </div>

                <div class="flex justify-between">
                    <button type="button" onclick="addQuestion()"
                            class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                        Ajouter une question
                    </button>
                    <button type="submit"
                            class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                        Créer l'examen
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let questionCount = 0;

        function addQuestion() {
            const questionsContainer = document.getElementById('questions-container');
            const questionBlocks = questionsContainer.querySelectorAll('.question-block');
            const nextIndex = questionBlocks.length;
            
            const template = `
                <div class="question-block border rounded-md p-4" data-index="${nextIndex}">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Type de question</label>
                        <select name="question_types[]" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                onchange="toggleChoicesContainer(this)">
                            <option value="text">Texte</option>
                            <option value="definition">Définition</option>
                            <option value="QCM">QCM</option>
                        </select>
                    </div>
                    <div class="mt-2">
                        <label class="block text-sm font-medium text-gray-700">Question ${nextIndex + 1}</label>
                        <textarea name="questions[]" required rows="2"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    </div>
                    <div class="mt-2">
                        <label class="block text-sm font-medium text-gray-700">Points</label>
                        <input type="number" name="points[]" required min="1" value=""
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div class="mt-2 choices-container hidden">
                        <label class="block text-sm font-medium text-gray-700">Choix</label>
                        <div class="space-y-2" data-question-index="${nextIndex}"></div>
                        <button type="button" onclick="addChoice(this)"
                                class="mt-2 inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-600 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Ajouter un choix
                        </button>
                    </div>
                    <button type="button" onclick="removeQuestion(this)"
                            class="mt-4 inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Supprimer la question
                    </button>
                </div>
            `;
            
            questionsContainer.insertAdjacentHTML('beforeend', template);

            // Add first choice automatically if it's QCM
            const newQuestion = questionsContainer.lastElementChild;
            const questionType = newQuestion.querySelector('select[name="question_types[]"]').value;
            if (questionType === 'QCM') {
                const addChoiceButton = newQuestion.querySelector('button[onclick="addChoice(this)"]');
                addChoice(addChoiceButton);
            }
        }

        function addChoice(button) {
            const questionBlock = button.closest('.question-block');
            const container = questionBlock.querySelector('.space-y-2');
            const questionIndex = questionBlock.dataset.index;
            const choiceCount = container.querySelectorAll('.choice-block').length;
            
            const newChoice = document.createElement('div');
            newChoice.className = 'choice-block';
            newChoice.innerHTML = `
                <div class="flex items-center space-x-2 mt-2">
                    <input type="text" name="choices[${questionIndex}][]" required
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                           placeholder="Option ${choiceCount + 1}">
                    <div class="flex items-center">
                        <input type="checkbox" name="correct_answers[${questionIndex}][]" value="${choiceCount}"
                               class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <label class="ml-2 text-sm text-gray-700">Correct</label>
                    </div>
                    ${choiceCount > 0 ? `
                        <button type="button" onclick="removeChoice(this)"
                                class="text-red-600 hover:text-red-900">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    ` : ''}
                </div>
            `;
            container.appendChild(newChoice);
        }

        function removeChoice(button) {
            const choiceBlock = button.closest('.choice-block');
            const questionBlock = choiceBlock.closest('.question-block');
            choiceBlock.remove();
            updateChoiceIndices(questionBlock);
        }

        function updateChoiceIndices(questionBlock) {
            const questionIndex = questionBlock.dataset.index;
            const choices = questionBlock.querySelectorAll('.choice-block');
            
            choices.forEach((choice, index) => {
                const input = choice.querySelector('input[type="text"]');
                const checkbox = choice.querySelector('input[type="checkbox"]');
                
                input.name = `choices[${questionIndex}][]`;
                input.placeholder = `Option ${index + 1}`;
                checkbox.name = `correct_answers[${questionIndex}][]`;
                checkbox.value = index;
            });
        }

        function removeQuestion(button) {
            const questionBlock = button.closest('.question-block');
            questionBlock.remove();
            updateQuestionIndices();
        }

        function updateQuestionIndices() {
            const questionsContainer = document.getElementById('questions-container');
            const questions = questionsContainer.querySelectorAll('.question-block');
            
            questions.forEach((block, index) => {
                // Update question index
                block.dataset.index = index;
                
                // Update question number in label
                const label = block.querySelector('label:nth-of-type(2)');
                if (label) {
                    label.textContent = `Question ${index + 1}`;
                }
                
                // Update choices container index
                const choicesContainer = block.querySelector('.space-y-2');
                if (choicesContainer) {
                    choicesContainer.dataset.questionIndex = index;
                }
                
                // Update all choices in this question
                updateChoiceIndices(block);
            });
        }

        function validateExamType(type) {
            const moduleSelect = document.getElementById('module_id');
            const selectedOption = moduleSelect.options[moduleSelect.selectedIndex];
            
            if (type === 'rattrapage' && selectedOption.dataset.hasRattrapage === '1') {
                alert('Ce module a déjà un examen rattrapage. Veuillez sélectionner un autre module.');
                document.querySelector('input[name="exam_type"][value="normal"]').checked = true;
                return;
            }
            
            if (type === 'normal' && parseInt(selectedOption.dataset.normalCount) >= 8) {
                alert('Ce module a déjà atteint le maximum de 8 examens normaux. Veuillez sélectionner un autre module.');
                document.querySelector('input[name="exam_type"][value="rattrapage"]').checked = true;
                return;
            }
            
            toggleOriginalExam(type);
        }

        // Add event listener for module selection
        document.getElementById('module_id').addEventListener('change', function() {
            const examType = document.querySelector('input[name="exam_type"]:checked').value;
            validateExamType(examType);
        });

        function toggleChoicesContainer(selectElement) {
            const questionBlock = selectElement.closest('.question-block');
            const choicesContainer = questionBlock.querySelector('.choices-container');
            
            if (selectElement.value === 'QCM') {
                choicesContainer.classList.remove('hidden');
                if (choicesContainer.querySelector('.space-y-2').children.length === 0) {
                    const addChoiceButton = choicesContainer.querySelector('button[onclick="addChoice(this)"]');
                    addChoice(addChoiceButton);
                }
            } else {
                choicesContainer.classList.add('hidden');
                const choicesSpace = choicesContainer.querySelector('.space-y-2');
                while (choicesSpace.firstChild) {
                    choicesSpace.removeChild(choicesSpace.firstChild);
                }
            }
        }

        function toggleOriginalExam(value) {
            const container = document.getElementById('rattrapage_options');
            container.classList.toggle('hidden', value !== 'rattrapage');
        }

        // Form validation before submit
        document.querySelector('form').addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default submission
            
            const questions = document.querySelectorAll('.question-block');
            let hasError = false;

            // Validate basic form fields
            const requiredFields = ['title', 'module_id', 'group_ids[]', 'start_datetime', 'duration'];
            requiredFields.forEach(field => {
                const input = document.querySelector(`[name="${field}"]`);
                if (!input || !input.value.trim()) {
                    alert(`Le champ ${field.replace('[]', '')} est requis.`);
                    hasError = true;
                }
            });

            // Validate questions
            if (questions.length === 0) {
                alert('Au moins une question est requise.');
                hasError = true;
            }

            questions.forEach((question, index) => {
                const questionText = question.querySelector('textarea[name="questions[]"]');
                if (!questionText || !questionText.value.trim()) {
                    alert(`La question ${index + 1} ne peut pas être vide.`);
                    hasError = true;
                }

                const questionType = question.querySelector('select[name="question_types[]"]').value;
                if (questionType === 'QCM') {
                    const choices = question.querySelectorAll('.choice-block');
                    const correctAnswers = question.querySelectorAll('input[type="checkbox"]:checked');
                    
                    if (choices.length < 2) {
                        alert(`La question ${index + 1} doit avoir au moins 2 choix.`);
                        hasError = true;
                    }
                    
                    if (correctAnswers.length === 0) {
                        alert(`La question ${index + 1} doit avoir au moins une réponse correcte.`);
                        hasError = true;
                    }

                    choices.forEach((choice, choiceIndex) => {
                        const input = choice.querySelector('input[type="text"]');
                        if (!input.value.trim()) {
                            alert(`Le choix ${choiceIndex + 1} de la question ${index + 1} ne peut pas être vide.`);
                            hasError = true;
                        }
                    });
                }
            });

            if (!hasError) {
                // If no errors, submit the form
                this.submit();
            }
        });

        // Add first question on page load
        document.addEventListener('DOMContentLoaded', function() {
            addQuestion();
        });
    </script>
</body>
</html>
