<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';

$dsn=getenv('TEST_DB_DSN');
if (!$dsn) throw new RuntimeException('Disposable TEST_DB_DSN required.');
$db=new PDO($dsn,getenv('TEST_DB_USERNAME')?:'root',getenv('TEST_DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$suffix=bin2hex(random_bytes(6));
$database='iti_runtime_'.$suffix;
$username='runtime_'.$suffix;
$password=bin2hex(random_bytes(24));
$account=$db->quote($username)."@'%'";
$scope='`'.str_replace('_','\\_',$database).'`.*';
preg_match('/host=([^;]+)/',$dsn,$host);
preg_match('/port=([^;]+)/',$dsn,$port);
$checks=0;
$run=static function (int $expected) use ($database,$username,$password,$host,$port,&$checks): void {
    $environment=[...getenv(),'DB_HOST'=>$host[1]??'127.0.0.1','DB_PORT'=>$port[1]??'3306',
        'DB_DATABASE'=>$database,'DB_USERNAME'=>$username,'DB_PASSWORD'=>$password];
    $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/verify-runtime-grants.php'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
    if (!is_resource($process)) throw new RuntimeException('Grant verifier unavailable.');
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==$expected) throw new RuntimeException('Runtime grant policy returned unexpected exit status.');
    if (str_contains($output,$password) || str_contains($output,'IDENTIFIED BY') || str_contains($output,'IDENTIFIED VIA')) {
        throw new RuntimeException('Grant verification leaked credentials.');
    }
    $checks++;
};
$db->exec("CREATE DATABASE `$database`");
try {
    $db->exec("CREATE USER $account IDENTIFIED BY ".$db->quote($password));
    $db->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON $scope TO $account");
    $run(0);
    $db->exec("GRANT CREATE ON $scope TO $account");
    $run(1);
    $db->exec("REVOKE CREATE ON $scope FROM $account");
    $db->exec("GRANT SELECT ON mysql.* TO $account");
    $run(1);
    $db->exec("REVOKE SELECT ON mysql.* FROM $account");
    $db->exec("REVOKE ALL PRIVILEGES,GRANT OPTION FROM $account");
    $db->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$database`.* TO $account");
    $run(1); // Unescaped underscores must not silently grant sibling databases.
    echo "Runtime grants integration passed ($checks checks).\n";
} finally {
    $db->exec("DROP USER IF EXISTS $account");
    $db->exec("DROP DATABASE `$database`");
}
