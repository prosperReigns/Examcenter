<?php
declare(strict_types=1);

/**
 * Runtime-neutral contract for local theory grading.
 * Implementations must never send student answers to a remote service.
 */
interface TheoryGraderInterface
{
    public function grade(string $question, float $max, string $answer): array;
}
