<?php

namespace App\Modules\References\Console;

use Exception;

/** @internal Desfaz a transação da importação no --dry-run. */
final class DryRunRollback extends Exception {}
