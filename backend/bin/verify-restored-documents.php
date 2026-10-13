<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';

use App\Core\Database;
use App\Core\PrivateFiles;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Documents\DocumentService;

// Run in the restore-worker container as --user 33:33, not as root.
// Exercise the application's real private-file and document download paths.
if (PHP_SAPI!=='cli' || getenv('RESTORE_DRILL_ONLY')!=='1'
    || getenv('DB_DATABASE')!=='iti_restore_drill' || !is_writable('/var/lib/iti-private/documents')
    || !is_writable('/var/lib/iti-private/outbox')) {
    fwrite(STDERR,"Restored private volumes are not application-writable.\n"); exit(1);
}
try {
    $db=(new Database(require dirname(__DIR__).'/config/database.php'))->connection();
    $files=new PrivateFiles('/var/lib/iti-private/documents');
    $service=new DocumentService($db,$files);
    $checked=0; $downloaded=0;
    $sql="SELECT d.*, NOT EXISTS (
      SELECT 1 FROM institute_activity_log a WHERE a.institute_id=d.institute_id
        AND a.action_code='document.archive' AND a.entity_table='private_documents' AND a.entity_id=d.id
    ) AS is_active FROM private_documents d";
    foreach($db->query($sql) as $doc) {
        $bytes=$files->read($doc['storage_key']);
        if (strlen($bytes)!==(int)$doc['byte_size'] || !hash_equals($doc['sha256'],hash('sha256',$bytes))) {
            throw new RuntimeException('Restored document integrity check failed.');
        }
        $checked++;
        if ((int)$doc['is_active']===1) {
            $actor=new AuthContext((int)$doc['uploaded_by'],(int)$doc['institute_id'],0,
                'restore-read-check',false,['administrator'],['document.view'],null,null);
            $download=$service->download($actor,(int)$doc['id'],false);
            if (!hash_equals(hash('sha256',$bytes),hash('sha256',$download['bytes']))) {
                throw new RuntimeException('DocumentService download does not match restored bytes.');
            }
            $downloaded++;
        }
    }
    echo json_encode(['app_user_read_verified'=>true,'document_objects'=>$checked,'active_app_downloads'=>$downloaded],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,'Restored application document check failed: '.$error->getMessage().PHP_EOL); exit(1);
}
