const QuestionTypeHandler = {
    init: function() {
        this.questionTypeSelect = document.getElementById('questionType');
        this.optionsContainer = document.getElementById('optionsContainer');
        this.setupEventListeners();
        this.initializeQuestionType();
    },

    setupEventListeners: function() {
        if (this.questionTypeSelect) {
            this.questionTypeSelect.addEventListener('change', () => this.updateOptionsContainer());
            
            // Handle form reset
            const questionForm = document.getElementById('questionForm');
            if (questionForm) {
                questionForm.addEventListener('reset', () => {
                    setTimeout(() => {
                        this.initializeQuestionType();
                    }, 0);
                });
            }
        }
    },

    initializeQuestionType: function() {
        if (this.questionTypeSelect) {
            if (!this.questionTypeSelect.value) {
                this.questionTypeSelect.value = 'multiple_choice_single';
            }
            this.updateOptionsContainer();
        }
    },

    updateOptionsContainer: function() {
        if (this.optionsContainer && this.questionTypeSelect) {
            const questionType = this.questionTypeSelect.value || 'multiple_choice_single';
            this.optionsContainer.innerHTML = questionTemplates[questionType] || '';
        }
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    QuestionTypeHandler.init();
});