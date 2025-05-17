let questions = window.questions || [];
let currentQuestionIndex = 0;

function displayQuestion(index) {
    if (!questions.length) return;
    
    const question = questions[index];
    const previewDiv = document.getElementById('questionPreview');
    const previewText = previewDiv.querySelector('.preview-text');
    const optionsDiv = previewDiv.querySelector('.options');
    
    previewText.textContent = question.question_text;
    document.getElementById('currentQuestionNum').textContent = index + 1;
    
    // Display options
    optionsDiv.innerHTML = '';
    ['option1', 'option2', 'option3', 'option4'].forEach((opt, i) => {
        const optionDiv = document.createElement('div');
        optionDiv.className = 'form-check mb-2';
        optionDiv.innerHTML = `
            <input class="form-check-input" type="radio" name="preview_answer" value="${i+1}" 
                   ${question.correct_answer == i+1 ? 'checked' : ''} disabled>
            <label class="form-check-label">${question[opt]}</label>
        `;
        optionsDiv.appendChild(optionDiv);
    });
}

function nextQuestion() {
    if (currentQuestionIndex < questions.length - 1) {
        currentQuestionIndex++;
        displayQuestion(currentQuestionIndex);
    }
}

function previousQuestion() {
    if (currentQuestionIndex > 0) {
        currentQuestionIndex--;
        displayQuestion(currentQuestionIndex);
    }
}

// Initialize preview if questions exist
if (questions.length > 0) {
    displayQuestion(0);
}