<?php

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PHP81Migration' => true,
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'strict_comparison' => true,
        'strict_param' => true,
    ])
    ->setFinder(PhpCsFixer\Finder::create()->in([__DIR__ . '/trakk', __DIR__ . '/test'])->exclude('data'));
