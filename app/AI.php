<?php
declare(strict_types=1);

final class AI {
    public function __construct(private array $ai) {}
    public static function validate(array $values, array $old): array {
        $ai=array_replace($old,array_intersect_key($values,$old));
        foreach (['base_url','model','provider','system_prompt','api_key','transcription_model'] as $k) {
            if (!is_string($ai[$k]) || strlen($ai[$k])>50000) throw new RuntimeException('Ogiltigt textfält: '.$k,400);
            $ai[$k]=trim($ai[$k]);
        }
        $url=parse_url($ai['base_url']);
        if (!$url || !in_array($url['scheme'] ?? '',['http','https'],true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) throw new RuntimeException('Ange en HTTP/HTTPS-bas-URL utan lösenord eller frågesträng.',400);
        if (!in_array($ai['provider'],['ollama','lmstudio','openai'],true) || !$ai['model']) throw new RuntimeException('Ange leverantör och modell.',400);
        if (!is_numeric($ai['temperature']) || $ai['temperature']<0 || $ai['temperature']>1 || !is_numeric($ai['context_window']) || $ai['context_window']<256 || $ai['context_window']>10000000) throw new RuntimeException('Ogiltig temperatur eller kontextstorlek.',400);
        $ai['temperature']=(float)$ai['temperature']; $ai['context_window']=(int)$ai['context_window'];
        $ai['enabled']=(bool)$ai['enabled'];
        if (!empty($values['clear_api_key'])) $ai['api_key']='';
        elseif (empty($values['api_key'])) {
            $a=parse_url($old['base_url']);
            $same=($url['scheme']??'')===($a['scheme']??'') && ($url['host']??'')===($a['host']??'') && ($url['port']??null)===($a['port']??null);
            $ai['api_key']=$same ? $old['api_key'] : '';
        }
        return $ai;
    }
    private function url(string $path): string {
        $base=rtrim($this->ai['base_url'],'/');
        if ($this->ai['provider']==='ollama' && str_ends_with($base,'/v1')) $base=substr($base,0,-3);
        return $base.$path;
    }
    public function request(string $path, ?array $payload=null): array {
        $h=$this->handle($path); curl_setopt($h,CURLOPT_RETURNTRANSFER,true);
        if ($payload!==null) { curl_setopt($h,CURLOPT_POST,true); curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR)); }
        $raw=curl_exec($h); $status=curl_getinfo($h,CURLINFO_RESPONSE_CODE); $error=curl_error($h); unset($h);
        if ($error || $status<200 || $status>=300) throw new RuntimeException($this->failure($status,$error,(string)$raw),502);
        $data=json_decode($raw,true);
        if (!is_array($data)) throw new RuntimeException('AI-servern skickade ett ogiltigt svar.',502);
        return $data;
    }
    private function handle(string $path): CurlHandle {
        $h=curl_init($this->url($path));
        $headers=['Content-Type: application/json'];
        if ($this->ai['api_key']) $headers[]='Authorization: Bearer '.$this->ai['api_key'];
        curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>(int)$this->ai['timeout'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS]);
        return $h;
    }
    public function complete(array $messages): string {
        if (!$this->ai['enabled']) throw new RuntimeException('Aktivera AI under Inställningar.',400);
        if($this->ai['provider']==='ollama') {
            $data=$this->request('/api/chat',['model'=>$this->ai['model'],'messages'=>$this->ollamaMessages($messages),'options'=>['temperature'=>$this->ai['temperature']],'stream'=>false]);
            $text=$data['message']['content'] ?? null;
        } else {
            $data=$this->request('/chat/completions',['model'=>$this->ai['model'],'messages'=>$messages,'temperature'=>$this->ai['temperature'],'stream'=>false]);
            $text=$data['choices'][0]['message']['content'] ?? null;
        }
        if (!is_string($text)) throw new RuntimeException('AI-servern skickade inget textsvar.',502); return $text;
    }
    public function models(): array {
        if($this->ai['provider']==='ollama') $models=array_column($this->request('/api/tags')['models']??[],'name');
        else $models=array_column($this->request('/models')['data']??[],'id');
        $models=array_values(array_filter($models,'is_string'));sort($models,SORT_NATURAL|SORT_FLAG_CASE);return$models;
    }
    public function transcribe(string $path,string $name): string {
        if (!$this->ai['enabled']) throw new RuntimeException('Ljudimport kräver aktiverad AI med stöd för transkribering.',400);
        if ($this->ai['provider']==='ollama') throw new RuntimeException('Ollama har ingen endpoint för ljudtranskribering. Välj en OpenAI-kompatibel server för MP3/WAV.',400);
        $h=$this->handle('/audio/transcriptions');
        $headers=[];if($this->ai['api_key'])$headers[]='Authorization: Bearer '.$this->ai['api_key'];
        curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>['model'=>$this->ai['transcription_model']??'whisper-1','file'=>new CURLFile($path,(new finfo(FILEINFO_MIME_TYPE))->file($path),$name),'response_format'=>'json']]);
        $raw=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);$data=json_decode((string)$raw,true);unset($h);
        if($status<200 || $status>=300 || !is_string($data['text']??null))throw new RuntimeException('Ljudet kunde inte transkriberas. AI-servern måste stödja /audio/transcriptions och vald transkriptionsmodell.',502);
        return $data['text'];
    }
    public function stream(array $messages, array $sources): void {
        if (!$this->ai['enabled']) throw new RuntimeException('Aktivera AI under Inställningar.',400);
        if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
        set_time_limit((int)$this->ai['timeout']+20);
        $ollama=$this->ai['provider']==='ollama';
        $h=$this->handle($ollama?'/api/chat':'/chat/completions'); $buffer=''; $answer=''; $status=0; $sent=false; $upstreamError=false;$errorBody='';
        $emit=function(string $text) use (&$sent,&$answer) {
            if (!$sent) { header('Content-Type: text/plain; charset=utf-8'); header('X-Accel-Buffering: no'); while(ob_get_level()) ob_end_flush(); $sent=true; }
            $answer.=$text; echo $text; flush();
        };
        $payload=$ollama?['model'=>$this->ai['model'],'messages'=>$this->ollamaMessages($messages),'options'=>['temperature'=>$this->ai['temperature']],'stream'=>true]:['model'=>$this->ai['model'],'messages'=>$messages,'temperature'=>$this->ai['temperature'],'stream'=>true];
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
            CURLOPT_HEADERFUNCTION=>function($h,$line) use (&$status) { if (preg_match('~^HTTP/\S+ (\d+)~',$line,$m)) $status=(int)$m[1]; return strlen($line); },
            CURLOPT_WRITEFUNCTION=>function($h,$chunk) use (&$buffer,&$status,&$upstreamError,&$errorBody,$emit,$ollama) {
                if (connection_aborted()) return 0;
                if ($status<200 || $status>=300) {$errorBody.=$chunk;return strlen($chunk);}
                $buffer.=$chunk;
                while (($pos=strpos($buffer,"\n"))!==false) {
                    $line=trim(substr($buffer,0,$pos)); $buffer=substr($buffer,$pos+1);
                    if(!$ollama){if (!str_starts_with($line,'data:')) continue;$line=trim(substr($line,5));if($line==='[DONE]')continue;}
                    $data=json_decode($line,true);if(!is_array($data))continue;
                    if (isset($data['error'])) $upstreamError=true;
                    $text=$ollama?($data['message']['content']??''):($data['choices'][0]['delta']['content']??'');
                    if (is_string($text) && $text!=='') $emit($text);
                } return strlen($chunk);
            }]);
        $ok=curl_exec($h);$curlError=curl_error($h); unset($h);
        if (!$ok || $status<200 || $status>=300 || $upstreamError || !$answer) {
            if (!$sent) throw new RuntimeException($this->failure($status,$curlError,$errorBody),502);
            $emit("\n\n**Svaret avbröts av ett fel hos AI-servern.**"); return;
        }
        if ($sources) {
            $prose=preg_replace('/```.*?```|`[^`]*`/s','',$answer);
            $used=array_filter($sources,fn($s,$key)=>str_contains($prose,'['.$key.']'),ARRAY_FILTER_USE_BOTH);
            $footer="\n\n---\n\n### ".($used ? 'Källhänvisningar' : 'Dokument i kontexten (AI:n angav inga källhänvisningar)')."\n";
            foreach (($used ?: $sources) as $key=>$source) {
                $title=preg_replace('/([\\\\`*_{}\[\]()<>#!|])/','\\\\$1',str_replace(["\n","\r"],' ',$source['title']));
                $footer.="\n- [$key] $title — [Öppna .md](<".$source['url'].'>) · '.($source['original'] ? '[Öppna original i processed](<'.$source['original'].'>)' : 'Original saknas eller är inte kopplat');
                $footer.="\n\n[$key]: <".$source['url'].">\n";
            } $emit($footer);
        }
    }
    private function ollamaMessages(array $messages): array {
        return array_map(function(array $message): array {
            $content=$message['content']??'';$images=[];
            if(is_array($content)) {
                $text=[];foreach($content as $part){if(($part['type']??'')==='text')$text[]=(string)($part['text']??'');elseif(($part['type']??'')==='image_url'){$url=(string)($part['image_url']['url']??'');if(preg_match('~^data:[^;]+;base64,(.+)$~s',$url,$m))$images[]=$m[1];}}
                $content=implode("\n",$text);
            }
            $result=['role'=>(string)($message['role']??'user'),'content'=>(string)$content];if($images)$result['images']=$images;return$result;
        },$messages);
    }
    private function failure(int $status,string $curlError,string $body): string {
        $detail=$curlError; if($detail===''){$json=json_decode($body,true);$detail=(string)($json['error']['message']??$json['error']??'');}
        $detail=trim(preg_replace('/\s+/',' ',$detail));if(strlen($detail)>300)$detail=substr($detail,0,300).'…';
        return 'AI-anslutningen misslyckades'.($status?' (HTTP '.$status.')':'.').($detail!==''?' '.$detail:' Kontrollera adressen och att modellen finns.');
    }
}

function chat_context(array $paths, array $temporary=[]): array {
    $blocks=[]; $sources=[];
    $memory=store()->path('data/MEMORY.md'); if(is_file($memory)) $blocks[]="--- MEMORY.md ---\n".file_get_contents($memory);
    foreach (array_unique($paths) as $rel) {
        if (!is_string($rel)) throw new RuntimeException('Ogiltigt dokumentval.',400);
        $doc=store()->document($rel); $id='K'.substr(hash('sha256',$rel),0,12);
        $blocks[]="--- $rel ---\nKäll-ID: $id\nTitel: ".$doc['title']."\n\n".$doc['body'];
        $sources[$id]=['title'=>$doc['title'],'url'=>url_for('view_doc',relpath:$rel),'original'=>store()->original($doc) ? url_for('open_original',relpath:$rel) : null];
    }
    foreach($temporary as $item) {
        if (!is_array($item) || !is_string($item['body'] ?? null)) throw new RuntimeException('Ogiltig bilaga.',400);
        $blocks[]='--- TILLFÄLLIG FIL: '.mb_substr((string)($item['name']??'Bilaga'),0,200)." ---\n".mb_substr($item['body'],0,2000000);
    }
    return [implode("\n\n",$blocks),$sources];
}
