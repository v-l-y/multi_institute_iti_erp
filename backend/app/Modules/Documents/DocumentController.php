<?php
declare(strict_types=1);
namespace App\Modules\Documents;

use App\Core\Database;
use App\Core\PrivateFiles;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class DocumentController
{
    public function __construct(private Database $database) {}
    private function service(): DocumentService
    {
        $root=getenv('PRIVATE_DOCUMENT_ROOT');
        if (!$root) throw new HttpException('STORAGE_UNAVAILABLE','Private document storage is not configured.',503);
        return new DocumentService($this->database->connection(),new PrivateFiles($root));
    }
    private static function number(array $data,string $name,int $default=0,int $max=PHP_INT_MAX): int
    {
        $value=$data[$name]??$default;
        if ((!is_string($value) && !is_int($value)) || filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$default===0?0:1,'max_range'=>$max]])===false) throw new HttpException('INVALID_INPUT','Invalid '.$name.'.',422);
        return (int)$value;
    }
    public function upload(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('document.manage');
        $student=$request->body['student_id']??null; $name=$request->body['filename']??null; $encoded=$request->body['content_base64']??null;
        if (!is_int($student) || $student<1 || !is_string($name) || !is_string($encoded)) throw new HttpException('INVALID_INPUT','Student ID, filename and base64 content required.',422);
        $id=$this->service()->upload($actor,$student,$name,$encoded);
        return new Response(['success'=>true,'data'=>['document_id'=>$id]],201);
    }
    private function records(Request $request,AuthContext $actor,bool $own): Response
    {
        $actor->requirePermission($own?'document.view.own':'document.view');
        return new Response(['success'=>true,'data'=>$this->service()->list($actor,$own,
            self::number($request->query,'after_id'),self::number($request->query,'page_size',50,100))]);
    }
    public function index(Request $r,AuthContext $a): Response { return $this->records($r,$a,false); }
    public function own(Request $r,AuthContext $a): Response { return $this->records($r,$a,true); }
    private function content(Request $request,AuthContext $actor,bool $own): Response
    {
        $actor->requirePermission($own?'document.view.own':'document.view');
        $id=self::number($request->query,'document_id');
        if ($id<1) throw new HttpException('INVALID_INPUT','Document ID required.',422);
        $file=$this->service()->download($actor,$id,$own);
        $extension=match ($file['mime_type']) { 'application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg' };
        return new Response([],200,['Content-Type'=>$file['mime_type'],'Content-Disposition'=>'attachment; filename="document-'.$id.'.'.$extension.'"',
            'Content-Length'=>(string)strlen($file['bytes'])],$file['bytes']);
    }
    public function download(Request $r,AuthContext $a): Response { return $this->content($r,$a,false); }
    public function ownDownload(Request $r,AuthContext $a): Response { return $this->content($r,$a,true); }
    public function archive(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('document.manage');
        $id=$request->body['document_id']??null; $reason=$request->body['reason']??null;
        if (!is_int($id) || $id<1 || !is_string($reason)) throw new HttpException('INVALID_INPUT','Document ID and reason required.',422);
        $this->service()->archive($actor,$id,$reason);
        return new Response(['success'=>true,'data'=>['document_id'=>$id,'status'=>'archived']]);
    }
}
