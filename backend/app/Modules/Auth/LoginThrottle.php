<?php
declare(strict_types=1);
namespace App\Modules\Auth;

// Compatibility alias while existing callers are migrated to the layered namespace.
class_alias(\App\Modules\Auth\Infrastructure\LoginThrottle::class, __NAMESPACE__ . '\\LoginThrottle');
