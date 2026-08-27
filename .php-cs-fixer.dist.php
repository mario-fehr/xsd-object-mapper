<?php

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PHP8x4Migration' => true,
        '@Symfony' => true,
    ])
    ->setFinder((new PhpCsFixer\Finder())
        ->in(__DIR__)
    )
;
