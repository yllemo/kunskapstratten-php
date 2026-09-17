<?php
declare(strict_types=1);

final class Importer {
    public const EXTENSIONS=['.pdf','.docx','.doc','.pptx','.ppt','.xlsx','.xls','.txt','.md','.html','.htm','.csv','.json','.xml','.png','.jpg','.jpeg','.gif','.bmp','.webp','.mp3','.wav','.epub','.zip'];
    public function __construct(private Store $store, private array $settings) {}
    public function convert(string $path,string $name): string {
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if (!in_array('.'.$ext,self::EXTENSIONS,true)) throw new RuntimeException('Filtypen stöds inte: '.$ext,400);
        if (filesize($path)>config()['max_upload_mb']*1024*1024) throw new RuntimeException('Filen är för stor.',413);
        if(in_array($ext,['mp3','wav'],true))return (new AI($this->settings['ai']))->transcribe($path,$name);
        if($ext==='doc') {
            return $this->binaryStrings($path,'äldre DOC');
        }
        if($ext==='ppt') {
            return $this->legacyPowerPoint($path);
        }
        if ($ext==='pdf') {
            $text=(new DocumentConverter($this->settings['import']??[]))->pdf(file_get_contents($path));
            if (!trim($text)) throw new RuntimeException('PDF-filen saknar läsbar text.',400);
            return $text;
        }
        if(in_array($ext,['docx','pptx','xlsx'],true))return (new DocumentConverter($this->settings['import']??[]))->office($path,$ext);
        if($ext==='xls') return $this->binaryStrings($path,'äldre XLS');
        if (in_array($ext,['epub','zip'],true)) return $this->archive($path,$ext);
        if (in_array($ext,['png','jpg','jpeg','gif','bmp','webp'],true)) {
            if (!getimagesize($path)) throw new RuntimeException('Ogiltig bildfil.',400);
            if ($this->settings['ai']['enabled'] && $this->settings['ai']['provider']!=='ollama' && $this->settings['ai']['use_for_image_description']) {
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
                return (new AI($this->settings['ai']))->complete([['role'=>'user','content'=>[['type'=>'text','text'=>'Beskriv bilden på svenska. Återge synlig text och relevanta detaljer.'],['type'=>'image_url','image_url'=>['url'=>'data:'.$mime.';base64,'.base64_encode(file_get_contents($path))]]]]]);
            }
            return '# '.$name."\n\nBildfil. Aktivera AI med bildstöd för en beskrivning.";
        }
        $text=file_get_contents($path);
        if (!mb_check_encoding($text,'UTF-8')) $text=mb_convert_encoding($text,'UTF-8','Windows-1252');
        if (in_array($ext,['html','htm'],true)) return $this->html($text);
        if ($ext==='json') return "```json\n".json_encode(json_decode($text,true,64,JSON_THROW_ON_ERROR),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n```";
        if ($ext==='xml') return "```xml\n".$text."\n```";
        if ($ext==='csv') {
            $rows=preg_split('/\r?\n/',trim($text)); $out=[];
            $separator=substr_count($rows[0]??'',';')>substr_count($rows[0]??'',',') ? ';' : ',';
            foreach($rows as $i=>$row) { $cells=str_getcsv($row,$separator,'"',''); $out[]='| '.implode(' | ',array_map(fn($v)=>str_replace('|','\\|',(string)$v),$cells)).' |'; if($i===0) $out[]='| '.implode(' | ',array_fill(0,count($cells),'---')).' |'; }
            return implode("\n",$out);
        }
        return $text;
    }
    private function html(string $text): string {
        $text=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$text);
        $text=preg_replace_callback('~<h([1-6])\b[^>]*>(.*?)</h\1>~is',fn($m)=>"\n\n".str_repeat('#',(int)$m[1]).' '.strip_tags($m[2])."\n\n",$text);
        $text=preg_replace('~</(?:p|div|tr|li|section)>|<br\s*/?>~i',"\n",$text);
        return trim(html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    }
    private function legacyPowerPoint(string $path): string {
        // MS-PPT records: TextCharsAtom (0x0FA0), TextBytesAtom (0x0FA8).
        // Best-effort text extraction from legacy binary Office files.
        return $this->binaryStrings($path,'äldre PPT');
    }
    private function binaryStrings(string $path,string $label): string {$raw=file_get_contents($path);preg_match_all('/(?:[\x20-\x7E\x80-\xFF]\x00){4,}/',$raw,$w);preg_match_all('/[\x20-\x7E\x80-\xFF]{5,}/',$raw,$a);$lines=[];foreach($w[0]as$v)$lines[]=mb_convert_encoding($v,'UTF-8','UTF-16LE');foreach($a[0]as$v)if(preg_match('/[A-Za-z]{3}/',$v))$lines[]=mb_convert_encoding($v,'UTF-8','Windows-1252');$lines=array_values(array_unique(array_filter(array_map('trim',$lines))));if(!$lines)throw new RuntimeException("$label saknar läsbar text. Spara om till modernt format.",400);return "> Enkelt textutdrag ur $label.\n\n".implode("\n\n",array_slice($lines,0,20000));}
    private function archive(string $path,string $ext): string {
        $zip=new ZipArchive(); if($zip->open($path)!==true) throw new RuntimeException('Arkivet kunde inte läsas.',400);
        try {
            if($zip->numFiles>2000) throw new RuntimeException('Arkivet innehåller för många filer.',400);
            $size=0; $entries=[];
            for($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i); $size+=$stat['size'];
                if($size>100*1024*1024) throw new RuntimeException('Arkivet är för stort uppackat.',400);
                $entries[]=$stat['name'];
            }
            natsort($entries); $out=[];
            foreach($entries as $entry) {
                if($ext==='docx' && $entry!=='word/document.xml') continue;
                if($ext==='pptx' && !preg_match('~^ppt/slides/slide\d+\.xml$~',$entry)) continue;
                if($ext==='epub' && !preg_match('~\.(xhtml|html|htm)$~i',$entry)) continue;
                if($ext==='zip' && !preg_match('~\.(md|txt|csv|json|xml|html|htm)$~i',$entry)) continue;
                $text=$zip->getFromName($entry);
                $out[]='## '.$entry."\n\n".(preg_match('~\.x?html?$~i',$entry)?$this->html($text):$text);
            }
            if(!$out) throw new RuntimeException('Inget läsbart innehåll hittades i arkivet.',400);
            return implode("\n\n",$out);
        } finally { $zip->close(); }
    }
    public function process(bool $force=false): array {
        $registry=$this->store->json('data/registry.json'); $result=['processed'=>0,'skipped'=>0,'failed'=>0,'errors'=>[],'warnings'=>[]];
        foreach($this->store->files('inbox') as $rel) {
            $hash=hash_file('sha256',$this->store->path($rel,true));
            if(!$force && isset($registry[$hash]) && ($registry[$hash]['status']??'')==='done') {$result['skipped']++;continue;}
            $original=null; $output=null; $image=null;
            try {
                $body=MarkdownFormatter::clean($this->convert($this->store->path($rel,true),basename($rel)));
                $name=pathinfo($rel,PATHINFO_FILENAME); $ext=strtolower(pathinfo($rel,PATHINFO_EXTENSION));
                $meta=['title'=>$name,'tags'=>[],'summary'=>'','source_type'=>$ext,'source_hash'=>$hash,'converted_at'=>gmdate('c')];
                if($ext==='md') { [$existing,$body]=Store::parse($body); $meta=array_replace($existing,$meta,['title'=>$existing['title']??$name,'tags'=>$existing['tags']??[],'summary'=>$existing['summary']??'']); }
                [$meta,$body]=Store::parse(MarkdownFormatter::basic(Store::compose($meta,$body)));
                if($this->settings['ai']['enabled']&&($this->settings['import']['ai_format']??true)) {
                    $raw=Store::compose($meta,$body);
                    if(strlen($raw)>100000){$meta['ai_format']='too_large';$result['warnings'][]=basename($rel).': texten importerades, men överstiger AI-formateringens gräns på 100 kB.';}
                    elseif($this->settings['ai']['provider']==='ollama')$meta['ai_format']='pending';
                    else {
                        try {
                            $response=MarkdownFormatter::generate($this->settings['ai'],$raw);
                            [$meta,$body]=Store::parse(MarkdownFormatter::result($raw,$response,false));
                            $result['ai_formatted']=($result['ai_formatted']??0)+1;
                        }catch(Throwable $e){$meta['ai_format']='failed';$result['warnings'][]=basename($rel).': AI-formatering/taggar misslyckades; importerad text behölls. '.$e->getMessage();}
                    }
                }
                $original=$this->store->unique('processed/'.substr($rel,6));
                $meta['source_file']=substr($original,10);
                $folder=dirname(substr($rel,6)); $output=$this->store->unique('kunskapsbank/'.($folder==='.'?'':$folder.'/').Store::slug($name).'.md');
                if(in_array($ext,['png','jpg','jpeg','gif','bmp','webp'],true)) {
                    $image=$this->store->unique('kunskapsbank/images/'.Store::slug($name).'.'.$ext);
                    $this->store->write($image,file_get_contents($this->store->path($rel,true)),true);
                    $body.="\n\n![Bild](/images/".rawurlencode(basename($image)).")";
                }
                $this->store->write($original,file_get_contents($this->store->path($rel,true)),true);
                $this->store->write($output,Store::compose($meta,$body),true);
                $registry[$hash]=['status'=>'done','source_path'=>substr($rel,6),'output_path'=>substr($output,13),'source_file'=>$meta['source_file'],'updated_at'=>gmdate('c')];
                $this->store->saveJson('data/registry.json',$registry);
                if(!unlink($this->store->path($rel,true))) throw new RuntimeException('Originalet kunde inte tas bort från inboxen.');
                $result['processed']++;
            } catch(Throwable $e) {
                // Keep failed input available for retry. Roll back files created in this attempt.
                foreach([$output,$original,$image] as $created) if($created && is_file($this->store->path($created))) unlink($this->store->path($created));
                unset($registry[$hash]); $this->store->saveJson('data/registry.json',$registry);
                $result['failed']++; $result['errors'][]=basename($rel).': '.$e->getMessage();
            }
        }
        $result['format_documents']=[];
        foreach($this->store->documents() as $doc){
            $state=$doc['meta']['ai_format']??'';$missingTags=!array_filter($doc['tags'],fn($tag)=>trim($tag)!=='');
            if(($missingTags||$state==='failed')&&strlen($doc['raw'])<=2000000){
                $raw=MarkdownFormatter::basic($doc['raw']);[$meta,$body]=Store::parse($raw);
                if($this->settings['ai']['enabled']&&($this->settings['import']['ai_format']??true)&&strlen($raw)<=100000){$meta['ai_format']='pending';$state='pending';}
                elseif($state==='failed'){$meta['ai_format']='local';$state='local';}
                $this->store->write('kunskapsbank/'.$doc['rel_path'],Store::compose($meta,rtrim($body,"\n")));
                $result['repaired']=($result['repaired']??0)+1;
            }
            if($this->settings['ai']['enabled']&&($this->settings['import']['ai_format']??true)&&$state==='pending')$result['format_documents'][]=$doc['rel_path'];
        }
        return $result;
    }
}
