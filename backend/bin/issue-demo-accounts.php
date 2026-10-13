<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
if (PHP_SAPI!=='cli' || getenv('UAT_DEMO_SEED')!=='1') exit(1);
try {
    $db=(new App\Core\Database(require dirname(__DIR__).'/config/database.php'))->connection();
    // Credential recovery is allowed only for the exact isolated demo fixture.
    if ((int)$db->query('SELECT COUNT(*) FROM institute')->fetchColumn()!==2 ||
        (int)$db->query("SELECT COUNT(*) FROM institute WHERE mis_code IN ('LOCAL-TEST-A','LOCAL-TEST-B')")->fetchColumn()!==2 ||
        (int)$db->query('SELECT COUNT(*) FROM auth_users')->fetchColumn()!==6) {
        throw new RuntimeException('Exact demo fixture required.');
    }
    $accounts=[]; $db->beginTransaction();
    foreach (['developer','instructor','student','other-admin','principal','administrator'] as $role) {
        $name='demo-'.$role;
        $find=$db->prepare('SELECT id,institute_id FROM auth_users WHERE username=? FOR UPDATE'); $find->execute([$name]);
        $user=$find->fetch(); if (!$user) throw new RuntimeException('Demo identity missing.');
        $password=bin2hex(random_bytes(16));
        $db->prepare('UPDATE auth_users SET password_hash=?,must_change_password=0,failed_attempts=0,locked_until=NULL WHERE id=?')->execute([password_hash($password,PASSWORD_ARGON2ID),$user['id']]);
        $db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL')->execute([$user['id']]);
        $accounts[$role]=['username'=>$name,'password'=>$password,'institute_id'=>$user['institute_id']===null?null:(int)$user['institute_id']];
    }
    $db->commit();
    echo json_encode(['demo_only'=>true,'accounts'=>$accounts],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $error) {
    if(isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'Demo credential issue refused: '.$error->getMessage().PHP_EOL); exit(1);
}
