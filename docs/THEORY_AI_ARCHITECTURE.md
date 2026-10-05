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