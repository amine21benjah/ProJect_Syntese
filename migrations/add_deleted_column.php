<?php
require_once '../config.php';

try {
    // Ajouter la colonne deleted si elle n'existe pas
    $pdo->exec("ALTER TABLE exams ADD COLUMN IF NOT EXISTS deleted TINYINT(1) DEFAULT 0");
    echo "La colonne 'deleted' a été ajoutée avec succès à la table 'exams'.";
} catch (PDOException $e) {
    echo "Erreur lors de l'ajout de la colonne : " . $e->getMessage();
} 