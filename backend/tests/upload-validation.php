<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use App\Modules\Admissions\Domain\TraineeUploadValidator;
use App\Modules\Fees\Money;
use App\Http\HttpException;

// Fictional upload profile. No production official values are seeded by this test.
$profile=['genders'=>['Female'],'categories'=>['LOCAL'],'qualifications'=>['10th'],'trainee_types'=>['Trainee'],'pwd_categories'=>['LOCAL-PWD'],'session_code'=>'LOCAL2026','portal_shifts'=>[1],'maximum_unit'=>40,'admission_opens_on'=>'2026-01-01','admission_closes_on'=>'2026-12-31','age_cutoff_date'=>'2026-08-01','minimum_age_years'=>14,'strict_age_boundary'=>true];
$payload=['StateRegNumber'=>'LOCAL123','TraineeName'=>'Local Student','DateOfBirth'=>'2005-01-01','Gender'=>'Female','Category'=>'LOCAL','FatherGuardianName'=>'Local Father','MotherName'=>'Local Mother','MobileNumber'=>'9876543210','EmailID'=>'local@example.test','Session'=>'LOCAL2026','AdmissionDate'=>'2026-08-01','HighestQualification'=>'10th','Trade'=>'Local Trade','Shift'=>'1','Unit'=>'1','IsTraineeDualMode'=>'no','MISITICode'=>'LOCALITI1','PersonwithDisability'=>'no','EconomicWeakerSection'=>'yes','TraineeType'=>'Trainee'];
$checks=0;
function uploadCheck(bool $value): void { global $checks; if (!$value) throw new RuntimeException('Upload validation regression.'); $checks++; }
uploadCheck(TraineeUploadValidator::errors($payload,$profile)===[]);
foreach (['EconomicWeakerSection'=>null,'MobileNumber'=>'123','EmailID'=>'invalid','DateOfBirth'=>'2012-08-01','AdmissionDate'=>'2027-01-01','TraineeType'=>'Unknown','StateRegNumber'=>'LOCAL-123','Unit'=>'41'] as $field=>$value) {
    $candidate=$payload;
    $candidate[$field]=$value;
    uploadCheck(isset(TraineeUploadValidator::errors($candidate,$profile)[$field]));
}
uploadCheck(isset(TraineeUploadValidator::duplicateErrors([$payload,$payload])[1]['MobileNumber']));
uploadCheck(Money::cents('0.01')===1 && Money::cents('100.1')===10010 && Money::decimal(10010)==='100.10');
foreach (['1.001','-1','1e3','NaN','0'] as $amount) {
    try { Money::cents($amount,true); uploadCheck(false); } catch (HttpException) { uploadCheck(true); }
}
echo "Upload and decimal validation passed ($checks checks).\n";
