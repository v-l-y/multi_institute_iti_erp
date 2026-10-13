<?php
declare(strict_types=1);
// Explicit isolated demo only. Refuses populated databases; never reseeds or resets.
require dirname(__DIR__).'/bootstrap/autoload.php';
if (PHP_SAPI!=='cli' || getenv('UAT_DEMO_SEED')!=='1') {
    fwrite(STDERR,"Explicit isolated demo seed authorization required.\n"); exit(1);
}
try {
    $db=(new App\Core\Database(require dirname(__DIR__).'/config/database.php'))->connection();
    foreach (['institute','auth_users','students'] as $table) {
        if ((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==0) {
            throw new RuntimeException('Demo seed requires an empty database; existing records are preserved.');
        }
    }
    $db->beginTransaction();
    $db->exec(file_get_contents(dirname(__DIR__).'/database/seeders/rbac_seed.sql'));
    $db->exec(file_get_contents(dirname(__DIR__).'/tests/fixtures/foundation.sql'));
    $db->exec("INSERT INTO staff(id,institute_id,employee_code,full_name,staff_category,designation,principal_assignment,joined_on) VALUES
      (9115,9101,'DEMO-PRINCIPAL','DEMO Principal A','non_teaching','Principal','principal','2026-01-01'),
      (9116,9101,'DEMO-ADMIN','DEMO Administrator A','non_teaching','Administrator','none','2026-01-01')");
    $accounts=[];
    foreach (['developer'=>[9201,null,null,null], 'instructor'=>[9202,9101,9101,null],
        'student'=>[9203,9101,null,9101], 'other-admin'=>[9204,9102,9102,null],
        'principal'=>[9215,9101,9115,null], 'administrator'=>[9216,9101,9116,null]] as $label=>$identity) {
        [$id,$institute,$staff,$student]=$identity;
        $username='demo-'.$label;
        $password=bin2hex(random_bytes(16));
        $hash=password_hash($password,PASSWORD_ARGON2ID);
        if ($id<9215) {
            $db->prepare('UPDATE auth_users SET username=?,password_hash=?,must_change_password=0 WHERE id=?')->execute([$username,$hash,$id]);
        } else {
            $db->prepare('INSERT INTO auth_users(id,institute_id,username,password_hash,must_change_password) VALUES (?,?,?,?,0)')->execute([$id,$institute,$username,$hash]);
            $db->prepare('INSERT INTO auth_staff_links(institute_id,user_id,staff_id) VALUES (?,?,?)')->execute([$institute,$id,$staff]);
            $db->prepare("INSERT INTO auth_user_roles(institute_id,user_id,role_id,role_code,scope,staff_link_user_id,valid_from)
              SELECT ?,?,id,code,scope,?,UTC_TIMESTAMP() FROM auth_roles WHERE code=?")->execute([$institute,$id,$id,$label]);
        }
        // Cross-institute demo user needs an actual role; the shared fixture only linked staff.
        if ($label==='other-admin') {
            $db->prepare("INSERT INTO auth_user_roles(institute_id,user_id,role_id,role_code,scope,staff_link_user_id,valid_from)
              SELECT ?,?,id,code,scope,?,UTC_TIMESTAMP() FROM auth_roles WHERE code='administrator'")->execute([$institute,$id,$id]);
        }
        $accounts[$label]=['username'=>$username,'password'=>$password,'institute_id'=>$institute];
    }
    $db->commit();
    // Capture stdout into the ignored operator file; never display it in logs.
    echo json_encode(['demo_only'=>true,'accounts'=>$accounts],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'Demo seed failed: '.$error->getMessage().PHP_EOL); exit(1);
}
