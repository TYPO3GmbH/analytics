<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/Classes',
        __DIR__ . '/Tests',
        __DIR__ . '/Configuration',
    ])
    ->exclude(['vendor', '.build']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
