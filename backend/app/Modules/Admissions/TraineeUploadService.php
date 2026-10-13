<?php
declare(strict_types=1);
namespace App\Modules\Admissions;

use App\Http\HttpException;
use App\Modules\Auth\AuthContext;
use PDO;

/** Produces a validated local payload. Does not authenticate to or submit to a government portal. */
final readonly class TraineeUploadService
{
    public function __construct(private PDO $db) {}

    public function prepare(AuthContext $actor,int $institute,int $enrollment): array
    {
        $actor->requireInstitute($institute);
        $actor->requirePermission('enrollment.manage');
        $q=$this->db->prepare('SELECT e.*,s.full_name,s.date_of_birth,s.father_name,s.mother_name,s.gender,s.mobile,s.email,s.category_code,s.is_ews,s.disability_percent,s.pwd_category,q.level_code,i.mis_code,c.session_code,c.upload_profile_json,c.status calendar_status,d.status source_status,u.training_mode,t.unit_no,m.portal_shift_no,r.official_name FROM enrollments e JOIN students s ON s.id=e.student_id AND s.institute_id=e.institute_id JOIN student_qualifications q ON q.id=e.qualification_id AND q.student_id=e.student_id AND q.institute_id=e.institute_id JOIN batches b ON b.id=e.batch_id AND b.institute_id=e.institute_id JOIN institute i ON i.id=e.institute_id JOIN cts_training_calendars c ON c.id=b.calendar_id JOIN global_regulatory_documents d ON d.id=c.source_document_id JOIN trade_unit_shifts u ON u.id=b.trade_unit_shift_id AND u.institute_id=b.institute_id JOIN trade_units t ON t.id=u.trade_unit_id AND t.institute_id=u.institute_id JOIN seat_matrix_entries m ON m.id=b.seat_matrix_entry_id AND m.institute_id=b.institute_id JOIN trade_master_revisions r ON r.trade_id=b.trade_id AND r.status=\'active\' WHERE e.id=? AND e.institute_id=?');
        $q->execute([$enrollment,$institute]);
        $row=$q->fetch(PDO::FETCH_ASSOC) ?: throw new HttpException('NOT_FOUND','Trainee export candidate not found.',404);
        if ($row['status']!=='active' || $row['admission_route']==='unverified' || $row['calendar_status']!=='published' || $row['source_status']!=='published' || !$row['upload_profile_json']) {
            throw new HttpException('EXPORT_UNVERIFIED','Verified enrollment and reviewed calendar upload profile required.',409);
        }
        $profile=json_decode($row['upload_profile_json'],true,32,JSON_THROW_ON_ERROR);
        if (!is_array($profile)) {
            throw new HttpException('EXPORT_PROFILE','Reviewed export profile is invalid.',409);
        }
        $map=static fn(string $key,mixed $value): mixed => $profile[$key][(string)$value]??null;
        $payload=[
            'StateRegNumber'=>$row['state_registration_number'],
            'TraineeName'=>$row['full_name'],'DateOfBirth'=>$row['date_of_birth'],
            'Gender'=>$map('gender_map',$row['gender']),'Category'=>$map('category_map',$row['category_code']),
            'FatherGuardianName'=>$row['father_name'],'MotherName'=>$row['mother_name'],
            'MobileNumber'=>$row['mobile'],'EmailID'=>$row['email'],'Session'=>$row['session_code'],
            'AdmissionDate'=>$row['admitted_on'],'HighestQualification'=>$map('qualification_map',$row['level_code']),
            'Trade'=>$row['official_name'],'Shift'=>(string)$row['portal_shift_no'],'Unit'=>(string)$row['unit_no'],
            'IsTraineeDualMode'=>$row['training_mode']==='DST'?'yes':'no','MISITICode'=>$row['mis_code'],
            'PersonwithDisability'=>$row['disability_percent']===null?null:((float)$row['disability_percent']>0?'yes':'no'),
            'PWDcategory'=>$row['pwd_category'],
            'EconomicWeakerSection'=>$row['is_ews']===null?null:((int)$row['is_ews']?'yes':'no'),
            'TraineeType'=>$map('trainee_type_map',$row['trainee_type']),
        ];
        $errors=TraineeUploadValidator::errors($payload,$profile);
        if ($errors) {
            throw new HttpException('EXPORT_VALIDATION','Export validation failed: '.implode(', ',array_keys($errors)),422);
        }
        return $payload;
    }
}
