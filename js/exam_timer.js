const timerEl = document.getElementById('examTimer');

let timerWarning = false;
let timerDanger = false;

function startTimer() {
    const interval = setInterval(() => {
        if (timeLeft <= 0) {
            clearInterval(interval);
            submitExam('timeout', () => {
                window.location.href = 'register.php';
            });
            return;
        }
        timeLeft--;
        const hours = Math.floor(timeLeft / 3600);
        const minutes = Math.floor((timeLeft % 3600) / 60);
        const seconds = timeLeft % 60;
        timerEl.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        
        if (timeLeft <= 300 && !timerWarning) {
            timerEl.classList.add('warning');
            timerWarning = true;
        }
        if (timeLeft <= 60 && !timerDanger) {
            timerEl.classList.remove('warning');
            timerEl.classList.add('danger');
            timerDanger = true;
        }

        // Save time periodically (every 10 seconds)
        if (timeLeft % 10 === 0) {
            saveState();
        }
    }, 1000);
}