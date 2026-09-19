<?php
declare(strict_types=1);

final class FileBrowser {
    public const FOLDERS=['kunskapsbank'=>'Markdown i kunskapsbanken','inbox'=>'Väntar på import','processed'=>'Originalfiler efter import','skills'=>'Skills'];

    public static function path(mixed $value,bool $file=false): string {
        if(!is_string($value)||strlen($value)>1000||$value===''||str_contains($value,'\\')||preg_match('~[\x00-\x1f:]|^/|//|(?:^|/)\.{1,2}(?:/|$)~',$value))throw new RuntimeException('Ogiltig filsökväg.',400);
        $parts=explode('/',$value);
        if(!isset(self::FOLDERS[$parts[0]]))throw new RuntimeException('Mappen visas inte i filutforskaren.',404);
        foreach($parts as $part)if(!self::visibleName($part))throw new RuntimeException('Systemfiler och dolda filer visas inte.',404);
        $path=store()->path($value);
        if($file){
            if(!is_file($path)||!self::visibleFile($value))throw new RuntimeException('Filen visas inte i filutforskaren.',404);
        }elseif(!is_dir($path))throw new RuntimeException('Mappen hittades inte.',404);
        return $path;
    }
    private static function visibleName(string $name): bool {
        return $name!==''&&!str_starts_with($name,'.')&&!preg_match('/^(?:config|settings|secret|secrets|credentials|password|passwords|token|tokens)(?:[._-]|$)/i',$name);
    }
    public static function visibleFile(string $relative): bool {
        $name=basename($relative);
        if(!self::visibleName($name))return false;
        // Only user documents and generated images; never PHP, environment, keys or bank data.
        return in_array('.'.strtolower(pathinfo($name,PATHINFO_EXTENSION)),Importer::EXTENSIONS,true);
    }
    public static function archive(): string {
        if(!class_exists(ZipArchive::class))throw new RuntimeException('PHP-tillägget zip behövs för att ladda ned hela kunskapsbanken.',503);
        $temporary=tempnam(sys_get_temp_dir(),'kb-export-');
        if($temporary===false)throw new RuntimeException('ZIP-filen kunde inte skapas.',500);
        $zip=new ZipArchive();$opened=false;
        try {
            if($zip->open($temporary,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP-filen kunde inte öppnas.',500);
            $opened=true;
            foreach(array_keys(self::FOLDERS) as $folder){
                if(!$zip->addEmptyDir($folder))throw new RuntimeException('Mappen kunde inte läggas i ZIP-filen.',500);
                $add=function(string $relative) use (&$add,$zip): void {
                    $items=self::listing($relative);
                    foreach($items['folders'] as $item){
                        if(!$zip->addEmptyDir($item['path']))throw new RuntimeException('Mappen kunde inte läggas i ZIP-filen.',500);
                        $add($item['path']);
                    }
                    foreach($items['files'] as $item){
                        if(!$zip->addFile(self::path($item['path'],true),$item['path']))throw new RuntimeException('Filen kunde inte läggas i ZIP-filen.',500);
                    }
                };
                $add($folder);
            }
            $closed=$zip->close();$opened=false;
            if(!$closed)throw new RuntimeException('ZIP-filen kunde inte slutföras.',500);
            return $temporary;
        } catch(Throwable $error){
            if($opened)$zip->close();
            if(is_file($temporary))unlink($temporary);
            throw $error;
        }
    }
    public static function listing(string $relative=''): array {
        $folders=[];$files=[];
        if($relative===''){
            foreach(self::FOLDERS as $name=>$label)$folders[]=['name'=>$name,'label'=>$label,'path'=>$name];
            return ['folders'=>$folders,'files'=>$files];
        }
        $root=self::path($relative);
        foreach(scandir($root)?:[] as $name){
            if($name==='.'||$name==='..'||!self::visibleName($name))continue;
            $child=$relative.'/'.$name;
            try{$path=store()->path($child);}catch(RuntimeException){continue;}
            if(is_dir($path))$folders[]=['name'=>$name,'label'=>$name,'path'=>$child];
            elseif(is_file($path)&&self::visibleFile($child))$files[]=['name'=>$name,'path'=>$child,'size'=>filesize($path),'modified'=>filemtime($path)];
        }
        usort($folders,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        usort($files,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        return ['folders'=>$folders,'files'=>$files];
    }
}
