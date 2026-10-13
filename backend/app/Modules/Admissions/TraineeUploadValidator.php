<?php
declare(strict_types=1);
namespace App\Modules\Admissions;

use DateTimeImmutable;

/** Validates an export candidate against an explicitly reviewed calendar upload profile. */
final class TraineeUploadValidator
{
    public static function errors(array $payload,array $profile): array
    {
        $errors=[];
        $required=['StateRegNumber','TraineeName','DateOfBirth','Gender','Category','FatherGuardianName','MotherName','MobileNumber','EmailID','Session','AdmissionDate','HighestQualification','Trade','Shift','Unit','IsTraineeDualMode','MISITICode','PersonwithDisability','EconomicWeakerSection','TraineeType'];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || (!is_scalar($payload[$field])) || trim((string)$payload[$field])==='') {
                $errors[$field]='Required verified export value missing.';
            }
        }
        foreach (['StateRegNumber','MISITICode'] as $field) {
            if (!preg_match('/^[A-Za-z0-9]+$/D',(string)($payload[$field]??''))) $errors[$field]='Alphanumeric value required.';
        }
        foreach (['TraineeName','FatherGuardianName','MotherName'] as $field) {
            $value=(string)($payload[$field]??'');
            if (strlen($value)>=100 || !preg_match("/^[A-Za-z .’'–-]+$/uD",$value)) $errors[$field]='Name does not match upload format.';
        }
        if (!preg_match('/^[0-9]{10}$/D',(string)($payload['MobileNumber']??''))) $errors['MobileNumber']='Ten-digit mobile required.';
        if (!filter_var($payload['EmailID']??'',FILTER_VALIDATE_EMAIL)) $errors['EmailID']='Valid email required.';
        if (($payload['UIDNumber']??'')!=='' && !preg_match('/^[1-9][0-9]{11}$/D',(string)$payload['UIDNumber'])) $errors['UIDNumber']='Invalid optional UID format.';
        foreach (['Gender'=>'genders','Category'=>'categories','HighestQualification'=>'qualifications','TraineeType'=>'trainee_types'] as $field=>$key) {
            if (empty($profile[$key]) || !is_array($profile[$key]) || !in_array($payload[$field]??null,$profile[$key],true)) $errors[$field]='Value not in reviewed upload profile.';
        }
        foreach (['IsTraineeDualMode','PersonwithDisability','EconomicWeakerSection'] as $field) {
            if (!in_array($payload[$field]??null,['yes','no'],true)) $errors[$field]='Verified yes/no value required.';
        }
        if (($payload['PersonwithDisability']??null)==='yes' && !in_array($payload['PWDcategory']??null,$profile['pwd_categories']??[],true)) $errors['PWDcategory']='Applicable disability category required.';
        if (($payload['Session']??null)!==($profile['session_code']??null)) $errors['Session']='Official session code mismatch.';
        if (!in_array((int)($payload['Shift']??0),$profile['portal_shifts']??[],true)) $errors['Shift']='Official portal shift mismatch.';
        if (!isset($profile['maximum_unit']) || !ctype_digit((string)($payload['Unit']??'')) || (int)$payload['Unit']<1 || (int)$payload['Unit']>(int)$profile['maximum_unit']) $errors['Unit']='Unit outside reviewed upload range.';
        foreach (['DateOfBirth','AdmissionDate'] as $field) {
            $value=(string)($payload[$field]??'');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d')!==$value) $errors[$field]='Valid yyyy-mm-dd date required.';
        }
        foreach (['admission_opens_on','admission_closes_on','age_cutoff_date','minimum_age_years','strict_age_boundary'] as $key) {
            if (!array_key_exists($key,$profile)) $errors['profile']='Upload dates and age policy must be reviewed explicitly.';
        }
        if (!isset($errors['profile']) && !isset($errors['AdmissionDate']) && isset($profile['admission_opens_on'],$profile['admission_closes_on']) && (($payload['AdmissionDate']??'')<$profile['admission_opens_on'] || ($payload['AdmissionDate']??'')>$profile['admission_closes_on'])) $errors['AdmissionDate']='Outside reviewed upload window.';
        if (!isset($errors['DateOfBirth']) && isset($profile['age_cutoff_date'],$profile['minimum_age_years'],$profile['strict_age_boundary'])) {
            try {
                $birth=new DateTimeImmutable($payload['DateOfBirth']);
                $boundary=(new DateTimeImmutable($profile['age_cutoff_date']))->modify('-'.(int)$profile['minimum_age_years'].' years');
                if ($profile['strict_age_boundary'] ? $birth>=$boundary : $birth>$boundary) $errors['DateOfBirth']='Age does not meet the reviewed upload boundary.';
            } catch (\Exception) {
                $errors['profile']='Invalid reviewed age policy.';
            }
        }
        return $errors;
    }

    public static function duplicateErrors(array $payloads): array
    {
        $errors=[];
        foreach (['StateRegNumber','MobileNumber','EmailID'] as $field) {
            $seen=[];
            foreach ($payloads as $index=>$payload) {
                $value=strtolower(trim((string)($payload[$field]??'')));
                if ($value==='' || isset($seen[$value])) $errors[$index][$field]='Missing or duplicate value in export batch.';
                $seen[$value]=true;
            }
        }
        return $errors;
    }
}
