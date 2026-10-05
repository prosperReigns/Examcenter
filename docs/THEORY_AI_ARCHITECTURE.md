# Offline AI Theory Examination System

Teachers create written questions and set maximum marks. No model answer, keyword list, answer dictionary or marking scheme is required. Students submit written answers locally. A local Ollama model evaluates relevance, factual correctness, completeness and understanding, awards partial credit, and explains the mark.

AI marks are provisional and can be reviewed by a teacher. Scores are always validated and clamped to 0..maximum marks.

Default local runtime:
- Ollama
- llama3.2:3b
- nomic-embed-text (optional for future local RAG)
- http://127.0.0.1:11434

Lifecycle: teacher creates assessment -> student answers -> local submission -> local grading run -> structured validation -> provisional marks -> total -> teacher review.

Ollama/internet failure never destroys the submission. It remains saved for later grading.

AI theory is a Pro feature, but Core CBT remains usable if Pro is unavailable.

## Implemented local workflow

1. A teacher opens Manage Tests → AI Theory for an existing test.
2. The teacher creates the theory assessment and enters only question text + maximum marks.
3. A student uses the dedicated theory registration/exam page and writes answers locally.
4. Submission stores answers in MariaDB and does not contact the internet.
5. A teacher starts Run local AI grading. Each answer is sent only to the configured local Ollama instance (127.0.0.1:11434) using llama3.2:3b by default.
6. The grading service clamps every AI mark to 0..maximum_marks before persistence.
7. AI marks are stored as ai_provisional; explanations and grading dimensions are retained for review.
8. Teachers can replace a provisional mark with a final mark. The original mark, final mark and reason are recorded in theory_manual_reviews.
9. Students can view the locally stored provisional/final theory result.

The existing MCQ examination flow is separate and is not blocked by the theory workflow or by local AI availability.
