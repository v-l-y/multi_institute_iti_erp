<?php
declare(strict_types=1);
namespace App\Http;

final class CsvExport
{
    public static function response(array $rows,array $columns,string $filename,?int $next=null): Response
    {
        $stream=fopen('php://temp','w+');
        if (!$stream) throw new \RuntimeException('Export buffer unavailable.');
        try {
            fputcsv($stream,array_keys($columns),',','"','',"\r\n");
            foreach ($rows as $row) {
                $cells=[];
                foreach ($columns as $field) {
                    $value=(string)($row[$field]??'');
                    // Treat formula-like user data as literal spreadsheet text.
                    if (preg_match('/^[\x00-\x20]*[=+@-]/',$value)) $value="'".$value;
                    $cells[]=$value;
                }
                fputcsv($stream,$cells,',','"','',"\r\n");
            }
            rewind($stream); $bytes="\xEF\xBB\xBF".stream_get_contents($stream);
        } finally { fclose($stream); }
        $headers=['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'"'];
        if ($next!==null) $headers['X-Next-After-ID']=(string)$next;
        return new Response([],200,$headers,$bytes);
    }
}
