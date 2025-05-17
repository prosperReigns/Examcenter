document.getElementById('questionType').addEventListener('change', function() {
    const type = this.value;
    const allOptions = [
        'multipleChoiceOptions',
        'trueFalseOptions',
        'shortAnswerOptions',
        'longAnswerOptions',
        'fillBlanksOptions'
    ];
    
    // Hide all option divs
    allOptions.forEach(opt => {
        document.getElementById(opt).style.display = 'none';
    });
    
    // Show relevant options based on type
    switch(type) {
        case 'multiple_choice_single':
            document.getElementById('multipleChoiceOptions').style.display = 'block';
            document.getElementById('singleAnswerSelect').style.display = 'block';
            document.getElementById('multipleAnswerSelect').style.display = 'none';
            break;
        case 'multiple_choice_multiple':
            document.getElementById('multipleChoiceOptions').style.display = 'block';
            document.getElementById('singleAnswerSelect').style.display = 'none';
            document.getElementById('multipleAnswerSelect').style.display = 'block';
            break;
        case 'true_false':
            document.getElementById('trueFalseOptions').style.display = 'block';
            break;
        case 'short_answer':
            document.getElementById('shortAnswerOptions').style.display = 'block';
            break;
        case 'long_answer':
            document.getElementById('longAnswerOptions').style.display = 'block';
            break;
        case 'fill_blanks':
            document.getElementById('fillBlanksOptions').style.display = 'block';
            break;
    }
});

// Initialize form display
document.getElementById('questionType').dispatchEvent(new Event('change'));