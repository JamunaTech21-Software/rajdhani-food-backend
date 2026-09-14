<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/tests', __DIR__ . '/database/seeders'])
    ->append([__DIR__ . '/bin/migrate.php', __DIR__ . '/bin/seed.php', __DIR__ . '/bin/openapi.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                       => true,
        'declare_strict_types'         => true,
        'strict_param'                 => true,
        'array_syntax'                 => ['syntax' => 'short'],
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'            => true,
        'trailing_comma_in_multiline'  => true,
        'single_quote'                 => true,
    ])
    ->setFinder($finder);
