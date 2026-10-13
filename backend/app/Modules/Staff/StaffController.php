<?php
declare(strict_types=1);
namespace App\Modules\Staff;

// Compatibility bridge while in-repository references are migrated.
class_alias(\App\Modules\Staff\Http\StaffController::class, __NAMESPACE__ . '\\StaffController');
