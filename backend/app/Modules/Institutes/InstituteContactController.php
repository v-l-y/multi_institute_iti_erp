<?php
declare(strict_types=1);

namespace App\Modules\Institutes;

use App\Core\Database;
use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/**
 * Non-regulatory institute contact maintenance. The official name, MIS code,
 * affiliation status/address and recognized capacity are intentionally not
 * writable through this surface.
 */
final readonly class InstituteContactController
{
    public function __construct(private Database $database) {}

    public function update(Request $request, AuthContext $actor): Response
    {
        $actor->requirePermission('institute.profile.update');
        $institute=$actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute identity required.',403);
        $data=[];
        foreach (['contact_phone'=>20,'contact_email'=>190] as $key=>$max) {
            if (!array_key_exists($key,$request->body)) continue;
            $raw=$request->body[$key];
            if ($raw!==null && (!is_string($raw) || strlen(trim($raw))>$max)) {
                throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
            }
            $data[$key]=$raw===null || trim($raw)===''?null:trim($raw);
        }
        if (!$data) {
            throw new HttpException('INVALID_INPUT','Provide contact_phone or contact_email.',422);
        }
        if ($data['contact_email']??null) {
            if (filter_var($data['contact_email'],FILTER_VALIDATE_EMAIL)===false) {
                throw new HttpException('INVALID_INPUT','Invalid contact email.',422);
            }
        }
        if ($data['contact_phone']??null) {
            if (!preg_match('/^\\+?[0-9 ()-]{6,20}$/D',$data['contact_phone'])) {
                throw new HttpException('INVALID_INPUT','Invalid contact phone.',422);
            }
        }
        $db=$this->database->connection();
        $tx=new InstituteTransaction($db);
        $tx->run($actor,$institute,'institute.profile.update',function () use ($db,$actor,$institute,$data,$tx): void {
            $q=$db->prepare('SELECT contact_phone,contact_email FROM institute WHERE id=? FOR UPDATE');
            $q->execute([$institute]);
            $before=$q->fetch(PDO::FETCH_ASSOC);
            if ($before===false) throw new HttpException('NOT_FOUND','Institute not found.',404);
            $new=array_merge($before,$data);
            $db->prepare('UPDATE institute SET contact_phone=?,contact_email=? WHERE id=?')
                ->execute([$new['contact_phone'],$new['contact_email'],$institute]);
            $tx->audit($actor,$institute,'institute.contact.update','institute',$institute,
                ['fields'=>array_keys($data)]);
        });
        return new Response(['success'=>true,'data'=>['institute_id'=>$institute]]);
    }
}
