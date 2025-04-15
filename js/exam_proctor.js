class ExamProctor {
    constructor() {
        this.violations = [];
        this.startTime = new Date();
        this.isFullscreen = false;
        this.lastActivity = new Date();
        this.suspiciousActivities = [];
        this.initializeProctoring();
    }

    initializeProctoring() {
        // Détecter le changement de focus de la fenêtre
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.logViolation('Tab/Window Switch', 'User switched to another tab or window');
            }
        });

        // Détecter les tentatives de copier
        document.addEventListener('copy', (e) => {
            e.preventDefault();
            this.logViolation('Copy Attempt', 'User attempted to copy content');
        });

        // Détecter les tentatives de capture d'écran
        document.addEventListener('keydown', (e) => {
            if ((e.key === 'PrintScreen') || 
                (e.ctrlKey && e.key === 'p') || 
                (e.ctrlKey && e.shiftKey && e.key === 'I') ||
                (e.ctrlKey && e.shiftKey && e.key === 'C')) {
                e.preventDefault();
                this.logViolation('Screen Capture Attempt', 'User attempted to capture screen');
            }
        });

        // Surveiller le plein écran
        document.addEventListener('fullscreenchange', () => {
            this.isFullscreen = !!document.fullscreenElement;
            if (!this.isFullscreen) {
                this.logViolation('Fullscreen Exit', 'User exited fullscreen mode');
            }
        });

        // Détecter les mouvements de souris suspects
        let mouseMovements = 0;
        let lastMouseCheck = new Date();

        document.addEventListener('mousemove', () => {
            mouseMovements++;
            const now = new Date();
            if (now - lastMouseCheck > 5000) { // Vérifier toutes les 5 secondes
                if (mouseMovements > 1000) { // Seuil arbitraire pour les mouvements suspects
                    this.logSuspiciousActivity('Excessive Mouse Movement');
                }
                mouseMovements = 0;
                lastMouseCheck = now;
            }
        });

        // Vérifier périodiquement l'activité
        setInterval(() => {
            const now = new Date();
            if (now - this.lastActivity > 30000) { // 30 secondes d'inactivité
                this.logSuspiciousActivity('Extended Inactivity');
            }
        }, 30000);

        // Empêcher le clic droit
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
        });
    }

    logViolation(type, description) {
        const violation = {
            type: type,
            description: description,
            timestamp: new Date()
        };
        this.violations.push(violation);
        this.sendViolationToServer(violation);
    }

    logSuspiciousActivity(description) {
        const activity = {
            description: description,
            timestamp: new Date()
        };
        this.suspiciousActivities.push(activity);
        this.sendSuspiciousActivityToServer(activity);
    }

    sendViolationToServer(violation) {
        fetch('record_violation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                type: 'violation',
                data: violation
            })
        });
    }

    sendSuspiciousActivityToServer(activity) {
        fetch('record_violation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                type: 'suspicious_activity',
                data: activity
            })
        });
    }

    requestFullscreen() {
        const elem = document.documentElement;
        if (elem.requestFullscreen) {
            elem.requestFullscreen();
        }
    }

    generateReport() {
        return {
            startTime: this.startTime,
            endTime: new Date(),
            violations: this.violations,
            suspiciousActivities: this.suspiciousActivities
        };
    }
}

// Initialiser le système de surveillance
window.examProctor = new ExamProctor();
