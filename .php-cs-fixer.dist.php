<?php

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PHP8x4Migration' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'declare_strict_types' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder((new PhpCsFixer\Finder())
        ->in(__DIR__)
    )
;
