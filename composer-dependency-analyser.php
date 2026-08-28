<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return new Configuration()
    // Pinned only to raise symfony/validator's transitive floor past a trans() signature that
    // triggers a PHP 8.4 implicit-nullable-parameter deprecation below v3.5; never used directly.
    ->ignoreErrorsOnPackage('symfony/translation-contracts', [ErrorType::UNUSED_DEPENDENCY]);
