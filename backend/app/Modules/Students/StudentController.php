<?php
declare(strict_types=1);
namespace App\Modules\Students;

// Compatibility bridge while in-repository references are migrated.
class_alias(\App\Modules\Students\Http\StudentController::class, __NAMESPACE__ . '\\StudentController');
