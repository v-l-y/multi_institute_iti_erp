<?php
declare(strict_types=1);
namespace App\Modules\Fees;

// Compatibility bridge while in-repository references are migrated.
class_alias(\App\Modules\Fees\Application\FeeService::class, __NAMESPACE__ . '\\FeeService');
