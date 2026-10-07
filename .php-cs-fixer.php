<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,

        'array_syntax' => [
            'syntax' => 'short',
        ],

        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],

        'blank_line_after_opening_tag' => true,

        'braces_position' => [
            'allow_single_line_anonymous_functions' => true,
            'allow_single_line_closures' => true,
        ],

        'concat_space' => [
            'spacing' => 'one',
        ],

        'no_extra_blank_lines' => true,

        'no_whitespace_in_blank_line' => true,

        'single_quote' => true,

        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
    ])
    ->setFinder($finder);