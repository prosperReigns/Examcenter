// handle delete question
function deleteQuestion() {
    if (!questions.length) return;
    
    if (confirm('Are you sure you want to delete this question?')) {
        const question = questions[currentQuestionIndex];
        
        // Create form data
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('question_id', question.id);
        
        // Send delete request
        fetch('delete_question.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove question from array
                questions.splice(currentQuestionIndex, 1);
                
                // Update display
                if (questions.length === 0) {
                    location.reload(); // Reload if no questions left
                } else {
                    if (currentQuestionIndex >= questions.length) {
                        currentQuestionIndex = questions.length - 1;
                    }
                    // Update total questions count
                    document.getElementById('totalQuestions').textContent = questions.length;
                    displayQuestion(currentQuestionIndex);
                }
            } else {
                alert('Error deleting question: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting question');
        });
    }
}