<?php
class SessionManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function startSession($userId) {
        // Vérifier si l'utilisateur a déjà une session active
        $stmt = $this->db->prepare("SELECT * FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $existingSession = $stmt->fetch();

        if ($existingSession) {
            // Si la session existe et que ce n'est pas le même navigateur/appareil
            if ($existingSession['ip_address'] !== $_SERVER['REMOTE_ADDR'] || 
                $existingSession['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
                return false; // Empêcher la nouvelle connexion
            }
        }

        session_start();
        $sessionId = session_id();
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'];

        // Supprimer toute session existante
        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$userId]);

        // Insérer la nouvelle session
        $stmt = $this->db->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $sessionId, $ipAddress, $userAgent]);

        $_SESSION['user_id'] = $userId;
        $_SESSION['session_id'] = $sessionId;
        return true;
    }

    public function validateSession($userId) {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT * FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $session = $stmt->fetch();

        if (!$session) {
            return false;
        }

        // Vérifier si c'est le même navigateur/appareil
        if ($session['ip_address'] !== $_SERVER['REMOTE_ADDR'] || 
            $session['user_agent'] !== $_SERVER['HTTP_USER_AGENT'] ||
            $session['session_id'] !== $_SESSION['session_id']) {
            $this->destroySession();
            return false;
        }

        // Mettre à jour last_activity
        $stmt = $this->db->prepare("UPDATE user_sessions SET last_activity = CURRENT_TIMESTAMP WHERE user_id = ?");
        $stmt->execute([$userId]);
        return true;
    }

    public function destroySession() {
        if (isset($_SESSION['user_id'])) {
            $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        }
        
        session_unset();
        session_destroy();
    }
}
?>
