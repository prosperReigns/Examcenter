-- Examcenter migration: combined objective + theory marks can be fractional.
-- Apply once to the existing CBT database before deploying this feature.
ALTER TABLE results
    MODIFY COLUMN score DECIMAL(10,2) NULL,
    MODIFY COLUMN total_questions DECIMAL(10,2) NULL;
