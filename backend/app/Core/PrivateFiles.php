<?php
declare(strict_types=1);
namespace App\Core;

/** Local private storage; clients never supply filesystem paths. */
final readonly class PrivateFiles
{
    private string $root;
    public function __construct(string $directory)
    {
        if ($directory==='' || str_contains($directory,'://') || (!str_starts_with($directory,'/') && !preg_match('/^[A-Za-z]:[\\\\\/]/',$directory))) {
            throw new \RuntimeException('Absolute private storage directory required.');
        }
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('Private storage unavailable.');
        $root=realpath($directory);
        $public=realpath(dirname(__DIR__,2).'/public');
        if (!$root || ($public && (strcasecmp($root,$public)===0 || str_starts_with(strtolower($root),strtolower($public).DIRECTORY_SEPARATOR)))) {
            throw new \RuntimeException('Storage must be outside the public directory.');
        }
        $this->root=$root;
    }
    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32,64}(?:\.json)?$/D',$id)) throw new \InvalidArgumentException('Invalid private object ID.');
        return $this->root.DIRECTORY_SEPARATOR.$id;
    }
    public function write(string $id,string $bytes): void
    {
        $path=$this->path($id);
        $file=fopen($path,'x');
        if (!$file) throw new \RuntimeException('Private object could not be created.');
        try {
            if (!chmod($path,0600) || fwrite($file,$bytes)!==strlen($bytes) || !fflush($file)) throw new \RuntimeException('Private object write failed.');
        } catch (\Throwable $error) {
            fclose($file); unlink($path); throw $error;
        }
        fclose($file);
    }
    public function read(string $id): string
    {
        $path=$this->path($id);
        if (is_link($path)) throw new \RuntimeException('Symlink objects are unsupported.');
        $bytes=file_get_contents($path);
        if ($bytes===false) throw new \RuntimeException('Private object unavailable.');
        return $bytes;
    }
    public function remove(string $id): void
    {
        $path=$this->path($id);
        if (is_file($path) && !unlink($path)) throw new \RuntimeException('Private object cleanup failed.');
    }
}
