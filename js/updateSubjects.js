document.addEventListener('DOMContentLoaded', function() {
    const classSelect = document.querySelector('select[name="selected_class"]');
    const subjectSelect = document.querySelector('select[name="selected_subject"]');
    
    // Get the subjects from the window object, with fallback to empty arrays
    const jssSubjects = window.jssSubjects || [];
    const ssSubjects = window.ssSubjects || [];
    
    function updateSubjects() {
        const selectedClass = classSelect.value;
        // Clear and reset the subject dropdown
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        
        let subjects = [];
        // Determine which subject list to use based on selected class
        if (selectedClass.startsWith('JSS')) {
            subjects = jssSubjects;
        } else if (selectedClass.startsWith('SS')) {
            subjects = ssSubjects;
        }
        
        // Add the subjects to the dropdown
        subjects.forEach(subject => {
            const option = document.createElement('option');
            option.value = subject;
            option.textContent = subject;
            subjectSelect.appendChild(option);
        });
    }
    
    // Add event listener for class selection change
    if (classSelect) {
        classSelect.addEventListener('change', updateSubjects);
        
        // Initial update if class is already selected
        if (classSelect.value) {
            updateSubjects();
        }
    }
});