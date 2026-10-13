<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';

// Operator-only worker. Plaintext stays on an ephemeral tmpfs, not the host.
function command(array $arguments,?string $input=null,?string $output=null,?string $inputFile=null): void
{
    $descriptors=[0=>$inputFile!==null?['file',$inputFile,'r']:['pipe','r'],1=>$output!==null?['file',$output,'w']:['file','/tmp/command-output','w'],2=>['file','/tmp/command-errors','w']];
    $process=proc_open($arguments,$descriptors,$pipes,null,[...getenv(),'MYSQL_PWD'=>(string)getenv('DB_PASSWORD')]);
    if (!is_resource($process)) throw new RuntimeException('Backup subprocess unavailable.');
    if ($inputFile===null) { if ($input!==null) fwrite($pipes[0],$input); fclose($pipes[0]); }
    if (proc_close($process)!==0) throw new RuntimeException('Backup subprocess failed: '.$arguments[0]);
}
function counts(PDO $db): array
{
    $counts=[];
    foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-z0-9_]+$/D',$table)) throw new RuntimeException('Unexpected table name.');
        $counts[$table]=(int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }
    ksort($counts); return $counts;
}
function fileHashes(string $root): array
{
    $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file) {
        if ($file->isLink() || !$file->isFile()) throw new RuntimeException('Unsupported private storage entry.');
        $relative=substr($file->getPathname(),strlen($root)+1);
        if (!preg_match('#^(documents|outbox)/[a-f0-9]{32,64}(?:\.json)?$#D',$relative)) throw new RuntimeException('Unexpected private object path.');
        $files[$relative]=hash_file('sha256',$file->getPathname());
    }
    ksort($files); return $files;
}
if (PHP_SAPI!=='cli') exit(1);
umask(0077);
try {
    $request=json_decode(stream_get_contents(STDIN,65537),true,16,JSON_THROW_ON_ERROR);
    $secret=$request['secret']??''; $archive=$request['archive']??'';
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D',$secret) || !is_string($archive) || !preg_match('/^[a-z0-9-]{1,100}\.tar\.gpg$/D',$archive)) throw new RuntimeException('Valid operator key and archive name required.');
    $db=(new App\Core\Database(require dirname(__DIR__).'/config/database.php'))->connection();
    mkdir('/tmp/gnupg',0700,true); mkdir('/tmp/paired',0700,true);
    $archivePath='/backup/output/'.$archive;
    $gpg=['gpg','--batch','--yes','--pinentry-mode','loopback','--passphrase-fd','0'];
    $connection=['--host='.(string)getenv('DB_HOST'),'--port='.(getenv('DB_PORT')?:'3306'),'--user='.(string)getenv('DB_USERNAME')];
    $mode=$argv[1]??'';
    if ($mode==='--create') {
        if (file_exists($archivePath)) throw new RuntimeException('Backup archive already exists.');
        command(['mariadb-dump',...$connection,'--single-transaction','--quick','--routines','--events','--triggers','--skip-add-drop-table',(string)getenv('DB_DATABASE')],output:'/tmp/paired/database.sql');
        command(['cp','-a','/var/lib/iti-private','/tmp/paired/private']);
        $manifest=['version'=>1,'database_counts'=>counts($db),'trigger_count'=>(int)$db->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn(),
            'private_hashes'=>fileHashes('/tmp/paired/private'),'sql_sha256'=>hash_file('sha256','/tmp/paired/database.sql')];
        file_put_contents('/tmp/paired/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
        command(['tar','-cf','/tmp/bundle.tar','-C','/tmp/paired','database.sql','manifest.json','private']);
        command([...$gpg,'--cipher-algo','AES256','--symmetric','--output',$archivePath.'.partial','/tmp/bundle.tar'],$secret."\n");
        if (!rename($archivePath.'.partial',$archivePath)) throw new RuntimeException('Archive finalization failed.');
        $digest=hash_file('sha256',$archivePath);
        file_put_contents($archivePath.'.sha256',$digest."\n");
        echo json_encode(['archive'=>$archive,'sha256'=>$digest,'table_count'=>count($manifest['database_counts']),'private_file_count'=>count($manifest['private_hashes'])],JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($mode==='--restore') {
        if (getenv('RESTORE_DRILL_ONLY')!=='1' || getenv('DB_DATABASE')!=='iti_restore_drill' || counts($db)!==[]) throw new RuntimeException('Empty isolated restore-drill database required.');
        if (is_dir('/var/lib/iti-private') && (new FilesystemIterator('/var/lib/iti-private',FilesystemIterator::SKIP_DOTS))->valid()) throw new RuntimeException('Empty restore private volume required.');
        $digest=trim((string)file_get_contents($archivePath.'.sha256'));
        if (!preg_match('/^[a-f0-9]{64}$/D',$digest) || !hash_equals($digest,hash_file('sha256',$archivePath))) throw new RuntimeException('Encrypted archive checksum mismatch.');
        command([...$gpg,'--decrypt','--output','/tmp/bundle.tar',$archivePath],$secret."\n");
        $tar=new PharData('/tmp/bundle.tar');
        foreach(new RecursiveIteratorIterator($tar,RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $relative=substr($entry->getPathname(),strlen('phar:///tmp/bundle.tar/'));
            if ($entry->isLink() || !preg_match('#^(database\.sql|manifest\.json|private/?|private/(documents|outbox)/?|private/(documents|outbox)/[a-f0-9]{32,64}(?:\.json)?)$#D',$relative)) throw new RuntimeException('Unsafe backup archive entry.');
        }
        command(['tar','-xf','/tmp/bundle.tar','-C','/tmp/paired','--no-same-owner']);
        $manifest=json_decode(file_get_contents('/tmp/paired/manifest.json'),true,64,JSON_THROW_ON_ERROR);
        if (($manifest['version']??null)!==1 || !hash_equals($manifest['sql_sha256'],hash_file('sha256','/tmp/paired/database.sql')) || $manifest['private_hashes']!==fileHashes('/tmp/paired/private')) throw new RuntimeException('Backup payload integrity mismatch.');
        command(['mariadb',...$connection,(string)getenv('DB_DATABASE')],inputFile:'/tmp/paired/database.sql');
        command(['cp','-a','/tmp/paired/private/.','/var/lib/iti-private/']);
        if ($manifest['database_counts']!==counts($db) || $manifest['private_hashes']!==fileHashes('/var/lib/iti-private') || $manifest['trigger_count']!==(int)$db->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn()) throw new RuntimeException('Restored data/count/hash verification failed.');
        foreach($db->query('SELECT storage_key,byte_size,sha256 FROM private_documents') as $document) {
            if (!preg_match('/^[a-f0-9]{32}$/D',$document['storage_key'])) throw new RuntimeException('Invalid restored storage key.');
            $path='/var/lib/iti-private/documents/'.$document['storage_key'];
            if (!is_file($path) || filesize($path)!==(int)$document['byte_size'] || !hash_equals($document['sha256'],hash_file('sha256',$path))) throw new RuntimeException('Restored document does not match database metadata.');
        }
        echo json_encode(['restored'=>true,'table_count'=>count($manifest['database_counts']),'private_file_count'=>count($manifest['private_hashes']),'trigger_count'=>$manifest['trigger_count']],JSON_THROW_ON_ERROR).PHP_EOL;
    } else throw new RuntimeException('Use --create or --restore.');
} catch(Throwable $error) {
    fwrite(STDERR,'Paired backup/restore failed: '.$error->getMessage().PHP_EOL); exit(1);
}
