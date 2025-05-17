// handle edit question
function editQuestion() {
    if (!questions.length) return;
    
    const question = questions[currentQuestionIndex];
    
    // Fill the form with current question data
    document.querySelector('textarea[name="question"]').value = question.question_text;
    document.querySelector('input[name="option1"]').value = question.option1;
    document.querySelector('input[name="option2"]').value = question.option2;
    document.querySelector('input[name="option3"]').value = question.option3;
    document.querySelector('input[name="option4"]').value = question.option4;
    document.querySelector('select[name="correct_answer"]').value = question.correct_answer;
    
    // Scroll to the form
    document.querySelector('.container-lg').scrollIntoView({ behavior: 'smooth' });
}