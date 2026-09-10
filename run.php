<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/app/bootstrap.php';
try {
    foreach($argv as $argument) if(str_starts_with($argument,'--bank=')) putenv('KB_BANK='.substr($argument,7));
    $command=$argv[1]??'help';
    if($command==='banks'){foreach(banks() as $bank)echo $bank['id'].' — '.$bank['name'].($bank['id']===bank_id()?' *':'')."\n";exit;}
    if($command==='process') {echo json_encode(store()->locked(fn()=>(new Importer(store(),settings()))->process(in_array('--force',$argv,true))),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit;}
    if($command==='status'){echo json_encode(['documents'=>count(store()->documents()),'skills'=>count(store()->skills()),'inbox'=>count(store()->files('inbox')),'registry'=>count(store()->json('data/registry.json'))],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit;}
    if($command==='skills'){foreach(store()->skills() as $s)echo $s['slug'].' — '.$s['description']."\n";exit;}
    if($command==='watch'){$seconds=max(2,(int)($argv[2]??10));echo "Bevakar inboxen var $seconds sekund. Avsluta med Ctrl+C.\n";while(true){try{$r=store()->locked(fn()=>(new Importer(store(),settings()))->process());if($r['processed']||$r['failed'])echo json_encode($r,JSON_UNESCAPED_UNICODE)."\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");}sleep($seconds);}}
    echo "Kunskapstratten PHP\n\nphp -S 127.0.0.1:8088 index.php   Lokal webbserver\nphp run.php banks                 Lista kunskapsbanker\nphp run.php process [--force]     Bearbeta inbox\nphp run.php watch [sekunder]      Bevaka inbox\nphp run.php status                Visa status\nphp run.php skills                Lista skills\n\nLägg till --bank=bank-id för att välja kunskapsbank.\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
