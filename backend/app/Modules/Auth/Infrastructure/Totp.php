<?php
declare(strict_types=1);
namespace App\Modules\Auth\Infrastructure;

use App\Http\HttpException;

/** RFC 6238 SHA1 / 30s / 6 digits, encrypted TOTP secrets, replay protection. */
final class Totp
{
    private static function key(): string
    {
        $raw=getenv('AUTH_MFA_ENCRYPTION_KEY');
        $key=is_string($raw)?base64_decode($raw,true):false;
        if (!function_exists('sodium_crypto_secretbox') || !is_string($key) ||
            strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new HttpException('MFA_UNAVAILABLE','MFA encryption is not configured.',503);
        }
        return $key;
    }

    public static function seal(string $secret): string
    {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($secret,$nonce,self::key()));
    }

    public static function open(string $value): string
    {
        $bytes=base64_decode($value,true);
        if ($bytes===false || strlen($bytes)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new HttpException('MFA_UNAVAILABLE','MFA secret cannot be read.',503);
        }
        $secret=sodium_crypto_secretbox_open(substr($bytes,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bytes,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),self::key());
        if ($secret===false) throw new HttpException('MFA_UNAVAILABLE','MFA secret cannot be decrypted.',503);
        return $secret;
    }

    public static function base32(string $bytes): string
    {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits=0;$value=0;$output='';
        foreach (str_split($bytes) as $char) {
            $value=($value<<8)|ord($char);
            $bits+=8;
            while ($bits>=5) {
                $bits-=5;
                $output.=$alphabet[($value>>$bits)&31];
            }
            $value &= (1<<$bits)-1;
        }
        if ($bits) $output.=$alphabet[($value<<(5-$bits))&31];
        return $output;
    }

    public static function matchCounter(string $secret, string $code, int $lastCounter = -1): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D',$code)) return null;
        $now=intdiv(time(),30);
        for($i=-1;$i<=1;$i++) {
            $step=$now+$i;
            if ($step<0 || $step<=$lastCounter) continue;
            $counter=pack('N2',intdiv($step,4294967296),$step%4294967296);
            $hmac=hash_hmac('sha1',$counter,$secret,true);
            $offset=ord($hmac[19])&15;
            $value=((ord($hmac[$offset])&0x7f)<<24) |
                ((ord($hmac[$offset+1])&0xff)<<16) |
                ((ord($hmac[$offset+2])&0xff)<<8) | (ord($hmac[$offset+3])&0xff);
            $expected=str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
            if (hash_equals($expected,$code)) return $step;
        }
        return null;
    }
}
