<?php

declare(strict_types=1);

arch('it does not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'print_r'])
    ->each->not->toBeUsed();

arch('source files use strict types')
    ->expect('Syriable\UserPresence')
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('Syriable\UserPresence\Contracts')
    ->toBeInterfaces();

arch('data objects are final and readonly')
    ->expect('Syriable\UserPresence\Data')
    ->toBeFinal()
    ->toBeReadonly();

arch('events are final and readonly')
    ->expect('Syriable\UserPresence\Events')
    ->toBeFinal()
    ->toBeReadonly();

arch('enums are backed enums')
    ->expect('Syriable\UserPresence\Enums')
    ->toBeStringBackedEnums();

arch('the package does not depend on optional frontend or admin integrations')
    ->expect('Syriable\UserPresence')
    ->not->toUse(['Livewire', 'Filament', 'Inertia']);

arch('services do not depend on HTTP controllers')
    ->expect('Syriable\UserPresence\Services')
    ->not->toUse('Syriable\UserPresence\Http');
