<?php
declare(strict_types=1);
namespace App\Modules\Documents;

use App\Core\InstituteTransaction;
use App\Core\PrivateFiles;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

final readonly class DocumentService
{
    public function __construct(private PDO $db,private PrivateFiles $files) {}
    public function upload(AuthContext $actor,int $student,string $name,string $encoded): int
    {
        $actor->requirePermission('document.manage');
        $institute=$actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
        if ($name==='' || strlen($name)>190 || preg_match('/[\x00-\x1f\x7f\/\\\\]/',$name) || str_contains($name,'..') || strlen($encoded)>6990508) throw new HttpException('INVALID_DOCUMENT','Invalid filename or document size.',422);
        $bytes=base64_decode($encoded,true);
        if ($bytes===false || $bytes==='' || strlen($bytes)>5242880) throw new HttpException('INVALID_DOCUMENT','Valid base64 document of at most 5 MB required.',422);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime,['application/pdf','image/png','image/jpeg'],true)) throw new HttpException('INVALID_DOCUMENT','Only PDF, PNG and JPEG documents are supported.',422);
        $key=bin2hex(random_bytes(16)); $created=false;
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'document.manage',function () use ($actor,$institute,$student,$name,$bytes,$mime,$key,&$created,$tx): int {
                $q=$this->db->prepare('SELECT id FROM students WHERE id=? AND institute_id=? FOR UPDATE');
                $q->execute([$student,$institute]);
                if (!$q->fetchColumn()) throw new HttpException('NOT_FOUND','Student not found.',404);
                $this->files->write($key,$bytes); $created=true;
                $this->db->prepare('INSERT INTO private_documents (institute_id,student_id,original_name,mime_type,byte_size,sha256,storage_key,uploaded_by) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$institute,$student,$name,$mime,strlen($bytes),hash('sha256',$bytes),$key,$actor->userId]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'document.upload','private_documents',$id,['student_id'=>$student,'sha256'=>hash('sha256',$bytes)]);
                return $id;
            });
        } catch (\Throwable $error) {
            if ($created) $this->files->remove($key);
            throw $error;
        }
    }
    private function scope(AuthContext $actor,bool $own): array
    {
        $actor->requirePermission($own?'document.view.own':'document.view');
        $institute=$actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
        $student=$own?($actor->studentId??throw new HttpException('FORBIDDEN','Student identity required.',403)):null;
        return [$institute,$student];
    }
    private function activeSql(): string
    {
        return "NOT EXISTS (SELECT 1 FROM institute_activity_log a WHERE a.institute_id=d.institute_id AND a.action_code='document.archive' AND a.entity_table='private_documents' AND a.entity_id=d.id)";
    }
    public function list(AuthContext $actor,bool $own,int $after,int $size): array
    {
        [$institute,$student]=$this->scope($actor,$own);
        $sql='SELECT d.id,d.student_id,d.original_name,d.mime_type,d.byte_size,d.sha256,d.created_at FROM private_documents d WHERE d.institute_id=? AND d.id>? AND '.$this->activeSql();
        $args=[$institute,$after];
        if ($student!==null) { $sql.=' AND d.student_id=?'; $args[]=$student; }
        $sql.=' ORDER BY d.id LIMIT ?'; $args[]=$size;
        $q=$this->db->prepare($sql);
        foreach ($args as $i=>$value) $q->bindValue($i+1,$value,PDO::PARAM_INT);
        $q->execute(); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['documents'=>$rows,'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null];
    }
    public function download(AuthContext $actor,int $id,bool $own): array
    {
        [$institute,$student]=$this->scope($actor,$own);
        $sql='SELECT d.* FROM private_documents d WHERE d.id=? AND d.institute_id=? AND '.$this->activeSql();
        $args=[$id,$institute];
        if ($student!==null) { $sql.=' AND d.student_id=?'; $args[]=$student; }
        $q=$this->db->prepare($sql); $q->execute($args); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new HttpException('NOT_FOUND','Document not found.',404);
        $bytes=$this->files->read($row['storage_key']);
        if (strlen($bytes)!==(int)$row['byte_size'] || !hash_equals($row['sha256'],hash('sha256',$bytes))) throw new HttpException('DOCUMENT_INTEGRITY','Document integrity verification failed.',503);
        return ['bytes'=>$bytes,'mime_type'=>$row['mime_type']];
    }
    public function archive(AuthContext $actor,int $id,string $reason): void
    {
        $actor->requirePermission('document.manage');
        $institute=$actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
        if (trim($reason)==='' || strlen($reason)>1000) throw new HttpException('INVALID_INPUT','Archival reason required.',422);
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'document.manage',function () use ($actor,$id,$institute,$reason,$tx): void {
            $q=$this->db->prepare('SELECT d.id FROM private_documents d WHERE d.id=? AND d.institute_id=? AND '.$this->activeSql().' FOR UPDATE');
            $q->execute([$id,$institute]);
            if (!$q->fetchColumn()) throw new HttpException('NOT_FOUND','Document not found.',404);
            $tx->audit($actor,$institute,'document.archive','private_documents',$id,['reason'=>$reason]);
        });
    }
}
