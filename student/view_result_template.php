<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultats de l'examen - <?php echo htmlspecialchars($exam['title']); ?></title>
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
            <!-- En-tête -->
            <div class="mb-6 border-b pb-4">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">
                    <?php echo htmlspecialchars($exam['title']); ?>
                </h1>
                <div class="text-gray-600">
                    <p>Étudiant: <?php echo htmlspecialchars($exam['student_name']); ?></p>
                    <p>Groupe: <?php echo htmlspecialchars($exam['group_name']); ?></p>
                    <p>Enseignant: <?php echo htmlspecialchars($exam['teacher_name']); ?></p>
                    <p>Statut: 
                        <span class="<?php echo $exam['attempt_status'] === 'completed' ? 'text-green-600' : 'text-orange-600'; ?>">
                            <?php echo $exam['attempt_status'] === 'completed' ? 'Terminé' : 'Temps expiré'; ?>
                        </span>
                    </p>
                    <p class="mt-2 text-lg font-semibold">
                        Score total: <?php echo number_format($exam['total_score'], 1); ?> / <?php echo number_format($exam['total_possible'], 1); ?>
                        <?php if ($exam['total_possible'] > 0): ?>
                        (<?php echo number_format(($exam['total_score'] / $exam['total_possible']) * 100, 1); ?>%)
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Questions et réponses -->
            <div class="space-y-6">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="border rounded-lg p-4">
                        <div class="mb-4">
                            <h3 class="text-lg font-medium text-gray-900">
                                Question <?php echo $index + 1; ?> (<?php echo $question['points']; ?> points)
                            </h3>
                            <p class="mt-1 text-gray-600"><?php echo nl2br(htmlspecialchars($question['question_text'])); ?></p>
                            <?php if ($question['image_path']): ?>
                                <img src="../<?php echo htmlspecialchars($question['image_path']); ?>" 
                                     alt="Question Image" 
                                     class="mt-4 max-w-full h-auto" 
                                     style="max-width: 300px;">
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <h4 class="font-medium text-gray-700">Votre réponse:</h4>
                            <div class="mt-1 p-2 bg-gray-50 rounded">
                                <?php if ($question['question_type'] === 'text'): ?>
                                    <?php if (!empty($question['answer_text'])): ?>
                                        <span class="text-blue-600">
                                            <?php echo nl2br(htmlspecialchars($question['answer_text'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-500 italic">Aucune réponse</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php
                                    $selected_choices = !empty($question['selected_choices']) ? 
                                        explode(',', $question['selected_choices']) : [];
                                    $stmt = $pdo->prepare("
                                        SELECT id, choice_text, is_correct 
                                        FROM question_choices 
                                        WHERE question_id = ?
                                    ");
                                    $stmt->execute([$question['id']]);
                                    $choices = $stmt->fetchAll();
                                    
                                    foreach ($choices as $choice):
                                        $is_selected = in_array($choice['id'], $selected_choices);
                                        $bg_color = $is_selected ? 
                                            ($choice['is_correct'] ? 'bg-green-100' : 'bg-red-100') : 
                                            ($choice['is_correct'] ? 'bg-green-50' : 'bg-gray-50');
                                    ?>
                                        <div class="p-2 <?php echo $bg_color; ?> rounded mb-1">
                                            <?php echo htmlspecialchars($choice['choice_text']); ?>
                                            <?php if ($is_selected && $choice['is_correct']): ?>
                                                <span class="text-green-600 ml-2">✓</span>
                                            <?php elseif ($is_selected && !$choice['is_correct']): ?>
                                                <span class="text-red-600 ml-2">✗</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="text-right">
                            <span class="font-medium">
                                Score: <?php echo number_format($question['score'], 1); ?> / <?php echo number_format($question['points'], 1); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>