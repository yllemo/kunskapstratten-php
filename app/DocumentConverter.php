<?php
declare(strict_types=1);

/** Built-in text conversion; no executables, OCR or external libraries. */
final class DocumentConverter {
    public const DEFAULTS=['format'=>'structured','tables'=>true,'separators'=>true,'pdf_lines'=>'paragraphs'];
    private array $options;
    public function __construct(array $options=[]) {$this->options=self::validate($options);}
    public static function validate(array $options): array {
        $o=array_replace(self::DEFAULTS,array_intersect_key($options,self::DEFAULTS));
        if(!in_array($o['format'],['structured','plain'],true)||!in_array($o['pdf_lines'],['paragraphs','lines'],true)||!is_bool($o['tables'])||!is_bool($o['separators']))throw new RuntimeException('Ogiltiga importinställningar.',400);
        return $o;
    }
    private function structured(): bool {return $this->options['format']==='structured';}
    private function xml(string $text): DOMXPath {
        $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
        try {if(stripos($text,'<!DOCTYPE')!==false||!$dom->loadXML($text,LIBXML_NONET))throw new RuntimeException('Ogiltig Office XML.',400);return new DOMXPath($dom);}
        finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    private function attr(DOMNode $node,string $name): string {foreach($node->attributes??[] as $a)if($a->localName===$name)return $a->value;return '';}
    private function text(DOMXPath $xp,DOMNode $node): string {
        $text='';foreach($xp->query('.//*[local-name()="t" or local-name()="tab" or local-name()="br" or local-name()="cr"]',$node) as $t)$text.=match($t->localName){'tab'=>"\t",'br','cr'=>"\n",default=>$t->textContent};return trim($text);
    }
    private function table(array $rows): string {
        if(!$rows)return '';
        if(!$this->structured()||!$this->options['tables'])return implode("\n",array_map(fn($r)=>implode("\t",$r),$rows));
        $width=max(array_map('count',$rows));$out=[];
        foreach($rows as $i=>$row){$row=array_pad($row,$width,'');$out[]='| '.implode(' | ',array_map(fn($v)=>str_replace(['|',"\r","\n"],['\\|','','<br>'],$v),$row)).' |';if($i===0)$out[]='| '.implode(' | ',array_fill(0,$width,'---')).' |';}
        return implode("\n",$out);
    }
    public function office(string $path,string $ext): string {
        if(!class_exists('ZipArchive'))throw new RuntimeException('Aktivera PHP-tillägget zip på webbservern.',400);
        $z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('Office-filen kunde inte öppnas.',400);
        try {
            $size=0;if($z->numFiles>2000)throw new RuntimeException('Office-filen innehåller för många delar.',400);
            for($i=0;$i<$z->numFiles;$i++){$size+=$z->statIndex($i)['size'];if($size>100*1024*1024)throw new RuntimeException('Office-filen är för stor uppackad.',400);}
            $out=match($ext){'docx'=>$this->word($z),'pptx'=>$this->slides($z),'xlsx'=>$this->sheets($z)};
            if(!trim($out))throw new RuntimeException('Dokumentet saknar läsbar text.',400);return $out;
        } finally {$z->close();}
    }
    private function word(ZipArchive $z): string {
        $xp=$this->xml($z->getFromName('word/document.xml')?:'');$out=[];$headings=[];
        if($xml=$z->getFromName('word/styles.xml')){$styles=$this->xml($xml);foreach($styles->query('//*[local-name()="style"]') as $style){$name=$styles->query('./*[local-name()="name"]',$style)->item(0);$outline=$styles->query('./*[local-name()="pPr"]/*[local-name()="outlineLvl"]',$style)->item(0);$label=$name?$this->attr($name,'val'):'';if($outline&&(int)$this->attr($outline,'val')<6)$headings[$this->attr($style,'styleId')]=(int)$this->attr($outline,'val')+1;elseif(preg_match('/(?:heading|rubrik)[ _-]?([1-6])/i',$label,$m))$headings[$this->attr($style,'styleId')]=(int)$m[1];}}

        foreach($xp->query('//*[local-name()="body"]/*') as $node){
            if($node->localName==='tbl'){$rows=[];foreach($xp->query('./*[local-name()="tr"]',$node) as $r){$cells=[];foreach($xp->query('./*[local-name()="tc"]',$r) as $c)$cells[]=$this->text($xp,$c);$rows[]=$cells;}$out[]=$this->table($rows);continue;}
            foreach($node->localName==='p'?[$node]:$xp->query('.//*[local-name()="p"]',$node) as $p){
                $line=$this->text($xp,$p);if($line==='')continue;
                if($this->structured()){
                    $style=$xp->query('./*[local-name()="pPr"]/*[local-name()="pStyle"]',$p)->item(0);
                    $outline=$xp->query('./*[local-name()="pPr"]/*[local-name()="outlineLvl"]',$p)->item(0);
                    $id=$style?$this->attr($style,'val'):'';
                    if(isset($headings[$id]))$line=str_repeat('#',$headings[$id]).' '.$line;
                    elseif(preg_match('/(?:heading|rubrik)[ _-]?([1-6])/i',$id,$m))$line=str_repeat('#',(int)$m[1]).' '.$line;
                    elseif($outline)$line=str_repeat('#',min(6,(int)$this->attr($outline,'val')+1)).' '.$line;
                    elseif($xp->query('./*[local-name()="pPr"]/*[local-name()="numPr"]',$p)->length)$line='- '.$line;
                }
                $out[]=$line;
            }
        }return implode("\n\n",$out);
    }
    private function slides(ZipArchive $z): string {
        $entries=[];for($i=0;$i<$z->numFiles;$i++){ $n=$z->getNameIndex($i);if(preg_match('~^ppt/slides/slide\d+\.xml$~',$n))$entries[]=$n;}natsort($entries);$entries=array_values($entries);
        if(($xml=$z->getFromName('ppt/presentation.xml'))&&($relationships=$z->getFromName('ppt/_rels/presentation.xml.rels'))){$pres=$this->xml($xml);$rels=$this->xml($relationships);$targets=[];foreach($rels->query('//*[local-name()="Relationship"]') as $r){$target=$r->getAttribute('Target');$targets[$r->getAttribute('Id')]=str_starts_with($target,'/')?ltrim($target,'/'):'ppt/'.$target;}$ordered=[];foreach($pres->query('//*[local-name()="sldId"]') as $slide){$target=$targets[$slide->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id')]??'';if(in_array($target,$entries,true))$ordered[]=$target;}if($ordered)$entries=$ordered;}
        $out=[];
        foreach($entries as $n){$xp=$this->xml($z->getFromName($n));$lines=[];
            if($this->options['separators'])$lines[]=($this->structured()?'## ':'').'Bild '.(count($out)+1);
            foreach($xp->query('//*[local-name()="sp" or local-name()="graphicFrame"]') as $shape){
                foreach($xp->query('.//*[local-name()="tbl"]',$shape) as $table){$rows=[];foreach($xp->query('./*[local-name()="tr"]',$table) as $r){$cells=[];foreach($xp->query('./*[local-name()="tc"]',$r) as $c)$cells[]=$this->text($xp,$c);$rows[]=$cells;}$lines[]=$this->table($rows);}
                foreach($xp->query('.//*[local-name()="p" and not(ancestor::*[local-name()="tbl"])]',$shape) as $p){$line=$this->text($xp,$p);if($line==='')continue;$ph=$xp->query('.//*[local-name()="ph"]',$shape)->item(0);$type=$ph?$this->attr($ph,'type'):'';
                    if($this->structured()&&in_array($type,['title','ctrTitle'],true))$line='### '.$line;
                    elseif($this->structured()&&$xp->query('./*[local-name()="pPr"]/*[local-name()="buChar" or local-name()="buAutoNum"]',$p)->length)$line='- '.$line;
                    $lines[]=$line;
                }
            }$out[]=implode("\n\n",$lines);
        }return implode("\n\n",$out);
    }
    private function sheets(ZipArchive $z): string {
        $shared=[];if($s=$z->getFromName('xl/sharedStrings.xml')){$xp=$this->xml($s);foreach($xp->query('//*[local-name()="si"]') as $n)$shared[]=$this->text($xp,$n);}
        $book=$this->xml($z->getFromName('xl/workbook.xml')?:'');$rels=$this->xml($z->getFromName('xl/_rels/workbook.xml.rels')?:'');$targets=[];
        foreach($rels->query('//*[local-name()="Relationship"]') as $r){$target=$r->getAttribute('Target');if(!str_contains($target,'..'))$targets[$r->getAttribute('Id')]=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;}
        $out=[];foreach($book->query('//*[local-name()="sheet"]') as $sheet){$entry=$targets[$this->attr($sheet,'id')]??'';if(!$entry||!($xml=$z->getFromName($entry)))continue;$xp=$this->xml($xml);$rows=[];
            foreach($xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $r){$cells=[];foreach($xp->query('./*[local-name()="c"]',$r) as $c){preg_match('/^([A-Z]+)/',$c->getAttribute('r'),$m);$col=0;foreach(str_split($m[1]??'A') as $letter)$col=$col*26+ord($letter)-64;if($col>512)throw new RuntimeException('Kalkylbladet har fler än 512 kolumner.',400);$v=$xp->query('./*[local-name()="v"]',$c)->item(0)?->textContent??'';$v=match($c->getAttribute('t')){'s'=>$shared[(int)$v]??'','inlineStr'=>$this->text($xp,$c),'b'=>$v==='1'?'TRUE':'FALSE',default=>$v};$cells[max(0,$col-1)]=$v;}
                if($cells){$row=array_fill(0,max(array_keys($cells))+1,'');foreach($cells as $i=>$v)$row[$i]=$v;$rows[]=$row;}
            }$out[]=($this->structured()?'## ':'').$sheet->getAttribute('name')."\n\n".$this->table($rows);
        }return implode("\n\n",$out);
    }
    private function stream(string $object): ?string {
        if(!preg_match('/\bstream\r?\n/',$object,$m,PREG_OFFSET_CAPTURE))return null;$start=$m[0][1]+strlen($m[0][0]);$end=strrpos($object,'endstream');if($end===false)return null;
        $head=substr($object,0,$start);$data=substr($object,$start,$end-$start);if(preg_match('~/Length\s+(\d+)(\s+\d+\s+R)?\b~',$head,$len)&&empty($len[2]))$data=substr($data,0,(int)$len[1]);else $data=preg_replace('/\r?\n\z/','',$data,1);
        if(preg_match('~/Filter\s*(\[[^]]+\]|/\w+)~',$head,$f)){
            preg_match_all('~/(\w+)~',$f[1],$filters);foreach($filters[1] as $filter){
                if(in_array($filter,['FlateDecode','Fl'],true)){$data=@gzuncompress($data,100*1024*1024);if($data===false)return null;}
                elseif(in_array($filter,['ASCII85Decode','A85'],true)){$data=$this->ascii85($data);if($data===null)return null;}
                elseif(in_array($filter,['ASCIIHexDecode','AHx'],true)){$hex=preg_replace('/\s|>.*/s','',$data);$data=@hex2bin(strlen($hex)%2?$hex.'0':$hex);if($data===false)return null;}
                else return null;
            }
        }return $data;
    }
    private function ascii85(string $data): ?string {
        $data=preg_replace('/\s/','',$data);$data=preg_replace('/^<~|~>$/','',$data);$out='';$group='';
        for($i=0;$i<strlen($data);$i++){$c=$data[$i];if($c==='z'&&$group===''){$out.=str_repeat("\0",4);continue;}if(ord($c)<33||ord($c)>117)return null;$group.=$c;
            if(strlen($group)===5){$value=0;foreach(str_split($group) as $digit)$value=$value*85+ord($digit)-33;if($value>0xffffffff)return null;$out.=pack('N',$value);$group='';}}
        if(strlen($group)===1)return null;if($group!==''){$length=strlen($group)-1;$group=str_pad($group,5,'u');$value=0;foreach(str_split($group) as $digit)$value=$value*85+ord($digit)-33;$out.=substr(pack('N',$value),0,$length);}return $out;
    }
    private function pageIds(array $objects): array {
        $root=null;foreach($objects as $obj)if(preg_match('~/Type\s*/Catalog\b~',$obj)&&preg_match('~/Pages\s+(\d+)\s+\d+\s+R~',$obj,$m)){$root=(int)$m[1];break;}
        $pages=[];$seen=[];$walk=function(int $id,int $depth)use(&$walk,&$pages,&$seen,$objects){if($depth>100||isset($seen[$id]))return;$seen[$id]=true;$obj=$objects[$id]??'';if(preg_match('~/Type\s*/Page\b~',$obj)){$pages[]=$id;return;}if(preg_match('~/Kids\s*\[([^]]*)\]~s',$obj,$kids)){preg_match_all('/(\d+)\s+\d+\s+R/',$kids[1],$refs);foreach($refs[1] as $ref)$walk((int)$ref,$depth+1);}};
        if($root!==null)$walk($root,0);if(!$pages)foreach($objects as $id=>$obj)if(preg_match('~/Type\s*/Page\b~',$obj))$pages[]=$id;return $pages;
    }
    private function cmap(string $data): array {
        $map=[];
        preg_match_all('/beginbfchar(.*?)endbfchar/s',$data,$blocks);foreach($blocks[1] as $b){preg_match_all('/<([a-f\d]+)>\s*<([a-f\d]+)>/i',$b,$pairs,PREG_SET_ORDER);foreach($pairs as $p)$map[strtoupper($p[1])]=mb_convert_encoding(hex2bin($p[2]),'UTF-8','UTF-16BE');}
        preg_match_all('/beginbfrange(.*?)endbfrange/s',$data,$blocks);foreach($blocks[1] as $b){preg_match_all('/<([a-f\d]+)>\s*<([a-f\d]+)>\s*(<([a-f\d]+)>|\[([^]]+)\])/i',$b,$ranges,PREG_SET_ORDER);foreach($ranges as $r){$start=hexdec($r[1]);$end=hexdec($r[2]);if($end-$start>65536)continue;preg_match_all('/<([a-f\d]+)>/i',$r[5]??'',$items);for($i=$start;$i<=$end;$i++){$key=strtoupper(str_pad(dechex($i),strlen($r[1]),'0',STR_PAD_LEFT));$hex=$r[4]!==''?str_pad(dechex(hexdec($r[4])+$i-$start),strlen($r[4]),'0',STR_PAD_LEFT):($items[1][$i-$start]??'');if($hex!==''&&strlen($hex)%4===0)$map[$key]=mb_convert_encoding(hex2bin($hex),'UTF-8','UTF-16BE');}}}
        return $map;
    }
    private function decode(string $bytes,array $map): string {
        if($map){$hex=strtoupper(bin2hex($bytes));$widths=array_unique(array_map('strlen',array_keys($map)));rsort($widths);$text='';for($i=0;$i<strlen($hex);){$found=false;foreach($widths as $w){$key=substr($hex,$i,$w);if(isset($map[$key])){$text.=$map[$key];$i+=$w;$found=true;break;}}if(!$found)$i+=2;}return $text;}
        if(str_starts_with($bytes,"\xFE\xFF"))return mb_convert_encoding(substr($bytes,2),'UTF-8','UTF-16BE');return mb_convert_encoding($bytes,'UTF-8','Windows-1252');
    }
    private function tokens(string $data): array {
        $tokens=[];$len=strlen($data);for($i=0;$i<$len;){$c=$data[$i];if(ctype_space($c)){$i++;continue;}if($c==='%'){while($i<$len&&!str_contains("\r\n",$data[$i]))$i++;continue;}
            if($c==='('){$i++;$depth=1;$s='';while($i<$len&&$depth){$c=$data[$i++];if($c==='\\'&&$i<$len){$c=$data[$i++];if($c==="\r"||$c==="\n"){if($c==="\r"&&($data[$i]??'')==="\n")$i++;continue;}if($c>='0'&&$c<='7'){$oct=$c;for($k=0;$k<2&&$i<$len&&$data[$i]>='0'&&$data[$i]<='7';$k++)$oct.=$data[$i++];$s.=chr(octdec($oct));}else $s.=match($c){'n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'f'=>"\x0c",default=>$c};}elseif($c==='('){$depth++;$s.=$c;}elseif($c===')'){if(--$depth)$s.=$c;}else $s.=$c;}$tokens[]=['text',$s];continue;}
            if($c==='<'&&($data[$i+1]??'')!=='<'){$end=strpos($data,'>',$i);if($end===false)break;$hex=preg_replace('/\s/','',substr($data,$i+1,$end-$i-1));$tokens[]=['text',@hex2bin(strlen($hex)%2?$hex.'0':$hex)?:''];$i=$end+1;continue;}
            if(str_contains('[]<>',$c)){$tokens[]=['symbol',$c];$i++;continue;}$start=$i++;while($i<$len&&!ctype_space($data[$i])&&!str_contains('()<>[]/%',$data[$i]))$i++;$tokens[]=['word',substr($data,$start,$i-$start)];
        }return $tokens;
    }
    private function content(string $data,array $fonts): string {
        $stack=[];$font='';$out='';$inText=false;$lastY=null;
        foreach($this->tokens($data) as [$kind,$value]){
            if($kind!=='word'||is_numeric($value)||str_starts_with($value,'/')){$stack[]=[$kind,$value];continue;}
            if($value==='BT'){$inText=true;$out.="\n";$lastY=null;}
            elseif($value==='ET'){$inText=false;$out.="\n";}
            elseif($inText&&$value==='Tf')$font=ltrim($stack[count($stack)-2][1]??'','/');
            elseif($inText&&in_array($value,['Td','TD','T*','Tm'],true)){if($value==='Tm'){$y=(float)($stack[count($stack)-1][1]??0);if($lastY!==null&&abs($lastY-$y)>2)$out.="\n";$lastY=$y;}elseif($value==='T*'||abs((float)($stack[count($stack)-1][1]??0))>0.1)$out.="\n";}
            elseif($inText&&in_array($value,['Tj','TJ',"'",'"'],true)){if($value==="'"||$value==='"')$out.="\n";foreach($stack as [$k,$v]){if($k==='text')$out.=$this->decode($v,$fonts[$font]??[]);elseif($value==='TJ'&&is_numeric($v)&&(float)$v<-120)$out.=' ';}}
            $stack=[];
        }$out=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$out);$out=preg_replace('/[ \t]+/u',' ',$out);$out=preg_replace('/\n[ ]*/',"\n",$out);
        if($this->options['pdf_lines']==='paragraphs')$out=preg_replace('/(?<!\n)\n(?!\n)/',' ',$out);return trim(preg_replace('/\n{3,}/',"\n\n",$out));
    }
    public function pdf(string $raw): string {
        if(!str_starts_with($raw,'%PDF-'))throw new RuntimeException('Ogiltig PDF-fil.',400);
        if(preg_match('~/Encrypt\b~',$raw))throw new RuntimeException('PDF-filen är krypterad. Spara en kopia utan lösenord före import.',400);
        preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s',$raw,$matches,PREG_SET_ORDER);$objects=[];foreach($matches as $m)$objects[(int)$m[1]]=$m[3];
        foreach($objects as $obj){if(!preg_match('~/Type\s*/ObjStm\b~',$obj)||!preg_match('~/First\s+(\d+)~',$obj,$first)||!preg_match('~/N\s+(\d+)~',$obj,$n))continue;$stream=$this->stream($obj);if($stream===null)continue;preg_match_all('/\d+/',substr($stream,0,(int)$first[1]),$nums);$nums=$nums[0];for($i=0;$i<min((int)$n[1],10000);$i++){if(!isset($nums[$i*2+1]))break;$start=(int)$first[1]+(int)$nums[$i*2+1];$end=isset($nums[$i*2+3])?(int)$first[1]+(int)$nums[$i*2+3]:strlen($stream);$objects[(int)$nums[$i*2]]=substr($stream,$start,$end-$start);}}
        $maps=[];foreach($objects as $id=>$obj)if(preg_match('~/ToUnicode\s+(\d+)\s+\d+\s+R~',$obj,$ref)){$stream=$this->stream($objects[(int)$ref[1]]??'');if($stream!==null)$maps[$id]=$this->cmap($stream);}
        $out=[];foreach($this->pageIds($objects) as $id){$obj=$objects[$id];$resources=$obj;$parent=$obj;$seen=[];for($i=0;$i<20&&preg_match('~/Parent\s+(\d+)\s+\d+\s+R~',$parent,$p);$i++){$pid=(int)$p[1];if(isset($seen[$pid]))break;$seen[$pid]=true;$parent=$objects[$pid]??'';$resources.=$parent;}
            if(preg_match('~/Resources\s+(\d+)\s+\d+\s+R~',$resources,$ref))$resources=$objects[(int)$ref[1]]??$resources;
            if(preg_match('~/Font\s+(\d+)\s+\d+\s+R~',$resources,$ref))$resources.=$objects[(int)$ref[1]]??'';
            $fonts=[];preg_match_all('~/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R~',$resources,$refs,PREG_SET_ORDER);foreach($refs as $ref)if(isset($maps[(int)$ref[2]]))$fonts[$ref[1]]=$maps[(int)$ref[2]];
            $text='';if(preg_match('~/Contents\s*(\[[^]]*\]|\d+\s+\d+\s+R)~s',$obj,$contents)){preg_match_all('/(\d+)\s+\d+\s+R/',$contents[1],$refs);$data='';foreach($refs[1] as $ref){$stream=$this->stream($objects[(int)$ref]??'');if($stream!==null)$data.=$stream."\n";}$text=$this->content($data,$fonts);}
            $out[]=$text;
        }
        if(!array_filter($out,fn($s)=>trim($s)!==''))throw new RuntimeException('PDF-filen saknar text som den inbyggda läsaren kan läsa. Skannade bilder och vissa PDF-kodningar kan inte konverteras. OCR krävs inte för vanliga text-PDF:er.',400);
        foreach($out as $i=>&$text)if($this->options['separators']&&trim($text)!=='')$text=($this->structured()?'## ':'').'Sida '.($i+1)."\n\n".$text;unset($text);return implode("\n\n",array_filter($out));
    }
}
