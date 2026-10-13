<?php
declare(strict_types=1);

namespace App\Modules\Assessment;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Principal-reviewed selection of a source-declared formative method. */
final readonly class FormativeSchemeService
{
    public function __construct(private PDO $db) {}

    private function one(string $sql,array $params): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function principal(AuthContext $actor): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED','Principal review required.',403);
        }
    }

    public function configure(AuthContext $actor,int $institute,int $yearId,
        int $sourceId,string $method,string $reviewReference): void
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'formative.template.manage',function () use (
            $actor,$institute,$yearId,$sourceId,$method,$reviewReference,$tx
        ): void {
            $year=$this->one('SELECT id,curriculum_year_id,formative_method
                FROM batch_years WHERE id=? AND institute_id=? FOR UPDATE',[$yearId,$institute]);
            if (!$year) throw new HttpException('NOT_FOUND','Batch course year not found.',404);
            if ($year['formative_method']!=='unconfigured') {
                throw new HttpException('FORMATIVE_SCHEME_FROZEN',
                    'Scheme is already selected; use a reviewed correction rather than replacing it.',409);
            }
            $source=$this->one('SELECT d.id,d.status,d.sha256 FROM regulatory_sources r
                JOIN global_regulatory_documents d ON d.id=r.global_document_id
                WHERE r.id=? AND r.institute_id=? FOR UPDATE',[$sourceId,$institute]);
            if (!$source || $source['status']!=='published' || !$source['sha256']) {
                throw new HttpException('FORMATIVE_SOURCE_REQUIRED',
                    'Reviewed institute-linked official formative source required.',409);
            }
            if ($method==='points_ratio') {
                $q=$this->db->prepare('SELECT COUNT(*) FROM learning_outcomes
                    WHERE curriculum_year_id=? AND institute_id=?');
                $q->execute([$year['curriculum_year_id'],$institute]);
                if ((int)$q->fetchColumn()===0) {
                    throw new HttpException('FORMATIVE_LO_MISSING',
                        'Official learning outcomes must be linked before points-ratio configuration.',409);
                }
            }
            $scoreA=$this->one('SELECT id FROM formative_assessments WHERE batch_year_id=?
                AND institute_id=? LIMIT 1 FOR UPDATE',[$yearId,$institute]);
            $scoreB=$this->one('SELECT item_code FROM formative_template_scores
                WHERE batch_year_id=? AND institute_id=? LIMIT 1 FOR UPDATE',[$yearId,$institute]);
            if ($scoreA || $scoreB) {
                throw new HttpException('FORMATIVE_SCORES_EXIST',
                    'Method cannot be configured after a formative record exists.',409);
            }
            $this->db->prepare('UPDATE batch_years SET formative_method=?,
                formative_scheme_source_id=?,formative_scheme_reference=?
                WHERE id=? AND institute_id=? AND formative_method=\'unconfigured\'')
                ->execute([$method,$sourceId,$reviewReference,$yearId,$institute]);
            $tx->audit($actor,$institute,'formative.scheme.configure','batch_years',$yearId,[
                'formative_method'=>$method,'source_id'=>$sourceId,
                'review_reference'=>$reviewReference
            ]);
        });
    }

    /** Item weights are source-supplied; they cannot be changed once used. */
    public function item(AuthContext $actor,int $institute,int $yearId,string $code,
        string $description,string $max,string $weight,string $review): void
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        try {
            $tx->run($actor,$institute,'formative.template.manage',function () use (
                $actor,$institute,$yearId,$code,$description,$max,$weight,$review,$tx
            ): void {
                $year=$this->one('SELECT formative_method,formative_scheme_source_id
                    FROM batch_years WHERE id=? AND institute_id=? FOR UPDATE',[$yearId,$institute]);
                if (!$year || $year['formative_method']!=='template_weighted'
                    || $year['formative_scheme_source_id']===null) {
                    throw new HttpException('FORMATIVE_TEMPLATE_NOT_CONFIGURED',
                        'Reviewed template-weighted method required.',409);
                }
                $source=$this->one('SELECT d.status,d.sha256 FROM regulatory_sources r
                    JOIN global_regulatory_documents d ON d.id=r.global_document_id
                    WHERE r.id=? AND r.institute_id=? FOR UPDATE',
                    [$year['formative_scheme_source_id'],$institute]);
                if (!$source || $source['status']!=='published' || !$source['sha256']) {
                    throw new HttpException('FORMATIVE_SOURCE_REQUIRED','Current published official source required.',409);
                }
                if ($this->one('SELECT item_code FROM formative_template_scores
                    WHERE batch_year_id=? AND institute_id=? LIMIT 1 FOR UPDATE',[$yearId,$institute])) {
                    throw new HttpException('FORMATIVE_TEMPLATE_USED',
                        'New template items require reviewed versioning after scores exist.',409);
                }
                $q=$this->db->prepare('SELECT COALESCE(SUM(weight_percent),0)
                    FROM formative_template_items WHERE batch_year_id=? AND institute_id=?');
                $q->execute([$yearId,$institute]);
                // 4-decimal precision matches the canonical database column.
                $existing=(int)round(((float)$q->fetchColumn())*10000);
                $new=(int)round(((float)$weight)*10000);
                if ($existing+$new>1000000) {
                    throw new HttpException('FORMATIVE_WEIGHTS',
                        'Template weights cannot exceed 100 percent.',409);
                }
                $this->db->prepare('INSERT INTO formative_template_items
                    (institute_id,batch_year_id,item_code,description,max_marks,weight_percent)
                    VALUES (?,?,?,?,?,?)')->execute([
                        $institute,$yearId,$code,$description,$max,$weight
                    ]);
                $tx->audit($actor,$institute,'formative.template.item.add',
                    'formative_template_items',$yearId,[
                        'item_code'=>$code,'max_marks'=>$max,'weight_percent'=>$weight,
                        'review_reference'=>$review
                    ]);
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('FORMATIVE_ITEM_EXISTS',
                    'Template item code already exists in this course year.',409);
            }
            throw $e;
        }
    }
}
