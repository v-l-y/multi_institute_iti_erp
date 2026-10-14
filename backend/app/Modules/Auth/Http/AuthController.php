<?php
declare(strict_types=1);
namespace App\Modules\Auth\Http;

use App\Core\Database;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Auth\Application\PasswordRecoveryService;
use App\Modules\Auth\Application\MfaService;
use App\Modules\Auth\Application\RecoveryEmailService;
use App\Modules\Auth\Infrastructure\RecoveryDelivery;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Infrastructure\DatabaseThrottle;
use App\Http\Request;
use App\Http\Response;

final readonly class AuthController
{
    public function __construct(private Database $database) {}
    private function service(): AuthService
    {
        return new AuthService($this->database->connection());
    }
    private function throttle(Request $request,string $operation,int $limit=30): void
    {
        (new DatabaseThrottle($this->database->connection()))
            ->check($operation,$request->ip,$limit,60);
    }
    public function login(Request $request): Response
    {
        $this->throttle($request,'login',30);
        return new Response(['success' => true, 'data' => $this->service()->login($request)]);
    }
    public function refresh(Request $request): Response
    {
        $this->throttle($request,'refresh',30);
        return new Response(['success'=>true,'data'=>$this->service()->refresh($request)]);
    }
    private function recovery(Request $request): PasswordRecoveryService
    {
        if (!in_array(getenv('AUTH_RECOVERY_MODE'),['smtp','local_outbox'],true)) {
            throw new HttpException('RECOVERY_UNAVAILABLE','Recovery delivery is not configured.',503);
        }
        $this->throttle($request,'recovery',5);
        return new PasswordRecoveryService($this->database->connection(),new RecoveryDelivery());
    }
    public function forgotPassword(Request $request): Response
    {
        $username=$request->body['username']??null; $institute=$request->body['institute_id']??null;
        if (!is_string($username) || trim($username)==='' || strlen($username)>120 || ($institute!==null && (!is_int($institute) || $institute<1))) throw new HttpException('INVALID_INPUT','Username and valid institute identity required.',422);
        $started=hrtime(true);
        $this->recovery($request)->request(trim($username),$institute);
        $remaining=(int)ceil((250000000-(hrtime(true)-$started))/1000);
        if ($remaining>0) usleep($remaining);
        return new Response(['success'=>true,'data'=>['message'=>'If the account is eligible, recovery instructions will be queued.']],202);
    }
    public function resetPassword(Request $request): Response
    {
        $token=$request->body['reset_token']??null; $password=$request->body['new_password']??null;
        if (!is_string($token) || !is_string($password)) throw new HttpException('INVALID_INPUT','Reset token and new password required.',422);
        $this->recovery($request)->reset($token,$password);
        return new Response(['success'=>true,'data'=>['message'=>'Password recovered. Sign in again.']]);
    }
    public function mfaStatus(Request $request,AuthContext $actor): Response
    {
        return new Response(['success'=>true,'data'=>
          (new MfaService($this->database->connection()))->status($actor)]);
    }
    public function mfaEnroll(Request $request,AuthContext $actor): Response
    {
        $this->throttle($request,'mfa_enroll',5);
        return new Response(['success'=>true,'data'=>
          (new MfaService($this->database->connection()))->enroll($actor,$request)]);
    }
    public function mfaConfirm(Request $request,AuthContext $actor): Response
    {
        $this->throttle($request,'mfa_confirm',5);
        (new DatabaseThrottle($this->database->connection()))->check(
          'mfa_account',null,5,600,(string)$actor->userId);
        return new Response(['success'=>true,'data'=>
          (new MfaService($this->database->connection()))->confirm($actor,$request)]);
    }
    public function mfaDisable(Request $request,AuthContext $actor): Response
    {
        $this->throttle($request,'mfa_disable',5);
        (new MfaService($this->database->connection()))->disable($actor,$request);
        return new Response(['success'=>true,'data'=>['enabled'=>false]]);
    }
    public function recoveryEmailStatus(Request $request,AuthContext $actor): Response
    {
        return new Response(['success'=>true,'data'=>
          (new RecoveryEmailService($this->database->connection(),new RecoveryDelivery()))->status($actor)]);
    }
    public function recoveryEmailStart(Request $request,AuthContext $actor): Response
    {
        $this->throttle($request,'email_enroll',5);
        (new RecoveryEmailService($this->database->connection(),new RecoveryDelivery()))
          ->start($actor,$request);
        return new Response(['success'=>true,'data'=>['message'=>'Verification code queued.']],202);
    }
    public function recoveryEmailConfirm(Request $request,AuthContext $actor): Response
    {
        $this->throttle($request,'email_confirm',5);
        (new RecoveryEmailService($this->database->connection(),new RecoveryDelivery()))
          ->confirm($actor,$request);
        return new Response(['success'=>true,'data'=>['verified'=>true]]);
    }

    public function me(Request $request, AuthContext $context): Response
    {
        return new Response(['success' => true, 'data' => $context->profile()]);
    }
    public function logout(Request $request, AuthContext $context): Response
    {
        $service = $this->service();
        $service->logout($context, $request);
        return new Response(['success' => true, 'data' => ['message' => 'Logged out.']]);
    }
    public function changePassword(Request $request, AuthContext $context): Response
    {
        $service = $this->service();
        $service->changePassword($context, $request);
        return new Response(['success' => true, 'data' => ['message' => 'Password changed. Sign in again.']]);
    }

    public function sessions(Request $request, AuthContext $context): Response
    {
        return new Response(['success' => true, 'data' => $this->service()->sessions($context)]);
    }

    public function logoutAll(Request $request, AuthContext $context): Response
    {
        $this->service()->logoutAll($context, $request);
        return new Response(['success' => true, 'data' => ['message' => 'All sessions revoked. Sign in again.']]);
    }
}
