<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use App\Modules\Auth\Infrastructure\PasswordHasher;

require dirname(__DIR__) . '/bootstrap/autoload.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
// Read credentials from stdin, never command-line arguments or a committed seed.
try {
    Environment::load(dirname(__DIR__) . '/.env');
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $username = $input['username'] ?? null;
    $password = $input['password'] ?? null;
    if (!is_string($username) || trim($username) === '' || strlen($username) > 120 || !is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new RuntimeException('Provide username and a password of 12–72 bytes as JSON on stdin.');
    }
    $db = (new Database(require dirname(__DIR__) . '/config/database.php'))->connection();
    $db->beginTransaction();
    $role = $db->query("SELECT id FROM auth_roles WHERE code='developer' AND scope='platform' FOR UPDATE")->fetchColumn();
    if ($role === false) {
        throw new RuntimeException('Import the schema and reviewed RBAC seeder first.');
    }
    if ($db->query("SELECT id FROM auth_users WHERE institute_id IS NULL LIMIT 1")->fetchColumn() !== false) {
        throw new RuntimeException('A platform account already exists; bootstrap is disabled.');
    }
    $statement = $db->prepare('INSERT INTO auth_users (username,password_hash,must_change_password) VALUES (?,?,1)');
    $statement->execute([trim($username), PasswordHasher::hash($password)]);
    $userId = $db->lastInsertId();
    $statement = $db->prepare("INSERT INTO auth_user_roles (user_id,role_id,role_code,scope,valid_from) VALUES (?,?,'developer','platform',UTC_TIMESTAMP())");
    $statement->execute([$userId, $role]);
    $statement = $db->prepare("INSERT INTO auth_audit_log (user_id,event_type,event_data_json,occurred_at) VALUES (?,'developer_bootstrapped','{}',UTC_TIMESTAMP())");
    $statement->execute([$userId]);
    $db->commit();
    echo "Developer created. Change the password after first login. No permissions were auto-granted.\n";
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception instanceof PDOException ? "Database operation failed.\n" : $exception->getMessage() . "\n");
    exit(1);
}
