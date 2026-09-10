<?php
declare(strict_types=1);

final class Store {
    public readonly string $root;
    public function __construct(string $root) {
        if (!is_dir($root) && !mkdir($root,0700,true)) throw new RuntimeException('Lagringsmappen kunde inte skapas.');
        $this->root = str_replace('\\','/',realpath($root));
        foreach (['kunskapsbank','skills','inbox','processed','data','logs'] as $dir) if (!is_dir($this->root.'/'.$dir)) mkdir($this->root.'/'.$dir,0700,true);
    }
    public function path(string $relative, bool $exists = false): string {
        $relative = str_replace('\\','/',$relative);
        if (!$relative || preg_match('~[\x00-\x1f:]|^/|(^|/)\.\.?(/|$)~',$relative)) throw new RuntimeException('Ogiltig sökväg.',400);
        $parts = explode('/',$relative); $path = $this->root;
        foreach ($parts as $part) {
            if ($part === '' || str_starts_with($part,'.')) throw new RuntimeException('Ogiltig sökväg.',400);
            $path .= '/'.$part;
            if (is_link($path)) throw new RuntimeException('Symboliska länkar tillåts inte.',400);
            if (file_exists($path)) {
                $real = str_replace('\\','/',realpath($path));
                if (!str_starts_with(strtolower($real),strtolower($this->root).'/')) throw new RuntimeException('Sökvägen lämnar lagringsmappen.',400);
            }
        }
        if ($exists && !is_file($path)) throw new RuntimeException('Filen hittades inte.',404);
        return $path;
    }
    public function read(string $rel): string { return file_get_contents($this->path($rel,true)); }
    public function json(string $rel): array {
        $p=$this->path($rel); if (!is_file($p)) return [];
        $v=json_decode(file_get_contents($p),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($v)) throw new RuntimeException('Ogiltig datafil.'); return $v;
    }
    public function write(string $rel, string $body, bool $new = false): void {
        $p=$this->path($rel); if (!is_dir(dirname($p))) mkdir(dirname($p),0700,true);
        if ($new && file_exists($p)) throw new RuntimeException('Filen finns redan.',409);
        $tmp=tempnam(dirname($p),'kb-');
        try {
            if (file_put_contents($tmp,$body,LOCK_EX) === false || !rename($tmp,$p)) throw new RuntimeException('Kunde inte spara filen.');
        } finally { if (is_file($tmp)) unlink($tmp); }
    }
    public function saveJson(string $rel, array $data): void { $this->write($rel,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
    public function locked(callable $fn, int $mode = LOCK_EX): mixed {
        // Lock lives outside data so reset never unlinks a live lock file.
        $h=fopen($this->root.'/operation.lock','c');
        if (!$h || !flock($h,$mode|LOCK_NB)) throw new RuntimeException('En annan bearbetning pågår. Försök igen när den är klar.',409);
        try { return $fn(); } finally { flock($h,LOCK_UN); fclose($h); }
    }
    public function files(string $folder, ?string $extension = null): array {
        $root=$this->path($folder); $out=[];
        $walk=function(string $dir) use (&$walk,&$out,$extension) {
            foreach (scandir($dir) as $name) {
                if (str_starts_with($name,'.')) continue;
                $p=$dir.'/'.$name; $rel=substr($p,strlen($this->root)+1); $this->path($rel);
                if (is_dir($p)) $walk($p);
                elseif (is_file($p) && ($extension===null || strtolower(pathinfo($p,PATHINFO_EXTENSION))===$extension)) $out[]=$rel;
            }
        };
        if (is_dir($root)) $walk($root); sort($out); return $out;
    }
    public static function parse(string $raw): array {
        $raw=str_replace("\r\n","\n",$raw); $meta=[]; $body=$raw;
        if (str_starts_with($raw,"---\n")) {
            if (!preg_match('/\A---\n(.*?)\n---(?:\n|$)(.*)\z/s',$raw,$m)) throw new RuntimeException('YAML-frontmatter saknar avslutande ---.',400);
            $meta=self::yamlParse($m[1]);
            if (!is_array($meta) || ($meta && array_is_list($meta))) throw new RuntimeException('Frontmatter måste vara ett objekt.',400);
            $body=ltrim($m[2],"\n");
        }
        foreach (['tags','document_paths'] as $key) if (isset($meta[$key]) && (!is_array($meta[$key]) || array_filter($meta[$key],fn($x)=>!is_string($x)))) throw new RuntimeException($key.' måste vara en lista med text.',400);
        return [$meta,$body];
    }
    public static function compose(array $meta,string $body): string { return "---\n".self::yamlDump($meta)."---\n\n".$body."\n"; }
    public static function yamlParse(string $yaml): array {
        $out=[];$current=null;
        foreach(explode("\n",$yaml) as $line){if(trim($line)===''||preg_match('/^\s*#/',$line))continue;
            if(preg_match('/^([A-Za-z0-9_-]+):(?:\s*(.*))?$/',$line,$m)){$current=$m[1];$v=$m[2]??'';$out[$current]=$v===''?[]:self::yamlScalar($v);continue;}
            if($current!==null&&preg_match('/^\s*-\s*(.*)$/',$line,$m)){$out[$current][]=(string)self::yamlScalar($m[1]);continue;}
            if($current!==null&&preg_match('/^\s+([A-Za-z0-9_-]+):\s*(.*)$/',$line,$m)){$out[$current][$m[1]]=self::yamlScalar($m[2]);continue;}
            if($current!==null&&preg_match('/^\s+(.+)$/',$line,$m)&&!is_array($out[$current])){$out[$current].=' '.trim($m[1]);continue;}
            throw new RuntimeException('Ogiltig YAML-rad: '.trim($line),400);
        }return$out;
    }
    private static function yamlScalar(string $v): mixed {$v=trim($v);if($v==='null'||$v==='~')return null;if(in_array(strtolower($v),['true','false'],true))return strtolower($v)==='true';if(is_numeric($v))return str_contains($v,'.')?(float)$v:(int)$v;if(str_starts_with($v,'[')&&str_ends_with($v,']')){if(trim($v,'[] ')==='')return[];return array_map(fn($x)=>(string)self::yamlScalar($x),str_getcsv(substr($v,1,-1)));}if(($v[0]??'')==='"'){$j=json_decode($v,true);if(is_string($j))return$j;}if(($v[0]??'')==="'"&&str_ends_with($v,"'"))return str_replace("''","'",substr($v,1,-1));return preg_replace('/\s+#.*$/','',$v);}
    public static function yamlDump(array $data,int $indent=0): string {$out='';foreach($data as $k=>$v){$pad=str_repeat(' ',$indent);if(is_array($v)){if(!$v){$out.=$pad.$k.": []\n";}elseif(array_is_list($v)){ $out.=$pad.$k.":\n";foreach($v as$x)$out.=$pad.'  - '.self::yamlQuote($x)."\n";}else{$out.=$pad.$k.":\n".self::yamlDump($v,$indent+2);}}else$out.=$pad.$k.': '.self::yamlQuote($v)."\n";}return$out;}
    private static function yamlQuote(mixed $v): string {if($v===null)return'null';if(is_bool($v))return$v?'true':'false';if(is_int($v)||is_float($v))return(string)$v;$s=(string)$v;return "'".str_replace("'","''",$s)."'";}
    public static function slug(string $s): string {
        $s=strtr(mb_strtolower($s),['å'=>'a','ä'=>'a','ö'=>'o','é'=>'e','ü'=>'u']);
        $s=trim(preg_replace('/[^\pL\pN]+/u','-',$s),'-');
        if (!$s) throw new RuntimeException('Namnet måste innehålla bokstäver eller siffror.',400);
        return mb_substr($s,0,100);
    }
    public function document(string $rel,string $folder='kunskapsbank'): array {
        if (strtolower(pathinfo($rel,PATHINFO_EXTENSION))!=='md') throw new RuntimeException('Endast Markdown tillåts.',400);
        $raw=$this->read($folder.'/'.$rel); [$m,$b]=self::parse($raw);
        return array_merge($m,['title'=>(string)($m['title'] ?? $m['name'] ?? pathinfo($rel,PATHINFO_FILENAME)), 'tags'=>$m['tags'] ?? [],'summary'=>(string)($m['summary'] ?? ''),'body'=>$b,'raw'=>$raw,'meta'=>$m,'rel_path'=>$rel,'source_type'=>$m['source_type'] ?? 'md','modified_at'=>filemtime($this->path($folder.'/'.$rel))]);
    }
    public function documents(): array {
        $out=[];
        foreach ($this->files('kunskapsbank','md') as $rel) $out[]=$this->document(substr($rel,strlen('kunskapsbank/')));
        return $out;
    }
    public function skills(): array {
        $out=[];
        foreach ($this->files('skills/_custom','md') as $p) {
            if (basename($p)!=='SKILL.md') continue;
            $d=$this->document(substr($p,7),'skills');
            $out[]=array_merge($d,['slug'=>basename(dirname($p)), 'name'=>$d['meta']['name'] ?? $d['title'],'description'=>$d['meta']['description'] ?? '', 'instructions'=>$d['body'],'document_paths'=>$d['meta']['document_paths'] ?? [],'document_error'=>'']);
        } return $out;
    }
    public function skill(string $slug): array {
        foreach ($this->skills() as $s) if ($s['slug']===$slug) return $s;
        throw new RuntimeException('Skillen hittades inte.',404);
    }
    public function unique(string $rel): string {
        $ext=pathinfo($rel,PATHINFO_EXTENSION); $stem=substr($rel,0,-strlen($ext)-1); $candidate=$rel;
        for($i=2;file_exists($this->path($candidate));$i++) $candidate=$stem.'-'.$i.'.'.$ext;
        return $candidate;
    }
    public function original(array $doc): ?string {
        $source=$doc['meta']['source_file'] ?? '';
        if (!is_string($source) || !$source) return null;
        try {
            $rel='processed/'.preg_replace('~^processed/~','',str_replace('\\','/',$source));
            return is_file($this->path($rel)) ? $rel : null;
        } catch (Throwable) { return null; }
    }
    public function fingerprint(array $files): string {
        $values=[]; foreach($files as $file) $values[$file]=hash_file('sha256',$this->path($file,true));
        ksort($values); return hash('sha256',json_encode($values));
    }
}
