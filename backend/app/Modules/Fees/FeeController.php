<?php
declare(strict_types=1);
namespace App\Modules\Fees;

// Compatibility bridge while in-repository references are migrated.
class_alias(\App\Modules\Fees\Http\FeeController::class, __NAMESPACE__ . '\\FeeController');
