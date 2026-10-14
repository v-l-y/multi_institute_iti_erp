<?php
declare(strict_types=1);
namespace App\Modules\Auth\Infrastructure;

use App\Core\PrivateFiles;
use App\Http\HttpException;

/**
 * No insecure PHP mail() fallback. SMTP requires verified STARTTLS and auth.
 * Email contains an OTP/token only, never a password or bearer session.
 */
final readonly class RecoveryDelivery
{
    private function mode(): string
    {
        $mode=getenv('AUTH_RECOVERY_MODE');
        if(!in_array($mode,['smtp','local_outbox'],true)){
            throw new HttpException('RECOVERY_UNAVAILABLE','Recovery delivery is not configured.',503);
        }
        return $mode;
    }

    public function send(string $email,string $subject,string $body): void
    {
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) ||
           preg_match('/[\r\n]/',$email.$subject) ||
           preg_match('/[^\x20-\x7e]/',$email.$subject)) {
            throw new HttpException('RECOVERY_UNAVAILABLE','Recovery delivery recipient invalid.',503);
        }
        if($this->mode()==='local_outbox'){
            $path=(string)getenv('AUTH_RECOVERY_OUTBOX');
            if($path==='')throw new HttpException('RECOVERY_UNAVAILABLE','Outbox not configured.',503);
            (new PrivateFiles($path))->write(bin2hex(random_bytes(16)).'.json',
                json_encode(['delivery_mode'=>'local_outbox','to'=>$email,'subject'=>$subject,'body'=>$body],JSON_THROW_ON_ERROR));
            return;
        }
        $host=getenv('AUTH_SMTP_HOST');$port=getenv('AUTH_SMTP_PORT');
        $user=getenv('AUTH_SMTP_USER');$password=getenv('AUTH_SMTP_PASSWORD');
        $from=getenv('AUTH_SMTP_FROM');
        if(!is_string($host)||!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D',$host) ||
           !is_string($user)||$user==='' || !is_string($password)||$password==='' ||
           !is_string($from)||!filter_var($from,FILTER_VALIDATE_EMAIL) ||
           !is_string($port)||!ctype_digit($port)||((int)$port)<1||((int)$port)>65535) {
            throw new HttpException('RECOVERY_UNAVAILABLE','Secure SMTP transport not configured.',503);
        }
        $socket=@stream_socket_client('tcp://'.$host.':'.$port,$errno,$errstr,10,
            STREAM_CLIENT_CONNECT,stream_context_create(['ssl'=>[
              'verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,
              'allow_self_signed'=>false,'SNI_enabled'=>true
            ]]));
        if($socket===false)throw new HttpException('RECOVERY_UNAVAILABLE','SMTP connection unavailable.',503);
        stream_set_timeout($socket,10);
        try{
            $read=static function()use($socket): int {
                $code=0;
                do{
                    $line=fgets($socket,2048);
                    if($line===false || !preg_match('/^([0-9]{3})([ -])/',$line,$match)){
                        throw new \RuntimeException('Invalid SMTP response.');
                    }
                    $code=(int)$match[1];
                    $more=$match[2]==='-';
                }while($more);
                return $code;
            };
            $command=static function(string $line,int $expected)use($socket,$read): void {
                if(fwrite($socket,$line."\r\n")===false || $read()!==$expected){
                    throw new \RuntimeException('SMTP delivery command rejected.');
                }
            };
            if($read()!==220)throw new \RuntimeException('SMTP greeting rejected.');
            $command('EHLO iti-erp.local',250);
            $command('STARTTLS',220);
            if(@stream_socket_enable_crypto($socket,true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT |
                (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')?STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT:0))!==true){
                throw new \RuntimeException('TLS handshake failed.');
            }
            $command('EHLO iti-erp.local',250);
            $command('AUTH LOGIN',334);
            $command(base64_encode($user),334);
            $command(base64_encode($password),235);
            $command('MAIL FROM:<'.$from.'>',250);
            $command('RCPT TO:<'.$email.'>',250);
            $command('DATA',354);
            $lines=preg_split('/\r\n|\r|\n/',$body);
            $encoded=array_map(static fn(string $l):string =>
                str_starts_with($l,'.')?'.'.$l:$l,$lines);
            $headers="From: <".$from.">\r\nTo: <".$email.">\r\nSubject: ".$subject.
                "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8".
                "\r\nContent-Transfer-Encoding: 8bit\r\n";
            if(fwrite($socket,$headers."\r\n".implode("\r\n",$encoded)."\r\n.\r\n")===false ||
                $read()!==250)throw new \RuntimeException('SMTP message rejected.');
            $command('QUIT',221);
        }catch(\Throwable $error){
            throw new HttpException('RECOVERY_UNAVAILABLE','Secure email delivery failed.',503);
        }finally{
            fclose($socket);
        }
    }
}
