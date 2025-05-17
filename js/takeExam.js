let currentQuestion = 0;
const totalQuestions = window.totalQuestions  || [];
const answeredQuestions = new Set();

function showQuestion(index) {
    // Hide all questions
    document.querySelectorAll('.question-container').forEach(q => q.classList.remove('active'));
    
    // Show the current question
    document.querySelector(`.question-container[data-question="${index}"]`).classList.add('active');
    
    // Update question number display
    document.getElementById('currentQuestionNum').textContent = index + 1;
    
    // Update progress boxes
    document.querySelectorAll('.question-box').forEach(box => {
        box.classList.remove('current');
        if (parseInt(box.dataset.index) === index) {
            box.classList.add('current');
        }
    });
    
    // Show/hide navigation buttons
    document.querySelector('button[onclick="previousQuestion()"]').style.display = index === 0 ? 'none' : 'block';
    document.querySelector('button[onclick="nextQuestion()"]').style.display = index === totalQuestions - 1 ? 'none' : 'block';
    document.getElementById('submitBtn').style.display = index === totalQuestions - 1 ? 'block' : 'none';
}

function jumpToQuestion(index) {
    currentQuestion = index;
    showQuestion(index);
}

// Track answered questions
document.querySelectorAll('input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const questionContainer = this.closest('.question-container');
        const questionIndex = parseInt(questionContainer.dataset.question);
        answeredQuestions.add(questionIndex);
        
        // Update progress box
        const box = document.querySelector(`.question-box[data-index="${questionIndex}"]`);
        box.classList.add('answered');
    });
    
    // Check if question is already answered on page load
    if (radio.checked) {
        const questionContainer = radio.closest('.question-container');
        const questionIndex = parseInt(questionContainer.dataset.question);
        answeredQuestions.add(questionIndex);
        const box = document.querySelector(`.question-box[data-index="${questionIndex}"]`);
        box.classList.add('answered');
    }
});

function nextQuestion() {
    if (currentQuestion < totalQuestions - 1) {
        currentQuestion++;
        showQuestion(currentQuestion);
    }
}

function previousQuestion() {
    if (currentQuestion > 0) {
        currentQuestion--;
        showQuestion(currentQuestion);
    }
}

// Initialize the first question
showQuestion(0);