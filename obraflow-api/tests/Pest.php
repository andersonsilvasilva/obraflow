<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// Tudo que toca o banco roda com migrate:fresh no 'obraflow_testing' + transação por teste.
pest()->use(RefreshDatabase::class)->in(
    'Feature/Database',
    'Feature/Models',
    'Feature/Auth',
    'Feature/Tenancy',
    'Feature/Access',
    'Feature/Audit',
    'Feature/Provisioning',
    'Feature/Registry',
);
