<?php

// See: https://cs.symfony.com/doc/rules/index.html


$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . "/src",
        __DIR__ . "/tests",
    ]);

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PER-CS' => true,
    ])
    ->setFinder($finder);
