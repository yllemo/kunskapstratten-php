<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$targetBank=$argv[2]??null;
if($targetBank!==null) putenv('KB_BANK='.$targetBank);
$source=isset($argv[1])?realpath($argv[1]):false;
if(!$source || !is_dir($source.'/kunskapsbank')){fwrite(STDERR,"Användning: php tools/migrate.php sökväg-till-Python-projekt [bank-id]\n");exit(1);}
$source=str_replace('\\','/',$source);
if(strtolower($source)===strtolower(store()->root) || str_starts_with(strtolower(store()->root),strtolower($source).'/')){fwrite(STDERR,"Källan och målet får inte överlappa.\n");exit(1);}
try {
    store()->locked(function() use($source) {
        $count=0;$skipped=0;
        // Credentials/settings are deliberately not copied between deployments.
        foreach(['kunskapsbank','skills/_custom','processed','inbox'] as $folder) {
            $walk=function(string $relative) use(&$walk,$source,&$count,&$skipped) {
                foreach(scandir($source.'/'.$relative) as $name){
                    if(str_starts_with($name,'.'))continue;
                    $rel=$relative.'/'.$name;$path=$source.'/'.$rel;
                    if(is_link($path))throw new RuntimeException('Symbolisk länk nekades: '.$rel);
                    $real=str_replace('\\','/',realpath($path));if(!str_starts_with(strtolower($real),strtolower($source).'/'))throw new RuntimeException('Sökvägen lämnar källan.');
                    if(is_dir($path))$walk($rel);
                    elseif(is_file($path)){if(file_exists(store()->path($rel))){$skipped++;continue;}store()->write($rel,file_get_contents($path),true);$count++;}
                }
            };
            if(is_dir($source.'/'.$folder))$walk($folder);
        }
        $registry=store()->json('data/registry.json');
        foreach(store()->documents() as $doc){$hash=$doc['meta']['source_hash']??'';if($hash && store()->original($doc))$registry[$hash]??=['status'=>'done','source_path'=>$doc['meta']['source_file'],'source_file'=>$doc['meta']['source_file'],'output_path'=>$doc['rel_path'],'updated_at'=>gmdate('c')];}
        store()->saveJson('data/registry.json',$registry);
        echo "$count filer kopierades, $skipped befintliga filer behölls. Originalprojektet ändrades inte. AI-inställningar och minne kopierades inte.\n";
    });
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
