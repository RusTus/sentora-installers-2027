<?php
/** Adminer - Compact database management
* @link https://www.adminer.org/
* @author Jakub Vrana, https://www.vrana.cz/
* @copyright 2007 Jakub Vrana
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
* @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
* @version 6.3.0
*/namespace
Adminer;if(isset($_GET["status"]))$_GET["variables"]=$_GET["status"];if(isset($_GET["import"]))$_GET["sql"]=$_GET["import"];const
VERSION="6.3.0";error_reporting(24575);set_error_handler(function($Bd,$Fd){return!!preg_match('~^Undefined (array key|offset|index)~',$Fd);},E_WARNING|E_NOTICE);if(!preg_match('~^(unsafe_raw)?$~',ini_get("filter.default"))||ini_get("filter.default_flags")){foreach(['_GET','_POST','_COOKIE','_SERVER']as$X){$Hn=filter_input_array(constant("INPUT$X"),FILTER_UNSAFE_RAW);if($Hn)$$X=$Hn;}}$_COOKIE=array_filter($_COOKIE,'is_scalar');if(function_exists("mb_internal_encoding"))mb_internal_encoding("8bit");function
connection($h=null){return($h?:Db::$instance);}function
adminer(){return
Adminer::$instance;}function
driver(){return
Driver::$instance;}function
connect(){$mc=adminer()->credentials();$I=Driver::connect($mc[0],$mc[1],$mc[2]);return(is_object($I)?$I:null);}function
idf_unescape($u){if(!preg_match('~^[`\'"[]~',$u))return$u;$_g=substr($u,-1);return
str_replace($_g.$_g,$_g,substr($u,1,-1));}function
q($Q){return
connection()->quote($Q);}function
idx($Ga,$x,$l=null){return($Ga&&array_key_exists($x,$Ga)?$Ga[$x]:$l);}function
number($X){return
preg_replace('~[^0-9]+~','',$X);}function
int_type(){return'(tiny|small|medium|big)?int(eger|\d)?';}function
number_type(){return'(^('.int_type().'|decimal|numeric|number|real|(binary_|half_|scaled_)?float\d?|(binary_)?double( precision)?|(small)?money)$)';}function
text_type(){return'char|text'.(JUSH=="sql"?'|enum|set':'');}function
is_user_type($U){return
in_array($U,idx(driver()->structuredTypes(),lang(0),[]));}function
full_type_sql(array$n){$U=$n["type"];return(is_user_type($U)?idf_escape($U).substr($n["full_type"],strlen($U)):$n["full_type"]);}function
is_searchable(array$n,array$X){if(!isset($n["privileges"]["where"]))return
false;if(preg_match('~NULL$~',$X["op"]))return
true;$U=$n["type"];$fl=$X["val"];$Za='binary$|bytea|raw|image|bfile|^vector$'.(JUSH=="mssql"?'|^timestamp$':'|^bit').(JUSH=="oracle"?'|^blob|^long|rowid':'');if(preg_match("~$Za~",$U))return
false;if(preg_match(number_type(),$U)){$ki='-?\d+(\.\d+)?';$Eh='';if(preg_match('~IN$~',$X["op"])){$fl=preg_replace('~^\s*\(\s*(.*?)\s*\)\s*$~s','\1',$fl);$Eh="( *, *$ki)*";}elseif($X["op"]=="BETWEEN")$Eh="\\s+AND\\s+$ki";return(bool)preg_match('~^'.$ki.$Eh.'$~i',$fl);}if(preg_match('~^(small)?date|^timestamp~',$U))return(bool)preg_match('~^\d+-\d+-\d+~',$fl);if(preg_match('~^time~',$U))return(bool)preg_match('~^\d+:\d+~',$fl);if(preg_match('~^bool~',$U)||(JUSH=="mssql"&&$U=="bit"))return(bool)preg_match('~^(t|f|true|false|[01])$~i',$fl);return
true;}function
bracket_escape($u,$Sa=false){static$in=[':'=>':1',']'=>':2','['=>':3','"'=>':4','='=>':5'];return
strtr($u,($Sa?array_flip($in):$in));}function
url_escape($Q){static$in=[];if(!$in){$in=[' '=>'+'];foreach(str_split("\"'<>#%&+=?".ini_get("arg_separator.input"))as$qb)$in[$qb]=sprintf('%%%02X',ord($qb));for($s=0;$s<256;$s++){if($s<32||$s>126)$in[chr($s)]=sprintf('%%%02X',$s);}}return
strtr((string)$Q,$in);}function
min_version($io,$Vg="",$h=null){$h=connection($h);$Cl=$h->server_info;if($Vg&&preg_match('~([\d.]+)-MariaDB~',$Cl,$A)){$Cl=$A[1];$io=$Vg;}return$io&&version_compare($Cl,$io)>=0;}function
charset(Db$g){return(min_version("5.5.3",0,$g)?"utf8mb4":"utf8");}function
ini_set($Ii,$Y){return(function_exists('ini_set')?\ini_set($Ii,$Y):false);}function
ini_bool($Pf){$X=ini_get($Pf);return(preg_match('~^(on|true|yes)$~i',$X)||(int)$X);}function
ini_bytes($Pf){$X=ini_get($Pf);switch(strtolower(substr($X,-1))){case'g':$X=(int)$X*1024;case'm':$X=(int)$X*1024;case'k':$X=(int)$X*1024;}return$X;}function
max_input_vars($J,$aj){$Zg=(int)ini_get("max_input_vars");return($Zg?(int)floor(($Zg-$aj)/$J):0);}function
max_input_vars_error(){$Pf="max_input_vars";return
lang(1,"<b>$Pf = ".ini_get($Pf)."</b>");}function
sid(){static$I;if($I===null)$I=(SID&&!($_COOKIE&&ini_bool("session.use_cookies")));return$I;}function
set_password($ho,$N,$V,$E){$_SESSION["pwds"][$ho][$N][$V]=($_COOKIE["adminer_key"]&&is_string($E)?[encrypt_string($E,$_COOKIE["adminer_key"])]:$E);}function
get_password(){$I=get_session("pwds");if(is_array($I))$I=($_COOKIE["adminer_key"]?decrypt_string($I[0],$_COOKIE["adminer_key"]):false);return$I;}function
get_val($G,$n=0,$Ub=null){$Ub=connection($Ub);$H=$Ub->query($G);if(!is_object($H))return
false;$J=$H->fetch_row();return($J?$J[$n]:false);}function
get_vals($G,$d=0){$I=[];$H=connection()->query($G);if(is_object($H)){while($J=$H->fetch_row())$I[]=$J[$d];}return$I;}function
get_key_vals($G,$h=null,$Fl=true){$h=connection($h);$I=[];$H=$h->query($G);if(is_object($H)){while($J=$H->fetch_row()){if($Fl)$I[$J[0]]=$J[1];else$I[]=$J[0];}}return$I;}function
get_rows($G,$h=null,$m="<p class='error'>"){$Ub=connection($h);$I=[];$H=$Ub->query($G);if(is_object($H)){while($J=$H->fetch_assoc())$I[]=$J;}elseif(!$H&&!$h&&$m&&(defined('Adminer\PAGE_HEADER')||$m=="-- "))echo$m.adminer()->error()."\n";return$I;}function
unique_array($J,array$w){foreach($w
as$v){if(preg_match("~^(PRIMARY|UNIQUE)$~",$v["type"])&&!$v["partial"]&&!$v["invalid"]){$I=[];foreach($v["columns"]as$x){if(!isset($J[$x]))continue
2;$I[$x]=$J[$x];}return$I;}}}function
where_function($He,$d,array$n){if($He=="md5")return
driver()->md5($d,$n)?:$d;return(in_array($He,driver()->functions)||in_array($He,driver()->grouping)?apply_sql_function($He,$d):$d);}function
where(array$Z,array$o=[]){$I=[];foreach((array)$Z["where"]as$x=>$X){$x=bracket_escape($x,true);$d=idf_escape($x);$n=idx($o,$x,[]);$ee=$n["type"];$cg=$n&&(is_blob($n)||preg_match('~binary~',$ee));$I[]=$d.($cg&&!is_utf8($X)?" = ".driver()->quoteBinary($X):(JUSH=="sql"&&$ee=="json"?" = CAST(".q($X)." AS JSON)":(JUSH=="pgsql"&&preg_match('~^jsonb?$~',$n["full_type"])?"::jsonb = ".q($X)."::jsonb":(JUSH=="pgsql"&&preg_match('~\.~',$ee)?"::text = ".q($X):(JUSH=="sql"&&is_numeric($X)&&preg_match('~\.~',$X)?" LIKE ".q($X):(JUSH=="mssql"&&strpos($ee,"datetime")===false?" LIKE ".q(preg_replace('~[_%[]~','[\0]',$X)):" = ".unconvert_field($n,q($X))))))));if(JUSH=="sql"&&preg_match('~char|text~',$ee)&&preg_match("~[^ -@]~",$X))$I[]="$d = ".q($X)." COLLATE ".charset(connection())."_bin";}foreach((array)$Z["null"]as$x)$I[]=idf_escape($x)." IS NULL";foreach((array)$Z["col"]as$s=>$Fb){$X=idx($Z["val"],$s);$I[]=where_function(idx($Z["fun"],$s),idf_escape($Fb),idx($o,$Fb,[])).($X!==null?" = ".q($X):" IS NULL");}return
implode(" AND ",$I);}function
where_columns(array$o){$I=[];foreach((array)$_GET["null"]as$x)$I[$x]=true;foreach(array_keys((array)$_GET["where"])as$x)$I[bracket_escape($x,true)]=true;foreach((array)$_GET["col"]as$Fb)$I[$Fb]=true;return
array_intersect_key($I,$o);}function
where_check($X,array$o=[]){parse_str($X,$tb);return
where($tb,$o);}function
where_link($s,$d,$Y,$Fi="="){$Ci=($Y!==null?$Fi:"IS NULL");return"&where[$s][col]=".url_escape($d).($Ci!=first(adminer()->operators())?"&where[$s][op]=".url_escape($Ci):"")."&where[$s][val]=".url_escape($Y);}function
convert_fields(array$e,array$o,array$M=[]){$I="";foreach($e
as$x=>$X){if($M&&!in_array(idf_escape($x),$M))continue;$Ha=convert_field($o[$x]);if($Ha)$I
.=", $Ha AS ".idf_escape($x);}return$I;}function
cookie_path(){return
strtr(preg_replace('~\?.*~','',$_SERVER["REQUEST_URI"]),[";"=>"%3B",","=>"%2C"]);}function
cookie($B,$Y,$Jg=2592000){header("Set-Cookie: $B=".rawurlencode($Y).($Jg?"; expires=".gmdate("D, d M Y H:i:s",time()+$Jg)." GMT":"")."; path=".cookie_path().(HTTPS?"; secure":"").($B=="adminer_import"?"":"; HttpOnly")."; SameSite=lax",false);}function
php_error_text($m){return(ini_bool("html_errors")?html_entity_decode(strip_tags($m)):$m);}function
php_errors($lb){$Ed=[];set_error_handler(function($Bd,$m)use(&$Ed){$Ed[]=preg_replace('~^\w+\([^)]*\)( \[[^]]*])?:\s*~','',php_error_text($m));return
true;},E_WARNING|E_NOTICE);$lb();restore_error_handler();return$Ed;}function
get_url($Qn,$dc){$Ed=php_errors(function()use($Qn,$dc,&$I,&$gf){$http_response_header=null;$I=file_get_contents($Qn,false,$dc);$gf=(function_exists('http_get_last_response_headers')?http_get_last_response_headers():$http_response_header);});return[$I,(preg_match('~^HTTP/[\d.]+ (\d+)~',idx($gf,0,''),$A)?$A[1]:''),(array)$gf,($I===false?implode("\n",$Ed):''),];}function
json_decode_exact($kg){$kg=str_replace(['\\\\','\\"'],['\\u005c','\\u0022'],$kg);$kg=preg_replace('~"(\\\\u0001[^"]*+")|"[^"]*+"(*SKIP)(*FAIL)~','"\\\\u0001$1',$kg);return
json_decode(preg_replace('~"[^"]*+"(*SKIP)(*FAIL)|-?\d[-+.\deE]*+~','"\\\\u0001$0"',$kg));}function
json_scalar($X){return(is_string($X)&&substr($X,0,1)=="\1"?substr($X,1):$X);}function
json_encode_exact($X,$qe=0){$kg=str_replace(['\\\\','\\"'],["\2","\3"],json_encode($X,$qe));$kg=preg_replace('~"\\\\u0001(-?\d[^"\\\\]*)"|(")\\\\u0001(\\\\u0001[^"]*+")|"[^"]*+"(*SKIP)(*FAIL)~','$1$2$3',$kg);return
str_replace(["\2","\3"],['\\\\','\\"'],$kg);}function
get_settings($hc){parse_str($_COOKIE[$hc],$Gl);return$Gl;}function
get_setting($x,$hc="adminer_settings",$l=null){return
idx(get_settings($hc),$x,$l);}function
save_settings(array$Gl,$hc="adminer_settings"){$Y=http_build_query($Gl+get_settings($hc));cookie($hc,$Y);$_COOKIE[$hc]=$Y;}function
restart_session(){if(!ini_bool("session.use_cookies")&&session_status()==PHP_SESSION_NONE)session_start();}function
stop_session($te=false){$Tn=ini_bool("session.use_cookies");if(!$Tn||$te){session_write_close();if($Tn&&ini_set("session.use_cookies",'0')===false)session_start();}}function&get_session($x){return$_SESSION[$x][DRIVER][SERVER][$_GET["username"]];}function
set_session($x,$X){$_SESSION[$x][DRIVER][SERVER][$_GET["username"]]=$X;}function
auth_url($ho,$N,$V,$k=null){$Pn=remove_from_uri(implode("|",array_keys(SqlDriver::$drivers))."|username|ext|".($k!==null?"db|":"").($ho=='mssql'||$ho=='pgsql'?"":"ns|").session_name());preg_match('~([^?]*)\??(.*)~',$Pn,$A);return"$A[1]?".(sid()?SID."&":"").($_GET["ext"]?"ext=".url_escape($_GET["ext"])."&":"").($ho!="server"||$N!=""?url_escape($ho)."=".url_escape($N)."&":"")."username=".url_escape($V).($k!=""?"&db=".url_escape($k):"").($A[2]?"&$A[2]":"");}function
is_ajax(){return($_SERVER["HTTP_X_REQUESTED_WITH"]=="XMLHttpRequest");}function
redirect($Rg,$ph=null,$P=303){if($ph!==null){restart_session();$_SESSION["messages"][preg_replace('~^[^?]*~','',($Rg!==null?$Rg:$_SERVER["REQUEST_URI"]))][]=$ph;}if($Rg!==null){if($Rg=="")$Rg=".";header("Location: $Rg",true,$P);exit;}}function
query_redirect($G,$Rg,$ph,$tk=true,$Md=true,$Xd=false,$Um=""){if($Md){$cm=microtime(true);$Xd=!connection()->query($G);$Um=format_time($cm);}$Vl=($G?adminer()->messageQuery($G,$Um,$Xd):"");if($Xd){adminer()->error
.=adminer()->error().$Vl.script("messagesPrint();")."<br>";return
false;}if($tk)redirect($Rg,$ph.$Vl);return
true;}class
Queries{static$queries=[];static$start=0;}function
remember_query($G){if(!Queries::$start)Queries::$start=microtime(true);Queries::$queries[]=(driver()->delimiter!=';'?$G:(preg_match('~;$~',$G)?"DELIMITER ;;\n$G;\nDELIMITER ":$G).";");}function
queries($G){remember_query($G);return
connection()->query($G);}function
apply_queries($G,array$T,$Gd='Adminer\table'){foreach($T
as$R){if(!queries("$G ".$Gd($R)))return
false;}return
true;}function
queries_redirect($Rg,$ph,$tk){$kk=implode("\n",Queries::$queries);$Um=format_time(Queries::$start);return
query_redirect($kk,$Rg,$ph,$tk,false,!$tk,$Um);}function
format_time($cm){return
lang(2,max(0,microtime(true)-$cm));}function
relative_uri($Pn=''){return
preg_replace_callback('~^[^?]*~',function($A){return
str_replace(":","%3A",$A[0]);},preg_replace('~^[^?]*/([^?]*)~','\1',($Pn?:$_SERVER["REQUEST_URI"])));}function
remove_from_uri($hj=""){return
substr(preg_replace("~(?<=[?&])($hj".(SID?"":"|".session_name()).")=[^&]*&~",'',relative_uri()."&"),0,-1);}function
get_files($B,$Ac=false){$ge=$_FILES[$B];if(!$ge)return
null;foreach($ge
as$x=>$X)$ge[$x]=(array)$X;$I=[];foreach($ge["error"]as$x=>$m){if($m)return$m;$p=$ge["name"][$x];$cn=$ge["tmp_name"][$x];$bc=file_get_contents($Ac&&preg_match('~\.gz$~',$p)?"compress.zlib://$cn":$cn);if($Ac){$cm=substr($bc,0,3);if(function_exists("iconv")&&preg_match("~^\xFE\xFF|^\xFF\xFE~",$cm))$bc=iconv("utf-16","utf-8",$bc);elseif($cm=="\xEF\xBB\xBF")$bc=substr($bc,3);}$I[]=[$p,$bc];}return$I;}function
get_file($x,$Ac=false,$Ic=""){$je=get_files($x,$Ac);if(!is_array($je))return$je;$I='';foreach($je
as$ge){$bc=$ge[1];$I
.=$bc;if($Ic)$I
.=(preg_match("($Ic\\s*\$)",$bc)?"":$Ic)."\n\n";}return$I;}function
upload_error($m){$hh=($m==UPLOAD_ERR_INI_SIZE?ini_get("upload_max_filesize"):0);return($m?lang(3).($hh?" ".lang(4,$hh):""):lang(5));}function
is_utf8($X){return(preg_match('~~u',$X)&&!preg_match('~[\0-\x8\xB\xC\xE-\x1F]~',$X));}function
utf8_length($X){return
strlen(preg_replace('~[\x80-\xBF]~','',$X));}function
format_number($X){preg_match('~^#+([^#0]+)(?:(#+)\1)?(#*0)$~u',lang(6),$A);$Kl=strlen($A[3]);$I=number_format($X,0,".","");$I=preg_replace('~\B(?=(\d{'.(strlen($A[2])?:$Kl).'})*\d{'.$Kl.'}$)~',$A[1],$I);return
strtr($I,preg_split('~~u',lang(7),-1,PREG_SPLIT_NO_EMPTY));}function
format_status(array$S,$x){$X=idx($S,$x,'?');if(!is_numeric($X))return
h($X);if($X<0)return'?';$Ca=($x=="Rows"&&(JUSH=="sqlite"||$S["Engine"]==(JUSH=="pgsql"?"table":"InnoDB")));$X=in_array($x,["Data_length","Index_length","Data_free"])?adminer()->formatSizeValue($X):format_number($X);return($Ca?"~ ":"").$X;}function
friendly_url($X){return
preg_replace('~\W~i','-',$X);}function
table_status1($R,$Yd=false){$I=table_status($R,$Yd);return($I?reset($I):["Name"=>$R]);}function
column_foreign_keys($R){$I=[];foreach(adminer()->foreignKeys($R)as$q){foreach($q["source"]as$X)$I[$X][]=$q;}return$I;}function
fields_from_edit(){$I=[];foreach((array)$_POST["field_keys"]as$x=>$X){if($X!=""){$X=bracket_escape($X);$_POST["function"][$X]=$_POST["field_funs"][$x];$_POST["fields"][$X]=$_POST["field_vals"][$x];}}foreach((array)$_POST["fields"]as$x=>$X){$B=bracket_escape($x,true);$I[$B]=["field"=>$B,"full_type"=>"","type"=>"","privileges"=>["insert"=>1,"update"=>1,"where"=>1,"order"=>1],"null"=>true,"auto_increment"=>($B==driver()->primary),];}return$I;}function
dump_headers($uf,$Ih=false){$I=adminer()->dumpHeaders($uf,$Ih);$cj=$_POST["output"];if($cj!="text"||$I=="tar"){$Qb=($cj!="text"&&$cj!="file"&&preg_match('~^[0-9a-z]+$~',$cj)?".$cj":"");header("Content-Disposition: attachment; filename=".adminer()->dumpFilename($uf).".$I$Qb");}session_write_close();if(!ob_get_level())ob_start(null,4096);ob_flush();flush();return$I;}function
dump_csv(array$J){$vn=$_POST["format"]=="tsv";foreach($J
as$x=>$X){if(preg_match('~["\n]|^0[^.]|\.\d*0$|'.($vn?'\t':'[,;]|^$').'~',$X))$J[$x]='"'.str_replace('"','""',$X).'"';}echo
implode(($_POST["format"]=="csv"?",":($vn?"\t":";")),$J)."\r\n";}function
parse_csv($pc,$rl){$I=[];preg_match_all('~(?>"[^"]*"|[^"\r\n]+)+~',$pc,$Xg);foreach($Xg[0]as$J){preg_match_all("~((?>\"[^\"]*\")+|[^$rl]*)$rl~",$J.$rl,$Yg);$I[]=$Yg[1];}return$I;}function
csv_value($X){return(preg_match('~^".*"$~s',$X)?idf_unescape($X):$X);}function
apply_sql_function($r,$d){return($r?($r=="unixepoch"?"DATETIME($d, '$r')":($r=="count distinct"?"COUNT(DISTINCT ":strtoupper("$r("))."$d)"):$d);}function
get_temp_dir(){return
ini_get("upload_tmp_dir")?:sys_get_temp_dir();}function
file_open_lock($p){if(is_link($p))return;$_e=@fopen($p,"c+");if(!$_e)return;@chmod($p,0660);if(!flock($_e,LOCK_EX)){fclose($_e);return;}return$_e;}function
file_write_unlock($_e,$tc){rewind($_e);fwrite($_e,$tc);ftruncate($_e,strlen($tc));file_unlock($_e);}function
file_unlock($_e){flock($_e,LOCK_UN);fclose($_e);}function
first(array$Ga){return
reset($Ga);}function
password_file($i){$p=get_temp_dir()."/adminer.key";if(!$i&&!file_exists($p))return'';$_e=file_open_lock($p);if(!$_e)return'';$I=stream_get_contents($_e);if(!$I){$I=rand_string();file_write_unlock($_e,$I);}else
file_unlock($_e);return$I;}function
rand_string(){return(function_exists('random_bytes')?bin2hex(random_bytes(16)):md5(uniqid(strval(mt_rand()),true)));}function
select_value($X,$_,array$n,$Sm,array$Cj=[]){if(is_array($X)){$I="";if(array_filter($X,'is_array')==array_values($X)){$qg=[];foreach($X
as$W)$qg+=array_fill_keys(array_keys($W),null);foreach(array_keys($qg)as$ng)$I
.="<th>".h($ng);foreach($X
as$W){$I
.="<tr>";foreach(array_merge($qg,$W)as$ao)$I
.="<td>".select_value($ao,$_,$n,$Sm,$Cj);}}else{foreach($X
as$ng=>$W)$I
.="<tr>".($X!=array_values($X)?"<th>".h($ng):"")."<td>".select_value($W,$_,$n,$Sm,$Cj);}return"<table>$I</table>";}if(!$_)$_=adminer()->selectLink($X,$n);if($_===null){if(is_mail($X))$_="mailto:$X";if(is_url($X))$_=$X;}$X=driver()->value($X,$n);$I=adminer()->editVal($X,$n);if($I!==null){if(!is_utf8($I))$I="\0";elseif($Sm!=""&&is_shortable($n))$I=shorten_utf8($I,max(0,+$Sm),"",$Cj);else$I=highlight_matches($I,$Cj);}return
adminer()->selectVal($I,$_,$n,$X);}function
is_blob(array$n){return
preg_match('~blob|bytea|raw|file'.(JUSH=="mssql"?'|binary|image':'').'~',$n["type"])&&!in_array($n["type"],idx(driver()->structuredTypes(),lang(0),[]));}function
is_identity_always(array$n){return$n["auto_increment"]&&(JUSH=="mssql"||$n["default"]=="GENERATED ALWAYS AS IDENTITY");}function
is_mail($rd){$Ja='[-a-z0-9!#$%&\'*+/=?^_`{|}~]';$bd='[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])';$Bj="$Ja+(\\.$Ja+)*@($bd?\\.)+$bd";return
is_string($rd)&&preg_match("(^$Bj(,\\s*$Bj)*\$)i",$rd);}function
is_url($Q){$bd='[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])';return
preg_match("~^((https?):)?//($bd?\\.)+$bd(:\\d+)?(/\\S*)?(\\?\\S*)?(#\\S*)?\$~iD",$Q);}function
is_ipv6($pa){$Oe='[\da-f]{1,4}';$bg='\d{1,3}(\.\d{1,3}){3}';return(bool)preg_match("~^(($Oe:){7}$Oe|($Oe:){6}$bg|(($Oe:)*$Oe)?::(($Oe:)*($Oe|$bg))?)$~iD",$pa);}function
is_shortable(array$n){return!preg_match('~'.number_type().'|date|time|year~',$n["type"]);}function
url_host($qf){return(strpos($qf,":")!==false?"[$qf]":$qf);}function
server_parts(array$wj){return["scheme"=>(string)$wj["scheme"],"host"=>(string)$wj["host"],"port"=>(string)$wj["port"],"socket"=>(string)$wj["socket"],"path"=>(string)$wj["path"],];}function
parse_server($N){if($N=="")return
server_parts([]);if($N[0]==":"&&!is_ipv6($N)){$Gk=substr($N,1);if(preg_match('~^\d+$~D',$Gk))return
server_parts(["port"=>$Gk]);return(preg_match('~^/[-\w.:/]*$~D',$Gk)?server_parts(["socket"=>$Gk]):null);}$dl="";if(preg_match('~^([-+.\w]+)://~',$N,$A)){$dl=strtolower($A[1]);$N=substr($N,strlen($A[0]));}if(preg_match('~^\[(.+)](:(\d+))?(/[-\w./]*)?$~D',$N,$A))return(is_ipv6($A[1])?server_parts(["scheme"=>$dl,"host"=>$A[1],"port"=>$A[3],"path"=>$A[4]]):null);if(is_ipv6($N))return
server_parts(["scheme"=>$dl,"host"=>$N]);if(preg_match('~^(/[-\w./]*)(:(\d+))?$~D',$N,$A))return
server_parts(["scheme"=>$dl,"host"=>$A[1],"port"=>$A[3]]);return(preg_match('~^([-\w.]*)(:(\d+))?(/[-\w./]*)?$~D',$N,$A)?server_parts(["scheme"=>$dl,"host"=>$A[1],"port"=>$A[3],"path"=>$A[4]]):null);}function
count_rows($R,array$Z,$dg,array$Oe){$G=" FROM ".table($R).($Z?" WHERE ".implode(" AND ",$Z):"");return($dg&&(JUSH=="sql"||count($Oe)==1)?"SELECT COUNT(DISTINCT ".implode(", ",$Oe).")$G":"SELECT COUNT(*)".($dg?" FROM (SELECT 1$G GROUP BY ".implode(", ",$Oe).") x":$G));}function
slow_query($G){$Ml=driver()->slowQuery($G,adminer()->queryTimeout());ob_flush();flush();return@get_key_vals(($Ml?:$G),null,false);}function
get_token(){$qk=rand(1,1e6);return($qk^$_SESSION["token"]).":$qk";}function
verify_token(){list($dn,$qk)=explode(":",$_POST["token"]);return($qk^$_SESSION["token"])==$dn&&in_array($_SERVER["HTTP_SEC_FETCH_SITE"],["","same-origin"]);}function
compress_alphabet(){return
strtr(implode(range('"','~')),"'\\","!\n");}function
decompress_string($Q,$Pc=""){$_a=array_flip(str_split(compress_alphabet()));$y=strlen($Q);$do=($y?13*($y-1)/2-$_a[$Q[0]]:0);$Za="";$Gk=0;$Hk=0;for($s=1;$s<$y;$s+=2){$Gk=($Gk<<13)+$_a[$Q[$s]]*93+$_a[$Q[$s+1]];$Hk+=13;while($Hk>=8&&$do>=8){$Hk-=8;$do-=8;$Za
.=chr($Gk>>$Hk);$Gk&=(1<<$Hk)-1;}}if($Za=="")return"";if($Pc!=""&&function_exists('inflate_init'))return
inflate_add(inflate_init(ZLIB_ENCODING_RAW,['dictionary'=>$Pc]),$Za,ZLIB_FINISH);return($Pc==""&&function_exists('gzinflate')?gzinflate($Za):inflate($Za,$Pc));}function
inflate($Za,$Pc=""){$Gg=[3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];$Hg=[0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];$Tc=[1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];$Vc=[0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];$I=$Pc;$F=0;do{$le=inflate_bits($Za,$F,1);$U=inflate_bits($Za,$F,2);if(!$U){$F=($F+7)&~7;$y=inflate_bits($Za,$F,16);$F+=16;$I
.=substr($Za,$F>>3,$y);$F+=$y<<3;}else{if($U==1){$Pg=array_merge(array_fill(0,144,8),array_fill(0,112,9),array_fill(0,24,7),array_fill(0,8,8));$Wc=array_fill(0,30,5);}else{$Og=inflate_bits($Za,$F,5)+257;$Uc=inflate_bits($Za,$F,5)+1;$Ni=[16,17,18,0,8,7,9,6,10,5,11,4,12,3,13,2,14,1,15];$wh=array_fill(0,19,0);$vh=inflate_bits($Za,$F,4)+4;for($s=0;$s<$vh;$s++)$wh[$Ni[$s]]=inflate_bits($Za,$F,3);$xh=inflate_table($wh);$Ig=[];while(count($Ig)<$Og+$Uc){$rm=inflate_symbol($Za,$F,$xh);if($rm==16)$Ig=array_merge($Ig,array_fill(0,inflate_bits($Za,$F,2)+3,end($Ig)));elseif($rm==17)$Ig=array_merge($Ig,array_fill(0,inflate_bits($Za,$F,3)+3,0));elseif($rm==18)$Ig=array_merge($Ig,array_fill(0,inflate_bits($Za,$F,7)+11,0));else$Ig[]=$rm;}$Pg=array_slice($Ig,0,$Og);$Wc=array_slice($Ig,$Og);}$Qg=inflate_table($Pg);$Yc=inflate_table($Wc);while(($rm=inflate_symbol($Za,$F,$Qg))!=256){if($rm<256)$I
.=chr($rm);else{$y=$Gg[$rm-257]+inflate_bits($Za,$F,$Hg[$rm-257]);$Xc=inflate_symbol($Za,$F,$Yc);$C=strlen($I)-$Tc[$Xc]-inflate_bits($Za,$F,$Vc[$Xc]);for($s=0;$s<$y;$s++)$I
.=$I[$C+$s];}}}}while(!$le);return($Pc==""?$I:substr($I,strlen($Pc)));}function
inflate_bits($Za,&$F,$jc){$I=0;for($s=0;$s<$jc;$s++){$I+=((ord($Za[$F>>3])>>($F&7))&1)<<$s;$F++;}return$I;}function
inflate_table(array$Ig){$R=[];$Eb=0;for($ab=1;$ab<=max($Ig);$ab++){foreach($Ig
as$rm=>$y){if($y==$ab){$R[$ab][$Eb]=$rm;$Eb++;}}$Eb<<=1;}return$R;}function
inflate_symbol($Za,&$F,array$R){$Eb=0;$ab=0;do{$Eb=($Eb<<1)+inflate_bits($Za,$F,1);$ab++;}while(!isset($R[$ab][$Eb]));return$R[$ab][$Eb];}function
script($Rl,$hn="\n"){return"<script".nonce().">$Rl</script>$hn";}function
script_src($Qn,$Ec=false){return"<script src='".h($Qn)."'".nonce().($Ec?" defer":"")."></script>\n";}function
nonce(){return' nonce="'.get_nonce().'"';}function
on($Hd,$Xe,$Da=null){$Fa=[];foreach(array_slice(func_get_args(),2)as$X)$Fa[]=json_encode($X,JSON_UNESCAPED_UNICODE);return" data-on$Hd='".str_replace(['&','<',"'"],['&amp;','&lt;','&#039;'],"$Xe(".implode(", ",$Fa).")")."'";}function
input_hidden($B,$Y=""){return"<input type='hidden' name='".h($B)."' value='".h($Y)."'>\n";}function
input_token(){return
input_hidden("token",get_token());}function
target_blank(){return' target="_blank" rel="noreferrer noopener"';}function
h($Q){return
str_replace(['&','<','"',"'","\0"],['&amp;','&lt;','&quot;','&#039;','&#0;'],$Q);}function
nl_br($Q){return
str_replace("\n","<br>",$Q);}function
checkbox($B,$Y,$wb,$ug="",$c="",$Bb="",$wg=""){$I="<input type='checkbox' name='$B' value='".h($Y)."'".($wb?" checked":"").($ug==""&&$Bb?" class='$Bb'":"").($wg?" aria-labelledby='$wg'":"").$c.">";return($ug!=""?"<label".($Bb?" class='$Bb'":"").">$I".h($ug)."</label>":$I);}function
optionlist($Ki,$nl=null,$Un=false){$I="";foreach($Ki
as$ng=>$W){$Mi=[$ng=>$W];if(is_array($W)){$I
.='<optgroup label="'.h($ng).'">';$Mi=$W;}foreach($Mi
as$x=>$X)$I
.='<option'.($Un||is_string($x)?' value="'.h($x).'"':'').($nl!==null&&($Un||is_string($x)?(string)$x:$X)===$nl?' selected':'').'>'.h($X);if(is_array($W))$I
.='</optgroup>';}return$I;}function
group_system(array$Qh,$cl=false){$I=[];$sm=[];foreach($Qh
as$B){if($cl?driver()->isSystem(DB,$B):driver()->isSystem($B))$sm[]=$B;else$I[]=$B;}if($sm)$I[lang(8,'')]=$sm;return$I;}function
html_select($B,array$Ki,$Y="",$c="",$wg=""){static$ug=0;$vg="";if(!$wg&&substr($Ki[""],0,1)=="("){$ug++;$wg="label-$ug";$vg="<option value='' id='$wg'>".h($Ki[""]);unset($Ki[""]);}return"<select name='".h($B)."'".($wg?" aria-labelledby='$wg'":"")."$c>".$vg.optionlist($Ki,$Y)."</select>";}function
html_radios($B,array$Ki,$Y="",$rl=""){$I="";foreach($Ki
as$x=>$X)$I
.="<label><input type='radio' name='".h($B)."' value='".h($x)."'".($x==$Y?" checked":"").">".h($X)."</label>$rl";return$I;}function
confirm($ph=""){return
on('click','confirmClick',$ph?:lang(9));}function
print_fieldset($t,$Fg,$lo=false){echo"<fieldset><legend>","<a href='#fieldset-$t' class='toggle'>$Fg</a>","</legend>","<div id='fieldset-$t'".($lo?"":" class='hidden'").">\n";}function
bold($bb,$Bb=""){return($bb?" class='active $Bb'":($Bb?" class='$Bb'":""));}function
js_escape($Q){return
str_replace("<","\\x3C",addcslashes($Q,"\r\n'\\"));}function
js_escape_re($Q){return
addcslashes(preg_quote($Q,"/"),"\r\n");}function
pagination_href($D){return
remove_from_uri("page|next").($D?"&page=$D".($_GET["next"]!=""?"&next=".url_escape($_GET["next"]):""):"");}function
pagination($D,$qc){return" ".($D==$qc?($D?"<b>".($D+1)."</b>":$D+1):'<a href="'.h(pagination_href($D)).'">'.($D+1)."</a>");}function
hidden_fields(array$dk,array$zf=[],$Sj=''){$I=false;foreach($dk
as$x=>$X){if(!in_array($x,$zf)){if(is_array($X))hidden_fields($X,[],$x);else{$I=true;echo
input_hidden(($Sj?$Sj."[$x]":$x),$X);}}}return$I;}function
hidden_fields_get(){echo(sid()?input_hidden(session_name(),session_id()):''),($_GET["ext"]?input_hidden("ext",$_GET["ext"]):""),(isset($_GET[DRIVER])?input_hidden(DRIVER,SERVER):""),input_hidden("username",$_GET["username"]);}function
on_upload_progress(&$On){$On=(ini_bool("session.upload_progress.enabled")&&ini_get("session.upload_progress.name")?rand_string():"");return($On?on('submit','uploadProgress',ME."upload=$On",SESSION_NAME."=$On"):"");}function
file_input($c,$Gk=""){$bh="max_file_uploads";$ch=ini_get($bh);$hh="upload_max_filesize";$ih=ini_bytes($hh);$Oj=ini_bytes("post_max_size");if($Oj&&$Oj<$ih){$hh="post_max_size";$ih=$Oj;}$jh=ini_get($hh);return(ini_bool("file_uploads")?"<input type='file'$c".on('change','fileChange',(int)$ch,lang(10,"$bh = $ch"),$ih,lang(10,"$hh = $jh")).">$Gk":lang(11));}function
enum_input($U,$c,array$n,$Y,$ud=""){preg_match_all("~".driver()->enumLength."~",$n["length"],$Xg);$Sj=($n["type"]=="enum"?"val-":"");$wb=(is_array($Y)?in_array("null",$Y):$Y===null);$I=($n["null"]&&$Sj?"<label><input type='$U'$c value='null'".($wb?" checked":"")."><i>$ud</i></label>":"");foreach($Xg[0]as$X){$X=stripcslashes(idf_unescape($X));$wb=(is_array($Y)?in_array($Sj.$X,$Y):$Y===$X);$I
.=" <label><input type='$U'$c value='".h($Sj.$X)."'".($wb?' checked':'').'>'.h(adminer()->editVal($X,$n)).'</label>';}return$I;}function
input(array$n,$Y,$r,$Qa=false,$Ln=false){$B=h(bracket_escape($n["field"]));echo"<td class='function'>";$Ad=driver()->enumLength($n);if($Ad){$n["type"]="enum";$n["length"]=$Ad;}$Ki=($n["type"]=="enum"||$n["type"]=="set");if(is_array($Y)&&!$r&&!$Ki)$r="json";$kg=($r=="json"||preg_match('~^jsonb?$~',$n["full_type"]));if($kg&&$Y!=''&&(JUSH!="pgsql"||$n["type"]!="json")&&(is_array($Y)||!$_POST["save"])){$qe=JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;$Y=(is_array($Y)?json_encode($Y,$qe):json_encode_exact(json_decode_exact($Y),$qe));}$Fk=($Ln&&is_identity_always($n));if($Fk&&!$_POST["save"])$r=null;$Ie=(isset($_GET["select"])||$Fk?["orig"=>lang(12)]:[])+adminer()->editFunctions($n);$c=" name='fields[$B]".($Ki?"[]":"")."'".($Qa?" autofocus":"");echo
driver()->unconvertFunction($n)." ";$R=$_GET["edit"]?:$_GET["select"];if($n["type"]=="enum")echo
h($Ie[""])."<td>".adminer()->editInput($R,$n,$c,$Y);else{$Ze=(in_array($r,$Ie)||isset($Ie[$r]));$me=0;foreach($Ie
as$x=>$X){if($x===""||!$X)break;$me++;}echo(count($Ie)>1?"<select name='function[$B]'".on('change','functionChange').on_help_value('^SQL$').">".optionlist($Ie,$r===null||$Ze?$r:"")."</select>":h(reset($Ie)))."<td".($me&&count($Ie)>1?on('input','skipOriginal',$me):"").">";$Rf=adminer()->editInput($R,$n,$c,$Y);if($Rf!="")echo$Rf;elseif(preg_match('~bool~',$n["type"]))echo"<input type='hidden'$c value='0'>"."<input type='checkbox'".(preg_match('~^(1|t|true|y|yes|on)$~i',$Y)?" checked":"")."$c value='1'>";elseif($n["type"]=="set")echo
enum_input("checkbox",$c,$n,(is_string($Y)?explode(",",$Y):$Y));elseif(is_blob($n)&&ini_bool("file_uploads"))echo"<input type='file' name='fields-$B'>";elseif($kg)echo"<textarea$c cols='50' rows='12' class='jush-json'>\n".h($Y).'</textarea>';elseif(($Rm=preg_match('~text|lob|memo~i',$n["type"]))||preg_match("~\n~",$Y)){if($Rm&&JUSH!="sqlite")$c
.=" cols='50' rows='12'";else{$K=min(12,substr_count($Y,"\n")+1);$c
.=" cols='30' rows='$K'";}echo"<textarea$c>\n".h($Y).'</textarea>';}else{$_n=driver()->types();$yn=$_n[$n["type"]];$Ga=preg_match('~\[]~',$n["full_type"]);if($Ga)$kh=0;elseif(preg_match('~date|time|year~',$n["type"])){$Rj=($n["length"]==""&&JUSH=="pgsql"?6:$n["length"]);$Ae=(preg_match('~time~',$n["type"])&&preg_match('~^[1-9]\d*$~',$Rj)?$Rj+1:0);$kh=($yn?$yn+$Ae:0);}elseif(!preg_match('~int|vector~',$n["type"])&&preg_match('~^(\d+)(,(\d+))?$~',$n["length"],$A))$kh=(preg_match("~binary~",$n["type"])?2:1)*$A[1]+($A[3]?1:0)+($A[2]&&!$n["unsigned"]?1:0);else$kh=($yn?$yn+($n["unsigned"]?0:1):0);echo"<input".((!$Ze||$r==="")&&preg_match('~^'.int_type().'$~',$n["type"])&&!$Ga?" type='number'":"")." value='".h($Y)."'".($kh?" data-maxlength='$kh'":"").(preg_match('~char|binary~',$n["type"])&&$kh>20?" size='".($kh>99?60:40)."'":"")."$c>";}echo
adminer()->editHint($R,$n,$Y),(count($Ie)>1?script("fire(qs('select', qsl('td').previousSibling), 'change');",""):"");}}function
process_input(array$n){$u=bracket_escape($n["field"]);$r=idx($_POST["function"],$u);if($r=="orig")return(preg_match('~^CURRENT_TIMESTAMP~i',$n["on_update"])?idf_escape($n["field"]):false);if($r=="NULL")return"NULL";if(is_blob($n)&&ini_bool("file_uploads")){$ge=get_file("fields-$u");if(!is_string($ge))return
false;return(JUSH=="sql"?"_binary".q($ge):driver()->quoteBinary($ge));}$Y=idx($_POST["fields"],$u);if($Y===null)return
false;if($n["type"]=="enum"||driver()->enumLength($n)){$Y=idx($Y,0);if($Y=="orig"||!$Y)return
false;if($Y=="null")return"NULL";$Y=substr($Y,4);}if($n["auto_increment"]&&$Y=="")return
null;if($n["type"]=="set")$Y=implode(",",(array)$Y);if($r=="json"){$Y=json_decode($Y,true);if(!is_array($Y))return
false;return$Y;}return
adminer()->processInput($n,$Y,$r);}function
jump_input(){$xg=['select'=>lang(13),'insert'=>lang(14),'search'=>(support("table")?lang(15):''),'schema'=>lang(16),'database'=>lang(17),];$I="<span id='jump'><input type='search' form='jump-form' name='filter' value='".h($_GET["filter"])."'"." placeholder='".lang(18)."' aria-label='".lang(18)."' autocomplete='off' autocapitalize='off'"." role='combobox' aria-expanded='false' aria-autocomplete='list' aria-controls='jump-list'".on('input','jumpInput').on('keydown','jumpKeydown');foreach($xg
as$x=>$X)$I
.=($X!=""?" data-$x='".h($X)."'":"");return"$I><input type='submit' form='jump-form' value='".lang(18)."' class='hidden'></span> ";}function
search_tables($G){$_GET["where"][0]["val"]=$G;$ql="<ul>\n";foreach(table_status('',true)as$R=>$S){$B=adminer()->tableName($S);if(isset($S["Engine"])&&$B!=""&&(!$_POST["tables"]||in_array($R,$_POST["tables"]))){$H=connection()->query("SELECT".limit("1 FROM ".table($R)," WHERE ".implode(" AND ",adminer()->selectSearchProcess(fields($R),[],$S)),1));if(!$H||$H->fetch_row()){$Zj="<a href='".h(ME."select=".url_escape($R)."&where[0][op]=".url_escape($_GET["where"][0]["op"])."&where[0][val]=".url_escape($_GET["where"][0]["val"]))."'>$B</a>";echo"$ql<li>".($H?$Zj:"<p class='error'>$Zj: ".adminer()->error())."\n";$ql="";}}}echo($ql?"<p class='message'>".lang(19):"</ul>")."\n";}function
on_help($Rm,$Jl=0){return
on('mouseover','helpMouseover',$Rm,$Jl).on('mouseout','helpMouseout');}function
on_help_value($_k="",$Ek=""){return
on('mouseover','helpValueMouseover',$_k,$Ek).on('mouseout','helpMouseout');}function
edit_form($R,array$o,$J,$Ln,$m='',$G='',$Um=''){$_m=adminer()->tableName(table_status1($R,true));page_header(($Ln?lang(20):lang(21)),$m,["select"=>[$R,$_m]],$_m);adminer()->editRowPrint($R,$o,$J,$Ln,$G,$Um);if($J===false){echo"<p class='error'>".lang(22)."\n";return;}echo"<form method='post' enctype='multipart/form-data' id='form'>\n";$pd=false;$so=($Ln&&!isset($_GET["select"])?where_columns($o):[]);$ec=(count($so)!=count($o));if(!$ec)$so=[];if(!$o)echo"<p class='error'>".lang(23)."\n";else{echo"<table class='layout nowrap'".on('keydown','editingKeydown').">\n";$Qa=!$_POST;foreach($o
as$B=>$n){echo"<tr".($so[$B]?on('change','whereChange'):"")."><th>".adminer()->fieldName($n);$l=idx($_GET["set"],bracket_escape($B));if($l===null){$l=$n["default"];if($n["type"]=="bit"&&preg_match("~^b'([01]*)'\$~",$l,$Bk))$l=$Bk[1];if(JUSH=="sql"&&preg_match('~binary~',$n["type"]))$l=bin2hex($l);}$Y=($J!==null?($n["type"]=="set"&&is_array($J[$B])?implode(",",$J[$B]):(is_bool($J[$B])?+$J[$B]:$J[$B])):(!$Ln&&$n["auto_increment"]?"":(isset($_GET["select"])?false:$l)));if(!$_POST["save"]&&is_string($Y))$Y=adminer()->editVal($Y,$n);if(($Ln&&!isset($n["privileges"]["update"]))||$n["generated"])echo"<td class='function'><td>".select_value($Y,'',$n,null);else{$pd=true;$r=($_POST["save"]?idx($_POST["function"],bracket_escape($B),""):($Ln&&preg_match('~^CURRENT_TIMESTAMP~i',$n["on_update"])?"now":($Y===false?null:($Y!==null?'':'NULL'))));if(!$_POST&&!$Ln&&$Y==$n["default"]&&($n["default_sql"]||preg_match('~^[\w.]+\(~',$Y)))$r="SQL";if(preg_match("~time~",$n["type"])&&preg_match('~^CURRENT_TIMESTAMP~i',$Y)){$Y="";$r=(in_array("now",adminer()->editFunctions($n))?"now":"current_timestamp");}if(preg_match("~date~",$n["type"])&&preg_match('~^CURRENT_DATE~i',$Y)){$Y="";$r=(in_array("now",adminer()->editFunctions($n))?"now":"current_date");}if($n["type"]=="uuid"&&$Y=="uuid()"){$Y="";$r="uuid";}if($Qa!==false)$Qa=($n["auto_increment"]||$r=="now"||$r=="uuid"?null:true);input($n,$Y,$r,$Qa,$Ln);if($Qa)$Qa=false;}}if(driver()->primary!=""&&!fields($R))echo"<tr>"."<th><input name='field_keys[]'".on('input','fieldChange').">"."<td class='function'>".html_select("field_funs[]",adminer()->editFunctions(["null"=>isset($_GET["select"])]))."<td><input name='field_vals[]'>";echo"</table>\n";}echo"<p>\n";if($pd){echo"<input type='submit' value='".lang(24)."'>\n";if(!isset($_GET["select"])&&$ec){$Qc=($so&&($m!=""||adminer()->error!="")?" disabled":"");echo"<input type='submit' name='insert' value='".($Ln?lang(25):lang(26))."' title='Ctrl+Shift+Enter'$Qc".($Ln?on('click','ajaxForm',lang(27)):"").">\n";}}echo($Ln?"<input type='submit' name='delete' value='".lang(28)."'".confirm().">\n":"");if(isset($_GET["select"]))hidden_fields(["check"=>(array)$_POST["check"],"clone"=>$_POST["clone"],"all"=>$_POST["all"]]);echo
input_hidden("referer",(isset($_POST["referer"])?$_POST["referer"]:$_SERVER["HTTP_REFERER"])),input_hidden("save",1),input_token(),"</form>\n";}function
repeat_pattern($Bj,$y){return
str_repeat("$Bj{0,65535}",$y/65535)."$Bj{0,".($y%65535)."}";}function
shorten_utf8($Q,$y=80,$mm="",array$Cj=[]){if(!preg_match("(^(".repeat_pattern("[\t\r\n -\x{10FFFF}]",$y).")($)?)u",$Q,$A))preg_match("(^(".repeat_pattern("[\t\r\n -~]",$y).")($)?)",$Q,$A);$y=strlen(isset($A[2])?$A[1]:preg_replace('~\n[^\n]*\z~',"\n",$A[1]));return
highlight_matches($Q,$Cj,$y).$mm.(isset($A[2])?"":"<i>…</i>");}function
highlight_matches($Q,array$Cj,$y=null){if($y===null)$y=strlen($Q);$I="";$F=0;if($Cj&&@preg_match_all("((?|".implode("|",$Cj)."))su",$Q,$Xg,PREG_OFFSET_CAPTURE)){foreach($Xg[0]as$A){list($Rm,$cm)=$A;if($Rm!=""&&$cm<$y){$wd=min($cm+strlen($Rm),$y);$I
.=h(substr($Q,$F,$cm-$F))."<mark>".h(substr($Q,$cm,$wd-$cm))."</mark>";$F=$wd;}}}return$I.h(substr($Q,$F,$y-$F));}function
icon($tf,$B,$sf,$Xm,$c=""){return"<button ".($B?"type='submit' name='$B'":"draggable='true' tabindex='-1'")." title='".h($Xm)."' class='icon icon-$tf".($B?"":" jsonly")."'$c><span>$sf</span></button>";}function
copy_icon(){$ic=lang(29);return"<a href='' class='jsonly icon-copy' title='$ic'><span>$ic</span></a>";}if(isset($_GET["file"])){if($_SERVER["HTTP_IF_MODIFIED_SINCE"]){header("HTTP/1.1 304 Not Modified");exit;}header("Expires: ".gmdate("D, d M Y H:i:s",time()+365*24*60*60)." GMT");header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");header("Cache-Control: immutable");ini_set("zlib.output_compression",'1');if($_GET["file"]=="default.css"){header("Content-Type: text/css; charset=utf-8");echo
decompress_string(')c(GViDWPFloQuDeeQUO^DgIF+y?4O<cX<jDcJA*[iuQ/YeF96jOuG0x[iu&n>{/Lt(peel""twnUSV4BjfRQHIaBSj#}4}^YKK<$BQMFP!+g0;Oaei]~TuoHWgJWsnlo=Q<zp
uKaG7hs1<!+Oi==O^}->/9Ea]~3synPV<Bm<c_L,Wm<:hI3>D]aslZ$SmG>H4Jg&]_G3uM_JP:Uxi;FAxIn_^J<D92mCE[jOq`wQHqqX[abvndl6
BuYT?K{gCgwCMO.[Bh>G.8O8j4cIh5,JaCXgWkoIQ!Xe%"4xCJ:#SsY.cQ)wF)[oAV@<X0jd2+]KMoD[>B4-v8{,,5LHUM34%n.ryR|:FR{0:aICtMI@=)$E^(E*:+o^~E%/JJVY<6`t<36)7mKyhI2:4Q%)Fo6^*oXn"r:NoSCF7=8WtpI91EqD&q]%mr`%zJG0Us@Lmi0lzD?Jm_.!%96.
05-6$9gJ5ax@d2@W#%4dOJ)dKrIhH<fII!d{G[[I[ajMju&sY5,h-_prPbj1%#EWXF1Gq.K+lD:sy4U_,<r&5^8e0eNI+ZclG?lfYe9UG!7:$;LSenK}0zA}Hr"r6Hk&7{RDK%2"Lqg8d<iC84Z1HzadoTQIv^Nm637v.T[>ekSylqlA2,Sd0UOW[GOnj]?I(rr3D?<fp#f77<J++3-&%V.~ZnmNJH[y]`-^0uG@G4iId}ogQQE
<?w%giV}f@vlXb
MvCbX8f:=d3Ax]qKEp-m^PAQ60knO9)+,(K`(k_*pRZtYK2oEe>T<T19
8/KE0QRqMQ2Zi{y|s_*Z*3nf*SKkX~pV^ZNE&eF+E=*;AA"3>99[b}$CN/W3C*?dXP.!6E:7&
8DN`6p1]rtfE4<_
f(P[Ux2ry0nf<PY]XTU^Ll=8vSj8kmWn;8oo8dfJYdbKvrr6d%L0F6vmh%?=RDtJ>od.Kf_;-K&lem4.[s6-U8?_qZ!30>e
#,<5F60=.DmmVj*!6Y,vLqPc2443S*-I32v==ZWdo4dR<xcq=.Tq,7iqik(&DOhm=Qoqt*5cDK;$y{"Bm
kF?)=ch!$5TIt8QN<9$a#R+(O1$s#aZNShtE-(.7_F``i:"}kxp]Yx6^OM^;e0M+??x79M7Y<Lg41Q@!!`Lo[[?s0%`hIXW7A?*rG&c)dt_!.k<P
!LH.#D0r[5j!nBvJFZ/
zca<%!yWK?IJCS-HJ[HNqf_!.pUz%_:@h&2bU2SnD&W"o>tH
SFubJ3(pod1+"-L.aeP6<C%N^VY:W1T:9YL&[C!X#~VLBaTF!aM@x"Jn8001@ofj_y.fRnEo1ooO_No)K-@D.)JN_QZ_@-sT5;N|.j%#r(hxf|D;JD6gJr<CqQY;E{2U=~eId)KBcs(+yT0AG0eX3;AyoGZwhlhi#X4G&W=<dBZR&)ef4xhwP:[9Fil*UQth<COFaql94jP34`J&C8]sY{C"^rVW_HOH.);{<5fuu&Y}TX?f(q#UcrQ!)3<7mnY=?5U@i!/D$K`n_rXT%vKD<dPBL6rr(n;?JKZH`1wlat.z
Y03*&^jP<h[>f)AYOQRdzD,NHD"(deXs0vAr*f>SWjb?2>=-2oqYE4&Zhc3i&9YW7i2xqjc%VA1,";Capf7<gW?OGIeLR`3<nPzQ(8=JL5w!xe.V/cY`mrrc}A6Fgcr&xo?&xe`6ljlw@SE_5wYVUy.&NfuXTfGCwJTe@Y/WAb8Z8/3Lx-=NNR)Y=o25@MDn8aS&"jKgv#2p_hBN3hAr%<Q`JWRR[$_C5-!!^4U@%kl=[#P9K,FIEY%EOt<5uRdQAZ]t%R<si6<O?wWEAEBIp($eb.HAC_>3a@yshOWmJQqipBLsG8~>`UTgfGN:mhdI<PLDzGUDLGlNV0hA`jKjhEpV1&bq7.!K)Nu?(9y%@Pe[
<(Gh8*RG8+k&wG+veu
^WerIBNNs76r#jb)_E+X*32W:U:C;LH1zcPJ?1YOKvWWz`bQH,/[+eF@mS/dvD}s5b~g<z$9=-2OaA%7
y[sakM[_h"r[F4`P(1cLxT*bQR6=%kJ`*6oM!OKX#!EX]QZI#Kt
s!o}vHn(C(^&ORC_h<IV`obwa|3p=F3(_bm~w4^:5xY!/k1GpX/vn=^W-{%
GaD:^K2-"5_"3b/ySXCP:|s
@FrGD*3U@=$Q=;lsPAH1tBP2oA70;4NeQOC+3@Cv"NM>.[hG"*F]PB8(/J0T81l2>*+XJ#qy,39V:%BQ=
w~7~!`O]bR>:?hLBCh>_5%S!.PS(0Y77oUkv00%mYYWbD-gEukB
RUv&iYZguO9%y@NcD[VnR>bAlY%F(4%K^U)3`-URp3[{no*.3,<S=4#c/`?v5~H,FHL|uAbdq.cwM,_=s[
:ct);`0B"xvqM?V_S+kl!2KQPpL<2Xp`&3pFXaV=RII(Z4GM<mY5-/2:SFUU*oUj%wF4@UK@i3_.OFgmNY0d~0Dv[<]0`q024;@%m=sg!/VP,b<G[o<;+n8@fGew.<gZ<Z#1OPEc{Y5i(I;<BAT9>gT`63MUwH(659gsG.dnuC1TU`/76G0rhbHodRo4,t>kG%,XA*1;neXg1w7))*bsl5*BMEGaaX)`3">,Y"?b><33K7gb-_n`)rn_qITk:GFFhgflkRq9qIr!@,/rOYBQ8i"Pv.b4]ASemkfhi>d:9_ihN:?x})m%9QgIA(BmAu1N8F#3H8R$U!GcERwKc4,v#C#Cg`nY&.ONKBXuolK]c]"l$#m-Et7N}B^@N6JcH1)Eb+3I-A$aP<Mxx1uU`[b#cH[1uxN8H4$mcifjxAXWI)8E[x:,Z+!HJ(K=jIDmZH_k4awgVS;["dZkEvG:G7QwF_egMN@DK1J,@f4dO3`Tpr09uH2M"ve.U8za~1dm]`[>XLzTf)k0KZmx27V
n^;m[an?.biQyvKlUxbAVa0FdXieVZ?9E2H$fuY6ma-s3b[_"326ck{nvuX_hMrf{Lsw
ZI5%v:M^S<rpc0,uI%e]F0clkXr)T>>E2!B$FKc$4`3PuwV5odughhv,7xlv]Z_P2$!6fmyihSd-K4J|u$;8Ejxc%JNF/&q_UUwdv,Y|dI3fbC_8buxQ8s3Vvvxo=AM:cpc<iJxcvdY`cRq$58E)]<jJZBOuO6__dG/&>c)AmSPkJmDf=DwEf_D@hiH~%_l8cPeCK+sRI]i2g*,Yh!B$tX2ia1/(iTek3P3^_8PSoH7q@2*.T0_xu)&}>CD55)EJ=.rXXzx0A}n<i70"Bh$uS(*{$8e5$x/Z1#lDnYR3C%a.lA9>?Y@B?8a,otCSGLjw,SnwbDy+&Auy58#|.fsEMOP-Erox.K_!:8:bU~(MGM;62!]a$j>j1>;^!7*/PY?Pc0<@W5>io>e-^0n{)Uq)h`gL%L=RM)IdGx"l5B*:ZFt]@&3Lo>`:=OiZ<w6}B:R"LFwB0[8jgwK<G}tmu"o&V%0~`[B`08yu8{wz6eR#b>e5f;u.s2KlMX8mgwn@iHocv]l=vygi]>/z8]ATOe>+",*hrjX.u8Q[B(=n]DC|6lEN8c9|n%J,[kJ~b?-sDJ]a#HL[VX%H)?O(FX=h`[Z+@Gqr$NG-*K=iO"p@!:,Ys<fl/&M,7e)9043fy,?*wg-(`<4aok0>nu>wL+=16U-_D?c(y2YX
;AfVIMj18WoK!a0HJR4cvQzYXijD@,oCeg5]9q9$kJi(Hhe-qO|-bvAu=uECp"M^g/jN1E7DvtyvYf@emMaUd$b/DhVnuhxi$xS?LL3-Z=}_|B``Yq+5qcGW;B%!7$S^=T8H3Y>OHC~qi1O+^ou6:EZWR<*HdExTUXCER(OG(l.AuO0p$3;Mv,fKzSL.)aL!d_RFLd+.$qprx#hZKS!^Eo%k_o
M;Cg/~F:
$.hx,o_DVla!~
~L!d|;6QUrukX!3_&*DOX7sc*ImaFKk,_+J$U_
@4vupHxO][ERVMahT6sm,,WN*$3ymI^f.**1Ne:Y5V;2m5ki&Lc1l[K&v_f1HA#:F.H3DJSqeh^=D0G@Ew:m9pW&>XdMwfp.yAm_l,+JT[;FJ[WyVi3u^S_v>s3!qg&eXk?Ka`c%^"*xC(6a*T^P7WTrc=e`uFkiw7A}fZ%=kqHz<+Lgi5stD%N8bphObsozc>y%/vAD1Hh=TlG%:E?rFoLw.[+DX2Jei"NvDAVH/QuzSXmCO)M&>"jztw8.y5hK:-_5]xq{rwnU[<&#aK8-j^P<0*]*7Fl>2!rH_WVG,c0KBxFiY[ha0b/y!b#d9;W3:,mL+d"j8_gdV>0GLHtDZy[-,M^3+Tm(+}g2R_Z(3xlhxA!ir|:U^O`UP?+wkn#z"U=M1"=3-3aQPDC>6k;mN+XC4`h6tK8hCJ+}0-u%`!*}V1RR#076"hR[(0x&;NtCJ^J<vQ+dV[J
W2`YynG1#O==l~)t&w@.*X28+-TV9C(8gh4Ie}kF"Ghs[5V8xg0#.!^Y3zE27qK5H"nO`_k8Y[<krL#]vvkC!$BFXZW0[e"Ha)+5dA:{Xw=J"!bJKWtx;D9|>,eo*u$UDkDEwd--DmJ(`5W<Qo-Q*0C]"P$H9JrsJIWKCO3s1;<aV_x;m6RwsZ=%$Cqub|p609udYkZ5!]DAGFK"_p*&1>X?G%jujw?_w/
g4vbfR*hZL-p1mZS=X~vKJ$=ho/_J
j*"V.eX*/B3Q)s,JU?Snr$r0y.}/=`a).n(;}Q~WY?/v`2AX^mm<QZt*g=.3N1m,.7=GB%DgeSx-QL)fpBX5cH9E;?9Vil^UrPT;qMkMxk:PtGe"<`1g{:LV9cKXLcmxE&=C)QnqgxX^i3Y-42-_/emXUHhkzdOr?!A@]Axrzvy23in;k>b1]3s5NxF=,7}U3xml=)yUu&OM(Bfxd');}elseif($_GET["file"]=="dark.css"){header("Content-Type: text/css; charset=utf-8");echo
decompress_string('#OsbOb3V?!K0U*,j#-4P2Bz+35Y(O]|GIGFDsyf"RV_CWG&(<hG7poe1|](vZ=6gKb*-e*bfu`XNvNZUFTxP<kqOjr%%43Ge]*BE&fsS#$XGQs=jy?$Jqw)[=$pZs!#`BwhTS`Hx]c/
gWB0SBBXi)^FyF(MQLF,

CntAU.ADCkT^/<4hx@Sg^
Q:{DP1JO0$3OTgwkiNq>)k;xr&Qh*bS(@Bze[?PjZu!*h&UmI7<lxWc9)SaQvxR#;%$<pQAd+6,HWq@]vu3^lY1Y-Ns9y3L5H!=QP`I(rW5Hmx2spr4^XPbOa]@XKH,y^@Lq?=.-z7}qzeIF=B7yM-[7f*y?wDLK<WkFEA"t,#vW6>r<#Kf"]LY,kcXWxcX43
Ass`W9ARbvKD)]-@s[:$!rwYav_$?oR*(xrD_R;EA_gOpnVg]m(S7@|g#&qK8`wv8>Ve+uJAe<BCTZ=x251_N$KrVP!Q@9e&?&z={f4CNVgErN_i?URd=Xht|Qr-%P%uCSWYu<D86`]A3;1;d$x"Gm))J17Q
ZpWmu{G[:S:^$855HJcKZKE;S1-dlyr~:<jt
vN|yIqN`"?)$N>~l|SM3|;g7*iHMqkDXK,u=-Hto:2;IM[pN!44bS5#B},0<$3}je*,c@B7eav8x71@N{#iT(Z;#k)kUdlPj<1JMoyDm5@QZU5N:f5nPG96r~P]]M=:)mv=gMBhrpK&S2geLF@mf&uke2lkXlf8RiG~[JJkKov&yU$W
^-NPh_0Dd/,;3Lh5Xqqi+oH&|*hdQ%wk_U3(QJAG!:|Lxc<M4!tXBPGbzHyeN$$4U![h!BOH|/uZL0S@0?`M=qbV;Bk,}@3+n@Eq"FY;GKX`tx."s9E7uY,[6Rh;.v
(<8kBLH"Ke-/4^wv
dI2k`b#;@i+L}<oMML3kc
T*|N]?c(A]J!zj7Z6v?+[v"xR^qVr&HXOk_>=2=n10?w,o5J{Y;tk.Fnt^[!s$xmrAHN.V+S6?wVou8,Nt:OJ3#T6');}elseif($_GET["file"]=="functions.js"){header("Content-Type: text/javascript; charset=utf-8");echo
decompress_string(')`KbOh%Z51pK,ikTs?)"H;V,IIr:rC[t5,_F@ncm`@yoj3"_:"vA_VBRR$RI?[f_[w-[fNe2X4^yyl=Tq
x8x"wGoLxJJW6("@rvP__?ee$FXD
,"hNFt[01(c0@O1M
7cgR,9];2Vqh$;omxg<Z1+I`g40]
N"NMpph0J>.4woWEa9x,H~KyMz)^$~&=7y&,)3Zi/:#LuN9`cwOcNF<?p+mWOvqk^)lzHt%*ysfUs!_&74]IKI[=7l6yD%Ojl.PWvS,{1$@RW%HFpi8pXIotu
9vqP3l
9@k@b"CyS)^]UvEFq)f"lY^5p7"nPgWQ|pw)Me6V7oIeN2NFbY<NqbStJb7Y/
.1tWaHzr=-GDr&>Go;tQ.BK^T*:x^0PT1y1DsH*Ok=QJ*/~dv*qfQbO(X(XUDZ&"5,P2;yGI)3n2<9R_CsTh0T!%bPMYYTuaTDM0`+(IC/M>Ypv60-@gK7Nie
gFAOe]e__m8_~"l^9hki:Kc(#BF92R-4~W#A5_>QMyFx"hZln3g`T;WJ(ArvB=^V5?XpDYVi6mv_S=LnA8NT2=F=2o&v{X+mYJXnTc`STxNxcIF`+p1uuxaXiV;pE7Z7}5y.LFYknDJs1Vv7h_RQvkg#>M+odggKSiS2@[g/)6IBFW^%bi^PMv/l<MSO+1#T@&^*61^B"
7K2vVu=I.w#DxX|7Q%TUVc,CdL7;*;$(Xi?QbS2vl%+,e+Y,<goAui+:-iO-h/H;?1O/W%!AwZ7*&_y@U9:1otdYJ)5B};Kg&kZpbtxOm*ubdOP/awC!L)Kd<NJ[@d&rgb+(ojc^?S};RQvMZQ8qPeTUnE}3hA4WaX$Fb6[w7#j.|M^<&a8`(p2mPhBA?brn6`gKMc[:v=CJ]3aP`;")-8Xo^yiGyc]PGjFD`N~69p$di
LlR!SStMMcS>5&VM#cXL]FCfd
bNz/a,:Zr6([8BbJu1fjKHrj9Ea(;?qaiOr1^>nWp5)8e,{xGYpdr^%qs)
cLthctKR;6(sk5h$xbP)C]
@.:.J&$k;#GpyjqU?N1s>)=]GMKF-&+TQOhc,NAfX9N:&&2d)+CccK,KG`ptmluSbEVs+AQB#W1-c&*Ut/2m8#YSB]!#39x9TEM6RY
vqDY7Xw0wvfo9Y.nT9GwVR
%+UVSjIw$&PoJ7+5IYF:Qr+m2+:M`c<a6`AB!wpXBU8_5!^$-*6%i8:6$>)>mu4N9!Px
`Ga|m:>qTYNg9(WiXNAFl7lzYn97$]p{K,@*$WyQ,"#G=biZ[_g`wxNI`ACZm#
0!/$.$kG0pg`vU"y>KXxvwLDXT+&bTDeBWg5>j~]G,z@_AcBsWM"HW,=7rnR0TrYs4_HJCaye0FVbbAZS$
h6U"E-MAI
_GR1Olah,&WSed$fUtSWa%Osh@0)C8
iScU(]c?C<mdfbb9>+{8m:|Tz7ydRYzhX%5Q]XaMm(ypvytUeJ-n1K}yd#n(qJ]y^K{sd6km2=7eBLmPGeiK<al;LuBn}^X#Ip+IIlrV-Fn=jYG*sd$RvO]:[qQLeX~"i2E,,_mlwgPXkK*0EKkccF,P!bsOE"`XDEKGgATQ?9B1g9bJc
KyME)+LwPWk]SED>4oTJ6<%WlaYdOu2;I#aX#XStlD)=@>>USXpe7oCF/Tk@?6Oel0guR/k>]Q70<Y#45&5LE<>3f]#&U0?f!`/Xw%4
jDUJpWS>28V5T=M*~C9B?,Z(_&98ini6R$;@cSF"a)}sN2&c/Dfjb9GwNih<6!lhl-:[rF.cPo4&nP29{AI$m+r4J_B3HS#cQS{J=uehbK=b|.e;f3@6_IAGrGy+G:_pXFo`^_=WoPY>AOEW~/h8F/y]bQ&dc0HND-|UVbKjWeKq?dh+Z+(hh6Liiivq4H*i~Oob;sp"&`Reqy6%(eZIDv1ymiLP
vTh@ortB=K&}0ithe#BmfgXq(WjkX
G)*fd2f_fh@Pr=3PDhsda1iLIXM&ww16Q"G6gZ;YLjs>j?,.c~J8vma;6P)koE-)y]5rmv_+qsYk[Inzada/2JtMyc>fKIc2Kqy<Jl2VS?JoG&
!cpVIMP[~c^Gm1Fe>j6DN_Wdg$KGR*>5Z+_O?UXc_e)lmnL0t6}S~(A]qDR"t%eAXlM3>,zfJ0vZpp$_dZ,,zt..wTFU~!r"s8522AgB+>YDDI4T]lnolcFU;Lo]^;Sgp4Z(c$0oW=ZOP1PNvY1^?4q;l-ovUs<,1Y^8D%Rc)5dB!&9[.EeM^1mT1)Bg1/_jv<RllYu^]hurh)VlF>>ttiu!_&B2R-#(h%D#]Sw@g28+^i|"YB!_cBEa,fz)Ckf@&`ukf+#^x1MBv31,4d7D#yCOdVLZeq{<]2w*.+$m0r#3)wd)#<KJqOvs_73
<,?Pf]r2S]OK5;/-DX<u#
Uvc,ZX&DnNq(t2,F>#0H2ak.^"Rr=:&r~(4yuU_OIHq#YaS*=?c[dBn+
vpX3=v1I!Lg%k)tyCMRvJI;dxvmcC-M0/tJQkmlu!S>joCKgbh9v/iY@S
rB
R9p".6dPEe/KZsh8dC/C?S]5d2aR#d+`mrV1JYyx]6<vtS>lkU]j#t
j(vHx:=@HVX
/Fo@E#qrOe.ZD^"+$]DC#_&FHjMqN]yr$Tl"49j5e;A5A.V0P|ZiNbS>gW-`&PPr4H=Q8I&//7z"6sxM%_NYct,ofp*P#Lmi#j;__-wIqO=na1rrw~8q!5=m,0q$#M0,,X[W%/_HR#k6JTao6`wf@(
)z(UsmzkY_@LwUW$c:OQm9BorP1]/_y1,%yj>@RU*F}bN>e==
T)}S%^43Ilb73?E`1hGL`cWhPqH6I.GZ*CvI`/N0/i`)H9A#]05.F]A9Zxy[W"zTj#U8fI5P5UGUzSGNIN?.V
Rt537;Q-b=Lv>/f*$HQw;"DtT"pl7>.FpT}j%JVv1Cz-4<<hL#1o/)Mj3i;,qogAC=qUD
qn;Hnx(Pn^.nDht&*M&k^QM.rT
]aGhkf"`3)A8Yu2L*VF[R(nhV>=A.&giZ1rC<;l8v*Q=fn,IRr83MGO,-Rl<ThcvR;QSk(J--DWLFoyg-M#n?Z+kpoG<MsC^.s,(,Y=Zhew%(Aep^L4*39Cg3q<:2(>(k7O#
l%5*swkC<83^I6JVG
~wWJK5&x|d>3HnC"A^:wKRXbH;kWg/O6t6G4>sW:
vLL!W#D6_#CyQe+;$$JaCk<"0#RWu?mJ2elz9{U%lCxXpoM[q_lSsOwr<(yJsXlDN`nZ1%lIFW,9g%<"lv_if&xG`qdAcd.df<iI)H#~04XHr>NZ/Hdx!U1c"%y!RPXl1[3,btUAxpn3jyxiM3W,wJW7lE72G}8ZuZs7K)w)4k(%hXvN"RN9/Ts%hQ<~1t#O<>SpcG%Zd-ZRj"gFN:T:U=)W8i8omD0riCX7)>BJSsRV13%NT~,YYI"p$Vm@:3.>HiPv?C(]6#HCAky71lRN34kcb1ts3eCzEDY1=Ij*kDEU()rU/:WVj1WW821:g+h<G5cui=tO_~[XQ#nzRttfvJ55=wIflTW"6#&z)zCj(VCqR"VpNnR!b@Y_-<.Rr<M#NzSCx)"K8IA#+*Y6l},YeY_WB[[5Q5riJ4Ib*+Vhj(TI0,G$.xx*:t)*fbqsL0xyG`,]UlBPhm8;nZ;qpFz)t_tWw$M~H0feaG%961uRdKm.gPrPLT-
BIqbnzD<SPvMy{M,UJy~+|d!m"y<Yb
H.p%T^I==y
=@cSDmTo`NYgVi4FC[_j]v[C9kmgi`46.IiP8hQ^DH-mh?!]h=X"yUW=Mm`@5v*;IR(Lw3cse&l%Go=$tn0
n5Rxr>Sm#:[|;Sx>F$W
P4r9&RjKP2>6P~
7&Ni7^%aWmuamqU>]y7t[NBI*xZg,i#_v.J<$#gj3;"4/A0RAq5[#7+f%&H:(ww0;!
u(BS`7jmqF0ft99g=`&*?t)BZR`e+ru-f@"<c.o,QH<UDe0xbptroB92=sl";.yMrNJ(Y<WYf?Ns*FL|x6%?j`p~x~q*%"K{;"uTA",b,8>ah-`UiT1,E$iRiuDGQeqL)#;WVe%wlh!8fIH&Dz&Yh/$zG}_($Dv)nAJ@v/cu$"_;u/i~gKNhEf.x?:x3)q7)tgnP=2)xcBs"Dd)W2ycImsMuJ->$AUd6Sr)Z]MkL>>F*G[HK*=]"i{$tV0N->a`N9`jBMbVmhDhYNZ(?b^G9#7:~1
n#T~BMH^lJN^9rmJbMJaa!G}W~K$1zP<]cyHiUC22K[_I.G&X
K"v5Ha``!gqR_d=^s6Cup6rE?Fc~#">Boj^zcoh7,Tx%wq-B/m@(I=bu&Ia=O>e.Kye:CyoTwuv25$CE(wB;]]pemQnJ:rv{u9XVlsDmqxG_Uz]912RZn060Z3gEDyo
/oFfHjt-!vr8KwQ.2H
EIcG~i[y,)d:2R
XyWo<]2
W8tShKa"Y&<)c>yx1hu7C~@UQ#WC)u;dDl:/o*kGv|^A+_ecc|iK*<KTF,2$1bQ.D~b63bdRkE2Vh?I)>_-[Qo:fEcu$W=ugI9ZL"`C7H[uK!"pQ>3D5A/T}]te0F#f:_ry7M{.$`2:2b,lYEV+Tjk[[UXlY/8<rlPC{3+9&:t[mDiY=@H#A(AR>`_,([{xF8{=]R"IaP]!$AaX_*=I;3fL4Gp!=>.h_^y#*f!/"h9BlmE_)rXPf9NN3PL:L+mc/YH
M.8Z[d3;I@daqQRjzlQC3%TFVDe_IS|E"91nmaM"p8vsovQfyiQyB:G]_E:t[=p.>LR6T,
$6TPZS_h&>%Ar-J;C}[1&R)VsK-NbSHbcIA`QKBV1V<xR,c$bM-))wY[mEUc1:]tYW_~Lc.{9_f-;5A_l]fI-/WBy}4jnRa&Q-O_WnSQFD7,4!C*VNIu/d+W7%d/?l.]$3Xe!;W_7[E:-P";TG^%>ynRbUT5MIjsij@N$hv:
mGLo_.z
d$Wu;lZ2`P<7VgZ.HO-jF]()YTW9_N<*sPStyD{j#ffW-leZvZixA0a#4`If878O6]@&YSDK}x*pX1iO>g4ITAJ6$Oh<%XvmuSUdR=705F@T++eDa@ADKe$*93g.zkH`!a(GTQ`qD*6q~TO#8N!F|b=hhj/56CCObQG1H%Ew^v]>1D~#OE/7:UWX0Me2
_(jpZFk)V)Ge%ic7%
!)U0O/1"HS(_WWwzyCyE@shKq?!#M6`;
V2UajI-"rthK%/C-)OPo59BL3N#a}8ACJQT`V%f%C7@7|-%O[n+"ol7FZQKX3UE$)(O5B4eLy`{[+M+*82Q6?WBQxQ&epu(a{L7QYijjppkv1!FyUZ1.,A
/P+t7..)glu>R>[QQF3*`k
G>7Y5c%$?gvFedsFKrvE(O)OIXz_cdMfO(W=wjyCi
y[J^@&Pk.89C.^K[XORHia!0m.+o]XyRvfH%|$M/*srk/1lwyi|AaM$oe3#efkb2fcx9;q#n;])1nrm4?Kw@qY
n5(tnE=MUK%,B6)Wd}<XnDmT,6-GEX,TdqR%ON%4bov2jOz)mh81Vz4,s/)jG4^/Up
kc$UNEf]gqH6Qu~`s6!%VLsotnndz`-sbR?X^$,9S6[FDWIvelBQOVPsVaDEC&Phmoec4cJ5hkjqV.wI3-9/=sdE,;<YmW|#/<n*]g8PT+l$Wys[ryp97<{9kPG=m8V#&.NSOy4Aia17pXwa88bNcZ.G3[Np$,N;0w:/:+]*vUxW8al^jOr9+"
C}kZ6[kTa5s,I.2*&;hZG?p)p@*3z#dNDKUdSL%sb#:vS+aK/0mbv"]7.cn~`>lU+P/J93kn^&+@*5U(uJ@E#/<B/:g]G[YG*r_a7vwXB_gC5JrBasu44c/fJ+k1jC1BMH6K@=J2>)ERXd1Cf2nb;Y&-N;_KuQ?kRlmfFgfp`0RI<u(pW3eC-Qo>p5:fCo?Za~.U7[!4jg:f#XO`cY/:PGr/&_2eQ@xl)Cy5t*J&OP<2Vz
LIPD4$u@umM#X%msUlze32_+4mJ<{JN2,%-F)s%/
.{M]H-+~IZ2Yx;1"OJvOG:rVVMc
lz2+]vVAR*M}7eKJCi.lDn-#taG:oA[n;|fR101Yt-OYn8v=KuU5Uc]tj(J~9]l-V(&S1;D~=)XEcZQdkN>Q.gTe2GQ.U%)G=qW<G6!E`KIiU{;b["FF
b83)(qqju<`+vvaW/n;fb7p$w/MedWb?:6MY?20QefZXW*U?t5*(lr+wo45DPaayGX/[RiN6@w$=WoV7$HU)L-)g(n8I5*<N@o`eN*FjPGco*o<dvMfEKiJkrxU!J5VBR1hni,fjikn9}1^uhN^-N$mrwLO_-)LDJ-^Y._Y2BmurMGhPVh3]^ALQr(I6ym(`^Rjn$AOq4U|3`
X5G[1=~!cy"bMM$K@gD69jYZ89X3XbRRn4eSh,vxtw6*jR.o7<`@K7P=<iOWdK*In=9ZT.uI(5}M-$;km.*A(RbjRCc
7TM
N!b(A.uY2Bqip?;w8]ue/8
J!`kF
SYsUbIG^cu35F]%J!0S]!gtXv@vMGj
_=e"hQj:59QZ{xVWQ!~8Mn0/)N5/z8JqqnYqKUfA-p9
xUdX~W80=kE:Gh{=SQX_L(UOU/*Ko@*TOo0_!/!4pHzFqKuM*729fIk/B:7*TVsDTFOoBt7Fm^&Ox9ib4K.[U)0$|+gb-[M9Phgk,;eHZ4(^Htr@%m4r!nlWGw%A/5,6y29W*Qm)1dU)iF<_xTOZhI~3Y`2W{,^Y1$JZ"`nU0Q!!9?Nf*
f(jte7z`B%v71&Cx$0au@jPk5`%Lh*HFG,5]H(4.#@DVmmYO8RPd8-GS7fV)g39y3<(bc
`9))[DMEs_j&;=x@pyy7qnBDwSWBtJh<yoFVP,^m>ooq4,2JG3un9/SUS%5&12,pughmTDL.9YS(G`?nW&gO?t!lG(G<Z"pC;,:XW)biV"9!T7fFzU+OFYPVJ&/r5+*1D&2"U6d<d:s]aAv9oDCMJ2n>1TPWkw,^:^ldq!6UcOc?M!oR`.u%c]F$"u^s5NqEsKHV{p+9iX7_bR`*0"slbB.i>P
]INGG#qr6BK_FvCR+,w7;Q3~qQHP@3&wu!mmk2KE-q<##
xfJN^N_[`5m.RX&&,aQS)"D)L

mE9(T>.!~N:8>jo;r!N$h@%JveqX<r;;Hs<.m:)Ei<B<0aUfa
H-tmKRB)00VYp$y]zTHLW3(72O3;htH^4L^s/0z?fo@cwfd0;%_NyfbGCaPqbiVt269fPTWJbb)>HfxmWQ5F%KOYUY~JgEmMClo%U45kDpT#$BjFMT1ya1?H,;6NVTo)e0l4-8UJ+M5MQ(]dnU5cqKzu`OAU=nr2DK]0Wb7wkJN$:Y+vJ8,h&y:hyuQ8!T6M&9}1P5F+4_$LabuFd`+3@Gd$f]O#]O>_QDl[LVq4V-B^sEOs6;3n/8_R-;c#:waY;fw_NIx3{gy8wC!%Hm61Lj01k"RdK%w`?Jgm[RqYQm%49N%%
CA5#E<C]&`WbsNdH(dc]FmL[pK=^tq0AgvR?g*wSmD)$A3`P64JQ`kWrlr]N(cX~V>g#e#8g_DyZ4-ZV>f({3N)OjX!9jb!1whhj]F)7TT:4PVE^=BO&,<k^tIY2DFWjZjW;aP7k`n8W[9OTELeFI-@Sk$u:csqW?(vAQdvlLex=)`o;YssPN~@id/m|S?F0WvaZ/6@.0T&J?>X[^6Z9&!f?)LGb">.!=dqX5-)$T2Gm%~mffvdAyw/)NgQ]f/[w(L8V3-oF$J>;[YZn2(FD9oM$i[gQ-HS|p0ldH0`]vRVgUGt,)[Q0YpF<Y-c>(Q]]?gKgy01qxyj-aC*V;0fQ5~N))3poqN1
&@Tp?]Q>wBz#9~4_f%=>Lb.n8j:WO.j4:j2tJpN5mTUWS;:yk`/M(&.B:!U.nHA?-?Px8T2VVSGOTU4WPPi{p)AB/irBXn,Pu*"=U:t]T2M4(S+pobo%+l:]Sm
TZ5fCg-p/`!ftgHcA^MePfw)p_:i>?lU|?z0*>U=A[f9B[Rg`gYpVu{4%M&)Jt-j&Lue|W^%-@btFM6%(^VM)*3ayO[!{y5-#]|PMW-.8,/qDB`K~h*ek&hK;%?&KEn
5cVonyoiZaSMl6.=D0[B4cFXZ)jw0[Py@nOZ%.QYw
c=`K!Y&g}M`^JxP54!oon`>tyHaHGDS3@8Rx0q=%)EVR^w?@dt#2r=NA0r"p9Z<np8K;eZ.A)8X4~(M9.0Q^MH#Fuo->Hck#n;g+Kco:zUy)8Y(R!Al_%-CjK&Sn9VYv+?=!U`iuoH<gX=/C_!r2Vtv:_5^dS@BX"v)`5QJ/Z_39T>_A8+L`6+sDuv$;s$J%goJ>p)$"Ay@XHf0yvYXhlhb::i[-jqv9@M;Me:-2<;r9g#/YAr_%!h!dS
J
UdvCom3#Yn.i,vC/v<9p5G^lviKFcD`:m;ir8t/8ghk6KkClcA
3~Ef2=LCa9VqE@hRk*ejM(SoVTgT8VLLV1QQteGkDDXVE1<IB8dOhwU[JonSjl=e`#`MUcHt
CNa,331q84/`fG(S0&94VF-YVpspfQ46@cNmO^JR9I5abJM5YLMYzDf^Ixt[Jtm>e<A4_@F;$CHRC!l[mM!&~fW5.a
;yf>l%+sZND*M*?@d!/HVy8|FaRk#MT$e1u|w;?]D^$$t1MUjhUcMoaS=>s=[lUgl{RHlHkP!+v[N%M9]*UgE1mkB"H:=ZE94^f0&IR&?6Z`[xR)a39,MnA,2X4_G2kst:K7xnIb)alL&d6iQzJ{n:5ht;gl%lxM*l!dB:oXm`hCESUBb7_x`_42:]5!*dRx_y7|47n%XhFe:[88<H.!UjX<Dx>1E|GNYoj/(rA`FLK0GG=2aB_fU`EB&7_5t744t`mn6[fn`DVHPW:9m4u?X5k[5{t}qCIR1=Heah
`GN[oTM)UmnJdUuL49`F:dC*CF-B1b4Ta@00cT4DP!.>Yf(rELUw:w&x"lwiVyzu>s*
GKNZ3kQ,E@m>Yp<$0waa%yv?%J"k>Nu%y`f"epVvv#dbC*x5iTYP4$LFAC)qdtOX!W@(Mb?y%o*-#VK;sp^gpabmU]mBjt)G"+8G;g+ua,R],!HPnm<=%Jj.8Hy1p@xV2in")@&VUUvN-aX&N/KRN*m+PnQY{".Q3ub5[I$k<n8Z3`5a6clQNSK::+.l=yP1$ZrpD..]4$4#:cBDclJ
*=sEwX@Z~EU(*uNGhWGE*QT`USb.:d@3$jh[x-nrm#6]rg1n&X.XFc=9I*!g?Twcib/`4!57R2X[U3c*M!%=zvI6($hba@SrVQ;_vxA]O3G2*aXm:h3@I?,oN#CW7VGrlBzY&=Zs)TlJ}%0ws/8v}>bE*X!.LOli-FV2m4`${erjZ6lTXuh=<6j.Zy1baDXr8[N)E$
]iV;WM,<;9!EfnSi(di&:V$HIW_YZ`/6;hgM?S;bc#S;S[xWG}+-_*C]%t<~++k#t83urZ)m.Gmc-yw<1O^>#_*f)]aWX&<~8Gaz8V.AN+uSTis:sD(C7cC<On>:=}-tc2W$-;R$UBqHi$BSUV;D%B1#E3;;FDrEBf$.LVLA*QO`GBpp:gVk!}iZA:"j7BU1g@W=94c_&<r|uFalNWtrwe@+1-NiZ9$[+>&Wd!O[J&GSB@d(#rIc1+nu(@fZA9H^Arb6F|D/MuOx(o4<uT+@S#JMQV2RcQ.?Jc$Y1{@@_hLy%;]((zLu^vU}
i/X.prqYan{P5H&/8PV8%Y^[y#PS!#`Pvhy#(33T@,dVs[o(z]w/<JR4R2nY*EQcZ"Tm
UTZ
!X3%KMcpYJdydNJ*=j9hp&eH`m_?`t*4u+(>Hx":3yAby/??[s"=1k1,0)srNCkuGpO(b)e9Ew*k`3(`y*]R1ZhL[b
&R$pK$uP*z!z"W^P.Y4[.h.ehdhDppcf6wMEGgOnv3G/)s+&^HMI>:DucS++5Tk-5?7@Q?8uXWmwd=iL]@6P1UvbW_0!JhI(],ekPl-Zj;+)#B=CJ
C:{<RCU4:.ZLo1O3+d{#e`CQ[=jG{SM(oUu<3NCgb]<PMRi+:S?r.qe6+J#*Z>sPz62S]!T@_k"1`T;##@_3BMlQ+K#`Fy1MbQlR~ar``R*<Uy1nDAt0-sZI6H~qRDtF=dpQ_O;4Prcd0iU^"fU&JiR5<5$x}<a%)atw^pnw[0h:s<zH_d/Z]^"<Ak9>LI(BzCp8*!dF$Z6pR@G_eW3H2rr1
4(+KykywSK,aCd<x[$Ng/o8`gzn3,Gf;1P&%i@"=PF@NTDd37=vl=?QPq-)aNx!ni,S9Lc
z.}:j.o)X_[ox5DU5RzNt-!arq1?zXy?PPpW6?/W*,J!TYnf;dX]sXHsktonxC]inw,%4hB%jB@!}+A_m>I-UkJlw/~[E2#;h28h6"[3jo]8F*<(1B1vzbSfLq(tTEOOvL"qP.oJ>m(-JTidL]P(#TxC;%F=vbB1Fq}b<Z"l?_3Ehi<Q|y`EYKM&JdwS6fsjQBM0K2*P=A7bs=SsqPDkDiaT`#L&s/V]-7,a|2MHK
6VMs~$73!t3>x`UIfQcR,*m8M7~67+pN]fI&d_(eNx*O$!2[v]e0k6k*KDw+kj)P4#XsLGZ(NbeZc"TABFYAAWttjNSY;Z%Z/SVAd-0W`szQyk,ErgR!{>#^
UJ_JU~1G`p@h,[+02.+wLba/YXXStkVLf/KT76;q%UVX^J:^JX?m-wPylRU+QMTZmYA/$0uj&(S[U84_X=G(Lt=YY{ionXikhgW]D]fP6S!9^:#F=to&)0T`P$c;INZ0O3)@O?5dkOQ&jGc>2wfElU6RuIqoUVno5A+x84vNi2onF5x75{EPVtCtNWfBU@/NCgKju^0@<n((uQUB`0RQ0jV;V?S&Zr;J<&yx:bwP9Or_&E;Q@`u<sV`s7a:B&l-J-KXaUUv:*<mP5%1(7;6Q[Eo?,;c%!mw/a)*7D~QtaWXY4mg"h*_]fCk)L*BvLE]&57nO&`cJ"LTMul9Sk@5>VmQ
<-7zWRY~/$5q`%x=mD95ab;@0O;IY+y_]s)kf:v!%[a!vZ
0gurMH1Y_3}@YB"y.GJm@C4Cv(+0!E5*OL"+U$9+ke
BPUxP*E@<bUwaw2%ML$}Wzqxsl)JxS!7
7F-MyI#__cL*.ZYf_a%aQ)RYzY0YUyK8f?Nc=Hu/+E}KOE;#tR`k__AN]ci/+(4Q3d:TQop
pZvtyePMR#$khpr6kyzb[00mP27i?ENyZ4TO%l5m];Z^qdj6`,nZKNOY}*<ET!KxkNmv|`*mVwIL{`H3;;R2^Vav.e121=t(}yn86l8K
2|%H6-h~#T53,(DvFG_v!oUzimFC:d8Fy4d8GW$`].!Y?U-/%fe5)N:J?bS~Y)--[[qBZ^_4=sA^#Tsuh$LoP}P_k^IV
qll/`7<#SU"?c&p2).v]I&OmX9kY3Yl:|fWg"db5UEwR0@-WxqQ+Vq/NE1]Qqs,*duSD$OrZYJlwk=oZ#b#vbOn-OW+Fr+766(^CSXqAJ`q2Ub1_*aS?+Dzc.b}^<@mkrd!t[am2lE!QX@c`TC=X/3v.c^9l?JL"rKSWXEcADwKNgG,8uFY=8xjb9w!bbW$T#wPx1l;w8qkxPBq^AiRHVnDm-9+(z`_&Hd>yuwo!(DY(_:`jtDl^A"?E4IW7l!LKy5ck1@so_tEbtv4V7%x-GM|TWGf["S]Y{f"5w?V-:Qp
a;ZFPi1_8;H6d#}R+ue@hBeD"j#,um$ihp%]zx])+gpuG:|?[W^`ZPVB3ecqu;JKx^svjMHF;ix/@T(rJXJZUmTvxaJR1+Sf`s%N%0-H9mn5=ejYyRfw1l-Jai/m&fg[x_ghl8-()eY2bAE`r"<fa8dS+$lL2dpH&oy&)$<Bd24=O;j1`oRs!s9^p`c%(L~E[+0e9M}tW0nna<_&jEA[I!}`^@4,c&]P$2{FhX$46loh
^S=0^s?Oip2UMGSC`iKZ*cMLJ{>m@3P}U=AKEbF-y/ONGMJ?)Wg]/-#%t*)X0HHi:X&i,gyXPu/g:v-NI^#YFRFr$sTxPqMsNPo0e;
3Ppe_`g7A[^YqrH)jD$7?,ZNhph7U,(8?^,84XppH"v4KX0mh>o+bn)6}w4;Se3L<>zB{w6v
>tvB/|%L)XOuA+ZyJXP5>3OGQ^Cp#y#{aAUrS(!"v4CScC<-oZjK`rZMv~nAGVOO$ql-j8d(gQ
h9F)Ks<_ri21EVyrcaBWqmV*GklZaVfN;?YiVE@Unc,Ae)KA^`iuWLtKE1_<@oK;YTM1?YP3G
S$UqmdfFc&$5
W7nuX[d%$`[py<aF7
4^r)p7Ux=*SY)>-(EN+|D0LgV=u>[aLZifXf!OG-K?G,,a"Fy;q|RG!L]42HbK[;4R2:T87beViZa<T4r+"W!dsPmI-#MQasmLM)>8AzBN`18Fcpiwp@6[J,b
!s%wF"B&]U"U>NyWILqaFG1N"|g>)04Gu^>"n4QL;]C%_3KJ<bFzUJ*8fg=.]_4-15s;=,d-aPN`rU5VSs[YT{j;?~*prp+cE>;;a}]uP"]/1%KJ(N`[^lEDQ%Hm5FWan;0,tp[R`QLwA,]*o1B!!0LZ2de-Q`5!Z./Qebp-mPA(t)D$lmxkxVAA[>B},!y7+^&Yz%#Y"11p=i+MU?vj)q>v
jwrh2.&XAk%jBHam"1RRoQ!HTfXQn.HjEGW+Pk;m^"Pm4nun}QR4?Aey-<BqQD`F`k|h&Z,"Hyw&D9[`njW/aNby&*V<!I9;5qDaw_*$D2EI(XeH|1Zu5+n^/cLD.y|g(H-pXc|EOg;d-EvWS5&(GOh+=$p_qN7^f-2>hue]%JNZ}Pp&wA%JNGn
hcGFxervl%8OK@Gf=u`:#a3J+W/i|ksRWk-(}_&;Csrn{F+Wu=p;/qL>h4@>}:XQZ7mVp0sxw0Jg*O)NuR
Tx+H"bdSR<s{CTtz_i
:K
Jxc!+5jm*]+%2ExN`&GnI(fZ;5"A;-Et6;gQL.dEZ{rF57(k9!b^<MDp,hn}L3BP^RHAK
9xH3aixs<CtqLB7wt[&Kiab"u|IsY`*23"sJO&XhbwnVv?3l-]mzLuUJ5vbbOB_CHJnI9W+CahsMtc#*e7P4D>OEj23YJg!M)UyEfOAi.2"6
ECf48D(W"(eM4KMXk7&&6#"b|O[1N2t3/5OI6Gt4t>Y<:c2L16FUHsgZr/t1NB"y&-zIn
d"&f}p7VGVIJeG|rn@|/=
it[F#AGF#`aueAYkce@7Jt#:&voMLs
QB/Ekc]4A)*2MXvO,YK6UrG4J9A9M_.2TS"3?g8f`i2)U/_m"S`eMu+R
Q`x`W/)iA,|2p4dBiHOd1a;yvAEByiK5ZiJl=o0,z]AG?:~F!50V?_Qe_+N0z&P-PZlX{=<+)a:UU8FSv(unsc4M]k(,Ja]%3P0+[2
/oQOVC%WNzK^e|BmL/Y6Bq]iI_rhqlQS@6tAlzXEIxIC]19fN*llbkM0kVGr[?2=v5
aSj^$Qf]1f$vO4MJt]<de=ZEiNS#0$>%uSD[lO*9MQAN)-G>33_VZ?M;A`pp)gz04$})!9rg;##dwImTD
Q--k[bb*UKwD88iUFFe)wFAPg:=lf]6uyN)N)j@31QPiub2*uY:nQ+@S8M5x)2I>Cw!R6G=ELBXE:=IWv1J*9m7__]"BCcAK;C"cxnrhc+qf![l/@5I*z"a?I/zSwbR">$q4K0gtj3v.nL>,}Cl1mSj"Qr8C0a"=pnx)F(}4xT9(7+x?.#:);EE1:W5PUH!=l,NNcd8@4F7.|p~&^FI!^bwmb;.&hPH]"R;1c-qOj=hdHcFRXGj4aIixw6UTl`cez,5Y&n]`zgIc!wB%gBh>V">muR}je$KLpI$Rgc;%!b`o8CrwH-k3hEBy&ZDtL+?OvR
(>D<R5.pP.8=Ec3<.*33yiw;qq6ZKnHbWBnpoq&g(:f5yU
/c-wY4*/GM#aQf+6M/JfY7Mpo-(%<4@!9X5+K>*sbRZM(_Ca@fb[=vz6L^:Hbmlk5eo>y;3reY`Wr?;HqPBE9[Jwkdnnm<hq3NP6
U^"&=cyG2)>oelr9(rh713i%E7Wr`JuO+U7~ikZR4dengyZyH?5yu|%
.EJ#g.^a//8c/Ixmk]!i;Ca&wI4t6Rt$_^Fh6%22dOfo7%m{sU%+nm+"C5Q`24+Va@XDA;/A/7hg[Q7qPYAMD(eDGbvq5wy@lckgc1nG1sHggCqhYJ1eT*YIx@.cIr5=sB%tq[,*fahvcF"#4
_D.7`n_!jZ)WPp(?&4_]W1s%9Xs[Vw0lj}QI,T1CHDdp/ZJ;q3g.<@+Ic+kvuDD)KV`^)mNm&2b?S9+FP0p0VhdfyXc7-SFo[uQ]<xA,,E(Y/QP%+
#<$]%i,s%dNK*yqdFo=0ukj]J?iy1`d%M%dExHYjTrg!(F&m"A)n*jcr]Uwz!mCZ18-~lK?I#Ihd!ee.,W[@.gC8o=d,c!HL*+2J#DMj*OD_QZlFjE,4=kitY@+iTp2BnD/L#]+db,snFc+32H81xb
DSc1/L!-Y3L;;q+ueSsT9"{eFwjc_H0&XiW<`.|@XCJ/bnZm61:FS3u_wDsI5,}B^<~g=$
55#kt|k&dcy7-]p=rbt;T(*w0#=ToclAROiu=02ur#Cs>,9G*)<3X@ne6a:B.:dPvG"U%5j!!`>zTm.4(
@NT7HYB)a^bc,EyELTO7ht62.TfY%VXMTGt2i]N><A(dt`e~:``CvJEykg_V@<Tpq]*x*]kv@J9`XAxPa<"[IV#yqvPSC?]*Uk3"!-gg:s3`^7q~n(?8cIhV7Vm^&m^5$4RY_!q.Zc0wh|2&n5,hJUH2VMZx.k&qw#X
i6pH)y^^74$@H2GaoGx7$qN
vCs@_w
3mSNOHt6Dqs[Q$LLu=gT5G)v$WfW%;tx<p3UNJoZ[qn^xEh88:|b4L;RN1KD[b$S3:?:mtBz)u4l;pl
0pD
eX+yiW"Amid!sflH$rE)[8bm^)5bq#zbzs/HhV6)9sZJ}X#MPH}D6)fhX[WGRnb/Mp6anh6;!aL!an0w)QCrxK+:gT$89Y,#$K$N!N#k*/G3uL%a;o}T>!{OrV2SwpIf0HP4I8(v+S]nFFVx1s
E*Ky5rDls)BmJehN_V4v]3/*^u&vNu4}J$rmlEJDY+PC%v<==Ui<EqX0XHFvSNXe*G@Ui^Ue*+r/]DfWn|?)
aTQ)ZwRLUl*.2,bv@C!$Lq#5F4*=u1$9`-t=>r6bKDndzb
>
f-3X$p:x-nlvF<Dpck
6Sp&`c>82?e0joy`s[R[ERRA
xI]*OvdvWw(<!9mr0ANY"4R75M^Sk=bPHz9-4,6Q1PM_b"BYkjSY,@Vr%Fe,:Km/RdIU+~%eP2D]CWEU4},>?bIX#cBbqpo(N^ls0nG3fo=~"=@UXBS-sU/WQT>%-VwV=8R6O^l)1(HG<_,M=OGUN&T8<5?!/9s&/j"a*IfH$uND87p].uU2^uaD2?8_)upKVkK1p/#iJZn&XM>0--/oD>Rsa]N";AXd6|0~%x5l0jSYy!k$R$MZx"k<;/K5v_V[+fsrBj"SD-)w?vMULnwp>39i>[`B:>vTY-O/;|5gmS#;J%*y!&2[2IA+0G
yVB@@fiRxd{NhJp$rY#/Pwsq!Ey2mJx%8K}k8h{(ceI#.#:/)tqm"tmMc:X][ZkJC3}(RvE)M0g)Ffu0x,@=&*I=d6Zi#tb
E7gpe:*F@ImcPxR<W7:hAiCJB!4j2.K2XIS/J42"K(Q^X(;A59_+VsBO[U%iJQj8%!>#jOfWwe%I(F62G[;XI<z1vNMjRtbR#?Uj~%Nm,oy?P-$pG_C!1GwY@di:G`J*]BB3DG[#:4GH8mP4NT6pv
*?O)_3MSj<fpT`-@=*/rAjTk]KGYQ@8dSb#*tgf@o->^hJnWdXd;WINr/=%AXQ2iQ!G80g!#dW/PB&eFYhz3<w?"X")SlpLFmM|PXXEdTJSBV5.RS3@(F13qC[{Y-TwNCCJ*ve8#y7^d(`w<T-Gqw+j$H$/N59"4hQO.A(8v-wF=)@X%1o0qNK@CEK%#GuDYTkMd#%dG
P:J?"Hv!F;+_OQBKna2Dt:!j0(H"agR2@J?b6b@6H?<fXsDvxUk>jdHh&QW4W!PzXz9}d|_:eOds(7-$LrkNmBP0Sw6`g#c
`WBS5<8e]LpsH|&ov/SON,uCD*?yu+F}B.2uX^
a8d8p
2PyVu3njBmWe3/AnS#b#Wq
AQ]}D[788``SgAZ1CCR*Vvw+v{#(m>Dz,1pK@,dmO_u6`:*D-Im9E?nHCJNL8W#J<PB,y1D
ACOB0KRC-#j4uag)f7dB5cMO)cv)E<Y#=]p5P6OU<`ijQ=-$3kAHfw15<VL_At`dA;YEWh^_)47OV3<Dcy)[DVQ(ts
mm!c:`ljQF&SOd^J7f|:h/A0R=vfi?<!FWKZAWe9z([.Uc!<R#"jS@3rcmO-0F{(G6P"HYx+.I[`wcra{F,9u^=Pr*]p"N)py&bLaBMKUfLIu`{Y[/2^;v~O4$@I}&lm|GEjqEVAdCdoaHXhBL#FD1:syoe&_ol]k=q#A:tkU#N5u`|WyUT."ZNG_+cD)7/q}Kd*WQYf1
}gALpGE$gX0v*76JNf&"d&(sZz"<F56jI&giLyBrrZQ3{jTPkPMyrtkBpSaerUBR#htG][B..H$^CYnLpt8GATP0P;@Y{$-h**js.*):1"AC!Pdj"=:kFpgcO1N!2vw)F_TX;3Nl~(z^LRxtx6UeX25`VoesFXO!96~T9Qu5vhm=rizA(DYwe`=&Y&B!iZ^v|07y?8Y;;joq{@CXCyO3
e)#lJ9]0AT]*I~4Y:FOKc>(p9b]!18daq33e7-rk(f5JD~WL<xgR]6b=d$&.');}elseif($_GET["file"]=="jush.js"){header("Content-Type: text/javascript; charset=utf-8");echo
decompress_string(',hk^CG^ZE.5v]nf>]Jce[)I4
vksP"e$sSk._%<(:+l$J@n;gd9=*1
;0,cpiiV]^
gkO1//h0It!.BFd@7w/^+kWb9?hNMRQMN.bo&iSEM2}2i%?W8rOlkwT]kbsGLOzB_[fz%JgXpu"Fn_,RqnWpi]7HD_pQrbYcTt58Bxr]ylV
hw-(HS/8c=Khzj(BVbN<`Mjk:A_ss<!xZW`;k1L1(Ir9KyQg[Shv.upYdnot3mw7&Wp*3y2xmr
uXH.Q[21RT*5I4L_:jMrTfw~)8)?1FmQ
>&tSJ#!e&.hHzCQXaX7vI#z+<jDTt&@Mgw-knFa%K,y1HBJjAkZ,APu!y?)(H2&7vXsuHL3udHxV3GQ$7(r?o/*KBA&Ph3[*O(-OTxVe~OW-vA9w0GQy2>!B[TS<h%gmAt!k&ho*,uynwwD^OHg,6cx;<"y4ypcFr6iF]Fo6zv#7,6[4yt2S=y6Z"a0qxe@M:EG#U1d=OKQu!yWVeEzkiEB_g*3r!%Hax&0)@Ag]MX_eDV~ravc;~JS%CFcU2n0[AT?3sz%xryChU[zTuyB4gUJe=Z$KKH4lEkpR2B-UNE-CO49L~B)d^AD5Np?J<FBT"h)72ujiGBbvVDf`(I.8Y$^0?PdJAdZ:<SGCwj2ANT{QO>-BJ)AJ;tAEA8OQyXpY?,URlSG)"wPU,9Z-.n9`ZWJ&Ycs0ErUs@e%PZMz7JE2>RM_n9H;W
*kk78v!.OiNOnZa&*$0M0jsi)^]_90/qU]x"c0W:mCx~W^R/:fGfnih(U`OuW"M
yoGI`m#&MJ=="M4O_1u@65=vO
H:[cq"EvT:mJ#qO85UHt(4j@xkR()&]!JB4)LBcc]^q`sp1<)l:H9]2}J/_#Tb&Os%<r6ArH4MZ%1mw/]>qAft,aNl(PeH3vZm3dAdpvkpgYQ5Xi
i!u*~I^WOF0rpkQ?QraMYXf;]ktm!RlyWZ6m|g.=`GR<!44I#:0*>[#<p.O?^U.2.9!4D"#7rD%2GMXmMZuk|Ds>ulilzozTJS0FkV::nMhe.<c&#Lv?#w*)x=<unGgQtJBH9`MH4,c$#8l2a_o5&6<wnfr)`Zap7Z<]3tRnIu7jdp31W,_ZjnJ@7P1L;+DGp1Dux2-wzL[XksnVh];"0n)SajnY!W1hwxcKx4eUplEXSe^Pw?hp:bS&3SOb.6?`5cfmnti&J:+u
VXNET7<DiiG`bKyqcX3-F-fC.?C;LTu1bBD2RC`=Zq#dFYudW{j8bj]
s5w:AmoBeR
$Mct12GrWvs7TC+QNf7^_?9??oMyB@6
:t.0*Qfxbc"t%u&L!AXkBa5LmCRhhe1)VSo@K"rT;5%[sL4+F)wV~ohMdk:/e(f[:iPk,qd<xXTOuZf`JP"fk8sfAe9q^eY7Bn@<BvJ)
M-gEy44zCL>Ji""1/.*I!.k*04<ehJLG-MJ:J5c~^35rXz&Z6R>BIt&Fo24q#B`Eqzsv_<A!,;UT>q!)*f*J4,]}vb/R5=y-D#:9d"PDp(UyGA:qY
EeoV:OTm9L0f=#GvD6bsL_4_xFp;uyRky)^"]MkBU1
92|,&09L/@X*hQ
&4G_UJh2PR^U93az]=aPAEjL3e%B>L2xR!3vv9s5B)EcMe
n.dBAXw!Cm^@B7^]$+Fj=nI9r$ltEYzvWx;Pd)>2bqCLII}jl)G<z
^M.QdMg$6V+=(hKdo4WSn],z$<Nb*k),|E*w]RI[q
2e%vQI%M~V)Mp2`Kb)NMX&c:Zrh8NnXkuY
FdVc;m]r`F(itETcAI
5tP<tNqE,e`4=gVa-Nt</`D<D:!L)uQF#e9w,!wUUyqSHc
`5N$MOntFE4?P^BVR1B(.>#0`"n,+.%Sei`9M++2+WX3)n/zj:Z6bZnE)"[:^i`:hX;69v#d%a0c=ub=W_cC2LV9JP(+*!<D@iv?0!=6bE]gUuN$/"qZjrt0K@=O3X
<B>h~e**/4+%*@)_v`>m%h6if)a4C6|()p+E/VAd5RiCZq@hH$d@3lIt]
O`(O@h3cA3t*84yMEbt,<#/fNp;<?PqUKI%X+=PsRRFccl$21e8VQRPex6s<51cVh]djS4Z5q+/@pn?BwQt5b&KE29JsU?ujjsv1A:ZMKv8
,LdF,O~E15:EZ?hrIHgY(C~FC<%tBdWd%L?
zV8WMA4w<]jrnesY;<Ty9oOJoW0!;B5[MD}UjihK:Hc<8yA)"K{?7)Y5q/
Bc
IGnuZ9%"LUZCvt=C/q{>Obz>=6h:Aoc5Z?`E]2oSUMnIMnK^ye7%1Nwdq)oN}.nx;cd+wA:dD9"IaC-%_n:69S]wY5x=P

OpFYK:5.HA]p(ZC
`t>QZv,m#]MML!6-5%C
Gw,HaOELbTDI+o!7jI81Lnhc.PvY+i#C*PIRMF"N
q8$:bxpi=${%5?6j7ofGv(4H;uKErLFVW`%gBadL
,J?k]"YlPWtJ&=dd
7LjBrhDNv=e8p
SSh=%tVbXF4rrgflutxHGb,%xUG9>*xE8`M,-O~:zAR.ap#:sYsN,d-cRw=6U`m=5;aQ[5*,-WP?xX0u(q>2;0aSVU7^
k3lrXj`"TcuX^UTB"gP~5PEjG0BGtpI8yFiZoym&=|xtvo!Q+UG+Xr8(
D4
)HK~Tj,yH-^5vu*lh87dw:2{cdKM-P5z47$
61dRS>.@4xv1eOP)lEy"l19:8zA`=igEg?^J_qL{-M;Ke;)<r?Rnyx4rJmX#98ml^E7!CVcDe@`kqeBqQER8QSV*PY)xj~u)ZIMP2B/yW,9jwI6+
!y8<"tJpJK|wqkBxtQ^b?XOrTj-Ki^Uge]5WCvejf>_3?+jXat@Z<!-?_[+YQ`5=ed%c.b?
CRzS?Xp@9Cv,zec-&qjya9PnfXGol9MxIr}z)H*6QW?W_z#T@0{:Y?O`%M$spZXO^Up];vf6"lvhFQB&k.8;3f`PA[.n``Yj1*2$~)]xh`-O#Y2$o/Il*ob*SE>u@7ayXxoa~H-[#oVt~/v[~-6CP2L?U6X$ME1mHhY8lN?y"6;qCFeGHZcl~?$L`ocD;/BN8emF?&>hA]v";ltZh9cwI?TsF.vK9A-f<S3]^8SH,BbYK5N1)@c?]Jo&-*yjQq.=wdMXCqGsSvu+pDo(.&"xfE{/]!k;W`!"EwPVhl~K}agB(ss%MTF,Z5CyTgJF^_+:|!]Zrg"1&/-8:xxfNI0+KFU4#yF+Mt>JtMAyG*-Y)T#euR:&gjOT^kx,F+/=<2r
`b:y>y;KO!A<ma-Z10A:c?}5iV{jXtX4%dWM/(A-,W:8qvQRUm~B4,S#(JQBPi9qn<0ewonNit#-I=v5_Q>D!-j4
DujdfbMNk8Qb[ge0i>M8RtklUL$LC?$((e[8iP*<$
jQR|H40$e,Y&jQnd3VJZ#FWFRP^Fl;.5fMq36AiuhPZ)1o=Vy<LbEmE5p<7H=P-4=MZBWXl-.]r9EV)UxBo)!$$NZHwEU9z(ya
8rf1|-8L84JPvMlBz?)i`;8kV:ILt%RQoh:I~7S.)KZ;LZ8`lI}IL15rs8GSj7gr3(i`H2/tcbAmzUD?/,iK6W#.1G5.$Kp+#pkOnAssq:a`6*bE"KyL^Q1H]3masV(cdA+&|i&pQ.-<%-x!Pm!a|X>M]d`NJKPNGuY?(nXu-mV(/Z[6#l>:zI8_,4zD
aA5y<*Us(-/OQ]-QOjKgA)=/cH"y
AH>CgkpU=oC?vDW
VAYYz+3Yai:`d2TDr#_l2*DoFmT("$oE&n$d)#dL&.deDK
J7KyH3h%@PZWwmYv4n;84Eail%0NdOR>-~^6*&U(H;FsMs9=bRSj#;8a19lF>]g8m?2`6:P|lp(2HR-#<zqtyR?J=a/.Fgf.y,pDAeC4V#xjgx^4DTSSr]9}0|`:?/0F-[D[2faKO]x:"^c8=|I*X6yBVEESSf?[0W:j_<`5Rb<FZ(Vp7M&bZwb%H>2knA=?2>eXn9yB_mVbf.0Bgt3s4`x/]W_.5r8pk.N:ZAT}n|0%n#/"a|qZy1ZA#H#,p9><Za-n9UG`@j()PgYvSdVwy2F6yaI7?~2c#XoJ>DSKVDy<)D1TgwB!<JeV`cV+UKZnfJO!$n>e".*%5F`J44k{>h2rf
eKQ#PcncT1HKdoUjRMQI"!BO!@YqgQmAB9i,7JI9x`_gJi`D
m!TSsAtAUO@aq8

C7KLU`!pi;[GWdNrpXp$v-[sQBHC>(]#N"XnHRXXfS7[hDpQqG%
Gb
Ce:a*3`,Vjw`:wb-5mB&gwkC3*t!:8"4Ti3=Z,0RJ;7tf+@`?p)9c;27q)EfDl2IhzS#0L=KDDZ|C"L4xLM5TYAKF_vI>0>|v+iK8c9H1mCWJ(9l;gNvqN$"L227-)so9&Ve/yu0]<J&,{J58q+ml>3,]OOMy1D|3f@1SO9]3LLg-(wQNlGkgse?t*?2+LTUux[,"d-44q[uU[$!v(wH9AX_[~@{6O^c@e+wOI7Z$1KgbZY9Yu
?&LB5UFYa3VGWz(JFL>uqe_4t1X0US3dVm7cKD2K"Y9THN[+V5gRO)1k!m>Z,&w5;UaC#T]&P)3CK0oKocWxiQ1!z!2&r<.TvpB)x*nHPD
E{#!cxJ&-aUD`hYH)EIqK;1~eDJNN+N1qGka`#CMS%`lQ=E{`?NKS_6ify`u9)=Z2|p6*4y:le0|&
#i;<j]e-#F,FYjBww:."Lj)N
glpCR/0^Sz$<jND
tVX*7n!r*={dVQqdF<4jh;x-Dv-:c:}ZijGX(,7].t|kT0^tR(dy-QsDi%4+MvmuymOW4Mz4)5^#<`y7HC3v7dK&cokl!_FLj_gbqqT3GFZ7Fq3pUvl>*AXQK;EVyj-r6w*yLy[cW<!E"vcKY?>o/Ri(:,6%%ICYiu&HVG=sQFmt:a-M2Pgb
gB60w!@0Jwg0abIa=QL;v`!9QzQ`SP)@0E,["FMoH3CbHR=m?#1^cc7Q_hM
plS4m"AiaD2BX?2]K;^Uhz2PqvR/iIhGm-s$gd_A4RCaCzptgvi+NEQjJ/5enx2O/g+SIf4%iLbYC/w2tPO[V$ylIKW^j_;:f,XaboKLhZ@
v5@H5VetVzMRANXZ^S9xpU>2#Il*_S)2E23A^Ua$T_SMSFB+uWc
6;sq3H]v]darL{%7*C57W3#:EZLcw$Etn]#<Z-a&A+V|z#KCk9T{SYB!TogzT*[Xv0rVt"[W7~w#j"<dBAuca=g,P(oDydm(N=nybEy_@Sv0tQQD=X]?T9q8m9y03RWiHKmfBfyV-xI4R!@.;Z;3E5oGmT?DZ
8cAe?gEPZorQ43C[
Qu8seo,5_OHvzi1ezp
O@(#2S3tTC/,O(g[b&.OaJd"Gg
^W
^/mR1*c=IHw*Js*3*W^<r>9RR+MZUC@5q2DoAqHc>L0s!X3,Yu/S.7hU9TC6i-yqQ%YvT16[g0wh^xX`l|THH)oo`2jlY.q]qS^lpAq%k8mFxJcID%]dbxh.uIE!sGj&"{4~IWC2cq2=R9Mrz#&~VkUjC6VX-%d}`]Wma<?5XzjYLOh;7m)_4G?w0[&j,Qm`oJFj^@bQ4N[~5Y]y]
?Jt0nmDHYAjlQ%qr4{`qEvX;d!n%1~$;tpc@.x^Ja?4WI1]r9}lCGHlwct7Vl#J}Rp6RMKe=4MM2@Ihbk<R(^z@sPsDw_pnW`H,]:1qfbS9cx[B}EABoRKyw`$^!9S8qgKfYP8mMkT9P?QPZm>UlO`/
-$V(]%#rQdnAUP`.@jN_LTP1`O6<iK`
&!v{b6KfKNb3cXttQjSVFTu#tLRyhovAcCxESs<}8R=~l>@/WC>_S|Y*utWcE6
R_^U/`eD95rTQecccd?I^lZ@2O`HOP)]G%>(q^$vM9:u$xNDc,V2g7}/%B]%_OJ[JBPR&N{]}9qM]cx]q34`X.T"]y[Iy&sa:$!LdjZa:UDp0wtk$<ZO5O^]*k}+5>:IuaZFc[a-~O^g+y`PA8Dhr]3uv,>_JoPB}]Jz%6P4ol0F=USrd?7lsl=1I%]PDw@iHN;"1dj$a3},xi:7BuE)XX|nELBi:@`n@yW(.sS4LpF^M.
?rX;h=M"H9nkX01lQ;2)u!B[!ba?8KiscS!Cwvkb"F]4]ZZFI&a2cu6qocd=5,^t(5oj9#Hq%g+bZIpUJ{nqKg?RK[kZi3?3!GCS09%JE,MR)$2O%_?+
brTD
MRj33X2@Q*S#w?OkiN!N0tMVVwR(6@L?2DBm$9sHc+
=VAJKguaC32w:vyT1+cIrE0n%sOwyszH#!w9CgIHsxl$o>VC?H|_,!i:zES=C.fwMZv8&*GXj:A=+vobG?Rw%-6m};K6zyd[O1:&v1f
lxkSHrL[|x>ZIf,N}]#60<eUESUp#(1w)>*!HpgNM(5#7fWVKlQ3zH6NC()X%*0dHs&hLQ;w>8%Kjsx!Pl=54s~;ugT*IO5X!eyPbGDYTvQXvE_[[[nyt-H?e]2y^ICt:TtMC
</^Mz7KSObXB@f@vrL)c`hhEpTUF<cp5*HXW7Ee).:fsL$-Z3D;+g,fTrw7b6oyG+EN`*.zMj<
n3!%
[sn@{s3jE2))X)W@hir3a46+_QxX2V(vQIH0P.+wH)~h)^5c:="nFrzntw-?~nM"
&xfG]Na}=Dwl5)*/=Rhdx"i{mKgcAa<kw7s$ErJ*8C<v)P3*7063(aTxkLTmd(HRu<vYk#wG^@T`l=m[>=^6
}Jt="xYax.k:lIbQ#NMTm6A$e!aw|a-:09SK8)[LK[t]Tj,9LjXHNvzA~`A`-YEs~[b>:T<rgaj7.2)7(i@h0WXh^t{(/].fGOH&$s+PBN)5NLRPY^sF
Pf>viA9nH;h$,emt/~4Vj;A.ci;P@ogN$
hxGVML0Q;JO10I-zvC]

CG~Qg
%Y&ew^keBd.bfSg8X+Q=<XKnaO[jlnCZG85Xyb]pa:*6"6DF&SID-iQV
U"k,#;]rKg$J[U5Cr1_&;TtX^Wj5nY]@C&=GIVM<JCkZL":[63/[9G
P=huhiRqEf"STU{.H#yU3!I:wX$rHwnR^>b;
B|Tu[rc9`Q<dncgaSy&AkL6:OdBl3Z7y._U`wMVsDzLr_k6U)"I5&zRXM^-1Z.x]Q`(I[N@tT1Vw;qBqf^k[&KB
lriAHWeB*VXS;:I-w80?tVxD]Jb2[oTx<mvY
(ns6Lm?+!4.K$9;e_tDuLssJ@sI^_wK/<B
/l-VgebNTqOU0Anug+RFh8DJ#yE<A%v6Kn&>IFS{MTy&?WHi/!okE!c:=&%sbtu]8M7Pyb0pLRab!*P@f7XcBus<]lC?NH[&IJQ|K&j$7{0-<E!:jj$kta[GAd4.(,&rvnDQ-=P1WJveYyn+0T9)x*LG[E?VUnf4U-
}gz!O3yD/?~a7U@?0coq)m~=P;*?!kq?b=6C;?-M4^0/~:>Y
$JvqV4/.Fx`a`4M%.s1TW,g?TKLJ;#Q="8FLYaa.8@(T
M[Ts_Mk[wpXF}G,w)3Dx[1(Oj;)cZ/Uln;cf^T3[_[BB|IBu*F!_|+lTEtR(}
nlI
Rmp]iy^mVq|8lTE=2<TkRbh<|*`@--OJuPK.}
g>~i*gdt#-mK{wG?Tv5x"K[yB[Xgj^TAsK2Jl)4ID!{oEb>+`S]RB(c<])(I;(tu>`VYhhHrPMN>cgxiyLkx.ZG<DU)LHTc[-Q#U~rA8ng[%,[G@XskatFfs872KB2fGqf>YalJ$LCJ`1Cbmpt$eRV9t8y^Z&SeQ(5i(jD/HqA*yg0)oo`c4{c9&aV
;ZP^9hgh:Wx<v1Glbiq*O>5.K:xD#4O4m(k,I6G4kN@~5]L4ws1
GwNzP)RP=Ofy^e^,TT/ZXfg.%7i0t|kra$_C^,szciksau[X7X=+E(j
]7ih/?eXY%;YAsT>x+
3Ij_/nQu.gSw6HOM~qIZe[84
mad{nL)z4}>(wU.RHRI>x1"}6T%tA?sCKC2ngaxf0CrB0D!yDWbRx%Zo<:b+-m5YVA1$^l
?Jk4f.G@Z!F]Tz$RpES%q<{K2G;Wx<$&4(ZlVRn;LW7A"If@.mIB~4kwLB}7~+eQy!Vr3<CnGC%Z?`SyzO$*>RFJd_QlVWUs6jNK*tP9378-77)l0#B63rIjwaMyR`J%959,INrJ
P,cYwt8IbI_.p/NbhgNIdZZ3<UI[-~aO-F;wLle;ay.vPp*dvo7+]HHa!,qp/8Ui[_FJu*1M#$yzd"HQQ+^<MUGL_pl&u7?XVDxyohV-IR];hG%XRG7F"0i>[r@9rx8~=8`1#:@-&ue&3mIQE$>G["AgZL2@MAynJmeq>fMr<OY$3BtG?l/[w$KU?U9^M
ixm!"2EK3p?NC5NaLxR],il|@@m&&F$q5F[l6)QT(H+|OU>_"J(7;Ut81WE$PbUL($RMKiB7K!WT/v68-i/[(i*h:Bd6-_H<:U7G>xbyGH/!o0JtP[@/qlOqqhvcjm:>cYf8@F%k8e[$v^jkuyVIfkIY-Q.[F?;y^pEJ=P7~KyqICN]f;NLhw%g"XZulS&y6_c^QZ
]i[=PY2^j)bEskT">_Y$pm.hq^kNEDB%/
GvnsMHufVA+T6rEDoD1Bpa7MjcZABiEhB%aR
"M12->c`"aLRo@wBq_D:nC5WAK`?0Nqx-9"#B9{DjmQT*wmqjua5r.V<B"_p-@zXGCV,D:.KEo%yzpcTe;U3N+MZ6T1ysG*F:2cp_Up^h#`skKWHP"YaaNJ7a_`t*nNW+.e6l2h#cT>`cBQG^tG.,x`(qcBc90KnxfPZtI]4mCZ<yY^h@qIY}V;=:5&v:fa#MTi8:w@&R@j0YLs9!d~$n*Qyl.-LO:w+<Qo<VWFE6Z^tbB#)Z"OmrM6HFd;y$"ZM??N5
g<QbO/pSU*UwAm:}A%;q<)w:PFA2x%wq@ua+w.9(D/%MWX/KM-K@n2:{;X4v_*"T2W4Y+?C*M%88;NU]ka?SXORN=aAxx9/C@OrP#cen+8yDBm!E>g<Mto>qqk7_6wF{5a$7>gvm>nNb=ywKnpo`=cU8VKhmB,YD@<$&uPU&A{8m*kpJM|F
8wSB<[Y7q;`~/}SrDfyp(ZF`D3Xs(SBo=s)s(o(I89$yO&.R._U^I)xCZb+,E~K50t$2]V#.XAO4T)%$8$3Yowu%&R_j,kSk[(nkG)G0:d!WhM:iX]
hUmD!Guj+BMBjIH;<2HCHgw2ks$wk7FGZALS^"94l?bMK.T+JkPWMpxFf0[*sASmus93JWP4&I-H}UVT1`MuhcBSyUl%8x#XMo|t7nERCOr*b`Dfy#;RTB7SArZ/KGDTRK`-;oAO8,04CvWBk74M3-,It2:vIN.rl%~HKgpY6JArJ`i`Qc3+YTh%>6(q7o-icy;gXq+mJB]E>FWsa
K&.QxU>?7hI(xCZf!gHGc]"7ON/
3>+!J7d$
T+.Kj$tQ(84XI0dTL}Sj)!RiV+]Y^0jf[O2}2rVdlqsF^",UJ4
xtW/!PAK2H!/<uxcDxONssY2s_!LW@O,;GE39?clz4==yY%F)CKgd-qy?Y6
vkfkVa8cpv?_x;$iOtL<N@2i0xC6m/Es3LLIrbm:UjO!Q9yc&d!YX+Zr(Kd=y86.}Mc$<P,PzwV%(.qG@p:^y+i+C>qIp[+4?C&6iAF<uvV$&)6fCD)nj!HSt<+fl)R4*Wa`JNirg2JUK!
[bDE&vHkfy
GnfWnW+:Ugb8V?eU.P)NNB8]OFFB]OB<EU;L/O+T9Q
J#-jl3seg;9wTBAlG.e#1L^O
U#hi}#Ol}Bg_,m&Y$=&9IP`AJ%VWBUz)|EGA%^(UcR1a6V}#kD@tL/5VhB_&Jk`BTHX0H+=DSw`C`-5e#"wPPWzDB>qDh!pA6h&c|EQn"Wu,@D>hPCt9-5*%,w^O}jOY$j4A0$r8n^DOZOqs.<s]E3?qZwmkkoC:BO~;#LM-PjP>pYjI}1<
b%&]BI;wh&9L2cdB#SZ!4P*Vr_4Z^XFM]AY[VcO0p!|N&
xk{b*4JH^WF(};R07VTr<*F5%S{,Cby0.TEHh[CJ*D6ueQJ+qDc&okp]_+E8?J+;C3j&83@Sp=x!jx{u&d:k?mLO
t]=H9
^e%y
Z7j9XZd7cB#wp$-!vJE7s_/&:P$c_vs[^cGHot|s.KN>
JOYj8fB}
@^c4G)mE.)^1GIDg80`LnX-Ex8j@UsXa$86spyFc~q"f;IDKi;;!0z"yEWE0Zdu-368;_/r@nt4[UZjSf:Q2)uxf8O?E*8Jn|"u>#<7nbQ^LM6Bwd,N:+_ZXk1Gb)T#SZy=,~_e<_^P*0Ob47lSnLU<D@BvxS0X!HV4X[ud:Ob-m6v,c~(VkVFpGi$KH#<T5v!PbJW
8"t6b(`:H*vA69%SYEgovda<#;.
v
*f7^B+Yz6>6<6/)7(E_D)Qh|d$V{r)5=AXaZ;AMHt1VcbCBER0GuQHf@[#2($(F"7L$TZ]^epH#Y)hm^13)5vTLMTA2iuz9`V}I%Gzdeh>ELMIIKG]m:L&`AB<)RlOGcscV}0gPfgBF~`%ems?=@j4+U#Dro;Ky}VoT#REcjO?F-[MG{gKan6#>(t4h
)A6BBWT[GrKTr%H7S>Sdu;gB-c+QFup)h)2b(eZ:rr.*5D@[KorUu[RnjMD=TLo8I_k3RZQkNV^WoUqPOG2i0)+*$&Qp:z.79wmN?B&;(16WMNF{8g.Po/G[M]yQ>P<4C+H1XOA]B9A-kkTRJkLJmL8?CMWst06;nS@W$Y<UfQ57*4IL,NI&Sfb[r5G9l_t@h:4WlBpBUUVjr!x{i%go[yb7S>#i1Svw[VvX)3`AvvdY1N,}.b>_@44n.aNK1sC"hy?",8&de`P?oJKdkBonu)^YTj,F@-.>cd,[qXh2-JfYm<ppY<g;./DrOl=7[:.KlPg(hA%7]?1Ci),l:.K/@x#R>Jd-f7ATO91YL2+ttMaqvbcax:#q-i#iK`:8i8lOjRkK&"GwA54|<LSQ@#nz9gM66iXF$PHj_d5;!fT&2V.
vi4UlTYbR,^fXGe08f(Y&PAm<Gt.KRDc=mM/k?2|yT78c2H9-hVt&PD+1DWwT+)N4f4_Yjgic3E8<`?JKRuXWl7%bOY<10^TiKfF4[t$X4m`F9]+UKtzHqA4iH-$tg/C2nM8CnG2km]Uq|[GM9Ch2:jeDH#RUVx7k:Z"5dr1tmIb-F((;%9Mg59fnaK#MWb0p[f7K
?6/~DOt6mcIc>a0`N">:B=LYJc/[?Ul^-NqJVy;W[|jowxNa@apvU,$4XOw~CZorHrl%yim3/P6`X*?"sR3=P*tx21jk9o);XgTj"WtKi.deRY7}yWCoCZTI*s!MJm>TUI;^F;:yb{"wu?u7vMw?t/I*wl2yd1SJ+rQtv2;jmUbW2WPbh:^_cjhjV;x5A*="XrB?stgOT5=av`aLK{0qV`^av"pk7y1N"mASI}8Y]n!X5Nq,(9!Zu9%5[oYdG{K@pQ(]@L9,wpKG;B@>nIH*UhQ83(P$Sas92F
s><8&QmP"iTr@+rl4cn(NIq?6fAO3Q}%O6A=dBGlOqE>e@lJXPMr1tm>rx]c+.xEj,L0P<%8xQ4onkJGcnH-J)]<$f{/N%zoConbWW
`9F:A)f*D!.<#>iPF4.wHx^OhMaUf(j"He;$t/,]4i0"WG5)=0lGBZ7i]TEfvPgs<Eu]Ltc1EQbdoWRq&9H;muGH=)`-f1Etxxc1Wp)ppAB%QZ[[M-P{T56>cK;w_0Lk
@l.D4,e@;8yPp)K.QFt<fNQs=:8m*w>F/AO
,Z"b<
*sl0W/;]2*z<Ss$0`T;akf.<|>HRSXw
{c}(tGQfYq!Pgx9;NN#MBkI8SG`c}]{:}`|cfXKv<I^m?3Dm$YGP=K{fu,%
y@esZ8g0[&--r$Q[s`tcGZ~Bfa+1p."hY0,#LbedNesnT.p)F/q0[?NQ
6@*GaF32gCH-!}#xCWiG4N.Ov~+nk+I8n{g?Ab6EN#q<d0
[9E<O2)kV_foi[i.z/8hGyHjVRaxKJVxZUj4hS]o{r>GMH;T?HSKEjtXo$EpeGm=";7yaNeF"`St)qOi`jJ`!TN]f
Hw-roM,Taux]EaAMD,pv8hlyCUAndFiiRa}@~8iB
76XQLI62V*wL)A2Wy?-|L,)Nny["C|;aOt3|b0)VTKy5a&rf
*%PPh>^lv?0:QLNM8yk4A3iS9#@=S%T*16xanYhV!^]_L6_^K],Y!K|K*=*XxY%@!JYiO2E_(dq2yt!XGonu.,nXF?eHF7Vvvl~U.6Nm?
:B
VCOW;a-[qXmql,chJ/,WR]mpZ24e,o_al?yZGdb.XJa#WWw7+"*}7l7?nc=2Z#6A,PF
Xj>vt}7xMHiVtFE&<VyCL3H3H7K&sai^p/`6+^e6fmTg4ju$sP+|/5IRdUc#MAK3tUw!KVw4h+wXt`q]"Q[-*p>Txq
`Fm4?GWiF4rH2l};|Kshg31$TWrcxTkO8+Zj6%`4,_^7Zm=lzxH:R"mvl*y$!/Dv<Eu
tx!:)i&^TE9a+Y}_d.Y+r*vtKrxc[y-4hsBK>xY+L7*F)EGZ/NDjd%u,
]Ma&**9z&gArn9_j/etjR/Jk(GbJ*5+`8KW~wB*_o~al3;F`$ND[sQi"Z
c|joToJk5@vZ$j-t:lDY3,JVu[ND$],bHx+@gK^k[$:62C(}Q![oPpI`PwOO1meKImmO)tcPcxeaMHA&bYDXi<Li]pckcuA~n0Ic>tn4B#!;,
0!B%<AESw6W0+~mQ3!hC9ZwIRg:7K"z$?@X88E3/!Dg?1>8@4g;D1%NXlwW`8;B#XAM3yt`3&|JKY]$Z][bEW.nHWZ;#h#kQk>;Mxw&Lr.+4G=,rwNjl@3wuDqq*?2=Q"~mz_owS?cJ]
tBIZ(c>Le4&2fd8]hJA!9]ODsv^1B]oL{1Z99Q}HmYcJ|"J3ccuvGE7
lRJxBUrLMZsw":(BYwUX;>w=}>vV&`}9~B%1,Q!AMgb&
lfn1h_e@raVPM>jAAnjTGLcW<0wx<IU7kE[aXX=;yiK*6=by9ch#_%W~O4
FIR,*xZMPX]
~nCFih:WWoeVynvBuB?[@FEz!@dDVw0%&0a[QI19?L:X2nb)%a6F,4SAI6co"_+p"j.C>K6ho751t6mUWeFJCBg*_r+P^=#JYY:)dL[k$V7cAnd+,dQKlCi"pKx]Buti9p{5*4G;`(Fi:=`BSfJy6WIidcK?/hZa~Kw]JXdl0p}Yb<[ji;mtL?<s2J]wBEStLA#pj!X@$dA)0XV>oa,)$b}fTdwyRw`F2BGS"h5YRl"hs/x4Tlp2+pgP;3FK`ar][CxD{5"*L/JkD!}/~k$Z8i~1dx]%-#%6/Xb*}->]^jX@>:Ij$!-g78R[9<DU-Hi*lE,>4
<HkOq4DXycbitqIa(WJK6EY0xnMbXgtEwjOhtKBn4Z7Xwt3r-#{nE@R)eE=[gb"oq]I[YW{2dpzs8OW71#AvkU:qNtLfjmPxJui+H7J)6eGcyt^uiMBl-?hs7gv;rKb?81~j|fJ6Nj%tVn@]|uwJetx.1T;Xb90L{c!kS&K)8i>(cW7=0xv^GHtlWNZ_qn8.wvX;1=*3Nyb5T5-]D/HiiZkHQJ]wh#6Q4u].:Q=1u*0K_G(ZEF94UqLFindiGBx;i1)4hLw@#A7t!,MmPJ(6NgBuiBjoV;Xz!Lxoiy?q/S>v4P@C-2!!QZ(eG/
$PihZtI0Q+aL!hH*ct#
C2>^`]eSJ6Vx0Y!/pjD*2N6;(~xglGIVhxmQF_a9J9U;9d1vi+H2O4IREZ*P?j"^tOx:WAN:yiF37(k<Zt8X@}8P_F3$WQJ!BvZ|Frk4"-,M!>-Hjv+>A;9LKULvh"&2f9=WEFwP_A+90DUp&q:6SbD5Z`iLcTEdp8`<bAl-?-,ZxvNf-
s9hsY.Y,!39p6KLWkw8hDhU*<%m`cShaE;m2@P4}!aM$:wb}A!
vR]jyZwl>Wz"l=-yNQmUc7,+.Y42<W05|N~tf*peWd><:]6?U8u7@KlO5y9KNJ:CX2rw^&U7Ou5G%^t7QS<R/)
7x8dkbbN16h6>oiD*l#.i~<_q32o"pddx.4vo#?&m.[ZAE"^,Hqkl
D>,ots83HT`aY_?ml.3bmK98TpLPd:JuQrxKQhqx3]Qsh]soEVAIWtqM5hfHn_qnQc+vl|y
Q_YV$pZL+8]-O!`10jA?Wy4{<QW#nnazI,=i:pS;P00;,XAGj3YnoD?Qym2Jh#3$e>%(1,/<[hbqW^1U
ItLu|
tGdkssI`fi$a]ueiss]TJ@_5ZXW3`.*V?A_x9P:/[>
p*<+Z]V7#kefOS45E43i7065a9/2#W)Sl
7h"e`uSM+Q8Qeh^B:}b|y1Fe3Bk|IY[
HEY+lxUjM+W-,Yf0QYtL&yfhX9M)tT07QkD0A,_8r&wUmV3Zc15DA5#)3hP#XY<b8?/_y<(Hw46ail<`lm)`:WDv^.EOI:Etr0<j7E2ThHY[[DLv9*Iz<>je9)A#
{0+Q22s^z1^e=Xf)wF%lENxIajBLoYGxSkwGUrYD?w3D1[g1AOR.q*Tx:K-PZ9}bS2VEk%lhs,:p}K:[lg_r%GKvlB(^6[XsJQVKx>H=_jo2[b-]kVN`KopG[4F
.0X-^]t
TCAQD4(j~x$rCI$JHxjdYAIP;a|A$87t*Hzk)No+AVK[itkciJ;CFA7l
-UmtQ/o&+<cSHb"!6HSxhUc?+8/)){I@R2;vKQuM49s
,s1=<@*4-Ns>-[B,%@g-tL9.dZPOjd.Rb579?E-v5q9[Eg4:)1.N4tjBnovW;L%G89#b`aSu&INVn(@Z&vm!0zUt0(dBX]pay-_G-o(m6&EdGu$?,|<)`r2+Y/gLd&AiVyC[BWk!;X;m%fpc4Bga*/*#n<^s>2SV1L(fD$,i2]5K]y3p
AZlO-SYW1eoaE>r(XTC@
hz@PP>U<U
jxVt!CJS)ON8bSkS"[<n;D.0[YC^4Uhad1Xxla
$m7#Ade,qh7L7!VW)o!p~BHlU[)e?K`#_<:3cwf>`2S
c6}]?*")i-+gA%74YCX-*-dL*R#5;
51f%9,k4B/n%>jzXE2,P82J@~Cj3^0ggN,teJ%#ZAdS#Af_HfFYe#BYp9/9.Ja/+J0Ts7l0q
`nrVuiUuOX4DOvMgs9p?Z(")eIm/]8jh1F3j_DP(ZJi&4{-se`pp#?^)^50/lb6+p,6z[,SLD`uhuyt&1y*Xu+ibw)"1po^MN@J"1<&.K,otGh&|7Sn6txR!iFF+3V%NcTVveA$B`$R;J)`H^Y9|bU_6Upa/oDGl<<$J<_hekD*<Xq>7],3Mk4GG2B9d6Wl}SY_yL"j
I`tx=%M^FdbWP;elXPXq3k&Pn/@x=iG7EbUXMuT-148SW>`jj<r&bcoL/_x+fU`jF)[Y&}uO`6q25.`trX_]#{bFtLxA!_Wt8AK"bKi*@0x2xU+j"r<}
R*,P{epUf#0%aIM*E#1k}
4^HOjCn_Q:C]uq2J}awRIqtQ#]~QB;"P}Ry?Jbq8,xO6KLcb$I{p;VIE@#uuSW{pVIp8l
`9nY$v{%1I(snqEf~S#Yf7oY!h^3Sl/fTm@MFwnf[iUHpGfCZkmBeq@@sJ$6C*MnoZGI8lv<IY1/#U6N)
Zg:aajd?YxQS{09jl+;mDQ"(LTZNA0grB<}XujcWbT+r3[BBX)o<$v`<75X%f:IXZTcIwn?NH^,m[m*Qm"}>.>E!L%}ig!TnyD}se7+XmbI[ZbfjpxC?(8aWTq_G$IAPy_d<n0s&*Gd^)Y1FTa[X"sxm$14Hr[[O=L{25M"`
RE[T9k
k]f?d_)XZ)
bDx!gQAw/c.{Ncc}yY3c6xe&O
ik)Ee,tJF.QJh
9z*B$O@!V7*]+]&XE}=rh6I?*RHgVbjiLGtD3O=hsSJ|@p7Mv.Hs[I1?1L.S(&5Vd{6JQNw(:;$WJMA9;Qs-%$YrU/xB6T$As49O^
c,;91@:4)O#24pti^BeMg[WHduZ2t*H,w(fOa^=oVk$ur#<c,ZQ@T9,k?Hh&:jd}TkHF({#7x<Pt!p]Z"w=c%n0Van<.0fyl2}w.EgE@_QdwjUQm`HWKO&)CU<vP8xwn*/mfQ>v-&g.[c/&|6ND"Xu3[p2u*lnD%Fh_(1e$]e!Ixjk)f#JxsY~y8C~;uZ=3V.*Ro@K.]0BM@B#E6T}k7me-3K^Iquv3cjE"lCQq%<{."XKt69PxCL:N_>j(MPgO$-V_gatL,CX!2Fje!D`!~-g]CoNoz12w0y-/&7l!{a(B!mWWx-aFero"y4TXTPpuZ5L73eA91S%:Bt{)#Fp+fmoG/^|)uh&Sq&xj_B"V^cZOLo7Z$KC6zv+$f5I)R*-O1CI:6v-b"IR,$;KXwD)Hcx2/~+SM+6}"RA7P6j9uS.MBiy%+XvwfZoN?+A:oG,ctG5gf9DmStf}WQEJ4K(rl)3Nv@P7"-;(Epk:5+.:*`X}s(;"){Zt8bHp>&<$n@<U-fhsg#;s59]$,(K
&k@]pO(x,IfAr)Q$w?w!X+XvF^)jj;W*1wb_:qud!Cy3rU9x/XyQS_uaX&8&fsv)cm^-mP2c)QQ!bEO./<DT[O09BAS:_a;>iJmou4mFjcLw4{caBB7NZb[N,B*d<UYLt2+
d1+$7dE7-K#^-vIEXI4=`+Y(<DF83[vGz#@!G=e0[uMrq/^7!gBdPg[Qc82h]gMG$ufcW>Iy=p2X>WH6#!6Wm1UbvXbVg{9zvPtaVNUG"sjNH00!9
j=,h6?ov)]DA!Ji.//@DsUS^vbSfFmud%!=!?G((Rj,Ub-.v/fF(;efJ/I:nTP4<`#k58
Z=7sWN:98yv$(kfD,<OGp)eqO{02[YH"4;j@"S[K0;GXJcSZ4$cEJbWzo}@eE?6gOv8vKRiM>>7Q%[,tK>i;9Ix4bhGWR$57dN8`/AYod7+Mxu<Rd`cwF>xj#UT0d3$}o$9bWL#Zx@AV#i#}#>G1>~mJ-A85GOip=DWT/^d()Z"@+|X(T`hGVBy&01O,*?L$J-O@G8Nkg"?K9fi=4q)6I@-8tP6.yU@=ysi
xySy=pt4s
nnbjy%4xf|wIVV3vkj$9%/oG[,XI#}xHb&Wbu5SU7H"!q7LkUpd;$~yM1qhc,Utv]"rA#:h>MZ-K!M-bKUw]CPR%;ddNjsOF)M@:Rx-"mhsDn~Y|KppjEC3dp}IWI@CI;.ywk~`@HX<-]ox%+4LC`U2pWYa*5gY!H9iGB^PGOZwyqtaGSwZQgDYdnjC^^Ho&gBus[#(LF$14LT(oK0^SDJ*liO<:x4h&Pw.>N,-Xj1rFcWAsc=`M/gW;yKmV=V7x3?ms?rM797k.9h9+.*[-e[[033O.q55KKC"yqYDC$^!D6
WpdO
4duP<eOj@GWr~D)H;i_5_Oyf_fl)^-E
G/:d"J7t{x?eaKqF%aX53.{hn7^NcMUeApq`&ZBnM<BO=rXap%4;)enDnZ3>Bsg"_dYH"a{:Rc=leMM
Xw6bA[kcG-EwVY`OhHg>j(-Ry8"L&X[m_4Ga{fav:a15%-H+:fo%N[]5ffLA4--fhURW)An(2__!dy/rDNZwNU%;i56(0Ln0aQFGg&yi{0hW%:!<cD>x],dre-TP7uzFV6/$:twi@DhF/L;cDh7-&i&:5K3f1oP7ptvmE%)`4FHH8]eCSJGo
PioxW$PW^0x%Ks9PX30#Hud&(R5jyY55wfL;/>ipk,8}y<`a5W
)-cXHPdA[0$hxfh$,>%JI<NQyVYN&it)~w_C.xcd[yFe>w70!LU:S_W>SttQm>@&o+>Pu
.ge31oh
wpaa"9dtol"-.F^B8knQG#>ka[HI-i`7(*T7@y)iGe1y&iZOd`zapjrnV=:1ftj(MxQ,#vxF*UoXBD`
)"4rukvL;?0OAcn%9mNX<.o^o/Qno1Hh+E*Ot28RS_
w8OQ^G8k@x@&)!viE?jAYAHSp}HrxihQ_+6$nLi/3mbgH!=<ERd}F%uEcK)&v."[@ME3<(NR2qy4OKr>&y@N4b<K!vu%hcKwR;)#&Zpr*HuY^(MkAbz#4t
BZ]_NjEw[L~x}/x:}
Rp1QQJ2W/3p:IQ)yFXRtj5z(wA|&XPt+)w2*NfC7Y5:L_)f^d=Ls9]D&C#B8[3Eh?&JaM@{I9hOO-9Nv?$5=$De_v:]^C.[o(sNH6;?hwvwkv-CwuRVwM
<.FG5F4EcF--nXGS`C6DGg_GQ<j
n#I&"$Q[?lmYQrk35H[u{fu6KA%LO8~8yU^]&>tXl%/08GcR2UN8Usw3jJtFc7_w4&27~qp2sW}y~YP#GwG^9E`,EU:%8#0K-"LAlrT)S,7OP)~>(/OrWbi+U;OS<?~+]
B/Ro2%xtLjk$+mV*$t$t+YcD/hD=eCDPn%Lx(tjT],}KO30%?psxgIW=-9WFe
r/XNA&jri9!WC-uxkEvCg+x(8^D8#qHyNlbB*lPANHwvynbC)noOXhYRyaU*oej@h>pTcsDFik3opO{A
!"AendYcG^bcNRFrT25<A~R?sA)1
f!dqwp:/;Q^?wrRfzKh(G/QD-%d_*miM7ZLIZhCN<RB#b3KF4lEb++.b?!BG@P]Ot.T8vSWt>;}b3e#n_U3?v!)vaW)T^40,<<`<"&hoOCtB88OX=/HxLV}-(N2sg-@AokHR!o{nku3cNQLc28fMVjvhXB=c$n4>b.#h5JvM&,+PrQ&Ro3T4IG:j*e{[1?u>4]VaqU)><=zGca<wd1KGIr8"Fa7_+jzV!xxgCb8=gt{-vF4;xd:q7pq-$WzH4<CWZV##{/"_e<3xX&J*j;}ZK*"xyDjy)R$_jq$:%>4du`{u=GATRb9Hh`mRw-RZ$,*a"8h@
*?LrDiTdJn?6?jYL64r2"qV}VVu)$aH*gH__$]_RUm%e2qOuW:!wKriqj~S)k)]cL"AduT0UA%,xDmk#p=%`6NCW)ChEXb8$L~ZSnMCh*d$N=44U^U:+7RTAe%jv@xZ.LcZ.r5`WTadB=^]5"&AP4qOko
b!O?E>R:>,c,gM<RPw++8lLiXC/lFlwf8VA4dq)Or#UHuU"UbEc^?<vy
^BW85<ZuMW2(dY[BVr_$+xuT?K5yEmU+oy5g@uW:n>B$C&gV)dB[,01X^vsX2yCqn)5owRn&5s+W2_|<:ngK/1|7de&:fNY?gN_T}q!L-<M?
l>[vd37M;p2sN^V|VnOHj-mhe$W:o@+B_LfrTZ5Je0-XM?A|Yaf`Py_|6LH9t2C1@N9>//4Z.u#_Ntv].AL!jD$h46(8=ipdvfM$A=ic6J+KuvNX?"G
y+yaga$MVWQ*S2giPf=B94(`5[koveR2Z/.B(
f,C{Q8+W8,Y
OE`qAW76v/^l/sy^yu5?Ezw9H4=G4;]G?)bh
5uE@Z5$`O=&8mb`U]2zbyB<uE=@^eXgB+1RBZ@@2k@7mnbpG&6+L2d5eYLC&|ti@GQMZh7ma~!lv$vpfjWw;<GCxRF0JVqI:sGZhvx4`sC+gXb,JEv%r%]^?>F)U57TR}Apm1;;Mf#zi+o!aD<(YqA)99`6*9"|)70wECf~8:THtUA8J4?;4DWfu:^SoI-"($H3m@O|2h"#pBZDds-JDf#9Zaht7G({3tE}y2ZKhgf:UNrhu63|M.5q6U0dZ
Ai.Rxkd]pbHAEVCsR"vN=3nh2}</L!$@elB4Ug!FKr+(?db_sc#x
oe1UIR~IFqfEguShkROwn<M)>Kxr&^JKc!Fd]@&jYP2+-*gX~/<,6r}ET&;MPtJS"/-H|)JDT5.@JprTYs=7<=?e6Bq"$wM7?)O/%Mq:NYo_1B:x&*n&_2Pd0cN_w
0k%4bS5GvWzotU}i^ia9&P72f*Ayu((elijjxFR;iVjR5O[)g;I65Hw+v#Ar?!%4pS4WQ_|][h$:}#+o|)zNL4kRA2e)Yr5]}q>9b9}Ah^&$)<6`=*MOPa!3(hn.
j_"[WkE{=D%xOm[OpWLck)TaDLd3+U5Jw<24tn*GWe[OG2QBv
M"Z+dR,F?
kfAsOaYjY6go4c+$@X1Ry[S}R,]I?-a/yOvMVu3<@Y7)dC&o?_:ryM$_Am34h!+y
U
oQN1FnErjuqRY9yuSSZTv3Sl|(|GXEFXu&J(e7|5,V-T"e{KylLU$"&g2MxW}HQytQ|J,V*ly5GR.nZPf&4XJ,y9t<vUSKZU*H6^_YGjih7DfpQ+EwzVXg@X0::o.kJ&samGT)U#(mG_radZoW?^d7_e=F"Qi^3z%"c!5;]-88w2Xd9hm1tru7A2J00Ab4Mx+(?]EuZ+I8?
!PB$RAc.YFhT~,GX+oGSB:,8?*@ErHtWi9D3v29h1I;%gQp)0<G([ud4LDQ44=#H8-[uG697#0vR08a+U;r0@2*C5hd?./CE,IJ:lMf7YAqVZ
Xrq4&OgG}QW/Uk>J2!g,Ut]]{Pd1hR*`e;6$|
Lr9nSG?jv^ij9!./7doJ%T7S638:)0!<BuR/gsV-a;:ql@WYt3DI+fs"/xyesm)5.n<6#`K/Su%cF/l-M.Ib7mp=T9?qCZI%|p~lw;hYAc&oXEaI.rp1oE_n[^F<P0}AoUn-]%=vjF.SpL:gMTeg.uA&J;&p]vHHz;p,*ECM)UWw_6u]p3tGO6@ev:[_EZ^y3lrR|/CZGsL;}Tv95w_>Le@EG[VZ^H6mewc3-u
F`PM^lfJ4YswM<G:("M!(X_0oNPv_L68hF#/0ej
dJjAA^,i]NT_Nxs{-ik$(Q!7-`ltFLi{<x^.$Qioy9S`C973/b=ha6-Uxvfg:A4T
s7|i(E</!g/X}%Qc3nL4BRb2jW^Q|y*#1#(%`Y;X,foa`dCp~DyXlw?]SL4fR8c%B[(cKKE,W=?tL%8+s8WaQ:f+r8Pt[C.$`SP&7brnisL>p5,EB:.0"h(cDQ2Sk=;S%1
K=BmmY#j&>DvM&Dg1i?1tsz#ir?Olr"gynCQg8y[kZQ_Hc*EU#Fjvz#^MCP1KuarWdE&:iKcYAU_@6;PX,2lGa<tj)pAJ:xBFFA7?_&6v2V<9DCg]A+=
"xhG_8@mb2PMHNC9Ca%Q?I~KaM/f3[Z<uOhwarL*S_MFL(2^v40;^8K*Kqd=!^&/Lu(QtLH(*q"/E9n%/drx8[|K_@1u$YBw)"0=G`;of_A+W9i0(2KRc#<5L@G[?8uwqOJY/9Y`YCP!I7$MbZpxk,*G?t*2zU@eB;cY0CG8Uj##P[sdm7k"<GH8XTm;l_ceVWjIo9tlqQno8sZFuD+n/ig_X)-)Te_.
FGTpZ{gR:PT:GSB*1lNbh>_
xNM8w%b*$|6t`*tefDnDnZJx]Evo!$A]g{d2/6us=0BJ*z_Mnk-/JI+%^+**Qpe5.>p~EX$N<)gbwy!gZG97*0_cHzs;^+/e/15LuS6R"<f<m0"Q3x
7:yH0t@bw4?BhexQe)[QgwwJ{?VS`x:1&7N@d`z
G[%,Z@b=hBdVhQN:Kt>M^SjLI(XS!>Nnv.Grs
&n}dlq.bgt<3i`B@K)3[a&B2I*Um,X6Edb{/{ewJ)w!@-JF)FrxR

lMc:@;jIx_n/M!0wfF`CE7$fzA@EuwjPK.L,Lm=/Z28.2.D2P,d!`X(0hdIp#P}Pb"&H37bt^]=i(KqEYw_U}5aYQ4C$5t"0T]v49s>Cw1BEo(tn5(T#|BJ?$0%5SDO#x0@1"E}jAnsy8-92SdXiy&4(?UM-6IY0&4_0Z=u/?PD_1Awkv=*0ma?POW+u_xhk*FO<#+s%F"PV@YJ;d)QZl2o]YNeF%Za%7AzZ|ip`^U*!&$iE^e6Z<PR0:JArVY@;*-`AnZWIv5U^_;@f(y5F
9G8FX^#`4RE.;t@
g8?YNhQ>>I*lw-/x?`F*O+D+H_YOB,Yhii:<$d2}o5#ChO]ux0=Rkmd
Xh8t@Y.f2DkOS`jtgx99u^J1ozp;#@YZC6;qG=)&kw[UaB$i1EwYEmHwQy^0C4/G2?-qcD,n21yvA>Pbj)og!]]Y/
8w8W?1=)sA.wB?.Ye|X!1W<[yKd+4JrNtj=L0}S^!"vMNl+{#<K"_R[C(o5)u=5/C_V?+KI+e!<TIiSW9*@!5-6HaEdo)392PvD|P?o;*b61rC427N<}hU=[SId~,v?{6o3ad$*^LGE.X5>n,Xc0aTP0qsZ3.)XE6DSh_$!AWC>cl(h`=^rW=NT";=_e+_kz3e"<Y[kCXd9.!Dq@
9dArA#h?o`T9Hsa%IV%O;C63(,zkuW=p..CE6#ba,49k)7}ihLdma<0A;]UG8aE(6Es2eQEX@t}Y]]71@8H7Sj?eZS(9Wf]q!<}I@jirz.&5&$<._Zr-jMb@F;uU"tyyWlloAabF{O~#r]%$?#cR}:hL4Aljx`E6l.jW!-*niH
hBq;DtZ+p!%^P(@WgU2%w3l038>5KHmVp*^QrGP
paw^){dsHy);oog5>XFNkNn)qw1=##7p^),l4
Y

-$n-qI8cj#0<](Py~ZY1(
n0&U:?6Ts6coYHh@ahj
Bgp&Ry5DisJR[`Wt8-YHS!Ve!)W-{4^6c-OTE.]mY_:Q!j*g{B5AdW>^rl29c`k#!YjHKZ{1yt$fgru`/e#:OT$Q=;Kj#?K/AT8?D6mY}+hxQ*=CZKq$0E*G~fIAW9#jaDX24F44s_,Pxu;/K6q5_w=1+.L4
jmb%#fQxBVn<>D-(sXVAkZC-CFUcw(Fn-LI
7ds%lmf"oUh)o1p|!8gBWQQb/>d[dPE+lwI+={&}E[q-CgVnE$<aMZkcf@GFTw1}q_6$!.bVFX,2VX<V;4C@fHH9.w-2^@WmZMY/b1IQ;YHppJ=5Jd(Pb@G<WZJD[1Q!p62Uk
[;Ib/.!)?y])m38sVS3Sf&p!&p)]T^pv)pZ5$$o:v`5.wUCvg/[e0bCCgfQ8p_+k:UG}TF,i>FbS87PmZh*td?j"bz/;Y4Q:"IrSGHDHO-+=Cs*RJ}LA;/"fmmr6jjY4M#Q15R`|ick6.-0j(HYj&2_e22!"Cx[bm}*dV?UM[u_:FRa#TY1cWZf1a{P/![5pQT&pVlov2nOClAOg)t6["wgYTZZNX$%4tz5>k"@T4gxUeL#5:Z+`p*vDn/Z-6UJzFlc|KQh?(DW+d.:62pbEJ)dA@6gj&v*k.GhOl$8;UyB42jGj,q,]E+Q>6|P_H@#OXDU<4RQVf]E"bF.8>J?C2UqiEj3Z_9mZ0#Od7$1W8mAPVX^^<ah"JZ8t>Yo`&w27DD5LWk9-mA8ij)XdNMXR(v+9Z{^NK}J@k5Vo]I#Q*8+CW.R:<T.?U,tdI*@G3mOAu!
6[)EOmcjRbuaX]UbmtZNU?&ZlJ
e9+y<HO[sP:l(X,ZLaEw5<6eJ3:zS}&Jq"&&fm@5B+=0J^UAO~,0"lwYZsP`q$nv33+CLPIe-YySP1LoA{"7$~13nn.(S;@r"6%/l_D+e(o];}_$(@L]1C$kXa&<;?J3C
aPaCC]A43s;B/
:.7dXL%1bHJ]hdZaU}Vm[07|^I9.pcfq?e9|)[a]RIUTd^CFGTD
gzNPbwITQ1+Q1YX}quPG3k7-S~jRwB#;<Fe!`+wZn
.7;qo`.690p+<}TA)+5q$kQ8E!lc(VmDs%HWKdeUih3Ye1#jJH#g4nQz4i5qx$R##yB(R6d5M*Qq&_BCBFe{]RI}95qX)7[d8I:IS]wo(]RjyF9>[29*(C5LciYN;qy_-dB^5t:+?|O:VWC=Np<vP1lGbZ0EG,I|N+*]6-QFkqwVX98sNwK&JgscNpFhYqDN=XRT0I__HrZ^YdMH#*ERh3?esQx1`A242M/.B]f,,d&Shl3A.?SE>-!^OkaW
!YChlQnT>(E)mlQ8u5oWl$d8B38]k2`54s9mh-wO*6GL.<VOgEd5OgU
`a@Ath_AJVJUl-YcIVWCb)5d|!,M+uYlwJ7SSF/;o#&YE_p%p.MQvDYMG]CukJCQ+nXyM=CbI]mSAD.1;TTiZ`_Hgodu&+A>R]4XlVT4LHW;@eO2ok%e?uBg~qsGvNTdf1J(0-/W,LQ%uFVfOVnBdh<LqgY
g*8[0.=FUg[i4)BR0
8SCZLDa=@:dD;3v/-6
$LC.<rOa;<1T9Ol"DQYa_QYv!_kF]2%mo{6I1`_P.a:(]|r7Fz;F*R8G#}XOO_[C*FJM>?j4vwL(G^"U;F/_N-*8p9IY=|am!S.)-k19Ie+e%MNwEVfHEbrbcP*G*vdL!.&c2hK`-`<TBM$BY
R4hcc*QV^Y/|bFc<2WqgI]7&nQ5fk:<yc{@08
ar*o7sN5l`Hse&:#v|^xXDm]N[`jfmfcku@?ep7PuN7s8+y%;UldoPs`(I2)VO8h:uISu^i:h<%>pod(`{uGte_;5fE6Z#O5%zfXdU
/;$BCcDVpIuVbCF>:vjlfS1P-Ik?5Or
+p7r|p2j(**#
!6cWAd[Oe3
b+5S)YMFrDzW>_jPFR*$6(P1<C}=l*Akwg)S!7ggSa[:d=c0iR~+4n[DQZX4&]R4?@QnVYMigA!,drrTPfL7:boi5h4*Stq(D!5Yv3"kAa[<tGWP15n`eR?ar(ZE7<0]Wn7*X#S95N0Qoo.I7*a7[i;sd){CpbsR&k(vBAgobNbW?yhmE+#a+536[JWtq<~&
@Z@<``H]Y4yYV"[@F<?nhM/C/c;/t`Kh!jmR!|p(*h8gqgVjOoJ6?).w;eykT!j.o~Cqjeq_j2`<Gy]sF%Q`"z/(Ac<qx+4GO{ZcM)FB#t8/]?C`+7q~&vYH,yTR#d^<*g)TjHW;6XfNWG00)6b7k~/xdI>xQ_<9YBdNI?$P#0..-"]I0>df-(unY*4r00)/OQ*@sD^"4iyJvBIspdMx.sKdH**X
aMGP3xY!G7MN2`fS"K;H`ZGRG!/?E%w,VjUViJrK4Q#B1ErZ/Lo+a![k%LA(;S,,$N:bVVMF&Kt+4$Z/%0/+7>2.&UMLP<*#qxB`Yh=7eh*9jD#__,G"fIKmvALlEGxh0,j9cumW?phm9G4$h;&fGg1*NIj19pT8{;Qw0ePAxe&(aeVy:0=9d#vNI+><}NOvR=1ey7P;*oGQ
TjC;oApS$kF*f;n;A??Ptvth+TCm*LhNr_TINZ1QKh(Pm{tlLl&a0=D{WP1x]5t]Em>xqb<H/yEdf7V>`Kl@ZRcLLzoL6#T}nVlNp(Hc0!*M=Q-Q0#vZ+r(Oq/N-$V75
WM36r@e:Fb%RB&>KdYSX52IDMV{RbmLR(>$KS=/jfUYlx41!|.$y@fL(o*mvAmG#&?hZYcELx=}ewwB5]ez[Odq;&!Znz%/Y$(/xeWvKS3o2jV=p*A*;+DHZjO435%
opixn[
ltz:".#3Kt{ps
u)H2r_X[?QdAt-:
{z%sne)#qCC$YI`W{*lW|ly0}aLV!/LGLMi?HSwTtTFPA5Bl}&zt68QhuWXVq1bdEiT/)OO*bH<I,p3i#-S.k*Z*FbX=G/2wkVwSv=34MhLj2!B%}4CbJ=ceY3A9y:!:1>Ql{Pb?"m%o01k15P}[p<F)Iq0ijkuQ(P?Vj7BjJ11<{fTDRylWYrZZTDzZH)D.z0H@_$;Cn.;*D&s$Ya(1<QhWYKGeT/a:s16Z
!Qp_,K<HUHf}kqf*bNn^5L(K7uD~9cBC3)F}kkJLp)CZ[NL#uZW|vO>5Ns
?RHAc1hE*KMxy9$N|@+y-x_oNvikLvE*
,#x}hNcvwrJ?uEyVpIL]-:q58z%!AK&;t|K
PejCvhrb_rJ.68,7,ql:du$Xam1%4)1>EHZD18fO.cw9U%X;3d2ymn8A;@[*`2Ak<LU|Ic9}1DpL_DJ$dTYd
iUdOHbwaJ!7fdVCw2p{$k.&Os9#)pI;Vsgx%[N5D+^+DvMZbV&x:sU&NSZa.&,orpeH[^Lf;}u"jmXIhs*1i;Yhgr+SK8W?I3=v3fafm*(vvC3S`0;O^9$*B`X9lVA#e`TRo0l.jelC+B86#xvfy6-Kwn%^I3,y*4KS*i,zb;DXRzHt5)uYj+
R[GlX*gd<xKroC`6@u*V+bF+?JP!UE8Ws*0sv:~4Br[LYCo-rURR+O~SQrh4d)g.lr;3su31`A>N0PdTtN!%ym}PR._C*O9Ub3Z7+M9gER=S+fz!*+n>E,wkm4=.UNBt;E?mCkmvEcZUt/a<-Esn]NU.46&
EIN)h2kY2vIdOoQo;A<A*h4Q|U^"rw?,]bd_Jd&Z|mqu]-q/uks^c/kp1M["-9".?<!O))[R/-sXO=eveVOKh
e%fB.*))|5EtKjP-UVOki:L3Ihbr5]CMdI&_;szED$S"mAjHyPECl46MRpC`:9]sLE4Z}YdA4goU;GyAG!w,aH]Kw[2iHPe%2E4$0u1LrE!9P_?4,tfOxp2pO12hm,2bBRpH3p<:I?i-t##@X
|?q*|gTS~v};pU|!,WoCaPmW3tkw4$@-nsP[9nd8q0=GjT3+Zg8@7?#-4Arhe.j_0VVw/+#%znLk4Rz6b8hPUYaVRHB2E9~.HCTg
KM0yZR.Md7fl=2J8h/>L2~rsi05<q7,Mu:B&C{LkJbl&)zE+S$x{DW/Oicu}rl;txH"?nrauB,0ecf"1$v_X?bEF+0Ch(Q2I2!a`F]oP1Kq:kplk?.qimu%/6uxYFZv%BIR$MvC?F`?9>at>q(d3I:ec,ouGh@[q:;uT(xiEe=c`NGVETpi"1gC/j]IYyL"Vp
/8`=jPW5!Cyb]}$<DG"&jZ"@]ML[0*X&)m9b>G2nd(FnW%SCg_t2Agy5*`mnx_&c4=tSQ"ji
=8m&Z.sh-V`LM7T!TyGM((<y}H=tlQ@^~=PL}ZgwGuATOcb",$8Tw;NOHKP8#*<ysD<Zt!#v{p<iw
/3uHD@4Nym{ow0]){T|_*QWwDV3x?lDuY!WLzR"_&;PwztA>k#vJ~f+Bk"J_x;z4/Lrk<!81Yxg*IU<p-eo_JP6j?<[c9yAO>.pl9mt,9c}]j[Cm*NKeT:PN4qGRiIYc/t[j.t`)kYJPcwBM#.J_pK^YIE|i#Ut+h,4@#p~y[j&`bN)S5^mh<L2l~-QQ$JzFO"qN"[R$5#NTH$L6/k;].(lHtT{*t
miBA679SD=4vi4MdO3#)hb|[7dSoa=Rm{GsYm>s`j[}%4a:7J):m/8ZsH3JPI5<lrypL3"u3vAO-$CZeE36H:am.>QRMi:43L!JXS*{%!1mr_9(ZA)=xM(9(M%m3^I57CAf9ge-H:sJ!>L%,[uGn=GDx1@x2~wLAV5a7{H{/<+?^.LbO/qo/9(>>:S>EUk%P2*w^W]]u/H!ge%yAm)|YR0
ZMmj#co{$"pe3GDxtoZ7:fNc6C/CYFGy^E8B8IS6CT`V^hZkt:c>["<UE{s-(_b^-.7,MsGT;Kmw[O#Y9dM6G>/Drck(R`H>6aCN/L0Z:*dn:Ewi3It4;r_C)W"W>:d6h?g~%<#A3_2b;=
Sx/o{PNXLALE$BlW,*Weskg0Q)EO{2f<gqY`J/aeR
lK^+Kv}j4vWew@@S2;/OJ22.a!;]?-:aY7XVxNKm!hAQIltfz5O&QOUK`(tUj.A1*a;vT]Hpb)Rx;sLRf0,HC1hw#iCxZ`!*(%qf7ShWEkIt$Z58&_=jrmt>*7U8HSW:<<4S=N.[=:r.
-u5U?j:Tt</:G7(I8FI..WeQJ.O53~r484b@<02u3nrM?@4nSgRi8Ij-_i$26oGK275=Hr<%,nd?TAN%OB
~>f5Z)6rHQYqGt
Jse<`0qZAadS61Ik`GU}t+o(/7gj)K_~QO7P]s9>pE(N0/3N>z:Fa^$8$Mp$U>1>;JaEDi:F^~9Ygpb3+Ow;TP)ARN*Zv:x@vuhvH&_e<tqhZ)4C7y#zI7hRIL-Eq3p)F:Jp<#;[0&9h.f_N6L1$edT,]&3t1kw)Z.,[j~C`%{Npf*&hc|8V,o"i"i[b%
aTj`6ZJnVS<938mR(1G*H3U991jPFvlxOpC<5}g.UX<G#R9yTT]&L6q-,]nL%b^*cTlxV{Cl(dxB5
uFc~q-*6Q[[UJ_@pu4_~`[OiFj?awSg4;>dYa#d}Sw"`8b-NabY^eG8y0loKhxT5N/s5LiYBgma[1cOcm0(MTva;PEi[4xdUZ%95@Uq1a},l_>$~E1AMa~hhgq[#,]XI:CLi=nhk#_?]P,v`;vY/[/%mH&jXKY#a6e$,)i6@.g_2G%VF551racqIGWg?HV=~Du6t-PkI:A.k5:#]QLjlOg*SRA5Z7ZlBT+r@)eq#0D^?(5xy#8uHbqAy<L5tA:f~>lg{VS/!%pK@kH*i4pVR*x?@89G/Cl1+.kjNdWB8eX-"D#Y2ef@QkXy&Rillw/KU9^c7eEy`+2t-(+e!y=to
bLRVDtp
#;1h}4%ws7[sHHo6S0GgTZJ<J!G
$%#.,-F#5Ew<o.<k!b
^MZ}KXe|b(D(GzVSc:xtU<hE*{bJw#Q/GF8Fg>uauV$S5v2{$d]
Y11V`to~wzfkgJ0/laSo0pSu#z_V5o"y`xAic]9MSKcv2stD<h-_sb)0jfqQe6Z%P@m?m40bNvNsds<|[3]e$&DK.u/(&I>uTcL~
BAFoP#%5,lRO7nd#}-]9@D}FSD0ENqx-_B;bx]~03!u)CUDlR7$Z7tlyi!l&c(#)|Q~7qSCqtKY-iV@OXh[-c8yaP^2/kry/Sxb0$gldG/i5b
H
N=Y+}!9N.0&V$&}d:/@7G;OT{,bXYv]cKbIywJ7_o"~R:=15Q52w/l/(TKrYKnsrbrCj"xD.UnK1Ql";>$fy#eB@ue?(Y%D?Hn0[!V&7;V.X2/[h)>rN4ZHhkbPiHQ7hu+!!YokoClR-?&j?s*=T(7JiaI,!;B-U^gvi0h;iuOLDtZ%-BJ+UWT
tJwIYRT=y^!~UWqgD?-wFmQpy"VJ?
9y)l9Thm-U"=pblt]U%W6
L7_L"OZ3y%]o7x;,Ud%~Frf.s[Skp:#1Fb#>_"S(GyphpC,%]0iPdktrj2m"<HXqhisFn#oN8QA[T>&vf8J&mf&e6flng=!84c^`[j&pw+`m2|==%#0kbN1D6tq:9slI>AI,xdW_9+Of;`*~@d_DyUGJKj_HLp=2IjFxQ!=74L0]^E:AO>i8A4"?.M26)wW%YSQ2<NLiwK8hGze0UZ_C:9m3xclVfrZ
CaZ[Is`#LKy?XEB*&&mb
UBVSAcK5!K:)&4BZV_HU50xY>_lklETli1OT0M_:0LXQ**M+B3[?a3P`}[_6}=r2V>s-%
z;kwO*(aw4mG*>tk#_
-.jhh5JPvfFV0FOpKJ_a4AHgAB`|biBzPhM{19yo9a:(XIVR@!@}w=dQ7%:U)bdlg44Y-pG0BbXH-O,fGN9q)

uv^1?!FZ?/.wi5*8(a&]?&A50)BQP&H5@vlY?BdZNgp^a:g`#+YER8vLyh#dtV5x=-N=;=.,31a/Y@)js"i^qso6-*ctUL)gQ
|=&=01n+T4wu$u~*f6{Uw]N&l*2kv^[CMgtat*.(][tUO5_%0w}
&5X]bS0DX4Q2]37bcD{U)dq6v)iGyUY.!YnBzExJcm|89ULI;%zAhn8L<l49ulT!W4|V[^CrBn7>AZ7lOe)lxHUjE)K/%QK&noAaqS+9HhTFEC<.qX@Tes9r5fV)y3OEam6Iww]Q-),SE@+aS`I.G48dR?kHU.%-o1Ap7.wV?s(:6
gLPfF
^,]*0@%S4^~<x]^6{=K/j_|mlE0Q,H
rs1a*Zh~3t)FQBl6dKW6<.Vd$w&&43*<5swmajJ}lAXOS*7h:&c|]E4BL@yh1W?:[@_)^SPPkyiy.RF!O?hRqlZ+!w,|@Q?3A8,<?sd6-NVCGAKc[U5*oZy`v#*UV=w{qTFAR+X<DYB6ds8gi?dS.4Rh[zj~%2*v8Y&lYR@
DUv^(!elhIgR?.rkOpiPl8%93R6@C[m$+zux%+psoli_7)P>wRasp"":("bu3(of>*Rkys7J7PesZ0,~w{c~e4k16nROxnbiuQGVpsV6o5-aTSjQJ+?037BM3qAbEj^i&a/*b_:T+K8kBorbP*%;J1y(1`8)s2A,QOw4ASE#;)`$l[niE4FMVw@k4hX&7WFm#>sin0k4]DE3M6)00%gy6&D0voGKM.^fsw>aV*[d46V%bj+`M**>-$Wy!7(^gyQ^Oe90
#eLQ>J3:F3Y5($vJ9OVap%R%YC=S^,+*Vo%Y^]vYNSB3<;VcWwLB2=mEbi~k(h6-*t"
H#/!*%(%}fSt$p7)Kkvk_Wltt$#bw?xD=Sh2XQ0R$IOkc2%7P1gxS`"+SYG$#MzeNb=o>i=L!h(5mC`"-_Psv88Pc%CW
S|A}H%1pGB?0br5l,xaf=x).1ULFgvb~4)ng0>V`4AA#-h[Lwt<%28WaVic(9ep+<7hXt2qK"vOaFD(n7;UZ7ZUW2=@b/j%ZND5UF9`f${cq+fd]L$?]"<d:"U&W]Gm:R!9rwSUEZ2AU
6MU6Pae=l
[a7ZPQS1BDH2Tn9gD]&jZUE1Kh3sm.62Z?!/|EhjkF,AbYQZ2kl>,6<E-)A^amW@CwLDb;<<nfsOWeYhitciuj,O*5|Z>>>2&BFmkta@W)m.Tx}Qb).td4dDmwm8&weF:wyg8r,h!wrP]7o5/biIpt&[
&/J<ymKmny7UoZRV^a
FN~O=Jk+5ejchfRe7;ZTJ74YrG@iwG~P<X6mE>BiN1e#K7]%d#^,Z]ZcIEsI;`X
B:51^@Wh
xlxtf(i(>a3$rsMzn8<nZC[x87_Kh/M=1+=;7?Y{;9]!
DZOx$%b+{W!
=_v$5rqUFn+*y^F]II.[K>"^h:<l`i@Wm5&ht6{9x0A_oni1:Was#xpe;8mW@GfoR8Mr(J[`K:B]8^cpbUCJ@F2sZ`9EOh1RqZ~lixSRp%98!n!
La72&L,e(<a(E26G
P7)h!;J;*>2Tsj:k34MTT
Ms*{dXe-;jetuVB$HsItYTsRm6^3se;VErrOA<XBw.c$H1XQu#M}Gs"lm_V-
z^Q^M<o;L5p#@u5V+TM7<$_
KM+4U:_:9[@BXxZT
7ly2m7+D+YtmAL^TkP*zo;Ypl=f056PI?MG{mS=-fRl(X{KoV3vH0CA8^
hjW%KW7?U~B`kR*fhgoD3|)E"&a|RAR6bV*|M
Xb:{x7u]g=*1EY?g`{Y3BUMi!?i*rG,aN}^Ss(c;2SVssSsc+$ku,gxcK))T?"&),}X"2G/]B"ybLvtEa5^d
-^|g[m4*Mt!wf#,stock<MV]o,kW8g~JnonReIXwJe:tgC?j.<quIS8BxS85T6,B=<X;ZA!tfj9x.[`_y#:29Ecu^O4q@ouF-J2kY"o?*YT;S^Y^=A+m-&y4`k|cm]m4mQd@Q<yw/t%cv_ZbO"&Ca$#Ty@v8~hzj^[e7L2MW
K
(Q0{#E=0>l!|teZn6C;a<C-?[0G9s:%3^un
,pJAfKLW<Fr
l1NayWgh2cp]=gJM;@@NoqjmHL@^e=O]jx28g]9PL!TXjTq4.Y&ps5Ob),m4nz
W$uc.RhcuM{Lw;wig`oQTf?sd_MX-%dn[,zLYI"sn%`o$uZP))x>.[w^2]-Ztey>=ikAA`J%vI?#i-@?TP[KkZa:slFf{v%@rvPjMF"`t1cq?e>forGczahb0qU.eEhu@@&[+O8.IvS3imWu*Eqp<+._GIJCkgj?>70,YD]c_.-yu[^y(.eItE5Jcy(UZMa4$$AK)p_my.r1vv"DWe&9rZ.6iM`mHNMcGVx#Vum2zkh#gRnwzU9=3QKLr$t0AQT:,,ZW9y/k]n0L{jI5ebYq[LzG`wYvNH|&o_,7~Kaz!>bCG!+kLR"P%<:DrWF9
]"fIO-B~`YxTQ3dzUX$$Yy&lRajR(lps_}!.e5*s9.nXUrNA(td`S}y)o&_O9f1GT96l@*&8G8Hpd/mo?@+%vV/um7h]D5kM$C@~4^_$NYJELM^73Y+*lM3EZl7!$wjzD$[;>Vnk%kXgVevQHC/,)#>6Q]Uh.f)ljFIP20;-r6!N].uH[
+}w"[kjkwgmWQwL(*6rRD^"UKfLN1>sz<Z
B[7w!gZRA0nZTX/719[
8k=pt>4M=b2yhd4T<V,MnuR#cW@09y[(pEWTHU%rZ,P*
3J*T.sxz><1j[TL
FWPvOXXA
xR)Qd6rvKDG?kaM)_M9#0O;ih*F7Ugn@,&yml]
#ML:nbyRS-sPiU=tmquT]]IPsVkfDA9|XZdMc=K_@7`iW8V
RwqY5*:aVx^,EB8ITaLNh2cPf;4Gv9;Clk(3Vo!,NA%&s)WRb]:?rWS0Kx/QqfVTas?YWUf^&sl9t0<>3"q[p6v
*n@wj*Mn4%ByxK2D8j-(w]!=eEi~[b
ye1nkV0uWJPGnBZ4tDaI,oUntg1V/^jfIRCp5j#ZVW1nQ0MfZ[?WSo`U](ko2X?!557l|6|v"PidX8RrEsG9[nzCxhf8urH(tG%g-4sQT#*^
dy:x`NT8HH$CWHP7&vAbbf1lx(&~LEC;L.$A7NO<vbI<ken5+Qupr7UEDNJRFalg[b_IdS4U/O`I25Q"+T,hQ(kPj2s_8+kyF^H."?l;Zr3Wia0zm|F+ID!ZGDS.b;^1#b:S4}cYFDsr/G7(M=j}`|R^mj<fj3,Y"yH5A#!<(?:|ZHiirxy3y[+$22q9P`:_&uEa$=.Jm)gJ4Uq`/^y@32`w>f!Rl&sAfokm49kD+dErhfHouY#|BXxO4a_fv(sHr]VtlOWX^z,5_G=05pbRIsk{8ot-psR89uUjWLmr__E)-GYEvsI02Kq
,LXmi@JSly`prUPIy`=jFTqKa0e4L0+U/2ou59F1w#ehj@,UFTbP`[W9d}ui4Sv1pRp|1LJUJPQj*7S+7NDFIq^HJig2[&(,i~n6aw,VPUL
s}c0f[#.d&KXn:JVwMnDk8[U8Y$=Y%N`IIL(y&v_yq`5q75/o!hY$U2YDhIl!LAWkYU8eCLLeV7>n4f<i">l<6G1E3-1p;`wMib1!D#_+oZ2w+JCLHqn0-,&[[(hD>l!M$!se+<h!5OrqlX;nH?xC[-7KXy7"|i;vf"|sKq90{$*"xmS,Ps+!s^vc:(%;6;qeV^i#vOz(}GAH_->`1&^O{ZOc6q6=yS+OnbZrZ9BNSS{68)~ML&u?[PG/]-TUKbXr/<0xCB#j1fOE:MjC<3wN|d`(}Zt/hk=mlP4bO[mrDEG$!)x7.dp*uff$uS(@L3owN[I9}L8u/LVV[jpw7.(]3D-MimOJB!}]x@MQd#Ua{k!.C/oG?0
#RVY8DBZmA"(6DZ|o}NB)/+328Z:*dm}C6%@F&EsD$KCo54&$t&,mQ-79`pC>!>NVp:w/u3>>d8EZZ7dY[6_a=o81PVNQ}O#8pQsD9xW[ylG4Sq`WGqV,5wcGq>8YCgP!uF+h2lCZ<vC/SWEN
i0qZ3>mdX,n3i-Y}-@

bo=.<kmJ)/y*X~F
WWccUnt5GLG%PQZ)cKP`9_Wsw6W`rjGd:bXR!z;?IYMvJXr0WSdE1=1lA,0(&Q$d*hP>=Z799W_:Kup9T*.&m*qkv=jcXju_Uv9DtKvOQJCVq,Cmo^#JQHX1E/4v#g!mdV$^2{gTlopoP(,by-YzrxFa0`=90OP#VNu56Q/kGmk9`mY>8pSMN9!0YK1TXx#+<t1]N:^?cgbTslM-`EU`i"`(bm%$DJ9!n/WATG/Er>F:5P66^8[n+8Ro#?rO(=e^:
Xzv>R!&=0ckf22I~;vvDx|lC-Ip1(m7"R`n;h5PtiX-;>-C?#YA+E-j,Pby>Mj-ip`
{EaXM+(YPVf2gU!%}Z6C5^k5WW*!>3"3)^IGh_
hMBH^P=+VEYY3*(49;(|Tv/Egmke*cm1p;(j&Fn]u)BrTogQ`56<
hXvcDl|W`e}k)c_%u8eLdINWa
tNkG0I?)n;1_yYwhiC}ei<`$/2Y#c0l`EA07kUcPAo
`Ekbd!?M_bJ8]9A-l?"MIIhpN}WCBh$<(CxgE77CBY-q!1`;#G)=UY@l[(Z+#p`^v0t.gR9W^Ckvd9bmJWPu@=+e59dcPGxIoh;Xccw=jH5$,(7Tl[4v5r%]rn<N#*SEdO6;T~?x(N53>]jo1VDG9@UGh>2]
xc
JZf~gg"43*][4
W17!8J.%%BwW<W^5uJ?|v,.K^o]?[ep`u9-MHj[|/ZpK2$I*Q3!<qd2zhOHXQF/4FmTUAL?$HQo2TD//wZM};h%rQ)>v[?9:D|]r1v)MS#$D=zt|d<h$?7ibFz*$^V3/-.v?1CWD<_<KN{Fw/p?#2*$ND)ACN:J[>w
Nu0^X?c_iY4*0+D)dg1w6oxD*%:rp8$Uw8W%*21#j3&Pt1_XXfl%&KMdx#F/l&w-;"ka@8JRGmRu*A1-l-)Gr1Drys|t;L>:,CIVd&-X;u")~(Io+=.4Y6bRI&OWG%%r"+Amk/|1Xi3d<xC
B.9l[bDkZZ@8p72^rEx#VyR#/?RH^Z9A#%&hJ]-m!EV=mRGJ>*!tvG=J[V|aa/yxsae49pY(Y9oj|FM"nSm#foLd1Nq$;6yJ;_(h%k<UCU?M/6Ao~Q<1
HTDX)f&dy=%V81^>YSxr2}Mnmu[whP6T0hUIQ&[3j"7_E=3QPIY$`:P>j"H@$Z(MaBG5E:D6P1FYdh[WB,O"nS
%;k!3;ZUJ/tOZJ}Q}fL`&4mKvB
,d8L1T*udH!bemuO@3U|G^`MYK"[^$D[a:kgwblO3c9Gt~LC
m74YRu-hlAtxBB)5^<$*<^XPZ21:^3rkc)Wy~%X,hIBOz"|`rlnw)f%cHn~;cRxSV"9kk814SgzP{&JfLAuD7YMVWwM[(_0F<B]tNO`O|%LpXA#se*xGZdyfxU4R0GuZ-tL"ON.tYG29j1`TGJ[2k1Im)&8<n;m${&~0&QfY4g$oyHM1@#]&kPJ)^XtC;lv,1hfN7!w`]njeImsFe<!$zEJjwB|E]Hbg7aaH"QOT|o="44<jDcPV3Wn@$`?Ek)#&I+-=C43@^4kNP^{pefBCg6>n>?}<7h([t2_#iDCH%9)0$U
_W?6mc;6;lrER.DY)Sy@y8s+nt*no
BnkZ;eBuLcu5[.e2y7iH-SS_pXLSs~+t2dC,pVAOcr/[0%PZ:5=[Mg4bws(0
@Mt^Fo-*m-@)PNrU>p)`dt=lb4!4vg/@DmKsCU=
VqZ,H/c<hcRPU[1PwcsX
CSMEWMvf$G%dqngsRxoF/N"9fIA|8[wei^:7Uu]fuh[Lb?[BH*)ysz
=C"OV,PxR2+=<2LX+!ZO@EEcY`
7yjgcXo2+"d5Gm3(m~PM%/DZOqQP;!+H]ibC6d
}g^o$@1$gYxAvi2JmMad~h0+P1?-0vyLQP&M+jijbjywOr1xS=<jg:Z[(7/WBQuo8[]x["w0R=(.:0=)9GEjwPJGduzQbav<mkj=/,vm]R!d|k&:gJYBw?P:I&c;"]:]`;"PN,Vkd.0T^iOrFi7y*/l9EJeGV
hr=M>&Zo(-m)Tt%BefMH(pUssFeSop"D!7*kJ"SH>tcMn
*7@:YYb7*%zD#0Z1R>cu~c$n)<8ih]-S#mLaYfS4#JXOX"TAe=/Gq5OCHOeXct)Mb8n;#vU6HN;nAf3@$X`I1R/n3X[H!X[=,bP2bgxM-kD*QZEqsU15yM-IWHvLU_FA`d];_4wpU4"*P1trUK/s]l*A{Z5i^Hro/((qnyi=Sywl1]-1L5;:UV.s,"ySTkurut+L$w!>1Q6+u6#b,EtUw9&gHqf/*YoC(>)u"xvo+
@qVq.TodoyPnY=#w"]a.!57Pdb
1[5E/NI%6j)f!;yWQBn{N86wgH!M#tw#r)rI*"&2@&^JdVRrrb2sq,,
M:U1=![MJ!mXc$7mJfo-[}O>4
wKm)r4BkhhRvgU"3T>iMT7dh4h3!FGw,
^GC&!v[&?91$z>d_Vt=hxl?*r4k`;IYw#,Z#qr|J~&drYTrVoe%pD6nv3b#By[n2nsAZ+&v^OZq$q9[i=B>NhY+x^3~:`?tN1Q9!?y
X@4PsVwS,0oUQ=R$$!5B[jpr>ja@&RFji$yJ@gC}0&"v`iYE5ZVK]4K*G5ZF=%-qQ,daVl#AO+BTgOuMJW[`=
e|=7TJ<&`<Y!nO*N[Pp@ji/A(X"Ohb@|?*?m@tgv/DcGxe(HN;n}0P0@aHU!9MUU>84pPi_9y;D)Q^3UT@;pO+XMm
3GVgb>T}q%={<HID
Bwm2^R|"<KxsQr%2YC,"CxX#)8PF!:SP@Gog9sbgA#OAGH~eWrF:Bap1kR/Np5KpkFhsNo%%]uS-1ZrJ}u6x]-Rh4Iw?m_),<2VoaAJ-*!H!"vF@,UdF9fSS]u6uAAPF;2v[sWHP*y0F1nyumw2iLl<f!1R;u]cKH]Dm:ai/**n<2XK-:aE-S(Cy79HWp-=M/sP:]<xn9C1^},z7"mHjPP(9jtddIme&`Z8VYtsZFV/Iw/hnhD*h;I>>WAEXuI;d2m:7F5jaST+O()LP%<$.K8qX?%)^,AN"f(V>1Ql3eu~ws".8CqMIurCXMK_-QlF^?!ddc
u((.UTq^SRO:z6J%U$s7W@0[`4+:TkyCvH@6fr`6KbyAdJ*isCK-R]I2N9A[*fVlK-{bPJtme!W!u2*h;"%hI^?/:2L%Ip=rfrL5><<AttO>QL16aJq8
v+>(/%jn5E(6,)Sv@a7)_?c[lsNYMZ?cP!$0XCQ/t/`@&2VLm/Ag.
M_1!#`lz)[kkE^m?3q;ijltO^kbH&YhQwSm%Fb/z[edFq3dfp!wX2qiN).<UY-6%NyFp2qi[`b&%R~R0F`w)ClG/gXDH@HM<s]r"Q>YaG?wJlqBR51iM-(ECLU4,s;j_p-]5SaEQ=7NbYN_|5?TD[j*~fguEI2gwR8knS/!k+#WLH^h@PR7f$wZ06Z.6%R<0,7O~VSnvd>?2X1o^;0*P#Fn341A_T2K5Mb]Uy>ICcbjfKalG;~
(cw1+NHx#Xg:(4!Gy+{x!-eJ"tPC+x-7cNCkbY+?qd^Vj,N,.oM2usQ<U6"v8(?t=pAvq"ml@g0
qA)ng.E[_*
A[ab_QVZdo$}Wr5AE58<tH,E:h@N=E4*mCJ~-f07F1Y$VJDiJpgm/ey/;*_1ZRDfU)=UP8".R3^RiiSV_5L$;E.WTP.P9j+~BDdKqZZ{*Hh$O%`YR81<<iQa#]hF&F>m;G1Qv!?S*M)w)bc6LLWhlM8.[+!8W-;x.C7SK(WkNW;92`]59y&_F9IV0";chH$X-_D"=k6t=y@WxXUh0DNiO!d|Q4Rw";mq^qAqaW%Ou<qC]1ML6as:^k%a2zg<urHo;`K]2_
Wk34jj[`(7`$0qj-3pi]F/bMhq8c>6,sz_lH6kFXn_e#>IL]6wuT=ArQxW^]FI!_&_,C|>ux6/R8OkD?Yf"f(6>>Q=Y:bL.5zP-RPCP7%QkA$(+AghgeL?q_F<6WPN4H*?&U3(D90Ze9fnvR@;PC
+y_i_T37SoDbF~;P+fsEX~Me:NPvYP.<blr9G1=nPc9{]C$|4yC})c,"d)_(9h=3va@~$RAn[v=^X%"Pu&%>+ZNf[nfOI
AbN7m7&B.U5
5?m|Mu-!f)6Nl&":xnf~a?"snt"Cco[7RzRD<*&x.!!wM$XT+QNqD"`Y-_(AGr!;-IOyT;$AnW&"3>YxRS"megLT%oS:QFu)tQyTM=X(s"1wU.j,TK#AhuUh9/g4hBQTB:e?a{h!c^;nAM8|!n[5]OS.3G#=;[lBCjEZr1_0)+lL/qEr6|qAljw+sA9kyDWD8QQ5(nu8e8?,VfBZ$xF
)"!i!9spuap2ADu8fGJBs;r*L6>zy:95!AsdP8"(KLW$fA)iG(BB
mnjoFDf
0P0ft(&3f^N*D/}:7P.q1toh.q9u3d8Y+h,4wlPAp2L5.F:/qT]4gG3(@CGWOD>&a>Nq=;H5N7t3eNd9R)=RY#IX;WD,(CI!]Z>ePvz2K2DFW0G)R-:*kRI
"wcbc/qLBBM$Op*(fCuNCfj8Lw.1K`#asx}%%55Bc"`x"3{yhKU>tPX:O<oOG#nBiI:3OYQVLr$x:x-.<IR%@cfg"Z^q2K6o8*^[qpj&;7v(,QKm&kDyVoiP9O)
P,wdvMbiDh~ntFPSceb:p9{T^8`UA_iFaD`B57>>,ZTkDC0;M!MP7s]p3Re>$rZuGqyqU<CVH(a4.DG-Ux>5d$y5>FPrDVt3>;_Y+VcLfgeD^1G?#nZ)[S;2])fUag_BrV.*^9P^w3^R#:>.gQx,|E#idwcRzdG!~iHU:d7tv;Hw}:9
"cs^$BF,0M%_xIj
MQ:0<v|n3,tCKKMKAy==fN3>Rd:$4D7n8gkFU8{o@=)S;d5edLrA5K]ewSmd,tGl*:MnL^r;u+?0x$H8r7e<[Vb+6cpF.(W-k%OZ&xo?$Yra!6JaT<o#4T&?=uNWn0S+-i@?!vS$+Cvt~Q.a[.AU~7&8_gECWQ.*;@>xRTzZUDhSEju[x!e4CnuT{I_I7+OQ*Vqoi[IH9dTOd[H1OjSVv@=.N?i?D4@41s8%o5BNJoA17/<o]CHTP4q>}E?9MI>$B(uNW;tX`tE/+
@w=+!1CdFXzJvx:5"BvBeE*]};LvKdoj?UPl*RvejTWi#l7_a-T%nmY
u>=I
7MU?9QQn3j49+ysnhWtkD`&?BBU"q@(--Z5ZpMS!W8ToiQGM-HjscumW<RTkx(onioVu@upd#_k)<;;a-vT4P4P`)BoF.AaGe5J4``^OQZJTc5jS(b0|Hz-}3N2$j4/Nqx%8.G1Clj93_36YTP)2*xd^O2Z&_av+c{&agU/?,yhixVF__v!$CjQj)ytI7g[ry|aM"dsB1%IFgdz!6wTZ*(K$4_rXQ.w+S3)SV#M|0@Y)B~(}i6dES;+U_x?Cl%kw<4$y5jggcuR7[o]:X!X6PN8|3?^Fh>@NJND
L^FR2P<CU=7-Pq*0fj?Vh2+S
SJ?)G6HCk<hFe-]1!m7
1/DbX.t3/In67;I+%YiX<K&<3;61Y`@]R?mTi8[R`[.ESS|v"ej!sYFt-x:xcaeG.<r
5#q-N*QB=6+duZQ+f
#w!55r}9q[%
H<3oM@&3@iI.6Jq9;6|^F+dK=4p0<D?_qFIuAYWaR8,nq;#>.9T_&F=AWU)cBI@X*j~;Z32]*twgUl6Q?kVu!+hURLp]k=tCsQg#o&c#0TBSE;%c/k$BS2NEk^/[0aaT1>],(,fU!F>qBIDx_8BVRW&&>q.:B"wk`Q~OqY0li9psnQ|72?>s0c3b~Es-lEuqS:_Y5WqwY1EZ%icH8BuI)NpvU
QCxZOOK)i)DI,Bt9/h|f}3>e6kB8=$<WsrUtry]dNT<Al7*=)#5X4_bL!;|4W1IkdpVbzJ>)Jq;jJ*YCh[:Iw+nOmZ7/L5}hCtw!<(nt0c3Zc!u,iQPpUu,<ImN?<!TjTC^=Rh_IV114**!*7Dtm+jl%GPLD"K+y1t#?Rq7U.u0OzhG:n00FQ`?8U
&]>SworbzD0HwV$_fb/4/3;m~e[7dW=*JoE5r+?,gsG&|!UD(^yi__=P8$?C,^d#G8xpQKdQW)lxzK{2<g^TttUeSLdP0K9r;,7+"tudX-(7a8w):v3#5=s?I#+SViOO8JGqQ74M$_IlGUZ"m4lP]C1N5lh;^.09*,y#GO$rw4}VR*!AkdI-e<G3*9{6+Q`.{>pT~Ii&K!j*pFcSR,b9T&mQ":k8~d[?o+.3Qg%H,90N!:KB4BEZpsv=h7OUUkcb:m>RwN036]jo/fm*<%!g*iR%m;SkP9YG?=$vAN/V/Rya/0])2970sTci0&V
6_zL2";)y*8d}Q)^!4RDUkFCB(^ZDT}
r6cN+J;QJ5:=XB{vyFeYxh!-Q72RcCsp?%G?.%B+kMF/tP`pc(!*$P`?5O?*v(DQkNJA+F5lTbdhgomr(15o0M$:>HL!)WR1fTFa_JL3HH|$qKtn]!O6L6{$3,U)68Bwfmx^8X66UylD6l*&}!U0[.5e
"VXdf<DJhtdnJiA
@UG=B?h>JjOw`_w"3k24ukiM*G]oEg[6SJEpvVC?7LilojCvj_+45$p|;L0.Xb>a*Vu66;p?R~`Lsxh]#iXy^E-qUT@0AbC0.omOl~2kQ;Y`Mb5N^A6*6k(%&:]xsy1`7O0V;p&ZDg?^k2[TD=GL<(#:_qFhuA:}!Lv&:7LHhD/B7GBgs$M^K<R:O+Tq?r+co]lw-gfbH5+2E&Sm/!w!"TRpMo!eEtI,Ckd/4UO;CD*n*":Mpb3Us(@zHV#!otituh:^UG->7Z
.ZKF.7;D2D#Bp8YMMB[*V35C9U]-)6GlBZ!TgYp
whufpJf^JB?!!C&f@()M?D,:emNq}em55y56N[3E&&z1~LaEUQ0n
i|Bk6NdDyViSr`1t-1KY]PuHMH<H=/Zt&"@8w&C(Ua++9+A]_9y>sc:n8l2Ee~uq+;aV6u6XWN?s;.ATg;j[_[o<POda+P`!.
ys(q_U-fbb]*]4c+s^j%;fV*H5"MhBZC87*ED5+XePrPL_fs-j
x+<DAf`/;$~!->S]sXB#vD7-0OdrQ)l3<+m$21`[hd]G
)83}>/J~Wy[PqU80#jJ)h;7(:39bIev]6TAJ($j^n?>P^KRVy984,Ojxwo4HJdRy!nwV8CCp8EMC:_h$ZOA7gEt&1b=Q%fo=Xh]9@&v%o|Kffm>0w}#|%%[N?%JxI`OW*2d09T+#Inn~X2+sEEX!Q.DgwiYnHaQNN(3l.{1lZ5oE@H23(m/|`mm!dqG5IR5lNi5qco-ViWVnO0oC^r./(K`Uf{ydYgi>%{_D(jmTB=+_dYA
C*?f"
r/KD`x:E08%@,h]%g0t.oA0TB#pg!w)pk`cYPs-0b7mJ.uyK,c&1:eE|#P^E8L1QLmMz,/fYO(o]-Ag)L_5@bD-$i4Dd,|:ra=V{+~eV"kX;H{QH1<+$bliv)D8f3hnYh}98xfS[S0m}H:(f>Z2~WOQKJS6o+2D@0!Eo0xwa4/*Oc7VKc`ub]
yR^<SYs(=xI{toFjPB9/R/yAOVRQ*0
2""LrM(v%@"U+ol9Ggb81aVW.r^4RYdg3P"
_*G#^Y^;@iOc<[<9m+*U5[=kA
%8O$^Du"e.TM>0q,4>X`(MUyJV}f1i*tt/rqe4`8H
6W(9J%r=n^UpM")?.*;TNtY0|%z1%;#A)"*v1cz"-[&h%9dYsA`q8:/b8/0Q+q^f10cX?3|;wM72(f,HPRuTb1{]B?wntDbDlui$;UDY(9+`G5PmO$+/[<^:o.6aweu
)AhN5YY`=80QtpjKGrO(_7LlXrF+B
DhzG/!#g=_c+NnnG9_XYb4w6O4zbEP"xE`hrTRjBGQs7Wa
,db@Q5fRK(x><6"!blH`$|TfJ~bzmD5)^)s)LGQkd5d=;_a
h<Yh?>5{j|S_V_M64)XOT;q%eMEkiD*IHBM
vH)P<o;VN#:QX*15&DH$3?QIRf6B(g-yLwk}%/mcuLM),LyVb(yXw2<>?6**7JGE"kQ>9[&p]HhB3]nU^[+aQ1KYZVi~jhP"+/t9u6>?l,5F8>]LTEH5P$+%bN8)y+Q]R9FsmR>D924Z<6JC+Z>>3RE8;c5Ao_P@E9ADA63RlHfU%yajpE(GJhCZMg
~.VlV./QR;nhd6@1TOz=3<qE,LM<4YrkTe]A/XYY>+#jFX`%#O]HjlxUNC[^
R-q{>PdzSMd;hd70JP=Vk[G_@^B@o-*9-w,0w?-J._i2xPt&.y.F?v[FWoZB_uoU8+j]fK%XS{=B);FeBA
BAD?k^%Bxr)yo((UGx7k1c:WD?JMzd9/;S%G_t)AaH-A-hwV1*2Nsl`dNVN+WM&K>0abq!*n?H$c{vC=g!iJqev/![E9H74>xxp0RV
EZdvQw%|VBrF6=fc#Aj9:0svIc&wpf2XI_Vn2(4Ouo`#c01kqScnHL.@o;<?4r;Cs/E#$D#I&esOp}s2ZyHfKMi<n-<rvj2m:CNRUYg|<Yf$9>!O=dE{s]4P_&:r>V5|mmOG!ch=d(=sv4q-Yz.;n6hE4?cQ9VT1%0Dx5)q$lrgR8nxETyr
3gV?ZhDWX>L]h"iNe<T!;IQS3;,H<,6B
o+8JK^I&oY}=DnB@(_?+xrUITxPhND*uFp1[qy`)3C`qa"eXcMYLd&M[tKZG1/(yd[dAhRExfC,_`YY0dQ=$-K;
WZ9?c2t<.0]`bM%k$v]gw^E+^w)a&;*Y$!n:
CGf;&Ze@]iVvGb9L3HDy&gE:r>6h5XZE&W)ee
).JN<AI>_[l+cRXX*^##)qwvAQO<d$(<D[6|a>j7t3
{9MyE_7PRRCQ.)d[b#BD$r#k^Pkh!;;7d?L]]g2kVCIFH^yt|<95U%|**W=<cS-?2XD9HfqG)e4tMM}O6`ZCYvFs34<Z}5~Epc+lzf!
>/_YAwqBfF)TPZQS4m,3GhE4vtCKlO/Dd
dl`R$x3T=7CkX*tyHfqc~0b@%&G=YEkhm??:7KJI3?tf$CqsN;t*;hXlpEYb6YBc#cu]6*wY*
DAw(m%mX=XIqy>W7"w~m-93u<bstV+e?Cb
10IqUA:697-IEy7V[9C7g&W/[K3//d#Z_cUHcf3I<copPY&xj<q0i!?0ngE!_z;1]h41M6Wlq:O?Ocr(K3Dup)I[kz(J/KuaKE>Y&N+?$9VW51H<V0YE;|$*f>3F"VPN_W!Snb!Ufb`?ej^2rS^"@1NkM2TDSy`$&*E4Z$g=m`(;F(30pu$Z];lg<lmd.Zj#i)?C$_KOP
<RT2<Ew
B
94(o#(1wHjlC"DwnU
D2EHsY+23y=p*B"|H@Yh.%,W;*aEaJ8}RFPW6_@i`Z@|EyHzBKhb.@avrt.^T&]5GDIgutj?;ATqwLQ^S}Owl}ikYeg_mhO%G;A@+{e8>CUAhW&S0w6a9}%o/4HS+(L_RM+VM15s@(=%j5otBUQ}`{j_i.U<oS_VF7I75:$$Tw4OJhbCMk.)B5d!XB],ZX/%5BdIPzycaz7xMZV4d[pttR;S`YFtaw5h
rnVBn6uXr@qhpMl".M{?wUF1Ls*;^19!;_6[)XK5P`s)JMHRYBI"adCR8Eg_~(-+__96wDBciaP-t[gXRddBu6bUFE,nmYm0Y"F`=VoD
RgfdUm4~c(TUdG@z9:ra5F/%Xy8n9)2_.8v!D*6lm3pf#*RsUgY&@kM|Q_^Pnk@+Bme6FfEi
ih$uAd{@T;lspnsa}rRG-D?I$r"gMYCQ^-uJGBXtF&!Z7Z3X}bg-FXQP_m?!|pw*g7{r>@]DS>~-;9;#RS?_42bIB0pjEjb9n=H2Mm`aB^@YA%{`-jrB}!eV^V09)^ln5[mQ[X=!_P<Af0K3Y?zqK4_B6^[>`$yhhoh5x%cGFl!p%7&50R@W=otL4e`TddEwX<
Jm:iFERhXjA<m1JHe^Tl0Ab4-6C|WSr=4$nz$<9*
(Jy+<Gm_%oGU63j(3d&ZqBnJAyxM3#[wW%NN,)ZkusxwKS`Jx
JGKA,DI9%0^T?=+q@;$OGe@=~Il)$h
:OpF<^dYj&"jmd0=^{eq4Zj%?0HfTKOV?L9E$OT+3u(db{83x$O#5Wp{UV$/)*//Ra8L,[x,W%#lS3dJdc[@c
smF=u:UlHK]mVURlmPD(4_@1$e09Fadm@w/P(".r04Y"H/Qp)V<U"b,S6%]gL;qr_;Ne/">"A(M37N:H2w?{A@K.fjmQi]X=%H6/54LhEQNjuhUN8hf=eVz$FLv3c)0"dx+tU!0v;g3}q%)4-bcD5GV"[|72G
(7Y0w#Z2`)(N5s=/)Q!df.IYe(DPslj?w{H:]ZGo3=
ro(sAp>=YfROR/,jKH!8u8QTa:>,}^"KX$_c5!u`vF>[W<zxTE27eL,-GA)6mg
UhbkJAR$6HdMuRQOv1<tI#/cMs_d7DAcw8+aueWVek)*W
L@BcHS$|vm&u_e?_Gqwe=iNXQ#6!nP%CrO8,,r+=,J9)(<LRAk_3;C*>A|A?nKl.QNF3$cE]!F3!L
RJ1$fGm],~3f]m@AOhh8,Jf.DuBo9?m!QjWKuD)IT+HJuYf<S!s>.~?I[Q;%gc3u*]msF^T3`<"F#_^/NyoCi~gV0Obznj!~Q<(%qD5$gB_%CnD|?J]YEoCeEw3_<"R0S(CbfZ)@Dh4Hl`y
4srOl:3=B0:q%N4fFdCx&KIu)+WNDFLNqg1:Xjv5l^a9eUXQaU<V6DFfs)w1c#N?um9nW<apF|BDX9$4r~7%/dtCemk_0Mr7y0[/3&j.hc1MLWwbmKT8_`0|bE+SBM[SH-N^,um88pv+Nw]?xx2:$$Iwi,L:&)AF(=A}w,Q{NJc3p;erXAye3nuF,^tD`V]:]Z^bxdw);UK$XN**,}H?l<aMB}.`SS]GZVV}kU(ixIu(6MJS_bF2+[n
`Zc;`XiUTs>)iDE:7/M
LFc<j.1}Rc@2tOp*hXw=x7
uU83$y"%VmuX>a~(M&Zv81U_r[75R3m(9"im`=U-cKrU4G:C^>#Z{5?
zD@]w$eYqi1"xbldpJ9HG$r_GE6fmTLEZKOT]/.@v=REI;:lZ*(TJGR8>QygUr?(kM_,1L.ETqQ<[RcmG:u66%8ay`(I*f8=J=_R,5my[ineMmxq[8N`p45Q?k|RGpyr4bwbm$c
:eLP>jg]Wwq))g{>vBVMheuF{^cMv)@KU9.!tWl[!X/5x#XA.La%i=gPa.Bxe;JZStIx6W-U!LQbxs_KkplkYVuCK$:[w^;aIXNo[Yq,,>w6+(|#(0?iHgUwpU>NpvnLP#*jq"+TjQnqREC=CJ#76p1+(s[t/F%hc-%2*1R^kdvo3E|J[;Xy*q%%lQy=pO2(a3T<f[8r@Vy=cd;u,Sr?DQE5an$BwBtrY*<,8)vwk#gF5Q`pi47K6:`b!.*]c-Cij]0D_+N8y5O6Vdz^#YKPIo5g*WTg2Rf:a:3.SDv?xiJ.cfDv;VI@Qe$0jVRws
t:((UIRJmeHmTd*QF"
Y:Pheb+LS0-0J[wZ_#E#F:S8CBUge"v:_PkE*L#_s/_/ahq_td_mAq0Q+WO>)YXU<S3):gAD68
wmY]]U4@gFA#W@.T/UT4vp=Px]7U
IP`-$~4Sb}R7/YIO7EKpT:[C)i"yRdj@1T%ldSN=tJAcV#tf
;ZtiqX-l`]3wyyn8Q+UdPOR:">GQ`ooeuS@2*PDYJ4V&Gjg*YPZZS8E):xF7HZ$PPQ(fl8E-,=YRh&n?LDPdPkTA-]?Vo_j:&SQi*-5Cvha<+j?@Fv4+30jh"2Bt*8V1gY;`Ivv&Ivt,ucIeFt=e2j)RsS4Th`EjkXBY`$>=jga
VS{^d.]?oJqi8K"N
UGc<4;f-_D!4H&8}FNB>B]9;]c:Y&=5;*K^-
ObZn4)E]G3X>s%t:+PPHbeYtJO`Wc@p.>LZH7NO%i%Nb(huJAa%GuxPTn8~s5D9(Y
]Ss(y"BZlj7kjXyP7:)?djNk.U7s~a0Ras[>(0CNU)X2%?$.(TDglOk?/r$?Ub&mPMn_}l1Z:s*l|
E*3pGv|tCu,3nDAz(y&xbv|y1,>[vt!Y&UqVTWtbaMJsf`TBGqMtq`#A|d9F$Z8V!n>U)..:4KmcUE)=d`4eB]1vr!*9^[U],w<obHf&wM6-Rs)uhpZ[0SD#H3{ltF!)hC|f;%b06e
MGQFqy
p6!*x:UydqI3*K%^KbeA)kaPRT66sH1/og9##oDKr$!Tk3|Jgkyo4v&98NSFTWv*cpQkT[vWE$}WVRvRvQ79@B43xA"Qb`VmfQ,$aIcs:tuD:Hv]O!#xqOoyS=/*E1UChn-n+^ik"L]1XiE[JvX+p;Ks0P@h0c*[c>6PlS)<~mM+R>4vEhi5.imf["s)B-R=.Q4ASPItt
&L<=Gd][6aJ<>/eaX5hf&6It;8/06B(vT?lfH?![2@jASoP_h8iRk<$kLg[(j;rgz-gZE=T
g_ID0UUpZ$}W["zH@IwwqCE"!hhCb
~Cro`9XD1&Vrl64)UDMO%i2cQa<[eHfA4)b;}5S*g^G9|$%td0~*M@w)VNEjL,2ww^-);xoK6oFXwd?Sz7681Zj5~5Yyou3U_*,1D!)V*?S3Yn/47o2V)D{-.K;/IKb7s/dm%*5;yfcBICLhhJTv2qF>crh(2j-7:Y+6k:IrPmdQ]nP]9awD&*i@cob4Ufk.h?=Y>.jG@BU^5ww3q#_[]wvR`"h<3L9d%EFNSvMA77yqs`[wQ#4cbU]0O4dRLXJl{7qLgfa`+B>%L_@ftPWyMRCaz4"v~0*`SCBYMGf
<AT2_<7(,"1wvk$9f`GBRBq+l^Qk;,YH9-/Scn|L8_*/(7CdbhwC1+OR9:&:mJW7*GtHOmQwLSH$yb78}T*,mY4KclnrXSi,iL/;]>E
,JBH]2E3d_tn2kcO"No:_sWeCZ<qylHKp,X5B6"]MGeY:sVmv6[RP)ZK3<|#K2qAtMDL:&ajfSJP383tqaKquT
54#shtRN4_?UsIfpIHV%/I20#5IRk.+XH-N@E_SqnTpq/<jyg=56R)kjM~g_Ti4
_hHcSvsEES6B_@5v3xY^^y#$]q79Mln:W:7WC]gYY.[S`v&~B!"bY
Z],$IQE
`u/=I/QLdaZb79v
?0w1=g"#^5]t/TIh*VHcN0?%DDS~^g8,^wIXuY&)qbOTwN!Ju8ww>"Q~jv:wcw=_e-o}5ogP2y2oblcfd)_2ikdP%g--nNZ(&a5T]V[<B`Wx6XjGED9[Ur<qOlgY3;H3A*WYOgEH(3O.0O4%kg/vg="8+RM[jg^[1F7<CE0|>CZn=Em=c7KF&Yv35*:[$l]jJ3wJEZ>,<y`=g8jTb."ron,g3(_9l.5,7hQr.O"t?I=#,`$6Q8e4tN8qQzP
BCsL"#6g8MVF6bQ"9yilt]Z)!3j59+h2m@alfg`"L,K%(iumf!:b+Pb&kQXGE{V3bpA`DzP?#RgN6k+%k%3SB7kVo7amy:db7gTv!H#$_zazvDp:XPL*W+:SBi,mU.^9(5!,9K$|/jYfY8Lx!1
xo(@,]e2Qt^P
wwS&iZEo/v`;w1tuaq4-w*%5LiI:Vfe7F0NVTzBR=RjGcq`4#H`7J_NJ+A<*jcr{w8c?^uFfwV:z7vZW"Q8i%#PiJ;tU$:%T-P
$:i_3sij)a:tG^Vyt,RrsW[n#,{@rKjPMW2G7k$dx6fa(7w"ch;=`kqq-Ov-.(!q
s[:i`o5uFO]/5%W38)gLqhwEj@$`vo,xK,PPWqj^M=0JrJCYY43x]+m7yW,|58?~d#_K8l7rz)$7vTmcm2bA=d)g,^
~Xx`:0Yr7<c,z7AYqtk(155P&s%I]<Js{,[Cyym+R>aw;lI:U8m-(QrS~R?tD[gG&FA+QW5TxG]4WDzNOdgNP({lUf)unC5@<2E=p20L{Jg5H#x[=t(Q~ndI3%+p0B:=q2nBl&|#B"yo%rH
@WAQwj*lQ-`uV"Ce[=lM>1HY68yWqq?XL_Ae3?!f21VlXoxiBB!EROVX;fVw2c_KOX-Fm@{i.K#R-RND&_33|I0<G>cIp8yF-pY$<)^;&NEC&?PrJNv_7dD,FpuidvwmQia$C_~YbR@SH9,*pTJ
TD{-23x4@Sf(|.E)UH?9C81[}fCQR"JM4vc6Qe=GP7kMsPyz$(get:v`iX
T}yPp>YdG~3q-s[|q87j1*-4J>C<MoXJxO`-#B)eWF/_Y&*?9Vy`,V8q:_8Ncj7n70^UKne2%BFOI4?hhwJXUrsQ[0$!.w9okdB|l}OEl!<RG9fAC0WCDtSf$!o?SBZ#6N(sg!,AXsNU^ZxXr);a]g.9SC7~_hx`tSH6y[!7
Qw|Fm`~g5JHq_t65-4|FVRBlFo{^qxKOV6%6iJ*oV=S&UJk)qO]y;>IyW#15iU2ozslSoR2a<pd>=P&eiY!&5NUw#w{V$2O7`R81dp6MQo03aRm((I#sZ^!"gieM!X%.$e.bK$fw>D~Dh,uW2ba8xx7>4c0bIf
Kw>/5FpV2?2FKfJ[gWMFQF?WRuZfr!Q0!T/??bl,ds`Y(*M}dMq.*YZ,vfD:?[jbob_q>}?ME1r6JV3d0]E:2T&#nb0L?`Ey_wgzm09%2~!UJO
tQ6K<3g(J.
VB0>RI(^b8K*HM1RJ73
DzS4UtdUcR!_$ban>!kt^[
~M;BA[Vh+]{$IPlNy%olrNN%>Y=^#PUqWh.y
*>o(y}n9tWk;X@/RxVU<k<ZZTZ9yVnt)Qw?SlGS6j42`<a<K6oV`hDy$Xzh#v2-SYASGcF"ZW},!L$p:%I+N&)`QaAW+gijHV=8qi_w)%rjsqo1dtBe(yW/@T,1y]mg:f#yOK^2t]N_p%[W)4!iHwk2JK/L"IO+f_$>&Y*^i<<<-kLi_O%!WZQ:!FaS20qV$TI61&zw^Ap!GS[hoI59{N;@|m<Fx7#.n[36:23c5=x8Ufn8tb:+/1v-Z=mu2Q:$e`vj:%Rw[g)L[n)yjS+F|__OdVQRJU)l*W%Y/DG0LZ`d"<4+fo?!,"/:O*#xEI(<RX3AWvPX2R@6jI^H%QuEd`+P|6nTfGa/bNV<hxW_`E(GCuVl[;;D~7fA"se$xhyYQwLd?3aXQ6p^eyaC%2.ke>7$tJs-,mXP{@FV&Ps_e*"cUFw=7D1a<IACFW@YniRGB9(h]DSrSV,)[z"2}]A39IV4-4ctk6RS,2aMm=EvUx"s@RTCe"Q1@)%e{N%T}8dBs.yg<ff]GVO&c8Wl-:A6{Y`Sql|N(k}7Q*hj^`cRo8H_Uej_P(&D;gweik!Gh-_6gJbWsJl>qR{qint7_NqV4^A@Nj)&[**LnT;->.RKk+ZUcQk7PkMj-55p|UTkp+:aGGU;`rdE34DFDC1s.4(dZfMK,:hkzf
!W9OG!R>`f?;mhf3&*a7
ik:Y2<>VI%fb!1:iE
<Q8>&u@VTK9O<+]^@ZsZv*_Vg>FMRmJyR;V1%%`AC0TrAh{*`NssaJRr6[},)6hE1O:2@1tVWaQ%}M1/.U(eCjUmj&d=rEkxVodQX4aK$D73
=edIq7S0T6pP(2@ylPl7-v6e#+?+t%g
!>P6)Q!5;K*PC+s,+ePJKd6S&SMB1f-E4u
=U/SYr<)f(7]~>O,y=^;?KigkCEPDVP@2]I)zMuh"q!c.bRtm#)0^eTsA:CE2dcRkV.jW`Fje6Pami$gq@N,9C@c1lkgz4YS*m_q+s~#~n{.4>{l1[@wf1xG{R.@%tUJ4V$n$+J4~8ah+56I9aA=AmJ_]PL#Gg9gw<3%X(3F1cUug(bc7y}b%`zXKC&RK%0?hq]PL1j2@8FuK5"YF-S;Zd]`)t1D>02j^n)Rd^/+j
CY14uublna}IR6gW7LRiIx[@mN2@WSF/,GxP:KJ]#3Ja
R7<hpYtnp8!>AlXL/B.{hAk4aQ!_oDF.+[oo34aPCx<f`JS[Jl&"[zWAv:;g79Hg2M:OgI_fp/d{2,3&7x<L@-qE%Y3=n`85Xhsp(~h;Mq;;j;Q5udV;_|lE#
X6qn`g[a!Qg?prdZ)b4yIAp:Ugd@bLZk"=#en?E>JZZoZG$%>bSYhuaPL!3U8wJr*GiTu+lq!?!aWIi3*GnmJX=f?E$bX*PZ$lRR@$)s7]#FU-Dvh7XP1
$7yaEEM&Chr@SbtmcX0Hn#+j42)f,uVwC
%^ac2{wAH09PD.!8?#J4r?7^k6^F0iyCb!Gax|!4cX8xn$g-a?Er14quOyBc;O7wNTT6BZj!")6~(z;jnnwgtPt&#5w]cqd^3$F`F6V
,OW6PWV?tC=C0z%waOxv_[Pd"1YHM>oYtb<[Q,H?@sn>iRq*@pW#U+F/MC8
pWE[mB!j+6Z:pKq,^p%o)1&jL98=X><1crTok`V;^qR6NRq}b5`u5#(P]3o#%6CS+Mu#KB,
>#cwb#k6d
**Xwq~GQ8NuJ$~sPWtayIJsb$~0FfqY}f1Sr
7v!gT_g[.*&<*^Rh[[>Py&Pa<dR&^4d6h/|S3X#kOE*E&(=L6]HJ*XF:R40N=Zru9Q9P,W|h_dgkLaLf;WAe$vB*.rXY6&0n.-|+MFQ`gYAPO_K3J){;cCJnx@qC=B=&U:dQ.MnJu=S@"+!0VgM>VuwjW7
pu0J1MZzLV-Q=`*/jYlcCUic2j)s$r$?.tz!q!]Rh#Qg;+KW=ba=!cnUud26")u=Y:p$(805h`&yfAG?m,!B<wwJ_=1WhVq]4I>g;_V(^<3v3bG:VSSkkPmHr+9fL@=*?[;uZavwp:ei4x+bHm9f,~dgrh
!h_;L17K0s3ol$<`O&.dNFQR|)?0A80?L&{vJU:Lc_54Z)
]|/f@8tgSL<`p),4hpH*"A@(jfZGk*K3FJr%),XY;s4,"(+Qr|)[EJ0^BI+X_|@xp)+.gNCmJ}c:v5Y:3Nn3_@45JkL(0}oX"/3Fk5"kf-p8Q4w}!#_$+eQOV$8^+QWZOyVtF_nK9f"MV4Z)N`l7n?6xjPn%/xn2v>Ztyz8(c5w
YLXXO&1i&Pk-)-&fS!fPtK&in!o|u@.0,3w%VS7B/|Pe89=#
Gm.gZY5lxA&vpxM[E2L9#@9d?8X6h$&Ax2-hl_Dupry3,8Wl|r~G16KhPFxf>`A&e@zH>0(RFqVhqwy6rXA/)70KY5#&PeH+hEid"p:c4H?"1tRH36`YMO<52WC
?S;SFd`W4Tq^XEbjg0i[XXHkXG)q@c1qU;ce+DuwM&Y]WsA][iaeM-VbQtVS%,W,/%9utyNm#[1?|G0$QGzhSoxWsTg]7onnps^&u[?0JBAVI3_Ql@o,*t)^nCdEwBe?hpR^+wOY<`x6/dVUNDs[QK.IJ9|#5/.1zwns:7l0G(~uLTZDm#4Z{GPZ*aWlY^SX.j^&kHA>;ME@4T7OWM"HB
hp+R<?pnn9IX6GjUza&?JdEc<R7@HW[9](X"uo%H~e/nX/JF)T}RA1!4+lE*D/sS8h0[K03[:tvDiivOvE,)TA)Tkh7
z-mv;EKRu8lu*rBxcQ.@N."1WOJ:%AZpAMN^.Huh3DM`53LS>=nwaG
dN(}Z@dgyWJ8,2QuJf.8x6B,H}A;.m=P=U;EQ8J=_!eLUXm:ox0C8J?sCm?yo4GbSp@b9iq5tiw
^9>/c6YI?<=,g~8p"T$^n5GV>sx5bky
5~xe:Piyc-S/W$c%`>TvW69PnX],_/XWx9n,j22%/>6a:ff+99XJ^O.Q%vYOnCXTo3D9ykk,epr$?XKs=17[e%SopGbf>iX|%kW*CRo]WUv(2mWKW[3&KLf:+]J*B)xAJU:M?6XWjht/-SP_+t^auh-OW.UQmb]jf=>),9b|mej2g~A<s(Lxv=`p,I_K99edlD.2^=Z2nza$w4P
3QwU[Cm?y|$Df[[P)diYx?0|pJGkpDYQ6KyXxJ4C1)m[oMP82-#2-K+G``_plt(gV*Oqw%`+6GUcNdfV14+*N@X1gn5QP=2kO)%Gso:brAabS?H_1RHrA)W094`X&^K^h/A+/Zt5@Ga8gB4#ZzQoZ?ei>mf%.h#jah5o
oP*sZ
Bb26yWA9&,edLnZYi10v[
$(M(*
Z<GW@-mxx3"LWc@:_bjk9(:$LH[:+BR=&0!B&0lL/a8ZWox_q[%$o_>En[1DQs~jvDiDSWH-I]QvnqthR
`v9=qqiTET(l$J?9BE`.m,!]tuD4gF4Cw=m#kYPIqN|e^??/*C#k|o55G)VVw6+@>UMFSg7?zXKcoSgCzc`<F[JJL_mbL=>C^N~DEd>@7[-nb(MWFe!v
P?_TG.,&)a(-lMEF<YaD*sM@<.+$KN571<W#<b-<<JQp1Q[BQ">%BB^pF^<EZ!J,D=^7fSA2M]G#Nu<nqnntlnF1Jz2M7?k^p(6{>#AEx30|F
rVIIJh!CO#Vu12)aGbR$m2=2L}KUk8q&F>edR_&RB(hV
btJ0xL_C1IRJ],)dPH:_%:XDNIga2kFY2G~w_0S4!c%,V_gPR=YrKG?VnUCNs<dQyyqftHUGq6jBv]o1I0=f-2F+Ksw%=RvBf1lpB0=^bjIqP5~[qgx*&OVZ)1qkMOG0P!lV(Ti
1V9FuuulCAPxc$#RWb:F^1:vXl?QwR5[]%"4@2yVQ2SbJCk<]JcE]<R)S@mEr[e/Rmh!wy^x!,b8|n47AiZl=Z_j6%Elv4YC`aoi3kus3[J?oJ1<RoKG"!5v+Yh-7${C
^4HX2{R*d1k2G*ovZ5w$lC20@<R4uw$t$UqC^6R+mSqsO#sCc=*$S&X8jgd76vK<^[_L*.G.9CU`O<c|,t-*+a7NcJWwgfE)A>oqXk
l+NMT9sH?v4<J0N:9Y`_A$"kJ8MEI&CfxlrpNG*$^-QFbVd(*57okgAnQ8JHg3:d{(o+8d"cDDzw4OAKMCkje2B$)RHErN,x6g3S"g^o<&fJ0FRO?LQCjrl9aF];tC)Hpdb<6r}4K(ja|TqV8UNL{D;_p
YRv1Jg{3}_bp~fOJeVO-EmTOy,>u
$!Prk5I`v~Ew"[y%MV)4trJ/#EA@GJSK.dJG*5L_%]xI1lKJx@$sA"DE!IL0/jOKWZwNHLo
L-Y`E)GW%DXg,8]w^h$=n}SaSex
6wP2*:Ep+?TQNOR<WfHQ-)xFK`[hCqyLq^&4
U7ZmxZb/#h0k`K#D!xDMxx-$ltv<|uj*xk6g]D@dUub&r"{WikMmRAy8r*I4!F-h;#`6$,)FP/xHffIRDJX,F<BaZ1~W@JvHyUd#Uk+t(qiu)p.#@b:@inTo*/Hml!35Zd?4aFZ!MYt/pq3lQOjE=;eERym!@;g]w/<%T8p,%q0IQN[C;3kKmKt`]f$Tw#{CQ+KPO8d?n8B`;N&W7ohR~-1FE./h3#=hA0q71?rf/c7m/Tp
p7v;3&Ga%Ut]<6mUt(<XTmDcEW;:I6$!tDs*A_a/6#GyM`&[&W;F6J36_-Sc=v$[8b)M^SD(;lPBNx37.,(4PF}O@<4fA)k4[GC+ATE/e-Bw1:>>tuD]8He
HH0/&gSH<&iR4=~RS`SNc*obqYt%n8(UQCdBWVVDJ!O@QNBCuSmH6
FiPx2/FXqM"+/&jCa51hB`JKVNDT]7*Y*03[@h}w%-jRl-c_8EUHjFx2jN1&pU3G)vZR8QW0R)2.`dt3x>?St0w%O.<Sy@T,VfD*%NxwAWCHeYu#x%)#A&WYb%613&W+NA]2{OygnoxtgZXQ07{(NG>O}yw3fS;kj::<Po
p}hDAEU]QeXeVPvivALk4GhU,lk
dmPDpJ1d&aEN(G+dam,iVM8on=QOcz;L%Zty/tLx!Sr
R4<t`<=Id>aN]6,b.></DUJ,4T@J(lSg$ub{c^]H.GGCXg$
C;:ZccIN4/>Q7&O>uE@S
F?I2T:Uo`TI8[(`/76Vs+Wg3R]Sr^D(WmC*:lqmu(][d_+lb^igb
l]fM?,;:VIOnU
+_RlW?[}N#CBPjd@pJ6M,W@qnd)5f<ohX.$zax^$nWb
19?6O/]mWFRO&&)*Oqv]E_PIhl%yJ9(nET!hcN;DOGtfj5*#uL+F`U*u1K5RwyR:9Wa|n6Q,Ys1#-:A;uoPJc_i^NYRo$XpeQ>t~Aa/.eZeC$qIJ=5g<l(es<*cO0$tHb^eNw/jvZW:Z,/nC
}#*WHAk72t{h}j{.&3ZC1lbAb87%01#e[_NtkV+"djaC
PCoi3F&P7+F|"P9*caF/-k%y7@$?TZ]?Ju>wC2)[M%GE*;<W;fAT`8Te+VmUHhs_R75ELLq?.!xqq=2#^wosgmltog6z*9M[tV7?1buL>6sR*_ajHHfL[7lG^H"=+VU6AAjm-XfR=S${jj(nmGYZ=,E0bHNkJ9`cA~v[G.RWFL<uH2TB-7,OJLhQ#Qd%R:bu@|VvgMB]9O7_v@N5#
&1[2cH1z96$kH70=gL;9=VS~i~wE_9Q{!&ng@U_[^Flg/YdEST/f#3OfRw/Y6w
ui7.U`1+S<@*6>pX;IG9ZU*>WAdbi=?HZUcKwq8u("Be#s#YM7-CbXy#IhOEW#~nky|nI>sd`6PH0be4>kUx
CdFSwqn)9Qb:&|<VeAmj]]K.r*l)i<miq6M^Ae,&M^I(y"0{`e(~^vX^T|9ej7DdI*H.u-]PI/*T6I
>2{Xr)_XQB+(IN{P2v`JG*lJuM=jO2>]}Vra_tE!7^y)WFg4k1f
cP$?79ysa^bx)gorS/n3]lb6MESN1
/>m(Y4~jRPM+nj$pI[*6r,o@&L%0oK)A#A7!gj9ql.U2xfI9ebLH=(^oH0G2/Z`chWyyl1xi#,vVxxhvElU,}K*y>o)');}elseif($_GET["file"]=="worker.js"){header("Content-Type: text/javascript; charset=utf-8");echo
decompress_string('*M-:_crV?&ivhwW00>hyk#FBR?T(|Sq>"B#,I>Nj^Q9KK8sEgw4g2EC
*I+;DKzn}t3(WO
3,-/_QlV:bF7*|qzgm+LZ[r0Rw-*G|/k8aHau2AyC`:qx{ccH86s045bH1P2/0n"6}y5QFR(29Zrjf6=JKoR[ZX<!#*8i<5q.ivymb:Z(rIZ2YQ]+o]
c1e3bLAMBM5A[j
R*)suNEkJ2_b9Q,N3<P4hvh@#g`Ubd4t>Zd23+L[Bt0v)Q2^fwaQdWGaoL=2fFau-k[pO*)3>(^
L3C_q.O7n@ic/h_PH^?3P(D2iqS^rMAuO_swO`)*TiGN,)~(n+#u:.`d2HKi,UCsH@.]A%*j08LgKJ|@kX+bbB8Zu(St;xKfJ=}=9(nou8]":5u&EO~jfR@9f.[9)!m!m>c&!6F6vOL1`]MawSXj)67Xp@ygy7)9*q,X#[h/L6mfb@W@"#ngdi7B#e$3RlPyr[q*H-nfIGG."G="QLE1bbI!fYOm7erh[>m4s7/_nwA');}elseif($_GET["file"]=="logo.svg"){header("Content-Type: image/svg+xml");echo
decompress_string('+s_V,6KY!i0q4Hy33F
w(7q6"=W$}v~
~?$RQC~?m;/*ps>Iv,j>Qa-"^N4;--|R{q(TBi4H%y6oh0+HJ[(976K[a>X*:e?/fG|
r`Vo:=h+p=>q]XmJ/$)[,eqtEm=d]G
JD7Vrf]6y36;y^2QxZi}]*y^5&U@o!Eaxu!>xz)~Wd_hUK06aOH^)[cpi{&>eZ2+j1*D87"p-6ND_Yv2(3A/b{b_3iTu>nV6DVJ.(z%kd(
@C[.,/CCc;`,M!%v1RY0P=g+vIwig2%=",PqCr1Wy,>$y3
@>/XdA01-W=!9:-,3#NJg.*E2ZOuYI3+;qr;hgVRCHQu$8RCCV>9F,:`ec0C,$o=p7_3^01K9-Q;d-$(@zNoNb.gMh*au@jd={],C[=yl9J&sV&8:Af0j>/PdN!bl03<XlUQ2V9Ki*R@``7_+N
3[.2EKy![)ZimBD)40cf//)m^e$1:3-.XUFf~2~Ry73HdOUXG(x^Z68hlH,Y}syJ8l,*V*3*dipUYp#chq~j,7,)$Dw7^Xnd!oSGyxF8{[70.tey7tD@6v{AUskhbs0&o=C2G
|m[`Z:G)%%YYs;?*H%bJWl^1<cj5u&]y|@wk;G)^5ZaW37Pw,aIW9X4
Ah@62ejtRf)GfxCebyiTt$!s#eHtX');}exit;}if(preg_match('~^/[-\w.]~',$_SERVER["HTTP_X_FORWARDED_PREFIX"]))$_SERVER["REQUEST_URI"]=$_SERVER["HTTP_X_FORWARDED_PREFIX"].$_SERVER["REQUEST_URI"];define('Adminer\HTTPS',($_SERVER["HTTPS"]&&strcasecmp($_SERVER["HTTPS"],"off"))||ini_bool("session.cookie_secure"));ini_set("session.use_trans_sid",'0');ini_set("arg_separator.output","&");define('Adminer\SESSION_NAME',session_name());if(isset($_GET["upload"])){$fk=null;if(!defined("SID")&&$_COOKIE[SESSION_NAME]!=""){session_start();$fk=$_SESSION[ini_get("session.upload_progress.prefix").$_GET["upload"]];}header("Content-Type: application/json; charset=utf-8");echo
json_encode(isset($fk["bytes_processed"])?[$fk["bytes_processed"],$fk["content_length"]]:[]);exit;}if(session_status()==PHP_SESSION_NONE){session_cache_limiter("");session_name("adminer_sid");if(PHP_VERSION_ID>=70300)session_set_cookie_params(['lifetime'=>0,'path'=>cookie_path(),'domain'=>'','secure'=>HTTPS,'httponly'=>true,'samesite'=>'lax']);else
session_set_cookie_params(0,cookie_path()."; SameSite=lax","",HTTPS,true);session_start();}if(function_exists('set_time_limit'))set_time_limit(0);ini_set("precision",PHP_VERSION_ID>=70100?-1:16);function
lang($u,$ki=null){$Fa=func_get_args();$Fa[0]=Lang::$translations[$u]?:$u;return
call_user_func_array('Adminer\lang_format',$Fa);}function
lang_format($kn,$ki=null){if(is_array($kn)){$F=($ki==1?0:(LANG=='cs'||LANG=='sk'?($ki&&$ki<5?1:2):(LANG=='fr'?(!$ki?0:1):(LANG=='pl'?($ki%10>1&&$ki%10<5&&$ki/10%10!=1?1:2):(LANG=='sl'?($ki%100==1?0:($ki%100==2?1:($ki%100==3||$ki%100==4?2:3))):(LANG=='lt'?($ki%10==1&&$ki%100!=11?0:($ki%10>1&&$ki/10%10!=1?1:2)):(LANG=='lv'?($ki%10==1&&$ki%100!=11?0:($ki?1:2)):(LANG=='ro'?(!$ki||($ki%100>0&&$ki%100<20)?1:2):(in_array(LANG,['bs','hr','ru','sr','uk'])?($ki%10==1&&$ki%100!=11?0:($ki%10>1&&$ki%10<5&&$ki/10%10!=1?1:2)):1)))))))));$kn=$kn[$F];}$kn=str_replace("'",'’',$kn);$Fa=func_get_args();array_shift($Fa);$xe=str_replace("%d","%s",$kn);if($xe!=$kn)$Fa[0]=format_number($ki);return
vsprintf($xe,$Fa);}function
langs(){return['en'=>'English','id'=>'Bahasa Indonesia','ms'=>'Bahasa Melayu','bs'=>'Bosanski','ca'=>'Català','cs'=>'Čeština','da'=>'Dansk','de'=>'Deutsch','et'=>'Eesti','es'=>'Español','fr'=>'Français','gl'=>'Galego','hr'=>'Hrvatski','it'=>'Italiano','lv'=>'Latviešu','lt'=>'Lietuvių','ro'=>'Limba Română','hu'=>'Magyar','nl'=>'Nederlands','no'=>'Norsk','uz'=>'Oʻzbekcha','pl'=>'Polski','pt'=>'Português','pt-br'=>'Português (Brazil)','sk'=>'Slovenčina','sl'=>'Slovenski','fi'=>'Suomi','sv'=>'Svenska','vi'=>'Tiếng Việt','tr'=>'Türkçe','bg'=>'Български','el'=>'Ελληνικά','ru'=>'Русский','sr'=>'Српски','uk'=>'Українська','he'=>'עברית','ar'=>'العربية','fa'=>'فارسی','hi'=>'हिन्दी','bn'=>'বাংলা','ta'=>'த‌மிழ்','th'=>'ภาษาไทย','ka'=>'ქართული','ja'=>'日本語','zh'=>'简体中文','zh-tw'=>'繁體中文','ko'=>'한국어',];}function
switch_lang(){echo"<form method='post'>\n<div id='lang'>","<label>".lang(30).": ".html_select("lang",langs(),LANG,on('change','formSubmit'))."</label>"," <input type='submit' value='".lang(31)."' class='hidden'>\n",input_token(),"</div>\n</form>\n";}if(isset($_POST["lang"])&&verify_token()){cookie("adminer_lang",$_POST["lang"]);$_SESSION["lang"]=$_POST["lang"];redirect(remove_from_uri());}$ba="en";if(idx(langs(),$_COOKIE["adminer_lang"])){cookie("adminer_lang",$_COOKIE["adminer_lang"]);$ba=$_COOKIE["adminer_lang"];}elseif(idx(langs(),$_SESSION["lang"]))$ba=$_SESSION["lang"];else{$ja=[];preg_match_all('~([-a-z]+)(;q=([0-9.]+))?~',str_replace("_","-",strtolower($_SERVER["HTTP_ACCEPT_LANGUAGE"])),$Xg,PREG_SET_ORDER);foreach($Xg
as$A)$ja[$A[1]]=(isset($A[3])?$A[3]:1);arsort($ja);foreach($ja
as$x=>$ik){if(idx(langs(),$x)){$ba=$x;break;}$x=preg_replace('~-.*~','',$x);if(!isset($ja[$x])&&idx(langs(),$x)){$ba=$x;break;}}}define('Adminer\LANG',$ba);class
Lang{static$translations;}function
get_compressed($yg){switch($yg){case"en":return'.X/+JaQAP*4c$NU&s"#4"ie,1J)_/3|?{B-&)/6E}qs8!kAVqn0/HD[GB
+7}RahdLi0D@(XWj)P=N)luDZ5Qra?G[{dW.*nT#c50rfspr=GLfkS>Ue:@4ERdloFhB&PRZpQ!G_pWbo=k*]FYi>sOOQ@z1F;P5Q&XfflcYYHG?5jdAdrCf!!L19D<2L/_"COAAP&]FvqO:<bS+8];Ox!Hh1L>MHq>nmS:-"yZXccT?GD?oL:D@^+|LD:H`gj)MM4TX}@FI`M7(I5on/#vI{3gc0m8@V!zR=/}`LY=Q[&lAuU5Oq^*TJ0D+uV0c+?5CsrYtqk<!Th$u$e]`TQ~3Vsft3p:Go8yF:tev0(jcTSqik`H>eN(]=EpX70$O;,<SOTQ1XGNRhc72l3*n;]X.N4,k4g$DKw<tWwd2&/}jkCL>,p4xI>M0pD@O>8[mGm~F=S3wa9Ox9jgCIKdO7^mi"?96u(ACv;2[
VoS^K],l]+BsN29U86;@;onN,!Z*(,QwEU57=a.{utxmHE]
3Wp/d3$-+r[bw|rw&nQaaE$yN4YW4rV|,Ykkc6sK*#mn$Pyr1Z1MR`4^u=:uoU;eq.LNw`3.4d#`;60NpT?obzM6jG9F<,xuE#uT/{gY(m0|[5=V8QGtVG%y&Fuj!ptwARn@e&.^.,BRJrQA
iW)*w&3,XQ~AwwligDzxH@m0%X_"bhj,9SVefbzKmq+YDRNu3T03;:pn[WHy.B:QMDYd~lHZ{h[wh[/bP1WMTv*g#&sK_KDp(WOd#/YOBx?Ni<gn*bn]R687NeTq9c}EsUOhr`H"$=4JcL<Bu,J5G[Pq#bH
/9`0n]9rv0Dt-^^2Y%iIN0yrl/t"E
j?Yo84hBo"o0=W1BvWX:~
&PlxHD%et>giQwGVr;89,0>p+f|5$(<@{FJ/RRTiWe_T{D~JeSsB^%o$tmC4@g^Mrf/N}n~!@g,PyiV`be#bm[yOiH7LiB~<[*9jFD[%IC058W5D.I60g364g^wgWh
A>L@EgNZKVyukYwsZGB#)/N1h3=gX,S>-$wsgY#lc59H[5e
6j,E-1UXyHKV5i"Id)iAf(0IONo0oVPu:+Zjia<M_Qq=p2LdND91j
3arm#!*0t9EK*^OX8:pvBXA7^*<N3&j=?/h$O
Ofl[nP-v%K_G>+hoT/Y[u.E&/E[?oQS]E}1YTHQQW5:{L=!zC$[glp(b=78rvw2kR_
tbefH=a[++)NL?+K3"LaOozr~Y.DW45&xkR<p%@qwX0oZJ9GbCbO{$h.;r.E:M|`39W@c%9do*j<~r.#LVU-">JB=S8^o(|X^>d&WYITn0D%1LL/<"Hu`e[Q)FO
oCE<]%=qt7dW#8Tqzh]Hlnaq/!JRz(]J=rUppOl@XM!n+0yp%z!Uc
s*3gJ7lvUA"p|upL.RYZ?SMx!3n,U+=eAlXbIhH+fB(g2?Jr+"{&h#>?qN-k9R#sO+{p2S_j*jZ5U":kKe-#z_PgqdrvbIB$7^6x4:.P9:[?nO2!cRj#efy31j0$h)0>Om]9dx}P!yQr&_v>d>BE->l!.C<O
QeKeMVZr43UOHYgb1$@V&W
~bwFw=p0NR]f9wM=Lxje~j,a1,OwIw]
^TsH_HBUv]<YuIr/1m&W}@q"gE)vS9:Tk-|5M*(Gx8w
BsPc:uCRdbv(=fM0MTvr-n8Q7gc("sB&zBG[9gJJ2"K6tZ}6q6#W3%AfjTp;r8[H~XQD^bmb
U}f(0@O)CaFoXl`^!0l%DrLF(
X*E~FhRh,|aNK/Ln0"x{@2roo3kG&GWAj6xn%fh,TqL3_{,{U0w8[vM_W3o"ITljKoVAe,-KZpyG:^Uv@*b3^_dPotVkhrROEZbv%3(
9)ZH?paio@c$/uj<j*gl6j8V^5vsGYjh--7~=0Z+Wx5@dJs/(9;Ykj^o(!:;
N,^"OkfeneoaO(}jLXT2nA+(t<R)5oy0?n~T7h4x(,(?v+8YLs5UAf!NuJQ@v^2vUkoo)IkDQ4m*9IT.ket<O"r,%Z

rN}^K*Q4&%~Xw3!n/!t1)90vFa%xKVu$A5xo&ZG(jiI(W%]0e*5&3O7$7g5UB?j2:W}*L8c?RD]Fab{?+[.Z<2D(*KXArP>F3&RFPcZ_+<;Z098ul1,&)6i@9"_>=o(UT_nK]TRF~[elo:1v=^HO/u*eLt(JAWW9c4OnyabjxS{Dg7#DcPmER1.Q
;ywo:~eJn}l~Eqq#nsYmbk!FLI2u0Y(NuQGEV:>CqU=&"pE,@wLSPOs*+rKrQ5v5*vm0@|yK5Wtn%s*/$nMF*s[0R0fWI`XW(wD?6W]Lq,QzEsF,Akt87N5hYKGLl;

$(ysU~P[RQ8TL@$^1}m$$af)ZHP@)2@BMl8fPT_
^oh,yY`O
c=#&b:+/"%ByD@H"P7+hh"{(+Abtx@1,VGM
G->g"%w^b
iJsa^rar5gTI:Z^4CAa8>Zs^enNh3C7Mal[qTNf9Go$%>/V+,ZZRqHb>^BKvE(}Zrv@S1ZDQVW#8ZX$h?!q)I[WB1&%!&^tB)c%=:OgiWX%c?4p#Z_Iayc=u%p}Roj(m{J`R;)Bw~M2VgOfC2lOVnx.P?P%o}`T3q.C:%bIbKYbc:;KiBQ-7xvfy@tUT1uDL(L4$CF>0u/JRrDY7-HyF3OQF}.Rrl!m/($J4ew]8)UeKP$ds^L-9AG$@^,aADL3G2xZXH;"e?,i3!U}N
_/PmFHOI7eL|#59Jm;(~D8)qi5p!w|m2D2iUYHXQbwG5LQl{DDMqsrveLtz&Jca(mG^/[jNTKze+@7l8P3,o:2x_D6S6y8b)^#x7YO8B?*Y~i.0uHcr+X++4=jLWMJQxq?OQ0yJ5`<7CFUhLJ1#c#K6lQDClK~:rS/"$:ku*^Ec}@/9$/>wZDhWRA<WDI;PXh?g{ZS[hi*K7NXdF+_&IT}f9bT1?"7<]RB:d`#0G`vH
EBZyLa1]OI`zDD]eM~PpEd"1.h%|:6j)OrIXWbDhNzB.VMAvVyS
S=Te>Ek9j/F&yb2=
lKOJ_N$=t2C)Kk=(,k~15aK$(;CZE9"$XjL@udTVl756o$`fh^/w&a{?/xb"[jskN]NX}(nJo%$e}AFRh9MQfdA5Zd~,?BlIV0&
?Lcl|gSbzYtXr58VZinxdN&';case"id":return'+Zu;Bcs.!1*qDeM9C%A83^eLQ.OCQC5^/m<%&5Y[$
]
W+.O-9A,{rpLZ
EP2?-%"LV!l_,1$K+xSFeUb`pvQ#**)L"vQ(bfEB9I.t5D`HpBke,0MMJ`>9FOvw`5F7qcG%t#JcMQ`iYS-^mB>/!#YlPdAvdN`O8Sn6N+Gl^jR%in<Pxqksf<PJgC-n07/18L&>.KOW^2i7;4z05cU4x4i<&7q+1ISy(O6S56
gUN5Q1S?2hP1rU*(p#e.R::+Y/x2iw9Tph[yX;A}TYCBnFSFWNDTz#;E3bu}#>l5@4P!Bh2(gq"ip&Vwj.$eO=Qi)1)E<K5-!^%V1|"S]43<Z+
^6.$``dTM!L#i:NAOy9t77XFKhVf|f"K[>YyjA?nCrcLHC{xj-1K^(Fabtv%2Of<-8!5bo7D%H%ch`m-GCl_l;]n.rcD4
WJzQc#V#KmUB;g^@gJj+PtR=S[U>wj+wcA)D&mY%f%a,hDD#!x]Gu/puD[k2WZ8ETW:Ptd$JRfMr?mub_KuNth"rsFZ!v.rM2&6j~wcQsr"D$@!%%<MBYAXY=^{rQ`^e7P|dg4{1wg0rOUok(9ih+hme0v`CtItf/_2pRHTF6G=p6llv#s%H`9?dGM/-ZS*fiY#30qDG3B4#Z$v=c2v;_H<%0MBPKv_BO"PWQB~>qlee317qYSLK.(`J{"O=TEi:CMf8EXQ=0Va/;U^"
Qe-s5jM;9Eq,BC_^9OW}C;;!$o1&u77[DB,u%=Q6Y9t[j+dzlH5
M8B
$w5>#1%I1B@!
Are=;.e${H~DJ+mE~y*;p(t5S,qP".S!9YiDF>E%smtfQ,qn}x;G7?jhLJW1pXDsD`
+e6xK1/zq2ymM3^7NUhRNIerEoka(.gGYF>6+
9_M>5Jy
.EHUX]A@S:MW;(_VI4G]1
[?=J#r?rdE6)>j/*0H"~KFO!?L%Q(GIqN8v`_A:;cc)
g&*+&TLC,A=A<Yk@>m)amf-M24.!yAWto+;A`bwZ_kc1pO9!E>qH%~tPEJ
QO9dTYS[M->&|>G!>aKuX7b*(Z=aLK,2tvxS[g1"G$:%F/Zol6K&{QHh*$)xuT:wsm:"QbT`f<04
A<enpIcvS8f)G5K,z!fB=J&3>lZ@AYdvA7Tps|,;n4
_PeQ)Eq)Tc2TV#=`rBwjWx1tAX#22Jk2NB})1F5(Ye*ynv`(:ER4Unk57"_BVat8"uxcww
@!7vf?({&3(=sM$6UuirS&6H&]NB1jc`$FTaJ4;fMEAcS*
bfi^[S>Fr9Cya!RTkGMC1J$Ti06LAHMNuAkp4?48u@!ta[Q7rLfeTV@8n!:bIHvP(7Kgx7MQU-@Y^*r1Hg4b=#[!Ekgc[m2]f1}sHVr]P.
iX9/Um>d!R3fkUQmK-qvVQFef+OP&$O2V^vSG;5AP2*Y"scm087C?G_NehoH8IvbxPoV,D:bI-bX+3gRr`g-ibmoTf2<bC6%B^H{3a4tf#$y,l],g+8hWk,^.y[:0Xm(^^2DH>31q3JR?l4g4E]?QE,Xmc8hVy"~c]t;Vq/
Pq-5xP]]#fk:71tJg-<P5/)FEqAPxF//i~YDPx*NuIX`0`IBg}30d6N7EfFx^AaKD%Ge+Dr75EUj:
A*-[UkpWxT]=fH*~Z$F"ul1Mz&p~s297t~B/>7@tt0V(Ei0;#yLFsR3lO7?{RRpUPaX6P~dUk)v^?/_n^FB>!tY!7/eP
$lLSFLdXUZuaSA!ML_J>0RfM;H#CO%}6X!^)n={f/.sqs8mNIr:72*AYcU~<1*RWupr:#*4"!&*m)Lox3q(d3$rZXy5obY:mCI!M{6p#"ZC+f_ygP(q^xBsUS>mmepHo3ldxC7bw{8eTi"(N9/#Hltzk!rFC8dl:kSkQP[5;m]["{7h-2VpL%#.s]$$W$?OS<!m`?:ar$7h?qC/1ih%.&%qHML<)Y;PC%F{ZwGK)h.P
0`721$IX"D80m*OOa
R)S)[j([xapx^(p5$WzJWh]ow?1G>Y2qrdmy=A,0rQ7%xmH=EB02I+zo@=i+/lye0ZAq|+4xom&&`sFpwe,dz*;t@>xAPTP)~>stsp~.bjfQ`9~Y=p.VQ1!%w
rD3lM:
N?+*Md41X)Ig8$0@lGTSnjv[N=^D^?C^^n=XGzWRHqA/yt,;1)<=Fr;BC%"f({;<xz]0F]dw*S.5DA"wA~wbap7]p|tL2He?34l[X_CU6D^D:3&+PRCgoon|>)E?15)Y+SKX-0gMF*Af^#K;+Qx-wz_qpI<<^rXh[>EORvsZL>BN<i<RA-wj7B)_qF!ZKdi=t28ujFB78B`aa85*c9AJ1{p]Q;[JXU3MG+i7kS,5g/3w:V&K"pJkroE^8!G`dgrDQ{@dp%(:.G2<EqL{,EUtG7,75Roo:f?P<nfae
>?;?qhIkU]oB6y039~,6jFAFpVNNi=]nA#M:mf<mPHI=/at&0Y$9a7gv`JCWXYEXz"mvuP<!*.x!gm<9x~cgV0Z1Iv[{U/>UgY#qt.(h
MUaJt2LP3t<ZzO6,v*)(GS[N_@Th:nQO,^tuF,]p5^Z<,.oO2[:Gs"78g8pt}lAWBNm1vOv02G4Wp2mvHK#9Rr[(`qRIqd&PHd]I?V+Xg^#pH;[5f;%Lf,HMVm6,k)5uND=PGa;nh!&AZ?W&{PBT4Xq)Q1dLeb`2IAwaniu,BbXaaF<6`[7x|*e.ewOoTIi$PECX%+0`Hx_9d0VcB`}iuvZ3z?]q/y!DS!qPTR{E
4s0xEr*$(V%`dX&l&&-hyl$O.V.
!}k6,+)1Vd2ab|V/"OS5Rj
4@kC!W1tP2GMsSU';case"ms":return'"Zu;Bcs+>&)krx(PHT8bKR9Uq0Tio^r!"&h$4n/6IUTTXQU!)yf@4m|gSQj4Eb0&d9Q@KP$,msNu*WTghCA[Om:Ac32`#OS*JfL2A#?SnmkgXym=6d2^=)5;8-)Oh:bp_:yQYg*$ufo#4%zN^b"0O)Zwc^{a{Ynqae&?xy0OB%uS/9DDPUpi"I#&^:"Iu3I2DDoT7?4n64r<)D>!P"bG+e%H~Bf=Dr1H^)|w<ZHC~wVRc<?"%d8x]]a(642uX`D:=m#ppJ7%7G@?=?}BOd>9}&|S"g$QRg>3h$MN2H5=lr_>Z@)
gqQ17:%aQTW,6AHmoWiT()6oDM@j*Z;sY$VGAvHMl<(;E-1*BwrbRk*[gK@D8R^E|AU1(nPQb;g=O4G"
C6*PJ[V5&8n[-9xUaW3W_7>vK+4DLxWuoR?/8hv"oa#YJhr#/r#B#
Vokm9SgYCWR.d(C,[;+c>RL5DwY4Y;c3dv#=W{Z20kmts7];686;#Ex68h"uP}NQUl[4J2_a-SL#OM!!IxG_hygsYk3!$5R9v0Rc"}km@4GDT`0LEas"7Bpaq/QK/5Tec%Mi/B-HGX$0L*!LM$gN_N8g[_0ldGB=?ye2eB>F+LfsW@Fd^g^H3S./
di|_WvnerU=I~iFIkYh6TY71DxYX3H|KEjDT@iNFSsgok2SGSwh$k+kx0?1SAR%Ca)tp6EIJ-OA-5f%)&tYQi0OhcC:pg!1ZiHr3ptu@/gr^2.(w/kj4T$"6nhcJGyi^BfU)o)KHrq&%C"sYHn
MmoyPS"To9I%5A=%K!$2QKccvT!wBt>D.(&Ci|
L6:KE+A1@=1oziT)%onxA/xVsZY&pu-eH$6sLv"&&AQ1|RRu>U2vp/K;`jH(+X4ue!k2
_6fn66J5GQtw>h#vhQL"&V3O=fk>DBfrNlP.i*t0a{+Q-sA!Ii:y>nCUD4>5nA#?p_077Lh,&}2=;
TeG:==waquMmbVkjG<;-5(up:uHfV~*dfb1!lY,Ak{Jo6^"R8xoxn8DmY9;PmDnDdWHg+HOb<:`~?bL*;)q}@8#f,7_#1^DoNG@kJ-]C@]M2)Bww;26pyC0`_`P{9pHcvErkJR>#$Y;-S<8Fv$>TnV2ZC_TiFapo%?ON6K/JOk8&svKa(P=n2Mojv##Xw;<vA*(
OX0CTd8[I{%A
TtDc{7FyK;&TRLVlhc2N}lI`Z
~u*8`%EE-L3Xft)^3`EX%R("`!E@|ZW(l62^aELiwnd+"V4D4*cg5]~j+cPZB<UG4VoSdcauYPy3_Z5&!OIy"G4gI+VUsuu22/mN&+aArB-+67<A14%?R7(UFdh-.IG0I$I#/p.<0lx4CFo,P4.T%dxn+Txc23p#l%-@as`8}/tYdo)fC=idejq(SP1svZTnRZks]m!Jrn,o|
VPO<`Df-ng#l!?"U#pRIcM."[TeZ,"{r-[s*v;[((?jR)7?0Ih^sL-ync[RG8!5t|sM@x#5&9<_0$P,5l]$/AG_T*A?m!#+:wkG7WtQ=rszBj8O^GIz/)K}-f[sI/VbJ|8wD^dZmW8,8Q$2%<NXw;-sQ~AjZf0G9GgR8hOm*r7q,o9O%G8D:AP;Z3NHt^CSbo><K
;cRD,Q/fAY/aQ4_UJcA:oLq9BfhTGUXl]?I8?KA+R$2~f8$G+
0HZf@9Il-4-iL$6/DrA;r!^/5aMaW>,sTWmG!K*~X)qhYxt=R+$;8Km[sJ@fCVt!"Af~?Im}5tAo
[.VQLq|Ocx4`j[S8cdkwN_Nm`)sUkNoCG3-c0g@y~N2AP8p?AOQH/=C"XoKH7Oj?WGE!FQ79I-JJqevC.cb+!3.8g)~
^o(Ll4A9,J#FoXT;`9[xqDw2OUJ-SJlt8v+:1Z0og?5s+I%[JwTZf_ZBVROZoNq&Y$9:|oGL:-9`<9a=<`fQ$(RWtf!5-Pve.DqT+ro>hRTTQ@;.s(I_A./M!hdk4%~W5$luy_c%<^L8ry@L4)1w3&",7i^w}kXafv__Pay%W^o[9sBt&=mJ.Yejr3Uppfi5|@0<&4PB3Q+>C:UkCr6i<Q=r2kTfEIaN<t8dd8FEZineB[-/YDkd;Hao~AuQ3P1OWIY,f[igm<HhiWG8]9PVeFa-*-X4y3ZhEpb/h%N-_)(2dkBfg*5hcs5QSO
qEBqGCUDG03j#`:<I.M(gO4Vk6wC!}Uo.`C1EYm+3Mr-6Clu]*Gp4,$Z2.E%^S.0Q7K/C5h?U{pqb:S~<4+o4TY.1JgaAvvhD"g#
5Hw2cYgo;Ce9`ONnP>_tDCq]i"F8dIf+>IC%9i..E3?^iP3[@QV!()}i%niE6RW[>,.@ehLJ9BeKQ7-qk4#T5ab@oinp[?L4L,gi"J_*N^pYVE*,32;MIIFe1/>lEEW[@#K`nRM>}l`02x_CV,Z5r@MpvH-L6$9GB16]VWN[S#Kp7>!XGQ2?BJX[32!heK/J@]*ToEY3VgK[i*+/(W:@MBcP(YTY.vf=nR$0H@DW`M5Wt=,DOJLrex,8LKA,+V!S0YT_/$&mPs92@Z@B5k.6h`H[ONI/a4l9pcs"g+??mkmk1(CunhOD"6Al,SN0p`X"N-mnvF#?Q
b<O4#"+M>H8IW&!v*UvYI3!+=C3:@_5%0
V)!P<aln1-M^-)k!:@5e;uBb&ip#/RRjiRJ$NJN&`S`-emd`>HVv8QDXixaeUkW+IID7+]WQiEq7r?Jwj2_SQ^:aFv6[xZ8IRNM;eV*I>n8rVBT8Z]G3MIw-K(Zd8f`*P<=Fw$rhiK`-B)_-`UsCH7FlEUS#`3[]^Dtm$QP[m,k"ASl';case"bs":return'"ZuKrbP+^.B`ofy#+N1ZM=3K$)t/G,}VQ+K74!@G:GuYaOy0Uk%/!8MP[2_kbq;Y$BaHI?k.M>k$++o<!TZQdT"cd?D1+fC.&sRW/Xo2}f]E<Fb&FxCSw(zf(yaizwwPEI>w#W7Gt1%P@nE_<l|I&E5o$PrLEXyJ97T<KG+"xoF<JizWO
b3zna!icbk}dF*nRYTGlB>Piv-TB332jtWIZ&FN*@Emm[4{G.UVo`6[YxC0"VLMy!J~1e?Zg#o
%DlKEG[FQ>62RZ7@467;IfW!oqs+;!$Vq0tPGa2CbWI?r_Nu7|2s%Uf{2yZB%u7TLooh?
&,Fpv(f@W?5;&qv@;g%Z)kLw?d+P
ght/xI=ArM3P,YE_"Kk6>7Xx,3/P~U$s,TGlK<CX}`ix8UnP0Hz?e`$
iqlA[=tw*wml0<Sp5Hi22b*ue51ER<!yrBZ!Oyi:C6.tdA^k~M91GR.WfKT
|r_K)48MRsf*+&L4Z<^
FkZt0VvQ&6)Ye&/r_/Rd.Hu8z+M&4u==h,;xf!`5<jp+:n@P&Kl<@w}>UtrA
3+,-R>0!uidcmw0uS*^zp57cYUVhA_t(P92LlSsY6E:|A@P>7rA@x|*xrI9B_]%I/F8n
rj;l{#C]ih}cFuRm5+5@H<a!C2E6(b.C$HU>w8Yr2ds%h[G6L;
l]Q^^+O2eB1AG@TueqF4gOf0Ngv^FE^
5B_bB>H1#rK,E.<<cD`|Hc3+7&^6&=U~XU;JsQ)[@foL"o_=[g8?F!6fLHRiQ7]j-@s=)hVVA~OF4sC_"/Q:+?#tld+o()0m2/ePK]7Qsi;pij>/${pfwA`J1Z#GQlV{]u`E`=W+Y#@ZM/08g`ufC%;%_}c-3!%gJgFvtM^7+"gV;ubT$Qo}wOn=YDG=,k^~PvI?o?ndW=L#Nbs
A#b2(Rq6)=P%VDytTIJUr&b-!1!U/o>m&T*DQe/GS;`|2!qq<VT021n(U1;,j#5s;(]-pVBr-cYMWm5)eQT!n6Le$XEF4NoH7xnLioX9s2tnM$X;mhE%AN$NP@thFU<(/61h%+&<BX=r(i0GJ=xF@4I%nto7uSwX-ID>m1D4lZ]T6T3|E}Fikbc@8D5luGSy%m_f(dRSb*>
.?sBUL.}#"bkby:G=)jrQ50nSEH3=W"@tpe?W.7d!%&rT
FNdi[!150a.gdL_Hth,a$.]@C&xm/:>1e!6O]3j2gtN/&glBK*R)oWp!LYc$kx,@r<vLg2"Yw;7"MqPv
mP+Lan1;`O5-#6sQ.bJtDX+I8bFt{t<I)Pwl1LaM4&)y+CT186+eKD:"69GK]8.6n$`sg1TkE>TL,M?WDdOJ$:@FboY%otdu>E*utSRu@2*&XJ@NS9~-)m)-Cos#DOGwbPmSn=^<]X%oA=S!jQ]Xc9}pt?Gw"RT9!don,"F/omFk>WIm
BZA),Rd$p8+vjX4gjhri&B6$^d
g;t)&+Hr++DZsQ?5gJlm7H9DV[s^.u
"?LA5Y#knY8OoXN[?-"b^4Em-dZR0BlRb(upaD
I0MsEk!t8-st([2=[MaWu:`-]m!k/.rIW=bE)"Z*B%AAgh(OO8ZmLN<*(SBbsZ[%F3rYV=g)g:vFaTw&Ed^O1WsJcM2C5RkfRAEl$W>)z*q7yAcqK/dhtdj*+_"lCw}1*@McM(w(;5LJ8CWbh.CPmDH6^GKV8Q/-(_wEoG5I?Wr[gIO]9
&Ec98Q`oip&2`p&4nLF&bsM-D]"KP?V`!Y09Bmc5sHFjuZE@!,{x[y;DOE94ROc!~Nto9
gClEPxL&/u}E-`x"<.LPTD%;)>udOH7R^&/b04J(TCKiT5q<fd2I1r_Tf85W~=OY(l=rHthfEr#[>23Bd#f9wqG,a$;7>_6S_s-%6d}tianI[+K5jB#-R^0vVi[a3d-sb]ey{l[dCIF@X?,A{!TiBspCB9lcO=DrO(lxa3B!&f`,$Y`%V)n*^_wJ/n@aaUz0.4~1[Qu8^!q:Sd6e{fwIZMN)d9iQbRiit1VD=@V1#N+G(
S4howEsXSt*^Iep1AAuxYd6)I6ICI/te0!:_bI#pUN
!,oa&m<tQ#,>5XB6(}uBba^JP&sqjraS57U[&{dA#g]iT;l$/vR5Ka1f(
9T9pm#l/C-nn$-W5
Z6ZPz-9DuQa(aH-W3:L-Jm"rF;G<k*n<W&R<,^-Rai[C5!(4p$iD;nrwsO&B?!m/8uG<T<JaGMhPJNoqETag)[c0ZZ.md@^qycpBW+///B9ba!yXjIai$@#P{5za`&8s.3?_~q1Lkl-8#/(bwG-a1.?IW)*]Z*8)hOH3Lu2NBE{kq`r79C"b&&M]/ea_BB#gc!/n5>PgN<q%^1/70K|2}KJ.^Jcjv94A
H!
db-]yx8Ag.W%4k^wj:BT)P/Pt^`f"[zFsUGO;+!Cs<oj-8DK{ld9B3PZf7SuL4g"|td4+d`!-?.=`_[HOT?
x)df|#J4/]))+O]ceJ&5<@o
WnEe~%PCSu#)fN<.v"#NL`sao:<
|3u0d:`mf[pWRI7S)):DB,*X95r2u6Qy1#RxgjCgOWfm~4os::@7nnh_/K*hGG.sYVM&1jX&gN[3pq0R)w"bWnEEY]Zbpdu0%rOgL)8t)VW&tb0O)M6)bLPNFb);*g5S>"(?1CG31_&YJDzhDRG*s[2m,1?SV>{D4<,<U5F[EEk]]Vhq?1v["bWUT_Z
2d"7
`:e#g;J2OrLa%=XWfVMIs.<=]=ok=NJv2*j0h
xB`e5H@lc-m$l0GY*5NqZ(bncw??he*`PN/)%pN^"X4*WYAt,MPVyt4YD];q)+A*bF34BPYuR!e@16=(;]_m`buC*I)V7SdGf[Zti3lj-"QJ">1JdEQ
j[jjq:9n8Vydrv9paO1YIF9FHDvmH9(gW!Q`/3htyyX)c7GQx,EDt[4
RBn&bm
j!6QClmmaeY[?vqEgM,HS9^"kC)+>#YZl0ArTkXA%:jG|.#.3GzZ:a*?P-fs1A`4jw9xh21A:nJH!vGR-K._"yKnenVx
>.VxWtuR.R,ST
87>~*=*U3l_)5!e1Bb>yj*<M2$]s$0Jpir+}h1:6`{T,4|-0x^dd9$4`-8YI:xmWav-p&-;oK+"d-a*6x8M6I0u4DiVRREA"Ud5f[Bhcx"J8-AS^m%M[6zd(<LDApD*t]QxHp-1X+&(/DRG5V8lA/Y;tW*O=.M+A<<E2Oa%TA*xx2dFS<&(4&j/E,[bSC#Lq@#Qe/t#Z$8/!FCihUTj_TKolEMq;XON62+s`Nq0b%NQJJ*2ew3Bm-~N6Z#mk:8T.4IyKWesLwD';case"ca":return'#]^Kk5IAP?T@+r&$@r#b3"{E-11tg-s^`Cm"nR_[R?l&>X.be??_`SdwaxK*&OI)oQo!VBVSQ_li;Qq_p0uss_-g8k/c<_6iN`)y|eX"*rfmqe3XnQha.`]Y^TYqvK2:W",Xanl*97h/`m~2?vvs;m}"Gmu%7*f4RWMmmErQEC,%z7@*MG&v}-=Bi):4,tFtfS2#wb6ufyWKENaK/r&4w_cM-M>h=t2W[N,H@,f6a5Tqkd/m!"a5j5%b.u"GUp@:7SOCDB:B4NToN@:$(d2l@RE$#CfFKeKC83m8%sll-`=EiJrPU,E:ej~$4Kze+%PjqWVx7hj`tm==9,wOy*7,:ZDqSN.i""21^UoT,)x
+yy>SxC;frTj6<e@+*YYRk7^DTT)TeVyaMhZJr8BcWp?qF5G/,&dZMg,rp:m*)GjHx_5!68vuX[q
r`AN(oB@4vQzr})mdwe}PTMmL8SsM=M2#TIuL|`fJ"K3jzOTP|1vO4=`g@2sB{j1
bEy-;w9]3L]HT!"4}S?kM!@ySqz=8hx5Y&1_;!XQqoz[A/Jl)q%
%iB0@,^boHrh3i~EBG]#BJJ^pq/E)k0xU`Ow<,+C]V<>!+4fbd~N5=gaHCo(I,1Uc]dh}GB@]6Plu;<2U!4d7SdN"(iuqsS<0/YF9OQK;nE<"pq8.I@v7LIOalLYaMnc)3+IE&uL;/ux<^64+B$9?]J`Pk9Ogkp!{+b:bZX&HjNQ>
|pKWHk]IM1i-zLUQmoi]^$fm^W,_z-]Qe]+QL%C-VlGP%2oV~G7_Q7>^*tY)-ab_H+~d)!j_Koj>%rywbUx:H332X#!o{L1]AA,Cvk]jwhl8OBT<cA9oHmm_*+o6^O(Ss_Is)B-srwj^XqLCl=3dCw{M!69n_-&u88uenA?e(k?<u&{"Z#;d|`gB<-[N:kz+@js$@`WjR7-/-&T]X"f.nq<4g2}*O5&2QuGRxUB#^*m2PCDu)0[g3.%CmnJ9;q.m(IMHx]0v|
*+w6"TFS
VO3TaM&u]!Q4>b;5_)]~2rSO4(7SXD0l>Z;:G:8nU/vVq@,"+VEUWhm{9Gmz4[Qs<}B?"7DH9
VBuL6NogWa_P
kdp6;b4kP0&V@^s96y$+.
9%
o<9iiM%)J8&it}7NM_qgYMlhGP-F7I7%-m!6*<cg=`&(S<Me:X9X9xX/fei]?S*9$x[FG##8W&V53DMGRwT9J`duQ;cw$!i0*2:)grsO2Mqqj"y&KnG6
>!x[i(?@4Pl&vlsNZvd>z1@8CoZ6Y=;D:gf5vii[*"9>BfVPbb@[|Q
=ix1ufp;o"yl=`SZ2>8`b]NVmhkC%a,1q#`u[^Mz,3xg"*=8a|*FtNCyw9[{ZICHpK%jP+(Uxzr$ON!9qWxL,cQJLZnvge
*7x
GE/S?MGRuKrTL0-T"#Tm6(p@5Kk=J]g@19.@l))y>pnOU&D$aw?K[d36|=-e5:e&#w3E(q?`YDxp
iqL3usfn#an&$S"rmWru3o"O
IY)0~CQgyS#2S)<cv[5_d6;AJ;rMWnkNMf1w
_cD6f&$c2|la$]P/NB^<(jym#hGJ
8ZFk
+lC4BFbEk.<4otrC=zjA3Wcz8BhP:cR~>WV.f%d*_*pyJ`G4,N-}_Ag-O[wL^*G`nIjsv@70n6:JdIwdVFG*yw3;$Q34DCO2-"saO%p|>}f*<5!H8)@S7Haz7CI.NEBvvD0;&lBZZ;Q[ndYz#75jiYV(vv+TGBT}5$1_:kK!CYrD.,e*dG)/>zZl%-Y+KD!_u>(=2q0p<9G@:WxAtZ3"ge/,(/T
ows&PE<~Bv>Z(t]Z_(#O&lV_?q,6.~1a=w2MpPNv+BpOeUoe7O#$KvvY#<<HB!y26X.P$>]E[BA9$#"nhaT@5z[a^pIiUFJ?BlP<?"!"aY5h4L1?g:L8qHGPnz^+w]jGuKHTv)qS%]oRl*L4ahGFnMr-#iV0r%J_RN,")5
?0/N
3My,d=Xn@[KPMSP0-C&W?lH2-4<YQ
b~x<_;x&
Rf`*8dD6{j1e|yUX"B|Qd&9E,=+u.0+a=gs@.2wt-T*U2Q)/aG4&vqoWrI$Qz?:C>jK5Q!Hv~ocW);Qa7G$JB]
]reEa>_[Uf)o$ca^@a?(tgBa%qIt1gd+_PYXJB$_rpYMvZ>{f1tTvQS~*EE]rls6KE
[nhwXLavI*=iIe/f8+Kj&Q6/YL$PfX?J,+4.f&s.zg~GPZot4E)xnz&tC$`D9WkqA,$<:2S8G[Hs$f#A}mW?si{B$ewQZ_g0VNkklZ=psZ$V22iA69pWKuV/xG{J/)DxStJAQUAl{Sy
%c]DH?M5}Z%]=(.Y1$wi($I8Mba:SBsegm6N672n{Y:A3;Gm/
Ve9gl_,hPT61C(Eb2WZa~1tQl(e7VR7YABb`P<GLqYlSGH?63xSxYjq;L-58^W]T:(Bn_NhHhX37_Qm)w524R3b<G]]1Amgi2Uv*E_+mpov(VHL1<m;958ASL(9aG6BGR:&/F`k;v)>"q;[3SH*s,F^;
4Fy,g#a{aUU,st@/xwJ8hlY]BjJF,b0Exi5q%P
_JM=2cBBY^5U3i(8IxXl>M)_Lrk8.S3ihuMtvsgad[I;R1d%[k#=`(6Wn4beW4IIO!rWpo]frh220>|*]o*OKA^X~Zc`u].bu&e;SRHwj%nhf(
9>4*dXxxbcUyLTZEB|&Sp{+8AQo~aat=j_0$iGh=RdNX=:"DVU`%n)s?TMsbm6G+.gUs8NR<0"R3ISaqLG7CX.dwpu438$,$LGY"ouW}/H1u8f94TF5.]cxVKYstw9k:EI]!F.lW(-D/22Pq^0gMq$186AJohJdRD~QiIu7|mWsvSl@Y3}nUqQt5I]kuh0Q!0D40Yad<RP=!aK^y$&3MN<i0yM=.;tx!-Et0G7_>le8
UZ0;F};R<&5n[N4S8};HoP2((
B?*OOr;x(IOj2zm?
!"vR$aOVVjPrh.w]IdNsjrGP]NQwuDM#C0V$CPASmowue;_`2QCD;]Z+_lN&.![/-5rby5U7YvI=#IIqU9s*{O#pg:c>wYB$7`(*4lsh&RJ@4RSIQ7UiTAw-k@}C=ZU_j]
$?Jm/"5a18?A`zKV6K1/

:2@C^N)ElXo=y*8;`26X3}3AH.QJ]PqLe&.b$gm_O?O:M5UgS{yM=(9(3;fz0T;^90CJG%OzHweVW=C&sjsV_m)NKM,:),IO[3evTmx}=j*itj';case"cs":return'%]^@)bTG2*U!(ie"]Vx*:rL3#hL%-fYdLC,NP={[>]M<^m`,y^o5[M[;GusYhe<ig)$Uu"l@KT?y+SB7oTbR_l@a9<}A]ry7*0[Z4M|%nai

:M<YB@U3^Gt/Q=0jn5jF7W2jxrDvk}7|k=B0HDr1tY$xWO?:ya1+jhmp$elV]$cf2>$wlik4/%<)JB4`/!]15;,n]&;pBWMjJis5GElp1YsKU6KpX)nfZ9OX?b1<A88o-"k|chw{i
e-2ZKD-,*iK<J+4enzHA1^5#7::QD9p$,f@5NU^_t,-~>*7WZm!~G(G#(>iidPHuOvFhdGowJro{gl8nC|#vB4+ap9;^lL#n@u6%p(<s#|Z=6Y$:6aM_iTX-XJ6]Wc2YROOpE?_(mQ=>t;&qDy8,2Ggw@&sX<aX_*QvQwUH}u4M5l%`:MO/sp_=Q1k%4ps+^iF
b=o<e-sD~V@7NjFM=nH][ntw<Olr"v9^[XrNP[1#tHt3@dmM_mgw4d/]!uFCoM3]xcoGZV*1zX@$E^spX3y5Y,f*yrNxkLU>IKV#~;KM,kFZH)1`:ccNKC
5dm
<N!IMzp;se<Ajlnn
<N9m9&Iku7+axm,`%xW+I_$`
2S[jt4rKbQ]~FpkZLV[RwA6o"!7t=*2
LNP.So/DF#;cviy0,6lucMI{`Sn)t.?B>/G1b{A{p~p,qm!p:,huT4,YDG>EG^%rDRL];lO.,3&
bn-^Xnp.^/5F23_HUm)7AR@#1K4!-<=)%J[oO.nc:P"nZ](pgo6h?^2bm,Z;+BvcR{D0<O82s=Xq1LuK=e3rcE!3wAk#uH)vCI05j?Z+"<2[(35D4L19"MR"VR6/W^0VT-c.Bua5Ej*}v$614/Gb-KQJew@jo3s+umwTjrAtgc76l6&X!vwvjfAAW#X{j@@>7REA=6d[7Eaov"2B%hxo:=_h$DI
NG#>vcpKxr](xe$3)[O&E_`yf"1wP]*#.@]O`[](,FVS<N"k]f#q3Nmyt-]c=6u?5N4T6XKypSiWZVO.:UkNxRZDi(%dnr(eV(*iuf2q$w_/BBRU1z"yu}-C5Yp!9>Nz0TX:LN%EQ0%z9;MLCR=;4./|G)`(<scNwSUeKY:RM>l=cFCJpF3U5~m#Xc;Eq;wSk:7?_&FGMfXC(>S+[#FEi`Q
OBGVA{dEwScvc&pGNz3$yigCC_ou#``P@KD$Ln,U=:IqAsl]<dm?563XYbhg>{DA<J%c/|3{LR2.O3%6$_AYaG1Al*X&@Scn!LJ)x$x
7FQ<J/^oFfxdRmkp:g0#:CQN/k$S#a22yfVtTEwx]jn|)5]TO|&]u(#sGT
yE9b6^oU0SCe1IBar,~ro9H7@Mc`Y!)-_drn*.#Nkyvyky"F2]>7#2|.r2j!o"m3lL%]bnr!/d"#Bn<O?^-&[>Q:KSkxgpQg6Z,_iN(2JK;.RIIi`UWfWiQ?OdqVPgdmG@D7Hkr^M6`7ha]f`Vd)?O
E@e}N`;^$]"%RN2cP9F<#iYpdy6]Nax+09
bt@qV&P)h@13I=C8@#OJ6aw_P(INgL8rKizlIHv"ZhKCeWpH!;cLHlgpRBB6zQ)-gKbed@
(r3@l8Tzp~p#PlVcVD)%/&4a6)i8?4K>i?/_:|
"*k+?LFA"y38yttkA^N
Jv%hWog?`Ky7lZ)!/Zjpe[LR3u-u9x(L:80hZ!;o1mu&y$[vpK]VTS?TmlxO([H!6N20i_s*pAf6"1Il:cSP@&{8Kj=Pv8%T=t$t~)T^V"c[g9uQ(toIzty`Hkr*`oy=ZTxf%eI(""<-2vqB<,{naeLhBgzc6R4fpbCI.g->ZGerKq|Glp`cVvwbChq[;1}s_S9Y}Sb2U?KiqiM?IO!y-egC%H?k+G5ow.|4]Cgbk.x#R
5pD>F@VEcd1A6)"^vCh>4K4;)(B>4(vr=>J_irEdp7YKUmKs!rCgU-g,hK)*4m!gVSZ^1^3R<?ZC5WgvKh8dcN!:c&+VOmmVu8+8(66Ql"I]3:A3sy6Y|j?R<
;i,)7:}UW&_!S.qZV^3pPf/1qE#vLo6n]/GY4K`#eQW$]w0(2h8cI#jyX
hi8Q@uO:KE&Y9J-a"-WBu9JN+A,<{g[&|-`i+
~N~"u]shn:@l:0xYWb,3G#.q#!%NHk|kL;bjzC"(w+h9zbW&?k6!$B38F7=E"n+NunDGTdf(.9Y!S#G=Z-
ZcY<K(1i$9te`)heP5.i
h_QT{@?d.$B:Z2=w59=@F9&8A!cZh[mAjqOyr+2.6W09zPN!dxYDf-Y.TBFbvj]LZ41a^@gwW,/t~v!O6=Up$u@QUB%fOv5$kwi-.U
X`]`.g+K.+5;AJV.23t(>QNLaF[^c|`Uh9_siJmLIHGN3ng%%Pu~_AaZ8`F?wby4kLsjvMrI*Kn+LN-]#2O~&o)9J"RH[DG^5AB-<skcTc[PArgFd<xv=O=2+[Bjo^$T#&GzE&"J0hl%%<0F;|CV6S@?Nk/8D^P8vz<s[fvE+uL;m4%y].;aXST/H{knDN.37I(:^M7UWkn!<$ZRS7Wa3plOKap.er#Bc_jVUHROkzl]MU5K3eOOw{=6/XbAjO/uqq&`wK3U,m#kH[3lJCpbHzx?e~AN4BFH[?4Sy2^cPT#S<}-g5d!%^?1`!an>*{W2>>%UyEgt,MD!<XK[2jVo^QxjPz7sL(d`pf($?2F`FTeW7jasZ1<VGxmg#HHf")5K0YmhY9pr&mpf2Q+G;Xt4&
]bL@%Mfr%jgxXh6GK$$uh{2@Um<$;):jAHMd[Edx&".$mHQ:8kU3uT^M^VJmbC):@SZ~5S5td"9|WzcAdPSqRjxTG]"SB/<*!v#vJ~<`AtGoq[)GV6Y<Ad5M`C!E<3kcNr$)T%*;i8QTQUI_uMN|Z;;N%bC&dtGI(
rzso"K<6fHSW]3?l+h)Pdw85&j6r(WV^aG`v3pB-b{>*ddCMoQc:GGpC$@#42jxb(CN4sphb)lx=e=wSN|L~>N?xof;VoMc5O7R?<P]l+~45bDN[!YE{?zwC?#Km;$6VwUY;Amr:p/t+Wev}_T"^KFq/F[@7m]#%rJxwgn3}c~,c`dUWI1E2e;IA@#$pTM6"Y2xTB)62BVQucpQ2.4S+lNYg4IA!<L[8*/YKpu2#qf[(=}mBg~^#-~E}M5(`p4t`bc_fA`PjX_Fpw|S6K#9mCcP{794
#>!*"AUw-
p53R0*4N9X4N@Q4^^x?u>gr.Azj&/&t%M[/vFFa27W?eeT/GdEk45TxF_i*nT
63hJQnUTMf3Tuqb[I9:0H;>@cmGIl0#hpl+O5ujlFA[)D&0"6dvsY7jHl(?KH*xxirH,4$X.42DzQ[0y:ZwA%@2{.)dPz&/ag/n-D6a`t|g1((Pi(9<o-(%cjx3T6/!3ZqrKnRCg]}PIi>29[*_>DgI~P2Zd#|i-5tyM?Q5_BWv3p3:;JMQ7wUt*4*y&dP';case"da":return'$Zu@ibTDI(l,oo=#<HNC0_BtpbD$Klp`vB"MQrkoGYS
k75#q/=(`d#=GPlXsb8I6u-sf(!Fv7<ZYbH+=W9]=g=;^T+3]<o*Cn_dHJzuJm,b@ZkDEc3HHCr<H>O)Yuw>*k0H=q
>3YXv9OHai9W(c<pAxnu.=TEX{0_C
e1>Vj^K^Kh;)B#tudY
p10ou/3qr)X$)kGye+
z(3oAId3HGfBi:POwKv7BYE,bwC8%:!Fh$G9Td%P6_1BrXUO7%:Aeg?nMTXGs51Nr,f6#8tp+U4LOq"a6q1S9Mv=k}u4-u#;=P]j_6AI.~(>IOoC0g!ws9L/.7G.A=6[3HDnyvms8#]$i:TiY2[aa6^b]]n#7W3o@_v^DV,[V`#AeS9tbV-bPuN7iN)0l#rYefs=7o&vyPq.tT4X_=W_;!K@R#I@#,S"9J_:R{l{9m0*5A#s-~.N@FB+Gh"jRmi*CP.Tk]RQuGp42iw"F*g0nQDt
.;:.dR/AwfSUEe18&S*$:iU75qy:5%(h>v_:OGjd`GvDE51,X^K8%f4.H&NfVuv*=A+YS%exx;&e,B&Ikq1Ac)2JurWO),a.0:ai<]8GW_bZBKVfxF`k[G^!_$jS6P7I;2D)h6-M.
O1PE9&iK/K:=oG?`";[sVhP`K#3
zKzb99;Me-Kuo.1BzUj)$>mxIc~DFb*wgI:*]mV3^l7A>`VXEhj)1b4]o%%j{0WRyINN-u0`5bE:|sYscOxH[Xpr!NZMqW9*^Q,=0,46AhoONY=8+Vt&]d:G;uC59%RZSYnStLAWg8J?Z
uL_(f^d3`.uJL@{a,j:-|[ifnjoeg/fBz2l&lq]j0c_NZCSSG;7ffIm+jlcH77I%yufm#!Ow;;IN4kimX>cvzi2c{N/8B<3wbGU#/=;Mu<7`~/^C>Rz$JE"arpt;Y7Z)ms(^~C,#hNB$-r~#n+^NfGT3f^P?pNk-TQ.*>]&).`<W>odIL94---s,!v!Yr%F<*c{O-2Ak;7Xk,Lcgb=T/QSAxm%a$yS^#E@sjccuuJ`efz93+]*^cErv`Ca|dJu^YX-6AvcsLo$0?#N/WOv=cV+k%ttW6JyF*um?n_tzKA"Ss!+
mOm_1AsO#rg2W?vi3pm%Mi*gx?Xad[n~pcHiJQm3a]32Lt^r8ANM^gAYs.1>yn`?9qHaYhE,y+B}+6_pPhA2y*cDxZ0noU8KXC3
-lW$+ca=)7%Wg[L3GIpBwK44v6hHpji4u"QQKukW;XXSm{&k^ajtla1~Q&0!gY:[o=F<d@q{<KfXMu?C^r.Y?Sd4+G[R3dbI=HdkEjNs9KfDHj_Q<T)11cDGhUm}8gNMT@WN"K)YP9FBRShMw$l?o$Q2,/S|Ns:w%v*)*ZtaT^Du%(e9IdUHrb3KDd*Wvh`3[uTJ%xc4AVMG*<5^ig)XeWY7_p^vtnPX.JV_steQtg`|wd#b+D/>r9Q#)[9gNZM|Wd;/3I17V^DL9_Oqt|*``wE-P`R^H_&@D!I&w.>035NY$^45m6w24jgCagAH#)(J[egTHTJ@OhZiDl:lNJ__DC$X1aoWinELK}!mx(&{:Zkzhi9:Snpb:+n<YyRzpx?8R7o*iz%7/XNt[:
6VW,SowqE"RbD(pT0q,C@2)23]{k4;ZCViF6UL#JI^t[]3To
;eeQ`$L-&FlBV.lYkp!b/ZFYf^<M({6O;)p-Mk+xT&?DT`k$f4yi%_!/YOdjZ]U"c(>r=?[[JAKm#DKCw]T~25LO_Zvk5x)^.w1sT5Spw@<OLMOZ>oq=v+BpXHF@X3H?SNExb=`^5*h$5>.)xZWOBI_*_>p1qVOr:p?(iGw<T/os]tKt3nsK5B
D>b2BT*paZ/vBLD^ul(uaH(Q/x}=n*kP6LH>.rkxGgCL5je>z8F#c0?DF)^k/,=;dr,?8cRw4M%mp%O3M<Vb5@&Y]-48B4(u|[2=*Ma?L@/k!9S)1>-+2#`]WT4A8WY6MlF2M@??1jxGz-hHZe)
SNiK60OvXUS=qR3wsp^ETb6eJ&3%Njpc$)kwO^vk4WQkHD9+E<]LdAB:L2<*VD*DTj]=w?.h<T~kSLvO@K:XE.AZW5EE.T3Oe=>*4DyTS[pBtvZd:Z{kbiingY-T4;2FR$PI|b01s9,D*YU&}`*
+feh(9N9w5H=BaC>9s8)>1J<3P[/HNaO@2/s`u/tPRZo?
{Y9i(k9Cb4}uiTK]^U^:{^=Epd!lV.[s*q:(qJ$ye;VK/u%kf;nGIB3iWm:O?+F&<Vl>::xpwL:5Us`>&d5-gadZ{2vKJ]`;d88aLd4,<PO00_KF{)AOz9sd:GGc}gs:<<ebj"euq+l.iX[W*9EJ_ByA,A`pjSJtnh>]R:EKXx@hb?1,oF
8I@3]8th!:Bb9VUzkq0@c#TSgw3GuL?_24u?>zq#^,%!QAedB6!$fkTR]TxJ`?QiD]aJj-ky2-(wCVS~QsD*e<ph=`T<grmM[EFY
}C,_lL:!<p,y.[#KS?Jm~)bj3LvW/Syo&er;`T:F%CYTU`6:KXFOG%YbaA<4loeMsx55^":G
1"6_"aOM
7t~#n8uQ&pFNZ&q/SS@oP4}RIAa62K7e>qK$BDiHY$hP(m8T1,*GB/snd"#"[2C3V1BD`TM3He6KDCnN^!.Et
da6K3-c>g8H7co
@&bsy42-=j/tv&R*g!xg?k)]yB.O.I?
8g6kMXXifGKlgz62JWbYxs^9qF;|yfR)6|/%ML%]26]B-
OsjRYe;ihOyLpa.jlo2*w{yCof3*h{QMXbel?nI5X+6*^2"yk"4(AtS!_&$EtL.{rcgf?<b5,<yEv_c-82I2rYvN3*UD)>o5';case"de":return'-]^@iaPp=?SXcj"71"ECe/UDzd`YI3!J>KuUck]6BmET%`eC7y9+fKog-tidKM9i$;dM{<oZfs(H+a!ov(x#s[Bk[a"l5^%7_e9G?t3+
At@NkyV/$s[oli@k2Gt4V8[M@fb&a7`B,veawjC/bhe"xt2`<6/[t{b=<Jq9`:IluM=HJy!4)`]+Y#Hk%zAC!?fj,MlJrK<Jy"Gg[13$4pfY.V=(<$m]aN,pWS7f!lqwuEep?*(;orLwODd!iMIrKAj_YDI?$j4]2P(])uafRWV7[Ml6:3*Dwh0jgR=:+up?b@<m)Wb.Dq@(L/Lmw,!1BPyg#ee*=f2z0oU[P83i_44-PJKJnS_GFUBTo@RclHe845pg<IqQV>,8U3bVS^-HiOjf+AR3?rAmsBV<h%LVGav0_AqM&%:N>dn~B}lDH[i"5G23x27pss!3nHy[&+f9V&MFxS14GI#ac0q&`CUY20$aGkEG7bH}:5vT<&Dt_nQn"ZFC=wXQtbUoV&xq]~hq!$fT(8x;DNxb)A)S>bnEK&=gsmK/BilmZN;m]@f*^5p#Fr`rF
r+L$ea2BlZE4Yvq48}Txm}cW_sG-V2kpR%tx":e>E^%2aDXc>ttiieEwb_boqVVGaNt[M=VH(-0E09
O5k8Wwf.>dgh3S[?}Y>47Wxk^.4^`+Zq0cfk,aUe<Q"=.o^74H"Q~+Ja45fn#)r[W6UTjZ<l|$.6P*+Oe>S:BL$RSd!8=hvV}E8=tc[1[N7qiBP%}!a+]`W)
Y*cYd9JBp@h|kS4ERAnX4H#57Hg+h)%;gm3<@SjRQRt(2OwvX+KjG;Jluo!DNFwbmfa.Ozs}8Cl(x]gZ&aQxi^_:<YLyX}QJ[
WQIl$FE[dG`M5$
4Jzi>b=U#,ZPulF7hQ>!YBH)y;cDxn&;.@{)pJ8>]0oOms"!9^|
y2bJb`HZ=3"
c#1GRIniG2J,:eZ=@ESB@YUlve}AEU.K5IO^p61:H5@i}F,KbNQOyP&I1>/,E3X,+sg/W;0*b8N
XT2y/Y$nYgAI&$JpHc:8v9B7)0IdZ7h]d#WO&$i6UxM^
K#>;?021)7hXkrQqbTrqx9J{ML0e4{cPB{T3V%F.l06#Dxnh&F:@cI-cEj7NT*J:<_O35BwA?mT>+kXOc8[=!*w~*&P=s)Y)&3XE@l4~4yS_d8LE*K]:"~0TI*7-_$Uj[zUjGxI;6wKLU;liiX::8k&RCe7jH]GHH|`5ehDyc!_UZ%f;1SDC70y`teFvk(=,$`0<kASeMhm(ufctZ>BQU6^I:8!f<fjwAH9wOzJk!Y]),JLA/aur[XC.tvea.aAh)ai;a/q(_6x(:SZ"_W"/3lER-+,kN5+KRQ%e%KI~Qmx|6>z)V85C-_*koq&Dwz6tIomNrwh36c.-V<>;"}kbw8<xYH:ch(&@SfU;]+H0Q(oyR34M;WAxro]x8M3N;J$b$VwP0W/vL8)ORLg!Z)6IC~cOU}3&-9UU?Px~^|l*]W@g.q-CMb8-f"_RoP2C
UQa9>dn?}eKXGUk@Q*7vbPV@jD4>xmlj3Ot.|fZ4}!jR1y)R:jD)R15DU1ycFLB((3>)qe9M}
IN!(YfSA9DdbnR*2iRfE%Qb5We4<TGc9(A-spkUVho@*]oBr,Jkb`itr?)&eT[f`P#}aQt"oWlgV+Yo3WdR>ap1<+Zv%+RkmdRyhP9np5q"y5*}%VcNK5;);/IBF7?8
*]7`SGQu]wC`Kj(
m:>&)gkAc
N>1qz$t%W2/*HatI8VZIZT|r5Ui1(r5%{4n8[jCv][:6zE5kfR@vN*Ef.[4o_x8m`@[eJBV`R%@Al;i+9Gi[doiL!2>?>utVo<-1*ozpaNI>g1IU};+rcePsl-9ZYpzd>`c!/Tn$2*kx|E4q>lT`#/YR
0j3l5o2V[~@QEQx/qy1=J#CN^0U%(N!),a!Eahml&!C.9`.Oe>ogrj)aH=$(I}^3N@4S`:^h^iu]p0N
"a=<Hopr-riov]>Y2M)!aVn)!.[^K*8Z1TSNX.qos7MT:
n*@FR<[5tSN+Xaj![X0iUzNdg]?_A;Jxw
5^mrCH>/e44v5024V`4z1l(|sL)gJeB/Xso6ELolV%uq%t(f5:
]GD#.lsSwW#W>QhUSY2R8YpZuUs`VNie$3H]io*_.mofr&nf4y1J)g%qei{GQ#M>V-Xm3s$i3Z}..>&N8JT3oWlG>I;D
!C#A_kI3.C+[-Uw").mf?b0k[DR0aDv(A*Lgx;oQ4a[wrtpV_3d7T))ys4WguOv;a]%YpV8&!j4CMa^=67JmZ]9cbS=N%Qwh+F/t&2w|QJ;tXq_
[>+7o$;oN~Q3eGtzZw!WJ{8HQVZ$GjKXktt{&}u8(.Qg0/"%&)L6fTT:4WDvP=u1>X3v=%3=Um
P"D&#.qP
Ym^PR)<[wB[g>k-nP@h|MNP["k8WGztrFL=
r(t9o;<vq*+<4BM,2kYp%T(^D+dd<|[S4!H7Qt]NXH:;Xue[").c>P#5faP?/!i+vi@Mm#Pgi8YQ
2f5Dgjpk,5m$}0J`5ca4U$~`OW0BjYT3dTPa!(mu@NumJxTxK@JX2FP*2O(`T>#cx.yunI_U5Zgm`6-cN:=e/O?I}u6y:]Vd-5HyZpEBY<^oX
XpgT>`vxs8w0G.+d~/}<JV!5T"N(~l3^I$MF69->VH5B30BMDgtz$LwsAC>USEm#O9$=MDK6`hBK|^_+)GG<3Hak[5Y+&tQ!zV6v0L.fl!5.L/#24p4ZW!T^7iO
7/7s[Wufz6QCvg4@Tcv)AM]eE=f-y8-PhjB;?MK!0DX9U@($)rzem0Hv=+[j9"F<>BzckVMoo^&c&=/OD,umc?:;9IWtsD0X61I"av=Sgdt!$?uV7:]VIO6X:>`<L*@ZhG}Mt#slv^t]sG8BJu<Ca>2ERAFSwVkcn/55%-fbt+EmiL((s[B&..B5`@nTK,Qg0T{l%+f]n1CUSZ59M":)6D#=_+|7`E0_NB,,udc]EBSj|@!][N-,&Si:m`q7>9JsvgZ$c<WF`*z%_kbNxYygc_d1qm4)+k-G~d[$"Vj>P-pQraQ0{JZRO6xu3heJhWbLE7;=w
qRsN?6oRFvrLV7L/)t:3.as>`x]v
7*qeaquNZ*I#RE&|k!PFj
x]^O@6OKy&Jl$mg/4t(p&g(5=N!ra!>@6r6]"3Q-Z&QK7%.T.w2W+4hSU$rZnK;?pU::F
/@Rk/c]7C%
A)EgTAQSXxXVR2s!wWS>3g^?1&zXPj$wC^{,2ravad(';case"et":return'%s`@j6KWB:$S,f{"*C%I^tyR&rFt|O:U$bVeP+0),aCSsa+yo,yV8^BqCqnZUx1hK!:30T
QEQ%#UAAi+.~cqp?wmf"2ku%vvt,2ACz"I:&4u9L(65bH)npS;8c%HDkaN
"X$m7GIKl>">+rL)e*[8)6f)C]o@>VGKO3`(R!fKxJ8f3J.MJjeSfXX%waT"HeM?(.Z??h3no^Qo!K&"M!M6ONw-(4n[W@>DIB.y4[Qm3RVL+jg"54!J>_=ChYk+"x>Z8<&BI1vV^`d9,
na0#f`t]^Nowt!o=Bfo4k36e&w$Ac+"1k9vO3ZzfGYvaoAE0#gqm:/VOnykL_X/b8(d:WtH(Dt4e$nP"}jKCaT:+3Y-5bs#!HdCJfl;(36ao)kA.
ApX.nA@*Xag3D}2wA(eR2HB_<<:&T:N&M~t+n
qPJ)BLgvse--3N)`%nSMEvf#JwYYNH);Cgc(jK,#q-K(I>&J+EB>pJwoT:uQ/T>PjWs{Z.:s4l((0&(Rl"ak3M$GJiBZFt%R*O,)C^CTq&
1Z7$ber2VL~v|r;Ar>V?~D;i+I)Y]F%WKvMMNHgJ03]YZ,/03?n].aieS!jEg-rOq9
G3#bA-&fIISYel4<9[!bDhf~g}UB3_184$yc-]FZ]XJ)@Z)l;X0J(<sK$h
6)[[M/C"-@fu0*kb,`@=dWP(~l{N{;WM{W{#_av:p3=i^7m3aAF=(]}p`hLR]W{5L4lE!]#eI+J
-B{h<dcTy[{lnoG
^_N2m5;uzi};(j&%k+mRkWl1IntNWr].D@Q
ic4^TtR9kFQDm<gtchp_lfU.[M
M=2Ay3bj7Ht$y/C9_7)@)^dmY#AuBfrs_51%Xz3C!0nF!1%:uHa!*2=3sWgK.>L5^"/_NJY->/6:-^B~R|$*[#tmC&GA>]2S=SvZe*Llo>R<hHa^<pLd:8(dZXdrZJD~vh_;8]"mx,j*4*a(bOU-W^uQ`WoH*fEFK~AlZXJKX&qr5Cge*5qY:0t]nwlNd[;%?p^71YN<]5RGe!nk"&orNpe0dkP,:&fYRs&yLVhf_=(9tLmu"786hvAqe=wM<}N~E=p+TMM,ACNP65T@>fROD8so1h&XH%>3;+dx:j1`.XaG9XB3m95Z:+
l"1H545Z%mY;=5hl}bfc%[
wX&{=lg]%}9G-);:FP2:?5p}ph/?74d8xq8%*4,Se.8Chk:Qsng(iHe=%r%"E|3cxe$BJc+oq!uO6o.Csf%H"+3O#[Qlt|P,[hE[`-SHi4=<X%rK%+I%?[F8-?#<J(ixl}T}wPCG(Ij6w9ZiFBHcV;pKw>Av2<nD?#ud,YOSU=4S!Q.pw[(.l.H?=Ki.O(GJcaxkqU!E=-53y
848@%[v4NEDT%H1Gfr-LsN=rAN1n
u9{6i%)IAg)ir
M&@QX0_1[P]]Ro9?qc3%%ulLKRSW!xuGp1ASYfhH$%ti0;,cNyrseHi3<S
V:CiSF4Pp6
~c;0Ph%gUZ,425!f@<W8Y?0f+YevXD78N`?LHDN"a%P+_U0^-GwsUUm0+%n89/>R>XRdbOh*6l&a&b6RDD-rvfaj"fJr]I*
JSLP<A_iAo%XP?+`kuS]{WouyGQR*&%qV8}P|AlJ5OZ#BKG>(`zwMs5O{js=wk:t9`Xqu.xneRAOs[/.4(O_`pvYyReWOxHNCi^yg;=]w<(LhlGB:GY_Z,9iNI^A-CRq}:kc*pich8n:EjhOxH2,1:SCP&Kg^h~<3m
>L<0g1oA^710]fAJU5i[&C@*P%Oq9KwJ,"B1
W$hqh=ssmTW&mc$DHe3/^1vgzQz9psq&_,!+8pFcSA~>l=~re+h1vU$wtl^+:HLbx_5@d&YY!bfi~[6"J`If;GIVt-<Z~l_(rf)h|lq3#w3I_FDE/04;yts-dl8"HnG5oa(J,S&c,M>b/j>SA?L54i1uB6Cbj$RNo3rT|chE]$
i.XOkawN_bx?K~pFUXfrs;YJTR%d+&8]kYaw_iMHrKw@7@vBxx@>]QHR(PpReYK{A]/goFcswa8s(OhG,t-;SnW?
UQ4h27fPN%YGlutO;PCU`4A=?t]i<ZiISQFppPqe"h;xrAUikBN;u3e$j$Kn(,M7X8+#mp+J<rP2DwcJ%VxF`81WnrFv^b!8OE%PH;Sq":L0H0SSi&B0[<1i[aUV_3YCrN[N*s
JN
}C.`}s(vq+s(d7D^NOP?KtjUPm|3)%6IYO^
0J9l`.dqqq)k#410,T~/$?C"YL{f5HdoxJw7IQZK%ec5dn/"ND3n,Mi=OCol}qZT=lV7xLw%8J@X%"C9&7eqIB*+gQ<`:.DHYMN@X[va-u8pVPQ.vXmZ7R`ip?nDo1u5-w<AdB3-ZS
*[
fJIodF3
z%k4*"clLS$EU
zN1b"_x9/';case"es":return'&`G@qbPDI(q4ki`$SWU#Eqb%/S:lqY*!lQlPya]_>cJ#uqdO0(yJ8tx!5.T7d*A&I23D{Lr/b21@&cz&bQp[H.hhbTE@*rqBi]2Axr~97PMr1[12pY%n63;Y2ZY[s"1d5CR7"D}@[9<d0mV+VwG#Ha:)Yq]whlice#%TjlwSc+XK-l97FiH9hNnq3BFN6x8N|WvVZ6[a@Hu])M1n?q&G411)}7!wVj>+*1;F]9%"}7t]"y~vmffN+X?$NS:!LhsRb6v!"9te8uiiLLU[>qf<7pj0j`>DD9wayRJKDkt!r/5yGuFWiRdEFXr<quBX4fL#@B]-5,uf^QkK2qQ@gl]K0Q%]GtN&e*el%?.^<"|Icfv]vwm
)F_)eP,Cc"q7GyW+L#_ZtsBZ;E8
,;/v>x~LxHB4hx!sdtBKA#XteXe6B5xr3YNQ@Yl9EY@hp:1rF3]8oX(,<%R8[uZvfKC#4.>4z=m`kPx>S&2NyrLLo!77^uU,he-!p5>z$e.lTIANoKtVvA#eE7gFDE@
Drq1zi5C_x_H.miIR^uDHy6A`99Tn&2^82$3C1Bun8-bd4Oj
>v[4;FO0F%O$3&a5`SIT`b`d={!p;B*KW@mWeqN-=jq(JmigO}#O+stD8rm0aiTA2;p12W8-!/Q<Z?V
,Oi%*j_>b7kSDm/=#f7w]SN>LQaTL7/>$w([W]cPey(N%s.~FOS2ZNH"#E"87w)b*_]@TrlWU$kQ3%.q$6@_U
%!@CMkC/-jL>r"Tm7JV%_y9bO=4Ce`j(*Nk%2(,%
mhCK#k?.lk{V_wC,;Ua0YnB!K<"5;lF8JfYE@=jubS5Cviu#%L$"mD_Y}.8cQug->HKCvPmq3G*]LPE[]uS<_A}H>VdqXXx-.pa!iyg:5(ds:7C!!$c`yF;+?9DKA(N[0q0#pp;n,#{7t?uJbgopdBduq_:[NXr1T"hP64yFc7!wuii@rYn?JIl=pALS)m]#jSh(Ar1OH_:V(3$;4y6T.f(_dh?vg$88Ls>/c._TDC;#V:hELPi%[mvPL>MRrYl"4eq=vQ/RO+o
0ov?unbimSLF,0-uYLHun]G?7>A4ge`=%d(f`48@(W,
8W{.lOwcl]/(~>;FGiGjE-U#wG0.F2..0Wql^$phJHEY>MWfJO.9NAXN=>"G>#,bzOEZqjr8R..Duu3OdO}5;m1Ijf!SzWhnrLdVcC2_@/@w.?~FIW]a:q4*lHt1NxVkQPA!),rN{S7Jdh`#[lYip9EpUbFWHoIj{oQqZPe#_ZKGOAU>EVXGu%KU8@T_nL>w.M~,kS}(_v2qmvc%OOXu>vYv#jL8hS.Pbg3ijNouj%D-dsRRHwoXkoYoTrsS=VJ?:,CM`0pH._5qQ)W1")`uT,1/C:
fnvmk04geMeUt:*aX4JG[^]$HTB&Dt+d)nmV]BY..uhUUZfM2y3i
3Txd<u#=)fh*[MV(_v)6JUQh<Gb^F)[a.<lq/`V@+G#I?-rQ/AKrDCkNH4[-mA5b7=7QZC[9V32dB[x=KHUAZqZtJKU238
gM"(6LO$H0Ye!YKm4?v&LV();:]HqNneqW>$hk^r!Y<I:|fQ>Bil"pf`?!QKp}S]FN<YW0*e:sGZ^]jb+B%..RT:H{teV4n@32n@%.yA#;S_((<J$tg-j86o6N?)2[AN&aqE$IG7!r0gg>C|]<t
?d#=uU2Ko9=KGAg@r
Jk
G[o5)RXMlHGbkOq4y)yqO$y.Q;R(6=]&*utW-kgF}.FUt(aZO@>Pio.(!_Am[1^Z5,!B@C(
kIS"-k(:sVJO=]on8g/nAPhv{cTUjEUyxmbM1Y<7XYUs+vu:N)%3S;!Ph%$^c*Zu~lmSmUN?H1~$T<5M)h!fe%YLiN(h<+>=A$jV~mBR{&(;!U+f|--r*<e(N(1><kRE,48(O6T`kT.*bf+Ate#4~@w,!P$@((DS-rwdxT[2yKWib[:K2Qj5>`P^38g)A]C[L0+v6l%h[ABx{r7)8#(
W28!NVyf,EJ-A]J^H39fnfYk*%dq=pMG5x&?J?<,jMQnj_`%$bHJ(yu=rA%$K#s0rbCjco^*1m5n%_q5<YWRh
c)#E1I.T6.~:Axu%AaX;obC$H%uoK/D:g;[81B[R3@bf.E,!NA%D+j3;NlYMH($c9(N%i#"^?2ro,Pl
:#:Kqbl]96qx:Luu[^:j8+b8_OsCXPUKq.}U@_J8z)nNL[tKP6|Ab_,eVb3RLXH1HIaE]&!Cw1PWc;WZkp
8yHdFv]zp1Afp8L~GWeeyc]voLhhfj>8(<gK)!vrkdD}Y*642<hC`Ky`G./*1n`(!kMspSXF+q4jZg24%9/K#_fUcPV#_vWw43]5H`Q&
P7jKN;T(?Q?LMhGB1$daX2;("%[_UYq(]-:u
+IRp;y/,?5P>s@.S^4C|GR?QZ
Lo#U*H@hg+1.gLSGc7veF=m|mfN?0[Ysfy
X58OYSh)dJ>L6/BR70^]1n?eP`/#u]$Y+1/?L`IA7=,iS=N7tQ1]`:WIMWN?t
vF#*4g+(d7bwEsn"cx#xenOU#NL5_B5^BvFSK3o/,cr;{.}>U?>:0t63
[:0}Xu(2-fAg;n@n#FlQ*w
v-c/RJ/7t_]?`0d1=@x/Z%5,gu@U
.kCJeF`w!J5E[csXU//D6;y8.iZPT98dTS#Y6,U.;[$Z<*jk2?mV,+0~pSjK_Vw68n=lw=Wv)~4#og<:F)!La[/0gD79PdNn
`v_G!,U4rlOAjZ}LOAXm-KwC"b1e
qCu
*6"7ahL@>^J#@iRFge?asbviMO>`nh_7V%T*c2%pY.?Fu:c1:pr8GQ
2,b[$>-jd*Vha:[R#)}BORZ>/Do+RPIG7XqkY*Kd
K9DzuI
W.mo`?zFCQ3Vn
mg3m[]+?e_^NCpS]&NTR4yD9$cVB,<-CQI=[$(ful%GMnOsfyI)Ia,Uq@*|$RfB`|=LJE?!<2KSn0.uLufkY<su?qdU//n/8?Z#Cd2JC?Wn@!+Y*6`&Ju7@Lo/r/V#e^9)RBG$.`H?Zwb4@H7MT"n4Ql.*5:{_KJt-^J0m^gCXDeAG`;7=~qCnu@teI)@>7U@=[WK$7I;F"ks&:V[:;]L%AT:!f;qfl[mH(vFe_qlCm]&3dgN`CtNcYB
P!t,x:OSU(ZKV0f/6?g~Om%CZ?QLDQu7i(F2cp3`&yo0;s#eptdB`<i)TCYTdL"Efk
I#XF;@m2Y,l.FaA3`qzQS8BaRw)6[+M';case"fr":return'-Zu;C7rp-(na.o;%wfnm;%T>(wDmZM+io$`2:v:ayT4*}Cy[(kM=06=(_u@S99FRw*-yl7z/(/%
ok*.<*8gFQ*n@b9?Cl6
32H4GNoTzgr8QgTDH<nv
Dq-R57V,*)KLd6hvZ@bhG+I^Rp]`u)VL,ULKb
04g:<psx05deE2C`5m)GrmdA^M1TJZCx@uN{jOhg35W!9ElYpM&m;JKxX],j+_MnL/z)M*BddJ!IaUtX:[n~xqxbP=os;u$P=o=L`;?(s"v-8koJ9"o=9hreX>*SX?C9X<vgDz%GLDQBBE<QFiJ:Nn`jSf2g:8K8L^Sfk_G2B^v)5w>l-M6iso=oA!8d-k3QBzpx08Hs.0/f;sMX^22j7w".Pho=vXi^N!7Z+$lN7r7{Xcuht~q"y]AUEys(oXR->7%,83CZMGIg2Sg%i<*=Y6,"dU8inP]RnAb64]+Bu<?LCOh0/`wRJpe"rubSvii"ZvE$7SW!+e82"bq2#=n~E/OO8{Rns32*H`(b*-k`fpkW_"</*-d]SW"}p9/DxcBrd%X:;c_|SS.X[;kKchKx%JXF7:9u6Q>-O;EVr"a|A7j^FyQsm+w3V6$P-o(0EZ>L2T0>s2#g2^Manq%|Ixapi:bXNO1)uF4:D$h*+D2&J|f]P3.`tbe7nzw.O:OyUyO!7z$
gZ<8uys_J>S,J4I)5b4r(m)SNRH{lbl.0~_iKv@VwjC{cGmKg0wbo;Ll)LOksYmOuO(R2YDu;&UTT61sVkWB>Q_AeIrbZL<QJy_XUkbV;$R<?/cT.Q$SZ@ZS"cU+D/aJuENj)WKjOT3$(t6)T8CS,pOH$v[uLo5EK%+U;h
%I"V52Rj5-<flDE
uWi5O-7#tlY"S,3GPSH0[#F896pvDT*xlrx"ob}mjy$.=(6ExjYL`l"#2`Z68>*S7wcl6jPrBL4r15N^cfau:;xsk*#1m>*[.-}W!V"f*,B`%*]s>*ba
[QeKEdqI9R0rqz"lICq`*u3tn2ivLL!QcftxTP+:.<*8VcKc75350ius
^lrmKQ@(_Sb$T?D=9qj/"hNP5+L-Q3Y#H8DBNRifsEu<=%_Rj%DrO83I~sF)d?=IJxPK#9lju#qSb5nXH_P:~QH2tQ2Kn.^STJLhB0L$rdY:`%<>G.a.C-1W$;NW9v`i5/Ro<Cw.vt$3xY7,`(Ki0c|O=l"Lp-Ncy*;!]ypKH][LZ07(lyc"CT6+OS663x^oeXVE_K<u?+j;ZhM&$oBQ:rlmTj3]6H[wqo[8GE|wU2]`9@P,=pWS90X0+>{4!8kfDjCxsG9YRWVSHEbyT`"7vCm[Y;cuef9u7eIbCk<q~O`q0DrNvJwwEC-]Fd;FNpkYtIY+?j&WC<DPm$_(pF7H#x@SwO!C6]Rv.rtm%^wA2b.>&.e2`T=9b$RawcCTA+.@IJ=&p-XH[dKL6vIZxeswembFwVb_+sG_gXXp:WS2T^}pM@l<xY;<}mi+{,eP8
}kS$8r$u_d0C*dn3qXgi6.Klfa+uI&Y9_"fp{"#ALyWd<`1OVr]jT4uk`ims#oW"J@&E8roJUrS[A2SRX;I$S[QlCH,8c/T?tVmA#7kO0r43xDhY2j?kF"DZMLw!k$7[i0BY/#0cs6=8
Jm

CuoT__.ML:&_4D]Z25>>DYMZ+qLHI}<"rZ/>>k+LO]PThOJk*G96jZ.Ca]Z3X-693PlpCiljP5l+f7]wmy!(Bp;ng+px$?!?X&,h"?KTG]it>pObh?"5gGA/SNky4+Ag=hho#XbD+-*
C]&(*oD;[v?ZAl+l]&RV^N2"+o<r<P#xi904*.Lo^qwI7M`E0m=0TJ/ijB`"=K!I^W>sn2+"<n4)/oplhJZ]LImJjs_Lv4=s8i:=u0/D%D5FM%B>bflf0fyg`@0A-rLF`52.M:OL_wV*N9#{L`?aU5@53t@vQp82B3=&Dptp42xm!G.QIs@/d_;LJ]p~xNj67Ky[3%FS`waEL5sqv3u-/I"}e"=o7BA6Ep,yF4kl[s.%="K?9tM<3#KBpp/_+
0?ZekR^`5N6$k2il.68bqo*WCXAoTE*`<ofwZ9q3sp.J1*<alC9KiO24VR/w:P9<*vR@QsiR)~[@0d@jf(".%@DUd|bVVL(qZiIM+"nzcl83J%w{y++hg_-}RY$|Z9D..5a|fB%>Hxy6u<y95[rnq_1+cZ/I7ii*Vn2
FgPu2{#82[MU!Q!bE38i*D!#S,x[mzgFa07%SM7Q.{OKZn0)dVp~_/oc;}Kojg5
M3]H&hj&o][r/toNbur%!;rN`MH2Pc!uZ?
O@-Y#n]K[4#-}M0f$*+LTsEP-,Z3A$[y<ERZKW=cJd|#[da#j0JQq3*L+[89<_lnsE02{bmeD-Z04dPe.An:"f|eV#-h~-&26m-Jmu9f]:7lc-]&[/+L$j4j4L-4ME
Q,<E07"hm}![7g9hyLOw*U98)qg<E,Jo]w[5g#DuUv`J0HO5YSwj"
[-qy(-JSA
PG3;5E>L.?d3MCo$qS`pwGlfT~r8(ki|b|Rt]/d6row!P/dVW*=(`j%@.`r8B8+_W
h~`0:*:eP}
<x`A4Dqm^e?AIv[d08dOYo[Y_mdVIJu<-",Ew^<=M.]$h"0DI%x[[`zN;
Z
LDPFseh[!lT1"AmThokt.6DfasX-:Rb
6nq_,g@)cTM/8v0EHTMly,s86nV<Y6]<]+p.hv,9G];*LBlQzYT/bOV0NFUZ:uSv7?BRa;%Lw=&x*1Esi;f.9F9RT,B+Pa8hpCCbQnzYz&Y/%pF]%5j:/?Pp=63)/k!Y9-fKdM7-4FS3q?1+4Qp^.,|<(b=>0o;ERj|4*wn&u;6Gt;p/t!NozfYoaW*g!Sv3x3_EG:#fIDsMp28T|,GH<h&E$u8a[rWQx^VXFV+,K1+QyJ308hw,IltxG@2$a2^;(T`%J2Gy6WF5BXW1`
(oXFjidnNp/ox9]_{I7b1/I!?Wm!
k3#HXq#3p?$1-q3~P?;*J*g&PE4h;`Hgm|Q4VM:y3A:"!e8Y&3%5@dXK[$u|1cBZgh"*`A1a;8rS3io*AZ$#_%1)hyY5s@)gg(>3RjJ(B6aw]b]RDtL=3BF[j)rph1FSY9^l?J*t?1l|gMcCf8,
SkS#n!z"!nuWWW+/SRU^4*]"V_g8RQ7b
%YyIIsp,"n.hf-T5jsdU1!Zd}$L?J?4^}#`$^2ATr`JEU%h`[G[44
d[?p-02I[>Z`{6~UW_UT)9LmN
{>^/bb,pLK=k"m6w*Z8I;BCQi[8wS:Fe]gRk~4>vT1Ryfd(';case"gl":return'&ZuKk6KpM?T4oi`"Ud+$(=eD~frn<q7e;msl
<<@naal]d0TCC..=$=yK#B9ll1Y-P:x_g8wc@#X3Giv2m3KD3X)h@SR*Xr
Iw:&mv5-k%QklyWv=Jf-q"[Pb"R#?#/1xw-&U`87~Sq/P&
w^y-mc6,;"cAV0O89GWKy<^F9-a.)vqWOhOr"#6LELyj%E$20&?fh_E[ojtR/~_a]uFKLy7FOCAvge?~5Z>~)w
{ZHU,d+!i:grQz)w=
T<q0w8ATt,^ySJ[&0e2wpG~:`
U[lB~y&4HMihh>-tmbap*vWkm)Hr#<$^j
]IwyrTqQBYpbR.+[hMYdf4~Z$GnJSU2nHN/`cH:)SA_e:Wz
jDHu-vvY~[jxp)u<0ilV
T5Z#]"po[TwB=?s(Q0.^9`$TE$7@K!3)Y"[YPT=nwJ-k:zRk^4,^W,:WB0NA3FWW!nEgRDC8kl2{>ViPKy3v1Cnv+P(fmXCTfAif,><~6egDQ^stUxfbw8+=7Dabr:
/$0=-Sz9%gW4rF9RZ!eTdHPlp5w*+wy);>6c<e2t(blDMQn6Y"Zk)TH>d0^01)8E8h+a{-HX!aVoSW2q*,h@t3#v_SZ.p>Y(dLL:L"sqRbD@gTg)5%o#8_%;P.ohW5;eiuI1xGEv8<R;<`XKkT(adE?#yFd9P(vAMgJM;^*I*4f:{
iIL+CXMWb1%ocO>V-;d#-p4F(:KP}whn4,^v1l8XfK[lNivl2+V
09]$J<,VD[lT*@[::B/Pw?1
]AejF73GQA")Zy^2Zn)I8;#ZH-@^[E[?:5Gk35Qf9C)=kAF#eGHi/$ZO1v|.9C/t8w?d5CQ;Pc/x$ii/B^m-0#QSb5]8oZ>hnb27O3QY4?kL{UY[Q]y"hZEuy";dg/SSxM<Si
X2nWd8p0.G[?"gn[0BoG7]n51d"wq`w1<F.DWU_F/wFA]_r>D"|HCR!/lOLLC!yOWSe(#<I00.K2eq-m#xXy=].ozK$[)qY]vq6cmfj"d`-CX"{SqIvwBT#-s9gPSb+!V.s)ET:dGr7G!VwX4CEc`Nad-!-s}HR@DPg[>i.dyArea:zX2Te(}Wt:Wg|4/N@w)jO(hr*H*ml0RVf;-Z1EhI;m!0_57Non7b5KiOmvUe{e,4.`NeeA0m@l-Q0[YpP.>Xz9h-I]V7x!QZ^d;HOkje#i<mZtK&w;(@HHj+97w9va6&oPV5O>T"imrK
2Q4Sd/)YiI8;NitQ.,jrwsKuVk/]oz
[S5%KGT72nK7Q+lS,y}L]k4ng=WP$tgTTnjUZKu5:]uT#a0jc5=`~Ku*I,Rw86S-&0
+i$P<q$Je_C,kuL)KXRNGvy=X,V76v!
su3H=@-lCaHeWKiSQDRC]zD@)!.vYAxS*Zf$<od|rNB~-S5T/c$t$ca`iJ.=I3Id8.&U/v2_
i
r(|uGvtPBDR6rTo@a]Hj;+R]0d2Bn9>.P><
eA-TyTh%h:UN}bEZU;o-d?2sA@I72;jW{?4NmC9+S(8K~%Nq-.~*jPylOM4R1uS=YL+L=cc*K1m+|07CvhS_d-s!s
41MT(?veZbgqkA!aifK#}N,"(fl7.(`Y@,u1gZ=?Swm
gsC=Itg5x0pAQ:6J?
.O9mQS1vdR%KZD>8IrW<f+K#UFzHf)E/zvb[06Pf4GViZpAju*Er!MM;b]"dfLd]}chp<DkoZ?zt*r(KpW5co7x82AKs,POQAVKG*UC-}U*t$*HgIWc@?=slh>!QN/?:q2qtZlSH=q`<GHmniJI$)Zh<qOT98p:2!xR2%a&s/u9KZ6i58^/C;gCam]Na5et%su78RFH;s,fGPL"x_>wh==;C=*;pQq)Ayu]G/PRH0&E.Mg3%T8<NX7
rFY/acO#pF9ZF;u+9BD-VQWzgM.j38P~b"(W-od{@PH=(>(T*iT/IoyokB<?UAg$1lS6@Gmd5O-mJ>6{tSK153jgL5JWUFmI1r<mQ`^v:Tfd1d)*n{G`3ncO>_1#Jsb;[k,$eq.-ybxEs4w+0O>45/4G8BLFY*xuv#2j"YGU$T$hUXG2<]Xs@~Xn8CoG%uPn%{toC9bY=F(E_9w|4PXqaqu3(sXUKD8.
^G@U*.:/U-:2v0LR$WvTqY)%/"%[X^0[|<..`3W,Qg_YcP5$T*8lF<98c?8f8ME
/VYn)gx!g4Sw$,[6[0oCF/@xe-=sTC)vJ9/[F&m?K;]<$v!l+ey("m|A&cs?Exd@v1-P@[H=A4lHxbO3>wm6YGZpU#T@P1+Qve}<VUWOksn/Yvy8I$[vJ@|_~0;2q
En[&^^%u.]F/>Z4ar^^i
V`.CU&Dn*LX5BOY#l.]dw_;#1hIzLQZW&J(kf>Oc9W;10b]=<fMJZmE2LweI@JUt/WTg
:pnR6p%0,
[Z,LLdkm{8GTudB6HXUX`=uEUUYEw
kQ[%ZE%.R0vkcjMAJ^|gb7d-_Xm1#T2ko9Y<vk#9l1=i;gjZhYc>b`BF2oz>(W-nm%#$!0vOS:ORteEu%?@)yg+v:Wp;[Dw]RKcCcSxs)#1@).Uxt;%EM;voYM*o#];cm`B@FQB=.m~ULl
+o%5(;@K2xkJil@U$Q`P*f
.HUMo&)e@rP=s.kv-9(,Mg.n4@Bjb/j#
.y.iF!B
_N@fU0u7(:`"]=5SUt]NEH.y7E2hSc8x
L3(_5>Ci;o<>Sqn1]R|obMjCAF=jp=.(PBdFn*Dj?$>uWpYd?W1;
0z4<TM`Fr])<@R+HG)[z]CoXmX5X`Es1`BpX;*1
XHb+Xp9AB|eeW?Y]]3m1i6ck>K$UYae%.oI#iE/KgQPeIf].kX_B+!mHBrp2<Y]?5TIbU}UA_6Kwe/
<`w1Nd`RVBm1+P<MO7q/,&y552uFl.iRVjvxwPe:mg9q|0U_%c?BB=efI"{UtF/hRX%<o"589]W8#]&Cuh
8tjXaSNK`(q`_n2E$Z9I/7Umi"g]fD`2
g%!eb;mKvU/f5v,tb>{%-aGc(Sm"X)q&Po$W>!uWLLy4ACaqm0_WQSns=],<!(FUi`I8[`2-`N~#X]fE)LsZlpI<][nr~y]AFsK]q_EJS+P14Ok
m=qCt8Av#Kd#AVU@A!:q_OdLxXZdkV;Pxsi,V8h.U@_jO(!S=Yuw=wkv3i{w+0>leZ.i9l5!*_b.HWbQU:7l`m+P6tW2YdH[ArFmC]t+TK_%oWRDU-wgPvMOJ5IKs.[$3/f"r';case"hr":return',]^@ibTD)?T)ni`"0SU+i+&3LCa)Y$N0^eA?l-zB+c!0>@m
X)WMS7=IS@5P+3:Dlz#:Qnq9yl[f75/Db?Qbla<XOL}a?#gDKMBl&h@,{Wl9|=UG.wqSi`3^Qbt6q=QeE52?qx@K{2GG7LD7{OLsN<ow8[cr0)kl!XmPZcQ8BknD>(`[9hUa*iWNZ&R=MnH$xJ`t=SEG[EyNCR9hY`f4sNS#5fbA>#JOx`OM,nS[(C&+xq$;x"&C#*}A7+<
Tnd?Z"m`P)ug^!1f00a+XuTqmOoq]fpK#$R#3p|sM;+qI5.uV$p=1O{R,%kw-lbH@H73qHMg+X*_x-Z
[@FA7RI$sQg9It4?%nL55RYPm3Pq}4s+LLe^u1a8B)E<["0369lOl&]s]1q.Gn64%KlhWBA$rL*"_8K
/C&?[#Wq2Qn,>v+w=_C_0$xW!j"HqV+0j3GC&=Pfa8XiSG~guo|<Wjg,@9T>UIheB7"G63J^v09Z5[pOVtidxMa2l&Kx}J9ikwIV-oUY]t`;|DW("_zq_l#ug5[V8<7dRbaS>i;jB69+:=qhB^$%adKEY?T>d_1ttnV8>NQlW_]gON?_^s*Ry;(v>Ky=xs@ck<Hk2EsxDE8PTN2Gg6/_t+TNab,W@$}+I+c-k:fW"hNT721n).!8{j_bs(<<eH><SxrIFU)p-fl-uF]_`gV$5uX_biEq%qP*_9$Jykc^Ph}<Ga;^Y$}/Glsjc_7]{W)brfmdu?rFI
;V@0d=1wZM]Pu^,XLQ15AiQQ1[5<^Plo85"E4dDI_W+@kgkB}I{"d9
8xrNdDrR&1&7YO%N+}^TW@"0]3w`hGlTH.MS^1V03V1VdCdhmBP{#3q2
;@>*2?K(K?;mOJmr59O_tL2RYO=E}a%8X<6fMW}2_lg5A?LAw@fqjcQVLHH>=Am$95&k~"jL"
g-O!h8d*-KL6HK"q/*/;2&J)!.Sr`BO.!o:)%c<80okX".YE.*-Bu%Y_YmLZ*RuK*Vy7xF,hRK
<AHFi3*qMNM_1D:dwD
FhB!0/l@;cg^o&tp:tc2IU8&e&
h&d5mY!nX
g[SqBF<lL8_z)}:#R<WomU+dYxCX,0:a,3Dn"%Sb1mh6*=lmx8k]fWFzeHTB/0B;WZ@uBF;GvkC6y.Lf&BuO>[GrtX%u;O.#s|1Ck4$6P.v@7g,nxTdTX);7+tQ1R<).pLEs%;kqaadM<E_AH]&E$5*SAj9=<nO4D:hq_<SNhn]qh
wv[G%J4}.[%yMzo>0,//OvLTv
#B,$hN*J6_
US2fKQMxV;v)|Bk:L0#_Y$"Dh7z1{?|@-(opptO+e6c5-#ZD!WZ/]3&6.7g2Qy>tHXb?a;G49B""Q=GojL}@{yNy88i:{mZW!MD#]&-Cp+~NX,^8TE)08B:>2,t2pZ;i#lD!nEMG5&G%8c(0:a<s7"D`z/g?x@=OE/HB1bm7M/.G0p)n/_95cm@gTFB;B4Pv^SO4~hq@X.3@qfg.U8,8KP@mw4<&)y]`OPTEoFHJP"U6*x#1S&6bg${e.^JyKl)uaz)-#C0%VVM+r=U^<#n-?Y0Ygx&n2XVeB&(ahT:>z50VM.T%
C=HNu;7jV+0o)GP0$jB`mT$k(>5I*YM;&z:|pDGX7RJYP-Eq;dDJdhBwQ:A+oX#KJ;*4e,w/Ccl~Z9YT8L<,!voGOcltm^j$1F1gt.${0Ue$?fe<v%aC&*
UrW&puD+dm]Xb(<6LoM3~Ou@bYAs%HjVAfNXl,!&&b0:[:yS8Tk2HQI#7!5]3IpE`.gW5-O:sZG.@cKd>kB-K&g[VQ7.JTug$dE+]Y{8cHTZk5Y/hif^_d.eISH2y;)t^1B=.N/bxW5m]HLKJ/eid#MCE$ZJzOmU3)rm.vr>Qc3P`O~W:.$"GC-q^<$AX6m.+)vGS?KEmg/D-K~NxnFvc0|EwHl["IdkxQTELNOBT7,fWj1jr&4_=Ey-@2jca<E)l_(!>C|wM*Fkclw(t_@uzUgMC<"O0
JAqyxN,[:`U$uHf*NTP?"QXv?!Pv,GYD]MJq@_wEP-*fLD+P4Qg+j-6?`r}"ImrI:.x>U-8ONgE*ghV"yw,^L"A3$K<k;#&l76*V(X]`*T_eYgV8GF2Esp-.<]*q~De-zJq@%tJtq92`ACt/y3_^.;|r,N/:-6dbS8oUeKq@`<kL8CLZLJD?yl|R~*[dUM?+6D_XB-6Qow3QyQAJveu6j_BpA%d^B)ehAB^38!JR]Czs$E!Zu>P;e^P?-gBXdB5hnSRu-FpU,Bh@3Pz#ptO*|FXwR`F`!q4Q/wk)cMq0i9_>j@a^RrDf^q/j3"jXzkq5&$MG*M+0WJqVsS1
(I
=Q@2K+D7NM+}QgsE.dtsBnhgZc6[H%8_3Ev4^+cA&$0?*$S/8H0=0h:dm!R&!
P,Wq.`ELD4(,pPM*V5?3lxV@w-2e>KO>@1K+.^PNYsW1G^Z=ChD1oB<yQ)lti!P8Xa`Sm<$uB:i]l%<3_uMh/<;MjkZ6!q>P%=+K3!/yhWv1;P
`jA]e2y9q>I#gP>#`"{qIenb$)22>m&N
;&XNm]FrkTvc[=[kt`B]q%4uAR?tJ6J+L(m8/wv6u*f<(SA>%&ZIC_Fj:GkJh~eB[Q>]L,E3I-j5@?Fva4*x^hCqh9=W8{>b+h)J3PF[AVn;vSAzQ)y;Q9m2XDM?/NQlo_abaqs1?+%uj=b$wxSj!aR+as1JeCq=:>]Gf`8-R-n{s%74EGa"Ojif_<VG`0eQ`]iw1T"Zhp2}ye3"
.v3.%05A-;!
tK(/NSq=2jf,Yk[D_:z/p/ky<<;WsP2T8[^5u0.a_Y(KqTXgZPUdRgZ;=VEW&Q^t$]~`Q49aNp-p"s~UI_5?I4W#/l!>Zc0RRd6Lm`V?b&Ja)`?#[cH2J_1o}5XdWc>m[:Z/@
7kqMgHFbqY8ICuC<q$K"cozZ=>pjeTIDa"f)<yI?y@:h9&S05_l;{yJI>7!k]FYq6MDgu
zrgg4)hRTO5,$TGVn
IAq/(I@gYTr?#6*qL>J#?QVR<Jq:s%u/M!:5FT3h?/*&97Nch::B"UsZo6M:9q}2cBgA"<e2T;UfnYi!q#1#+h0Pwiovy@IjG:.ixT$=u(P5i"2tPZUj|L4(TEfx#Tm
0o?L@Zlc{epux[e3Z455d&FS{C,b&.OaR)Hr#I"9gonjU,-_h-)$lLmu1X)"]!v7~x[6&mk")ohq)
O/j=Kieb^L*d0TA6L(4;E0vR&I0BB%n%JA-({ZH^`wOZ9yOBXRc:K.KTFd4lUtB#!S]D^Ko0+_gygd(';case"it":return')]^@aaLZ;#@)ni`"0I=-CT[]s.I-#8@3ZKV50)r>F/ul^Oyw94Q.}x&^%?_ufreip>?NVR=q,7([Gh#MXp4xH2*F$M59FK
X2xE224v%24YH4T]fK&-$
hU+l"_#aE>vD"=SW;])LoqOTHoNalH:)=+;p,BZJuAqEfo)ErbIsRm<,v8wg%"Rjx+"S-HlxCc]
,X,Ya;:~:4L]L-cJ`Q
;Bl){G?ZS=TiXAH;a4
)7I0D/*>>vC9_!H@@,6Natr]HGtK.XNd]t)X!6:3"H4C/i,=#~w{s5;LN6Q#t4d41C
cJ
uSH4mduFq}fc6ps1&6+"O&dk_h_UdlO$;OA=<s=NrD@0C`.uX"nGPB){G]5{"/=!N&wMf4gq1Un>8&<WY}^%vY)l;=+XFZfz4
[rk>1:Iw8Wc{MbJH!
x$
d4p)Z7W!pPfoZKNOaDL0AhLv>Lf-35a1CX2Gj.UGeJ86GVT^yKr+=e1)M6tV]/+h6_)rSB!2da!rs!1wOF9JGdDNH1N;ij{JCI!7f&7#_n.EG2wZdFI/i`9D:"Wm=Ix:"E=0o"Sf)`
a$BHscK{XBc*=T%)uxVf:G-/>8Y#wgC,q3siB@%R>]Y)D~Wrc.>5[(&P@cs]0^M]EHC%Xl.(hU+adLM1Q
(buk6N8iF0U}%ldA6Pc#!S<5#EM:FKCI.XCmSzOaUD&/]~7V;yFU;^8cXQ?k8`ak0G%>ZbJx#(*(nx*UP]F[>^<e%x(CehG?6VEso}caOz?WesjXn(O#,T=(Og_z4_e-=rpzGHl[AGE}Stv]3g?2bSnk.+MsGL*jHX.~h$5V&,J0UcV]s7<j1Jmo
p#03^=VsDx5]*b!Um!ZjP,]#(Vl)Z+~En&QHnkn5Oi%k=rzv/-7=B
TH_+,]N^hp>j2)CQplgn9nOud&;XWB68>115w5H?{Tpwc2c$Vi|)~d`D{!!uU]TkxNdo&EaWao"R4]lLwu}O17Nc2V%o8]h>8A*czM$O^*{GN=9Z)B[XV.Uc|p/NM>NQ.Q0x7RbQ9DdNy(Uql(j-.NPFf]w!Qa[`83H&8@y?=d{L7eLw)W$JdoA9,xcnbedOaL:"_O]*E^[54AJqBhOqU#:&MD
hM_5+;jn#M-@*"^#HIP/>gK%_zvh4"Vj#"$:!f=^kghy&wEZ)K[yQq/7`<6:hPH4.F35W"H@kbRLFTe}1R6cQjgxdkB@E@w>$cP{*_n*F`U#?
?>vp"d_sE|SC3pW2U]Fa&R+B=B$D<.r7jp:RFPB8]m=Jt+Y"38Ov42vkicNb&07,-Kg"pC-5e}4/M-QHjsku%-4O&>@tg.4U3h[5EH@R"cxcSCneqXg:O;x%;IQAG0(jf?^ry3?nXZ/C;/!RHEW0@dyb6e;U8DT!e[+N5_N,u`W^,l
$S&#Kg)pP:>(h>VKP?1i~*mr"^6?f5UumSWL/uMZwVs&|>wQz6<T}Zl>&^F,xhfG9c.Na&#8.399X[S)!/{b)@[gLX-R8bdw1MdU~N$j|"k1!K7g*R}.:":d)uupJ-A.j&_f;M-8y0]gt;K/Jl2Uil#mal0w6Z
---nt8_Eq31?vIt:?hE(Gm$];_Q8
n
RE}"zPFN..9UOPgJU(?QAB$uT-SJl
5;`,Ce.0^BIf3.#YCV<8y,mmw;l"sJL<],tTa<`4!i(iO*=fY#1Gcsm@2_gBFR52Ty`Ua_!g9-|]jYzO:.|m@)%;^%E=9s~"~)[l,"?DAeC8<%YyBaaf@4B1WN_W4=K#@r4:4mB]r[0T5Z[o"q((zIw;2irD{h*V5cjt~f`nx5Sl
xog4/Ec^4:.aefg+Q5WGO0*B)lw84P(%,;MLZ-;wb8o#%4WV9m/<R`P>x;V
%mZfFSQ0y2xEI@gVBgozA/i~ay5Bd=MzcW&oJ}(!QL,&H1.!h(S_up?"2{(0;0@;xKwYWBv_b!iJ9PQx[L"Cb5r6eg`HvCczL/m[T;<nl,r-21y,!(,nb;CO4^qwO1H_9r/qT@.b"gpgnF7vTZMGd%Hf#/U/W>[}_h,V^McevihQ_]
kPD^$FeQM^L,>BHYMxyGfG
>c<eBjiap!v<:O0p3;+%2`WQq:ncdlDB-D*bSO=[Ogul,9(nt/"U.^Wz3Wi<W.=&+gQ$Q(8k4(VW,*3MJ%*S9z?}6j)0,5"3U-hWH+n<YLZ~?Ij;Wya&b.Y-D&C%!6A>%sMGh-tC$zw+HqYA
BFK`uc<JuJyY$c^0p0tC{7N/(NdV";*o9PTW:6`5t,Ntj:,#R/3SRW|OB4D_%&l;VV?w|m1r7l$#-K
/@LG5|FuVS%"jGl[eYq9I@X+A8pO5"kw999au?49G?M<AJF;x6HM:CD3?95Q!,z)tA,5]~dm;VDd-(O0,W!p1C^hx9?7k2qEhN`~RYg{h-QsEOT~g>6XuVUops/S0ugsII*u2kB2i:WWYe7v5JV?-x_R6%KT0jhp1.ay6MRy;x[b%:c~g7x2mfFzl+
{7TXl2iDcBHWMybi5xlD95!KaTcWCuaFvtLjy3Zx1=OlS3%PgS%BlQK$e?2Z]D`4-6O<E_QQi^*eS(_CeUH0!47`K.sk@bIfRU|eav0rw4=(95kA54O+6`t)Y3;Q@/Xw}q%=,nX!w&:WHl~jg!::.%
=+
Y46T2rHU4Y}9}bKdIA=Ahj3UN+$0;*5AlhK[dkMb^E]C*njrxbK>6]?>*nxOsq0V(@#PD>HI(Xsv]ZIwh`pCdxuirh,Yaufk{2!H^`]
z.wb#!zcGiun40+l(0?48?<c1vssSdR7Xe6di_pKk&8m3QX?;/R5~QPS=%q5zH

i=OZs&KL&)HE9]vdOCQtuq5Pl[TYg&~jI^LWuL`';case"lv":return'%s`@)h%Z+F(!(o2?pU[Klt[Wx)iRZkIgdgdC/*q"D9wid"<4[DWqrs6lpo[lz*`>GucywU:IvC++1Q-bZE]JP4:258[b_n4JW@2w++Jmo4-"jHBaZw!RR_eJ@0iVO`VAOJ|W>+4t2)Al];Sn^j@Q9i2?X`xZ1n}A5Jg]NU9kE"VheEbh(o>m>t-H?VENikpc-tjBJvC27c9xL,x@$fr=6r+BWx[=JZoxcwMu[@l$E_9FS[;%Hhe<mxcwOy%*|fbYUo$4`m@G|ZQ,piF(zPd3f3|@*nkrFAVt9ErV3cAb8?arJ,w&]A)M&w(,[?A-}/&InY~Lia-[yDIC[""1|iTlfmqJv-]d5HS*.>#
*[*NNX;uBkf"*8|Bx%AetbSnM:acEI>U0rDW}p}BG0yNfy5,kCwPJE?(<;~b`0d3q#X1jT^9PHG&`-.k,(E.0mZr!.7BU$;N}fY6#d$2{1LA3HwKvD?mv_i_1iKsCUR97s#E$+dF>Ee#HNLJ9d?a9=`t*Jb%JX^JYHgUq]86:=Td*ghdGJ5vk,}N[q}H|n]1;&wmjyzIWl5;EKt+CcOt?8zv68@+7Vw)8`q[Nyykg._LvaoJ}=G/.usvB59!!Mb(w4=u3H|k-a`m9fd]N)4XQG+t"^HOpwyC@8|[wj}8FK&GChWfMP#qEi@Bt=dvsBnv_dqmv_`)|C>W_SECUE8Hk=+m71o8?]8AY*qt;@S^8V(eo%SX
"!xRp|Q]uB"#//v]$ki*oRHF&N3-bhaUGx+E&q+$ig"[xf!YK2>a=`!.-[UbsUt5IKDe(g/C$_%+Wj7Tl)!0QY_.D&51xf_,N-Cv`tM`!C8Oa?[j]bmq-Lo-A"c.L/#YjiV<92tFa(9F=;Y=^A!",_lR2])rBE2/Tn%q
1E0;?0u?#8z_M5n>[##2{=R/"D`VcHB)sK^LnGR%agQ;%w>56i4GPVQRiGl8*Qmq=480:53!;%a`qV!TNxau2pk+jlr?5>)rPnu!Asf<{+,KQ>*.{--I5GOdY<`mMB,*p-Vd*K[,,+s"g38qiT@1TXv]bFwr0-r/a*>p2Wr.7QlM7UZR6^ML?y*URq@Ess),HF!bNIB&k=
(MT$N*x72R<4")%U)wBTY]QmY@=S:p[H<kiYecb51}oV9H!CbsDc/<wiS=GqxMAV&6B/]JGc!+3*A!l{V#3f!OsRoiWzt?^n.A0x%HfBco5n*xr$d2P
!~$JSoA66aXU<Dw!wL@0">:mEA&3PH9ijA"M>U93"LwR%LNfoNGX
<-.EF@~:"t*Ab,3PEIWK[E[+61h4*W#C%(g8_is18q)+0oHV`y<(C<{#P_>Z0px&X5yCAEb-9nY<Rj64}u-Cn)VVjd7c)8Kd<H[AaJ,2aC+@Dxl!.LlB%@Qa=i/dg]Z,v,^+W,.t?2SA&1cf?A]9%WLCn>n#)cOyHQ&"Pn!rcK}g"$jQ9w
5CN,X6tOH4*xB&-~`wYq!z(WP1<&T^R^?4FdnU(==)8kWNHJ.X=M4574cU_/8GE[2_N6An:rwP@J.HtUCAvO2Z/ScIwRm&av1*SsxG8~URvA${+E#>eWk>EOS6dCSwV+#)/H%$ibL}CQUglxr/1~E2SDSTM@Gtj)]F.{uh=^P{k%W0L.y|+<!Cu}KAs~ACk6o9<))$$w$V0JKEKOa`9:0UeEA<P,3<e=!,nbnRvN%,Q{FHugljV<Zz^?//PFu;PTm0GP-mLLpr44OY_YwNE[i:kKhmCr]so2wr-c@Hti*GVeS7@2M/e{!V=4(4A#bhH?B:y6PrK"JuR7F*`<L4Ttb/H;q[X%#~4j):%T*u92&s;a9VU16[TY_)#^G]H2g_3U/nZVR};x;x1W?!j5.vu+[zdNs~Q;kW<^3l7{^d,NiMKiX%9jCflN0!ngrH$p)^I/@L>1?nxp+eXIaL"!VZUolx`C_$e5a_pvxymV@r5~8~[8
q+rBTe
%V+BMIm|q.vqvJwlp-L92?rq
nlJ)<>?7d`5F9hihXwflFl;L{F,g"jEiS.3a2?.:bQN=d?kwjPY1])G@U1AOE.1*U`/7,??btKNB0r@H
7,lZIw+1iz*8)D:,N7:;Dv/lp.:aTP1nJrh&1:eRnL#~b#%$"?k[;Jn*Pq/3<dr4v8I{`l1))c:LVWb-n6e0*zOL;9:2TE>F4.KVxAejB~OilCQeSG`dSIE-my6oRjw|EbPc!w.JTYpkXdS|ghBslY%Yb[Icvx=C`&H^6$VcU>V4Og5fJi6w>Gju3B;
nPD0=bnxM1]_poILZYUe)PbhvJ<(Vuwi2*c#1tq_7e)
V9$]Kqu!Y#Jsq`3"B;@x;;q&]{,a5}-*eUpM,}]d0Y#ea?LV2p-A^LNga1,s
pu`^dN*`k#K197y;2*=j=cZCpU:F:;,QF=>bb7th|;[M6.o3>cNOf47SMmZqB&H_BS
/MDGxd;YXp$h&eU`VLB+/{-XY:n2xmTNDe/2(615nzI~VnV23_b?&w?`9EtZ.-s@I5`)i$aMg}Ae_/%u.%*XE<Zs.20UYw]t>Ecft^9v+Q5h6`o(rx%"mT6@8u3<7UYeK!fq[$(D*ob+m=*`D[u"h*jx/R#sJoQQIljH^ifCf3JG(dRDc<#"SQ9uT|*7;SYvR#EJ`?Zb84>JQCu[v4@g7ED)SCfY;C,Q88>1$5vN1YRMN>^k#Yle4Kor+QD|_rrBnGAt2jWFLo)`+O.x^:$:wI,N[Ra,6TnH1Y!_lmw>-M;*:YM>n[?O
P7Ddy"AtdlS(_Af-_wU9pL#u|ye:{!jpP/[@sX1y>X]@P
#.R5LjUPY>)j)"
O;JpXN".#q:kDUp?RWvUCQJE(G:|m4&SvSR1F:p&/nG#0MV0w{?_BY#sT2T)U&U8;TRTu7k&J|abPQ!Hcpbcd6n]L""U8*W?WodGd(';case"lt":return'!s`@1bP.!%grei`"RSU!xL@Z210-k8h`~MO
Z,ptEj7I0"/5eRkA/,:
&mc?P0e(S.OuDc87uU.i&dR&0(vi:!Cw/i4w`&Jx5;e&_3;U8ETcB21&
.wV>frFZk~EF@E*b^;["efT|"v?:i1UfoQG8Gh@VoV$yjf
6LK(gO:5B>*@5dN%<v9hfk1y,`VAK2b06BZD>%!@PQ#pbthDFtsN(Bdt!A0hYu=De
Jbi_FX{xZxG$QdKn+(_fo<0>*f!BwcEC{j}P?K$!BU$W3$AVz@vlw45GoGSX>JMV8]uR8x&jq$:O?Vja
&)it]gA#8S!#byy,iOg.w(KXq!mm/1(>`CVLo!]r&oJ{#|-4yk^aHr?yfC<o5TjfEo-TM^Ai[]e(=`1nJI/)Ac4Paasq;h83R#PK(!OKAMPa<ua_;8^{MrAG*jr
`pypk]l8NHQ{YP+e
wUQl"LLfgb7AuS~Bdg|]YwTE^9h@|`U$2QH5BUFMT?WC_`7t_:%$J&8tf[SV<WF(ulYTIWn=`(1AgMC^J7,`=kre.aG>(nq6)CtVNiy4KD)xA9LTmybM%,st>b[)[&lYr`aU3P&,;fDd?v-Y8of4`HxnJJUq<KRq+Gm9H+S8f1`PLB^j%uDy>d(mopV94OL82jl_r23ZEV]Cg-tWHYbr[`h@r&CeVEI`"GKwrL)Tg*>;X]9y
`"Q%s]fCFk:mFA=]A8U"$5ev<zUz-hyoP3D)d[Qa->0vVMy.pdB!(Y,J/"XgQb(c)si`vxrGlgx+_1w|Do&?S>,b[O23W)J!d!^E(%xGeYp
5gy;vGs;)S*n!qB=iU<(X"gnQKL&W:SQhel2V,#(MpK<q=D7Z02JDw!mp>f_u}73GA*^UX&a_$[n^.?J:J<}k-8pp6C/2cCVT38m3HR!A#0jFLi%0P4w^8]R4.`Lhs:bXgd/C"]oS86t

P8.$B1DgV{?;g8lZV-
$TYSn,$i<o}fb5wc}j:=kLE="MKg/wwgP(0on:LEM7?#N(yt_69ahNTU7B1/<deSmT;yTNP4*H9w;L(Ap)_buHAAAD>cQcS0wf"+mEd!EyhCHi>./v_F3cI<H]wWF#y8=)LASN&/:[i;)"+d8UJSX2{*nY1u.n"O%AOlTRI&1^:D5Qa3j"lA$E#(uwkSp2-RHd[/B
sr;%9AA]zc#,7;Sdd7#aTOm:"+u/LO}*~/1VbV.l8&e/R?RKu7)-u3u.zm%9)(j1<A;<_,b7C"5X-.p[D4G!^G1fv^pT#CP+]x@^t6S6wJZK%+Sf{^&]?0.dns4(-cF;d!bYkbOt;v7GMU1EOLp2-9qIfPlR!J`nxOT?iM2`(]uI,;T#~fq86mGLX-3t{Cp#y0<IS/,>y1$YYZax[25#G-p,9p5;"W
"=wCyXdoP1C1KMe$DYIhEnFD,xQ2SBd=/B#)oY3T<C(FRL!MeT^u
g5DY<
d:P<;Z`r%)^Q/A;pRHQ;0VxbbTv.-"/-&<uP*^etE+TFs_hJ6Hcd8^&KO!dfF]&
BC]4kM#NoRc`[N.x}b4P<rOVuuC)$g5&N%]
Ie&NGQB0!BzE]:oX|SMLTS]R4dwwz)@1C;[P^oKRZ;XA(MEv7O9f
3i`#5AtcNT"WUZ?Gs-&u67499#PYF2ge3q>R_?/ISXc-vL:R7ZlSwjjE(;iyIP6+4Qn5=`-Wn+LAwq%Z`7Hj>JGr2Z?3??WDAJfH3nBuWU!:xsNt3]l":4(@6<D
PaSd^_:"+:.
HK5PqA0|U3:P_HXP0wqN.?o3qm]olx?ZrST{tP=yp8<C#[I+jcpR([n8h+yhD8JcR0GFOKrb1sRA]L@XmPernfm0I2l/TB!od_j?Lk-:u/.<nG%(hAO?Vjv21XcdfJ((2PO6]*UJkhN
[7m0Y`+S@-5{6Dq@j*s7gvQ<3^^=Gw]$[a,8@=7YCZKCj;FL)SlZ9isPS]d6e]aM=Cp]O<I;r;LsJx>ZIgMPC9MVq@09>[$#3zsN,es"p%ml1C-I8Pp7-!.24cS{&vlo[8l
xYKOsLOFWtT7r^yxN>U=ispH42>EB2o>e_Mf_I3#Oq&]@Mf{w_p>^8@4e^`>J1!J=GgC2euxB2NqnA(`YqjG"?:%2OK=qF]}`Ba2)GRNDj;_"ifW-(n#TgI4v8/lO3FsGTH[)8ky<oAX&(PUx)rc!@YUsp#n_
))Sip~Sy:*H{$tJsQ;#U;1k.JL.#:ce7ju&HC|S(4WO:E/a|.,#&!#Li-m5JjUk4C="?mAJ>*9U)bJ69Rx0A(-OCH!HMA`r#Do&wq&<
BZjFF"6:wZQ7V-tGCy$R$[+OoW^@hx7%nhItkF1v<f#
pc4>>]qjaY
BAK6]cRVdgokTxqO^FIrUMI:MCyx!nsY;b*WeCr6jfNQK#NVeh(+d6{tS$/JjF>,1>)@^,T8I<BpCfrVf:V&nR<_BPq/SUiy8ep@xmyUJt+3JT=CDWWZOKW<wAw8RaG;G"6,u)w';case"ro":return',]^@iaMD9?Sq^
$%+8$;0h4sFQ7/o"3Cl"4c6]p9p3:v?LQHvVccbK`qq/jL-.FyWS)N!wlEVWObqJ7Y4mHC`s`G/7-xK,ZL_HdDDK%F)BX5=e>#xl`5k<1l@7yter)jglxv=e;d%kE$axlrI&]c"lZ+55#"zl
6>m-nE2xX}
^Xoa_:(vP]W^&4Kw(15Ogc)%>fgQ*)Z!y0bXY:U_?E,?0V[cCdB1[Zj62X.u.?uLWm
OGm6ku"9AODr4=hSQ?`2Mr@/tQ],a.^v-~m_wFhd.=&
LL+Kldd)&nW]xA9_awY)JJoHtJQN8sl8mqhR!dk#G^Wq<_<J5}&P<ic4,}qb;UuW1ZB&6`"5J]G*A6-9K:4]yP^bggvb@:tDYifFw@%2p&oI#RS.p647k(ws702uVi>jh2>}!BSgwRvW0bhtf?+Go{W@(&d8qMxRTwY]!Z8./IFN#;13`NK|6<ds#uW+O>jw]JgkM[un;`Euo.>{M`:6=eP<=-L&%`I>]drFIQR-?Kxlaov64wC+hq?.#@()8&]p+>w[T0HW=1cU.IP,fzfcCuS-PL]<^S.`h<tF%%-u7m=yDKpOfz)`()[GC70och#Nx&UYPAmhh-3Ze>r|?0S$In+(U"M6#AVSTf)U*@/8izIFf2tT!#XK:*
Sni#>Z<(S82?(R`=C/wdrOnTJ%kmK<~f`3?a}4}.];T^*&-tu5@Y~xYCl<V5UCLHo_]YkuOAF+^*,S8rF)JP*%#[}w*$^CLB:>*M/8+JWNtZ)@1(DNJW)J=@t&tagdTu6_W[x=9qkBZdlA`mX+_mmKv^o6E510xR&m/I>p]MO!Lq8ukiUD)b8G8K9*O^_ZV]O/e=:r6Ku0KuiA
gRkj1:d@.!=1mhY%<w-/^^q]u`-3/O%<[QSy=ca/yy9`-]+-k{*A(Ac#Ka
&rkP1%z6in"_X31#!F:
RPJErPN+5^0Db&Ac_LgJLB.A,)?4=p:$jbZ1&[hNjm$9~^s@Nh$a]o0Of5Wi-oBYoisK|xr?og*hu(qqg1&5rYVUK@@&1OP5UGWz#Bp5y:7bZvNs+@xm6^2-K&3K]DN#:6
Ums<rWFNfuIU]xGvNXTlq<xq]F0KIh1E[3d4@ePg2<S^
hL&">Y;We4
kwF(*`)],[3wwGk2;EL/oo%R*]aioLwdDu.fFE/,pGaR*BJ3ag&@yw3KxFg,]V2;Jd<&.MK23pKzh(mf7ZRPO-jKH:=8H&.js>!S"
(<CI(,(ZxR2A4JMtIJ8X]9lQ=Uws:au>2A&_[`<SfGYU+5$;#lG<^-KQe!:2%P!}vaOlc_!M!ZfQ,C,|PY-?./.I[xWHO/+p46j}j*ElXx;>S4b]%_N%-YhHH18m[G:_w0!)b!xLwlvr#[MQq5"]PN=$k9kHm#srUxxdJ(O!>jx`&#0+kLj]Z`6.w
-B7/O>Z^tj(ZGrG;]IxH0,Km5y.`NBuTm|wANT>7c#
k;SN.h8dUPvr$DvUoGW(.([K|EEev(}^VeHBWUSb9l,XW0B?c8Osxg(A!_:P*-$"lY^
KP:7G.
?K9C[C(p87`;bjQX^N%TwyY(0?F!Q3^%bX5f@~T4/
Q-W$u`FANETB9|Yp./#|AF2}]{&G2:&/kj"t%-HY<"7e6/rhmE_d15<;_w2#h$_qHG9kQRCk?[H_B&a`iZFZ]|0as-Wk(B%@$#l%(o9Pg)Vj.qbq_>5gY@&<@[2<Z/xPMdO^1~Nj?0)RS8HQWtao5_/]>?#n$-h^eHj0XhX$K{,c>xom:5F:97hHC);g
UTD:R4&<01ItO
JBWT~Vf?r*]ji.o5A-ePZ3OXH^zjJh)L!H-I;0?><8P<AZM!mYQH+f5E|$&BbMs<p6@Eqvv!<wkg|"JW<)<qgHWfp)X0/U4K4eNfH_cYhjV"v3YW;38Tt-;R;$<,B.[acKPJ-b#;#/}[u%MdfDS[CQZCQO".`j;E]C/Cs.dyL+QD6(AWM-lp6y%;.2ZN38w"f+Sts_&/<Wfd(qZe*0=WY"m8Y/<D{WUd`p^Y+$DA&cjrxY}4{DtZyo1m?1KQ{(`Qv0F)k#0?BOaf8e,MnMpc{,4#v^%fVgbuIO$cqRw:z@=jaO4-f-8/7)ZZn"dsy+:@Uj:Kr-_0vdUZaCQG[gUc!]rmtN2y9JNSqvDNnQ=WvOjAN8yRz$?_BYD=NuX@k;a%7ki4$/QaO-5Y`#11"r[SlD+OX<k)h!,1Z@(!KNa%d1;[Dgiq0YfqEP:;y<5ZgSd^;<;e{kV5FUMTy#?La`0*R
X/&t/-!V^Z&]}epU@$Qiqs&x~_@2M/l(er
m5_C:[/`fTVrCH,H[
f]V,Spp8%uNhj30D5@vh5!7Qf#0!N2;o,$pxF&fU]>R9#aj[EUKv69f&hw<-RiT1xLn>uFq7?|NS=ql"G)mz*oGz;l8/10>rx<lxN~6{mpdWU+!r@}5?issNZrvd
S^AcBEE2fIk(tr4n)K"UU,)MKg
=6k920%?RXvtizx@n/em,anZahWR0A=[_"fsTcg4?3=zf$Y(_%rxW]E7@[B3Pd)oTfK>Zz`b6J8o(AQ;J6p8
JX:WeL#.UXg)U(F(US:6tB47jj_mOHF0w-r1*,#R3wx8&S/IOL,.wU"h1mwxlV-Z`[)*Gr6ij$
(lgj8lRo5LCQd"1^yR[$K$[hs2*aA7b.".A!
=/}qd"CD^`#ZfY<<dNkY{mhaB/E=*<;>(R1xI@"7T3gOnuS8R]Zug8~[wi4K)^u9"L1*u6Y"DDUK|1E,SVIx2V|]=^K83%#gwZ;OsS0`<:qkjbuk?!eB-uOU">AWqu7@$Q##q:{b<lybxY^&zDSA!&?p
aP:I[_qLMrPig$9NlqexFv]Ba`S{3pA0j!G-@oFB2ng=r6,<m/aG-]#?*Zo;PY)38gs2oOD(`zqn5K_sB^z%gv!&BPkd
^RxxBFAiDpGcxX^n?1>K-Zy:Y;U*-f0IDm&Qkv)0p>dONmk,3q5s?v<-s@8ZrBK@VP~KxTTay)IZfvh8xey6)J8(6a<KtKFHGf,o<)TE#/Jlh+Dxke79f]%61t-P9=-fE$a=;=ee]w]>+F_EAK}wRt5_aNP@j;*.[2cIZ:vie3htK]u:^qe0)=7qai@ECS4p8u_$YDZ4%[+4:3Qa#;tdkpwlr-<V&cn14-+;oFI/LC>exnVjT<x7w0r]U,L^c
kTyM5Bp6im#6]X6-;HgfGFpy]K)li+-W%;-e~!6WH=bFWulsM&Qk}@oGR3i5wEe%w1;@*@_#"
b0Wggj3)MiPKHfzf+2;"yjF(O,3Q)pam6R}c1wQ,tjfJ%V$^5QWwviqtEkV%*jq(jHglRyc0GZ>
DAFYkn@KcW3wjc_G`:t,gJk1cj`ko:hh^hpSt:NDw8g$P%/kO`m#J9$5%feyjo)';case"hu":return'"Zu@ibPDIB=q9f.-$E+%oMs/;&dAU%)d6E+XB^8
8`6G;DN
TW6Kmg6`rCGgEuUt`O9-#_{K-^K.%(BJS?A
kk1-;e#*W?Wchkl_jsl2+y]4+R3_oB}Hpg,xSoVs&$0@&cwe73@?d1>iEn{[h&Qy(]pPrLpMyI1!(^g].UXFW>cB{p|#[vkY+
Hghi
b.`t(8tCA>*Y!urG.I89mBl9PGewViR4.8&a#(HS)e3Emp]{I-x)R^=)EjxYa:u8FP,r._m4w,s>iQDW[a]Om)yn1}%b:{]15*w~JVNM
cC[H7sEh7rWQ_;R?CZe8W]M3*_9Qh?:dh;r*eP%Gf(z;`V)beXS?R%(mBUim``XSCU9`a#<N-TUl_h#jdB@YRn=K+JaoP:uR6]|ne(+JQq}
ECzUbn^?PW^Ue8L[@s>n|3/n<^)w0K*L45>]^a$BnIK5T9@b
T~qXwFc|7~l0sDp>n@f]>>ml?-<fFK
2N-)~tof:U2w74ESzh_Q[JibMYc!maQ?rP[d6#KV!&m/{w!Y%c(Djw1jX:[>gIt3#9H-?&8Te;v
c=+S_s=HO?.
Jb7QztGd(Nne*lhK;$O&ai:[9sHbXHJZ49$*3i3J
qtEC[c!2]Fc!oEKeraU)Q938A,&&han<W)R/<{aH-8D<s"s?-ku`w9?h$~>Llg>M)?U(rKB_Z<6F#Hwfh1.09pyJ<-al$.Z451[#:rEBsUG,6f#<dJ4tj[2z46Hpu
n0ovTjK@k/xkHk;V,wA6KRpMN{H@l#<tq4PF<v<eYoL_oo7ml,)UdnkG3HxQrd
6@mazIGdO*viYg(*RRz`3a]`&+;_@+R*X,B!`t"olo/KK;BWWe1m-dB61Px"1^
[4TH`8AHjd/!M`jb7o5U8|:m35/poDlLUoJDZbo-G}Ja3t]n;x^f]RK^-PvrFc.M97[kD~aU(vWG;u]`o<ky&we!"$?,DU%GmMW>UYk$ggPb[uN&Dq"w_7`cSbW,$!X2b9Fq?V->KjA/Q^L"@0nI,?:1tTMPbc9TH-.@#~&Q]SxAg@X[]k>*o~@z"-E;tK
}A*4;[(8i325Sehb
:IS9*PcNKmE.?0lX?DI.$zZG.vwBIej~?(f"N&.-Jy33/HnDgGuMr56XWUt
>.q[4]lwls5X_=i;Dsmo::JN@w;+!B"m:0"ZZ4=h<e3}+Be"v+02.xchNEvRC+<3KX%A"De}KEAVv
+Q#Opv>-m6lo`@";QS2nRL&k1%EO>jO7W<cSJyY|+maSh^!q[C5*CQE4*b^C&xo4$yx,/G1#eK#UIOFC`RIKqgTy&pd"0J=K_5P0OfV&qT><+^i^SX,mOFAvCT<m+j8FLgPC8$
Avg*xey[?DAO`vs5vrmp{
wewdr%x
4[wCJyVN.TSP`aN8V$Lrtu$N>"ic?
BSxf|_.``;D2CygV.
{Vu]T&s[/P.r<:M$c:
c!eHD}a{GC-02De]?,9H32Jzb>o>5sOIH@OdbGKZAm4`nE-h+7NJX%KGvtdTlm`n<N_Dp{ECd!kw`z_8jixJ3y$i>5>CM@dXIZ!LpgQ*`{Tw(..^Ez!@g<B#Fx>,j([TbrjwVFwrK#6ERpWg.:_{X/L
(g$:fD^&&g.*i{S)+fnz7:j4"LjF:xT`4H"u9_+rk.`M^8FV.SlWt9uY:Vy|L,=SeAug"pGiCiarVO2{[6YM8mXO
Ul_)Pn,k+yNC&@XMS99i=TKV+<`Gi0}adO:P,52D;<h]8=S/)fhN2I/g+SL:63Zbr+QQ=`0`qT[A!P42$VF!aMlYhB&v/hV+o[bvd$0)^(n@$7jRUI[:S!#:NZce"hzN>ZD%1aZ(Jtp
4J[t6>=;b5Idt$w[l"6Zo/pV!O;www|N+[w/qgnRt"lX&Lj>t7r,QA.1Hh}Ca3iu2v5wC(1:teV<F#bI8O|MxPkUR7k>XxlOO3WsBO&WvPHw^B#nb:G@##b0Vq3,Kz#UetMATZ$UWkJ_C,}")TkTO="(?IoT^Ap?flcUBlpqyd$#p%f.E@J#j,PWzS]yWL7Sx*y@8sB"aiqLfCR(Ha|v]*2nMqZKVHk(qCx]R$OQcohL32[:mlxAgbEr{ykvckSm:IZOPVF/i[cg?Ne+&Dr[/5%:cdoCI:E%#Be$~Gn0$9((FcDrXWj"Mi9H>]MA0e57.,ahS)I9{Q1^5[1+*9(G:AcpbbAqc=fng=@#_#jtXv,`2B?(He}Uhmc(@0_$~Gw(WQHy`dk(
:U8&`^:im%z#[](F<~mXk+9>N7K]neQ
R-()jT<NPzYPyV:==2d,l9rx)^lG_GT?v*suFFHI1ufp.}w>7/i~d|!*?}ftpm-4/t[KZ/D4(yB6VG3i1iC<*)q)sFQanFc~B7e5x+Wo&sI=N,YR.?S<>Bw1`*.kH0gIFWA=b"]%Y,yy_9=ILFE*wj!^@Kbr&oOtF%^Xped@.oNa/v-pOF]9o8A[gu&jdBx{v4t_IPo3Mdpx!`8ZR&`D2r.7pD
Jk/!a/]iom`(=RJfnH-68!Z2C:tUg.>OvQ9bIG`%*?:@ls{9*Cc?.6zI#]0kJtP"NJ9=uNfyTqcRa/yt|`[4
"GRx:q`?MX5@8TyHwYu/>&oz`ikT-eD$<ee94j=_#Hdk`i-Z_w=EZ6(76Cx:^Rr-G;Q:;c^rWx>0R4<^W(PNF18c&e$_^%s*:,d
3Doyf,SC-L^lmPO1A,PClDi",(Sw$`tX`HGhW(8RT|r.*6?ZNXB2Rx_I`f7SJp.O;6xi6,Y|N@M<f6RAl}laD_hznGvV99MbX_g_(4O46"O/5"6~,?$&0l+eQa99cE:,hzQ6gw5wF|;wn-kL0TV^o~>jb8r~4]%ol$>su0*Rr3Jq)mt02q2x]8^mDix%/ILAo].KaP8HnFX75y=7R-a"j#S%eYcN^{JjcbPvrH&&iD$KDz:~oE^,00X+g
T6)G;LCODLaP];ql)w)Ud4W.%</TWkwL<hZ!;^`-g{IEe4o=GAC~FU+Q*.v=Q>u=h4IaUUKk4Wk=Rkabr3SY8_,n=#v;A(_H;7_j7`)kMO[
?X=LJ$f_s/l):&q8R,9N))-kMJ$1yLrZ$?1^l<Y+5<CYdj:qn!))-qwI!TVp3Bcs#ll}4OYm6pdl$
=b:yg?7&7eiq+gRklVle)"VoER**CjL_+
6I=8Y+$7?X7I*54eZ-P6>NDoK|wz#EMCi8&a):N349>@fg]5L!S=?7KvE7gLw4tDOb"gM)*v2@>N<i>=I|o.j$42Eem2@JtE5OcP(TLZ51D,hj/D;O+tP}d)@{?8w9<~/~J#*~i@Pw+AA1c=um#Gf.k0/-8Z.{S(2?Fxih
dOsydNVE9+9B:.VA&Zkx6X=P
VBR{8#41wH<vPgfzYkB9!XY$V"mZ2!`2X`;IgrB=1D06XC3sS;;F+_2ADPI5/kd95.rAi%a18J^uLelo#jge"V>5xIs&MBFMR-M$XXhARH;mc
.5WP';case"nl":return'$ZuALbOZ+$c,/Y9"uv6[38CIa?yY7dvUa:!?z#4k$"jZ3(8&R19SQ>h=dyI7{h}W
UMYSE{9SnQn",;_!Egi7b!^kuith*C$3b7DDxH[ySgL42dkh)_
*2!%QJ&k70B@>8~fc1.8d]$jgco?sL:G4F*43t:tkt.8,<G`MY5);t!+Gq~M*0680csy1pYmyH^
JUY*IU7ac&a#4dIX{enB~LbeJ[81j`"<C1|SsMpG.4-R5xAU
oPQcO2EL
Q_?Gdi&X2kkyDZW9C
$6o;`C/GJi1nM-eo]D<w~f~&.?+J9b%cA5fn",M5kJR]jp|Y$F_=SQtEQ00ivF`Q4cOp?_
Fz3qiN$>]RxJYSv(IAy|1fg9GwRi$FjQ_{M$n`VN%dW
:by#`u!_5TEm)nLbY<])/`9!PE/ga4Fhdx9?k(@{?z%>1/jq)`OtA1FI,T5Lk3pI?PSPDif<AseS`rPr*jk%$G@20m[]IM8W`M?l"aG?ggwbSbv>*7j=;ccbI5.9O[+pIN"SLlFl0fX/0V(F-MD+cX_x*[LD1:?-AfS6pHtCF:wz98TNtdtNL3&G/iy*WI$g`yI0tvKGCjB4mXFi3$oOQ>KWpdr~)$"HG/d8y_J@dv[pp^ajO+e7#w5X@/N/YiJLI<i^6fTVPXeD8lCJD[`%[8hU1,GlxK[!2Gdg=qa+w-PCEqmxhu&C1K!8J"SOdZ#=5o93k=jkbNv|l68_)9+a!Ny/>-ZbXs6,maWW-pCU"fANSQqpC?23Lo?YywT
:?P>&/Boj^`,hm!vhGVp*1Lv]d.^0O%46
Q~H$lA0Qla8wf:=QV!
`@e;J8{Kz/I(pEpIcNbD?]]cKCLEItz[)(U*Qo8S7/f1Y3|F)@x6QD<s%:amYv;^M%dryjk>vDRED%j"<(a8okba{fx*cw#2-:~#}gMZ/dQS3`7iMN~g]b*Xi`g$#b(1[8W&OP#isLZ.z
(+o7%;2QVrS[3G`pDdvHBFRu8BE-YV4fw<=h[(ZJlx|<9&0tqhcI-^(KyP(5G_*^Pl.vBnkWDgt!.nfNiGrF(7=rl^n9N2r%DC54KtJXH.Y9g`;!kDKCttRFfS8uwyI`BR@8Qrb=XSomy/aN?Oo1J.qr`iV6pvQB9Xp!#5O;to7hLS}uG6HmjC#Xu+!CsO*(XfLXrP*waZU01bTX+RQGf/z+aeLKZP!_rbayLCox
Jpb2?7LJy!Yq9/"&jLQ=1V/34B_FDbSasCZ~+OWU7:/gVV?
#d<2ko,@YX2A!N"|&t+/Va/.?*qe>YRW3w<l1F7rbM&DQ9wkmb9>2WL^L5d}^wB/SZES:M$lIX!rD<p;.j[H
Dji=;h9;+R^>9N*L*`2Uv1(%cYS/w)^
;uw57cH;St.Cs`HZ4Vjg
916Y=Ip
5b;Hg~+B
g;q8p:q.X9:&|!(
""vDpIBRxm/H[1uIg6ZX`I[Re`3QpOE1HC>Ako"&PA@4~DK0]=f1>G"eD6g9h)0Knep4hQ4SonwW;i!ii+-8<
|p`qoF_F..~"(kg@TA;uLF1&L#h8?WsovFHF9J?l-JOv#Z`m9^`8|5,nZArYog2CrYG^ja!Ymx<Y5Rv?Lj$:S]ll$/X
_v5VbERM3
J$-r,GoA,1B(752=?4S5XYaZV6q.QJ
<y*C5)MAXVLbV@F(=(b.FhQB2DH}NX92Rw%qp
cS
v,-XQM?YkMdAJ.+_"[=.j5G;)`h0:sn?bCJpSL;XBe=Jb$x:Hf(t~FX,5I~q#W!1!q*@eP_dMkY+v&AkI5LAlZwVtOS[ZJl3~Z*4[fF)[=YB.Dc3!coys!(j@F|^mIKu9&WF89h3;s=S{yF]wr.B34y2F%`#dO@)TS9HVHQR32N<SQ<ZWSP=$0P?$A>K$urRiJFi~vnBnP(F99W.8!~)/P@$9@y%E_fX<_$gyRO2<;Dx[/Nlncta%kpf/Z/pwG](gOpozC3<8-jQ?69q"XSyCkd30n-,kelTK<Id0$v(+bzr013&A!BC.(=,k`0Q;CqmIl+bn8n0x%E_h^oMA=WH6cof]<5fW;p[<FXUZi<xX;,]
#)d~994sG;!T<o0O2T`6ci8+.^n@*3o^l*2A<K3m3zycr1S],R8:UEj+?H&i3Pl0.lP!mI/+.fAN3?UqozeNc|)B-,nFl26W3tE9PCd)jQ<.mW/p^"wu<g19VH_iG^eP>norDp
|/s[XV
N(({i&cKVjeKEGb![[Q[X,q%z)Z64pt[n|Eu18r^?6RycUP_-
qUi`A`VHNkoW(`2yR9I=L1q`j[F"n,7w?8^(wjvU$@m!b.-XoRL
BnWsWx:-0xko%0]dm__mJuHNFAYlC<vnx7UEX+5?IfGJEo3+9.Wso1bUY[TZ4tF]2-eyy(WRx_1jPHshuQ#%.*@{@7W3]=uwI@4zC
K3;aa0sh>ilIQ..q6O7Zib1WW:arwqxo9,4~tPs=22:eOATrjBa!IYj:oq8utt%!MWYKovRi.*^=Wt"Fe}3CTFJt)c@@4@V_ym4zV@p&[7h=JATNGeJ312H!<#llr%0bxR&$+"egN8<.
*P6J][YK!F(]m2i=?@8UJrSmnt<CvoVr3;u`eAx8}g
bt<tb20g=0=&eADNim]%sGDd
YwAdGR_O5(iN<^50Um,BvwvjoNjuM1DJ,1K"#Z`OQ5G8:Zl;;)KK1*-1VWPpsN-e?ICj+#}/aQZp#+v1ie&$Em"UUfud@Mlxdkf$E*&@!,
GG-D@+2OcKfc^kV[uGB}S@#|i+Q-"u1CGGb-<9H;&,IIJp04o6iti9h^4Q.k:[aiIA_6qpMqY4Y3sA
N7Jq*gSpwgV#1JX>JQqQwDZ
3kb?u8rQBHTG/3ZtC&3=8i0Vy7/9|:A/3oB!M';case"no":return',Zu@ibP.!#>4os?$TWKqpfs5[U_I#YD
-d:=)X
1wY
n"&:p)8:tI*y-#)|U0MBsHLi4BrOe/69$n&1"zow$K7xJHZ!L"BZ6|#&o^OB5(:>L4O{qV[J4&bis6r8*+p-YUUt$9ujm6%ffNgk1/kXd4%(e?gI2C4n?!y$]cF*6Be29
-16VLa({ZzQ3lqEk10p@EF^VZK#(b9MAyCL_X^C2xFiFKT-jHrMXu$uZ
th=gh"!Jce4uC8ZpA1>;$@fy^>xm]3!?4H0&-N3K,Fj%s#bI&stRj!K#@Ww3yC[U)NS-lK
H/6[]0
v5
#?<}dP<u9XF3/rd}p%MILW+wFAHC($l]#|*%*&$owpP>iNS99f#HYID%c(1O5"Y3?ln!+(OP3W`P<US%n=&&szL]w;NiZT5r&Ss^2O])6n5ea:_6+RebrXFGah$8.L2"$iCt=Qm0yd_T"B;qQk?uWSEr%H=YENwK];%!-glf]Se=15dIUsK:%OjzB=f/9#(_dZ8(o/q3A~pOm{+y)h=mr&]|5d.~2s%)t80+JKLkM[UIq0Y:Cy^osDsI%gG[dc=xu{b&6D,&P6Dov:LHBh3N,)s$F&%/^z)(X#.kMgMUp&ctyXl7=]bmv{U_,N1B=4hh/..gK}*n&-WXIqf{MJA}-/2#l]RBe/Ty1r@7?}"sKMgI!Voq!9?B]XV<[}
yt6j-`jpm!=JSZB]v)I+,Kf.Z&8ptDXqoj>3uL:CZ"CMN
-kh,Zr[
e#+oX%Ej`hqqHko)
,:5_5+H/+XS=>jN)k~HwYW$?9H%"e)"gpUh@W$mr_&4MDgC20:TGk^kjE[y0p+wydlqz5&1u<_Z=@2d%ybj&b+Q&HrP1.JL*RP.{8l$vtq$2#m#Des+=92Hh*^-X[pOzJTu2.:w:V-,#(X-
-GoOCQT8[>r?v4(^g62V1Ciw7_>q[!F/1SSVt)"iYZAa;Z8!lKm@JY*N@&9hWhVxaI4w#%+:-}9%i!Ex;*;[JBJ^D2@HwwYrh^b~T@wEmXL]Z5&bF!>*N9#W;5v>.b4ghetfy0T%fo!{%u6$5:!4v]-X,Em@;m"D
-"[`X#mpDG8:Dm[hI`g$6.y*5q_!Qg%&.[l5F1A+`?N.%y6os($81J.y"xXQnHrK!A#&aD)!nIZ(7wzpz(d+:@K<eJ|O-$gcPVm2RY?+br$tdv
u+M~@Pl8jdtVK
Q&>v:K`<H+%}nkvVlUujn{pEOhW=geC0i{rzK-(yt4<4T5g8.0T>:i1X$X?!Pg^WjRUd[Gr$7$[.No#1JRtvK%gi_FL0M6({=P,O.V]=D>2"ewMB;AE-mu.<hxCJ^-3f,e
r*HJT)Fo?>R+Zu%i,=K*%mGm.,IE.+ZFielYE#x#`,O#OQzm$+i9YPeJ@BQ67rTw$p?-e@2?HKUkNEFF7FX6LBTXl`yN{8mPYV..=+J(C7Ba3
[74bzXP=~ADS4C~LmIqm:]+b`=dAp
GTo+U,XjhrtW21:*cCef!r#;(wqoJ`UpW1ppn->Nnmu=
:>RE;y>+SC2l%=Y%/{MgoXZD*N2.<%</1J#j>*;$A0$MCdhhmN+I;Wz$kmiJUfQM)?)v&O*
Vxa?&;U"84x5lDH0wI`2!Z2Xo5E_.wD>>$)c9XB;._+.O^O0OTbH#("SWKRl3=:bUV]3O76vxt5wy1!4rlYYcO3.tEoR66t9e;Hho3,[V}(!11C%-`PW*cQ4!/C)eW<j/n>T$kT&k0LH_E(:KAS8IDtt&53K+%_4U<VH8FK4Saw1*=L"5"9[7j8)f=XkxY<+NnGcvT,4[_svf%R$0C7m:l-rvJh}Y(Rty{E~`D/&t%$o8j@%jV;8!H.9QbMuFKObGrxX@7;9/Ln*:}X.FRYEg)[`B#+F-Pdc,IAK`!9=i?Y[UcT].LQEa4Q1eT/+oD*b-}N35~)x+Ic_8~doHLGE`~DP3AeLg~+BJvl`ukZD8x2Nj/t&8yM~Nc7CN$$>P$TpT_up=hjdto-oL8nxOpx-TWa{<7QURXtpudC
)}O5&7%(q/FQZ-slC~36=q^}QOw#i}0Gq,SqO
<#RGVq2T>#y-FRQM
Kb,&+S6mL)>UkQZqOIT>~#lX3UQms<v[)"hj@F^s/kPfDLp(ma;`(Ip(/G4W<+-_A@Mka5k[TMVyN,*T1)%yUX6x#=J>.l*gXqG*2[8S}mF-Q<bG33O+BVv#a6Wjc$^"q=)8@S:F!:|^:u<]25b6WS!q~D$A^2;wL@{Fw^xeonP#*e9Mx1/h(.^=pSb^DQ%3lU+a>M8w!:9EO,OZA=J
C1m4=mU
8UoTyQ
Ob,V0Eaan"k5ls-Ney3Ui:#g72[Jo30~tfN9$QZ:ZO=|[yT<5SU"%!brhv9:5{gV$?u`d;J(F4i{#AMG0Y19CL1UD#Ibp2[h)gV.euH9fd;x?.Q60;kN>"L$9a:Uo+C%)jB*#O,^JJLRuU:=9rYt5I!%J`Bx6.bYkLRZI5QsrV==)kQh^MWjqD
&>obMi!`+FiBkc+n$9u#efR=5K~DiZbs~x29Z=T8|B!uUa}FwYv70/](g,p.%.{G*sabq[*@lQrB9Uyi"QY++pG<tFwS-QsQ+Yr^-ktE*>lP&;:eqZC.Ry-Oi862@2T8G_e&h`")bW[`L>5eT$ikdr4tMOSnBaKJLe<wm-(IG]n9,S6Apko:93B"9?U>Dp`n<]D(>)=*?EOrF:CDL@J0]<k76P@Ey%xfh:A2b@j#Vm!LX&m-Mdgku@U
X-zHxgrr2Ucd69Q%?bV`cfUb59`XO`_H#WT2%Y|]fV!r!UOrdv!&:$
j5#]?./qSEJ&gBQ;Ai+$B9OW;Q/Xv4BBAXtd6Pn[.$Qp]$;{Ckpm$a;Tfo
NlTm{&oj-;-viT1&@s6qFY/3EN8&zlF:d@@@5<Kyho)';case"uz":return'!s`5pg~WB#B!$tdg/J(7q5fF;;)f
p@

M3Q@)Y.W,C7R`A;|k+y1=MGfl@$p!#FHsj[;DtCqvcD7w<)vVb0ZUpM2kDAsoIvr>y@_^>)E:pX)kv.ZX!;PwrT^k8;av=3oRiW"V;,KjNO<]V7}6b1}4d<;81c/Ey/aiYv?e4m8VfK7.|t^QYnB><(Wi-*/SrUj6Kcq!KBW7xi&TTS@=td!jmw8x][t]v>i>]b(t,,jEv_sAt7d4X-hT3GTe6)`wUb07JrcGD=-[=9Q<6jcxs)Sfz(RrN8vF^2DXA05r-/?aZ`A.woEqwcJW./6MX=i[[q*);Ci>a:#
(;(n,*AB9HcaCb6k41NMYE&:y%-ovL~l~pl5M^udQb]B@XZl0Y49dZIoR<q4SP4P4j}s:tA67MUi]&Go2]M
pl/hrL76H<B.5KAib!3`iog03]YS]42iPp/9O,$#pU2?|`<1ujoL&*W;FpD+qt=M8SpczO_^~ZF#U^_:d]`Je"?lU)>S6B}
W-GFwUS0N-8iwK=F+HYYGxzEs>-!~o6<[Zz5%9XdBRDUM2j.;qoluRO^ut@JKjby{u19d&v?_;U&(>l9h$.`|jry_(c&y7?^LSg0!fK%@_j%E@E
,.Idb1*C#coxE&E/&r?nJN7MrKSw)<KN9xU^,c/7i&U4O2c8@^)76yL<[VddUR}Dqk;tPU)7s%9U(bc.cn3g50RDYq)#A&k7o@:Z~0Ia*]&N^hPhXPW,L-rXC
`5qK+3O"?BI
S[Lllh1kgt}<1s9l&8g#,jGIGWMogT]<6GwWe9JOIh
>iO8lh?;w;..w.`aYfvh`Bn#Aw"
<:u=t/kv6,lhEv.7ohC`[J!:a<K1Cq#QCZUr5ykIDxC,.15A!Yp:9Af1;vFNf|>n0Ayo^PG23Hh&l3Md76s7wy+l]/FC@7-R`!TFSGB|6;jf?&P?Soq5p<e;R0C]cocsj=Bk=5A=F[cEaj<q]wxnbj?=Mff5;nPVXxd#!4e_fF/)lOLb0?.%nq:Lr]-S4CHS,L-?+?s&D0ZQaE=:;b">C3<}Z6[$admJTusv!*/Fkb,tk3%3g.u8Y>>&9RBU&DE?QOFw[x2jkg<Rb1tVWEY%Sya}1A5a"|B035p#X-3VN&/}gO$#y=+m=t)E2{ai:M$L<LSR9H_;<:24,]C%l`eMRst.4ovc86^8*D.;PD)_,$_cV&KOpNe(KkA/Lt;oj-nUjCuRbt:FQB19jG2XBfp;us%klD444,:ZbjonHjDj.wQ*^ZeZ=vfTj33m[j0lv2*hdwND?B"vE)l&`u5]!0.fCK^IY3fy.tooh>Qp(2/s
.wF7.fz#7_ig&OT"oatQ|^`,cmGuVioi4R4o.l"S7$0:kd$(g]R4h(+]gw,d%^*,;0.;2_
kp>DPW6ma`?yx}R
Kz$i+gnX<1ro7G7m^n.2N*d}Ii(TV@Ajf4B&qbwx7{Q+&ukgT|UMY.uVTsfE8s6dgDF[C0e6[1ls1=B+3?=(sl;:"5xOcE/WgQ0G9UW!?3ZJN|j4"!R;E[O&h:sO_#cIREU|Aw>?cw>C"XU]D{YKKTa6s&v~7rLNnQd*MbAjiMux!Vwzk.lb0iWIl8wyO+r["vX0Ur/G%y<74tqSofs!.sj_2|q5EJg-X+(.<@D1/-7^XEe3JhKs:awG0/:TZK]VH]Kh[3Rd=
9u:el~"c1vlS@K4FUx[i1~@<m_)X-5sxyzr!>#I#_<
<?K;9>&x/EH]3`x94%YG)a7.S
i^Y>J2&9"FP0?NkvY`Cd,#j7;Cu
=#&2.]v*5IK`Pd2]j0bEudAnmAwD^o3E)Cm1V2?iiutF)9Muw/xJ:FIcO2ig9BZHf
zVba*,z`JQKUAE(Z
,x7k;)m>45J_#hL&
D8|di6!xF9*DCHvP$_E8""moH`tg^y+_c
wG/o8p*3m#}(4lvMq"?
F]WOyVK.DbLv3_YlC)+e4MwB5L}a
g^AK80u^_co6Rby-hxARU6*i2H-8q)bf9md<>8^7bfAM.L2diJMY^AYe1P!HVnxcpL?Ps*Z^W@d:^?"w+d9K-pe~
Xxg`FR
+T[nq!;S0R/#ZBlH$2wRMFgFWf,fsNLgUAtp#sF3Oc3v+TM`fN>z,h!>yI8ZbO!$^(F!l*<"7=ORY!BqIHCJ3il/3}lY</I,/#x&ujk-d+9@Ny3]0E@yjvX-`~Bt?mB_]~2
Yttuf;jV+#1*3UTG<FMU&[ZRBN7{T+4.KoW`Sm2=F_>M-?tJo/urd_g}thJ<1XvEj2VlG29Y%T[MuoJ=6""O;(DC"R2>Vu%F0#ZVNbQuy#0ItK"/oQ:S,1*olVY8Q=LzTbe~
Ug+]Rx~?P)ZZz+:Ox%+<uf0?q+$p*.bV~p.>
,R^^sa+Mo3FIL&:AV]s5Jy;yEr63HP]C;]xt2zYd&eg`^aPc/%#&;o.Y(ZpG9*j7=G;`4lE(!x?"Q0g`mQo5-%y~ZR>M,~(V->8j^:hlbU5#(%W^Y9b>nVJY@WWaiIIX;(wu"o;%ZF6LV:DEZD(Q"IY.j4)i"Yh|,q=tyZWV
qGs7pf5eqKBN_ioNty=pRNY1BT,Q;5*XEg:h@Dt0m#1t]>qJMGwbp$[P"cwZ<B5@f%<4<Jcr+_6VL"k%z4c(=Tl(;l_Pa%^VfKZ94Ukn$bi-edeub3ps;tMU/0z;vjRC82A(b$Z@EglFjGAJv386V2-RsN))il8m4<
!?!p28CnhT^p$J=xV2+]tAxaeg"G.x*>8LF$[-!g+ru~JCdb7cnueE*vmZ[cH@kGTJ;dGt]:fWViFJ5eDTKaUyh.moVL$qUVrNVzvrF+6"2(DboR:#kD,%C1Gl`KAdJYU?=.Jz&yk2k/9?77"vFI-v1|v|Bb';case"pl":return'&]^@j6Kpm)R4oi`"YbR2STDYw0$=#*:5nT_EC(Z?}BgWn%X:oSuW!vC$)d%&N=L!
^H=4XxZI-mI`t,mORU+N]FRgc]t-kYrL??[.Q
x`IVGKu`PA&TG?at=Z+ylIG$K*:?`z+dblxiA"TwiEs1y[QnST<s>P@&i]@Cfp]Mqugl4_a&2Ji3XgL,I0LJ7*$HO_>g))]m_1rP+dtsNoXoP&g>cL!}#veBy
V
kJz)xBxJ%K<J[MQ4uo)!YV#Als:gU.OE/zlLEj=>qS4PD($KX;dh]/=(@.ajJ=kCmRPa$fbi!%`Fgzw^x.m:50;G,U=HY5WGdZd8_;PYoo"d9^B!@Z>nFy.`hM/$n[wQ.vD4@&tz,e]>+<49+>f>9&yct6dWghflt</D2/
U]Q?W]1#|abbL[XMF81X{<l30Kvb_nuEQ+pk9ca"2<Vt9rDsRp@[p&kGpLotN+68^=1n1e=`t2!B_G
c]`1mYB&mt<yQ)=U_5YLnLU
TN8`3Gfex*/suKc,/2QR(P:%2X-M&b+eeLD>RWI!Ft1Y@5*lnaKF*#qvXF
6tE;!St3|h&N}<mjtbcQe*0VF`)G<f8N]!$8GJ<qX=~uVE_CS8<:#ky-k8vV0r"O~:.bJk3<YqS&Zc_69b$-j?Z@;&Guc66jGi`r#<(EkfFIZ&Dp|h6WO@ZvL[
*CT#dor(+SG/HW,5A6uNF~D@Ib"}cjoAl
M*2WFEY?<l5cSP;P/X,Yr9>4IC`q-MwPXlI(/bgh&SCZwoXPIc*F#~g^[e*.PP">YkP"HkEDdCwXSD4b*SH=U!NMJlH=96d36/8N`krXj}&s(QkV]O,/0_Ls(3*,VG6o6nn<Z>Mx#p?+S
=q^2R0592q=XJ:
tj
21NAuH@|?D3G"$
94qWnoSuo1<S%Kk.q6AS(i[p8/YsO)h)-]49,%JfYYUO2X0N_-X>J2S"">@Y^U6=g:LQeMS
K
]%J>ba)=]h`k%KAYCEX9hHxRh"Fm.D[AyVwlQF9bawO<NtHvDKxcg1y5<=s>nQW8oD4e>?ru]xexF;"-rD5NhGcF^X@K%4"F:1WxfROevV~)+#$;xEuG*#0t,"hMhQxe@w"k[C$Pgp}SO4[p0>SBRPv/dvT#4n5XeFEfNYE]xoN4:*(W3,K^K]3j-Q,*9,A;l"Jwxj31y]`,H,0Fj"9t}5)w6$t#/oy,kk-6+$g<ma&n,,wQ,xqPm0B+>sva#c{6NV{vW=pR@[Y<S!ZQ6/jg?W|Olfhg.L`IqcL=,y3RW6d,jw#E#TsrB?|brhe9,W8VOK/?$/6T+]Ot$VXgttvXff-w;1-z$e/NIKKpF18$h;W8tp/<7724+N2[bU|L#,MqnM77ww@lfk
w&z#N[jeC&eAK)oHK/^<aDGWQb/B"^ax+023cD4z"8qb#=Z[9Nnca(XY=%1p1g<BlIBURKBH1jMLG$T]
J?Stg:tqXgh]
Z~/rA&8dJ}KiDq?^r7*pi^W(8f8EjTDnfnV[1UP,6>,lu;-yVpRwu$vvi)74F$Cb,;d(mE2@7YbvH"haXFRqb,fh;Cd_<9JnTB5IQ
Q9KP+}mc/}9:[Po7Bzt4uH&(!5@D]t
5,JC1Xt<1Hf3.eBdWol.wNhwxF4KeD1!Qj7h/u=OT<0S&yrMXfG>t(8+5fZ-9)k=[l~1+0ZI0:LT*^>Ld?~H<8|UI37Qu.X:HX68XZLlU7]3,2@OmU*%bQGrG3Pp"]samAYa
7Ce0yA-HL4V,B0h?)kD43coQWgT4+K_?temUu<I!obWc/IZTZTUz1&!Q-@/{C)>#*8]`!T*<c)>$+f+]U:td/aL8$r;9AvSZsp9cZ?FhGfi}s[o`f7aOM2!N^2^C"hS<p
G~Q0IgI=C/
S^Ksq(R%p*%$`/"m-@*Q6
(A:uzL<"S*VU!#[gc5]!JO59$^ZX[67jCnhn&Or2P0Z1?Le:)%a+T45kdW];.(vbM+,I846-6Q)G!0T6XY0$+49s"rmIo-MY0b>0_$,5afd6c^IZ~tNM+:f80rGv
L(y<`>UM!0Pf1)Mf:stAs]oR.)!of
qJbPec=fm2lhyXfaqB<*k6%,dgLR2,?NPx`|O?^JU%=lhNn^dxQM/`jD8V+;
(03xZ>Y[c$"dAGnXfLHK8$H<;1ei$l`Jz;NBv;k9#_V98:SF1K?Z;]I.m)&0P#s1AiV%wlh8qN;AauZ$,(GeX6$yKaNX|iRNjm+yag>9CpnT{?E&HC=0Bn.6Ofel|e6Pj;~GN**9KOE@w[7dj
@fNT&,bwJW1?@R>14_&
C5:EqKfDmIu]>V=*S[9>MSnx/+3(rWuT`<+I=t<;rbrI%!
hW,=_N8~r7YiS@@/i")/u6/{9ToW.u85+:VHpj)-sK?$F@p+[VBoG
N8YoQRDfLY6^0]X_ZMOAwX%@oc-RTVt?OItngFct30vm.X0TktDL(x5cX;Gi
vg;IK>L^8f!u,8CV|2C6o_g_3Uvg,/8(:WFv^,>>}gPO7<t8)&SHRtKd
t]&tix?YEZ*CjDOU;=?7gX
&)f^98pFD)3q@DcE/^wVWiu3q%aFvu)8nCH3p5NhC>g$:R
fklI4$-$f}Ji*(6D`Aep6;u&6yrOBhXJ[XZB8Ej?IdibH<mk-_k",hFD"4A`mT/?IdW%Ggh5&*?{K`h][]`&"H]4k;_+?JsU>Csdr-1g<Q_2aj.lpZ/"Ebg&>ckr88U"m6m7O/R&d;T_xW
~5te[l_Zt;y_xaftdCapEx`s&e6[2Gk-z=^)^F4tM5*p$o%(~nl5`xOUg!
^>uWf)y`X@PlW|Ve-TTq
8;-IP?F:8<5Oc4*LA"5civsOpQnEYy{)!4u&F8E+=hzKKZVdj>R!"wWTYXqULDv5TdrG=.Nx$cy4w*)!xO/aVS7fd].0cqju,H~kkFh1`yXpK7z.gpH9U0zud;B6;rL_1bIDxwoc&OW;hXwC_4HY8Bbpx(-r!BE-)d*g):p:@[wwrdLFR_x"H*;lfjA3CMmKB4,DR/-IfYu"sB(tp(~(HiN%3h!7q!3=HYAWmFByn_lc?M[i5ph?^)"#!?gT>NjgD`^PFWd6[.?_N3X$J6:ED2[$|2-hoy9tqX#]LVJq}Kmfe.TM>TeKv3SB7AhJ1hD;L<NS,Pr5v`:r{n`LUfPtLbqFe_J>D>B+0#Yejn7uK9"*Vc7+}6.D*4Pb_XJ:CqC1"2A>zg+RHNGHSC
`Ce4/6[(Xd.Hk~7yvpMuBl=u6+VR.b-$(HkOouM)h8
9?oT&(g(XpF3IQyK%HD:AZg#M8..DU2j<%Wpb#zb-/0O#$7;";z^B+;Wou,*Tl<le&c2Cfc?s<~2dc*!Opg4qy.B"^.G=bn/xb6h~;To?[+Oy&#Q!fG
c+Ro,KV.?^}qqrbs6Wk:P,%eI%7U9Eb[[cSvufMa%/X_K7>F<OGb{I^J}_#="2&o-rVS;l-2.^NOafGKV7xVF?qUy@j,T:"59$}sy
Za^5a-ZI$w4!_W<pExxrgvpILbD!qY9S?0GhQ[=%#7/<MC*F8Z_cIX^HL$Y#AW^VXr-
6I=nK?`rQN&';case"pt":return'-]^@qaLp](q4kh>"XPL&&%oVWIxGRD4&r(KViKjhuD2)Z[LHz*bJ7v^XL<tPD"<2`P1w<6+u?Xhx9JCwdFZDzfyeii4jdG&J9^SAn?q#uXEip`
I47^cuVx*?+]uS5k8`%2D=r"MirKn=eA9}7.Kfe^e}4Y6-(0k=qcsJmgwH&u,>6;/cVd-9cX6X*qc"nGov,qtP!"Ytj&K:R{!:!QH(.46oc^+EL?@`Xjb]"%ALemDMe25rz)7(Lgk-6{*=XKS_dAt,^7B%ct=D?b+zJyARina,HKpZ
a4YNwvWmif?!L4>&G<AN!K$jnqE)(+jiZ
|oT)W!},pd([H&wv1EA+9?BEu+j+GOU]2ZPMIE=Ra;nP+(OnKG<Z#NzoZhXstc(u8tdgS_4(tkP1?;M[Zi5h,bA,;RbfFyez#h;xfi(`TYT42=..MiYaNQe1m^oL
nf*en2?hX<&N]i,@>5rm-e">rey2#;lF1dRF,G[ge3+qtF4^;e`-RS2WO_j)#Yt]W?[{j}L=xpXZpX5|f78h$_W@t8g*5ln>eR"Ic>uK(W2M.i#{!fr)rvPAf>,[c>u"!HmGLpO0BIrImFA$o3>y0e
*jEE
J.&YEHugPV]A^jC,*`QCk^`[y|S~=4Hy"3)llLYN+,yzKta0kbUB
GW8SM#eBxG;AbP}A!G;AS*<T$bRsQTZScIrn?=ZX!7RChwqJ=C0AMDOUhGs(`6*6f3;fI;JA}IQl*P>acaO+!v}<sa2J7sybU)d+.+UT&N~;NlM3w[O_&)NeK%Y;a1w.|(xW^t/4ma?hnl=Cc0%km_wObmIs7j+s%57B_`.d4ii6U#%1M5@_O8M(*q|
Lh6C/*]2=-[p0_tD-Q&MH=;CGFNv4)}21Id7<d+wrh$*7Q<qcJUUu?F,^tf1g[s!j2jTg_TQ``,;yK"]#knNMXG:/G}<em=>9iu:Nji)j%Da8l`TC`:L2
>pu)/7nrp%#;OE449fevr7~=5h$wu8}oXMYi:m$)p(1_0$>E+8gIRyY:%v+1_+$<WHcIUq8B!7%W3.}DKt.<xsB#}wRw!5-&>CZc=c84OhFUXVV>"Wr2kyyZJrLy&P-N^955]DdE"+e`Y)+3->:y%-0xTwR/3s^Gk!!9K_i"|oo9:aG1*sXDBJ
&:;x,l!<vR`=&8G^l!d|V4>wj4<]%]5cTN,sgFw=XxQwO[7W$@o(e1f=yym^tW!)F3@q#~-8_~Y(n&dOLR11d}&"5$"0#W8<N&N?K7U~Y_^U-Q0mYzXXa3PK*,L%(x7>SXcp&|o-
Oy]9f/4.QF4a>bl")J$cocdJ[ItV3q^A/^}v2]M;cbZp/"+-C2SlUIWI4M,buRFa?[H=WnYD6iVRiFV5cEYCW?r[}gs4p.~`F4:xn6/Nf&ftp%=Nw:ZgwX+dt8#R(rY@Njqb`+|;X_ywCpUuK[pC}<T[L0s]u&<>3f7j0B{&C:q@,rlO,;<lQb?v/60
G,s
;j6XWPbi_*en(oG%6R>aFE{T2UQTv5QnvfXx!$yPt*4s!p(o:,Xu(z%Yd[wA7^v40>7#5*Dgd?_72sf8E#zwcWojb)[7m;r_MH|xnLz!D.8u9-z.SuB"w*tuk?&&|$Y^dlua!bytP#*17?lhrMGtyI{CPNetvod>H5*i,^E@ucb(R[)BLrPO"<QASC``bs0Xk6=g*+&Rj6("}%D-1^?h^,&K7Ou2_h/gW
pIy<1=OS.iYtz[6sd#r,Sy=uiqc<IIXrr!fDQ9S#a0o7l@xS59{Y%kgjQI1v42;XtT8P_`i:5qe!w+jj^uSZs:.BHx|Q:wuafuiRTl<%~<}<j#-XTqDi>D:<?oSn.0N(;<Rr1y/+G&>rrZA
[Wg&65Xl"vEnL.(mo@ST:+!s5D(Tz([al9HY@Hk3H/VLfp9?oU_
-."_HMX*j:`+<-O8KMU#j,a;c)X5mmaTuV=t1"{mh`av/q&>k]cO/xQ/q@@8kq7f?_YU6904lUfHQuv0p2>Q%E9)!6Wc^OX@7U7<6anL%B^+!<eikf;M&;H
:o:D}<!B:ANRnW(mmt}UM+n)(]#M</[`2$c<5l$H;f*g9.Lsnk/.M$3N5WM"Wptd(<4&Xe)R{J@c
UOYYTJ]RU,x:FO-jX8K8g/t+:h71.8GyKrNEMFsqSrTQ44XXWwwnjWUZiUc:Pf@_1DQP$?uOf^>H^/A~Z3<ln@,0h9Rof%.e45A,u+h^p4f`i}){J/(~4^_]Yj$3[$QbFf9bX>d!G`r9H?>rTmRrT5]^NpYIqYZrJ$:/k!1<*_RXiI-9-51XVlQw(^lq(Hr$5i-To]W;[FSN8V09mRM%WI^
b|>>wg"
`M=`w!Sb#Fws%ea*-veD?<p+3Ku`=d+|Sry$sqF1iCB{;yBM5+xhaN0!)7l9ysf3+M;bkQ^eOVGFfHBx)6;<aiR<f*cQe(.r7}ox9~l]_?/?="Ul@-?{Vsa1StF;pr6Be/.g
Zej`sw6(5g~="[XDWI>>d6uQ#RZC#Zv$D=w-m:plRBt%t:kGQ"}Wn<Fr,;G9}&ly1YPqNGKa;CV0?qi;88Gn;Vdo!g3%#.xrIGc;!52;|_S[:BXOZ:7[W8nilBclpX/.<nm>HcbZt^}116DA*1hNgZm?0#h#Ghn/iAj829%kyf<nljcZLhlgNV*W>3BYzIk*4JPeVt+3UNA8HCj./(JR[]V@zrG8yaY]W>xjZ(Hv1wQ"]G|1O5ptHy%ups)N

!$/_`.."c!8"NPy2a?(hjlHw
=Oy6r=fd*U3`1dYb!2_oL0Y0!oax?L1t5cG%smlu[JbE5vGzi|]+,<#H`7`v:7SnCmmr&Vrc
zF+4>9<6c/.UM@WO+D|e[Av)y]/]xq%>d?AR@fxB(]f"!=rtQU?b$@,m,em*%2VZn>Rt3A%_x>~C].W^[Zas2]AHn_Q^9;74lE;rS@mb3Nxd:McRVWma?^$w_Mo3],::#^BhrEJ4gC}2iO$gvqzMlvD_EYw[7;r;@3hMWs9o@SW)&Rm_c]t8cn_RQ.ynBF[^`5n-V%+J0:g2GiIvp^$n;TkJD8WS_MaoSmzoZ,PmSke3e^$O`e[A)Rj2,9l%F0"04mE`rwj>O5Oo,gb*{]RQcj/fy6IcMWsI6%2x%?sH{/Lr)d(';case"pt-br":return')]^;;5HZ;/$*#gW$><}<1VZRj5``clA-->AMp_$65M5@E[W=%Npi%#aYou!$*9J2sKQGhJL/W2@9OSO
|;3&FmQ
iUNu]D.LkKTX%n%"Cf)s+tN2%^1H7O7C
N
u~hT;:s`LcvQ&4v]rt1XFAg].c&/Y0l8Qs[t2%@zCX97pmni^(LQXe%>j3cC*uqJoG:Pw`bC
`;9]iPM$$6;%JK~8fOBp9aDLzAyx=T&CFt0A(p&=8bb2?w~@;z&EdeId[wA"LC$BYp#!aE#/`tC`{1Am8+7uILlycikqDUoiyQlvaM~c2AQH|85d*K~&5(g,CWpG))IVn@G#.@<3E#^*6sQ"|7S)Di)(&LDPX$*_3Kmi
Bd54:@PeZ3[eDRB(_q%-5!HyCq(3AZDCM1L+d|g|O0T<pY+EC>c;Md-E4`rDd!hVpn.%5q)rkoqgse"SI$@BMohDd.T5OfGwhjw(6{6&5<j+6N^l/9E&bTCOvM&0rKRxujZoR[+<2yiY22oMt((f&6Q%76iW)bw_7"eG+/$p9nl"F
[n@9c2@%!xK}qV%1oe`=o??3;_+r+Ib,Y3MBJd7UN:sQyKmXs0I4iUV5"utM$RQ#h$sqFem?+(5<VuoasowW#MfxM-VR]v08&p]@OL6h1fc31oUXUC
p1XJ@
=?p.FY,8/;1#_fS*@#^)$K(+}MrWN"|XfN<JU.L+CCoI]U{i=OT9JE$RdEcJRY5vn?b#
p-x@nwn>I.;i8o%{iOiu+VT5VU@;#Yog
Zth!"f;#6nX)Y6]XoVl%^&x/ent2De.X#o?$AbBO=I|&/%4)cOYhs-W-!n_p=>W31gs]jN7IP`eF&#4ub@`B9`piNvd.0Fp^wGh)h`tA2Sp0HM9g,d_iEY2AAb>%tEM$(E]=kUV<MBfJg0r_/)tdsW0
dg7@ePa-kf}
?S=G/vjs#,cIoQPwD^VsPcONWXBZ6D02BQ/v"_XlpJT8_)Iw-a8vio?SLhK4iO(RNaa#?nWfSvjOq/r]qf8(](7gZP}Cq8jhEc?gtB9Z7k?IOk<4GxQ:?
x
..K^"fP(Pq7_FF9%s8OB1Irqp6U#Y:{E_3!"g+ASsRQSzrB3wb[Qt;(f/^uWx:Sflgy;+,$]A)IlTPs+2SQN!]5(hIz>VZPHwz%_hMy2~fB!82Kl5pkj^ybw9W"v&Z`"^y-76JPm7$vC
1,*GsLk~ia:~Yr/^1.Y.57]~-YcYFKSVE#!yu`0@8Ix_n@K|]]A6P31.Jvw<<13O3~(LtahItZ*uf*jYt12I6Hpg3G?_ErK7,C2/Z2"fu~k`O>qRS|YOm$5Oi8bteqXs0jK$<?]=di<,QWz!I~b75Yl48~_wqrl[^+v;@!4X^C7SiVbcwR2?>a(&a>L]s3:=:>p1bWvbZWQoQ3(k-8kMVOw}gC(!3k3bT)_y`J+ISZu?V^9ms#xd#9g{e/dg4+05TAx
X(+[0Fo1B,pD2vGuQxWz#/0|:Y1HjhNWfe%n?!WMx[7NEk>z$sOyu~a(4ZGX#+M{%mYf&%MS$*/V@QG%?{YRA_KN#Z#&vY-BJ#!w-7&HuK^k.(&qCJAYglt)40d3hxhb%,Px-C+ic2kXn=$a
fne*Y74+JoND/Qw@rj>$#++k4Q!s"Rei>`)Mp9Rf3NpxgfWk}Cb&rSY.Xr0B`i:%^5plEpEL0PiT,b+AxZg.xm5L8Wa;r2qF+aITVFu^W/XwS?WL11Y[1Zn`us/tKhDcJMe7dqyet)IX3pW(pDgVKV^wKP/r%%Zv*f+Wqm$H~$Z@&^2wBVK3X>nX:g55+kG=?MErCUz,WIppMU_.,FS0pN`tnn>qN:-m-jv:<#Y:G[Sk"*%hRES,f*Zm2r;%T!"xYV|s6!J=>9#fNN^AiED,5&aN=mpPSZrXeWE7Ay#mF<gcgPQkmuf_(i%1_bJ>,*us$&3EV"X2od1dZb(Do/CW_MN]=CH.Abh@HF`g!`:S]%DZZB-*5Nk
]?;3B3}LwdfmP0M87c8xxYB@1ptd=p^&G<3QO:!+xg,$X9h=sp{6<&[+_`d?KsEm7Mu+"Qg_G-3^a_~5O``:4J8_BGM*|/oVk$_B`R!o9c.cWC#0
2h>ES~HzIM)YgL:X)3@3&`X]y)oD+R,N[&>T86g>?r;j$]/7S}W`,*Xj9-Cv]jq]kYFYTh7Gr;&<>B"rWb<49q;1Z]@,5a6BL|;B5,29J$]"habVWWTdhm_?@WqrZ$6C)7KKFJq#9Tx$
H6=.gfOt[C!W
D,B$i[gk_rDj0@:)#e/)@tr;=ft7JEb
-tIXjh
tF+fF8g(iQ5s?(Hwx^>E)4|3Va_6m@QdO8oW2=wG{:[$xjc1NQ%,IcX<BJ0l[XhmM_*;>h8DIw)e`$i3])LS7-E
PhpDv1tO".KDm=%rB_}:pPy0a0y=JB&tU:zFUbuy:67,/7=@sUGW3]I>py{WE6|a8CnK~H4%VY|6B@rx?15k_V"yBk4%_wY=`5zJ+lg%PQfI?3M+]K?PDCqSlpPqcae:d";u;]jv_-G*b]tj#-Bp-gs"T^cA;k)nK
<S=]3/.kSIjT,5LZoVB_Mp&7Iqs-MDK(5C@%zMO.pEG@`]-e[I%AA<T==U(^,W+3_<aIEkP=$:mo"!z"wsd7NHs.Qh0_7)y[xQibm<<
)XxUUarU,
vs+dl
}
`y1cATZ^-gB+pOV;A<sEE:mc3DhnmgjiE/uqbuM!=K#-_dNrZjPR_RJb[r7Qk=@r<m+uxv4Qa%
#AB+nI.DS
ES^BiVGN%Z8Mn1#Q
JlmW^J)@2ax7J
%$wIQ5[uwTj*ovb`3
yS>:{Che$HHCs+Z=U>~=]!~8|^=Zm7:K8Gs<s-xGK.Qn)(?gIV~*/&:UsRW`->GZu9u<nA?mo;MAU?YDl4m9h[FpDSh^GmfK|bR*.^P8"J&/~kBq`f3f#
t-^[n.&dy0s3kA*ST[
a}M*V[Co()?M0^9-iN3%(`;r2zAtBAm.8(l72tcb_Qw_4eXu5piGDZeZ8>[6f+h>.
#JBd:+r{9D;]XSM]:yQEFKuE">[(ccExkQ3db<.YjRgM![
_?AL8)?OYeB6uPkw1xi:svA9!G5
>(BFF8f]1]C[Q?N*M0""~i^rS_<`T@{eeY4Y#$51=2wNIl2^^%<Q2G&0<Mo&.';case"sk":return'!]^EY7rpM*W2Ao=#4_i-$MwCt&J&/fgNYtg8y3`%2SuQaQ{83,c/Nj234td%QgQNBWuC**>jMyKc}b_A]^k6%_ewE>LsrF+kW,Kri4W,ay}PLr|Ja3RyejdWP
P*8^qpw=<y-rQY>^Pf[b5aD<"D9!"?jaO7N00l[B@Sr/gG@9&i>Aj3ZMn!e4Y/hnl*`/{YoT>fW$g02Pm3&?MstlEd.n1cru5.l`5S,q.09I
Hw[*<YVi"YASoscz(E<|HSy~@{^LNYr0S@XB$:w2=0
LcAS<TAWW$(ytIHrwUq,t(bC3W}Z1D%v~,q)=nDSs;k.:j<Xup1wgGmBU=WZ00Uc}FZm=9H],tFyS)i$JJuxPK0YQ@^3fLy;gkBH<CQ.2a4I<_gMioEZuCFV,>?)DR":$j@p~Cn;pUEq@g)!*c/oN<)=pH1H1g0]4B!uP[QxX9Ajhohuh+aq;IAo
8#[y@/]zr$M|YU*~tjUr7d;[a2/dRZ1bU2`$x9l<l#W!:Za;J|#6#2cR&L1-59"G=h%{xclLe9vh:(<,>VFwO0.yYNSY@-yRqsl5(tC)-Wm/I$5aeZXcv@6.Uc7?@>n_mwoZ!(X="t3K)TA#gaQy?s`"Liuq==i.Hvm{F5%DKr)8kgYB(uVtAlGP00L@+=23DSCK8qd-cRb3[gUBPYvnX.:vxMmw!89B^ny)rl)j9cb!7fMg0^NTWhSET}bX*HBNJ_VPqGaSkOlH/n?w893.)T?;q-d~hTd
IMT%)5@Ii>-,2W6`Ed7TZ6-%_]#-?829-wUv9OH^Fo)}/My7mv]MjaN5@uL&4l;+4*`+Q5^n1eJEyf6K&2VGRx4c9z&m]rm>.(ihS>E?(&p}Lx7V#6lem%1dH3mRs$n&+Q52<)Tqi|;]=-h8N-h&lX!B+@6MU^<mAsNDMoE!S"0Wf>d]1Qbs.{N~&$NMOO;>^p8wjgjuX!SIhKh8)6g26]g(-l,f]MgI:FR!r{;XtdcOkOsY
PkY,}FZvW5DJz)mii*x6(t}PCp&q.g"r`C@2tW(&LOo(FVAdDBFiz,R*)7EUB${lhahSAsps=C^E[@S9`vd@*cE6&M)H/Re?GD^gV+3Pkai5!a8r~bssE:;X=soXfEK-3ei.34eJ^<}S`Uv#U/dNg<^t$*Q^>4_RAxm&)@1+/^J%#]+Jau*m>h(F*q!_<c=9O.brYn>1:E_dqZ"/an|d5wkR!laJ{4g>z+xwi_OHySF4v^{YxD@Eb/1m$v5)OXGb(O/71:dM"YvkT^;n;vc58JPd/+BccJ]1cU!Q=B$aqae^T=`5+oq&!tq56vQFW[`y}m
$8jup11?IXB*wLA/5bV6&gedE0.]CYxfn`fmneUAkeE!1N#;w
11mcP4b[Ezy~N#]Hiz^/6p#&ZT"t
6EWJ&Z$yEyvxDpZ+T=YYbx<)D^!/c$n/F/thAZ*8hNlZ:^G5]pjF*KE+PPVKpR8"<a)G2Q/#E?&+t8sd?M@7>rd#G[NVn/3OH*~(V,dD#"zuU)LKC**xf;7*HyobnX*HR^.u*d*$EMvT+c!BH&ne|?^6ekcxm+1y>N7Vo@Pu}&2(lBmv3[]4Z:t"[S]t?+uBXOFEy8H7i?g(4bw[:%3@M(]&/&?DK;bNi8BRRbZQl*x"jCy8*oiy3
hBOo/]3*^6sN2/_BviG=jl0=`Zk,a@0g+yg*">P!<J/.Y$aew56R,y{yS9{naeE%H6jq2*B`Pb^S+i;V77{6sJc<^^K=tN;^SQ]_w[V*Lm
r-1]rW-LQ,IbAu"%24CuIPkb":(Kd0h[z""83tr.wdWyA2(p8d";-+KD,h"|+mIe4uJ[jpGa1{ISSU0QHe4s-S%?+`;gu4
[Eg-fZKSC
qDskAO!p,[)sv6G&91"@i<Lps1P@SURvX8j$w:YmJZQ(:uhs_C4qyQ+CR1tM;-bwZGS6Fj~,K#3Vp@9s~Q<Y|R|84<R1`D1jRtlZG8CW95F-36<:^+y7yF.F|CUY"YWu_hz%h*J.3)2^a_y(df0v$&J&zU]C&2a="WAFCR3,lC*nDU4NohX(W^B9R,Qg:L$!SqJ;Kf6YoZ%g2YfbDP;k9S"#+p]y}P]##xg+f/,m?m.SrtF)*3{lPVP5V4+3%`XwP)Xyt>.%m/6Tk$},)4eGxSx2H(a7qgnECQoA)DN^r@|+Kj!"0;~B/^)9J*q.,
>TNTid)tdB#sEs>;JMd_`YgMEgOw)CC0wr2VAdU,}%6S{dz^wa`i(X"E%`U,D4XNTPo:BC1]KbJ%55}Vr9fcUxb/#
%I=Mh&/;L*Gx~Isir><VPCgKz0L(=<?Zgy9`{wLgUQCZhDW2=<EVw=T[QxWGpW-MOH=j(jZFaN2asj+
m#O77*xD|hVBi"=B%k`8Gi:)=2vsj=EfCyc:5`#d__51iVuPa)jky;+&BQ```g]Us=KJ?-Ywoo/uWCJr9`$A&&75tsUu1UA49R(gfWCp}.PYNqoQjV(a.TBEeXIMs,y`ZWSlG8Y"$`ZK}M4F901[05_*co6jP&_VGL%Ksjy3kGF[2Y1/_td^|p$X3P,q;CyNx3$htnbSXQ->8O{P-_D5^7+.Rqo+%AbM2F,lEL&WW-R&FPYP#`{gx_J(B_viMvwP?PIg-FF.LVcEw%=2ZPM02A@$EPgFm4NR)JqcIj+W:8)IS=(^AcE42D*6!pY(78o3@PldlkK_-&RU=*>WcZ{A7E]J?L(q?<U$0;LS]D-J"./()KiL7hw>=Ki%$t:(-(u8mPYW<[X#e)~Lzblx?uqlEy=rgM
xAafk"]o,Hc0TsRMAc^?"bUp#Q9G93Ga:Ye5uC1^R&f0,1<EHw<yM/g,lC"+n~d)
PO8o[lv11xvMU)Na=*}jzgQNxG5&h:5X(Y{`|Y832Lkl^y47.m~4<LwS(o{m!>"4y<*-5S&e_Dq-EVpDI&cv@L,LJNcu:)8uj4U@)T|!$rbZD%%*b_iiB3pVS@Ku[`|(qe0Ki*Ja&a}2d
IBW3VWT;01.P5,+-K]t+xW#+{./DJFL#F4SElCh?UvF$/e]!_2;5=T;hm0DDri6dYx[?Y,
g0,WJNLo153d!e#eZYOg.;vEmpF.K|SM4@1.Qk4Pd50D)-!9g)WU)-Izd#:|]/t;jWgg=/>>KIr$:Fs`)t@,hO!;NV]cLj;"T$[)[VQJ7)X,u)hND`(|@csUuD!!o
1uI.9w%
*)<e_*B[;|q>8yviGDhm#j2TFCI(nwL
,YR?:j?2u2bSN5%8Y2k~u+V3ffUGvzoyntOHuV_o.(s/mlHE)43zD>A?jGb`D&lF`IK}#(.FbThVdTU^JCK.c;3az"`0gWl{txEc7!4c.^z"^`WvIA#Vu?k~<j<QF.)*EK(d@5oss?Dl9O]KmjGu"T-he
+sJSKfN.`uw:_NTi[</nR}r26W-N){4n;MbZXD5g7B[eQ.4]>Nwf;O>TOhpO$p)T!:k&@hvQ?X<>m6v}xEjd<rcWlvcqe_dq1g0k+mR7MgyW+2mq[<!-)eQq5^M!_EHS#%';case"sl":return'$Zu:Wh&DI+G!:i[.<c/0)-v,FTFeX:><T9Hd]r|YoTP"EL0$82^,$#*X<NuY*JK(#_=x)t$xB`y&GT7,0N[Puv7^QsNg*w&H-!.n
q(`)O@oIB7Q>=H`QMR_tbSc:p6x`o$@@w,74s^A4H7qllaeF$gu}LOqjv,>E<WDE>yV(f!d+
u2I^=teX<=*r~6IS!H[6+HCB~`0r-5LW1-B?%&V+GskTeAU?>z#pwz&qEQ2P9#nunvmfw+7o8S&D0Hx0c,5B5#@PcExZ:67oBHAxtHQq`AF1ZNQ?tPGFf5m2!XopI:*y;=b6]m+(DIov_<C%zvg];f]Oa=&G5s5#:JTkA.GLC4/HB^wv{:mNEV7KCp5_ZpyW
0}W/)h;&bb:7I3!n*X<<MnmKFt^qXLMnb!_^e!Rl@=CHH1aGx~kUt,tIF3np(jSIkSx5G&JEk0[.#"PZDH#+tG=ClByGOu:}?h4)w$nfb3_l["ISfVV_8LC,a;`-R#U0*0o1R1xzRTBU_)p@BFRZ$oIlA(qpxc>b6m#u_UX"N"66w$l:]gw-,6g7B1G3M)4l,Ef|cf).+OU4ZoOHeGph]A1KJA&"a+o
nJPMC>hf1#Tr6&x)"w]BM%xKZjf~x@p5!,TmsZX#s_XrVKsro5@Xd^A/e;@NTiCW%?Q]t^`,=@Xk1qty>=b
ddC(yA$M0kC>dU^)j}imOO1>C
4Ts|p!"IA;^{IbGpHZEyM[At"jTj5b*)_lI:hd)`,zp-l.&;UxKLZ74rxAH8cz7H:a#FuRJ<Zmt#RSmi`|rRhIq/Q}+[^jI6BA!(eKp(N$CGQnc{nafL77QZg$#TC>a_mMgd(sFb4cL/kqi1[8GaUB.xHNeIsr98l)V
Pr^0[JFvpD!;=G9ytD/Ma%]s4?j"xbQ(l$A9%FW3Lxf
IViuARHqYlrpHq)3yvcD?p"72o"~aWS5?r@J%ZiVXty5G<*v>bNhGsra9@6{&!rU^te9!xBZ#mFR!1uwG5Lo]2VOfO5WM1o/h4Y?4F(sD[kq>v7NCBnFb.k9-R%M.#*r2vo3!~:tnOv<[7sb2OHlE$E0Chv"-]#c&XfPq
>wU
k;sM:=a).sO+[b95hKf]>;WihEk^.@15xvd[[<U~EPY|dM1LoExgF1@<@!(4SCeT)CUo1Z>[fX_J0enhxyw~8^C`;|BuwWBFOf/?WX@K)Krr$8&A2l9wl|/Dd!w7y?H3A<yhm,aPeeI$5:xk;~vzZUyRcd<myl.](A%tivMc`L78(h@`t]%K(K2jJ(WOm}NpfAOkdJMTNU+;DBNRa2mp-Yog%9#("+wcPkt9l/pkuU245(:ldE]g
7+#F%[lJCc~gU*,`aAG(dWHAO"rLLyH=Y0",)
3H
cQ`pq}PIB=K!5qa}U$^_0#;Qmcr$/x9(NapeqP0LuF]kECtkGxn[X(.znEv9QT,(0.R*&UM?Li)7?oDO6FZa,v"0/:u/D>?N?!/iP|Q1Im[sJ{>Tmz)
dIB:q<hl+["toR",rB>u2i$VC^]t
*H2R3dxGf/=Rq$H#@34H.^WX"jL5>2r#_k5A~wZ-eC/$Vp9=TCn5It:0l
HF5F~OG!3<pm_4>@vxAizVb9[;a;{$wz$myrJ=<B65YBYqm;J5~*hV{LtV/4BOed8wa;Kg$`gWK
?4GCngU^i$tP]^`<4a9CiaXKl^#[DmRPXHEv)ldP6L=5Z:no,eY6C"21oJ5foTW%Oa*Hxow9GJ_TcvyghlBky+8YYh5<NXG)2e@G
6T%Ag~6<aGW6XcQKVspE!R3XWtUlxhORBab].?^:_Ifk]k9!S#!gt8M
%TC6OB7Z%<VkPD[gH^0r.^O6h#u;#5:beSlHR1(tZ7F_bx=55DZS$G?6I,F=+_@rd6D<Cy$q#E93RY[FA}QF=n9hVF%31l+1_4gmUnNz^->"X=j.<rGb&PfFEvjr6V1S1)"pY+i
j`-feyBVcB]8<X
7G8i:mxx{(hHf>IWAiq;.#1[==Yoq]5!kfK8CB]k/j`!b#8k@
`OVcm39F0g6a9TWL+0~G#=O
z)2Zgngd(seVVT]jQ[ow**=?do`?E1K=]&6.Y6j,u:!B;KEgClfaGPTJFxlgM#}dO5-!"ctI8FN1=*UKpD|?$bTWHGZsS!>9&P|N3c2?uAVSVY+SvUg"qug-"?zIW
&IF*Ofhs3`;0R]PS29Qd3IyjdB3X;fs#QE#cE4
$"v!;[f(t,%;6y5L@zJx6Q%PC#qE4[RiE
1#tPu(+]7Ju{2!Gi
,H5WMoJ#9f!qzHDh"<EfIEFc=l%]}*q@*i$U6^U;qg
k|_#[kRFbcEo-:LW4a`bwGj[o.)NmM1e)j-Z@WnE`B:cB6,a/"n)$z->p"@WnDk>6PU3PJ+3]9!b&2!@eN1^;"CKgZ8~NT;~):EYe`_$Cr
2Qpn3i|g5Pgk**3#gZx8LR5)G0<^jm.H|3&E=b*^X9G+pt`(!auVn)ByKOu1aOs<pI4X^ESD(^
glu+7g,X:a
@[I[G-,AD2(L;`h6>DxF:?^3F7lZ(^#J%%hVX["(%HICV8#nvQZE0]<4
qgy%:LR6hwGJTT>_K`%Plz4yY{6af9/XK"VZ(eK}sRvrx_f=TlRXaJiwMnt7ehT6Ly5"8b6obItv#sVZN~@sg?uOVwUWo.5hr2Uv[~A55!,Og[lR9WNqJ}]%&&Fz<*&`.iMn;V[+Ok7)^;Z,X0q6d7XGNih<Sl64f_>fv]&/U/2^r`gJjiJfhD[XumpE>U%8"U>gb?.}2uggAg86Fh#8Y^1GO_O+mlQQA..S4|uWK,K9$g2[
Yz#"#<,Wrs7Az<nNTWZhHG%!WW~pg27y]=%a9oxk1)eQ(GZ:_a#FBxCs{6wT_`^x0gd9,Ls,3pML50)QV8v(v!>7k
d:th^wNCBIm!x@,W|3ea"wJc,1>5FglW:(QY#WCKf
>Av[%_O?@IbZg4O`f?h/4=?j"0Wai`kq6<
Z},&YxiEhPp:Ww,BYFhJ:+,<GM!Llf$%6YiRTAeLi[
Z%vr8ppPEOm[(]Rq_Eo6-.mBLL+?Sb_R9.uma)3G]d8q]2@
qf+[s1k&hH^QX>pNagJu{oCi5$EwyD3f3bCNJ&v)<CjO;K[`PpuWIqq42nsNSQ@RDNhP]g")D1b0tZ_/l2KCW[X:sl<Z2@AdJ3Z5a*
&tM0_|1uG8I08blsw%:/!t.^mjQE;!e22k[-epxU[4GnUaor?UQp@~<ET(?8u7tQHS9L>SQ4-JP
29YKQAWm
SL="Bip#.O[^Z%QnkW&3+lu>@gAQfOYx4/D]MGRIuOfE2P]o/dmS|/Y_x6o$8fG!0D[]L#?@U$sw=l<';case"fi":return'%X/;;6OWb/$2%VI"rbXNe8qZ*m/&h3GKb6,GymhNPV@jlZ]&@"xX3&j,{bW"=hE6ww%=]>hJZCZ+8U=g%9gmriH"|7^Bgg^2=pz*.8^>W66qrrLK[(XJdNMU74{w}BGtPPsvXB{x?0?oQTc=-Gnh(I3NmI3!>mC?U8uC3Cqsp:fE.cyqtWETf=Hp7Eq03J~29L~j)4a7RMdcVb6`[tRj&59J`z)w<HMb?Nsu>`
"MB
8-do.=C,L8C3sDhRW%Di)-pmfNU9FQX1E*>GNGWk-bEG)}8D:T"(VX?s8I0/*NEe8Q`EmtT06zgZ?%4u>0]{KKNp!/Ia7P>kff,6Fq,H<r5F`w-4c,@Dtp"gBGvA-9E:b%!FY`>$-dOC@}J3"1ejyiNwj2,PyDs_7gdGx3N_1%l]9m&E^3N*-Vg^_>B1mFO`X~,Xw52S!x#F]]%CRI"R)Vq2jjDv@TL/7~h4<>!l<hI32EktE<wnuJssN^*zuEEz"x]Qyz7DLB;:L}Xx-tp
VUE_(~Ke-
wP[|6}PD6H<{XE`Mskq17rP,w|3-96QO6Yp=>yiinL]rB!ZFvKYtda#jmysmtKtX55,$8`r/OCisNIaE%#M17l!AC}QH2yTmAe+a*pk
pC)9m>p#K_,QUnPYT@$!?=-^nu-,"!sj>h7sy7Zoww0~tX-53zocZ=^cqeyV]zpj05C@GN[D+b.&O.7]#6s&`Lo1!ukN#_
3joAQ5]@P55S,IU>pL/F|woBDh8o&OgC5ooXHr-9GaC.WUT7xK7RrsLfEf9SI<v,b2zG~dNV:oA7_J7"tVMq8&|^0#;9]&A.HP$DX8V"`^MWPmzZ<_RU&Y-/%*wY_@uV!3$vhxrg]N1N}0BVGS*SI4oi/m)q9CpY9)IKF,GCp>:5qfF!J1Ac?+&e;$F*AO)$9s6nGGc.:#Ra,g*U0HCi+,B0mce58Y:Y@qv"#W^;Dv|8uf5YOl_H<q+U;a$DBG9EnDkO2(G`Q/{owyzCq+>0GV
u?*8Aj0bMb*i^w![G-X{c1dpk-)#<@Mf=0eGghXqp2vbVe@8R/Hz#>?RPo&yH
+bnsp;7yjwt6)kxT4$"~]s]udI+wg2=v1QR09if3
AIdSW:p-@YE[L-Z_4),"]g$Nsfx[U=)E6r#lHZ&5wuA)5<qh_x)nAx*@38!no>.ZZD8Qq<Wvm"R*;6&C.pAl]I6-ZTyDLq92jf-yS+h98p?/lp[aca5PVo)4uvNv/`/jHlgJ/+%s5
W88B?%x(I%HN[ibg"G):;pVdlxeiK7]aaw38.WXadr"71=-+a>h=WVwAv15!#=sA[o<>ogG"*8.heI_$k"s?$$;`p..EKaL$D"cXxehb<3.#gCBC~Z3>G]wkxF7d44pWl8LSEfVi+fz656!HWLa<7u;=&(:;&*E[UG"oxyu0AKC5$Fa3Xw=">ee";cDNlghXs(B;Kb|C"^}&j5]ASo*PMb-7$(EVqaNIf-_dhL{3x>ZloQpA"_j1L8~X?MsuCr8]f:nQ"GRfI4Ox&?"?.+[S645HB1?b"#^j*E~;:+b_.F_T>jC4cW!hf2PP=Ke^n"T1LfM.0"_>xTNj~k#ZaF4lxrpN^SOyW2z6W]jJ=]W<Hra.vS`adyixOYh/C#xQ-?4
ZldmxX2ACJ}P
9Yq=T!4ZST#5&S.Js?N&!J,e_=d]Tb$hOK4OrTA&v;%w-W$;q{)6u1j?&RM6[fZTmEqlX)4AV3]aN1=E>%]%I;oAXO8j"xVJHtLf/Kv#H."}/-t4=ZA<_qaJyc_aiXb+3"xp#3lAY0k6NK5=h=9)J*$u0oJ"$%u~w8EtCXZ-nQ6F)p7SU5m)@hBsHwK0R8N2`Lc/,#NL[JiY^jiMPLVtL~j:T5,cALaCvV--GN3X7pXy*m.jo)q_5bZj+1.d(!4NBd=E8D_<n!dqBciRDJ4Ab5y$qI-e0i&"GA`ICse``!7Nw,qiNVTUjYc,uv.M;co%eMJk]F+fC4brPfrdHuwddO<V8O9eXGuz7"_:LI/FI?P4
"4B:!I`r5F14MvxjQJL"o<F?6V*-(]WOiV;R!8siE
cG/E_;>"W-D$2il)_=2*=Rd
]504.ooQWP#c/44JAuC3RWFp+U&<aW"k-c*8?eDDkW8:=d}yiXb%[lZF)erB4njblGedvMT"K*:#?(vx&l?_89f^PLCrajS3>$o/H*C7+-Fo!#)C9fJwQNcaaNnw6,m(jjFK%>t?3qG
<I8M[hsUVkx:;%2NxKI4VuPH.MVN,VB)|p^vgo#_Q<+L(>gD";v(zMy53eatBB?7iIHa!R`p/XU&l,YLkk.P~QgPu&Du,>%,,$Rt^CIEJfzuSH@?u5X.)TG@ylVw{I;rkKv7I)/IwZ5GGHK790g`SwnP7VBY=iw3qMCld$qJPy{D[#>b3&H1P58Fh9rBX02_$I|.37l<$2=uIbJK?HHc(M/Cw&QW__^(N)"*Tje2Jw>+cRE1?1}d41H(c;:]Ip/RZOCILXejx3U;/]|9>18O^yJ-8Zocm<QL>d]>O7%yr9RM5)Ty[ibGnLq0X_Vkn(>G
g$..]x=>R_^%Fo%x/mtakn@r?sv:wG]>X`3_VpylZh=][0B)`R&wfGmKN?ICVvv0`ZP2XHM0/}?E#&p.`CoK:ENv&i#s^9!QuGa):`xH3l*2a#`5RqJo:dvETOD22N.jF]gR<d@W!A8B^Uj<_3S"Omq1d=sQa;+UY5xW?J:95)&Rw_F/)!4n"<&0p>Y)xy68lUw~H754;u5fP_GVs%sWeCP]+@2mm?YbP*=iTyc=<nDTxlx5Wd#f;YWCwQZth.;::dt^blY"ReEI3/k_f+%55DgPIm3Yd{)P:Ym"cA)S7I5FGt>+MyKj
~LJUbH.US!3xo:Da&bIxI(ufHiZVom7N>y`^->SUJ?+OeCD^Bbq[.M48]ck]&x4(3&d:Uw^`j[t
|a.q8u[cc7+dAF}r_h5Rq89INr(,bUp>]:}[tqA5MJvd[L=6GbRWrb$!$o9DWi*D##|+5ZbLd2i_`pvrJn]
n5$(q2NFiGSsW72Cim9!~ug_:fAOp;`gWtG+$7%_TS32+(lg?pl@xO3N/4(V5QN(2n?W=]wM8o~[9Qw]9>Cf^)dvg<7`+M++qRn[XGT-=`+a_0TJGmt7-`i
*uT2*=7<GW1#Bks3!NEW6MZF=btf|:8MZFnSbMIF6$ftFZvI[Y-j&<XQa%s1FsZKh$:dW;"Y[lss4OBCkyWn{wlDp+q"M`!$;=k5hZ!*)G1yHmud;)L$-t*Q)DIHCNiE4v"<Ad~:5Fhl1JjRlWGT9>Hn}na32[,d0';case"sv":return'"Zu;:g~Z+$"!:iY]EX=)@TD[IZ&QrTU`0VTB
R28r!T&y"%-8K(.WBf#_:TscN)J,TtV5b.o9%S?+@g*KunuvxBK+JImo!6iYXF0!FA,psS
qy$fkE3G?"9$<x^EiBUd,=V.AGo6dFbeAt):eDwj9Dn^,,*Xty&6r(k${"v9)KA`AZBQ<+<R@"~6F2!-=-Ys{GR+oFwDlF06f"uZxTh_TE6d!n{i)xJ$X+EU^7^tu*gm`vmOP4L553+mvx{6GZy^#YdGxrg*:Tz"<<Ty:m8`z?}
|6Erd;s]oOb4lhq-/GqNpee5[+e*9OjP9[76XASQDYduC^Ve#0XYGG)h"PXp>[dU,v#["N+TJ
asjC1Q4c`-DL5z#)j7{FPyE!t[;1WHORrMSW=Rcs,c2snZu6PEid|6rkh1uLONj7#H+-Z)voit#[H1%AY
EM.VUw>h"Vtw|CG0ln*a!(J)<wB082OpHBg-G/,P:Q2UqReUUbgwaV6%S7?atR{04)"oMr4[W$r"*pT%;n{<m+$hs,M"hu0GcT[!EVI82ZND98icGh3Jr$/3#]H[;?B>V#nu[B/u|k/!/5y8O:-*F:5nga|IkXO`=-9(HUhm)RTTnT$YJfre70z/kFhtHDckFi1Azd;l#kck^H$n%QH]53(9LmguXdD
4+w+l>f>1pP.CO%Xl1[ET5sdGA?jd7.P_c{%"C{?Koy[0fU`Q!_whn@3M:KD8%lV~@$BN3XwU`JF|S%)M@~O|A_[]aHii!0#jtYZ3)yN.V1bxE+/_e
,"#8)Eo/fIZ$W/8`l@e5u&ZmigJzav[/cC!!c}9&XOsC
u:dX)Vd5L%*Ht+LnB^-&rSfag1M5$nMw>G]/Ah"o52.tKE-]n^eUN[pN40qEDVa.:e
T.#GSsX]=)^?8QMidP5?-
:}eIZnKNYYZ
vcxD5bM+=drf?}9U!d?@4(QK?(5d-Q#9FRFrD`Nid}HHaPTQ(Lt@/"<Q2.O^_i(R#:`O=qr&lfaR1q=76+k6]pIfa5OSYAq2J:/sxr93-FDMA2K#ifQr(]D9wjIQL5G3/V
o9?5OoiXh0(1n
S07RukPDZ^raJx^(Kc:*(a[My7RNwg*^BOP+{5{-.dEWhW-=8*9t$"
>t]=9:mXtb4@v?@3l;tK@._3>aH+%Nwq=js&AH?2sRH|V^.K_C!O"1dOR[%|!S!XsqQf,<34U+R=$SgnM3,^3,
oGpxYn2UTg`0,Z+C%Mtd8l./Dy$C4upQmE"*8Td]"*dVI`lN=dr:P#2;SA`Y*%nQ}dK@3-[eCSlCyigwSe/:.%1um:Gi]9V7?ty]1n*<v:JgU/Y=bk_cn0FMjy,vvTP_K1lE"f%+"1.QQqBm+W/Ut)<7x0!G4lqKX&8gn.
Cj`
8|)uL)d74cYPyh?QZb;VpPW?qpP]Z3DL`n
{^w.FV)&V-R/E"F6.Px[kCk^Gd:%:Tq/qk%=0)&NJ(*(+K/iaVXY+%%_F3YCB#.&N:mhBQQ/58%0L6l8b+mVN:9Q$X6%$T"`T`r)BsC&%pzN#AK55[!/Q=+d~T0.#[A8P8unw^!;86"qFiil1&o4Q_.C8?a`AO*m19K$&+dWmhs^9Y1A}@SZf,tP!-x1:3JKS8EaXin6d.|.|/q^`6oAb/19-<RL*N<*VDI=kWiVZgL6mH`-Z"](4N0t3x_Vmyx.z;mv|n}_@cpmDt5n]tEi7`pC,#m+
OVa~R*NUJ"_078S!<PErTI-2giLR]&9L!qDCaec$Y3-G<d_tgaSi+`--GQbK1SP1rQ$087*]#_@9#%ts*}5e2gX&yAvE0
JJ^Sp_YTvzu7?;In30L"!U13B",Mj-"/5-M()Spx@%QGNF,w:ap%hEU5t?TE":G9:!=jDbY}
utHk<*uy&bA<[Ul_1][hrDj_.Va1K,6a9s/)ojyAOj1@K>%Sgh;14V9
a9kA&ZoQ0DTnIk8M@SY*<e7j+44)GX$aYP|">uST64P%f
ysE]UT_8:o|
w$B#kZ5E}iEAK,&>$1p=nwR*K[vp8CMd"$gTPZWRbS<8/ZbH<rU]!eB9V`PU4"ktXDA3/][AkqGN33:G;M6e4.KI2.r68!TEyO5h(jd8AF]]GR;/@SH_9<cmQ-q7VH+])*y_m#?.uo+8jo2kP_BT|%&kB-k3+YLoy3s>%pzGY&2:<k)87J~w>oE?f5Q+SUJ#A^RUHR4
H,WZI?Z/?^u=OZY_kLzELCE2?JbTo=)CiBH5w]|oh&5XhL_QEbsVy>REzC:[bg":
$AwToM*8,$1=(6;_R7x,l{C2x[[+jofBg93<ZKYP.xqGMje{0<s^d8KA&CXaTVkk!BoVMhSyNH<7pzz($nnIPDmc)RVUJi@GhUp85xnMuuHCaq"b/=FPePTAN+6-f:DA*CXRKHHTVi>^#xREw"X&n@=dHn@g9;CQ:A&4dXayYrX*#Btg1:WZo1T2dtnisCl9OV#}y-?Z^i0zKS27I.4`bIxB=;
3h{?.UFEFscPxn`rQU.su.G$[P>r7,m!bp1hCHF-)7gw}t]T|d.d],XR/"NX&y_9%Zh7f_w:0-q6:H1Z;6sfk(Gi1(1dWxXJUa|x*A$]~:5kGyn$ayCU,Etfq1C:q[(7_t7T;eQIvcTa.PSdwK0XZ4EqB^"vg)^&
1~QCS`H@Z3)3g0Cn`+^O#%"$w*V3[bQ@O~>B@fiF^/<]*p?p:n3
UxBkbBG,FoM>H*lAc&F0#50:1:YpX2>_O3pyIP]3Wu^t?F+Qp<56wwJHnPLVhWQf<6?/QF;.T]d
6!1G<FBD5f!l#0(?13^r%Ma9#G!Vb|;XQ[$8#6BB-2;@0r#%p?2w_[^)^R2;Pf@Z8}#2!NxNo~d_c&t$d^?*2v+KUW*,Pf,2HJg|9f4+u"mBDEN]u`7T"eT*=5c%S5+;(n8`o_z)_4;uXCl`:{"6_gKZD!>d*1;(Cs9LdU;!cBUTgfS);x+o:l>@iu`66dQa>70eJlwMn{I@mzt^';case"vi":return'$X/<%]@Z[Efn]v,A--#6-2II*pY&0JZ:}-un8SC#j-it*m>aZs#5^!)$(3WP.$pDy-jQsWyiAf
(X:1Uk/Hv-yki>7`mNW3f|G<hN_NJD[$tPw$XoE0J@A&e~%j1jtvB%Fm1;lF@x,apy-
T&
2jwNy`C(a.6
rkYfHuY7}che$cn)"fauD/M6eXsvJAXw&uUcN*CPFh:B-pN4o7E2=T2LdPZNhp}wL-{XMf.t;/.Z%7qjT]?`!@,`S+3lKA7MKwz%}LAQ![hRT,dmhb^O0=)]Q9|VYqS$%Q
aqH/1``o*"giVbm8h!q<aZ/#1p,t>Jyh!#liposE";q|WYUaplaQaJIn)x=:(nEnLuX1O;`-f;?=Oq%,#SUg&|
73Cj?6A"g)Xk^sC&qp`$<@RNSnz^wXx+}n}]/PP&/7"A,7/EY.fLg4<s>i3rxoXUpy|@;@!?V>#nzgwDv3WDXs^poB14BnY2Qo_z(>5AQcDa_a=.9+81flyqk]Osiv@optUAC7}-*=bFFqQ%mLCB?arf*]T$"MC.~<!2jrsw]V4La#LgaI1;;&l$1t`dHW20H8JLfUYAF;2b>Pf_2o_%huYhH`g@6bUw`I0se1
*4i)r1Qfl_XFT!!2XCdYrD?(1+b1DvUnpTs,I#;TU(^8
O7iaw8L2WeF,DfPy#/$ir#`[4wXizivLxj_-cO]#R#_&F
wrDpFEsKYm{^a>Q=56gR?v}1%QeQv)apW/A7T5sIMcv@3y(dS8B4cK6LC)Tf80yQbEg[:#$<HCX,OIAj+o.,U1b38T%R!J~
>B)e4/Ksam6!}PP`E*.d=`22n.kXMteaGD_:]gt`F$wDxf}:"@-P;VW1o+i:Vjrd~wnc9k;mWjr>^scO|EJy`,O^G"2WjQdUtr&v0kYNW!QVEAb(nEODL!8SRFZCAI6$hk8U~Q_cVH^,G]SPmONd&@@7.)tfv`LP3::]U`Zg~VN8E30bh7qmh6zRoKx6Dy
_*&LZTORMA+Fgq]3:r&EHmMN"`$FCxh|TDy@5du/-Ke1&u8lKKxa3J6S^X6w5t93uNxa)?$y*6uJdP!rp/EQ6zl0Hb2ZrBY)0a?966oPoI4s?>N=5Yp2?nPg9v5cn>$).}7vhsMc-$1}_7aFvUoNGw!Xt-2Ev8#X^tG^ku,V&k:t"ZrEPQkROHHos<3RhRj.jG%*=i!T
]p
@Aw+Tw53iPCI^4T;F6Ki6LfCp5FGheMt"D-2D3Sy30d@E144,>a2*aUFXwm,2*3+,:d
S
7G+oa?k,;@xC/2WU9D8=q;erc3K!ksP
$x(DBJ"Z6]1Je&$o9L8rJUrx"|e.=G4/;I."pn*lX7.)`Y&LAGMGl};#33`_$<4Yp~f:d{G[RY[n$"$
!Ibm$v3UAQ`Prcj?_r1bNvyIoK1Ab-iz,fb{/w-U(Y=J06C@adlPLyR@hI=C*[tk-59{T;i&I>KcihkRxVhqHBk.[jy`ff@RXUF&
2%g"7sE:uW$4006t-1Vpt(rT;V01Is7n|E-gk8dl&R+SMM%kH-1poR1L6csdHjCUUjR9+2{HRHRI60g`|j4qPH^#Fm~Q|5J&FUDg0O<KqhTg*
3EJ^w6=+eGmG|?l5
mr4<T;mgus7eesSdPI2n-$$zb4%)LAr2Rh;tJQ`1GP;38jIkhTA>`2%{G,<<Y[=exXQZeSaZ"46k%p<tyMi*83/EVXV1PR)jom#3&*sh[~a2943$KkZa7`sQ&p[BZ$j8+Aq+;-cs!ZCUL$0PKb1=8SGC4Ot{uh7"=a2]A|T+pH][M<2ds!06NNyDl|YHGj"[YI+{o~%kLnY=Q3WaF)>LWZ<Kxr3z%?Uo-[V_b2htH>th?/(cZI&2+H]ghdif4(yKvn0Zk(NuDSs{qJ<6vQeLo+.lIELOB>e*.sM8a1N=LWYA3ly.<N*Ln]-rj<IF=tS<fpcIfoT|b};j2^`"]5/WeoUy4/F<Iy[BtmKsb-rOE<%-++Zz&T($3bj|)|08C}QuaO,K*`:55nO]f+(o`fE!(4)Y$u)HHWU.s_m"7I/ENPU&Y(@)wq.Lcwhi"ffj?A8J`Be|,b7YVW*I_o%jD@Y9i
"T_JKb_2>rc,u{$0.*h/MM2<XE:~EtLS4P-ybj5/[]AN`c=3IT%jg+4Rp(hL^qlae#/<O]I8St#k?#5W]Asp>[mdM$RkvRkhC98?ZG%1i
@I9/-3hR8st]B@.Oc0_*2_dBwY%yL
?Zi;XfX:T+@7(bpgk"NF/W(B.s8z]|Sbyq^iA]`lGa6.A*"z3gl,!/(CG&9bJ";N==4to5"0jBTkPEvjNs`a-.9Brb).Lx-uJ
5WR#pev4y*HO.},AN|
2#t?awAG3HhBIHxHB&pl084U8rIdpRyJja^?[_559b_k$UU^`2E*5=k8auYFbQFHZR/<r%Y`67>:vw*I{F^/}5*<U)OjyT!IsOi/+p$=UHnPBn;`VDGTtr+70?/@f&,i3$dV6,fg;)[`O`]svA1"ld%:o&1ijn:D%B-D?GW!]Z;i~"$A=9{lp
x`rbHhPm)J!#40-_~)zsU$rJ86ZI#fxU99e7nP>[pP9%fi=
h=6!!n}(CaZQ@cJQOO$xfG?[]/X1_:3iNx!Smwc;4TQ-WLs02+ZS&S+)[8Cf#,&1S4t[<y)ac3{x1Fh"rRldp<4*DS;`#"~*;"(nlcDU$TF?Gm/E=HH"}B:`H#r6$h4N
)=CSUNwDm4[dF0_7;%ipKQk"sV4VT>8JyyVV4Jafm]CNgbm;5R:5:ipzmoav_N4CxMQN!mZuiI2Z2*
gr6?<#N>U(iO}mX-A4U77A/P^K9(N`_TjGx_!/@H(L1`SL{7NP[xo9?
^@.l$l!y<XX43FoD00JK!7![ft*bEHRK;@(x8/g5Vm+H)!Jus.<v2b5;ru*1J""OT@=H1Of(!LT9*nC-9@$[5c~w>"^ZLEDQNL
d22.Z4>Oqk
[0&!96PGs729y7b,S4WBNU*rfJbn{DU2m`Xxa^L]bJGxNFrS3jJ41k6>THPsjW-ZE
lJMIMZ_V%M>K[Dq6^_<#clc
u]HIE[bLrWPA
/.Q:Sc]2pz=plDee`5s)t"l:.*_98eMA8jIW-(v&``Q6>eEs7^0r7*M^y-o%@Zb8C#&eX~"@ldEX<e&j[Z%6YiU5Sgf,d|waXWrr(_p7M8:;`g^0jG/QM]2HI>+Qx.UWGE<G"l^r2Q9,c$Cc$#,y;-g0%-UPktV`qK.BAH)Kw5::Ha3-t}?pMGw+qOhx#py5%b72>0u@)A=H)CeObn+ku.Fy1McuQNbJ(<^K54./,FvlK%Rt5n=:Wcz(6CDk/.K1w@pn`BL~@|]qL[@Ap<6l0Y0&@C=re~0
bAle$M/Wk/.<7~sZqAn-.pevR*ktu}GJec:kK`pcgqU32`tl
E##K!IMSBC{,?$d';case"tr":return'+UF@ibPDI?T!(i`"UfoOIjB3>Lj%%]V*=g]"-->=ae_hFF_;KOMkC(r"Av(vXFyn)?VxGrih{4wpBLMQvCVD{APrylxvyUEy
-BnkfmtsQ.@7]5FOo}AnWl,l3;;)"EduLlxO>VC~)OUOfUadL3IVJRiIG@x.cx6U
-e{]9Euh{X6QtfqiL_4tNaXtv1pEH(`<?W]v?N_eEq29D@#%om.[H4&
e,,?:J-q}^[6&GCM#U1I
1Zr#vMUUd8c3%vhHv@aaA)j9f^HF1__f1yAlOxc~2i^7<GtwFOw&4`x>_!=XgjlR.0<<k!C$wW!3L7xpg[RWm!_!
%$_@pfXk8DG#t5sY{kJv7@&@XC^ChR2)[qb`T@Zb_Nj(+.7]alVok8u2OOEbnS{<mSaC"_D/ZsDFuT%oYU`.EE;wSV(BX3M4HM#=ZPHd_mA%pHe1Y4!WkAxeEDrm(^L=7s
9b_H@K4D@6$g-,@Xw$o(R_5xf.;B-zBI"sC
>+M2GKNX+NO>34[?j:=A8Wn&5PaF>vpaZRv:GM"pe}r1K<Y=%

F9R+==Z>L@6Uk;)v8i|q-aMh7KHKN/xXDHi!$I6q91|wrR,%t]AD2E)/b<#094Ek/hx!Ll@hId?J!
xoeVIPP_$,}U39Yu_tF([YAuBc7Qie$&O@[#EG.Nak,jZwklQEp8$>*Wz2O3K(;#.u)k1LK[!S{NOLyu%G0P,_YSBsn]=G9eQaP&k]iKZ"nY[VbSw#jKa2k*Og39vLCC(sWmOr+ye-G1|wxRjqR*J:1ya1^b^f#vz,6B<N~x/0b!h^dQ6<Vrd+(bjRnF[06d?)N<VO+("Aa%?%Xk~b</&?YwniJtlVRfU-Wa(9V"Cdp!Ycr,HM46UUiJj[q_#Q.?PjU&d%CC%fWxVq&d>GuSO+/wxO#-$%FqHCI"56x.jW6e4&2X`</#NRMdYAZRk`=/"lLAA
`-j6*@}[+3`c8Z6>sFw=[:*FtYhCk<h.^u(S9)z3$chESk?1FQ&iGUZrCJ]bBA%e03~ru+K.haq[hhGZ8N];7d28$E{(@iWKku&E_0K7kyE1+M>q^%1<wo(ozb#]kR}jInxa=kW$6O6Yv)F!@:t=)q!gfg>KjjcW!5BG`M8Y@H%^;$*g;GBlM!y;
I9qswVfIkx*_OY6e,XQ6G9n)1s^qp](&$u(++}$w2E:[Z6wI1Nmt"REsyI8A=(
w4Z.w4.mN63Y-$k45!-U)OGf/E!-84I)lg@NcWV,p&)yH>r$ulV2R&m0i[l",Pt=8]!_6>1<y<FiXfeQT$/)Q`f?p$C6dkQ"qbp1atC<puI:`QIiIRe(u/.MPHsYoldgEY*vc2:qr;Y97]o`}F/12]u%yYsLRT#%SAG>I?B7o(.Y
Yq5bsP67d)oXiGA;r/[`LpE08+RRxC8rsP89_sheA[WL]|FmXB1-P{?y$&fgkz,@QG4,tr,TCL>v3{0A:%oM
;5Ny[K]OW$t[ZPwdNr7V?1jboU-wbo)Bm-qkxI;!``!5<b2`8r=jD<m!!dG_{8!ToA[&zwPn.
oiiacG=@":t@4L]ybSh>hSyfx0;WfYK_U$?"U(*"S%a7h&&PH`L^/D=<Ly-v@4p39e5b;R~4-d*4TOKQ7x`+kyN4}9:^Nd3ZXI8b,c9%8/?,iI>fh#I3Ys!
2E53"Q<@^OTH~oVmj#I$KD.
/423SwBNDA;pT*`+9Q!/iW!>mRy@Kbmckfm0iG;+_mkf)H-bOdlHMNs*,WEsKoys}GyfG8QaslQKvR+R6:ZeK2cd?B66.#jp*um(~>m-RwE2XF4QYdl$lZr]0RVGz-pn?^C$}$Cf_M5dm6f=Ye%krA>Q@*S>cp=gc)Ow==]OeNQ&^Qw!-m>/0TL8X^`S!-T9
h4@8dUc@04.Gum&7"PeEb"tyssO<X:ROQoFALfI|^%EzpBb&EZG+<N`OJ
BN<J+[P$Z2xCn|^*5<O~MV9$@&@b>fo[T{>G;:Lj9NdHf`Zo#z8fHdW%6nmzAeGu1P)$fG]?VelP5i?5(UYm*Qbd_gdX=&$<Ef18HBQTs
7Ers2r8lnwv>fAbn,Q$%0x^0qT1]x%9o<y"eZm4)]4PWe?^|<.HLV`3O*V^#t+;)<It[3itUGGirKHe4#ok&]W;;H-Kul_,"W#$-L{*]_&E<FM4|6$9rd}q1ie@Ee!Mj^l<:9w]e@|,MWG1*pjT.BUL!>%EaCcJ[`|t#&j[>td5o8K-q&wVF51W{pWNGSv6qG2k/Pk+ee1)GERXdQF"0mB
Dc[Vat%:Kwf:G@Ofc.lJLV@N3b_!xm*&ZJU09b^Qq
~bG9nidC5?KgrahsP>&fo!6ejj)si,lqd4[xfc&H/A8Rb6nm9*PcV4v_sb!_2Eee5&4qllD(E[r7scyM@&_%f@=v,SVf*B!!9RmU*=++/T3k*FrW95["[*61838KIZ~xSGmg{QTxhRN)B=Oo1!WlA/%!Mos<S==%qR$e*&B7UjG=aGh_W@NF,!l-?;f=uX#0(p,tXNq_/cQNx95K]25-4L[[jd$Z#vvD+fT$f9k,ojhxnftcdT83q(O&Dr}8a+gbeWU-xF5CW>("+fPM$"-3xa[U[ptDdgK)3ewx;6TFE0D1R^q*4L7m0u&6`>YCpgH;]/|a7F/d!OntLm!m&pp+gu7U9[_wVDP]FV{9C.+ONjdY_,kjN-mhGZVU)1:9SiX?*.;%w@rF6>
Dj/d=|,.`!#2oKd?OB_&ld%3c"[BEv9%3T)*;<5"XhjNI{f1AeVBgPJlJyUYF5O=k(boCx`4m]E!-5Oz/cx2m>xwv5o@i&8DxDbNb-PHftbZ,aMG&6)UhMq`v:i(Ue,6:qjNj:1$I
NU+|F>t]5:4C;GHycUlYSgC+g`6)[
*Q*BrQQ{"-eV<Fj.uo*Y*D/;im1$YK!)7!kWlX/J&B;`gNT!Q!_:@BNmp3ub]"2xqdQr%?EM=6;F)6-El+81o^y1=}9?Hen(#8R-XP%0<zlK55LCAuOjRIg5kLvA%Ca4dR9*P0OQMFXn(rkRE(Lr?*shoo*
_9S3+tjv//[|;O*9JlERh:b-%d9o_MS61u%p<GOW-aUiU@Qz.<;$>eMS-q7ty(6r12PU4LIc5bx=MO9@2n1P4)[Oox7mJ^pwk=0Q?N9W$+HzL+E*>I0c5_LL^:Tk.yfKsOC_/TWb4f!on/74lp0%MB`j&)"1Nd,v"aYfF6/E8[I}<3TCmO@DLICI@--Jk[HCQD2_U|0Q^U(cl.F{P9t$MbHi71&W#)aUm=K"SXZ0wof)s|96Z~2w^qr%s$(86xn>&}#Q)?V!yw!Q';case"bg":return'!ev@qaLp](q4kh>"8lQEkNB`|o,p^XL&@kh?q63^+Sk4{Vhh~H"@n.89Q37IV-R%6pU0WkG
ov8,RAG`d9j@uj%s4v|t5l-n%sYs,?7bQ@8Rqv]mLl.S*#dvDsdG(H9A%nBvY^cM(/W4vUnb=A.
ejAm.B]6`gspC)DZt2)>#wAI`kHMlyuh$8&1Z+[J$y<KV.[`Wmns:*EEL6R`zc9jGm]Mul{[/]7k-0Y5II>HS-5`jW,x2cxl/k99=#|YT]Elq/988#E7a_|1D`L"5dM=V70Og9&pdY$hS)>&zyYurH
m5Wh48Z-T7x
=OW`/G=~b1eTaF5Alx
t_X`EG9_:57YsMG09wZJAr5h&7;afWXkufM!{(Tx5AWu<Iymsm%>&@T&"4iGxS_VKuSPn7u^q1.;2xb/li~#FViiT<j/0o0n#U+>.!45/:G(dMjc%a"^*wA7|TK"4*XxBts88:zyP9u@D(VV~-ox9IJ+`^V.a8$`cs{T
/KU39AO2r6UQbtZ%(9%a2T@D>vyon7o9CC%mmR(DpX:(;twm/IZmaVx:M7N:IWjZoNXq,tb4L6Le1h.<13)H-dV^w6P=9fZP;OJQC+x?lCOvy-_z*xR*;G-QM&`h0}.^cBEuEy3ch=@_ThRb_9
*_Mk3<~95EHJv?gvGT)l72L$]WGWd*p:WV,O<U56pr<*7hUMhcUKbK^WYx
[
MlYK
UA@)}H:+X@`v4lEp;3JN0P
8lLZgeC.J#bV+0le<|nlxfb+AOC-8p+0Q[tNERQw3a,.QJ)AGHk:["d6Q*h81}bjvF!D.Vr,(TJ}[W4`vz]JAl`@)*FTmJ/F5Rp|NG#S0CAKbF`CUoS$Bnd9a4(ME7UfGr"tLBg6ZW,U@h2q:5M3DWJ8=|ncgML|4z$X.Qu[ymw7$8-p!Xe|EpR#(J!Z9zM7]ZYpiJG""cXz#T5&;Jyam(NT2>,[@LNH+wXZ&HLrqZ2<&A/vQ-4(Bt@:,:V:4m/#O|={c-qOBlfT*"OC-9jCAy-s2}>+,y5U%+D>n/ZoQE1Kk540T4@41~&KNrD)Td5pNaScu$AkmYZGg.O<P^e`K48p<PC{[+xMn2M7i.^aiZ.[/D(Vj"W|<!y<,L1ot@YE0[V4URP5/7N7okdRDg@3(TvFC*e&AhBRH*<J<~Yx$RDKy4!bN,nYo?AKfCQV27
|C&CWlOKb$AN4#_xIO9Q<Vs(^rnx$I<<tCig<W{3+a?
q39FiC.e>%^,os)Yn/]Z"Y:N>deV?!bm;fFUB3W_K=s!is@;%ioWp#%yy9.FaWaZ5!Yn-7Qm$6X7w#wm2l7jIW|as4S]9*V]4+rJ^gq!|>*NJv<Bh.roRiG=Sa5^Z]P_%hZN)9i3`:3-%.`b*p/8H0V=O[aFl+n,(^*Tv^Zl{T&XHpt4#!R:]PBQ4&mP5@utO$DCDb7cW@EGwn-*-9*!G99%DFt^h0"Lg8h<f$Rb
1FQ@1lDBAX[UmpDzCQy%J*CL>c-,26=.7MlkZK?Ll?5Bf~P@]w^|a`KRDgZ8S~#yV-f4[rJNDk<wS:CE(46<G.HYC2+9>I.sh]/
/g$Z-qy2z%mgIg,vH81cqXx`9>8sZu0tYfz&EHyW8GW^GL#MHnHhRVqW06fKx+_T(_;].0/0M_)-+u_p1fZ+
FR`HH)D/7YkM6_$V-H~Ui%gqjCe9sfW-)XRocDv2zJAt
V}x[_30<)=HQhxqY^6"kFJiFdga>rt0rtip|usdO)>R
4T"8DfKOuP6BBgS61a&D?}=&H,$"RPG>TNO.T4L!KMp0_aAhY0,rEkFV9ubXS@7Ki!M#$,&^jAq(d=i}*qJ-1Dap(Z>]-*].C,a~h"uyD}&o!yM0ylb>2,EuZ#;je>wdWq]Xs]^>X3ET2K_D*8GU:HAX&S67]kZDg)#/RUH!kN%`TPj2II_O=a
/YkuF#+fs*
S|-nm5!8WjxQBN9(_B0?`<@Z/"D
0}?!.b#Fx7O}0w_{=fLXNz*~hVHzq(!E/>UU?Od>A$M<J<RXyBQi,xYk[uk62#9(O3n~wxt~*]mMCaDl]FTROW(&`ucl%Y.ajh$&c~JJVUP$N7n:k"
ab7
[vIs_Fu
,hgYHT__<X~?Fq7lT!ZIslqH4ZLeYo?W-dhy+y[<<Aw[!2O0m]^tcFR.m0VPK5Jmzdj7ENR,;U&E_I<Hy
bLNwi);h+dUkbPG;-/crF_S-!.XAl<#6
Uzu&A/oC<{wZeXBUwx+{Y*V*:Q-cwpqNv?M_Bg,+qh9yUS9@`UttUjb-p(kPCj@<)#G8&Vmk!c_@rGQCc;gsaqdO`St6jWEg)_#.LbZl=uxi.3c;d<Yyh+u/CwgTB!UqFsh|&2]+:;K}.6m4+JIuo^/nwp/vt$avfwh,,"v/`3lhB.;PCwt5*7i/t:>mglRH@P27m3Gr
dG]W:HS9nk9h$9oW
H~w&$LF{4)/foUH]sYgR,X&W3X(6S+K5urdl]i0jfBs|f<Lfx+Z
M2dM3Jx5.&va[:;(7Oh9p_b%fz#iDG"+&,ijPoG:0$+e=$K&79fE)bsAMjOY`d
?<G-#
?Q-]lhbQ/T=j)iy:;O}Vy
-aY7n6Th@?eFw:Okb?Dah*O@jF0hoF"-P%Xb#@ib(C["[tJU]vd0q-`.JR;SwR([_s)VzLx088$^Mr3dtV9)T.3+mmD0?sxJ$g5PeccD]k$S`Z=Q4jQAsg%`Sm$G#:.GvZO$S?q6*jUe|1:#8!*&xq2$Jyl
[mWc.Vk%2:r_;+ZZmO<KN6Z0;J`Uh,|^QYtv~p&z"Im(XXYh8r6!x+%F:=91}#r"=-Ntq[H/c0<
G*/Q9
XqIBC
-G0QLEQlm8EQzR](&Hul)NP1F.j4|=u-JrD;r^>8RRS0FE%cyI7AxD(cT&2S
z%``#Vs1[NVaksWtot$s"iWy.T)S!AqiFFCg3/a}f;=:.
Q1+}+}:a1)RH]{ck)qItKp$ti0mG>l,K<g7_rE1Er08ZD,K@Z8segRe(@rMWq(m8W*^@Yd[j+%+U)/pZMBS2[.rk?G]3w2BYQj;cfm-p;LCnu@_
Q2"|vIUC=Ndf
I$mtq0;QGO"$S0kj)CNbpnJXj=tU+QL/B>"d}fs_Q,sO2R"`xN+V%eUgg%^ey,Q"EUq!sXS_We+Lpfq>#.7r,#X]5kq
R=0FRfR6%:rd{S7`g=*gklor;A58Mi@blNUcfWPvA.I
ytiI9i,f0r54=6]%W.#91QN@wH6?`$,tnIf#sqBuM%h:=*I*dcOi[HBgW$8h71(VFU%!x0ZxKL(5Cj7)@>_Ic.FlcUSxZ[S9yV+x!hNQmX/!>]|+2n1-v$xO:%oX(c7F<m|Wg7KfLT/b4tE^&CPlU%[o;@K,C0p=cDw<X[W5n2OC_JB+4Et6F:>t1BlJ_mA*}d@d/$8uA;zj>1!V0CpW&IEdXJ{@)M#u]/#R?#fEnV`^H:
iMR~S2acHN89lFbmrP-4Wm7;JE=a>7sG
Y*}l:ENd1&5V;KC.#EW>GeE*wAc9@HX<~:{2D%q!K!|HSc/Z129E~Kk`a!a^>qf%>eL7DxV=Fyc%(2LM}Opo|#1vx+>GHNX`:dFw(UU`#h?ROm";Op+7)VF[*^{0)lr.MYR7[]81EaP$&C-tl]7^XM_)]y{
glBYR[=SrT?@t(!/B2s5)B4l)C[@MpSw%(dg2f>V/4NHD3/6]ffh*[REVQ]vkH:w,,Qi_tq?,Q4&|mdSA_ySfTCZs3;p{i"yR0{9gkQ`8@/>-p`k-;%_oQaE$UNs*HFAzcFyH_VwV`u?T&JaY&7@ZxxM=:!fwmBM/JpiR%X2I=g>8^ASzs$Di<?HXj3]kctK^bip=.}N{kOI]$RnBQWo`>z,)^)M~0[P6F%&sZMf]/6Zf^lp.oLPKW:b-7_b"BnvX
3u$n:/~5nc=qnCG]<Snf0)ta^TO<ufu4JNPCZ;KEPEH?>s.dwY+IfTf]54^IUg6/;^!aYQSg*Z&V%+wpX<Tvo*c=>Zw,=nfs:y~1dnXBIpen93#M.cq/Oce';case"el":return'"h_Kjalp])R4omo&zs6N1._"X;GQTOh-2"~NTu_Fw9XT9V^73.g#R]C&EsN&1l"@pBvBf,>cwV(WvMBH/a$sJ6a8Z$(+NBt
e@/^@l.MAZUs*6Lpv[~kK$5
xM.Y2BYIzLgI;c$Az.:r4tnspQFH(]zK7ZmeDRE+2&Z_2uP/O<5aZB/9[2<smZyvxYV%~/Fl{50Iqskn}#35Fa)l>Gf>TCCady`g/J|_i[jgL+8*i,=W8"k)}3J4J63;CYgi&dcH)"KvP6Ph5Sfj7w$n=;v,8TdEd^mO7vPt]7q!c?4)^=)ijPyq8:2[C<.pKHO?Pya*yF:?zmt4PZxWl%9#:qS8wUHLiN8+9[qNM?8OL2mom-O8*yAC4"#
O/I]|E+>ak&_XN{fybx(.r"u2T:Ld3!(zOo&W/{b5lo0B_UJPHO5Dp$AVF{EK*.o|.ySiy8Y~L[f96G<~1}P&uQ9
4bJ?aQkQ6n9m3d%Q,WJ/r*Qur-5o1Nx,U;I{r`PX:O+sBeQh(~U7.fTk82`s)(A`
!2*o:S1Z)Rw4dqk1L)%8mB_8C+wx%v2495)0Jm!)hD9"gBDa%asUj]#Wl)_?pY6)+I)Bdf_iYtl,j=1`ueJX`Cl%y@pa3q@A4Cbw4<A;5lB%q3Q)W.zood7Ypk5%c_j@9s;8ELegAEg2e^e.[,7i^h^flK9Q)Q}LQ<H0v"N&
7??H_ImiN.`WpqcyNN`Z("E~D,(n:(O)$sU7!Jbb_^Xx^FIk.9B&!R%;OpIPc1/1IMEO->@z"?*0:9:MV,Co30]}Aycf0WSfs*A4Z_%CPC<7iwvNviB[ri0tS/:cZ[U,<a8@>CgXpVtm0tlGY+!mUa^S?s%dU@
t6n.G?Nd`=i1e%49lJ"DSVuJ_JrSa/kvfhsW)a3[rVt!%9_i^6PwvFa>uq`^=pg9:gcWTcZb
Jnk[yBI;^0wzp^xI5iOj8rs&.p8_9]g19Y(}jBf2D)M!f05:=)bw*w!~"P:}@x_kOb?r6p:aWo%#Y,QT-)tCJxMJ3?c[+ktfa]f[+&L%bH:iUJFXBSMTqS4/""2i%rnbhnSuY4rY46)RMW>CXg
4_S/%)1m&oT>2ZPhR#U=Np*4mPyDo&4Jxg8wwN+#9&MDO"F$CI
pY*qJQC@l0Gq7?vjHWPm@}(8(RKCdCfBOWTe:G)yO=<8pw,"7$/WGKQC<`NWy-mS"
%jL/Z>.,O+M
N^k}d*07Qoc5EQG!3Y&m-[[y174~>tJ.F,%CG1bN)Gp^01CP/)812:])_+:#7voLtn;Y^yw$`C9Nh*".<0x
bVlb=hB"V%J"Y5*^UPrF>w
)M
EXPU%_q!VCn`y6,g;NyFlOd!vTXU]c[`][:pEdf-Sl0`2^tM6Syv9*Q]=Q",1HOPo9
iVq8y"kl_ltuz?P`n.gIl`xE8*r<_0qSyR":P<p^nMl]bIWu@RY)Vp=1qotvk>Q%NHR:78%X:yHLaUBF
>syoTU98dA""]N62hobhNCo{-^<G;-NtktH)aA&X1Ng5`xOyx>h8w|XmK$^(xjMHhX[:[W[1ijFap.[Af*1A@@S?7vEUVT^V@Dw"Is<:W*SaP0J*^*+mi0"t]lQa$=p5I8w.8AESswo}H8+?n0$1E)QR.+[c1jAmm;hC/`f7Ft?ew]YsSh_kj:3pILm:I}^rig9x0|?v&.<H*B](EcL
.KSNOo]Y(LNt$87yY0*bhw"ck6![)XsVIp
^j8DZ5zUb&+hb#*sf,w&TD6`J%eN*B4Ya6Wz$ci5U58X
CajU4N::9Xz)jhgLx,4CpEqmw
_F$n`MR@7+N1X>Ny1SQ5/hEfmxJ1D)E?l(<Yt.B=MLXp6bPB/l!bH#YB0PJ2JohsK]p3L2DiFTewwQ8"0UP#>!*;d4$Q96Cp94u;^#cSTY7,#D-E&RCvI^)d)8Zd*-][duz),^Wb$JDOi!_~R*4
b^!e&^[{St)<nHnyM:I?:v*-U-w|/)+mF(v@et5n,^b@NM/G3vRuVvSHoW<lZ,:"?D,=3nNH`_#%Eym4o5]k^m&J`4f]3<rZX=y.>&v#Z;9I)~<2$,oEi_`kZ-SgWrN>k[)/P-Zm$dA(BT;asyYn=$SLTcBY:->[B"ndiB:/bv_0Ju*gx6!sX6->_v`QHonLS+xA:#2lX<b):@E4JLuw_u4*2&`6
AXs:+Yq7vtp)u08Gt8Q?:Y6K_ev?3EJ`E-D`_Dn-0%r[>&2F371pa"k[q5ENL.Z0]mnfAADk&"|n?iK1oh+wnq_p|B;sVnP%8AGhZ%W>@Fv/.Sg@P8Ij&ysE!PvJy9C>*Y[P(YFPi@t+{)r:[@iP&(]?AELvnFw4l!EP0)"+
35bO%W@R-}TM(
Llx4b.V4!9=5ZleWkL*$nZ
4a=*%OW#|K()JegjXc:d.yVNnsP:[^=6-&2Gf/]XCHlhUxRZ~tK.X<ufALM3(!WrpU>h-WgOVTl1)D3`}D~,5>vU
6KYA39EAc5S##{?R*<B{MnW=D[3e?osZeMf`6_)Odqo7IUaC6h^8_9..9;hX+.J]qzC}%4"EF6c|%dKV:KkH(S[_=mH_Kk6cx;38LOpcra/3V![tYfEdf^?hiR(,21GTP,H48uZ!qdoj[%lZRF<&nRZ
MC*sQxVcil;pNzf7hZSnZ.ewKA_T607+)n"ms&"zqA&!d,/uFtt(G<LRp^36i2KpeJhpd2)Obag?Y-I;bL&h93]}<+@wEP*t"?+mo5J/!P3j6@8JtyVFU

<xWqA7Ot9L,+kFr.A"9_#P+.hJg[wM]yKM%imi%2"d<3ZhIa*-oU6tVF$nFtl2S/PV&OGE8>Zx?3D;L.lVZr])stj=Hq;j~N=Z/+B4f]JCN)wkvQ,p8WU-7qNnD-MD:
!lvO>GIa4T7Y6Gbw(Jsgc-?oSjotTRuQ7uMSypAn:Hb]YkO#osZ?yY>Z5`s_:Ci`$peq`Xq!]Y:MgF<7EAgs2f*LUsSI$!(0QD)O}pz[t0)FM.Z
2>.X,Z!W-]OZ>YM41?ss.FkG3O)`[[CI;!$5v=nri[Fr~:Z`g?M^mv,jX(.[=mF,=
{Du*(LAlQV:GXa/`"$;$K:h1k0ToThSI_Vz68w;g4G|UH7B/P](W,q:Cm4eH}$H.h?1$]uQ.O<[Fq=a:#w<-~bU&_bzJCi0"D4;@gGZj|#2]z%"F!qN684L!~%d0NEA[s=)_zKRigJqT3?X%D/hu!C2bdiGZ-]*QQOV5UXpo1&IF"1w#*;vnm)vz%A~]i;zWcWJ)(bbQ?X.Kx<Wc?%H)eiBJ|Xgt;C@;HT{:j)|
beHpjqHd^nJbnlYFo[{qMC)5{%vgp.K(612Ifn>KE5e(OSC9f29<A%hRM?1RtTM5Lkn^;a-Z>k7nym=e5JhGl=f,<LB3M)a``9[!03r<gLHMYqqQcWV.wZFcR6.7Yj)[/)y(G*iY-J9jDZBh8uL2=ItlVRO[5YUwn)(n#!P(ig~YVH_Il!x^Vt-Ld@*m`,3NA
N<6>xj]xVqjOO3oYy^Dd:EU<!rB8RCDc!($!+HAx1vq2_JXL>G-Egj#K];XE:bp6LW,H5n._{E++#h*xT?TmzytV<vK[vGQ,9aQO8`euG5R7f+_c"Bwcb]u^P&e%wI]vod{p=Czeja1e7AX2WeNktr}2F9hen_{l-G_6wDy$6.|*W!w;ph<DTB)6mJ3.LvB5u+?6
J,2M<2FrDo8+3/XVOo]
a2oZDZa+d!69_`9<>3^AD.YNmZ!Wr=xu_`7VUA`jxY%0MgnJ5x1kAQu.[3!^=pP|y&rRsbdk3BbqMh`QJ=fPCRMSRw]jwE68kMuU)kU+FUUvA=K6CQnA9B$Ra;mG#YvW
fJTy0$Kq7q*XlheYQy5[DVN&9x8IQr<=.M,tK?1c?xSV)JgL~K^1JmWgUbqayoplGpBR6I?6":g++K:=fCc`?R`YJ:gA&9|@{sIDnEfN/M]bQkjWtV~wG)KEXIE,Q&,<|PX4Fl9l:#3[<cQK{8mW[G_+YYOw*K1qfSoQ}sp5r(&FQpisO^C*eeG@O^#:<Qjk:rpDNr-2Hh_+}L
"ip~hM#O2*U)6ZntQ4B6-a-:W8U1)3#+i_GxEfc24P.hnMll2?[_nj8wx}.%IR7v%Qcp3n.ona:#/L1M1b]fqB+kU"k8B}dH`ii6q@vv7G-e6]hu#/`EyY59DIo`&>0gJBF&:61-f]ZLbaO8W8bwGQiWK<kty$O_u!:8a:>}60
jeHL;l2;|]^${q`wx%o&].FIO43RJUc
DNh2>P"R]!CDDy51KtDWw1xRyBDk;OcSl;*M;<MqCrWL-<Aa*-W.8rP2iI(Kx+X_(Im@(sCm~QK8hhXmtcyt?kCawR=jV.iy)hDU=U#XIhOx.!RSp3SU2u(.WbUd4vW0PA
=E+.4#CRr
tjP%a+v`b[C@Md,GG_R#f6hig%nncOU8)NbMJ.?M0)G)NhF0L86QFIl]UF1V"(azx57uFd9)0NgqyL@]vTE+>C.Mtc';case"ru":return'#evLUaLs&+YGXlT%1K#=Zm<+Xn`gX
#Lp){%"H-/&AC[r<`qJ_;U8)?QTU?a[I#wfo-xEv`XHXQw:clKk9ko/%[?4Pbsh[TiTEGk/x9?P]n5pTWiVKp[L,:dEy"Hk`G0iDf>x_4qF9gczUDXjiRH?GWIAuP?fn0vMDV5ay!3waH)*5~G6m40OGz:S%2+WO]L(:0lo
Rr8dureW#J5[`7s$"Y~jyVxb]0aYRoN231RPU>-oFs>ii()/
Q
cRrm<LUb</7*C4+1yPZ)HBJZ=WkMAEOO;DZ&(yxO_trE^S[6GzrfcwrFiJH#pe7CfEgkj4esyMV)>*Z1i^#aY9lQ=)$hY+@25^_{nap-_&(.,AY&e4v*E6`pl8BpCzG8&|O}+`t)<>0~fu@OGXMF,Q-wcflRe,@NjQ:#1`"vG8DCmeW:C;+I6`Ye@Y+a,aKh;INg.GA]N~]$-mM#%W
@WbUtO^sPTn<Gi9[pQ=Z:Y6QQM,f;:iB2^~*1c8PB<glZE|]G.<G"9RPOv25|M,;#0EO112/ZJl/4ow"q<3beZ{.6hs*uD>Y-]{]I=fW!e>W%mNj3PgN!@Da@41S)/c]/prxSskkR,,^4Ys-/%rvlm@J=]MB(I66/#miKlSV
]9smQb9iV[
2E(kT_QZ|Bq:`7}[!Q)[%$r,$`P+zYSn%Q?TcX}6^EA55P}*{K`Bc@27fIpx6O~pX8t
:WK:hBtm,"fPF2
!s*+s6coBDWr5yPG(KhlI5DaGo9?]pPH]!gsIKE?-`F?hW8;!X!o?bX*.YgwVJNX)1:UY%AF)+8&xy-1#j1_/|S
j?bqU(sL4g9qXS*wn09;2"04;]GFD#"ucQ@a:81
yF?cX}Xd;R6Qj}$l^:,TVi-/k
52<qATnJa+5)f(q`qe:^WXtSiCC}k"Qc_J2gRNAJ^.W80SZ
Rm`Gnn*TE6Z#:=X4,ZJ{^S4YN+YHX,7h)FVj=WeZ1K9Oqz50
X=?KM"8qLj*BMkPid49NT^tBNPqlE)P8k4k)LZEFu0?N`Rf]H4U4?2p^8ym?pk%yNNnG
VM3H<F=<$jZR=}BNT(#XxRUH[HR(_Jgps~R>ej`YZ<XM"/4z"J;oOu11cY$+W}!7/U4=/Jy$0BW>+=5C.W8ZiG>Y21$,Sh9FN_:|;`5{[gT7EK>4!/J=n~=0NNNO.$xA%wr%&J-109F3INon>FR(qW"B<j^g9K(CAf7{X0>TJs7%;]N3gJ2R7nVcnOkjVSCLjHc*4_hKfeINmJ+"AxLOhlen%<
vR,k&h9WRhRe2tNnx7|(!5GfYW+OB:>rtYtKBl!4H)pt*0QWpS!o~Rg+`C`!<D(dO(*#D6<fV!)XO$19Qr=d^]e*A#/5Upk3Oq5QP<9=+[d!e&tgw;v$UW#&r4|9-0HOE_4-J`q0c-cAGI^"C<PK_<W^Oy{%$>^V?S^"(/B9CM:U5
`:t[C*^Ih4;dg^TQ46O6t2*1uXAooyuIm_2Yhg)fWaz""-pN>ES&79d.HtHm[%~qG"CEmiQc{Eb3?@vQqp5V5bdZe!ihDBLQ)qq?1W<rZoA]os]Hv.rk
j[n+fK!leffUPQ&0Ali96*_d#raKh(#IJbnr"!bK"}3mJ_&4jN&(GlIAcu
^sa>{x"[`Z$N>6wyq%j8Mw%ed?6MUC*^"!+ac.C<L:*`"3z#7@*b6u)HlCbL}CM%|/"D")"xF;@Z@b6!xO8E9Zj@!QdQ!?m9(+BFf>yY"!{"VbJ>z9KE=/aF,y$Pi7@7blHwM(gMz<[_$@>Z4xsyx
}u7gD#6Pz%Pp-3(PD6N>~4F
)wDZc?#O^KdA*[7h#4!wx[a&
3_1AkgYZ^O
Colb4vA[k5c=s)4i;T;><:9>6pJZD&2s-FDCk4x-(F{%fyF4sHj=oiM!++"+,;Lh)9C0AmV3qG"yQHKFy]IWO(H/OQ)1Uer0>];K_L#c_8,&yZ(!f"L!&0PVqR;q1L3FNE83jXX&).8Ec:Ym10}moFU%_.44SJa`"9noT)b
4=)dK#F0>tZ(:%pWFBtP.;
]>/nNxC?5^u#f`>C5w=2F>d,RyT*V5UJwb^^0{idTN/&56FX%$dvRa*lS,?lYAj1P6M/eY%<UOwL
7#7s):h2+Kt!*;b@:#rhF4BOqdVi~)e"w5h;ST@sN$iteyD[2%eEJ>=aVFZ${XGMu^=V+&z:~R(7X!v4or|#B7Ra}UoNs]HkP[uuE^gO[O}eYbWh)v^Czt#c;`#2Fej739y!y:71|d|Y2p-vw_y2{,S`53(B*+Qr
_zqONq@9NPZ~-CJ|i{&6<GF1hbrS/+9e#+E/,Z*YC6..FTvoV$&#)!yyweg)$]v?U2U62wf0,K]P0t]A].R#w1kN?7urOWcZu.jZ.PjX0=(y.NC5l$Jv4oJ}isdU
K"zaux)*okb6Jx_1d+i<:Db]4m9u.r
.+bUmKm!6V1+9qUN<]&j"Tgn+<vcA2p8&H6~(+NlA{8mY}2c&$@pA0^j,"cc1dPV1*O5$y;qHAG^(ZbDQT?aGN^VG4J
nsKHdkA}xWvv$9N[1t1b(_Gh>{5`VS"dM4
+h#FIeLUYr"^2hg,rYAA4+BD9bsg!
0j
_-L[j2!gTNbh<32=@&?sFh;kw=blPl9QI1C%M>gX+Y.Ats=!@*p"=7xy;|T(Bq0!%pScDx1K5K+uo:4Af=bjNB?zOhR{c|"o
v-)lds]*eGJ!,`dTE@&E(V0`XLJ^SdU+>2Ht|.4om`CB+%(g?$E@Zu-te=$o"_=j)grd[TQ#ow7*vsoP=EM<mMWJFHHXU>M]2mAn6Sc%KQy?P"X`xPap/Fpx|vvkoSD[oH3+ZTmf;FX,}GBb`by13I_*YcSqi^yVAA2]U:W]nLQxOx!@g
:y_&frNPiYF>=`6_:wHWr>~,
gb7j@bVU+O&bv`"#NXj
tj*XmQ+#_R8D`
Q.xtV5;A4Q7NEg(?vXN$C*p8%wcCcbw(VZ_
3;2E[_f0#~[uZ[b8Vp@M849^%eU`2!gW
:@uJa7lHHEdBN8y3}:7VD5D-"U=&Dwy$l5rl.tK?K[Eb#tNBza&JA(7hiw4_$z#LDJNIw0PvITCCJqDXg!~BJhuB3Gp2>ICf?Y_Cf1L(0ldfar$+Rt
N:r,x(8
s/_cK-?tL.*-CYP~2frTrTE<tsJ5]<:*p#Y;:_WOh;ol,n6As%3gn`:3^%_gDLk[y7B$l=3-#^x/9%sc^@Rg[Ne5^hkee_t2nKiSS]umd/6N#^)9anBz-Nv+r>MOE9B[#NgyHwLTl$s(XGC@?((1eK!V^IC&
<N-Sm@q)h6g0GpdpPl>m=`8xY
6y9NBmW&UA1s7l[t?,=Zh:4CR<
62p&9I2TB)$1#u2xAY(jv9O[G}lE,`b=rEf|CrLX1XaaZP+X*@!e!:?S**k`*cfLVWl=_,2f,VOL[QET^
>YlbQ>wY7Lx+[G2GL2eH96b
)N^Tq[ia!71
TCUk[RY4oY>qE6uK>BBmC5m.eymj)d3X
ANL:/cz9<Gxb}
B=NLm@yBrZ0S
c"oc$$W&wT7UTw2F6J""bC0(l/D@^#/bde-Y$Zq[
3vnuU<*)~+F)q
*29W6KD2*D[[E><w.Q
X6-1TwGVZ;le
n."wdaVm&<m_@TQd-uJ_L"hf`2+isX&e&qU5zflO*z#&uDMwP
?y8Oh5-,E+w[o^Jb$1|bhl}J",>%bUr9>oPRTW9eX.dm2c1s1*,72B)52<6n|dRG#?xc_[]7%JIYaJ9P"KZR"DC-EdE+*Ib-2_Ps0HB%}i4;il.ARF)fU?J<r5iRZ,^BdS.v]o:@"qOfych>DGp[5?0ERv<K)QEx*=a95!%/Mu95krL;9^5Y~?5dEyzaKOI)7rO&&ou.)9ICFw-be&;b*TWHWte+NK1*e`^O0e/3*&t@WMNYku0kt4.A=muWoTbQt,9oW"cMles4#WLaoxp,+AVPk!oXh=rVNKE1t0`KUYQTU4L
.J5B921@#Aj%60=CN60qu,1koT#4n@?J(bb,9&;o)_Eg6c4kP8_!<d%4^^raof$jWSY-
kzj),+`@Ee,e-USBW*)H_
"gljIKV8kGlkD@w|G8mIaukaV{?i8B9_2pA{r9]4/}u!05juarNZYW14yw:G]HiDSdb_oFg>B=B/ASw|o_/Q@ZDjC7n|#+>p7m];UD5g-KXOk<Wsu"FtO
1n*0oWcV(2:n?^m_d[K{Y@_oW9@yItnfTy*8a8a4_XRy&s:Urcm:seB)*)`K3go.ko<nYlc,)p$QJI+jF<,iGUbKl{F1B}7fcuppu|R.R%tvk7P)qD!9q/l2)vf+Pv1wPxqGBRWU!y4[hD"0>[j+;N?QIR$1M=C3ee_@]^m$Ws9e,G9$x<#1x`l;@dXmhKmDg`5B&oxWj]c_k"(/N^6a,st^';case"sr":return'#c0@qbSpM(q,gY9"X8$=3.XDCn_V7#4SW_@[<lxs(!r_p]a7rsy;*/tD@8|Z5u~pok;rgkvh^<yTP1;7r=_S*n@ydM169SsnS9AIm++yRih5/#krkF&:dj*QhZ|t|6Y^.PA@*>ubVAM!nC3xE.:D0xB^}8G1?`o`!-xRIdmP2xZ4{.RgP)*%Pwl3F*7.ys-e%J6qx?TbU2HOn]"s^8s"tQdB>?}k{<tXu8h9z/nq/U(J6lK(#H?q|H;CQwq=fS$38BW
22(WP[1HaFM2+9^*rwk]>B.xK6ur444c67stc#JtsT9"t(phE5O_
)F#&9&rf=am"&-&ucwc>(X$wDj7;fB#PTCopGoQ9T^@P#E
c0,u~Itkjt_>D+hbwRF/%VG4*o"AkDs+O@*iS]k?8YP?:Mvj>hPdm$LCJYeFup#h`>OwK+WvNc4gQ)*KcV}0y#6M
AwB!%.58?5hlIDWFpR*:l"
E+kk@VxY-K"2j
IroQj3~vjR_=jd_@gVS+eyFH(/!
mia@P;.z"@D#%Q/`Fh:P-5xGl1.._g8r)]T?=^WwvAKHMt%p/T**P^wd3C.0(b3#Y<@YX%y$PBn^DG]w+[OZ/2ft_coIW@m("!*ZU"*-M0n]a:tIxpZG|pj#6vhNz8R2tOqV-Rqu41@S^3,+l.0IoZ:ifhEpL?>au=SQpL`x<1-o6>_[{SrlK7c1QiM3ZykYQ^{h1P!h,1xTt^OVoDfgJTj5&ElH}N"9Fc2IDm(5&F2GOVW:gf!immVl^xv[[U_@-&j
sTOm!%]dEMS*6A"J(sy]&$C"0aKZ4-|3m(&U{rFT#FCrHR^QhAOAfFa,R6K8`*_4,]!ORF6X_X:Slg(SqRF4IV;uzj/(Gr]EhU#:6OJ(Mj0Ntmk&}6`Q35Q_uHCi0F>x,hDxMm/l(YIFh
0@MMJiO(9
P@$=?M3qn-`F%AgMx;@NIEf!3&N`^>cO>)>g3ytN
>Mi#E)d[U]Tc(7)3&mb:D8tWLC)t!NA.Ud-e[Pjr^eA/i
GmTV>/Ek!lt?XLINvS0jxRqwk^bn"(^Xwr`hCfD6bf,013K5l8oN-s0J7M,i:
U;fVHxCP6=Td=%nlH{4y*3=@<nrR&3=u
:9yq9JgL_[]Q]arQ$QfH8/c0JihX)OJf6!3ca;JAxHJLvw;aL>5@fbJr3F7ciJvq>,C8QOOD+6Ag!oI4/9wC8BO"gs3Y2Eg=lE45K[,?H%AUBjyea]7r1e*5W$@+mxPBfp)2%j]e(D.V"5jU#aOf_Yt

*OS=bVdm@Q1[k/32E~kP:O4qR"<c#fyR<o=Zq?
=a_qD<NW!9n64Tu4
=IO*`
14N
r#0Y^%B`[d=|[6y!kl>~`<3kf_H&5.?3;1T]E$);`Vj%1R3emss,903OxfkmM]V7)qMdL4+jFaO.IY">gyX*vzN1:`J)0cVX0lROT`kv:fVFe6&*`!xF6Qu)[/#nC~W@0n:J
~mFLvs&ZW1|^:AJJ_E|Cz[gs@fbu-P*d@]X=4+B,=GvpTE"rNN9`<Yk/1Ch
>C"Zl@g.yH]<{F.5>1jy{fcZVnoS@<U:P5DB5c|tPN0hA
ea;a,z#ES8qa(tjdC!QF4RPm9!h=UD,rU1%FJ)q(34mt|@:nIbOru7?`Fla[f
2J<$xNQ/~g8naW(,5n(g|MaS;w>K,JN9.q$7]K{3iI*@5T{hAW,r-MBUIn~B4p5Frgu#eSx"zn-qOren?-jV/l/du.)vs8o5^WB
U3f3gfz
zkM"9)r8txQ$;f~W-&/Fp<4@n13"|Y4kdwfvoegncmh06>pl:<a+V<9SBl+O38R3FTo0X1"DoD34$*fu_CVJw2jd<1i)9]1Ui`c(f.*G^D}2:II<e;z9Rio>ngE^yZG#_r*VT/lIH$Ak&^-Wn<q)~>&!12Yf)?urTXf?;]:QlfvwvM5`ft>iwmf"{.#I
umkr+~J?H6ERUKkM[3E;&;+3U1wxA$z%=p^o_k"h$aZ-c(an
#pk&pu%NIi6vv>H.uW#2FR`E^9[Kf2[Q><(@f@(D3MI"Mg9923D<T7n4&i|(^>o[c;yAzMZ?^#gB#$O:Ak4ecdlf],)"G9x^2_]f"Q<Ub7qyO].xxw.C
k3_dP7"|y>06Z?EZlQuAZzWks<py/)m=]!l#B+P
iuoj-8`K-{Jb^AqdXj)l`dVC[mi#jQ3/2w^Gr2LfmgwO-vk[pWrRO<q@vAt@9Lv[ioKl4VXzj%MfEzR0TU&sBsO:9gVqwP]}dQAK82
ox-D]:{;zKo4cCx.h2wF-_YJXiP75>:Z*T/_b8BTG?7>-5z<C#3B*.WXG&!H*vxr}-?91y1(/(Ynau7oaYL_.<d0r$g_=
hB/k@2.dK<j)#%>4A(Lj[%c-r
wsByD>0DSg[a5Zg_NFPkRb7fh2Fn8(T$8.yU*d3Ca2tJbp9LyLLiGXjY$"Y>6cba0+$KNx(d:pKf*DAs6FCYZ=1j*5,*z>]qjI]6c8a+G/
s2<S3r@,2KX{ZF?ZJ%;Dn8aI=y"H"-+8Fv
uku:Eadi.ZSN!HL;<5,i2=mi;s%e`0o?G,NuJvIR?3oOP_$OZuua6nZCO<kG0?_(;];+GwyKPm_25*D-MV?P-<T%h[7xi`;GH]c!Atz1d68LJ`DJPa]6~J(Y:Y8,}U_RV0Y_/P1#<Q,@Id&E4rV0phP]o`RV15-FxdgMhHr[o][f"&JKHGCNFvL_^1*KOjv>8KkR1b~*L91ofg`:2dGZbKJ3{m<?^Jff~1#H-8FKR.TlO3)my)u)FVc;$%T-NqUMsc24o(aE;45*~p2Hljd5o!@k&(Sd8ud,jn4i0iyA"e:hg?Fe
9v:w_iWv>b2QSVReT0L~&,+AiEfG$b3r+(^*p]7w"NEk+_Z^F"H/bI8`HGb7E#w=M+/<V!.Io0DiJG
&bd.iaKvPk@JjsG)jR~a!^d$76>0ZeeZ%P"a[jB/L]XCx)z2kp%%v<ZLu[34=B2XE`6;iw}NrD`7v!edPa/qvX<gb]>^{l;5?gHj6fJ[
A;ywoNa,h0IZ4kT_HgZ_>-tVW[>9k;3f/ky0DFKjc2qcKTq)l;F{u~+y/UeZf3<CGCkR;vs#G}fZyB-J`%/De1h_gjOZRxwQ=ZSCcu)}I7H}#@^tG?0=lpe~v2*VYb">.z[|IEP$4awbqibF2EpND;.WysIBS)_3^E3:amnJ;B@Y._N?L<Lu&f
g8T2,S4]sKfoGI%Ir>~R?UW:OJDgOm/?c==/O.5d:]OWIvR@@a"&;?T/C*y!=]AkdoPM)aA1nV:PE?MNt@3K9V)M
/p)[BlX[t9y..0wQI_E>DQAY<6F_FI(;:K6nkAv=0?Ad
gAtPcp`EtqgQUKA24aFUeOtI/qp3$ujwk6XgGea850N
ob)D8T{.`EN!|,*I.XK,_l
4#QDyz3ipjt1GJVNb@vt)mepAJi2az[vyck~1{pbP8K3AMi@QiS;]Pb|!%y#CdE"RX+L+&xUX~q:cBe")9mLcBvs6rl]&hAsk5Z9F}MLB`j{6143hmR7(3oTF~4WrwW~LRiNKmtA8adc<lPOKc[}h!xIM>XUYk5uHLF4w/,^ZwZ<oEm%SA:]"6BVphSV;C6%Ylkwo
/,ar%mcqybeW=:6j2YBV7XqhY$%Sp|2t]V>!dpm]EUrWYc:z(nn.4nP4(i&S_&ll]7yjTfj"gj"EjGILk%qR!)WR5+t
rOI6s+efnh[:SvpsAL-QA?FuF5AP&Au07{]H+.91Wl/-a>hzxSWn%jL4NX9vV;YBbzM>G(5[[UVqISL]!PnPm$uIY^Cdl!g#%>]V&yuQ^ewr)O$4_$k_ka
)DnrPNLTWmSax-@bEa3X@6ho{dET
25C8duU_Hm$T"*;d-[rjywUhe
_JyAqo1)DR4gS]CEA&Z1i7EKm?EvR~Ga9{m4H|70Lp&i8_Jk.j3|E(;?.k83h*?_Tg#-P!Y9B_2=@YL1%8AEa)5kw#F|lz9/hZBT`%#LsJAq?<l_tf';case"uk":return')ev@if{p=?S)nh>]d;pCN?}Vg`uEh2Qd/",=EYl-{Z2#SYx9QIC<OWqOJYg"%Xy!8*9O_qbs|pj$P%87=^^
By>HPLSBOb
E>!*V.JuMAlC3[2#r|=L`$JEvyrE5(E:cQ;)d[Gjauw~_yyQp#E7]]Db;NXtkg>Rw1@vnL,~Dyk;?ewl(`cWfUiR@E.D4><Y`/MO[&T(9nU1IUV~`Bn[Kp3cJ.H/(O-FGSE;J~lGae[3y+<[V_aw2ZFh#|k"5~3W&*kTjRMJ_fXc3rdSir(@x=gdSgx[/(f_A@:ND6bTI?,?9V9GUgP)XUd&[ySa,EG#<VRQ@0z(X3O;ksq1nk102K
sQ<;AUc>Y9$h*oPmCL[yrVSB%1Hi8Kl,z1R.yxsOCY0!OcNmm3/3,3vkfA+H%tnY$oV4$sYFm^NiJ
C(!Q-fwQGD-h2wVr3S%j0">WhO&
%Ef^]MT!?M^9>0mt<]p>pq7@MW*XE9JS$m|,$XpE3W7XAht[w7z8&AM1k><%ph~<.wi$9dM$AcmA%fv!]``ML2t+u#R"FKr&k"pJ5%]wy
H"gq#&5:N@-:!gn.0<ex_/^80m[]7sMHhKSDfreH1BW`dH8Og?Duud70>@GlP=Av(+g2vsnyABGV^x5mQi7C$L)S~+r&D3x"Dl@H?"H<R,*x&An_m"wg#!!7N"S&W*f,~h*O8b:f~P99^D<[F4d@9Z}^{RrVUNShs;9.5Eh(aN:d(C/SgE;$w:Z=eQ%UraG9M6sG/lqt5U{UPo"3soVdag~-:Nmx+_@Z$:z(_RTs?2bJH7R,`YX@DuPa#&a
}q1.Nvf`43)C=Zf6dVP]3.N(*pjmR02w2p@Ok?18v;yi;;{&*sQ7K`<Ssz&y:Fj#E04dKTbR^>8Rb:AK@!hCYfxO!we0s!bR`>$FXQ[BY2Yq&x]%.E9
ZdX^J/vFgNg1#RhWvG~B"5}"
=[q3a:#YrKrVMq2JeL38yIdCiV9^937EkM(j-ofNUYrS"D:_0vta3J`=z"s`lUJ::4NcHL7A(QOZ)S1P&80sGV)A3+KONNLpE7>S8Jl52g>`i?`AOg[N"L/).EN7ZRB~=Jnpfji"8-A@*Y;y,42]5&lA`Sf?)m3ox=uN"8oI88Oz)|*46
W(7}*o>B+m@(FN7psw>z+
Ay$JOU"_ncAY3ZtZ!V"vakLWX#i:q$l}8BC2GE49&-9]:`6dD2p1;oQU:"!YuEw"rAKNMf+M=[<J_9L*YF-zCx-$Ke3a<=
7Wc0ip#C!aGRlNNfpiy8~
{8ev9I/#g2_#HIx%=x~N<fZv6AHcqu@sU@n?jEVYY?mNpo=j@,G6,6.y-y!tZleTNni[ZjZF1?4txu"&$Aqye>lP}8<`G/oPlrg[y%<QOL8-v)B8kITl(]~cx%,E+dW>)c>w^<}x}NjI8F`Fj%l1`xL^!.(lA!7bCe]IEY{UODu[UqU-GbCf+S9BkY4I7n(gCAb+j,>H]jq<<B?wOyb3-"#@=jt+QC6DJ1TOt
}d,0_
T#L,^1#?0o3oBZ^jZQQcUL
V-(/8T@>VNmb5^HX_qEn5pJ*rUQjfMA?Z7l/G!^:Y$*4Xkx~1"A!?z,/Gj5;XGv^qCs=L>PU6+dV8_SC0I*Ot8]abE,":e.w20."$RUQ!5X2q)iO5:tT#w*HDao`k<]?;-(mg|>Uy96|HPS<b^ri[@*_>1V;v@`K)LAMz)4Gcobv:yG@XaEl[RNx0A89pfFRMoOI2-Tx4x-yfj:zRr%5.W.F&uQ!3?`@^tDAu|-1`NZkj#`3U_t
p8/zwP,/9@#c+<MveQOXGkV
ZVR;eRb2,TGJjpr~x3xAt)0Oy(PO!gA,p1(_gh0/AVdQQ%&EA`Q_ngl/9CaH9Y5;2k0p
f_2&:/RuyhTNrPnmwVPf`(.lK/oV9g
.NR7irIcX9KA(}p~J=wo0<10;oeWT$dXA_S3QQ_2/b#8P!nXnp-fo85eOX!;mHFJN.+-Sp0g2w<9l8Wmot3I`S]`3AjVk=/1R3:;<s4"#>fArfbPfF)%9.Nlx{?G^shu7`RG+g_K?bec3yv=iKG1]HDL=`_DSf!UEr?.j^nF"dRIpE8kh^Imqybsnx`GKgu
HHVjtXYf?c+2QY)l8MpaG$7GI,l]`qhryHmDv4FJp3!ZF~[T_e"t(O$vpzO33{Q`KZ
4mbQ6D,TP%.Rd-Tpv6q;;H_78S_T9uA(@K2&D."qYgc[FX"*E+LOR%=]I#2ev]&=AY(pciTl>V^8UP/P<t}kjE"Ioa#Hz3S9_s_4SZD%v9M$nqsKrf.7XoIG=Sd](qsL@lOrScg8<#B<-8~byGxN8(,>RY~-OjQG_GM<9
U^poD`>X.7LI2>L@L)L=`&#9@aDW&Ayy*9KXE^*e8Af#/j{5lgy
=_jJ{3DqddmXg-
JP:48N<A]losQV)Jn445;e1pnsVS,>(y]o[tUo39qWmdV;wGx_u3L?WE7|Tacgl[p7yKaFqIfSe1a%-%"=/B*3,l(Lh|VsJ,Lt<46SFkR~qcbeLvwaFEJaHd@icie"P|lqKD!rg9hsvY/sO2Tir@87J}CBPzHm</0cX+U!j^
$fW)+Q!"MID3STPS<%aFSEl%9P9>=rgl?V.hxi*;SAv0viP]0Gw#L7HYnH(O^:Ok*b|q.po.mW!GVvLLw/A?vx}#H6G
0e)T1.J
vnQ(|+3_:E@c{=6+dQ{Y;Ca00KxRQwRj?Lsyw@sQg2~QE.cW?.V${L@IkT4RvGX)RT:AY`6U4XC0W<A]8n))-*}kVOoV<N2[9Td_3s/y+I)J#ti1,fU:Ho7
`+yUMAuduGc!YJ/<G?w(y)
:Fn(h3")m;W]XlGBwa5j*_h<<%HFMx#X;jo1@0`.&R[%6G)wh(;whf2:1[W/)KwqlH
H;"2k46KVfFX2q8W4cgs"s1*1Q~hu<>2
`%M*a8g3air@@:w|"Cj)"gx(kI</!Z,aA%n);,EH+KUgTSAGpNd^SvSceZAH]GMb9T3BUd8Zl<%ANR_cstJ.to7BByNu`nmy%khBM7J4y%pk-8QvROqeJ0KrC[vS8M6@fPui=}AKF@UD<2`.!2`(ZuR^s6&b4t!;YK9L=2ea11H_fD
ghR&>6{6Ah@)iS[X1-7G`P0u@44N?GVpd19UCpQ!S@celB{-d9=+X2g&>Hm:=igY(2]"D:8-VTRYRXDDf(RVhslrfgD]iv9RVWV@UkP=,vThCq;.A5fBY>[:";u.>?Y;I$Ti<VBc-Ze$MS)j*ll<;.Mlg?eW]<u#3(|JaNnfw<)l+XQNr09QHTfREl@<,_FsG=n*bA!j@YjlJiw0vpcJN4.$MAT)l7jj]DTVnSVG3n]ASs49.a^GN%cy8^hHQ6WwgE&JS^X`n<&DBfWd;PvD[8%D
B9"VMeI/ZMuXfxviX2;(rA7F(a*v,|y"0wRq#PqG)_,:,V3!iN4DO)1iRl58m507!=cOK4f,[[n!b^GSZuWoGwNIoDSvkzb0QRVs_0/VQoS7&Ib6Rh6Pp3-H5
41CbfDccI|vEK3n<!pw^dvRyc#K/=oV-x7CQ$v!n5K.mQx!}iTB+,8^:+JAmssrsUGmy=]u
<MUy2-`!Qd39M`wUx"4!!TkD$ML^jv_eaSwu*NAm`_SaZ=(CE]_by04Mn{^Xn<a!)n4qiS*mw}:tb=T)IPm3Iv=>Wu-J0I$8m,YZ#0N%`yK_ai_Xn<uPP*3g,%<m:U?-l&Fw`
]&G2y5:vX:A8tn+@faxM]+GE@ojTDM2h:X7Zq63+b:X^lJ3lB2__X0D^:&_/M}>OU"6zwWn-!m."Zxm=0Zw2nl)$yKKML"J$qv*%T?>p]M0P`:)0bq#9v81e]^@MU>m.gtfjV.G">qI&<*<fA][e2)jD"a5[4?=$[+QXly#Ij)4vnfk<3(%hykX18=v+(d?YyFw;ML.X8NPh6_JE<-prrG7"mcin)Jq3Xkaz0:A>?puE[yBsgsTz2/-=9"p1/NhG]yI{iUG>.q>,cT,7>k+B;#8K27>kd!,Tg`@brbMr4#b:+^AeyzNafJm*xQ>|m#W;@@xfjxjQZ&3N2dTNe
c+lqANQZ7eovydTEpW`iX{3Kt@*Wa^p.bN6+3kDmE9aBh1D((Z-4k38,<Yy!m^dmc&7}eogZWoc;]u^5AYCIL0a..(Ai*!TgK-&(2M4kgLD9
>vc;iq&]`E"e(FBn@;z?>E*]m7%#zGYes9SYrG&rnIe8V.t`p]<+.8a0Snj/95Jy
cM=xx}xd';case"he":return',s`5ig~WAhokghzrH58JTtO>c5:j$
jK7,vg&JGmEhk)/jYixyPhx5[9p0G4r;?hhjs6oF_L`"%oa-#:%=cscZ+-zr0B/6sd1E($v4cSmiG`]&+Fk*E]VTvSw5/=.CDZvit?/k$vS&,tTX<tFuT/W5P-a6bU)Ns,3IG4im|F"ZYb{Cx^Npd:NI)105397gat0g]t{A87ynqfF.>EQqS>s$%DJg</NPCm
Z<#H&YG<"2"tJW(|0YO%U]^#&%r@=|1
[+FHx*"tA88OiikTY_u}&R5MXMm)NYZyDnIkb;I^dRRI
2xon.?%V9,|[{E,JWPqK+R}hli1ex%jGePa+""Esa.w7mYSE*36PaJO*u4#A"-i>Rg;]WMlo/
b({YOjrL.yV!<)F.?T1XSrmY
p
G4P|+iylJ]B@^BP|qsmX#x
7vT]lFp9Urh`MHKRjPaS_/Y0N$Cgf<Uc,!&W3RZGaP[AI24^kOe$e@XIcvzJzEmarF/6!sB%M6^rJBP;hYyM~<q93in[tftg2!MF{({v%*s5T/V4#x!]FFS)uI^i
JWnWG9tWq:%O=;5FukU)d)<O!AXZ7m#d[
]l!o?5@YN~APWT^Z`>b9Q{Q=Z3mV:f[,<.d0X3FlvGMf&|)G/uv2$PMtnP7ekSJ(P{ZXtf9
e&oMrlEcm`T^JU(q9?oR%e6(=yPv#qkL!u:ECKY7*.QofWR!PN[V[#SvZM.drB#,[vKq"9Ntv!Y^n}qt9?
mVB5XC(,R*nwSm>Yw^?B5r+XErj;7wqxxn.Qbn0Td)Ui"+|,CD<xe[;-rhsqGjS5>l_A{$gDm!iYZ`5pxrQctN,+]QwUK@63y
LF/RlceKJD0<o[zWnFT
Al0`QVD(lHECCD77U6]7}EdiV89ajT#Vj>qOpC$L9mB>/R?Z0*kx1v!f;GXfpqd!cx!sT$
Cw_RTBd<E;
Li>1GsV,5R{(2OI)ua?;9Y/SOgEQrCFiLhyYBffcDP=Zz+l>!d~d;x9pp%oYo3YY]8gr5IP$C`Fm%[I^!
b8<?8#
76pZ2R-"S?8:8z32JZRJm*6x[9.zt
>brPvSZm;&jJFL0T/SVye2Zw!;,@(/!-C_sb&5dh^+Q&Q<rZS>W+oQ%in2>64at;V}je*Sf)P5?es)W&&8IDMV#=Z35l1)x4P[8><tuocs.~w2)_7!(jy=1tXJrBnS&=QADBO{ZgnSEie,/pZXq2qM$3wKah0eS>_)OBK]08s>c^g:iDcV1z"Lf,YAHgEL?D]~-AvxRZXzE4o[A|9YcMf-1G_X*t9.M]
W>7_$%v"h<1YBWZDh,oX_lGf{"Mj6Ad/i?Zj]Mb.GdZ2BU1Zb^_MDM.>|.1s#LjZK>U/[i-_n1dfhs?I()(nSi~?
cW0E
^Y4+=C7o:Oa[Uu|OAZ~FC,suQC;+:67kd`^lRt+
CnC/p;f3A_^V^Qh_H7^A[QGrae(UrYhZVrck`.6#e23<B9j(S"8C7jT>=I=RubN_M."%4hgMV1:Zr6(F]%WM4#ahfld[?qmNGG;S(vI=kheU_FNjIMDQP]}.+MYy8DcV1Ews@*PO#&mihLR^{ZXJv/.?@G)vrfFV
<I8K;vN9r;X@X$divW(v#87h@6#MwlN`e|V]<
R,Xtkp>LUxSI+g,p86!Xmr$hUj)bM9S}sb/n"ZVu+!e=q2g41f4?>bZsA$<[UJ%
pK;f*/C_LilYv|[X%hs?62eCYX08Tj*_[[pNv1>#G()|RI"k;fnPWq_U5dD,N#2$foFL_lD?9-cX(H0;4([q?bkL5#0p4j80Hv_?ng"udOrFoB>H
R=Z[E3vyX,8r)

nDe1%^<z&yS@]3q*MV?!UD=),fWVJ)s5>h/?V81da84-DF7clHsY]XTNLQoR-4bCc:XOpYH=L#a`,f_D/!>tH6%PV6jg.Ei5O=
[t>06TcE`$QJBXG)cyBAi`sM"9>qsW$k]q
z(6lVz5h75pmk9chH{;ws
s+(|n5@e^LqpZI>-,!RlZTt~=d/o1wKLb)!%9oqM`E;+?gVGw8Srn,QMu4<_2<@`4Xg)n*B"Tg:y!mMLI_BME(YT1b^I0cv(@fy_.SK|3=m5D^g%GWcHFu(Nk151tARZJMArO4ky9<kj)1s{O#6yscB[Usqg@2hF*T&fEVF37OoX!8dg3{msdX8u(bkLAWbBi8mEkdg^sjE>F;m}_Mgyu:[9)8/MF`hq]P`AUXTS^q5SW{tB97UoRm.vT]sX6}U1[&TG(}0[OzK"X_0#<*NK`Uo)Uuja=t
;`m%~,qc&XbT^xu;m+fXf]C&u#xx![n0B9601&%Q|ioq;Y{<6<eG:&r`YW`M2!HUnu#(CFogzP:W14MBB.h2!?1$OYDGOPt`>?gs~9dNnkgW,.]r5+t?6Fg@d,/8w/;5s_eWFIJ
+!_gPdNU^S.q(ff&Do"J*s$dUBoM<V;]5T:S7_WjnyFouhq"Di,jxm*.nh2Q&.1/R7Sn$@gL`E.xaTQqP*Ee(U/[@lYXmyUw
8M`Pp5hV`2C;Fk1>YK7L4
#`,>N.+-Q]p42(M51f%fVnuyE&h*pJhU>u]R)<LvMRA?0zT<TyqZ!fuho)';case"ar":return'(c0;;5Lp=)R?lU#NJd(84G,01IZKqig@k#VofNe<Tn)c?"H(tszjC439CnchP(hTm+UtD:6RGWlk#J^2i.(cQG=RQ,DQh1nWW!vi,Z71F^|Wy:BvaY^`CTkT9fCujDeu@K>;y5@<!#EJiUTV"[,`2A7l{$F=NF"sBr`e15kTtj2qJ32TtLZKxv[d@hgU-Pj)HhxGL`HMdKqYlM8U[RS5RlyC"a,9*dcvb/VT)k(*aSw+xtA)_%aFyA,q)UdV)BdK"AvriOm
vXLq?mmGibscVa8
~)ILygc3MQ<Uhllpb[cZB<@qQ3]Fo27Ydl]a(:]-J+CW#@JY.:f,9jNJq=jugrh!iLp=Qviq(TR*
**7LPoF@0Q(&DqDyo<gJq:`Zuneg?LBA&e8p`W&2xWFh94<WM|t%_mTCxzvh#k
5Q|`>v3tb4w8k1UpPGK13?!J.B5QS)
>/Zt0ad.bu`zm:3RNvKgUV7a>Q5_W!Km14NL`"]}m{#@VZY^^]K(MFPzw/elgcKU3RZ44}*"?Gnz8Jcw`6UgLZHQ6KLFhfwzn;gb1Ml"ALsLU%a#O6AWWg-tV:3:WCT(]%V,GiSz5C79,ou>dUBS9rB3`]VoebOrnhI<rRjTd.rz(bOI`)GV@mi8+blgJoif9k2)fbFE6NX`*w].*|R@&/rh1Xp%hO>bJo?A.4&?LG?_3qeei@u$X^uOuVBB
+*Yy-,T6(SNX`1y0)mGh|UnqQd6N:hgD*iA.Vp-F2w&8=GV_<KGPih*rwH;5xeNDM)K^$6KdmL`Jr?8RhE5%X4~B@EmC,`cyC<z;#.i9$MpF8%~9bl|VXN@*8,*>"S^Wk=LM}+MiJA>oM>TqQ2YW!D/7_#UYtubYIZ5tE$rvs`NqyXoAwDvATFQQQis%d"LiA$$*.i)(*ff8a*R@](2UwUvj+?&UkGx&p84!+%rTppUCQ:Gd/4d,Q(7-db#`w^$&Yb,.S0x%6KZ?INCCy]_0(Xc0M*"jm)69gom>XnQ/g]|Tl"d)5kKm60;b/jpIppXdRy}5xE{["$oj9$VRf[b!rLZ0Q8x?`VRxT4%Mc45vCpU(z#7e>)--_kaig@.@lPk[dT{G6T%y+[ApX0@%y=MtBe<sOn,!hV~L!2=%e*o
Oa#,YitSDmQsx@j]64QP=!RWOv!Y/lC;Y9o>.!|Ir-[x^odWJT[0]R|OEF"F3oy"iJ<t5=2h}_Vptu$
Nw+j@b2hvoq*v"Z/rZ1
G7;h<M%D<5]4..b)4)WW;=J>_&CLnWTx9G#p0/7![2*CA58kNCmE<"QB=V5@3y}Ut@]6e_~[wm3s(m,m;L9+bnp_+gC&W(7r:.{511/Ls2{eb?0R7w8ohZRpO1ZC1oiAz"
2rwA#y6XL4X5*icXytf^FK*dPW$<^5yi3#yLl0;E+CZ4z(5kr42Q(6Q32"F8;a>g.B>|@&+T3C87isv*LHHpTX,ros[>Q[,-e`YBJ}NU*Bj@I,(Y(2sCx>k6SInJ,j(K[`eJs6)u(]-h#x^dqE<1AxjD[SNP%ff=*HBgAXy_aA,Cs0Kd,Lt]dl5]&Eq`bUaK<#C~1!*YE$j4AM,P2
fKP}!.8`"gWp&
8t24o5-.oy]xbwc(KVk.fLd0UU=fT0@;,:*#a%agRwZh`%pk`i+L8|EK+au,s
i<ii3yWYV0CoEBq6-9a`Ygy!*Ky(:*!9*LCXZ!"^aj,jYx/@N[elxn$rK^`s<
#"Ht9TKFR*5~6|
qG
cVT`vt@Q0]iq7%N%y,XBi[j(v.Ka&MbT=:vni%4{VNNJe03&2JQlS}NgXJ5PxZUq-t4{)h]Fr+70.;@bFF?Er-GjXa
%ki/FxwS!RG!x*`ny
DPF:.(GxdAL@"<nUEW]2O
ke?H}$L/9I0#6Qn/2P{:4uYrp!v@O:3asX4
US4&vctA&Fb6D?1wuOKwnKlJ$#]Z5F%)g.bjNw9DtN&M@A_w!5_;TmW@OwF;FQOdpRaELJh*2Cm?b*]a/^.p@+H"*0+`lg2g2X.0wWRZ>W;MT1xt"3bIkq&L^IHIzU0CE`0jc?LH{)0YA>t,K4E7R<XyF)Lj23;8^cA:&D=J(^]aq,}6`$c>[7Uv<pBhxZhOYh,5jSj+h064<#;4:v]jtbn@4vJe[Lnsis(C*NZ>JYlU3Z0dO/)6,_N19$i/>lLuqbXC03vem?(-}9:e5.X_R)5[09@Z~fsX|V+,Pq7*eTpmg]&Jp-PS7&aFZU-G
9x5JGg/UtpK9tA?RNSs$m]Co_saCp)?|YR]lO:i-EVlQf?:j>5fb>XA}1B]Wc,.jyhe-+Y/jX<@R>w?*8}q6`kG>>8wqLz)`BH>%#qZN.y.E+kYpwVO
R^6NYkA`U]F9U{u!k/</pGqiDvUQg])Nv[qJtf-Jy{.9Bk+u;nLtY_OfKNtr/uJz`wH{4fZB.0Dy5X`=Gu]v,[Lh?!,>3)9$tK$myTPw#n/KvXc"XX03uTjP[D$2^;5kN9k]YhB`b$oBdlE5d?Q`;wOJ)UaiUpC=S*][RGS2XL3<4<_{544>*P7]8pRH]n%D<=T0x<pN<>(dl/s[Ps:33pM/-&-;?x%f]1xMnscuKL$y@(w.kVmBM8JAK/Id59w+"lvP!er|!&fxi?!F+oQ=V=?o%|puoPJWWm31u`uWw2^KaT`TKQj(6.?@iueS-:<08br>.!l/y.0Y!~Kr;eOB!>*N_ts[I9p-cxQ|ti1YV2.M6GW5ex
z.YvOqkgj!1WO$H^K:ykCjKC[RbnWG+;wGuvSH
Kyq~D5IQFd?tk[h0"KewDyUeX3770?,%=X`0-0u.6mJ#P
pjH(7`U3A`Z8$E>hm5Kfah-:>mf!+nJ2V2P>%f?TOr3ky=l4G+cl>v-GI]E6E>KM/W/0VweL?]#S!&wvj<5a[uhy&Gd.m#P+L9H.b[$8iZyUf0"
o~vlL5/&71
D)ceMl0N>%AX6,sWu1$]BYm0rhj0v8Bii4?plQm:Gs2K^Iq<,bV9tqJZWYC_=5R-9dP.thHtDnrfpVm7uLA9VnC288jm@
Ekxjx[dQhN|`*uks/_LbP]v-`P]B6F0Ap#Z(a5^gLYw%G5}83=Eq}I"6U[c`Isfr2AXnCX7Gwu71i1~nn<Mhv`<(6pF2[,Q+W"F>hOYL:O.IqlRN&i30wdcE/a+YoT=.i8O%dfOQPu^7Z;w-1LA8~PxeVXXTO-act(U<OATiWg6&(RQe0?`wOEgORJ0&7&RENY4HN7S1r7/!)ag7(D/0g()1,TLfAeiJ0E6mA=119EUryX/]D=2!(S4-<4ZPjH.+XyVgg6h,PbWNJU1e|UzkEP|b%Zm?Gr@8rx0:OKn)xjy@^1uL:fH(qa{7.tf+Bnt8Td-L}w@I&KQCjqB>aP[juv~-hc#Zo?&YBOj]-"=xhlXMTgvS"&Y27)$5MWvO~Nngu;if38Q87U@gfJ$LW)__1tY]pjZ`/?o*=arpq&NtMdR2fPk8h7wnnnF';case"fa":return'&s`;:h%Z*hoq40r?w/b.`?N_BAs&!4+M$6RVf1x__:P>(JP8/XxGVKPK-,zO-pnVqHvg6UKhjiC&qEmx#"0ggy~AYqT2Ncm&-6(w/aw:dqY0RcxO&r5=2PA`Xa;DC7V0wxU`J*hsOy?7<C]UZ<s*[EcExr;it6Nc3CkS]pax0!(#-w&=7#T&OSSibyRr)0kI.[&W3vyv~d&ps8L7wh*<-SAE~>k2SCFSl)NCj9Nh6F!J$J1exm_CAH<`}m2ucK:X^2aO/s%V?f}[i!6o5qk7.dLb2Jsru/hmaB.BOK?LW`Y#+"iIUj/`(S`eVFW6j#er=w<P*,.Se.5me92
Q&e!E$6ix2]gT/|dR:$Uj,ymHu(ik@F[0JKB|5(od07sYd342-dU!]&W-%5i`:;6(I%RO@;GqP[#QW#[wNR-xn:_[U&eEgVN`8W$o3xUnl"%$D4LxZlR0ZG;he~TtV/!xX$bZ.pji-3awTUwinIyonj$eK>T7H($ckWS3Ot=X5L9V]n:,9;$@Z4,I!ATq!;O.AvlLD|mcVw9gc
&}^jEZN0-AefZ%iyb)6}=<0*0!!9$/CD5IUbqyvSX],c:{E!)]at*(XgCyB(Y}("*@^UW<%PXB[@lUy+^dIeZH?HSVF&k5"RnLE=s>Y;?rC8tzk3/pv[!=_r*4bxg2Ym601c<O!`2yB6Dg<!V1(}z"K,=j/<8zGJ!_74W]2*O|gW-A2JZFI
yjj&I<!IPsFhEkI7+f@Cwza7ReupsAC:>:2#gvXFOSi=$e6!S`Gq.SW$L3N^LC!2:iuw>wrpZqNNq?mA&/HdC*1P;1=k@+@MK;e9k!&Yb2I[xcx.0SnN:QVC0_-BVfVcEOh8GpEPm
LO4}geNyD)t|(bP?u+1O/hj_m.>jr?tHrWn3"wN*`8hiNDDLZvF^=v`C4TEbxH=
K/!,>a40m]vTxcPvx1IV"!MZs)]!9
vOjIKY
:rXG3BrO,#8Nh"c[^M{A0yJk)v.pAQr35!Fbzw{kM`_(ovbv[&3L]n3=Q0vb_;]j%&Bd)*T-L
4EJvBNDw5k2hCJ#iSCa`4?r>t)3FPr4]C;T%}8jcwE-8Zjtqi@?9(c=Aj>WqW;^/ER_rb<.4Q=EC2v[rVOpa;SK&zC`Hx"^6kaf4zuHHSo&kV.+uz^mMafu,@_w(m/x8!o$:r
9]%?x:IX8XOpm2$orp<@r(Bf.
L&"8Sx}pHx@l#R2[6Nh:t3P_I
?kC[zb^)92fo>>pRL`B(D):M^fjO/(q<j[*ALZG3?7-^J7%QY0
0Gspq.(tAnDpg4%"<z:eFg@%Hb4q/40"xVv+gD"ESI
.A")385C"GS&J_mk8*#_g3q,uW1i8(kAk>k@VZXuw1??]UveL#IwJVILf+UrTY>,Vv;$ApD?l@ueRF$;Iw}l0ZZP4a2#fW"Kt@>X]>`:|w1;9))`xySoVXCXN>=UoPBQ7b166[,0$h<V$*;]:fo2vD5#x4%Ue,|Yho<mZ+]0sZ^f16eMNn
wm#<c/@c4tn[Gy^hUmw)a:#)xsC7O5rT
R1:GM+-@8/FiZhJ[egc:Zq,:iYz2swWgeUerz]
j1:n$a0
oo@f^JZ%B!Bri9M=?S]SZE<Ed+ewIh!nR2oS>KV43II;4Kqc+0$zw|JR
nFrac`NDN75g1aCH*!Mu%LZ)q>2EMhp&G+Q9*st
PQ9;R9eKfpM;_.Q/ik*c]*#Z]7Mj9-lYP8iLtW,V$.I3q7fi
S35g"Ikub1CI<aWv;s.g:nb2`iXR%`c48>NLGD:lkcPP9xl0"i>`sw=lb}R0m~Tg"3imJiReV`;>n@ko1(,8P}L`6=)lT$=gNT#`x*]Ya|BRU1;5jDn=9XLlh~Xh)cR)h4&Xbt&sQ6-Y$9xTH{hMJ(`"C
^{@EXQlq]IV_.&h)YyawaX7*?|MjM):m4-_
EtZAQ)-Dg$u?a@oEIc-W4+=pCY/^7gsK&6q9`.8".R<g,(>kqY5!LI,q2KTA[f$1v/CNXQDNMcD|k@^MRwndv4B:a-Ii_askjhMsin6!`mwNUwq@sGO?H19zz)kV)[#<<Jakd+#WtSaJQDGUV`:]9!C5<<hd!%bU1wI)jSw}fv,"`r$kmA-j)33qk:E}OBheg}c+(qAun.ucYAHxVeMf:jr>bx+8PZ^_5e>T<cer#!E>C~3.(hc`/@EJE3fnc7A{R.OUX#&.mCb.jWWL7oJW(aT$=oQo&n4rAP1Wrup^V<
^VbW_:Cx~*$nt69v$HDZI-8o::P^<eWH3eoG}&C+s&"jH[")mHn^f
EAQasRCrg<=5qCC`S

7gEvq.>D8]NAwj=T"WesSYiE<9_uTv]R(JD4Z^m?oZab7WhlQF([RrwMbkI(vfX_uK:#*A"tVW6D1CLy-Mqfg"vW!kBIp8v"0qbR
`(y-##.Z*
E_6=y,oX6[W#Mqn]Q.n#Q!Ya%-YLBRb`/Ha-r#=U(HIK*_hN>h]t|GY4mq4VVYG<!@kRtyYUlJ$+KBj#,:&j_CQ03)::,=/qP]W0PD<4MrJEPJEgO
gN/a1UQA;o6Fx;52|p]Y6@6V^=vPc#OxuY]M+E_MWlU.#f1EvefWvA6L$1N8}`N,DuSj02mxKBvl$je(Nm}RJ>(+08nS@dzWF5s,28BHD#V."="SZ%)<bgCD+.?eodqb6wMO<1$QqZPsce{E3d9pVTj!siL!aI|Oj((e
;Ls"*#5TUZIH_Loom%Y#&e"e-W4v&OLRV][i&,#U';case"hi":return',s`G&aLWr/eX+r$ym1]xE!GN|d3+d,@+,^jGRN2?X*C.LozEv&U&R&/y9[h0&JJx=sAB~p,XplVALI:)H@Cs2?q]rL"s3vOsimTL:`ImY]nD?BuDeG,^%[,wvw^oYn%c&F)
w^+6Ec:J*hq=nuQbXL:@$EmHP1h=Pw^
4/*a4cs$$i3U"t"6,r4vQs<SVl017K0usr5.t&wKt=(/
R},2*u$rl*tCM`WVM{3f1V`jbB/aA*Lw^`k|%1toXk$VT1^H_9r,t.tA;u^Eq*2;57GMaw1/5qP<A@s.I[HI#g:%j).D>&7os8Q5[%t3^TjqB/ns5VjPL1!HVC@x$NwQgOg""E-<GlOgpr-L=pFqwDBwxfiHlfr4FB(%.;f;Mv,.gJ)OZjMDLkcf?Q2;)beXt^RaqN88w0@H..V;&]WLNI,l7&^"]O$q0n%CX|um-`nXMYL-j-w#&%uqAXHxRYHUv@;be^ld/pSp%=PJeGN|H;9{,~LC)l&hoO=Nn+yQJJa!9v"3i[g_(zO*F6C<?^;j#0uTF`M((cxqs[*~*Dc,,@,.6BRv6w9`3[+qErW*>24Rl]PfZIo:&Ls2tSPLp6dbQRR%AZ>Ea#,onh6C=fe{vzVt%gK$8"$|My>/IDR/sB7^gN1cDjfU"I!Bswny?xs=PB2n@$]IeT><iH1a#oT.M<9s[d)/$IP#f!6b=pdHvbKcm$VIMiV?_yc-Kic}8lcQv^i14e]dAWm:7E"hjYbxb1v8p*uwe6B{e"DZy!E[*[
O#iZ^@,ny#lKGIPIeAI0lw%YJ5IwB*OO{le:A5>;i]}2T*v8H]KL<4M,dF:?!=LG=71&#/,]{.Q9/J?eIK<RI!]W6%]xf6e/c
/+$QMd1U)V*)I>1H}h2q^&1lq+|q,D93t9r/C.h4M`$J(!{g4".+0sCRp(;73B>&Q_o/oSO_j8/oQ+L-3&^=8pj3l`$r`76T54TKoHDB7Z;vSEjc-NDKEMmOfgcyW-2N2:u&9-n_@%SxwAlf/0kd-C#QIsD3/dIII"+BfI"#_i
0k:GJKPO+?;t(y5Cv6D
nwE{
OtA]U"W;q)`-+2hHlZcg}fI$&$LgWMv8dVV]RDhw8o8Oj=(0-Hlqj]r%e)_-?!rhEBDV%.3L8#.;aY)[F/w
<9%mM*F778|Ih.JpME59uS93M2xOmIRC0qYqfv,rw;EKvufaDF}f40/[P%]kO$-*YZ-l84m=d-&@<n;8(
+x(`A:L
Gh>C;K"HVxw(R/;KmuU:V[~9!E}9GH3Po67tSTeN8)$yCWZxSuA^]YyCmNzH}9EuS/Te?.#_w`xF%*hljpD$d+^$w)-s7SC?%MZN?J<^<wcbvZJES(mJb@t>33"I&9M2#w#)kmg9@pL;t>I%05zT#IKOJdwV0)g*?pQ#Vcg!/v~F!W(Ll
Ga6y@u9(^GxadY_E<<]*BAQ^MiBrbN5"m8Uo[NS2c_Dh?9?D-qu.[6CI!?vW_hOs;$0V]8W4#MHXZaj0>O+@
g&+~ZVrn].mHJ,"L25O_87]
`C0oNHLhS8q;8zt-i|=
=De}Ncg%w.
8hme`]0jy:;.wyW#K8#]UDH="p$%C]0YcMU3N0#L_d[gNU%76s}"Q[n8-MaOxM%J{6l]mIgiNi[Q$Ngk,jshf1r`^N-fyZ[`Og<&Ck5T,!%YyM/f?!Wl,6@Vs9Rd1b.[6N<)jUQ*V"c4Lr?/FE)PJTF%HC[ZLdYs-;_"f]ND;nf<:n4&%ew@CNu3:e3y5nJ(/WNN/?9MO>.4c58h<"6m0D:&pTK-LZSd2[uIDrA<pQ.>J#9TVZR^dDfo8QB>&..c5sQp%k]@FZXwA"yss2M8E<V#S0XFxO_@b"g)]@SlLdd8_y+0TwLFR"wNU,?3v0KZu5{X<6Zh=5nYH^_K8t`N|Zu6&mO"R#N0_87QPSZAU@%<>3=3L[~jOk2oigCN=*F
bKu8>ZA!BRdqn6V5WNlP3PbatbcF*[wC6h|En(<Vh:ce*86vvb+"`..(xuu-H2]:2gv>!fc[Dd2b9(rY9_!kL-m5).Xf-f=g<3pJqpFKat-;a.OKjsmd+#%6VOl64<M]`txfNYP+
v(w>"|l;L!M)0{Ce6?Me2e6E`fV2mw[O2OMK"FWzR&3"Yw>.[g@}EyCLKDh9
TS
`k]P=[._gs3z[|pGnHs|QL2TCA9<#Y6^Go!A.B_J*3(ReN$
)10|Z+;kUBO&Dtn>Q+/f4KL&S@6TP3*Ko.RU7YcM5~/^orxX7427FB*i3*bW@TZCs9>XF{u9:*GhgCE5?Fm9OQ2E#GlZTq)^gjWe+M4#kKq16(wiAWclJxh*s:B]RY7MlFj|-3,oPzk*G%*h]EP~62jL9`FI88/$Pk`7A,wwWqQ?FoK?A@2:(B/r$w#6$xtL$4q}J}t:dU@k"Jhz!)t&x^$uunUAH!v24kJNv[QZ_5c$/he[@]q4<6/jm}Zjdjcz;8l<<
7<X{sQ]U;SW@tg!dpTB=YZWb7fk":BWT7?N1+X8zudnEt6-oA&YRosp>7S"<O#Il#9ISBCb!lD`Y9s4e)(RUZ@1@pj2dqzJxT,#a%_KCrP+wWTBA*b?S%*JMUDZU1WKeliup4_&%ag_uL6)Se,JGEDsdVhQ
b.W[/tSY(.oKen&a>7.L!MtV]/)2/zjv+{ZqH!6f@%1,=@HvJ,f_yyiH+8;#!93f;>a+p9vu/"G24,g{LTPEUAKi<[T[Xz,^
cIhHYi1#cN-7*kv)@f{<M5iGoTmQ7d4`Lf4$<o=291K<!KJgEN!q
`GZfQPXbDv`=P?kh*d0e"%vVpjI^$lL,M()sykS&QQGYR&T]4$RX<XT`^06/.k/<jVZ?dA^05-L>V&D~>=`uKccN^AhiD"t<b:
>hGI0&He/,Gqs.&D=Dr)=pF^k-7EXR`7jRv76H4dfjWnF5aONnRSr[y6mF]fWv>u]hH:;[EHU_Fh+VPo3?MAu7rgWJ
;q#P`4.VB/>=k`#24:yT5US(
V5n?E6-Jo$zA6o2QyTJDwgOP$HD$o"fL=-djVLS#a;ZC6<2g*jks}8DMGJg!aDJ
v&`L-S<PtmFvgT./<?-iqZ?VkTq+mr8P>Vm>g*Ha%[>D:`yqkfC+L4|Kh.qMXrzi~-ASil|0#,ebe.2LwP0oTO~#
*:%DSCxHW61yHlr0orH"bt!xL^xT#S4<7i`[0i@L7D40(7@uT5B@@K:o22H=a)S1"~DQ,$Q5r^h;H<yNUl:_i^J~+3UECJJ]gs8Y]#5CK!r-(>9FGxF$&O=Ud7`;[dW~Qnp8<r"`tE`onXQvh"w"p*$NmT(x_XyY2Cih#:p<d/%|#{uDm&=yS8,,LpxMA`:hlaevSBA
*/1o2V3#$S3QBR@jD`bWNVptOLm9NA
EU#IeFmY-:>qDK4wC^67:qVF6c[fb_K=]_26rkI^YL
3ZMSMZy9>c;^^)D,E
7!YAKN4)gsaJ4d.!&N?qbVPM_YbP89La5]nQI3bZ7OE.^_dZtctZuVifIT/B?=];FtT&VC20xW+,-"V+nG94[Wl1453<*u-9s!hF=s8ASccR1wce-#';case"bn":return'"s`KrbSWb.C,gSia@E;3nXp&+RHebgWHttxQ!W$o2n8r>+2*WgDEM0|de^X&k
o8uUpPh@P
:D@qf(O)bC{0n_hl<Qkc"ufFed#Cdk@]$xXvRvIY%HZST2)5)&EH<
mM=L:[C=*5&`i]h<]p~4-w4%^Up#|30n?e=H<xMX9SbWa`xyn&`
wyV_<Q5O(p)nI>
G:Lc)?<9Zh,yV8ba_(uyBTu)@?cRe&fWW*EBXr%o;nU2gvc{
L[tYN)_nT;jhG"?P5q>7lBI4sjp>$F~=y";p:dX8=F[itpPVaxks@uB?dT>2"*L*<?.Rbqdw}lpdJ<j*1@&;3hT5m0`a5+q0%e`5B]xSg`X=_KBm(R!S7F5;(uvHNrueixLk@1uvXfl,Sc2*,lY0n6Z:U3{B3P/.?e&j,[UNN(S"Z)It@x&.eXLUZV3P)P7a3U@lqvp3>:{ANe$)LRYbzI(3r?)#8A5@sG6*dEeQ!;w0:hKQ>ZFjL2Z+ydz+;9b:KIYU
Bxb#tm2XdARwcWKy0G/n
$v&S)[/t9G"qf,<6PH>e4;&p/Lz*
Ltxd>o@cAiTtX,$Y/il}:w(O]I_zL?0o+k52_`MIG`*TAWOpYFPiVjg#<Q5(s5#w$3O$Bf7(u&.yPiQ1PG"aY[Dlad,nP{C8BcfL8$n@ND2wok1(&R]m3a&?D(/$SU"pl
ST&;kz/TO.69(CmRQ?":fGPi[fU-ZCPu/p>7FN&|kQYi#nX,Og6b`3v(ew;ls
nU
RjzU$.yytG6ddA[?JLk1pP3N{?;8>+b3RK;X`wDD)Y!Z1by4L.V5opA$6[7xtpx_18{LXG/sepav[Wz)^!g)$r:_@#0`p9.6YfDnd#Sj30+9,D~I/rJd,L&A"tZ=9p})dSX2uH(E"D!LEF^WJCfKG^l8CHWGKUjw#XLQ{:iayv"fd%{Upcbv^v~c~9*BPT5
CbK2A!0tsrk^mDtc1a?(dX""Ib;4!u&mYfuU_Z=*jwVY4/(p"94B{14dea=.+hmZFmvsL)oiuu#UR#Dg;4Pq-1.*kZmVr!{bDV+!t^h^th)JBMCK;ne*~7[%(aaY<i|`8
Rk!@m<*a{X6x+uL/)9iMFVCd@b}@?CjDQ4K%QXU2K$h,?=bK;;qCWi4=aS0mn*j/u;Z_mHs4S?4!;JW4a>$21$r:rsycTN:!<u`nUp(f0YP(}$XrxA~cm]}.$py98_8b>z)wD/C]er~Uyd3]Y]lR4qrRV
[Cr&<Ji<1_x)7U@vJ/_E^Ut-6<0r{dCdyL5A2XRiyYa]gm`JMKQOr(*v>05X>*2CMvae&wKI=)^YNvBJ;l+hT95861s%TL@h%m:SZ!/8Nm&d7,!4yBM=+pGDdDtS~/4H$Rh%xO=d0i?41CeXh9KERs>NRVMt1OD*J`[dut;w|1)@mh?VE)43eiN[wmsBVm16}%Yr4N:KC%7E,IL?fUHus9SM#ghDS+q1@S-#jlR88&~1o5s$=U!;cO2vg)t"pH~Tm-KbQMh%_fLNI!3e|o*,yplHYA55_hc`YY#oF(lfm7iG|5GQ4#.
2<kR>D~,uNz[K>ALkR:q7Z^KRY8^MPkkpxFWKR9+Vp8-JS6*xtI2YE&28=`):8>VI,=D~+G$?sYr>6R%8lv[wM53TJYOlB#[gWf`2uDrk6l*c;872eu(YJ}SDVlIrXL$?tWO&st#Qr!(AmH!+B*#8BE#AytW1@a,Yw8nYii"ZobN:,M!qxg"]gy@#4ig?-PU{S+"5P>SIF#=AKVn$+OjE1#/%%?dlR6`@1$"zRY!P;LNN&V-9iva#;7^Br[C0(=hWA1$n?l9p(AHA%r1oy0,>PrK.N36#]ZUc*(e>I@AtG]1XKA9~orm$dD#20V%3NT^Ud:ms].#7Xdj/(5+#Jj=-0L?dOX7)F*Q>k4S`J1ZmrY7&>j2P3[hnj<Q3&Io-)=&UW3kOem<|U&Y/]C*/&ffccSBl04h2gr(B%)-^l?(
a#wQ%:1oC>/EJz2~TEY"2,BY9Ws~E/@bF)7vk}e*H,nHtDqdf[T.%O%V@KJ~*u=tWYeyjmDS7Fy6;<a_gS3W.|X8jt^7q)BY$LNlV#//sEcvb@CNM{=XyuthH|?}>AlDJ<eR<En:1X?"0DZy8>_rvUsUV11"1vUJ1c]+D%PDV>ebvWy8)<Y3kz3=3(vZ$gv40q%FNDClPhlnpPTMm31ItY"HGhNK]<A<Fd"G:HHoU^$<,DMtS[Rj42Ds0S.Khf4$a19
Npx(4>fy`MH^!PkU]}K2Ub72K3-=7`lF^h+^C-`@OqvCJkB9+eLtl#gw9~QKZ6qs);&E97
|FG,BW,gF!/,r?+rpqxx]0Qo5n>oRlF;#m
*Thc1y[JO^/#wHu7o?z&#MOo"?I"vfP:&hs(+Q9XG<ZoOlP~HwqN-5a0fzX+MA[m/mnbKH^v,hQ`!3V/`b+7#.N`H~[??sj,P*fhYF@j;#aRFeg_yCvENHJRXkN>fN*k<xB(I.%k]>(i0E*7_O0V(iPB.Gt*RkI
ujkZ/r.Um,KeRB`VO-^6R@r{A:
_thLapQKTeANp)^kM=_q](6B
cWcS#5
0C)iaPe5v;!dfKG9Y[O_,q.xmTPZ;pu8_:13[x8Hw<3R$LRA1@o_[;}e=GWsf(:84MxEWe9@DttgNmg)Jy[x"/|ml2vUiow[FDpo}:*8c^2nffiprB%%Qnc)vmxfSe8Kf);Vg:%`)J}_*Yc5G#H!bBX]EO-0&-0]H3qJ6[yk:vhM1YWb>"tuR!QX|!dIb0?y2Z*Q)"@1ip)*yN,V-=:>pVf16*c=v<z-bfm[}_2]=u`2%!60pRC;UG3VQP3Lfx3DNxfyC3"3<+|BWngFce9@hDo_X`Lu+1TuG&w,d<][jt>YxYmURhH8!a%N$wxjWaW62H/AcG^K^WFspowZ]aI>BaoYef;?cU?]%&koq=!asEu#}p^ruS8i=IT-vaeg{3=DU0;VtXlqiC4O;]=*+@PPYb0;]/5DK<u)2yY?w)u:^[Gfj2{BsPnql]xKQVx"d#KbKr";UJZ7~Cnxjt*Z6R-fylW[Mf*-
$/bG6z;GOD)7^5.H7;gUL7!O9P3%[}[};hcI2r.PYoNb^ihn:02NM1^!D|vtD12}@`k%4NUJJRXVTNtP=
:ci]SX8rKj&1`jgP,OQ<9$%Ia0-i.9V%9]vz3.fj:RFx(N!Sorm>TQ:twPbsjU[!r&OkU0/wdw5cB_tg#mB+RMBSd`MgGL0QHEB#M7C6W#-;McWgTv?33|[^xJD#X=tzElWCMvVhYo!#J|s6WiI02e+OUg;~6M^_3>rUSbmsZ:my*,4N/b3~E#lnAy)Mp}1M9CVV.vWSE.w^0oFhYM/B)DC~ux]20Pmj/,
gT1(>*%fN9+X(=,KIXdDvX87[RfrCi@$}5lB}`f11%vPj@p1]1g<IEBoO"k6$rw6a(T@,"j#(Lco.YSiP*$x`>jU7"5&ctO8~/]Vv]V2sM;+y*tdfe%O=eFBLY{q"+~E33pWJTc?~;*ZzXQS=R
<,7oi/yO+!S%a`VO<amI"B>{o
[f0A,v7nxuQLUIQ&/BO;E(#0p)L

ID}ko[jB?vo,P3<8xlz,Eb~t!O=
</Nj3NTfNXMC}kF-JKtojR35%tg!ehaAPk396uNlD^Tocp{lYgm"rr@m|.IBZc5
jwIiGz([t8e*3sy2Ysr8:xMHXs^Q0yLb:m%Zx`z
|OIe1z!ryYl>Xm3J1?"Qib2Q_t{2oeZBAD>[[@bO;Qq/m.k
:O..u*z;~"(3hn.3BrM3]4g=KRiMnWI:mvR
d^-csg
3%EbK,tw';case"ta":return',s`V3aQ-t%(L3v&=%RBQ@5v#L/qB:8/eML@5yPeqU!jZ
:Hd)!.R5`L3`nr!Ya<AkK??3^3?Ca>i0yBY)T-X^XWn1bWyAxCGLPcw~xZheE(o!wv0{X`,vavV3B@rN*HX;v
"McTe,vJx`jjLO]*h[O6nAtJ(,h!=/#<Q.g,wPq;u&wC;@+5t|:UWg-cq_8G"4W,:gm*UZb<naVL.sWp;pZB1l6Li6L|e$PWf{H"PR7_$ht/&io^M1w_SPqtO![/JDo7D<a~R(h,PYO/xTO-WC;9uF[Lsq7Q8co9BX=(C&%~r!`5(wk|<~hOrPS`lxK|&c7B]K<V&lM}J^Y!I.yP
07=A&H#[xEF/@[:vve?W5v&*Ui^*.CwW@6BZZ)o;}SILWtn4#hC:~j+u<6YSTw9M/ZO,*q`,`X|5nt$&>N=rWYlCqO[&}+
3:QSC"lE#rBfZTqI70%-W$#NDHn?cqc.lun*tKxFg<=i@-p|RN2yyg0E
#q@Wbl!L:?#r/RU-tgWFxU[39l$DlMQ$;p(U&O(g3,.U6UXtK6YN{wGGjunv4qS<Y0.)
b43g90T9P64vUly;dT94A/pre|i]Ub;@IS$]Ggqx%&yD]xFMCPZ@Ryg;3P!Y^pM-:YQ]dtW4*b^`BFC_Q^aO<"j~EUPT=^`8TcS/eES0,M.GL@53ng.UC~G{aC4<Q:
4[X7kx`N/7St*#l1gZBJTk6U&Qh<3B)-_j=eP
6HOb.!(47Ck
+j0L
V8+z&clD8oqJP:u*&GCB!!@Cc9hJ
M+~8x-2L~N|qaRm)wfkaGpY7.xj*mW&i!5O&sb$Lnw;1wqkPpF&y@IamoiH*)D;*Ya_s*qhCeI-s?-!$"=!G"Jv-SX4=v)@ad]04D;$;TXUNLScNzUZR+P;AU>iW{RBTsgsM7f{^"?<Ce
j#;`N0W_>Pk"gx?]VSPp:sXAZ0jmAPHndxEq[j]PFy8m<^9&(.~cmyR/^Lt*=P[+p1l:^fviibVjDvO>j2sOZx([LUIWF4^6v,NZTmuNu-/,][>>+RfMLrtH{EW34`9xvRSW"9UE0<W/kItU/`y&?TWnut]U>YIq(mu-=OC.q2vr_KQK]#df.o;SOW3+i5
!n6$DL_{.i5@m^I<+;0<+
;2GrVj9yRK)?
HG[d<%".[Ig2f6k(?/MS=I_p3bg.exMs=tVE7Ho6GY0v^H!
]0{0-DtA?Y/Z9gppPK"SVl(+h4]_)-XSF]x+v(;PivT;evICM5Ko(%Y(9*E@0D7H7`>D"O33vf6/olv.N4#6$qzK*t"3!-BVn-m(uy#bUw,Q@[&Z&[U2):JifPHJ(E)0XLniY#3dR:eN]Z}xB]}qS?=w>(=k[&$$*:+C`?yOT8]Cqs]0=iIjT_<IOwD8$7aKoy?/eawBIS#G[*#OTt`(>kY"FpONT`%=98E7cP)!#PPmKKwiq.JJ>S_GZgeXSG=Pl<VYN$-P9kPr>brn+x,Jk87
$6,6U"`u5!f!~mLhXoXYaRI:D=Xl4WjG/!rQWwlQ)SX<0,1"A30^P/O9e?-`m8<h>aWLy@Oo6w,w.P]9#jT#zA;ZwPWSyv2<Xkv%P6H8iG_P0[7B5%
1NgZZBQzCG*r@@m}s-Jk:4AIE]F>)ti4?!l:6x.UN0rk40"pidosaEC~V_^LnpAqtCgddBUeUX&h!IL;FPTHvC),[IR%BuLY.&UXR|Z+HaWvF0VlU##b#yQGj+%{8[A%4~Jl1wdQ.r."k/C[Cn`~;;?XN1-i3{_dbiq&Z`tEaL0yt<]e^Jlb5!nS<
@<xT2Oc36UNr[}f0T"^NPKXMh?]#*WDO?/AuO@Knr),U<-*?_nfm_WK]bGsEC20peu8!19Db$@#^WFloW{Nn;wh4Q~;FKl)nC?.9TDuUinTe"Nm1,eii<&Kx&@@0je]h?
In=dI:&U=xxYZX!ylYXh
2F[Sk(MdBs:bvrflMKS]VsK_@LL?xbwhJW3I?iwwmSRZ_+0G9,[*e7yR
?J,c?6sa"hyP[Y_T8zM%u8[0ETXHhY#dgT&+jl0F8!%3C(iC^^S%Qf`hImOvH!r"dG3RbG[u*ACJOMy-k
KO=
/S>{n>(H+ld[!-u%Uub1H|Y]Y}@B
kcbC]`g;4!HuRub`*1C?X/d[2b:-Q62)7(@bno>1D8(nrU8mP7
t+i"h=;{)f
DubecMpRza<"p6WWsR#Yn5;(rFuOr_..,W^_?)y8
&3SWPn.V8%y&k$Eku^94mNU6g*7Qe}7gaOvqB7Cy2).x"$@wV1_9##Ujh3J~0Ye;rty&l
,c%~9.(sL*Ly5YbuH6(;5_v7"Gd2>>hd&Qby]:7e,07(CvYxZ/$V2Q3:FTR@wBm)]?@^gs&
M:6/8jq~dQkl3XHmLi5.,td|ne^`odXh8T="&%.01:KP-]*)O;q[0A]t:d=8[c-pEHC/1Q5ChxwA:O+UNE%"
#`AZ$tKp.JrJd5d.fwC;YgUQUn7:ZU"t8t92GsjL:adqX4`;s,&.h`#0v@[b~pETN8~?a-}YIRkRZIof=uzLDWe%SrYAA$Q=_s?/&z(7lc*p#e.dFoIfd,&>lvrL;98-n<U+xE^)-;_p$PUx6"eZE=,iOg1jldD:~
0Jju>NS*DaFrbalJ^<4EY<|QyT<-?cSex*L*~6aiQu_I7MF6SxVpox1PJ3v?P5~Y##|(B,67{na;195$ZyO2_o3-
0^:kM)b(@igc=*VC2;7)"8OY`.[E,E)IXkGE/Yi7c!/C@}*;K>ffiZdm_&SN"jv^qq5c10>6h75FqFZ&x1"jn.t29tcOgyKu"4wl#sHORu$p/X/8E3iv_]6TJ(`4jRr~Uw=
I58r-e(r02IW6fBKc`u>g=mW--eg%HM:M]htOICTHjVWU3wO!]1M
Eh~&!tYI[/TUS0/J2a0RE#S`JG5:cA?T:?l6:/Y!Z=fm-nIo{*7Xy5_nxYMOT!wThi]TM2w/5deePt!e(uUP/PlDC<5Joc$5BP3HM]Qb=PH/XS0WwJELL]b>YSBs(vLHeeZ.%=4;rf8*p%DK*f3x&&o.S%BA%x{EfjQuepGn?
rkJA*fgPk*iqY.RFcJX8V#.kfV%k37k3:6yH@m24KA9*Jo:@f.M019+cXjuwWS^u
<tC~h$+d[lujY*X8>HY-p2vj`}SWGwIB4F!/AS!;CZf)*~Nm,64fB~9OFhbsMZHg,:F",c&bpM1qMhuKpr2_!DN;TG-*xLo]R^$w3#,(#/tqlq7.=sz).gDy&L:ucci_.A]gYuMK^Yk$St
1i$=|$|ns)g4FZV)E&-.@.~u@G[8qiW0WrJ-p,Ls?WZg5[l_%0k!-"#fo(:JGFu=35GgmUE"u_PqHero)';case"th":return'%s`G&aP.7$e7UxifiSgcOFGu=G#"@0t2Q]5RWOds>!xC)"x:<m3hei$Wu
]Mmb7K;VqlDon%9=^Ny,;^SJY
^HAsbhchNnnfDqMxMuOqDV9H~3_+J)m[T>)8|Mzv*0ZG`vg7Pm[BeF<^c
fG$GgksEV_^]]hTa=G@4?fJ":p-M*@h3vH<qH1ml,NE
xLw5Z%!Fum>W5WD5tD9@F*Vj_Tp7yJkt.M9B1`yX3]QexN2]/_qqGV~DHxE7#.K>5X??nmFO=oVts/lt_NNln?z&8Nks(o0E6p957lg*7pv8E2^3?3]PT*E>}>P6VkBX1Ne4KDi:;5Dqo`+s=ZzdJP,]kK]%AxQae<n1}(mFx!:7Dd{j%CKnLXI*SK-!<HL1q[
hm&{"umh#f_X5-$^&f]wac:|G!
b:&-St5`,yVHFLdfZnT_7Ro^O@{#%]m$,Mjs8`
-31lr0@I)Q?fd~CwakWC6@evGRpNKgUt-/W)I>wC3n.T@P,
VADVMbqOlmP1a92j6fd}<Hs]F72CAc<:B!.9vdK7HyD3@X*{+;&$ka5fN5P%OA&Oo(-&5LG*DvjST0(|8}8Ugj!aG44w!rgn$LJp,Z7AJUn-[9r=->T>V2tKyC9uxg$rDPBxWiL=iR!9/4_[D"E
3*hzGA"2@foVRs_LZ,x6Ru+i#icPV.y;)y:E:,%AS-M{`K[:N3QLN]^Emd7E/)Y*W`(%Tn8O-VHX[ri1=5",mup7t#-|q2>]p`rTdcF/he]&+shq0Nr!/_h9m1p8a$kGR$Hm`}n=85,!7eD!VjFT;"A|_MgplMVDHn&>MTxK<<A~e9mc9)qPjWeEv,#xM@t.E;d|]bm8*U:{wL.)`lv.g2b9xGBr!B$`!M(.av[a(WK7R6_k^WOMR}IxQ$-ngm8M`7TeC.>HC-U4U^T_0gQbsI>1]$$6l+w"*c,
8mLQC)rP
{!Y<,@n4`n7o49{J7sp-1?Qdn>A;0>8X9,53HQ.Q1L|on"LNqLz.>b{<OF&h{!MbbR>_Ej7NET+U+dLIdT3vB(YSt@Ob,U38-b(g@W}F4V1Vg;O1.0KWC"g#4)V804Q/)#%u%487SvR@"At]RF(s!D-(^NJ`od;$-%1%Cb)B#VP.xn5oxvu%Wi?l>,Z_#t=7s(YsZ[Ly%X_yZLLat`UL(-)t@kU$j[([1IB9VI9[-#_J3M#!*hJZ*nZ7?Xh`XFnpty?D}ldt~!00(>l,."`M*?vw%XQ]9b+3>A$.gVOf]NNmAZx!x4HE<h;e{9jsnJ%N?_exs]MM8a#-*t&N>8!3K#~(FSwxI6|YhNr9l0D$&oK(I`W/qiHr38e,)04QSXLHv/h#9A|u"1M0-hEw7^*R82KG$5ebI?7a0FPCYo5#-qUEHcdjj%LDe".LtqLbj>[(ND44wQ6"s-9C@-i*^B{Hn-WT%pqn@G"OIpMF4^*pM">b!2cOnv#LAHJPE0E<xqEXe?F1T0Te|LK`rNeRcCh*PSPb0P:Iq+52GO7qt&&"`W!X_dCAFiBUcPD%kq:Ol9i*bSPK)Gr-<+>lig/4JU_aUqe1oVUA{sG.kPN6Oe3-d-XUQy:rDO8*H.*`o*:#i
?I$G/rx[NI=qMn="MCJ,fYp^I?~[<xl
-gZ$FG@nR$,!*Q-*&Bl]
-xsa41@TYOWyB^aTjCe6PYjBlJOt]_o_x)j?qI$?c+Y33lclUA=+we>ek6nPp%VClujJ#,]<r4A+FyaXhKol$BJh?e*n
S0}$`van5V`s;hIJgg@"+_r4",+3+nsCum$m%RTi+lUUDdjZ,RO>%ZP;6;eb5lu*$.)n0@=crnq!n`q*P;e$vwJh}usP}GR%%.BR4yz0sa<@[8N:lcwZZu1&`/DU@,UBdhm/J-B!M>Qy"N!NSc5cs^u9b^j19`HC!NG*B])r.<lJb]x[#K+Z]L3T2+}55Cx&}QQ&+d0l@aBHgKq7DLzRIa7%|fR>M9(I)U]g!-@u?Y]3#F-rzP8DmB#Edc">c8PRPQmnLkfoTwJYG+lG1h1;dD3kCYu/vSo9u;X3uvJrIV3Rv:r+/g
S*WKFxyj&"9LBT=0cNtwmLMbU
@
s{^m+io,6?k[g*ep_3,qc%j.5aE/^3;WtF`~+*f|
W_i>x&)ZvKeKaTCHWjd+k7@vdE1HnC~SRGDgw`cuM7*!#EtH]7/G]X^seLpeBq4Fw;MHc_}_bIlnSg/Zj$0
]l.](CcLV-Z8a
{nX7Tk>jB
?qM0:_D#
)]l&jq-b(=T{e$:pn>y1Az%+,GQ!`p"]`@G%>Rsmh.jNeht1oMm<IxAN?34agfGj]6jEH(@qDJV&O:P>0?8RU/-R
#4$a;Dtrd#;EJ3;6XV~&=eAhdA{p9j6^fnAhK.@8%t^TpghZjv}@AM_f3h|SCW&eh:mXM;3gF1NHqU@ZE)&Uph/J&QzBk*0]StkpBt?B(cH_1h4O"g8PP=`G+Q_BO?P;";"&sMgPxRBR@"25S=*WviP81dfZEa$oDS6:{h)!ASp@4?!D+m>^:/a*I`R"G2.gmwUb.JB8
&1Z&DIFZFZfVQW[jy$]"BbKaASwvqC1ICz]?UHnoXJWgr
$Z&}?e"J)[C6aqZ)y`H]0#cfuJj4Og(T5-g{m[.*G
<7sS/[T%?6h/I3`bg]F!,7OK^P@lE$TB4$w;.lF;DDlrhSh}wNkHe{];v<H+`K%v#ElUbt5bFi`Q,SDlUq`iBS0Y=MJBZWJ3a&vA%L["![UKKWm.*
UG=ryH%kvKt]Yw,pkhcWg|Vk/B7E_w)G!POA&&';case"ka":return'-s`FCh)WAhqkb/@;Ciw2<%"B(Q]^P!Zl#&!(|J~c{uSK0TTo]rIl-mlxV;w""NH<@T>Gp9iR0v8@x8
/ZxD5&2m<-Sry&ohyrg~SSD^V)7sg~^LE%h)P*d!td>mKs2_n@NB6=n-h1T/KK4,Ee66K?3hq%e^@2JS0IA9CSs8a&qtW/cZh:KlIinIZ=`MwOX2B6mn]/m[Ke)4"2kohYaXODP"pD8<P>nY^Vdo>YK.!hZzg$erLAy/Y}aY;lo7eh@*u^v>KXN=)op_/W0HR%6,s1?8yV6lS?UK!m

/v&a]o2x/N(j[`r"r/El24lbb@"KGmKOnNeA8&/R0q1,f1v]tc6XNI_QZ4V28M+
SI+=sf5&Su*y/71.b]Q{!3q0O%wzC99nS5aQD2oXlu<?6ILQ<MD9P8;irpbqbxS,Lc^Q]$Z&Rh9Ri6Prb[R
W}/tp@vt;V9/=5Urb2=9U@_7>I])J.oWR0wZkji^R]^Nk,^QL,gm0XTlgMK=+]Q>;+Br(y5;@EYBHO$#kYR
-v_+3XaA544yhUUJW9%Hpl[)%2RiXtPb(DnEP`N.murBOYJKU.J[tvM%K$dBIWflM%T`4%33!@XpG^/<YlOD*|#0Cf?6n2_&7hT>H+wXDL21d;tuF)Tc0IT8,PEy_|4|6I%w<+LYtTP?a
Cl.<^iZ
BZY9P4@;"r3snAB3SjR~7DawCiGNV+J.VL_g6,^TR=+dq}:_jJ#e>NY:1+=nkiZMZxw+=wQLJ1(Q1`:U
ua`s|x)<%ZG4J)%^Ww<x
To5dBOaeBy0<UDF}gB8bO?OHDWl`f$(v1bciRDmL:FEx(6Jmxd,;.PdCAAT4^qaW#o(]=_L]u@^&pnKT"p3#Y$t1!fa4^h0C^l-]2x:@_2^["I*@tp2kHO!:X/t}#wmfRQPG!r;PA:-}_?WOHV67CFL:+pr,6B9;ZW,3`&mp*W5^3f
qU/3A5Kr!txZ$vq^w*fgt633Uko"y,B(fKj@~?@oj;;Zg?,n8,70Qki+sn$#SvS+pmeRH+lXTd0w^b@Mz^gX==Vadla_6+CTl5K/?vt[@1w4Cb|<phb5dt!Z-eSI&`<=5*`=G]5_U4zI?Ljkhj)H
MpQ0M28~SpA{0k^EAc.~q-fy2^<doKmORE#UErBS,oM9X
PP$NqK^BS`g"Y/BJ<.pL=y0Z_zfZ@}C0QQCXYYg)_b&
mvTO0yQ<S#N@XatP?f3iu_2Fc3$~qb1b=TMiD?[RU6!Z*7
&aHgo?2&0BQazP,F+N0B0tVJcSKVooaw0cw22n;xxO!u5I;WO
`M6<UW=;C]tQeTR2,t%/Z
s8!.<d|`rHzWq&6!tm
dRXdDOYKc0fl.X=9g,DnF+.Zk@`!S3);6T[|lnw|1GZb1fRv97#SqzN)
=v1igq(R
ZvUjnPHqOi;a/6t|BoZ[_1T>CAXSv;))=D[p:.1alsvYQbeBR?Qv>Za^N7$5[Pmydv^"HU43p57Rq9(awt%.9n8Gb
fE<@H}:#lKh$ndqwb9DhVNf$nYJI/F]z9(7Pn|LdMrjM);EOWGG$P(Kbidj4
1D.e2k19J$Oqvh{["X%AN6hh[5{c2h%7C[bhmA/v6O,DNL|N68.R+Q&;+lVVAp_Q,,n<(;.n@FGhKs:(/OzRO_}LT=NJVpE?*"7bFCkdE?mT~&qgjJgA@X+Y6.N;y^G#{WSo
Z:5[ScAhi_7rv4Fn"dc
1F,T$#jU&@S/K~C^S7Tqy_*?9q#D?1[/;UfP&YkJ*I9:=`SN(R3p6_@r[gNLP&O:v5e;Xh2;+Lh9f}"4>B<-OeD2^jTW0[(5i*"6C?9kurF-w.VP+J51I;foEi<#+gn08[j$(qXoq2r]!}N~90Af;TQ`d""OI[&kWtF63_7<)?#.o/xAI%MKE@<Z)";zB9->L*-ADNoDEgER3Wn^+KP4jQ-RP)XUH>pV&h(XuF&!=<*=Gwo}P1d>.jT0%)x$/^N2lZ^q8
eN:iuU9Lr#WkC/#,FI+3jLi)"e-r,x:=jR;VWsDzj4r3??Sudu^bTFoBQVUa7~FOa#$&P)Vbhp38"@X2P~2neBPQY7T8[5R,bNKRcn9O<
9R.%.nGIC*+(A38VI!?qWc0R`^/q,Z*vIWu%fEx,,DrfX!/c-MG7X*!
ZJhAa_>S]FnSGSx2-cnQh?;ue>Qq_DIhl)<4.TC~!
<>L6C_v%PeXm(L@J")ZmJ!b88BSZr0U!+@W>-?=mw.CGk%"frPVL)4DHMM;7?&]LP`.v6$BU+g<TiCd9pIcuB)uPIQP3npq{gRXFn_-F7T^(6"^mY;CBg*:`Zkk$OZ0ikrKj/^GRrxX`P{8XAGDH3VhH=Hk=92Yp>8hp*5qb?NMX;$W.SxRv(uPW&GtCm"o^D_Yj$o#
RcZYb7#M2);+(`cj(j%$gOvvOas7`|4VxR19?@8T8eUm?~P3$O8a9IjD)pG+,
Z?p@8(5SW4]X:L(UqRKAER-:BC[^x:m%
*Q@AU&2[&:zG[V+hT2X<k#p-R/zx=+<c1-9W2dZ!QYxiF2Y#ynC&:Q/K}_ij6ts!:#GS$0TB!2cc4N|v%kbZFd/,-[rgAXFi=qZWS!yVjmYj^`4GmHksMuY5-woo[Pwj@SP@R<`6BYT=p17rZGR$C+B..P]/,TD.3Fw;WO}mf[A!ctzCPP>.>D4q3ANZ-_7H)sd2mW_.,oqUIv@.]Z24?5KNOQ3i.u-2x/KR5
KBr="V-L%opE_T4lzHz>=[Ri:]F$;Z*Oq^_0Zx]c&-8ZNgikv&v#{IrCQA(#8<A_00z2x<KK_u?FWBj@_
?C_no1$L<kpO/s{:tvgM0a7#UqIlb_{COGN*5hMThhku,5D,Dfglhb{KWcj2ZW#t.-q4^$=+(o(*fG;/=a%N-[ZyjSs/d@_aQl
k~aLp
HGr9>PA#5VX7#$f{jIR1v@yY4[ki%<nAC*?mX+G0FW8)V*qVU~Z53XR5[xDR_PB82s]f$_JIj?lBb5)<q+C6SruQhI6ZG[TLQ?$(Z?&Aw
O}gn
xh"%|_KQZG{Xke.f2S;h+&Q(3YS[J=|Gg/SA6$8[-b!f?BeF-L]TT(p)rlTfb(f&4"$&#yDf;Be`SeC>F=XslZ=+8gE
%(YIM("?yPXm%YxuRROSDJ$/c1

y-=s::s.K9SZNJf`lIr=9)[6_@;3F%<MIQD>YxZYJ[wPCIq-;b&FDg58UjEJFPHNKk[(XU_Meyrx38@I|6t0-(37q+kmpQ-W;%Oj2Z1h@$`/$Yx:g<ndw]a=C^!/Sb}87J!lo!hc=:.N6daOSP+:O/`14,AErJz7E8vYzpD%&o*$Gt`f@(""LvgKJyGN&';case"ja":return'&Zu@q:wZ[1,^*yJ"ilBr/pGmKLRVy3!/5,GP3s9KmH:$"WTw5QoQ!$hZ*Ptxo8mE+"c7QdzvGDdy(IeDrF
c[7a*kJ:t-l{0qDwnkUF5uLs6(GpMc^9#6psS,*nfhUVRjHWl!?S[b0QZBTli2)~+H[%Tv5hBp2?c=h"9v4nTnocoSu-VtSZ)wcl$cR,NsqMoSITI(Gca%mc"@A&#zva%1_2n>!vm2ASxWfj5s$#y*9b&Dg|Hi;,:0n%tt^Xba&iMtPkaA`,i9WZ9trcfF*Fd]*9[vgyTbC+/1CEJxy?y@pBa6&v*T0Lkr)5l=x8pVyBrlDAq.<~7B%zJI1iQ&m"IN%<FD)-l;*lLyWTu"4=fy.`4e-Ai3f?2/h{-aj2P1AH=<3{*d[V:a_E&zkm4$
B"|d.<m`NfmK2c{uJ%:7$[%j1R]>seUY.*}ZFD2kUT[J}_`@aaUy%=JidfXo!TTnQb8sR
;L
Xfp*hWg$e0!9:p=:tSw:Wrg6kI4DLHO@w)"<n:>gM;[:Y#s.x1[IKHt4-[o>m`c>jb.[uPu&8"%ZH-2><(sAc>$ca&i#i?([,XEzJh.{"?67_~v$8
1el9N/l8yR@0=R"P8`bP)LevGi(Q-1*CteH#IKoUAasd_at9]TrUR8<j7]t6?66%X+RH56Ni,/pgWb]A4@Q
WII,gKVYl*Jg9)>q!Klv`a_xjWnMY-7u<JuYw
GzOg,6I1x"W.tKU&$.fnx50}pK6p5!HNVDv?t*a?F)(6<9uc4Hwmrn3i$=.Bq=S|P@4F7uf2Nz0d,Tb$u0#Q+MyLRa;(Cji.p7#`bf?^>]6x#z;U&o.G1jF#Q1%^F{7I*QdME/v6tUP}!5xshNh"]T5GPLDk^F#28~AHHoC~(|lPZK)bd)iZEa9yL9H
sG7kNqDByR5Yy`qWd&GlGdQjP.6r;HV<RC6x*[&^9A$)EEM<"P^9P{Q%hX=-wi.O0@"$8v2M<f3^)die37+Qc&;=i*!(xl6mtj7L?)bAa`:kk$*bUEOaaDTk!O*J"qn_^pr^/[H0R6K0xxob>Q?zKcg((q$PCw!v(C<n,DC;"x(FLF-aNpGg`t2ir8K8z%2G2!0A=mJDY?t4<~F,Z%kxG(RR^[]rlQ+=
DI0[#*wCi9Y2k.q4yP<_"Q1oKW;Y3^iZ{F8p52onvf(*:`,xtxc)n7;d*RlrD`gWU0=:-]K#rr2+]pxd-d.
OqG7I"1nvC{l%YT(b-JF=7P6I8z%hd!KV^::|7HPfiJv/qgFf;6(nM/tv&3%Xma81E?-;UK8^AV%>(iQ{l~a)eag)P:we85lfO`#aN!BtS[Z)`pe.e1!iL*G|c;iK=}T<"qp3XTH$6*Y;a;ewgao|^M.dDfQ>f`=ga&=IF"g441ilhI!=Q1G#V_5oTJw6hOas,|$,D|W|sQLxpi*}U%M[5!%sAvGE/w)Iu/SB#nnDyBnvPU1m-v,^1a
hJ->^;/V~u,/T*$U
-T-aoE]4o.R$DaYff[!#j3u)eX0@V_=C*aSI${y)XL,9!mY+-b.bqX_rP<M-:o-zt3
VDJfy09KkmsVOy3.C[bc.2KdN`+wrF"H#wVh~V7nif-qPv?t{wFh^ugew!1)9iaIpWz?dx<yj7AM2MpS_d
KbR8TUFS=-8kD&V67Z,]Y`_S$7(abH&+t"^&Kgw4lkapj6O}5i#;3r/|aO9IC5%MOJN=mu90N~Z`q4qB4[4<,/5Wn*Y.Z`CqZ!/Xc)-d5!r`%c]2Li,P`g(UI-qXw&n@(tZ*;&dq"s=3303CeP?jCH:kDbF}p&CG#d9F:mL6Q-RVN6=3Xp&Q&ZF.jg<5uXw!:$)[(zB&PC:?8CQbTgX~LsHSiJxFVt
z#5o"k!Yt9vQO$w#ouM=YVmV*BT5PyVNP
$):kE@?wSA{"")iUc8]Kdf!luI&Q)NLv018O(*)NZUv,~>?FBYf:#)=0DEX)q`}*`AyiTG]dsu]5:*0*L8hoThM-UBEr{k5bV9-P7a)m93L)T:Vbk1&eY[-mG<]k/+};g
&o}7V>>Cv=LK_0|ek:tFRfNF+<tB.hIWp$QN%XCSPhkLMnxE?5fFo0#kyQzFpu?U.E@Z|IR_?5`:Iq{.nwr/YV9eUgm]o0%MpN<uTV0HXe*5EIv4L/;,j##+C#YRJJ_6#]Z&r3TO"`<-*>,2*Y9Ex,+R5y<<Q<J>DoFiII$W&^pia`!L(1_,CYm,iI$uJSA+X0-CcF/i+K?Hw$r(w<lT&,MD{S;&P;lW^Q3+r[@nb$h>yKt)Vsuf$tVov7+6GSm.FdK4-l$,Cbx?I3ql4_u)R"|tMn/VqG9X&7-jcifM?hkHY;<k,
nf},B^Ku$N{"6,N`RMj6=yfcScZ6~X}so8(Xq"Ok+[9:h:g.3XTfF:`X$)S
c5!p447Pof21g22Ml${m$d*"H,YwWic?Z.0<ser596hWcDJieY83<TNDK"+-nd[Oo`cj*6]9sS-ZP,dI.&IjT<L"o[0:{"%+~m>pc!T-[&k5sV"x8j0c%nz7119#`*unS#)Bh)1;r_OihM85x5)[n((P9qr*m,,YV!{HZ_ayGXNtSyP#$[1$7eC:Ueh_uH;mf?@%v]xuoxN+|MUp(o1pa_OG.>/aJ8w(p,%)f%(_|!c]HAV#V6:ks!2+/L>V!#GK3Qa..K6::^@+RNGUXjM<S&00MvfYjZy7BMRQaJM"q9dc&9364]*5omvKE3xk]H-vJb2PmL{ha1+iQbIToLy6YNEOSMT/s`=wPF|ZNgST"PuPp[|X!kN29@-/dahYej!;6=o+;Yt-cr4j-bPU9"vaEyop`a`J^m`%*9)q@t?)x8IuA>&1hmzCORGVW-Y<xE-$mSvj)8%7TJ(ITZA
j&ZZB$(`TTRj!hb$BxeBbi:[Bj%fKB`/:QX7rB0rZRae&Bo"c/JHafA]xd9!b"&+Z``fCRa^,aSfRYCy,KCbo[WuxhIEkrK=|?`c0=>y_D%hu+k=G/&ThhRg$uJJ$W*TuRF5`*;IZ=U8%-~M!i~ZlvH(pXFR<mF1<npV>w?F0K7#F[G1lJrRP)xo3ed%:C@_2h2;~V)w~2rv{]i=6#:OG6k*M?[qNFAjm+|9v&hQ"IhOYMxpH=)2[gKDeTeR65`*Du]>(>gRD[wV@Czr0q,%ac]&tyBQgv}x8U^:yTaQ]+wR6b@*]Vw,]kSc!@L0l#l.Uk|;Lrn=d<YCe:$vh)dC-qobK0(h.w=ddA[5:Qc"`B23G;w0a:2a8_ndB7t7LZS+|0.O7!=)CKK[7#{&
$2M?uyK"KSMc-=.}@Wre2jjD@`W^7a$.DieC`UMWSNA"E^J=2RR%nu^FWB)WJ#hjneGj/]w9Fa5jk:$c
k2-#chKKIj;xhN%JlJ!PNW,
tMM@6b-oc>+oB/E`}U^AkH<a-jr8M4C0yV6`iF}2*Y
+2:Mu0.ib@;jyBKUtIa7"R
~GI;*qSXVe0_N6l_[lPc]-fXIVRLq?HCA
*LWQ(/Mp&xwi)y2#3EAt^<^UEn*/NW2A[Xt';case"zh":return'!UF01g".WGlvhGW8^82yD]_vS
x_tv{CtSWo*/--="2*|$tN&.n?*:p&S-!#v*C>rP~`W9>yFI!N[@r[x&MV-tSY&yV+lPI?kWDR0+VlgoBgNEtOx#{E%
K/XD&gYA]3(M:/&+cW@rekQrY:gT3EA<Kby3O?pK=[ywpo*vYT$.yW
0z(M.qS0?UVNf*jXZ"g`g0jpI4HjeMyH4Z]I&/h}igsi39xbQ)"a$OB""0?Fm&n):wfb+@wo5s%:Ba5L&7VPefXw8eXI;W(RJ>R)PP@f@&toL#KAkjd=5TnX+M`f=LGP^"&L
Ve$
|;A`;o;%}HYi8BY=|vq.2-2tAo`tPq3S?v-/M@,xXO`
eK3Z>kZF$JAqB
CW|a.5+y!]HJ%i"y
d[Y`+$3I/4c=LAOeq%+:481SYG_sT.GoSt_a_WoIdnE^-nO~PC?
t6w.eWi*<LgiNO*";Ii
lg0sWFSe^m!!4D^Pb(`rEB@.+aB.Liv5mJw;U3-#`wvGlcW6rYRTd*%J&FAE<4&#AEn:L+jo]CJt9)a*QdV3^0qL$rLV=)R1-A)`9&P6Pr
WV5wl,}D73
jFcL`0$84CtK_>GC`cIFI}4&X6.0e~x!Q^xDq!r4>#m1yGkD;IwhK?)v2C4|pt2-hUWQ1"?}n0
DsuyE=d#gsPI:it<b%/lupZ+)M[`SqO]y@mYB-2^RGbe<;(+WR8"E-P6]`b_^0qKXvJc?*X4+5[qAXGw9o%@LULj>s-IZ5Vf+Kwyy!X[j=;M8:7B5av$V"1u/!o8@=O[.du[3QO,Jv>H4<jEpfx&Z-N#B=7C/UW-Ln_@h:>9#8Pm$q1nMr6H|
hQD!%OGg9$HkTe%atc5:++pag(OW!Xqg9NDFasuuO9SYZ-&n>8}NvY(
oVMp[,WGHfcE.Y,
cr=Ur/:7%:=>3bH4*Z`ASWBAFkI+93n8G2rOCxC%,%;NbZWU{oKWC-(09#D#maM(o09w2>-..hfN5H_uVYHrRc_(^kivgl6NQ_v]#=Hrgm5A+LWMR0*BYn>O~@[?uc4c}ikfw,wo(xFu6x(Z=b
/[Y3Zwb5$)6zhMbpc8)3^D;1n(VM6]B4fjQ?qL@5^iQ!fT[$uu)_:+2Q
4rj2GCs?r0W!4Z;`Mt{P>"WL^?6%(#T?]5FO=9-bhvi9Z#ymkjBy[Mipvfakm+TY5k,8<g*=QpeCB9KpCZYZ&@q3q)#+:JU
HSp4Uyp?%.]Z4jAoc&epZ?yy)!?DN&&oaE2!;4[BYvhDQ2}:&o2VOXohDThLp8@):K>5}Q_#V;ZQyiQ>de+q>_Lwh:*;"qxv}PL?qm
cC_
F01&;543p?
bRi^<N%jO4KB1_D*$y,_;S6gz=pvOpK_3EZ@KlycRQY=@VzkcF%kI)X,g+&
o7(SS^{_`%.(:c0vd@"pI1hJ`?zuU;?ag=5jsic%&d=.vaM"oZf&r;Gi^tWo(KCDiV}qeVeIu]&n7V=2Ji@]POg=]`kGW+B>fo/C8InUr
WM1
y&jdAU]`IO;m&a+m-cN*2Yn;9p!-":|cA8%bE6Ln+y(p|Ww2vs}i//@N)UP*Z1#s+A[X%Qpgo84l-3IvuxC/vjD3Qw*-ZZD<-2z9Ki9%Z;BkVY2a?_Oa+
Fd$>~xYax<R3cS`nL=xj2iz9AE~!MNh0d&FAf/Kd=^lJ)b_ViE!$I
V^Y2@KIG7K5^R7Xb"?znr?7WtkiIyYu;"(:U~&xfJbQ[4W51pGcV+!qMRGPcKDhSQbIg#UV^-z&)MD^`d$#7,8CfjsUX2S=[-7p`1X:49bUH&jz1nZ);uaBCN=
&AZpmojSX-$WNv-b#X`@ZDnfWe6#jMCYuh^`UhK(ZbDIxy#TeCQ
qzF`K(gf5bUo,nU})V_Y">mRHq#Ll
6$NI/IpER:n6oxKmDuRtUp
!0m"0;%4a%G-e$lb"7B1*[:FVOL*%Z$+^L~bYjQxdQN"m?Dk+Wft]R%@jeB=YFj-RJ!?OsXZ1GfPwca
cX/**H)>zvw%V3Co5![o;srmS!_olp54}r4&`E=!vky&2G6Y*UlrIIw9WIC)mN{Q/sQtp4hB]_<CSdm
0NB&2-)S}@
Xsjm82kk?tw:%l*m)ZVmt
p
5Z]@,.#J>P&xpFvv(%%-LLP19U=>[KnH:%*Brd]~OjSqJDEkC:F@,RDT:Jtl5o(H<UCR@Q?Rr9hOCsBT[;+@MD5T*DA"$-t&N?R%%Mk9[h^Uywt:gXCgQT]m
.&(e28Ruh`pqorT_i0%R*5s)J
1c)k"V@9iR/"S;IK0O*]dfwYd]HW*_B5QT;6"X1t/=`f/-l5.!>;r_9q#:;LKFlU=&I<@Y"4YfNPAP$J<us2ICsA"OH%3`zvosZvz-?8hvc92
`X]
-s<QdS>N<seOgp8_8iS=UZv)[+D_!MpQ$Y5dxxbFy2?-PGL%a?)`tA$FEnz5dJZ_j#yWs2RO]4yu<ehfQAx_AllUxfJ-##2g#Ds@>yCh-Gm-Xdv+B.q)z(?<T7Jx<Oxc)X5nq%0B
hMa_T,p(W|)}gK[)^&UO`DFF_L->&u/-4-1mIdfIZfZLwr
et
s8&F&P<m4<s7><J<IEH$p,G$"JPt,R$m"q1eMEY?j*XYfJiB:]mTjcTr[XU`W8[JV&-L^P.1)b3dOW/"TV)unU[KD{.Js"%p:N@(x-`;C6+H<b9}Fbp]_P:{m|;>;>Xp[5n$/Pn`R,fzm00}oPA&WK]U2/r]O01BqKeF&_6PM7`}]]!MO+X;RT5]v%jwJ>r!Hp
$`vYf>!5"=kFi41DiP2:Pgh,k22!mC+k4o>7eTZ3f:p,>N:E%szdVc3-,--jv<H,c,&K"k/&M"*o}DkX{e`H~S$/8p&a|U>wTctPQ)M#Bmj5a;B^~fxrP03u,IKK5=/l*t)[T`9i^oruH7]Jj=QD?u-0l#wXZd])P<U?
+AAXFi]_^7"rU1ue=(8HL=dT&;H}Hh^"&YI2p):[Zf9"IvY:ljN-qnDIMC_vNf;8>Arm9c)!<F!#6[%08vKQ`I2W(4HcJ7>l2/``WRA4-y"f2*#&/h%k';case"zh-tw":return'*UF;2lQDy@wR|RYfmWv>}a;SL]x"qL``XO$8%-=9]R
"CEk"s:r;.j/%$j!fQ3;8uGi7`)
vc&XInoBj"x&v
tSZ#G!xaum5uUMb.1fb``ZsNGj:^<*gNB4+Gk+*5g"*a<V^KAYjSxC9q=$;Fbt4?4W
HMxAi"
s.mcsz$1XtP
H=tnA;
hw+sS;uK[k<0}Hq*{k|Sl$7b<43<eLZPQMkQ/@t12Do,d]P("^Mo:HWm@LYdRemb_^D&8orL{U>jdMo;oTp!quIaFt4
@6U3PaEkcYp@yKG8uyD?%t>OV5mdTHi!P3JZ.
mn[Fw)~>!1V^;f}JT[:i_l"dqrV61kB2e<d59w,:wsP$;p
TB:`aE:`Rtkis^xPL5xE5~y2.p@O(#Vm2RmY:eb[slRhvHDQoJtTU"JLr7g
Lr(qnrm|VH)8.y`_KJOlm~l;kz&uAf
8-^N0(jfYnE.
E::ZknC
l(&Om|ajkB*h@D8Hf2AY0IvIgcS(X3tXkBUra9prcuUG.0BW0E7[P[mp3E-`m_!nkHTKSBfH_W&&M});@;a-4ml1EN6B[6EpNG(?[R4dcM&MHT1d)cXPqI]V<-qQ<H)/j-tyggczT<GBRw0VMc*u=Q"G93H;<U@5F%*kV/1q;
nguK<tMtwQ<(xY
Tmwa2$+Lfl+*a_D
/6+u`GiK7/eM
YcOH1@k<
M>BL&_Tmx&g_$RC;,?Y:AHkU(i7AD/YFhG!cUWRtUCoIB_X<`nQg8EYt1bkbd["qLmKtd[W"qBo$#lyXhny1{WVe}t5a<7kxm_-,}B"*YEfIw,-!PMy_di7ZEI
QM7B:ZFKM"#|@z9=d_;p`6fMMEqWviDC
Mnjy<NrT>Sb:2xnhn^7a-JeVB.PZ.].5FGX8n
KT}-]mHVa9@u
$^]dxo;O

2Q1]#tc~8yIT16LN6G.pQ7
e99["Fee,-,kxa|),<sVYeo!ZQjj1&iK!JF7]^=V-Qfd]s"eIZP>n.*@`2QwFmSvR@)wIlc8k2:/hp6MjxR6XE@p),^yZ8?gv`=.8cBH2K6O3`RbK&7xYX<rTt,.^ayZ:L_z%GjHZtfQFt=a$tV8s>gM{bZG.xUedUC-5WwgaU)sj!K6:DKh9AX#8^jn>7s]hjlfd+%<,X.u(&%cF&,K;soFJ/k$#CIAsooqiKE:;.C@2YW7w7aj{,4l!e;Z?U$3iqjrm8)i(Grw8Adb[;rM%W*_Z$gbi+t3C?z9Aje]1:u"4"/24"--1
ZJ,=Lv[vhB;5yB|vy>ik[T{
KYbUfnKBBaJTRUi_Gn$^5SVLZlPF}#26~#/9Ov[-ZK<CWN<0,6ZF?-f-$$M6ox*R@f]
pFIjQN~MKZWXRv*i~wi/
ug1vv]#mc/[`DtEUoiAKb~ULODJ;WN,siS[>xxHRpNiFXgX<il5A^:ZwD#>
L?N"FCEisFTmr[&DX$6<@uT-"qm>asn?n%WIp(At%#KetwM!MPYQ&md[:_;*q/^ydS@>S9,yka4949Cx5
w@z(V~=/WT2/@@W}FeOPkR41Vw7=@,adfF:"<M-dwY28yeLfFcT*T:,T3c@c`L65n_;w.jEYpU65JZb@)R(2&8$@@K;o/J]LPR#0:@A?l4:agj.6+)pLY=U0S<$1"XimZI^0UX8mh<D@+v,-T8(gB|7aV~ghp]q3QJBy(o@ZwNV
Y;mOxnTaeQEsj?+aOIbC8/MY`Is8X%v6`"kRl~3c)K$mq/ymjz4~6N5ryhVWNiI{Bi2NO`I<>:)_EsnF.LZnbVeZjv]w]ncc;[./pyz)x/uZq/3,F$"#3u)`$=$uJ|uUkY9=J|t!29oX>Ug`7gXsNgS)g<LDF$8nj8.@5G4].Y9Fm7<=oXLF]a%I8|KZSOA!-S8N8sVz=v:u=NC.
p8gPr^,]:MOB3fRgev<e.R6@%U@_AoE-wIamwI0fIiQ]8:1i7=1m./?9
R>X^G<V2ZV936S!iE*!<835YGL8|Wc;d%r#
r.(KSyT_ilN(f~;]:Z"l#t@~B3@F^:*j%(sYNAb:bvN~q)Vid5SC
3)j`e
T<Nh,Y6;lKfZ1n
R.$;^?WE[_8@!7v_u
vI>.0"oUqCZ#-f^l<vBvd7N-x%!qgr$|/{F:Z*8[f5NLdF?cwS:r6zgJN^C(daf+w2GTq7-"v#YtAM<27BKEp_ccCn.7ta-1CF=hmp_MwD[vQ9>"aJ7+?}I(6npmp-)rm1qIpOL[jj=Ru|l|=D-n/z()A@h5#GZ-Q&n|Iq@#Ur"]pf:u,8mUqFV~q!C,RL<(]<3-ptun@b(=LFOoRgfO%ir*)WX(k7&>P_1H]i:Ejn19?goN9lp"[y_TI>.x)m#dM^$]&Zr$qv8T[G`t[<&N1!hm`boC>:BU#h(U#+DPJ}cBY$TO[FI-JH
j.~^sssK{YDyOIP:3Y^G:v^D;9EJ~ft#lG/3RL3W/@LSu)]<{1xbr_dticS3D)}-Paf?Rggc~$aUWg1Gq0b/Z8}dsM>n}lN[-nZZ0GOMYG:y}>Xho*a+H/]i9Gn]dI+*|qCTo=/YH&0r6!,yFJ?UmDdW#
&V2`4S;.E+D9GhKeTg2lVJk^]i|FHYu/a)Y1@5~C1$],XB5xl6oPB$;T^oY[[XY/AFqQ<;76aQvFgs%fJ3.5/3)7_pf-kD-rnj~m%<$mWd}nh>>SF8tR,2~1;QET+0NJCO;A#B*(-3BumP"j]>bp|P?.]+BK59rj&p-^J$SR^w[gO5twW?_C"$(*ggALkK(.I%bbgbmi(pceTeY_BJ01lT"bkKY*B8tqq@DvpHZ7i&/leQC&1HWH]Q*_(K.r2RXj0e5XtN0We2$_zUD,?HIt,.ql-+T?oN;AiOX9/C3$ie5S
w@gBw>qhL|&oKkI~dJ>DHP%gf$m|B=%K<N/0#PZ;rNh{us"P`
DQDn`cxe2[wO<?J3ADaN%C""#FuET})NcM8MWD
GZ,[dLL[y#42hQ,p.aK(Z>5oW2
0tti+*6cc-yB0p(vE.WiKD@2dm86!~u_*3/To{9b$:M};|4"n/=}CU9:xzOU5EVa3A)4y$H"gs1Si#I|3>P0u(PZHU)(kjjl0L0);LO&({iGuB2zM`m>[hQ5E*d8@QGk3lUrF}#PmMgG=hy70aB3PaMvx)Va7!a@v2NPOo6wCPYxqhc}$h';case"ko":return'#Zu09h%Z+1jf3)
dKm%3/6*<MjRKbx,n~"vNFZ^;+lmag"d;-&W

M^)(b&8&E?QKw)-zaW*53q=w-O^snO"38skgg?BYkS<MUBR)1+^hQv([%8%W_`>Z(q?5[df&/}vBY^QN)s&+F4St2hVn!sx"O_94B<qN=7Y.w-::>Go|^g3"ms_v<#wV#V)|J~qwj7;Y)v@$"pdOZC1[l}Y2pzQmIJW!DCTR)|qHF~ki`%`P*8
uG~j62Uu(8s&%1,?"ovqYOw<{Nl@C@nN[PMEUn(O2<w%zBdxv$R6G`Ugcy|47e)/$)Ftklu+9:4wd94/6Y]+$YZOhQtd$?[9pp=K?KkenbG-!DzLF"MV%:#i5&k&-imZ;[{dQ9%.M,)S3?
G,_G&,88n:_[Fa%l7B6BilwC+}l{#Jqd`siSa1rk*sk{C"lIbp%(8a=aD>>P$%Ae2hx[<HvZXu&-ddb6K*2>BfnVrS4=w=],b"!@0x1CqrCFP1yZBj?yozLyIkG}jR+NJ[ek+VAxAkQ7>vh|g,#F$vok"b:uObE6mW@,5YZ4UKSH
K2Z8)`v2b]zvXdH4Y
Vj`UVB.*j*DA-V$XcGY%!7@-)NFdRS5?]]+TBH_uO*-%P`$!Th*:.d409?Zp"<WwUr]419o=7knCU#c_D2t:R
Ibj=mJ`PLL{^|a9?9gQYs*EPgj{*eNquOmXC^CMA_lkC/XT#r$PRDKK":t1EV8|Wr+o?h4je]=dE%9ZA]QOQ0Ogkf3ZN[4C*cE[!4;dw&I
l2k=gud%&zPyFLmzYMOqvi*_AD:dxhi3Ovm4rrU&nZ8Kt9&l7"-9U8S$bZv^K]?(ph:vHksleGR8Q22#Gx
|M#U[6f)UgS&8
P<O;Nmeb5X|?OF2Fa"sHV##A`p[FrIIbj%+=u6,6<^A*,qFi
pdnwDk2|^.xo;N>&jX->ZxjA+r<02V@0yV6E*f5jXGu)4*=^lxZR#Z*&:x3eQd6u%)o+!-RFO(1w`:3N88Q03IqLg]HvVi/NgAY_;?WDYH0>UOQ+bJNr:uMmMO52&6206J<
F[*D,(BV9^&$h-ZawO*w6H"c$yXyy393<-G+6j;Uf"[c"Y8S_k;o)]"3LgeI(LLn_n^E)Hw$i*HmPdH)`=w$Sktg
jJ%f)i%su.Igc,dSU0ZBJ2U11Z5=oy0a=w}dGN4wQ]rx+=N7QAK2
,|shDFx/r"2/=<YGW~M}V8(.XEDGBW.FR?u0Qcx%x"@%gwD$qFBs5hH[!5kE.~W*<c>NxMAE<|EL=ZCqU=3F;llJF`L3*Ub>Wyl_fF,]Q,Jl,0</03FkUt_D@]="";MtlGwKnPW~Tvei6/o=9xIx&&_*lUu+&._5wN+$8L/.u1_F"|-if2i6c&
ZL^-%1V>$-XU/%%.:0$^6ru+wo--wcV%y#Z6ziL*1q#i^J8"iePJcrZTq%Z^e`lV*F
yB6F]"#zA>mAhHY!5Vt4P%&Vr6c<I?-i8;Cp4^5eH6&c<!139?#(ly7yE
J}GaGb
4m"l
,~H{Ks?c[<E<YXWAksr"[jczuf%(jee@E1L:wI9BeqriK;q$?N@uQ6K5#jPVGzNh[f-NmOddjt5]E8WJiL#,5HZ3qeeByBgEo1dM_x,MQ<0M[=^/F!84Yz@d:hsI2~c|%AuaWqx]0Z6|MbhM5b9=#`S"8FW}.0_Y%Vu<XTfES,alI!F<`I>l939);8&(8odlz&OJ"Z/<]UuMuCD3E5&.kHF)k]=j.Bg/8~fKf1PdA-3!W{_Wmc)9^TQTX#oB<NjP9NfN_!f$t."M>#K_rY=e!edOuFIpr1"g_9xC>1QTQ_q,.PjKI%Pm;|
ghLUzoc^CEYqPZGF
5;op"/:q&O=?K#wQZ@_Al;aV_^ElSW9!bo%3U`[yPa%8LTL+ay4WdE]O+Ye7yUc&m:C$QUVj@W?O]zo1;^9bA{4.;sap"yh*i7XT6uL^Q!vAhhi@1t5F3NkE9of#-ScNgmx"<y@MWeWn"s6;Oj02oy7BoVkVvE<"jB+<8/k886,Jl4hE9LH{={_3_5F,BH(N%DGL`&>Gt*9y-Q?:`zt*j(Fvxa;b;Q_CAt1cL>
xUJA!9G,~c.#imlw</z`xa-d,OD
!Y|J|4lamnOvo&ngc#/(euX+Urzk!Ty8,[yx[.l@XS+.!`Q^jLc.OI
IR_Ao?:WH_qoj8mF^4``hQ;q+(=]H$7|Ar,@xySdLl:jb:&LMDlq14O%JTEprckBK%Dk1$AN&*r)D/@.I?^D_CA(BWhc=.vrODOK[EA/#!RVK;wo
EnpC3-E!
J[2&9zATi,n/l3V%L>9DeQ

(u"wo-+$@?<D?]yn_H8tZs`o_Pe^Va^*){IR],>n&+2Ku.df?`vD_!+tV,ZDf!I1HVFWHWejF24idvL9sQCmxJB8j1H
oE3)y/Q@yF9L3s62DnS)1;OwvwNM7Z("nNW]v`3IcArO
*jdaS!"ds:0rS!]0_>G7Kem2D_heDiG
M>2P,4kIY#8,s]
<{!^Wt7X=4klt6qWUH4%s*3QO8M9teais5j-!L@UU?4"b~[?Vwg[FOx<gVu`"ntZ6M)sLRTQIpMN<*WYgTb".WCf$"<C_./<Ncln)L_e2!`o3])i,>[}NI6wovQYB~1{5N!5*d*6M.n_Jm!Pif`W`Pv#bQ>_2*Fz4G!E!{&i:l#:6q#/I,o0p.3m*Hc!mq?f7"&M:AA6Pf+D`N[7
-uwju9,mYO},d/[M/p_As&0E*qj6cb8ZbS}#8Mm?)2hSBH~UT]9k@JU]%j2wG7!0N9RtTdM4Y^DqDIU,&tIv-KLH7WYL3?!@UNpJpO3(XLbwnxZ=fT:5|HuUbY+GZn[p@/I;ff}#ckLc]$IL~R4;#D_bz8JuYVk"xIptNocD5sh4Dr4JNJ]?yX{s
NHvQ#pO`+J_bt3@]Ay=$)^MaYDl9rSbD/7bq4
SsK=]8k$1d,-w11$`Z[t2gDKJD]hl5YBK_Fo]5iBu1e$si>d%~:w+RD,s8HW29LH46,e#4Uy
6W~LPeM9Q-aD[LwSpg+T$jL%9&dKd2WQ_eobuH!Fncyt
PqRJP!Pa]N$QRTW8hFROM4$zuUO7bE3JYq9f]1L2=/[tt./Zn!Jt
9oSIi188nl4=_AA]KA-dI;wYQ`rB<q8dzmeOy1YgjBs-_nS!v$kD#=hU{0$]2&~+/w~A[Ics#_2T@3x3-G*]#:RBsxA>Ah4E:65Yvuh?jx[%qK&9DUX.cc%e"mWCBGp&bPC@l1Jum1U)`
9BvjxD/61
JhtIF!F!-A"2]k($9J)r)8-]S"s:SIpRU6`,@5QV1S=V]H)5dFLxm<3u]!!9+:~>o+ATr+P`Ttwa=';}return"";}$mn=LANG.crc32(get_compressed(LANG));$ln=$_SESSION["translations"];if(!is_string($ln)||$_SESSION["translations_version"]!=$mn){$ln=decompress_string(get_compressed(LANG),(LANG!="en"?decompress_string(get_compressed("en")):""));$_SESSION["translations"]=$ln;$_SESSION["translations_version"]=$mn;}Lang::$translations=[];foreach(explode("\n",$ln)as$X)Lang::$translations[]=(strpos($X,"\t")?explode("\t",$X):$X);abstract
class
SqlDb{static$instance;static$untrusted=false;var$extension;var$flavor='';var$server_info;var$affected_rows=0;var$info='';var$errno=0;var$error='';protected$multi;abstract
function
attach(array$N,$V,$E);abstract
function
quote($Q);abstract
function
select_db($wc);abstract
function
query($G,$An=false);function
multi_query($G){return$this->multi=$this->query($G);}function
store_result(){return$this->multi;}function
next_result(){return
false;}function
inTransaction(){return
false;}function
begin(){return!!$this->query("BEGIN");}function
commit(){return!!$this->query("COMMIT");}function
rollback(){return!!$this->query("ROLLBACK");}}if(extension_loaded('pdo')){abstract
class
PdoDb
extends
SqlDb{protected$pdo;function
dsn($jd,$V,$E,array$Ki=[],$Bb='PDO'){$Ki[\PDO::ATTR_ERRMODE]=\PDO::ERRMODE_SILENT;$Ki[\PDO::ATTR_STATEMENT_CLASS]=['Adminer\PdoResult'];try{$this->pdo=new$Bb($jd,$V,$E,$Ki);}catch(\Exception$Kd){return$Kd->getMessage();}$this->server_info=@$this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);return'';}function
quote($Q){return$this->pdo->quote($Q);}function
query($G,$An=false){$H=adminer()->executeQuery($G,function()use($G){return$this->pdo->query($G);});$this->error="";if(!$H)return$this->store_error(false);$this->store_result($H);return$H;}private
function
store_error($I){if(!$I){list(,$this->errno,$this->error)=$this->pdo->errorInfo();if(!$this->error)$this->error=lang(32);}return$I;}function
store_result($H=null){if(!$H){$H=$this->multi;if(!$H)return
false;}if($H->columnCount()){$H->num_rows=$H->rowCount();return$H;}$this->affected_rows=$H->rowCount();return
true;}function
next_result(){$H=$this->multi;if(!is_object($H))return
false;$H->_offset=0;return@$H->nextRowset();}function
inTransaction(){return$this->pdo->inTransaction();}function
begin(){return$this->store_error(adminer()->executeQuery("BEGIN",function(){return$this->pdo->beginTransaction();}));}function
commit(){return!$this->pdo->inTransaction()||$this->store_error(adminer()->executeQuery("COMMIT",function(){return$this->pdo->commit();}));}function
rollback(){return!$this->pdo->inTransaction()||$this->store_error(adminer()->executeQuery("ROLLBACK",function(){return$this->pdo->rollBack();}));}}class
PdoResult
extends
\PDOStatement{var$_offset=0,$num_rows;function
fetch_assoc(){return$this->fetch_array(\PDO::FETCH_ASSOC);}function
fetch_row(){return$this->fetch_array(\PDO::FETCH_NUM);}private
function
fetch_array($Bh){$I=$this->fetch($Bh);return($I?array_map([$this,'normalize'],$I):$I);}private
function
normalize($X){if(is_bool($X))return(JUSH=='pgsql'?($X?"t":"f"):+$X);if(PHP_VERSION_ID<70100&&is_float($X)&&is_finite($X)){for($Rj=15;$Rj<17;$Rj++){$I=sprintf("%.$Rj"."G",$X);if((float)$I===$X)return$I;}return
sprintf("%.17G",$X);}return(is_resource($X)?stream_get_contents($X):$X);}function
fetch_field(){return(object)$this->getColumnMeta($this->_offset++);}function
seek($C){for($s=0;$s<$C;$s++)$this->fetch();}}}function
add_driver($t,$B){SqlDriver::$drivers[$t]=$B;}function
get_driver($t){return
SqlDriver::$drivers[$t];}abstract
class
SqlDriver{static$instance;static$drivers=[];static$extensions=[];static$jush;static$passwords=true;static$serverSchemes=[];static$serverPorts=[];static$serverSocket=false;static$serverPath=false;static$serverFile=false;protected$conn;protected$types=[];var$delimiter=";";var$insertFunctions=[];var$editFunctions=[];var$unsigned=[];var$fulltextOperator="AGAINST";var$functions=[];var$grouping=[];var$onActions="RESTRICT|NO ACTION|CASCADE|SET NULL|SET DEFAULT";var$partitionBy=[];var$inout="IN|OUT|INOUT";var$enumLength="'(?:''|[^'\\\\]|\\\\.)*'";var$generated=[];var$primary="";var$query="";static
function
jushModule(){return"";}static
function
jushAutocomplete(array$T,$fm){$Hm=[];foreach($T
as$R=>$P){if(!$P["dependent"])$Hm[$R]=[];}foreach(driver()->allFields()as$R=>$o){foreach($o
as$n)$Hm[$R][]=$n["field"];}return"jush.autocompleteSql('".idf_escape("")."', ".json_encode($Hm).", ".json_encode($fm).")";}static
function
connect($N,$V,$E){if(static::$serverFile)$wj=server_parts(["path"=>$N]);else{$wj=parse_server($N);if(!$wj||($wj["scheme"]&&!in_array($wj["scheme"],static::$serverSchemes))||($wj["socket"]&&!static::$serverSocket)||($wj["path"]&&!static::$serverPath)||(substr($wj["host"],0,1)=="/"&&!static::$serverSocket))return
lang(33);if($wj["port"]!=""&&($wj["port"]>65535||($wj["port"]<1024&&!in_array($wj["port"],static::$serverPorts))))return
lang(34);}$g=new
Db;return($g->attach($wj,$V,$E)?:$g);}static
function
disconnect(){}function
__construct(Db$g){$this->conn=$g;}function
types(){return
call_user_func_array('array_merge',array_values($this->types));}function
structuredTypes(){return
array_map('array_keys',$this->types);}function
enumLength(array$n){}function
unconvertFunction(array$n){}function
select($R,array$M,array$Z,array$Oe,array$Ni=[],$z=1,$D=0,$Zj=false){$dg=(count($Oe)<count($M));$G=adminer()->selectQueryBuild($M,$Z,$Oe,$Ni,$z,$D);if(!$G)$G="SELECT".limit(($_GET["page"]!="last"&&$z&&$Oe&&$dg&&JUSH=="sql"?"SQL_CALC_FOUND_ROWS ":"").implode(", ",$M)."\nFROM ".table($R),($Z?"\nWHERE ".implode(" AND ",$Z):"").($Oe&&$dg?"\nGROUP BY ".implode(", ",$Oe):"").($Ni?"\nORDER BY ".implode(", ",$Ni):""),$z,($D?$z*$D:0),"\n");$this->query=$G;$cm=microtime(true);$I=$this->conn->query($G,(!$z&&!$Zj?1:0));if($Zj)echo
adminer()->selectQuery($G,$cm,!$I);return$I;}function
delete($R,$lk,$z=0){$G="FROM ".table($R);return
queries("DELETE".($z?limit1($R,$G,$lk):" $G$lk"));}function
update($R,array$O,$lk,$z=0,$rl="\n"){$fo=[];foreach($O
as$x=>$X)$fo[]="$x = $X";$G=table($R)." SET$rl".implode(",$rl",$fo);return
queries("UPDATE".($z?limit1($R,$G,$lk,$rl):" $G$lk"));}function
insert($R,array$O){return
queries("INSERT INTO ".table($R).($O?" (".implode(", ",array_keys($O)).")\nVALUES (".implode(", ",$O).")":" DEFAULT VALUES").$this->insertReturning($R));}function
insertReturning($R){return"";}function
insertUpdate($R,array$K,array$Xj){foreach($K
as$O){$Z=[];foreach($O
as$x=>$X){if(isset($Xj[idf_unescape($x)]))$Z[]="$x = $X";}if(!($Z&&$this->update($R,$O," WHERE ".implode(" AND ",$Z))&&$this->conn->affected_rows)&&!$this->insert($R,$O))return
false;}return
true;}function
begin(){remember_query("BEGIN");return$this->conn->begin();}function
commit(){remember_query("COMMIT");return$this->conn->commit();}function
rollback(){remember_query("ROLLBACK");return$this->conn->rollback();}function
slowQuery($G,$Vm){}function
operators($um){return[];}function
convertSearch($u,array$X,array$n){return$u;}function
value($X,array$n){return(method_exists($this->conn,'value')?$this->conn->value($X,$n):$X);}function
quoteBinary($Zk){return
q($Zk);}function
md5($d,array$n){}function
typeName(\stdClass$n){return(isset($n->native_type)?$n->native_type:"");}function
warnings(){}function
errorLine($G){return
0;}function
tableHelp($B,$hg=false){}function
inheritsFrom($R){return[];}function
inheritedTables($R){return[];}function
partitionsInfo($R){return[];}function
hasCStyleEscapes(){return
false;}function
hasEstimatedRows(){return
false;}function
isSystem($k,$L=""){return
information_schema($k,$L);}function
lineComment(){return"--";}function
engines(){return[];}function
supportsIndex(array$S){return!is_view($S);}function
supportsAlterIndex(array$S){return
true;}function
supportsAlterTable(array$um){return
true;}function
indexAlgorithms(array$um){return[];}function
indexOpclasses(){return[];}function
shadowTables($R){return[];}function
routineTriggers($B){return[];}function
fulltextSql($B,array$v,$G,$cb){return"MATCH (".implode(", ",array_map('Adminer\idf_escape',$v["columns"])).") AGAINST (".q($G).($cb?" IN BOOLEAN MODE":"").")";}function
checkConstraints($R){return
get_key_vals("SELECT c.CONSTRAINT_NAME, CHECK_CLAUSE
FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS c
JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS t
	ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME".($this->conn->flavor=='maria'?" AND c.TABLE_NAME = ".q($R):"")."
WHERE c.CONSTRAINT_SCHEMA = ".q($_GET["ns"]!=""?$_GET["ns"]:DB)."
AND t.TABLE_NAME = ".q($R).(JUSH=="pgsql"?"
AND CHECK_CLAUSE NOT LIKE '% IS NOT NULL'":""),$this->conn);}function
allFields(){$I=[];if(JUSH!='sql'&&JUSH!='mssql'){foreach(tables_list()as$R=>$U)$I[$R]=array_values(fields($R));}elseif(DB!=""){foreach(get_rows("SELECT c.TABLE_NAME AS tab, c.COLUMN_NAME AS field, c.IS_NULLABLE AS nullable,
	c.DATA_TYPE AS type, c.CHARACTER_MAXIMUM_LENGTH AS length,
	".(JUSH=='sql'?"c.COLUMN_KEY = 'PRI'":"k.COLUMN_NAME")." AS ".idf_escape("primary")."
FROM INFORMATION_SCHEMA.COLUMNS c".(JUSH=='sql'?"":"
LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS t ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND t.CONSTRAINT_TYPE = 'PRIMARY KEY'
LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
	ON t.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME")."
WHERE c.TABLE_SCHEMA = ".q($_GET["ns"]!=""?$_GET["ns"]:DB)."
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",$this->conn)as$J){$J["null"]=($J["nullable"]=="YES");$I[$J["tab"]][]=$J;}}return$I;}}add_driver("pgsql","PostgreSQL");if(isset($_GET["pgsql"])){define('Adminer\DRIVER',"pgsql");if(extension_loaded("pgsql")&&$_GET["ext"]!="pdo"){class
PgsqlDb
extends
SqlDb{var$extension="PgSQL";var$timeout=0;private$link,$string;function
attach(array$N,$V,$E){$k=adminer()->database();$Kj=$N["port"];$qf=($N["host"]?:$N["socket"]);$this->string="host='$qf'".($Kj?" port=$Kj":"")." user='".addcslashes($V,"'\\")."' password='".addcslashes($E,"'\\")."'";$bm=adminer()->connectSsl();if(isset($bm["mode"]))$this->string
.=" sslmode=$bm[mode]";$Ed=php_errors(function()use($k){$this->link=pg_connect("$this->string dbname='".($k!=""?addcslashes($k,"'\\"):"postgres")."'",PGSQL_CONNECT_FORCE_NEW);if(!$this->link&&$k!="")$this->link=pg_connect("$this->string dbname='postgres'",PGSQL_CONNECT_FORCE_NEW);});if($this->link){adminer()->executeQuery("SET client_encoding TO 'UTF8'",function(){return!pg_set_client_encoding($this->link,"UTF8");});}return($this->link?'':(string)end($Ed));}function
quote($Q){return
pg_escape_literal($this->link,$Q);}function
value($X,array$n){return($n["type"]=="bytea"&&$X!==null?pg_unescape_bytea($X):$X);}function
select_db($wc){if($wc==pg_dbname($this->link))return
true;$Ed=php_errors(function()use($wc,&$I){$I=pg_connect("$this->string dbname='".addcslashes($wc,"'\\")."'",PGSQL_CONNECT_FORCE_NEW);});if($I)$this->link=$I;elseif($Ed)$this->error=end($Ed);return$I;}function
close(){$this->link=@pg_connect("$this->string dbname='postgres'");}function
query($G,$An=false){if(self::$untrusted)$H=($this->execute("BEGIN READ ONLY")?$this->execute($G,[]):false);else$H=$this->execute($G);$this->error="";if(!$H){$this->error=pg_last_error($this->link);$I=false;}elseif(!pg_num_fields($H)&&pg_result_status($H)!=PGSQL_TUPLES_OK){$this->affected_rows=pg_affected_rows($H);$I=true;}else$I=new
Result($H);if(self::$untrusted)$this->execute("COMMIT");if($this->timeout){$this->timeout=0;$this->query("RESET statement_timeout");}return$I;}private
function
execute($G,$jj=null){return
adminer()->executeQuery($G,function()use($G,$jj){return($jj===null?@pg_query($this->link,$G):@pg_query_params($this->link,$G,$jj));});}function
warnings(){if(PHP_VERSION_ID>=70100){$I=implode("\n",pg_last_notice($this->link,PGSQL_NOTICE_ALL));pg_last_notice($this->link,PGSQL_NOTICE_CLEAR);}else$I=pg_last_notice($this->link);return
nl_br(h($I));}function
inTransaction(){$P=pg_transaction_status($this->link);return$P==PGSQL_TRANSACTION_INTRANS||$P==PGSQL_TRANSACTION_INERROR;}function
copyFrom($R,array$K){$Ed=[];$I=adminer()->executeQuery("COPY $R FROM stdin",function()use($R,$K,&$Ed){$Ed=php_errors(function()use($R,$K,&$I){$I=pg_copy_from($this->link,$R,$K);});return$I;});$this->error=(string)end($Ed);return$I;}}class
Result{var$num_rows;private$result,$offset=0;function
__construct($H){$this->result=$H;$this->num_rows=pg_num_rows($H);}function
fetch_assoc(){return
pg_fetch_assoc($this->result);}function
fetch_row(){return
pg_fetch_row($this->result);}function
fetch_field(){$d=$this->offset++;$I=new
\stdClass;$I->orgtable=pg_field_table($this->result,$d);$I->name=pg_field_name($this->result,$d);$I->native_type=pg_field_type($this->result,$d);return$I;}}}elseif(extension_loaded("pdo_pgsql")){class
PgsqlDb
extends
PdoDb{var$extension="PDO_PgSQL";var$timeout=0;private$string,$username,$password,$database;function
attach(array$N,$V,$E){$k=adminer()->database();$Kj=$N["port"];$qf=($N["host"]?:$N["socket"]);$this->string="pgsql:host='$qf'".($Kj?" port=$Kj":"")." client_encoding=utf8";$bm=adminer()->connectSsl();if(isset($bm["mode"]))$this->string
.=" sslmode=$bm[mode]";$this->username=$V;$this->password=$E;$this->database=($k!=""?$k:"postgres");return$this->dsn("$this->string dbname='".addcslashes($this->database,"'\\")."'",$V,$E);}function
select_db($wc){if($wc!=$this->database){$m=$this->dsn("$this->string dbname='".addcslashes($wc,"'\\")."'",$this->username,$this->password);if($m!=""){$this->error=$m;return
false;}$this->database=$wc;}return
true;}function
query($G,$An=false){$I=(self::$untrusted?$this->readOnlyQuery($G):parent::query($G,$An));if($this->timeout){$this->timeout=0;parent::query("RESET statement_timeout");}return$I;}private
function
readOnlyQuery($G){$this->error="";if(!$this->execute("BEGIN READ ONLY"))return
false;$I=$this->execute($G,[]);if($I)$this->store_result($I);$this->execute("COMMIT");return$I;}private
function
execute($G,$jj=null){return
adminer()->executeQuery($G,function()use($G,$jj){$H=($jj===null?$this->pdo->query($G):$this->pdo->prepare($G));if($H&&($jj===null||$H->execute($jj)))return$H;list(,$this->errno,$this->error)=($H?$H->errorInfo():$this->pdo->errorInfo());if(!$this->error)$this->error=lang(32);return
false;});}function
warnings(){}function
copyFrom($R,array$K){$I=adminer()->executeQuery("COPY $R FROM stdin",function()use($R,$K){return$this->pdo->pgsqlCopyFromArray($R,$K);});$this->error=idx($this->pdo->errorInfo(),2)?:'';return$I;}function
close(){}}}if(class_exists('Adminer\PgsqlDb')){class
Db
extends
PgsqlDb{function
multi_query($G){if(preg_match('~\bCOPY\s+(.+?)\s+FROM\s+stdin;\n?(.*)\n\\\\\.$~is',str_replace("\r\n","\n",$G),$A)){$K=explode("\n",$A[2]);$this->multi=false;$this->affected_rows=count($K);return$this->copyFrom($A[1],$K);}return
parent::multi_query($G);}}}class
Driver
extends
SqlDriver{static$extensions=["PgSQL","PDO_PgSQL"];static$jush="pgsql";static$serverSocket=true;var$functions=["char_length","lower","round","to_hex","to_timestamp","upper"];var$grouping=["avg","count","count distinct","max","min","sum"];var$nsOid="(SELECT oid FROM pg_namespace WHERE nspname = current_schema())";private$userTypes=[];function
operators($um){return["=","<",">","<=",">=","!=","~","~*","!~","LIKE","LIKE %%","ILIKE","ILIKE %%","IN","BETWEEN","IS NULL","NOT LIKE","NOT ILIKE","NOT IN","IS NOT NULL","SQL"];}static
function
connect($N,$V,$E){$g=parent::connect($N,$V,$E);if(is_string($g))return$g;$io=get_val("SELECT version()",0,$g);$g->flavor=(preg_match('~CockroachDB~',$io)?'cockroach':'');$g->server_info=preg_replace('~^\D*([\d.]+[-\w]*).*~','\1',$io);if(min_version(9,0,$g))$g->query("SET application_name = 'Adminer'");if($g->flavor=='cockroach')add_driver(DRIVER,"CockroachDB");return$g;}function
__construct(Db$g){parent::__construct($g);$this->types=[lang(35)=>["smallint"=>5,"integer"=>10,"bigint"=>19,"boolean"=>1,"numeric"=>0,"real"=>7,"double precision"=>16,"money"=>20],lang(36)=>["date"=>10,"time"=>8,"timestamp"=>19,"timestamptz"=>25,"interval"=>0],lang(37)=>["character"=>0,"character varying"=>0,"text"=>0,"tsquery"=>0,"tsvector"=>0,"uuid"=>0,"xml"=>0],lang(38)=>["bit"=>0,"bit varying"=>0,"bytea"=>0],lang(39)=>["cidr"=>43,"inet"=>43,"macaddr"=>17,"macaddr8"=>23,"txid_snapshot"=>0],lang(40)=>["box"=>0,"circle"=>0,"line"=>0,"lseg"=>0,"path"=>0,"point"=>0,"polygon"=>0],];if(min_version(9.2,0,$g)){$this->types[lang(37)]["json"]=4294967295;$this->types[lang(41)]=["int4range"=>0,"int8range"=>0,"numrange"=>0,"daterange"=>0,"tsrange"=>0,"tstzrange"=>0];if(min_version(9.4,0,$g))$this->types[lang(37)]["jsonb"]=4294967295;}$this->insertFunctions=["char"=>"md5","date|time"=>"now",];$this->editFunctions=[number_type()=>"+/-","date|time"=>"+ interval/- interval","char|text"=>"||",];if(min_version(12,0,$g)){$this->generated[]="STORED";if(min_version(18,0,$g))$this->generated[]="VIRTUAL";}$this->partitionBy=["RANGE","LIST"];if(!$g->flavor)$this->partitionBy[]="HASH";}function
enumLength(array$n){$si=$this->userTypes[$n["type"]];return($si&&!preg_match('~]$~',$n["length"])?type_values($si):"");}function
setUserTypes(array$_n){$this->userTypes=array_flip($_n);$this->types[lang(0)]=array_fill_keys(array_keys($this->userTypes),0);}function
insertReturning($R){$Na=array_filter(fields($R),function($n){return$n['auto_increment'];});return(count($Na)==1?" RETURNING ".idf_escape(key($Na)):"");}function
insertUpdate($R,array$K,array$Xj){$e=array_keys(reset($K));$Tb=[];$Ln=[];foreach($e
as$x){if(isset($Xj[idf_unescape($x)]))$Tb[]=$x;else$Ln[]="$x = EXCLUDED.$x";}if(!$Tb||!min_version(9.5)||count($Tb)!=count($Xj))return
parent::insertUpdate($R,$K,$Xj);$Sj="INSERT INTO ".table($R)." (".implode(", ",$e).") VALUES\n";$mm="\nON CONFLICT (".implode(", ",$Tb).")".($Ln?" DO UPDATE SET ".implode(", ",$Ln):" DO NOTHING");$fo=[];$y=0;foreach($K
as$O){$Y="(".implode(", ",$O).")";if($fo&&strlen($Sj)+$y+strlen($Y)+strlen($mm)>1e6){if(!queries($Sj.implode(",\n",$fo).$mm))return
false;$fo=[];$y=0;}$fo[]=$Y;$y+=strlen($Y)+2;}return
queries($Sj.implode(",\n",$fo).$mm);}function
slowQuery($G,$Vm){$this->conn->query("SET statement_timeout = ".(1000*$Vm));$this->conn->timeout=1000*$Vm;return$G;}function
convertSearch($u,array$X,array$n){$Bj=preg_match('(LIKE|^!?~)',$X["op"]);$Rh=preg_match('~^(character( varying)?|text|citext|bpchar|name)$~',$n["type"])||(!$Bj&&preg_match('~'.number_type().'|^(date|time|timetz|timestamp|timestamptz|boolean)$~',$n["type"]));return($Rh&&!preg_match('~\[]$~',$n["full_type"])?$u:"CAST($u AS text)");}function
quoteBinary($Zk){return"decode('".bin2hex($Zk)."', 'hex')";}function
md5($d,array$n){if(is_blob($n)||preg_match('~'.text_type().'~',$n["type"]))return"MD5($d)";}function
warnings(){return$this->conn->warnings();}function
errorLine($G){return(preg_match('~^LINE (\d+):~m',$this->conn->error,$A)?(int)$A[1]:0);}function
tableHelp($B,$hg=false){$Ng=["information_schema"=>"infoschema","pg_catalog"=>($hg?"view":"catalog"),];$_=$Ng[$_GET["ns"]];if($_)return"$_-".str_replace("_","-",$B).".html";}function
inheritsFrom($R){return
get_rows("SELECT relname AS table, nspname AS ns FROM pg_class JOIN pg_inherits ON inhparent = oid JOIN pg_namespace ON relnamespace = pg_namespace.oid WHERE inhrelid = ".$this->tableOid($R)." ORDER BY 2, 1");}function
inheritedTables($R){return
get_rows("SELECT relname AS table, nspname AS ns FROM pg_inherits JOIN pg_class ON inhrelid = oid JOIN pg_namespace ON relnamespace = pg_namespace.oid WHERE inhparent = ".$this->tableOid($R)." ORDER BY 2, 1");}function
partitionsInfo($R){$J=(min_version(10)?$this->conn->query("SELECT * FROM pg_partitioned_table WHERE partrelid = ".$this->tableOid($R))->fetch_assoc():null);if($J){$c=get_vals("SELECT attname FROM pg_attribute WHERE attrelid = $J[partrelid] AND attnum IN (".str_replace(" ",", ",$J["partattrs"]).")");$fb=['h'=>'HASH','l'=>'LIST','r'=>'RANGE'];return["partition_by"=>$fb[$J["partstrat"]],"partition"=>implode(", ",array_map('Adminer\idf_escape',$c)),];}return[];}function
routineTriggers($B){return
get_rows("SELECT nspname AS ns, relname AS table, tgname AS trigger
FROM pg_trigger
JOIN pg_class ON tgrelid = pg_class.oid
JOIN pg_namespace ON relnamespace = pg_namespace.oid
WHERE NOT tgisinternal AND tgfoid = substring(".q($B).", '[0-9]+\$')::oid
ORDER BY 1, 2, 3");}function
tableOid($R){return"(SELECT oid FROM pg_class WHERE relnamespace = $this->nsOid AND relname = ".q($R)." AND relkind IN ('r', 'm', 'v', 'f', 'p'))";}function
allFields(){$I=[];$K=get_rows("SELECT c.relname AS tab, a.attname AS field, a.attnotnull::int,
	format_type(a.atttypid, a.atttypmod) AS full_type, i.indrelid AS primary
FROM pg_class c
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
LEFT JOIN pg_index i ON i.indrelid = c.oid AND i.indisprimary AND a.attnum = ANY(i.indkey)
WHERE c.relnamespace = $this->nsOid
AND c.relkind IN ('r', 'm', 'v', 'f', 'p')".(min_version(10)?"
AND c.relispartition IS NOT TRUE":"")."
ORDER BY c.relname, a.attnum",$this->conn);foreach($K
as$J){parse_full_type($J);$J["null"]=!$J["attnotnull"];$I[$J["tab"]][]=$J;}return$I;}function
indexAlgorithms(array$um){static$I=[];if(!$I)$I=get_vals("SELECT amname FROM pg_am".(min_version(9.6)?" WHERE amtype = 'i'":"")." ORDER BY amname = '".($this->conn->flavor=='cockroach'?"prefix":"btree")."' DESC, amname");return$I;}function
indexOpclasses(){static$I=[];if(!$I&&$this->conn->flavor!='cockroach')$I=get_vals("SELECT DISTINCT opcname FROM pg_catalog.pg_opclass WHERE NOT opcdefault ORDER BY opcname");return$I;}function
supportsIndex(array$S){return$S["Engine"]!="view";}function
hasCStyleEscapes(){static$hb;if($hb===null)$hb=(get_val("SHOW standard_conforming_strings",0,$this->conn)=="off");return$hb;}function
hasEstimatedRows(){return
true;}function
isSystem($k,$L=""){return($L!=""?information_schema($k,$L)||preg_match('~^pg_~',$L):in_array($k,["postgres","template1"])||($this->conn->flavor=='cockroach'&&$k=="system"));}}function
idf_escape($u){return'"'.str_replace('"','""',$u).'"';}function
table($u){return($_POST["schema_style"]===""&&$_GET["ns"]!=""?idf_escape($_GET["ns"]).".":"").idf_escape($u);}function
get_databases($se){return
get_vals("SELECT datname FROM pg_database
WHERE datallowconn = TRUE AND has_database_privilege(datname, 'CONNECT')
ORDER BY datname");}function
limit($G,$Z,$z,$C=0,$rl=" "){return" $G$Z".($z?$rl."LIMIT $z".($C?" OFFSET $C":""):"");}function
limit1($R,$G,$Z,$rl="\n"){return(preg_match('~^INTO~',$G)?limit($G,$Z,1,0,$rl):" $G".(is_view(table_status1($R))?$Z:$rl."WHERE (tableoid, ctid) = (SELECT tableoid, ctid FROM ".table($R).$Z.$rl."LIMIT 1)"));}function
db_collation($k,array$Ib){static$I;if($I===null)$I=get_key_vals("SELECT datname, datcollate FROM pg_database");return$I[$k];}function
logged_user(){return
get_val("SELECT user");}function
tables_list(){$G="SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = current_schema()";if(support("materializedview"))$G
.="
UNION ALL
SELECT matviewname, 'MATERIALIZED VIEW'
FROM pg_matviews
WHERE schemaname = current_schema()";$G
.="
ORDER BY 1";return
get_key_vals($G);}function
count_tables(array$j){$I=[];foreach($j
as$k){if(connection()->select_db($k))$I[$k]=count(tables_list());}return$I;}function
table_status($B="",$Yd=false){static$cf;if($cf===null)$cf=get_val("SELECT 'pg_table_size'::regproc");$vl=(!$Yd&&min_version(10));$I=[];foreach(get_rows("SELECT
	relname AS \"Name\",
	CASE relkind WHEN 'v' THEN 'view' WHEN 'm' THEN 'materialized view' ELSE 'table' END AS \"Engine\"".($cf?",
	pg_table_size(c.oid) AS \"Data_length\",
	pg_indexes_size(c.oid) AS \"Index_length\"":"").",
	obj_description(c.oid, 'pg_class') AS \"Comment\",
	".(min_version(12)?"''":"CASE WHEN relhasoids THEN 'oid' ELSE '' END")." AS \"Oid\",
	reltuples AS \"Rows\",
	EXISTS (SELECT 1 FROM pg_index WHERE indrelid = c.oid AND NOT indisvalid)::int AS invalid_index,
	".($vl?"seq.last_value":"NULL")." AS \"Auto_increment\"".(min_version(10)?",
	relispartition::int AS dependent":"")."
FROM pg_class c
".($vl?"LEFT JOIN (
	SELECT d.refobjid, max(s.last_value) AS last_value
	FROM pg_depend d
	JOIN pg_class sc ON sc.oid = d.objid AND sc.relkind = 'S' AND sc.relnamespace = ".driver()->nsOid."
	JOIN pg_sequences s ON s.schemaname = current_schema() AND s.sequencename = sc.relname
	WHERE d.classid = 'pg_class'::regclass AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')
	".($B!=""?"AND d.refobjid = ".driver()->tableOid($B):"")."
	GROUP BY d.refobjid
) seq ON seq.refobjid = c.oid
":"")."WHERE relkind IN ('r', 'm', 'v', 'f', 'p')
AND relnamespace = ".driver()->nsOid."
".($B!=""?"AND relname = ".q($B):"ORDER BY relname"))as$J)$I[$J["Name"]]=$J;return$I;}function
is_view(array$S){return
in_array($S["Engine"],["view","materialized view"]);}function
fk_support(array$S){return
true;}function
parse_full_type(array&$J){static$xa=['timestamp without time zone'=>'timestamp','timestamp with time zone'=>'timestamptz','time without time zone'=>'time','time with time zone'=>'timetz',];preg_match('~([^([]+)(\((.*)\))?([a-z ]+)?((\[[0-9]*])*)$~',$J["full_type"],$A);list(,$U,$y,$J["length"],$oa,$Ga)=$A;$J["length"].=$Ga;$vb=$U.$oa;if(isset($xa[$vb])){$J["type"]=$xa[$vb];$J["full_type"]=$J["type"].$y.$Ga;}else{$Bn=idf_unescape($U);$J["type"]=(is_user_type($Bn)?$Bn:$U);$J["full_type"]=$J["type"].$y.$oa.$Ga;}}function
fields($R){$I=[];foreach(get_rows("SELECT
	a.attname AS field,
	format_type(a.atttypid, a.atttypmod) AS full_type,
	pg_get_expr(d.adbin, d.adrelid) AS default,
	a.attnotnull::int,
	i.indrelid AS primary,
	t.typcategory,
	col_description(a.attrelid, a.attnum) AS comment".(min_version(10)?",
	a.attidentity".(min_version(12)?",
	a.attgenerated":""):"")."
FROM pg_attribute a
JOIN pg_type t ON t.oid = a.atttypid
LEFT JOIN pg_attrdef d ON a.attrelid = d.adrelid AND a.attnum = d.adnum
LEFT JOIN pg_index i ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) AND i.indisprimary
WHERE a.attrelid = ".driver()->tableOid($R)."
AND NOT a.attisdropped
AND a.attnum > 0
ORDER BY a.attnum")as$J){parse_full_type($J);$J["default_sql"]=($J["default"]!==null&&!$J["attidentity"]&&!$J["attgenerated"]&&!preg_match("~^(('(?:[^']|'')*'|NULL)(::[^,)]+)?|[-+]?[\\d.]+|true|false)$~s",$J["default"]));if(in_array($J['attidentity'],['a','d']))$J['default']='GENERATED '.($J['attidentity']=='d'?'BY DEFAULT':'ALWAYS').' AS IDENTITY';$J["generated"]=idx(["s"=>"STORED","v"=>"VIRTUAL"],$J["attgenerated"],"");$J["composite"]=($J["typcategory"]=="C");$J["null"]=!$J["attnotnull"];$J["auto_increment"]=$J['attidentity']||preg_match('~^nextval\(~i',$J["default"])||preg_match('~^unique_rowid\(~',$J["default"]);$J["privileges"]=["insert"=>1,"select"=>1,"update"=>1,"where"=>1,"order"=>1];if(!$J['generated']&&preg_match('~(.+)::[^,)]+(.*)~',$J["default"],$A)&&($A[2]!=""||preg_match("~^('.*'|NULL)\$~s",$A[1])))$J["default"]=($A[1]=="NULL"?null:idf_unescape($A[1]).$A[2]);$I[$J["field"]]=$J;}return$I;}function
indexes($R,$h=null){$h=connection($h);$I=[];$Am=driver()->tableOid($R);$e=get_key_vals("SELECT attnum, attname FROM pg_attribute WHERE attrelid = $Am AND attnum > 0",$h);foreach(get_rows("SELECT relname, indisunique::int, indisprimary::int, indisvalid::int, indkey, indoption, amname,
	pg_get_expr(indpred, indrelid, true) AS partial, pg_get_expr(indexprs, indrelid) AS indexpr".($h->flavor=='cockroach'?"":",
	(SELECT string_agg(CASE WHEN opcdefault THEN '' ELSE opcname END, ' ' ORDER BY s)
		FROM generate_subscripts(indclass, 1) AS s JOIN pg_catalog.pg_opclass ON pg_opclass.oid = indclass[s]) AS opclasses")."
FROM pg_index
JOIN pg_class ON indexrelid = oid
JOIN pg_am ON pg_am.oid = pg_class.relam
WHERE indrelid = $Am
ORDER BY indisprimary DESC, indisunique DESC",$h)as$J){$Ck=$J["relname"];$I[$Ck]["type"]=($J["indisprimary"]?"PRIMARY":($J["indisunique"]?"UNIQUE":"INDEX"));$I[$Ck]["columns"]=[];$I[$Ck]["descs"]=[];$I[$Ck]["algorithm"]=$J["amname"];$I[$Ck]["partial"]=$J["partial"];$I[$Ck]["invalid"]=!$J["indisvalid"];$Jf=preg_split('~(?<=\)), (?=\()~',$J["indexpr"]);foreach(explode(" ",$J["indkey"])as$Kf)$I[$Ck]["columns"][]=($Kf?$e[$Kf]:array_shift($Jf));foreach(explode(" ",$J["indoption"])as$Lf)$I[$Ck]["descs"][]=(intval($Lf)&1?'1':null);$I[$Ck]["opclasses"]=($J["opclasses"]!=""?explode(" ",$J["opclasses"]):[]);$I[$Ck]["lengths"]=[];}return$I;}function
foreign_keys($R){$I=[];foreach(get_rows("SELECT conname, condeferrable::int AS deferrable, condeferred::int AS deferred, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = ".driver()->tableOid($R)."
AND contype = 'f'::char
ORDER BY conkey, conname")as$J){$J['deferrable']=($J['deferrable']?'':'NOT ').'DEFERRABLE'.($J['deferred']?' INITIALLY DEFERRED':'');if(preg_match('~FOREIGN KEY\s*\((.+)\)\s*REFERENCES (.+)\((.+)\)(.*)$~iA',$J['definition'],$A)){$J['source']=array_map('Adminer\idf_unescape',array_map('trim',explode(',',$A[1])));if(preg_match('~^(("([^"]|"")+"|[^"]+)\.)?"?("([^"]|"")+"|[^"]+)$~',$A[2],$Wg)){$J['ns']=idf_unescape($Wg[2]);$J['table']=idf_unescape($Wg[4]);}$J['target']=array_map('Adminer\idf_unescape',array_map('trim',explode(',',$A[3])));$J['on_delete']=(preg_match("~ON DELETE (".driver()->onActions.")~",$A[4],$Wg)?$Wg[1]:'NO ACTION');$J['on_update']=(preg_match("~ON UPDATE (".driver()->onActions.")~",$A[4],$Wg)?$Wg[1]:'NO ACTION');$I[$J['conname']]=$J;}}return$I;}function
view($B){return["select"=>trim(get_val("SELECT pg_get_viewdef(".driver()->tableOid($B).")"))];}function
collations(){return(isset($_GET["database"])&&DB==""&&connection()->flavor!='cockroach'&&min_version(13)?get_vals("SELECT DISTINCT collcollate FROM pg_collation WHERE collprovider = 'c' ORDER BY 1"):[]);}function
information_schema($k,$L=""){$sm=["information_schema","pg_catalog","pg_toast"];if(connection()->flavor=='cockroach'){$sm[]="crdb_internal";$sm[]="pg_extension";}return
in_array($L!=""?$L:get_schema(),$sm);}function
error(){$I=h(connection()->error);if(preg_match('~^(.*\n)?([^\n]*)\n( *)\^(\n.*)?$~s',$I,$A))$I=$A[1].preg_replace('~((?:[^&]|&[^;]*;){'.strlen($A[3]).'})(.*)~','\1<b>\2</b>',$A[2]).$A[4];return
nl_br($I);}function
create_database($k,$Hb){return
queries("CREATE DATABASE ".idf_escape($k).($Hb?" TEMPLATE template0 LOCALE ".q($Hb):""));}function
drop_databases(array$j){connection()->close();return
apply_queries("DROP DATABASE",$j,'Adminer\idf_escape');}function
rename_database($B,$Hb){connection()->close();return!!queries("ALTER DATABASE ".idf_escape(DB)." RENAME TO ".idf_escape($B));}function
auto_increment(){return"";}function
alter_table($R,$B,array$o,array$ue,$f,$xd,$Hb,$Na,$tj){$b=[];$kk=[];if($R!=""&&$R!=$B)$kk[]="ALTER TABLE ".table($R)." RENAME TO ".table($B);$sl="";$Vi=($R!=""?fields($R):[]);foreach($o
as$n){$d=idf_escape($n[0]);$X=$n[1];if(!$X)$b[]="DROP $d";else{$Ui=$Vi[$n[0]];$Si=($Ui?process_field($Ui,$Ui):[]);$co=$X[5];unset($X[5]);if($n[0]==""){if(isset($X[6]))$X[1]=($X[1]==" bigint"?" big":($X[1]==" smallint"?" small":" "))."serial";$b[]=($R!=""?"ADD ":"  ").implode($X);if(isset($X[6]))$b[]=($R!=""?"ADD":" ")." PRIMARY KEY ($X[0])";}else{if($d!=$X[0])$kk[]="ALTER TABLE ".table($B)." RENAME $d TO $X[0]";if($X[1]!=$Si[1])$b[]="ALTER $d TYPE$X[1]";if($X[3]!=$Si[3]||isset($X[6])!=isset($Si[6])){$tl=$R."_".idf_unescape($X[0])."_seq";$b[]="ALTER $d ".($X[3]?"SET".preg_replace('~GENERATED ALWAYS(.*) (STORED|VIRTUAL)~','EXPRESSION\1',$X[3]):(isset($X[6])?"SET DEFAULT nextval(".q($tl).")":"DROP DEFAULT"));if(isset($X[6]))$sl="CREATE SEQUENCE IF NOT EXISTS ".idf_escape($tl)." OWNED BY ".idf_escape($R).".$X[0]";}if($X[2]!=$Si[2])$b[]="ALTER $d ".($X[2]==" NULL"?"DROP NOT":"SET").$X[2];}if($co!=$Si[5])$kk[]="COMMENT ON COLUMN ".table($B).".$X[0] IS ".($co!=""?substr($co,9):"''");}}if($R==""){$b=array_merge($b,$ue);$P="";if($tj){$Db=(connection()->flavor=='cockroach');$P=" PARTITION BY $tj[partition_by]($tj[partition])";if($tj["partition_by"]=='HASH'){$uj=+$tj["partitions"];for($s=0;$s<$uj;$s++)$kk[]="CREATE TABLE ".idf_escape($B."_$s")." PARTITION OF ".idf_escape($B)." FOR VALUES WITH (MODULUS $uj, REMAINDER $s)";}else{$Uj="MINVALUE";foreach($tj["partition_names"]as$s=>$X){$Y=$tj["partition_values"][$s];$oj=" VALUES ".($tj["partition_by"]=='LIST'?"IN ($Y)":"FROM ($Uj) TO ($Y)");if($Db)$P
.=($s?",":" (")."\n  PARTITION ".(preg_match('~^DEFAULT$~i',$X)?$X:idf_escape($X))."$oj";else$kk[]="CREATE TABLE ".idf_escape($B."_$X")." PARTITION OF ".idf_escape($B)." FOR$oj";$Uj=$Y;}$P
.=($Db?"\n)":"");}}array_unshift($kk,"CREATE TABLE ".table($B)." (\n".implode(",\n",$b)."\n)$P");}else{if($b)array_unshift($kk,"ALTER TABLE ".table($R)."\n".implode(",\n",$b));if($ue)$kk[]="ALTER TABLE ".table($B)."\n".implode(",\n",$ue);}if($sl)array_unshift($kk,$sl);if($f!==null)$kk[]="COMMENT ON TABLE ".table($B)." IS ".q($f);foreach($kk
as$G){if(!queries($G))return
false;}if($Na!=""){foreach(fields($B)as$ce=>$n){if($n["auto_increment"])return!!queries("SELECT setval(pg_get_serial_sequence(".q(table($B)).", ".q($ce)."), $Na)");}}return
true;}function
alter_indexes($R,$b){$i=[];$ed=[];$kk=[];$Rb=(support("concurrent_indexes")&&$_POST["concurrently"]?" CONCURRENTLY":"");foreach($b
as$X){if($X[0]=="UNIQUE"&&$X[2]=="DROP"&&!get_val("SELECT 1 FROM pg_constraint JOIN pg_class ON conindid = pg_class.oid WHERE conrelid = ".driver()->tableOid($R)." AND relname = ".q($X[1])))$X[0]="INDEX";if($X[0]!="INDEX")$i[]=($X[2]=="DROP"?"\nDROP CONSTRAINT ".idf_escape($X[1]):"\nADD".($X[1]!=""?" CONSTRAINT ".idf_escape($X[1]):"")." $X[0] ".($X[0]=="PRIMARY"?"KEY ":"")."(".implode(", ",$X[2]).")");elseif($X[2]=="DROP")$ed[]=idf_escape($X[1]);else$kk[]="CREATE INDEX$Rb ".idf_escape($X[1]!=""?$X[1]:uniqid($R."_"))." ON ".table($R).($X[3]?" USING $X[3]":"")." (".implode(", ",$X[2]).")".($X[4]?" WHERE $X[4]":"");}if($i)array_unshift($kk,"ALTER TABLE ".table($R).implode(",",$i));if($ed){$kk=array_merge(($Rb?array_map(function($u){return"DROP INDEX CONCURRENTLY $u";},$ed):["DROP INDEX ".implode(", ",$ed)]),$kk);}foreach($kk
as$G){if(!queries($G))return
false;}return
true;}function
truncate_tables(array$T){return!!queries("TRUNCATE ".implode(", ",array_map('Adminer\table',$T)));}function
drop_kinds(array$T){$I=["MATERIALIZED VIEW"=>[],"VIEW"=>[],"TABLE"=>[]];foreach($T
as$B=>$S)$I[strtoupper($S["Engine"])][]=table($B);return
array_filter($I);}function
drop_views(array$ko){return
drop_tables($ko);}function
drop_tables(array$T){$gm=[];foreach($T
as$R)$gm[$R]=table_status1($R);foreach(drop_kinds($gm)as$sg=>$Qh){if(!queries("DROP $sg ".implode(", ",$Qh)))return
false;}return
true;}function
move_tables(array$T,array$ko,$Lm){foreach(array_merge($T,$ko)as$R){$P=table_status1($R);if(!queries("ALTER ".strtoupper($P["Engine"])." ".table($R)." SET SCHEMA ".idf_escape($Lm)))return
false;}return
true;}function
trigger($B,$R){if($B=="")return["Statement"=>"EXECUTE PROCEDURE ()"];$e=[];$Z="WHERE trigger_schema = current_schema() AND event_object_table = ".q($R)." AND trigger_name = ".q($B);foreach(get_rows("SELECT * FROM information_schema.triggered_update_columns $Z")as$J)$e[]=$J["event_object_column"];$I=[];foreach(get_rows('SELECT trigger_name AS "Trigger", action_timing AS "Timing", event_manipulation AS "Event", \'FOR EACH \' || action_orientation AS "Type", action_statement AS "Statement"
FROM information_schema.triggers'."
$Z
ORDER BY event_manipulation DESC")as$J){if($e&&$J["Event"]=="UPDATE")$J["Event"].=" OF";$J["Of"]=implode(", ",$e);if($I)$J["Event"].=" OR $I[Event]";$I=$J;}return$I;}function
triggers($R){$I=[];$Ie=[];foreach(get_rows('SELECT t.tgname, r.routine_schema AS ns, r.specific_name AS function, r.routine_name AS name
FROM pg_catalog.pg_trigger t
JOIN information_schema.routines r ON substring(r.specific_name, \'[0-9]+$\')::oid = t.tgfoid
WHERE NOT t.tgisinternal AND t.tgrelid = (
	SELECT c.oid FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = '.q($R).'
)')as$J)$Ie[array_shift($J)]=$J;foreach(get_rows("SELECT * FROM information_schema.triggers WHERE trigger_schema = current_schema() AND event_object_table = ".q($R))as$J){$pn=trigger($J["trigger_name"],$R);$I[$pn["Trigger"]]=[$pn["Timing"],$pn["Event"]];if($Ie[$pn["Trigger"]])$I[$pn["Trigger"]][]=$Ie[$pn["Trigger"]];}return$I;}function
trigger_options(){return["Timing"=>["BEFORE","AFTER"],"Event"=>["INSERT","UPDATE","UPDATE OF","DELETE","INSERT OR UPDATE","INSERT OR UPDATE OF","DELETE OR INSERT","DELETE OR UPDATE","DELETE OR UPDATE OF","DELETE OR INSERT OR UPDATE","DELETE OR INSERT OR UPDATE OF",],"Type"=>["FOR EACH ROW","FOR EACH STATEMENT"],];}function
routine($B,$U){$Ki=routine_options($U);$ol=array_intersect_key(["VOLATILITY"=>"CASE p.provolatile WHEN 'i' THEN 'IMMUTABLE' WHEN 's' THEN 'STABLE' ELSE 'VOLATILE' END","NULL_INPUT"=>"CASE WHEN p.proisstrict THEN 'RETURNS NULL ON NULL INPUT' ELSE 'CALLED ON NULL INPUT' END","SECURITY"=>"CASE WHEN p.prosecdef THEN 'SECURITY DEFINER' ELSE 'SECURITY INVOKER' END","PARALLEL"=>"CASE p.proparallel WHEN 's' THEN 'PARALLEL SAFE' WHEN 'r' THEN 'PARALLEL RESTRICTED' ELSE 'PARALLEL UNSAFE' END",],$Ki);foreach($ol
as$x=>$M)$ol[$x]="$M AS \"$x\"";$K=get_rows('SELECT r.routine_definition AS definition, LOWER(r.external_language) AS language, '.($ol?implode(', ',$ol).', ':'').'r.*
FROM information_schema.routines r
LEFT JOIN pg_catalog.pg_proc p ON p.oid::text = substring(r.specific_name, \'[0-9]+$\')
WHERE r.routine_schema = current_schema() AND r.specific_name = '.q($B));if(!$K)return[];$I=$K[0];$I["definition"]=trim("$I[definition]","\r\n");$I["options"]=array_intersect_key($I,$Ki);$I["returns"]=["type"=>preg_replace('~^_(.*)~','\1[]',"$I[type_udt_name]")];$I["fields"]=get_rows("SELECT COALESCE(parameter_name, ordinal_position::text) AS field,
	CASE data_type WHEN 'USER-DEFINED' THEN udt_name WHEN 'ARRAY' THEN substr(udt_name, 2) || '[]' ELSE data_type END AS type,
	character_maximum_length AS length, parameter_mode AS inout
FROM information_schema.parameters
WHERE specific_schema = current_schema() AND specific_name = ".q($B)."
ORDER BY ordinal_position");return$I;}function
routines(){return
get_rows('SELECT specific_name AS "SPECIFIC_NAME", routine_type AS "ROUTINE_TYPE", routine_name AS "ROUTINE_NAME", type_udt_name AS "DTD_IDENTIFIER"
FROM information_schema.routines
WHERE routine_schema = current_schema()'.(connection()->flavor=='cockroach'?'':"
AND substring(specific_name, '[0-9]+\$')::oid NOT IN (SELECT objid FROM pg_catalog.pg_depend WHERE classid = 'pg_proc'::regclass AND deptype = 'e')").'
ORDER BY SPECIFIC_NAME');}function
routine_languages(){$I=[];foreach(get_vals("SELECT LOWER(lanname) FROM pg_catalog.pg_language")as$zg)$I[$zg]=(preg_match('~sql$~',$zg)?"pgsql":"txt");return$I;}function
routine_options($Qk){$Db=(connection()->flavor=='cockroach');$jl=($Db?[]:["SECURITY"=>["SECURITY INVOKER","SECURITY DEFINER"]]);if($Qk=="PROCEDURE")return$jl;return["VOLATILITY"=>["VOLATILE","STABLE","IMMUTABLE"],"NULL_INPUT"=>["CALLED ON NULL INPUT","RETURNS NULL ON NULL INPUT"],]+$jl+($Db?[]:["PARALLEL"=>["PARALLEL UNSAFE","PARALLEL RESTRICTED","PARALLEL SAFE"],]);}function
routine_id($B,array$J){$I=[];foreach($J["fields"]as$n){$y=$n["length"];$I[]=$n["type"].($y?"($y)":"");}return
idf_escape($B)."(".implode(", ",$I).")";}function
last_id($H){$J=(is_object($H)?$H->fetch_row():[]);return($J?$J[0]:0);}function
explain(Db$g,$G){return$g->query("EXPLAIN $G");}function
found_rows(array$S,array$Z){if(preg_match("~ rows=([0-9]+)~",get_val("EXPLAIN SELECT * FROM ".idf_escape($S["Name"]).($Z?" WHERE ".implode(" AND ",$Z):"")),$Bk))return$Bk[1];}function
types($Ud=false){$Db=connection()->flavor=='cockroach';$tg=($Db?"'e'":"'b','c','d','e'".(min_version(9.2)?",'r'":""));return
get_key_vals("SELECT t.oid, t.typname
FROM pg_type t
WHERE t.typnamespace = ".driver()->nsOid."
AND t.typtype IN ($tg)".($Db?"
AND t.typelem = 0":"
AND (t.typrelid = 0 OR (SELECT c.relkind FROM pg_class c WHERE c.oid = t.typrelid) = 'c')"."
AND NOT EXISTS (SELECT 1 FROM pg_type e WHERE e.typarray = t.oid)".($Ud?'':"
AND t.oid NOT IN (SELECT objid FROM pg_catalog.pg_depend WHERE classid = 'pg_type'::regclass AND deptype = 'e')"))."
ORDER BY t.typname");}function
type_values($t){$Ad=get_vals("SELECT enumlabel FROM pg_enum WHERE enumtypid = $t ORDER BY enumsortorder");return($Ad?"'".implode("', '",array_map('addslashes',$Ad))."'":"");}function
collation_name($si){return(min_version(9.1)?"(SELECT collname FROM pg_collation WHERE oid = $si AND collname != 'default')":"NULL");}function
type_definition($t){$U=first(get_rows("SELECT typtype, typisdefined::int AS defined, typrelid FROM pg_type WHERE oid = $t"));$I=["kind"=>($U?$U["typtype"]:""),"definition"=>""];if(!$U||!$U["defined"])return$I;switch($I["kind"]){case'e':$fo=get_vals("SELECT enumlabel FROM pg_enum WHERE enumtypid = $t ORDER BY enumsortorder");$I["definition"]="AS ENUM (".implode(", ",array_map('Adminer\q',$fo)).")";break;case'c':$e=[];foreach(get_rows("SELECT attname, format_type(atttypid, atttypmod) AS full_type, ".collation_name("attcollation")." AS collation
FROM pg_attribute
WHERE attrelid = $U[typrelid] AND attnum > 0 AND NOT attisdropped
ORDER BY attnum")as$J)$e[]=idf_escape($J["attname"])." $J[full_type]".($J["collation"]?" COLLATE ".idf_escape($J["collation"]):"");$I["definition"]="AS (\n\t".implode(",\n\t",$e)."\n)";break;case'd':$bd=first(get_rows("SELECT format_type(typbasetype, typtypmod) AS base, typnotnull::int AS notnull, typdefault, ".collation_name("typcollation")." AS collation
FROM pg_type WHERE oid = $t"));$I["definition"]="AS $bd[base]".($bd["collation"]?" COLLATE ".idf_escape($bd["collation"]):"").($bd["typdefault"]!=""?" DEFAULT $bd[typdefault]":"").($bd["notnull"]?" NOT NULL":"");foreach(get_rows("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE contypid = $t AND contype != 'n' ORDER BY conname")as$J)$I["definition"].=" CONSTRAINT ".idf_escape($J["conname"])." $J[definition]";break;case'r':$rk=first(get_rows("SELECT format_type(rngsubtype, NULL) AS subtype,
(SELECT opcname FROM pg_opclass WHERE oid = rngsubopc) AS subtype_opclass,
".collation_name("rngcollation")." AS collation,
NULLIF(rngcanonical, 0)::regproc::text AS canonical,
NULLIF(rngsubdiff, 0)::regproc::text AS subtype_diff".(min_version(14)?",
(SELECT typname FROM pg_type WHERE oid = rngmultitypid) AS multirange_type_name":"")."
FROM pg_range WHERE rngtypid = $t"));$Ki=[];foreach(["subtype"=>0,"subtype_opclass"=>1,"collation"=>1,"canonical"=>0,"subtype_diff"=>0,"multirange_type_name"=>1]as$x=>$Gd){if($rk[$x]!="")$Ki[]=strtoupper($x)." = ".($Gd?idf_escape($rk[$x]):$rk[$x]);}$I["definition"]="AS RANGE (".implode(", ",$Ki).")";}return$I;}function
schemas(){return
get_vals("SELECT nspname FROM pg_namespace ORDER BY nspname");}function
get_schema(){if($_GET["ns"]!="")return$_GET["ns"];return(string)get_val("SELECT current_schema()");}function
set_schema($L,$h=null){$_GET["ns"]=$L;$I=get_val("SELECT set_config('search_path', ".q(idf_escape($L)).", false) FROM pg_namespace WHERE nspname = ".q($L),0,$h);driver()->setUserTypes(types(true));return!!$I;}function
schema_comment($L){return(string)get_val("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = ".q($L));}function
set_schema_comment($L,$f){return!!queries("COMMENT ON SCHEMA ".idf_escape($L)." IS ".q($f));}function
drop_sql(array$T){$I="";foreach(drop_kinds($T)as$sg=>$Qh)$I
.="DROP $sg IF EXISTS ".implode(", ",$Qh).";\n";return($I?"$I\n":"");}function
foreign_keys_sql($R){$I="";$pe=foreign_keys($R);ksort($pe);foreach($pe
as$oe=>$ne){$I
.="ALTER TABLE ONLY ".table($R)." ADD CONSTRAINT ".idf_escape($oe)." ".preg_replace_callback('~( REFERENCES )([^(.]+)\(~',function(array$A){return$A[1].table(idf_unescape($A[2]))."(";},$ne["definition"]).";\n";}return($I?"$I\n":$I);}function
indexes_sql($R,$Xj=""){$I="";$G="SELECT indexdef, quote_ident(schemaname) || '.' || quote_ident(tablename) AS qualified, quote_ident(current_database()) AS db
FROM pg_catalog.pg_indexes
WHERE schemaname = current_schema() AND tablename = ".q($R).($Xj!=""?" AND indexname != ".q($Xj):"");foreach(get_rows($G,null,"-- ")as$J)$I
.="\n\n".str_replace([" $J[db].$J[qualified] USING "," $J[qualified] USING "]," ".table($R)." USING ",$J["indexdef"]).";";return$I;}function
create_sql($R,$Na,$km){$Lk=[];$vl=[];$wl=[];$ul=[];$P=table_status1($R);if(is_view($P)){$jo=view($R);$i="CREATE ".strtoupper($P["Engine"])." ".table($R)." AS ".rtrim($jo["select"],";").";";return
rtrim($i.indexes_sql($R),';');}$o=fields($R);if(count($P)<2||empty($o))return"";$I="CREATE TABLE ".table($P['Name'])." (\n    ";$zm=q(table($P['Name']));foreach($o
as$n){$xl="";if($n['default']=="nextval('$P[Name]_$n[field]_seq')"){$xl=table("$P[Name]_$n[field]_seq");$n['default']=null;$n['full_type']=preg_replace('~int(eger)?~','serial',$n['full_type']);}$mj=idf_escape($n['field']).' '.full_type_sql($n).preg_replace_callback('~(nextval\(\')([^.\']+)\'~',function(array$A){return$A[1].str_replace("'","''",table(idf_unescape($A[2])))."'";},default_value($n)).($n['null']?"":" NOT NULL");$Lk[]=$mj;if(preg_match('~nextval\(\'([^\']+)\'\)~',$n['default'],$Xg)){$tl=$Xg[1];$Ul=first(get_rows((min_version(10)?"SELECT *, cache_size AS cache_value FROM pg_sequences WHERE schemaname = current_schema() AND sequencename = ".q(idf_unescape($tl)):"SELECT * FROM $tl"),null,"-- "));$sl=table(idf_unescape($tl));$vl[]=($km=="DROP+CREATE"?"DROP SEQUENCE IF EXISTS $sl;\n":"")."CREATE SEQUENCE $sl INCREMENT $Ul[increment_by] MINVALUE $Ul[min_value] MAXVALUE $Ul[max_value]"." CACHE $Ul[cache_value];";if(get_val("SELECT pg_get_serial_sequence($zm, ".q($n['field']).")"))$wl[]="\n\nALTER SEQUENCE $sl OWNED BY ".table($P['Name']).".".idf_escape($n['field']).";";if($Na)$ul[]=$sl;}elseif($Na&&$n['auto_increment']){$sl=($xl?"":get_val("SELECT pg_get_serial_sequence($zm, ".q($n['field']).")::regclass"));$ul[]=($sl?table(idf_unescape($sl)):$xl);}}if(!empty($vl))$I=implode("\n\n",$vl)."\n\n$I";$Xj="";foreach(indexes($R)as$Hf=>$v){if($v['type']=='PRIMARY'){$Xj=$Hf;$Lk[]="CONSTRAINT ".idf_escape($Hf)." PRIMARY KEY (".implode(', ',array_map('Adminer\idf_escape',$v['columns'])).")";}}foreach(driver()->checkConstraints($R)as$Vb=>$Xb)$Lk[]="CONSTRAINT ".idf_escape($Vb)." CHECK ($Xb)";$I
.=implode(",\n    ",$Lk)."\n)";$oj=driver()->partitionsInfo($P['Name']);if($oj)$I
.="\nPARTITION BY $oj[partition_by]($oj[partition])";$I
.=(min_version(12)?"":"\nWITH (oids = ".($P['Oid']?'true':'false').")").";";$I
.=implode($wl);if($P['Comment'])$I
.="\n\nCOMMENT ON TABLE ".table($P['Name'])." IS ".q($P['Comment']).";";foreach($o
as$ce=>$n){if($n['comment'])$I
.="\n\nCOMMENT ON COLUMN ".table($P['Name']).".".idf_escape($ce)." IS ".q($n['comment']).";";}$I
.=indexes_sql($R,$Xj);foreach(array_filter($ul)as$sl){$Ul=first(get_rows("SELECT last_value, is_called::int FROM $sl",null,"-- "));if($Ul['is_called'])$I
.="\n\nDO \$\$ BEGIN PERFORM setval(".q($sl).", $Ul[last_value]); END \$\$;";}return
rtrim($I,';');}function
truncate_sql($R){return"TRUNCATE ".table($R);}function
truncate_all_sql(array$T){return($T?"TRUNCATE ".implode(", ",array_map('Adminer\table',$T)).";\n\n":"");}function
trigger_sql($R){$P=table_status1($R);$I="";foreach(triggers($R)as$on=>$nn){$pn=trigger($on,$P['Name']);$I
.="\nCREATE TRIGGER ".idf_escape($pn['Trigger'])." $pn[Timing] $pn[Event] ON ".table($P['Name'])." $pn[Type] $pn[Statement];;\n";}return$I;}function
use_sql($wc,$km=""){$B=idf_escape($wc);$I="";if(preg_match('~CREATE~',$km)){if($km=="DROP+CREATE")$I="DROP DATABASE IF EXISTS $B;\n";$I
.="CREATE DATABASE $B;\n";}return"$I\\connect $B";}function
use_schema_sql($L,$km){$B=idf_escape($L);$I="";if(preg_match('~CREATE~',$km)){if($km=="DROP+CREATE")$I="DROP SCHEMA IF EXISTS $B CASCADE;\n";$I
.="CREATE SCHEMA IF NOT EXISTS $B;\n";$f=schema_comment($L);if($f!="")$I
.="COMMENT ON SCHEMA $B IS ".q($f).";\n";}return$I."SET search_path TO $B";}function
show_variables(){return
get_rows("SHOW ALL");}function
process_list(){return
get_rows("SELECT * FROM pg_stat_activity ORDER BY ".(min_version(9.2)?"pid":"procpid"));}function
convert_field(array$n){if(preg_match('~^(geometry|geography)$~',$n["type"])&&strpos($n["full_type"],"[")===false)return"ST_AsEWKT(".idf_escape($n["field"]).")";}function
unconvert_field(array$n,$I){return($n["composite"]?"$I::".full_type_sql($n):$I);}function
support($Zd){return
preg_match('~^(check|columns|comment|database|drop_col|dump|descidx|fast_status|indexes|kill|partial_indexes|routine|scheme|sequence|sql|table|trigger|type|variables|view'.(min_version(9.3)?'|materializedview':'').(min_version(11)?'|procedure':'').(connection()->flavor=='cockroach'?'':'|deferrable').(connection()->flavor=='cockroach'?'':'|transaction_ddl').(connection()->flavor=='cockroach'||!min_version(9.1)?'':'|extension').(connection()->flavor=='cockroach'||!min_version(9.2)?'':'|concurrent_indexes').(connection()->flavor=='cockroach'?'':'|processlist').')$~',$Zd);}function
kill_process($t){return
queries("SELECT pg_terminate_backend(".number($t).")");}function
max_connections(){return
get_val("SHOW max_connections");}}add_driver("sqlite","SQLite");if(isset($_GET["sqlite"])){define('Adminer\DRIVER',"sqlite");if(class_exists("SQLite3")&&$_GET["ext"]!="pdo"){abstract
class
SqliteDb
extends
SqlDb{var$extension="SQLite3";private$link;function
attach(array$N,$V,$E){$this->link=new
\SQLite3($N["path"]);if(method_exists($this->link,'setAuthorizer'))$this->link->setAuthorizer([$this,'authorize']);$io=\SQLite3::version();$this->server_info=$io["versionString"];return'';}function
query($G,$An=false){$H=adminer()->executeQuery($G,function()use($G){return@$this->link->query($G);});$this->error="";if(!$H){$this->errno=$this->link->lastErrorCode();$this->error=$this->link->lastErrorMsg();return
false;}elseif($H->numColumns())return
new
Result($H);$this->affected_rows=$this->link->changes();return
true;}function
quote($Q){return(is_utf8($Q)?"'".$this->link->escapeString($Q)."'":"x'".bin2hex($Q)."'");}}class
Result{var$num_rows;private$result,$offset=0;function
__construct($H){$this->result=$H;}function
fetch_assoc(){return$this->result->fetchArray(SQLITE3_ASSOC);}function
fetch_row(){return$this->result->fetchArray(SQLITE3_NUM);}function
fetch_field(){$_n=[1=>"integer","real","text","blob","null"];$d=$this->offset++;return(object)["name"=>$this->result->columnName($d),"native_type"=>$_n[$this->result->columnType($d)],];}}}elseif(extension_loaded("pdo_sqlite")){abstract
class
SqliteDb
extends
PdoDb{var$extension="PDO_SQLite";function
attach(array$N,$V,$E){$I=$this->dsn(DRIVER.":".$N["path"],"","",[],(class_exists('Pdo\Sqlite')?'Pdo\Sqlite':'PDO'));if(!$I&&method_exists($this->pdo,'setAuthorizer'))$this->pdo->setAuthorizer([$this,'authorize']);return$I;}function
quote($Q){return(is_utf8($Q)?parent::quote($Q):"x'".bin2hex($Q)."'");}function
store_result($H=null){$ua=$this->affected_rows;$I=parent::store_result($H);if(!$H&&$I===true)$this->affected_rows=$ua;return$I;}}}if(class_exists('Adminer\SqliteDb')){class
Db
extends
SqliteDb{private$attaching=false;private$total_changes=0;function
attach(array$N,$V,$E){parent::attach($N,$V,$E);$this->total_changes=0;$this->query("PRAGMA foreign_keys = 1");$this->query("PRAGMA busy_timeout = 500");return'';}function
query($G,$An=false){$I=parent::query($G,$An);if($this->affected_rows){$H=parent::query("SELECT total_changes()");$J=($H?$H->fetch_row():false);if($J){if($J[0]==$this->total_changes)$this->affected_rows=0;$this->total_changes=$J[0];}}return$I;}function
select_db($p){$G="ATTACH ".$this->quote(preg_match("~(^[/\\\\]|:)~",$p)?$p:dirname($_SERVER["SCRIPT_FILENAME"])."/$p")." AS a";$this->attaching=true;$Ka=is_readable($p)&&$this->query($G);$this->attaching=false;if($Ka)return!self::attach(server_parts(["path"=>$p]),'','');return
false;}function
authorize($ka,$Ea){return($ka!=24||$Ea===''||$this->attaching?0:1);}}}class
Driver
extends
SqlDriver{static$extensions=["SQLite3","PDO_SQLite"];static$jush="sqlite";static$passwords=false;static$serverFile=true;protected$types=[["integer"=>0,"real"=>0,"numeric"=>0,"text"=>0,"blob"=>0]];var$insertFunctions=["date"=>"current_date","time"=>"current_timestamp",];var$editFunctions=["^(?!.*(char|clob|text|blob))."=>"+/-","char|clob|text"=>"||",];var$fulltextOperator="MATCH";var$functions=["hex","length","lower","round","unixepoch","upper"];var$grouping=["avg","count","count distinct","group_concat","max","min","sum"];function
operators($um){$I=["=","<",">","<=",">=","!=","&","LIKE","LIKE %%","IN","BETWEEN","IS NULL","NOT LIKE","NOT IN","IS NOT NULL"];if(preg_match('~^fts\d+$~i',(string)idx($um,"Engine")))$I[]="MATCH";$I[]="SQL";return$I;}static
function
connect($N,$V,$E){return
parent::connect(":memory:","","");}function
__construct(Db$g){parent::__construct($g);if(min_version(3.31,0,$g))$this->generated=["STORED","VIRTUAL"];if(min_version(3.37,0,$g))$this->types[0]["any"]=0;}function
structuredTypes(){return
array_keys($this->types[0]);}function
quoteBinary($Zk){return"x".q(bin2hex($Zk));}function
typeName(\stdClass$n){$I=strtolower(idx((array)$n,'sqlite:decl_type',parent::typeName($n)));return
idx(["string"=>"text","double"=>"real"],$I,$I);}function
engines(){$I=["table"];if(min_version("3.8.2")){if(min_version(3.37)){$I[]="STRICT";$I[]="STRICT, WITHOUT ROWID";}$I[]="WITHOUT ROWID";}return$I;}private
function
isVirtual(array$S){$xd=$S["Engine"];return$xd!=""&&!in_array($xd,array_merge(["view"],$this->engines()));}function
supportsIndex(array$S){return!is_view($S)&&!$this->isVirtual($S);}function
supportsAlterIndex(array$S){return$this->supportsIndex($S);}function
supportsAlterTable(array$um){return!$this->isVirtual($um);}function
shadowTables($R){$I=[];if(min_version(3.37)){foreach(get_vals("SELECT name FROM pragma_table_list WHERE schema = 'main' AND type = 'shadow' ORDER BY name")as$B){if(preg_match('(^'.preg_quote($R).'_[^_]*$)',$B))$I[]=["table"=>$B,"ns"=>""];}}return$I;}function
fulltextSql($B,array$v,$G,$cb){return
idf_escape($B)." MATCH ".q($G);}function
insertUpdate($R,array$K,array$Xj){$fo=[];foreach($K
as$O)$fo[]="(".implode(", ",$O).")";return
queries("REPLACE INTO ".table($R)." (".implode(", ",array_keys(reset($K))).") VALUES\n".implode(",\n",$fo));}function
tableHelp($B,$hg=false){if(preg_match('~^sqlite_(seq|stat.)~',$B,$A))return"fileformat2.html#$A[1]tab";if(preg_match('~^sqlite(_temp)?_(master|schema)$~',$B))return"schematab.html";}function
checkConstraints($R){$i=get_val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ".q($R),0,$this->conn);preg_match_all('~\s(?:CONSTRAINT\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^]]*]|\S+)\s+)?CHECK\s*(\(((?>[^()]+)|(?2))*\))~',$i,$Xg,PREG_SET_ORDER);$I=[];foreach($Xg
as$A){$Cb=trim(substr($A[2],1,-1));$I[$A[1]!=""?idf_unescape($A[1]):$Cb]=$Cb;}return$I;}function
allFields(){$I=[];if(min_version(3.16)){$K=get_rows('SELECT m.name AS tab, p.name AS field, p.type, p."notnull", p.pk AS '.idf_escape("primary")."
FROM sqlite_master m, pragma_table_".(min_version(3.31)?"x":"")."info(m.name) p
WHERE m.type IN ('table', 'view')".(min_version(3.31)?"
AND p.hidden != 1":"").(min_version(3.37)?"
AND m.name NOT IN (SELECT name FROM pragma_table_list WHERE type = 'shadow')":"")."
ORDER BY (m.name LIKE 'sqlite_%'), m.name, p.cid",$this->conn);foreach($K
as$J){$J["type"]=type_affinity($J["type"]);$J["null"]=!$J["notnull"];$I[$J["tab"]][]=$J;}}else$I=parent::allFields();return$I;}}function
idf_escape($u){return'"'.str_replace('"','""',$u).'"';}function
table($u){return
idf_escape($u);}function
get_databases($se){return[];}function
limit($G,$Z,$z,$C=0,$rl=" "){return" $G$Z".($z?$rl."LIMIT $z".($C?" OFFSET $C":""):"");}function
limit1($R,$G,$Z,$rl="\n"){return(preg_match('~^INTO~',$G)||get_val("SELECT sqlite_compileoption_used('ENABLE_UPDATE_DELETE_LIMIT')")?limit($G,$Z,1,0,$rl):" $G WHERE rowid = (SELECT rowid FROM ".table($R).$Z.$rl."LIMIT 1)");}function
db_collation($k,array$Ib){return
get_val("PRAGMA encoding");}function
logged_user(){return
get_current_user();}function
virtual_module($Vl){return(preg_match('~^CREATE\s+VIRTUAL\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"[^"]*+"|`[^`]*+`|\[[^\]]*+\]|[^\s(]+)\s+USING\s+([a-z0-9_]+)~i',$Vl,$A)?$A[1]:"");}function
parse_comments($Vl){$I=[];if(virtual_module($Vl)!="")return$I;preg_match_all('~\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^]]*]|--[^\n]*|/\*.*?\*/|[(),\n]|[^-/\'"`[(),\n]+|[-/]~s',$Vl,$Xg);$Jc=0;$Qh=[];$_g="";$di=true;foreach($Xg[0]as$dn){if(preg_match('~^--~',$dn)){if($Jc==1&&!$di){$B=($_g=="("?"":($_g==","?idx($Qh,count($Qh)-2):end($Qh)));if(is_string($B))$I[$B]=trim(substr($dn,2));}}elseif($dn=="\n")$di=true;elseif(trim($dn)!=""&&!preg_match('~^/\*~',$dn)){$di=false;$_g=($Jc==1&&$dn==","?",":($Jc==0&&$dn=="("?"(":""));if($_g)$Qh[]=false;elseif($Jc==1&&end($Qh)===false&&$dn!="("&&$dn!=")"){$pk=preg_match('~^[\'"`[]~',$dn);$wo=($pk?idf_unescape($dn):preg_replace('~\s.*~s','',trim($dn)));$Qh[count($Qh)-1]=($pk||!preg_match('~^(CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN)$~i',$wo)?$wo:null);}$Jc+=($dn=="("?1:($dn==")"?-1:0));}}return$I;}function
tables_list(){return
get_key_vals("SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view')".(min_version(3.37)?" AND name NOT IN (SELECT name FROM pragma_table_list WHERE type = 'shadow')":"")."
ORDER BY (name LIKE 'sqlite_%'), name");}function
count_tables(array$j){return[];}function
db_status(){$fj=get_val("PRAGMA page_size");$Be=get_val("PRAGMA freelist_count")*$fj;return["Data_length"=>get_val("PRAGMA page_count")*$fj-$Be,"Index_length"=>0,"Data_free"=>$Be,];}function
table_status($B="",$Yd=false){$I=[];$K=[];if(!$Yd&&$B==""){connection()->query("PRAGMA optimize = 0x10002");$K=get_key_vals("SELECT tbl, MAX(CAST(stat AS integer)) FROM sqlite_stat1 GROUP BY tbl");}foreach(get_rows("SELECT name AS Name, type AS Engine, sql, 'rowid' AS Oid, '' AS Auto_increment".(min_version(3.37)?", name IN (SELECT name FROM pragma_table_list WHERE type = 'shadow') AS dependent":"")." FROM sqlite_master WHERE type IN ('table', 'view') ".($B!=""?"AND name = ".q($B):"ORDER BY (name LIKE 'sqlite_%'), name"))as$J){if($J["Engine"]=="table"){$Vl=preg_replace('~(?:\s|--[^\n]*|/\*.*?\*/)+$~s','',$J["sql"]);$mm=preg_replace('~.*\)~s','',$Vl);$J["Engine"]=virtual_module($J["sql"])?:(implode(", ",array_filter([(preg_match('~\bSTRICT\b~i',$mm)?"STRICT":0),(preg_match('~\bWITHOUT\s+ROWID\b~i',$mm)?"WITHOUT ROWID":0),]))?:"table");$J["Comment"]=idx(parse_comments($J["sql"]),"","");}unset($J["sql"]);$J["Rows"]=idx($K,$J["Name"],0);$I[$J["Name"]]=$J;}if(!$Yd){foreach(get_rows("SELECT * FROM sqlite_sequence".($B!=""?" WHERE name = ".q($B):""),null,"")as$J)$I[$J["name"]]["Auto_increment"]=$J["seq"];}return$I;}function
is_view(array$S){return$S["Engine"]=="view";}function
fk_support(array$S){return!get_val("SELECT sqlite_compileoption_used('OMIT_FOREIGN_KEY')");}function
type_affinity($U){$U=strtolower($U);return(preg_match('~int~i',$U)?"integer":(preg_match('~char|clob|text~i',$U)?"text":(preg_match('~blob~i',$U)?"blob":(preg_match('~real|floa|doub~i',$U)?"real":(preg_match('~any~i',$U)?"any":"numeric")))));}function
fields($R){$I=[];$Vl=get_val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ".q($R));$ck=["select"=>1,"where"=>1,"order"=>1];if(!preg_match('~^sqlite(_temp)?_(master|schema)$~',$R))$ck+=["insert"=>1,"update"=>1];$De=preg_match('~^fts\d+$~i',virtual_module($Vl));$Pb=parse_comments($Vl);foreach(get_rows("PRAGMA table_".(min_version(3.31)?"x":"")."info(".table($R).")")as$J){if($J["hidden"]==1)continue;$B=$J["name"];$U=strtolower($J["type"]);$l=$J["dflt_value"];preg_match('~^(.+?)\s*(?:\((.*)\))?$~s',$U,$wj);$pk=preg_match("~^'(?:[^']|'')*'$~",$l);$I[$B]=["field"=>$B,"type"=>($De?"text":($wj?$wj[1]:type_affinity($U))),"full_type"=>$U,"length"=>($De?"":idx($wj,2,"")),"default"=>($pk?idf_unescape($l):($l=="NULL"?null:$l)),"default_sql"=>($l!==null&&!$pk&&$l!="NULL"&&!is_numeric($l)),"null"=>!$J["notnull"],"privileges"=>$ck,"primary"=>$J["pk"],"comment"=>idx($Pb,$B,""),];if($J["pk"]&&preg_match('~\bAUTOINCREMENT\b~i',$Vl))$I[$B]["auto_increment"]=true;}$u='[(,]\s*(("[^"]*+")+|[a-z0-9_]+)';$Gk='(?:[^,()\']|\'[^\']*+\'|\([^)]*+\))*?';preg_match_all('~'.$u.'\s+text\b'.$Gk.'COLLATE\s+(\'[^\']+\'|[a-z0-9_]+)~i',$Vl,$Xg,PREG_SET_ORDER);foreach($Xg
as$A){$B=idf_unescape($A[1]);if($I[$B])$I[$B]["collation"]=trim($A[3],"'");}preg_match_all('~'.$u.'\s'.$Gk.'GENERATED\s+ALWAYS\s+AS\s*\((.+?)\)\s+(STORED|VIRTUAL)~i',$Vl,$Xg,PREG_SET_ORDER);foreach($Xg
as$A){$B=idf_unescape($A[1]);if($I[$B]){$I[$B]["default"]=$A[3];$I[$B]["generated"]=strtoupper($A[4]);}}return$I;}function
indexes($R,$h=null){$h=connection($h);$I=[];$Vl=get_val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ".q($R),0,$h);if(preg_match('~^fts\d+$~i',virtual_module($Vl)))return[$R=>["type"=>"FULLTEXT","columns"=>array_keys(fields($R)),"lengths"=>[],"descs"=>[]]];if(preg_match('~\bPRIMARY\s+KEY\s*\((([^)"]+|"[^"]*"|`[^`]*`)++)~i',$Vl,$A)){$I[""]=["type"=>"PRIMARY","columns"=>[],"lengths"=>[],"descs"=>[]];preg_match_all('~((("[^"]*+")+|(?:`[^`]*+`)+)|(\S+))(\s+(ASC|DESC))?(,\s*|$)~i',$A[1],$Xg,PREG_SET_ORDER);foreach($Xg
as$A){$I[""]["columns"][]=idf_unescape($A[2]).$A[4];$I[""]["descs"][]=(preg_match('~DESC~i',$A[5])?'1':null);}}if(!$I){foreach(get_rows("PRAGMA table_info(".table($R).")",$h)as$J){if($J["pk"])$I[""]=["type"=>"PRIMARY","columns"=>[$J["name"]],"lengths"=>[],"descs"=>[null]];}}$am=get_key_vals("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ".q($R),$h);foreach(get_rows("PRAGMA index_list(".table($R).")",$h)as$J){$B=$J["name"];$v=["type"=>($J["unique"]?"UNIQUE":"INDEX")];$v["lengths"]=[];$v["descs"]=[];foreach(get_rows("PRAGMA index_info(".idf_escape($B).")",$h)as$Xk){$v["columns"][]=$Xk["name"];$v["descs"][]=null;}if(preg_match('~^CREATE( UNIQUE)? INDEX '.preg_quote(idf_escape($B).' ON '.idf_escape($R),'~').' \((.*)\)$~i',$am[$B],$Bk)){preg_match_all('/("[^"]*+")+( DESC)?/',$Bk[2],$Xg);foreach($Xg[2]as$x=>$X){if($X)$v["descs"][$x]='1';}}if(!$I[""]||$v["type"]!="UNIQUE"||$v["columns"]!=$I[""]["columns"]||$v["descs"]!=$I[""]["descs"]||!preg_match("~^sqlite_~",$B))$I[$B]=$v;}return$I;}function
foreign_keys($R){$I=[];foreach(get_rows("PRAGMA foreign_key_list(".table($R).")")as$J){$q=&$I[$J["id"]];if(!$q)$q=$J;$q["source"][]=$J["from"];$q["target"][]=$J["to"];}return$I;}function
view($B){return["select"=>preg_replace('~^(?:[^`"[]+|`[^`]*`|"[^"]*")* AS\s+(?=\S)~iU','',get_val("SELECT sql FROM sqlite_master WHERE type = 'view' AND name = ".q($B)))];}function
collations(){return(isset($_GET["create"])?get_vals("PRAGMA collation_list",1):[]);}function
information_schema($k,$L=""){return
false;}function
error(){return
h(connection()->error);}function
check_sqlite_name($B){$Ud="db|sdb|sqlite";if(!preg_match("~^[^\\0]*\\.($Ud)\$~",$B)){connection()->error=lang(42,str_replace("|",", ",$Ud));return
false;}return
true;}function
create_database($k,$Hb){if(file_exists($k)){connection()->error=lang(43);return
false;}if(!check_sqlite_name($k))return
false;try{$_=new
Db();$_->attach(server_parts(["path"=>$k]),'','');}catch(\Exception$Kd){connection()->error=$Kd->getMessage();return
false;}$_->query('PRAGMA encoding = "UTF-8"');$_->query('CREATE TABLE adminer (i)');$_->query('DROP TABLE adminer');return
true;}function
drop_databases(array$j){connection()->attach(server_parts(["path"=>":memory:"]),'','');foreach($j
as$k){if(!check_sqlite_name($k))return
false;if(!@unlink($k)){connection()->error=lang(43);return
false;}}return
true;}function
rename_database($B,$Hb){if(!check_sqlite_name($B))return
false;connection()->attach(server_parts(["path"=>":memory:"]),'','');connection()->error=lang(43);return@rename(DB,$B);}function
auto_increment(){return" PRIMARY KEY AUTOINCREMENT";}function
alter_table($R,$B,array$o,array$ue,$f,$xd,$Hb,$Na,$tj){$Sn=($R==""||$ue||$xd||$f!==null);foreach($o
as$n){if($n[0]!=""||!$n[1]||$n[2]||$n[1][5]){$Sn=true;break;}}$b=[];$Zi=[];foreach($o
as$n){if($n[1]){$b[]=($Sn?$n[1]:"ADD ".implode($n[1]));if($n[0]!="")$Zi[$n[0]]=$n[1][0];}}if(!$Sn){foreach($b
as$X){if(!queries("ALTER TABLE ".table($R)." $X"))return
false;}if($R!=$B&&!queries("ALTER TABLE ".table($R)." RENAME TO ".table($B)))return
false;}elseif(!recreate_table($R,$B,$b,$Zi,$ue,$Na,[],"","",$xd,$f))return
false;if($Na){queries("BEGIN");queries("UPDATE sqlite_sequence SET seq = $Na WHERE name = ".q($B));if(!connection()->affected_rows)queries("INSERT INTO sqlite_sequence (name, seq) VALUES (".q($B).", $Na)");queries("COMMIT");}return
true;}function
recreate_table($R,$B,array$o,array$Zi,array$ue,$Na="",$w=[],$fd="",$na="",$xd="",$f=null){if($R!=""){if(!$o){foreach(fields($R)as$x=>$n){if($w)$n["auto_increment"]=0;$o[]=process_field($n,$n);$Zi[$x]=idf_escape($x);}}$Yj=false;foreach($o
as$n){if($n[6])$Yj=true;}$hd=[];foreach($w
as$x=>$X){if($X[2]=="DROP"){$hd[$X[1]]=true;unset($w[$x]);}}foreach(indexes($R)as$pg=>$v){$e=[];foreach($v["columns"]as$x=>$d){if(!$Zi[$d])continue
2;$e[]=$Zi[$d].($v["descs"][$x]?" DESC":"");}if(!$hd[$pg]){if($v["type"]!="PRIMARY"||!$Yj)$w[]=[$v["type"],$pg,$e];}}foreach($w
as$x=>$X){if($X[0]=="PRIMARY"){unset($w[$x]);$ue[]="  PRIMARY KEY (".implode(", ",$X[2]).")";}elseif(preg_match('~^sqlite_autoindex_~',$X[1])){unset($w[$x]);$ue[]="  UNIQUE (".implode(", ",$X[2]).")";}}foreach(foreign_keys($R)as$pg=>$q){foreach($q["source"]as$x=>$d){if(!$Zi[$d])continue
2;$q["source"][$x]=idf_unescape($Zi[$d]);}if(!isset($ue[" $pg"]))$ue[]=" ".format_foreign_key($q);}queries("PRAGMA foreign_keys = 0");queries("PRAGMA legacy_alter_table = 1");queries("BEGIN");}$pb=[];$Pb=[];foreach($o
as$n){if(preg_match('~GENERATED~',$n[3]))unset($Zi[array_search($n[0],$Zi)]);if(preg_match("~^ COMMENT ('.*')$~s",$n[5],$A))$Pb[count($pb)]=idf_unescape($A[1]);unset($n[5]);$pb[]="  ".implode($n);}$pb=array_merge($pb,array_values(array_filter($ue)));foreach(driver()->checkConstraints($R)as$x=>$tb){if($x!=$fd)$pb[]=" ".($x!=$tb?" CONSTRAINT ".idf_escape($x):"")." CHECK ($tb)";}if($na)$pb[]="  $na";$Pm=($R!=""&&$R==$B?"adminer_$B":$B);if($R!=""&&(!$xd||$f===null)){$P=table_status1($R);$xd=$xd?:idx($P,"Engine");$f=($f===null?idx($P,"Comment",""):$f);}$Mg=function($Rm){return($Rm!=""?" -- ".preg_replace('~[\r\n]+~',' ',$Rm):"");};$i="CREATE TABLE ".table($Pm)." (".$Mg($f)."\n";foreach($pb
as$s=>$ob)$i
.=$ob.($s<count($pb)-1?",":"").$Mg(idx($Pb,$s))."\n";if(!queries("$i)".($xd!="table"&&in_array($xd,driver()->engines())?" $xd":"")))return
false;if($R!=""){if($Zi&&!queries("INSERT INTO ".table($Pm)." (".implode(", ",$Zi).") SELECT ".implode(", ",array_map('Adminer\idf_escape',array_keys($Zi)))." FROM ".table($R)))return
false;$tn=[];foreach(triggers($R)as$rn=>$Wm){$pn=trigger($rn,$R);$tn[]="CREATE TRIGGER ".idf_escape($rn)." ".implode(" ",$Wm)." ON ".table($B)."\n$pn[Statement]";}$Na=$Na?"":get_val("SELECT seq FROM sqlite_sequence WHERE name = ".q($R));if(!queries("DROP TABLE ".table($R))||($R==$B&&!queries("ALTER TABLE ".table($Pm)." RENAME TO ".table($B)))||!alter_indexes($B,$w))return
false;if($Na)queries("UPDATE sqlite_sequence SET seq = $Na WHERE name = ".q($B));foreach($tn
as$pn){if(!queries($pn))return
false;}if(get_rows("PRAGMA foreign_key_check(".table($B).")")){connection()->error="FOREIGN KEY constraint failed";return
false;}queries("COMMIT");queries("PRAGMA foreign_keys = 1");queries("PRAGMA legacy_alter_table = 0");}return
true;}function
index_sql($R,$U,$B,$e){return"CREATE $U ".($U!="INDEX"?"INDEX ":"").idf_escape($B!=""?$B:uniqid($R."_"))." ON ".table($R)." $e";}function
alter_indexes($R,$b){foreach($b
as$Xj){if($Xj[0]=="PRIMARY")return
recreate_table($R,$R,[],[],[],"",$b);}foreach(array_reverse($b)as$X){if(!queries($X[2]=="DROP"?"DROP INDEX ".idf_escape($X[1]):index_sql($R,$X[0],$X[1],"(".implode(", ",$X[2]).")")))return
false;}return
true;}function
truncate_tables(array$T){return
apply_queries("DELETE FROM",$T);}function
drop_views(array$ko){return
apply_queries("DROP VIEW",$ko);}function
drop_tables(array$T){return
apply_queries("DROP TABLE",$T);}function
move_tables(array$T,array$ko,$Lm){return
false;}function
trigger($B,$R){if($B=="")return["Statement"=>"BEGIN\n\t;\nEND"];$u='(?:[^`"\s]+|`[^`]*`|"[^"]*")+';$sn=trigger_options();preg_match("~^CREATE\\s+TRIGGER\\s*$u\\s*(".implode("|",$sn["Timing"]).")\\s+([a-z]+)(?:\\s+OF\\s+($u))?\\s+ON\\s*$u\\s*(?:FOR\\s+EACH\\s+ROW\\s+)?(.*)~is",get_val("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ".q($B)),$A);if(!$A)return[];$ni=$A[3];return["Timing"=>strtoupper($A[1]),"Event"=>strtoupper($A[2]).($ni?" OF":""),"Of"=>idf_unescape($ni),"Trigger"=>$B,"Statement"=>$A[4],];}function
triggers($R){$I=[];$sn=trigger_options();foreach(get_rows("SELECT * FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ".q($R))as$J){preg_match('~^CREATE\s+TRIGGER\s*(?:[^`"\s]+|`[^`]*`|"[^"]*")+\s*('.implode("|",$sn["Timing"]).')\s*(.*?)\s+ON\b~i',$J["sql"],$A);$I[$J["name"]]=[$A[1],$A[2]];}return$I;}function
trigger_options(){return["Timing"=>["BEFORE","AFTER","INSTEAD OF"],"Event"=>["INSERT","UPDATE","UPDATE OF","DELETE"],"Type"=>["FOR EACH ROW"],];}function
last_id($H){return
get_val("SELECT LAST_INSERT_ROWID()");}function
explain(Db$g,$G){return$g->query("EXPLAIN QUERY PLAN $G");}function
found_rows(array$S,array$Z){}function
types($Ud=false){return[];}function
create_sql($R,$Na,$km){$I=get_val("SELECT sql FROM sqlite_master WHERE type IN ('table', 'view') AND name = ".q($R));foreach(indexes($R)as$B=>$v){if($B==''||$v['type']=='FULLTEXT')continue;$I
.=";\n\n".index_sql($R,$v['type'],$B,"(".implode(", ",array_map('Adminer\idf_escape',$v['columns'])).")");}return$I;}function
truncate_sql($R){return"DELETE FROM ".table($R);}function
use_sql($wc,$km=""){return"";}function
trigger_sql($R){return
implode(get_vals("SELECT sql || ';;\n' FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ".q($R)));}function
show_variables(){$I=[];foreach(get_rows("PRAGMA pragma_list")as$J){$B=$J["name"];if($B!="pragma_list"&&$B!="compile_options"){$I[$B]=[$B,''];foreach(get_rows("PRAGMA $B")as$J)$I[$B][1].=implode(", ",$J)."\n";}}return$I;}function
show_status(){$I=[];foreach(get_vals("PRAGMA compile_options")as$Ii)$I[]=explode("=",$Ii,2)+['',''];return$I;}function
convert_field(array$n){}function
unconvert_field(array$n,$I){return$I;}function
support($Zd){return
preg_match('~^(check|columns|comment|database|drop_col|dump|fast_status|indexes|descidx|move_col|sql|status|table|transaction_ddl|trigger|variables|view|view_trigger)$~',$Zd);}}add_driver("mssql","MS SQL");if(isset($_GET["mssql"])){define('Adminer\DRIVER',"mssql");if(extension_loaded("sqlsrv")&&$_GET["ext"]!="pdo"){class
Db
extends
SqlDb{var$extension="SQLSRV";var$timeout=0;private$link,$result,$warnings,$transaction=false;private$query_timeout=0;private
function
get_error(){$this->error="";foreach(sqlsrv_errors()as$m){$this->errno=$m["code"];$this->error
.="$m[message]\n";}$this->error=rtrim($this->error);}function
attach(array$N,$V,$E){sqlsrv_configure("WarningsReturnAsErrors",0);$Wb=["UID"=>$V,"PWD"=>$E,"CharacterSet"=>"UTF-8","ReturnDatesAsStrings"=>true];if(isset($_GET["sql"])&&!self::$instance)$Wb["MultipleActiveResultSets"]=false;$bm=adminer()->connectSsl();if(isset($bm["Encrypt"]))$Wb["Encrypt"]=$bm["Encrypt"];if(isset($bm["TrustServerCertificate"]))$Wb["TrustServerCertificate"]=$bm["TrustServerCertificate"];$k=adminer()->database();if($k!="")$Wb["Database"]=$k;$Kj=$N["port"];$this->link=@sqlsrv_connect($N["host"].($Kj?",$Kj":""),$Wb);if($this->link){$Mf=sqlsrv_server_info($this->link);$this->server_info=$Mf['SQLServerVersion'];}else$this->get_error();return($this->link?'':$this->error);}function
quote($Q){return
unicode_prefix($Q)."'".str_replace("'","''",$Q)."'";}function
select_db($wc){return$this->query(use_sql($wc));}function
query($G,$An=false){$H=$this->execute($G);$this->error="";if(!$H){$this->get_error();return
false;}return$this->store_result($H);}function
multi_query($G){$this->result=$this->execute($G);$this->error="";if(!$this->result){$this->get_error();return
false;}return
true;}private
function
execute($G){return
adminer()->executeQuery($G,function()use($G){return
sqlsrv_query($this->link,$G,[],$this->options());});}private
function
options(){$I=["QueryTimeout"=>($this->timeout?:$this->query_timeout)];$this->timeout=0;return$I;}function
timeout($Gh){$this->query_timeout=(int)ceil($Gh/1000);return
true;}function
store_result($H=null){if(!$H)$H=$this->result;if(!$H)return
false;$this->warnings=sqlsrv_errors(SQLSRV_ERR_WARNINGS);if(sqlsrv_field_metadata($H))return
new
Result($H);$this->affected_rows=sqlsrv_rows_affected($H);return
true;}function
next_result(){if(!$this->result)return
false;$I=sqlsrv_next_result($this->result);if($I===false){$this->get_error();$this->result=null;return
true;}return!!$I;}function
warnings(){$I=[];foreach((array)$this->warnings
as$no)$I[]=$no["message"];return$I;}function
inTransaction(){return$this->transaction||(isset($_GET["sql"])&&get_val("SELECT @@TRANCOUNT",0,$this));}private
function
transaction_command($G,$r){return
adminer()->executeQuery($G,function()use($r){return$r($this->link);});}function
begin(){$this->transaction=$this->transaction_command("BEGIN",'sqlsrv_begin_transaction');if(!$this->transaction)$this->get_error();return$this->transaction;}function
commit(){if($this->transaction&&!$this->transaction_command("COMMIT",'sqlsrv_commit')){$this->get_error();return
false;}$this->transaction=false;return
true;}function
rollback(){if(!$this->transaction&&isset($_GET["sql"]))return!!$this->query("IF @@TRANCOUNT > 0 ROLLBACK");if($this->transaction&&!$this->transaction_command("ROLLBACK",'sqlsrv_rollback')){$this->get_error();return
false;}$this->transaction=false;return
true;}}class
Result{var$num_rows;private$result,$offset=0,$fields;function
__construct($H){$this->result=$H;}function
fetch_assoc(){return
sqlsrv_fetch_array($this->result,SQLSRV_FETCH_ASSOC);}function
fetch_row(){return
sqlsrv_fetch_array($this->result,SQLSRV_FETCH_NUMERIC);}function
fetch_field(){if(!$this->fields)$this->fields=sqlsrv_field_metadata($this->result);$_n=[-155=>"datetimeoffset","time",-152=>"xml","varbinary","sql_variant",-11=>"uniqueidentifier","ntext","nvarchar","nchar","bit","tinyint","bigint","image","varbinary","binary","text",1=>"char","numeric","decimal","int","smallint","float","real","float",12=>"varchar",91=>"date","time","datetime",];$n=$this->fields[$this->offset++];$I=new
\stdClass;$I->name=$n["Name"];$I->native_type=idx($_n,$n["Type"],"");return$I;}function
seek($C){for($s=0;$s<$C;$s++)sqlsrv_fetch($this->result);}}function
last_id($H){return(string)get_val("SELECT SCOPE_IDENTITY()");}function
explain(Db$g,$G){$g->query("SET SHOWPLAN_ALL ON");$I=$g->query($G);$g->query("SET SHOWPLAN_ALL OFF");return$I;}}else{abstract
class
MssqlDb
extends
PdoDb{function
quote($Q){return
unicode_prefix($Q).parent::quote($Q);}function
select_db($wc){return$this->query(use_sql($wc));}function
lastInsertId(){return$this->pdo->lastInsertId();}function
inTransaction(){return
parent::inTransaction()||(isset($_GET["sql"])&&get_val("SELECT @@TRANCOUNT"));}function
rollback(){return(parent::inTransaction()||!isset($_GET["sql"])?parent::rollback():!!$this->query("IF @@TRANCOUNT > 0 ROLLBACK"));}function
warnings(){$H=$this->multi;if(!is_object($H))return[];$m=$H->errorInfo();return[(string)$m[2]];}}function
last_id($H){return
connection()->lastInsertId();}function
explain(Db$g,$G){}if(extension_loaded("pdo_sqlsrv")){class
Db
extends
MssqlDb{var$extension="PDO_SQLSRV";var$timeout=0;private$query_timeout=0;function
attach(array$N,$V,$E){$Kj=$N["port"];$jd="sqlsrv:Server=$N[host]".($Kj?",$Kj":"").(isset($_GET["sql"])&&!self::$instance?";MultipleActiveResultSets=0":"");$bm=adminer()->connectSsl();foreach(["Encrypt","TrustServerCertificate"]as$x){if(isset($bm[$x]))$jd
.=";$x=".($bm[$x]?1:0);}return$this->dsn($jd,$V,$E,[\PDO::SQLSRV_ATTR_DIRECT_QUERY=>true]);}function
query($G,$An=false){if(!$this->timeout)return
parent::query($G,$An);$this->pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT,$this->timeout);$this->timeout=0;$I=parent::query($G,$An);$this->pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT,$this->query_timeout);return$I;}function
timeout($Gh){$this->query_timeout=(int)ceil($Gh/1000);return$this->pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT,$this->query_timeout);}}}elseif(extension_loaded("pdo_dblib")){class
Db
extends
MssqlDb{var$extension="PDO_DBLIB";function
attach(array$N,$V,$E){$Kj=$N["port"];$Nl=$N["socket"];$Ki=[1001=>0,1002=>true,];$I=$this->dsn("dblib:charset=utf8;host=$N[host]".($Kj!=""?";port=$Kj":($Nl!=""?";unix_socket=$Nl":"")),$V,$E,$Ki);if(!$I){$this->query("SET ANSI_NULLS, QUOTED_IDENTIFIER, CONCAT_NULL_YIELDS_NULL, ANSI_WARNINGS, ANSI_PADDING ON");$this->server_info=get_val("SELECT CAST(SERVERPROPERTY('ProductVersion') AS varchar(20))",0,$this);}return$I;}}}}class
Driver
extends
SqlDriver{static$extensions=["SQLSRV","PDO_SQLSRV","PDO_DBLIB"];static$jush="mssql";static$serverSocket=true;var$insertFunctions=["date|time"=>"getdate"];var$editFunctions=["int|decimal|real|float|money|datetime"=>"+/-","char|text"=>"+",];var$functions=["len","lower","round","upper"];var$grouping=["avg","count","count distinct","max","min","sum"];var$generated=["PERSISTED","VIRTUAL"];var$onActions="NO ACTION|CASCADE|SET NULL|SET DEFAULT";var$inout="|OUTPUT";function
operators($um){return["=","<",">","<=",">=","!=","LIKE","LIKE %%","IN","BETWEEN","IS NULL","NOT LIKE","NOT IN","IS NOT NULL"];}static
function
connect($N,$V,$E){if($N=="")$N="localhost:1433";return
parent::connect($N,$V,$E);}function
__construct(Db$g){parent::__construct($g);$this->types=[lang(35)=>["tinyint"=>3,"smallint"=>5,"int"=>10,"bigint"=>20,"bit"=>1,"decimal"=>0,"numeric"=>0,"real"=>12,"float"=>53,"smallmoney"=>10,"money"=>20,"vector"=>0,],lang(36)=>["date"=>10,"smalldatetime"=>19,"datetime"=>23,"datetime2"=>19,"time"=>8,"datetimeoffset"=>26],lang(37)=>["char"=>8000,"varchar"=>8000,"text"=>2147483647,"nchar"=>4000,"nvarchar"=>4000,"ntext"=>1073741823,"uniqueidentifier"=>36,"xml"=>2147483647,"json"=>2147483647,"sql_variant"=>8000,"hierarchyid"=>892,],lang(38)=>["binary"=>8000,"varbinary"=>8000,"image"=>2147483647],lang(40)=>["geometry"=>0,"geography"=>0],];}function
types(){$Gn=$this->unknownTypes();return
parent::types()+array_fill_keys($Gn,0);}function
structuredTypes(){$Gn=$this->unknownTypes();return
array_merge(parent::structuredTypes(),$Gn);}private
function
unknownTypes(){static$I;if($I===null){$I=[];$_n=array_flip(get_vals("SELECT name FROM sys.types WHERE is_user_defined = 0 ORDER BY name"));if($_n){foreach($this->types
as$Oe=>$Re){foreach($Re
as$U=>$y){if(isset($_n[$U]))unset($_n[$U]);else
unset($this->types[$Oe][$U]);}if(!$this->types[$Oe])unset($this->types[$Oe]);}$I=array_keys($_n);}}return$I;}function
typeName(\stdClass$n){return
idx((array)$n,'sqlsrv:decl_type',parent::typeName($n));}function
insertUpdate($R,array$K,array$Xj){$o=fields($R);$Ln=[];$Z=[];$O=reset($K);$e="c".implode(", c",range(1,count($O)));$gb=0;$Sf=[];foreach($O
as$x=>$X){$gb++;$B=idf_unescape($x);if(!$o[$B]["auto_increment"])$Sf[$x]="c$gb";if(isset($Xj[$B]))$Z[]="$x = c$gb";else$Ln[]="$x = c$gb";}$fo=[];foreach($K
as$O)$fo[]="(".implode(", ",$O).")";if($Z){$vf=queries("SET IDENTITY_INSERT ".table($R)." ON");$I=queries("MERGE ".table($R)." USING (VALUES\n\t".implode(",\n\t",$fo)."\n) AS source ($e) ON ".implode(" AND ",$Z).($Ln?"\nWHEN MATCHED THEN UPDATE SET ".implode(", ",$Ln):"")."\nWHEN NOT MATCHED THEN INSERT (".implode(", ",array_keys($vf?$O:$Sf)).") VALUES (".($vf?$e:implode(", ",$Sf)).");");if($vf)queries("SET IDENTITY_INSERT ".table($R)." OFF");}else$I=queries("INSERT INTO ".table($R)." (".implode(", ",array_keys($O)).") VALUES\n".implode(",\n",$fo));return$I;}function
begin(){remember_query("BEGIN TRANSACTION");return$this->conn->begin();}function
slowQuery($G,$Vm){if($this->conn->extension!="PDO_DBLIB"){$this->conn->timeout=$Vm;return$G;}}function
convertSearch($u,array$X,array$n){return(preg_match('~^(bit|n?text|xml|json|vector|uniqueidentifier|sql_variant|hierarchyid|geography|geometry)$~',$n["type"])?"CAST($u AS nvarchar(max))":$u);}function
quoteBinary($Zk){return"0x".bin2hex($Zk);}function
warnings(){$I=[];foreach($this->conn->warnings()as$ph){$ph=trim(preg_replace('~^(\[[^]]+])+~','',$ph));if($ph!="")$I[]=$ph;}return
nl_br(h(implode("\n",$I)));}function
tableHelp($B,$hg=false){$Ng=["sys"=>"catalog-views/sys-","INFORMATION_SCHEMA"=>"information-schema-views/",];$_=$Ng[get_schema()];if($_)return"relational-databases/system-$_".preg_replace('~_~','-',strtolower($B))."-transact-sql";}function
isSystem($k,$L=""){return($L!=""?information_schema($k,$L)||preg_match('~^(guest|db_(owner|accessadmin|securityadmin|ddladmin|backupoperator|(deny)?data(reader|writer)))$~',$L):in_array($k,["master","tempdb","model","msdb"]));}}function
unicode_prefix($Q){return(strlen($Q)!=utf8_length($Q)?"N":"");}function
idf_escape($u){return"[".str_replace("]","]]",$u)."]";}function
table($u){return($_GET["ns"]!=""?idf_escape($_GET["ns"]).".":"").idf_escape($u);}function
get_databases($se){return
get_vals("SELECT name FROM sys.databases WHERE name NOT IN ('master', 'tempdb', 'model', 'msdb')");}function
limit($G,$Z,$z,$C=0,$rl=" "){return($z?" TOP (".($z+$C).")":"")." $G$Z";}function
limit1($R,$G,$Z,$rl="\n"){return
limit($G,$Z,1,0,$rl);}function
db_collation($k,array$Ib){static$I;if($I===null)$I=get_key_vals("SELECT name, collation_name FROM sys.databases");return$I[$k];}function
logged_user(){return
get_val("SELECT SUSER_NAME()");}function
tables_list(){return
get_key_vals("SELECT name, type_desc FROM sys.all_objects WHERE schema_id = SCHEMA_ID(".q(get_schema()).") AND type IN ('S', 'U', 'V') ORDER BY name");}function
count_tables(array$j){$I=[];foreach($j
as$k){connection()->select_db($k);$I[$k]=get_val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES");}return$I;}function
table_status($B="",$Yd=false){$I=[];$Ll=[];foreach(($Yd?[]:get_rows("SELECT object_id, SUM(CASE WHEN index_id < 2 THEN row_count ELSE 0 END) AS [Rows],
SUM(CASE WHEN index_id < 2 THEN used_page_count ELSE 0 END) * 8192 AS Data_length,
SUM(CASE WHEN index_id > 1 THEN used_page_count ELSE 0 END) * 8192 AS Index_length,
SUM(reserved_page_count - used_page_count) * 8192 AS Data_free
FROM sys.dm_db_partition_stats".($B!=""?"
WHERE object_id = OBJECT_ID(".q(table($B)).")":"")."
GROUP BY object_id",null,""))as$J){$mi=$J["object_id"];unset($J["object_id"]);$Ll[$mi]=$J;}foreach(get_rows("SELECT ao.object_id, ao.name AS Name, ao.type_desc AS Engine,
	(SELECT cast(value as varchar(max)) FROM fn_listextendedproperty('MS_Description', 'SCHEMA', schema_name(schema_id), 'TABLE', ao.name, null, null)) AS Comment,
	CASE WHEN EXISTS (SELECT 1 FROM sys.indexes i WHERE i.object_id = ao.object_id AND i.is_disabled = 1) THEN 1 END AS invalid_index
FROM sys.all_objects AS ao
WHERE schema_id = SCHEMA_ID(".q(get_schema()).") AND type IN ('S', 'U', 'V') ".($B!=""?"AND name = ".q($B):"ORDER BY name"))as$J){$mi=$J["object_id"];unset($J["object_id"]);$I[$J["Name"]]=$J+idx($Ll,$mi,[]);}return$I;}function
is_view(array$S){return$S["Engine"]=="VIEW";}function
fk_support(array$S){return
true;}function
type_length($U,array$J){return(preg_match("~char|binary~",$U)?($J["max_length"]==-1?"max":intval($J["max_length"])/($U[0]=='n'?2:1)):($U=="decimal"?"$J[precision],$J[scale]":(preg_match('~^(datetime2|datetimeoffset|time)$~',$U)?$J["scale"]:($U=="vector"?(intval($J["max_length"])-8)/4:""))));}function
fields($R){$Pb=get_key_vals("SELECT objname, cast(value as varchar(max)) FROM fn_listextendedproperty('MS_DESCRIPTION', 'schema', ".q(get_schema()).", 'table', ".q($R).", 'column', NULL)");$I=[];$vm=get_val("SELECT object_id FROM sys.all_objects WHERE schema_id = SCHEMA_ID(".q(get_schema()).") AND type IN ('S', 'U', 'V') AND name = ".q($R));foreach(get_rows("SELECT c.max_length, c.precision, c.scale, c.name, c.is_nullable, c.is_identity, c.collation_name,
	COALESCE(bt.name, t.name) type, d.definition [default], d.name default_constraint, i.is_primary_key
FROM sys.all_columns c
JOIN sys.types t ON c.user_type_id = t.user_type_id
LEFT JOIN sys.types bt ON t.system_type_id = bt.user_type_id AND t.is_user_defined = 1
LEFT JOIN sys.default_constraints d ON c.default_object_id = d.object_id
LEFT JOIN sys.index_columns ic ON c.object_id = ic.object_id AND c.column_id = ic.column_id
LEFT JOIN sys.indexes i ON ic.object_id = i.object_id AND ic.index_id = i.index_id
WHERE c.object_id = ".q($vm))as$J){$U=$J["type"];$y=type_length($U,$J);$l=$J["default"];$Dc=false;if(preg_match("~^\\(N?('(?:[^']|'')*')\\)$~s",$l,$A))$l=idf_unescape($A[1]);elseif(preg_match('~^\(\(([-+]?[\d.]+)\)\)$~',$l,$A))$l=$A[1];elseif($l!==null){$l=preg_replace('~^\((.*)\)$~s','\1',$l);$Dc=true;}$I[$J["name"]]=["field"=>$J["name"],"full_type"=>$U.($y!=""?"($y)":""),"type"=>$U,"length"=>$y,"default"=>$l,"default_sql"=>$Dc,"default_constraint"=>$J["default_constraint"],"null"=>$J["is_nullable"],"auto_increment"=>$J["is_identity"],"collation"=>$J["collation_name"],"privileges"=>($U=="timestamp"?["select"=>1,"where"=>1,"order"=>1]:["insert"=>1,"select"=>1,"update"=>1,"where"=>1,"order"=>1]),"primary"=>$J["is_primary_key"],"comment"=>$Pb[$J["name"]],];}foreach(get_rows("SELECT * FROM sys.computed_columns WHERE object_id = ".q($vm))as$J){$I[$J["name"]]["generated"]=($J["is_persisted"]?"PERSISTED":"VIRTUAL");$I[$J["name"]]["default"]=$J["definition"];}return$I;}function
indexes($R,$h=null){$I=[];foreach(get_rows("SELECT i.name, key_ordinal, is_unique, is_primary_key, is_disabled, c.name AS column_name, is_descending_key
FROM sys.indexes i
INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
WHERE OBJECT_NAME(i.object_id) = ".q($R),$h)as$J){$B=$J["name"];$I[$B]["type"]=($J["is_primary_key"]?"PRIMARY":($J["is_unique"]?"UNIQUE":"INDEX"));$I[$B]["lengths"]=[];$I[$B]["invalid"]=!!$J["is_disabled"];$I[$B]["columns"][$J["key_ordinal"]]=$J["column_name"];$I[$B]["descs"][$J["key_ordinal"]]=($J["is_descending_key"]?'1':null);}return$I;}function
view($B){return["select"=>preg_replace('~^(?:[^[]|\[[^]]*])*\s+AS\s+~isU','',get_val("SELECT VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ".q($B)))];}function
collations(){$I=[];foreach(get_vals("SELECT name FROM fn_helpcollations()")as$Hb)$I[preg_replace('~_.*~','',$Hb)][]=$Hb;return$I;}function
information_schema($k,$L=""){return
in_array($L!=""?$L:get_schema(),["INFORMATION_SCHEMA","sys"]);}function
error(){return
nl_br(h(preg_replace('~^(\[[^]]*])+~m','',connection()->error)));}function
create_database($k,$Hb){return
queries("CREATE DATABASE ".idf_escape($k).(preg_match('~^[a-z0-9_]+$~i',$Hb)?" COLLATE $Hb":""));}function
drop_databases(array$j){return!!queries("DROP DATABASE ".implode(", ",array_map('Adminer\idf_escape',$j)));}function
rename_database($B,$Hb){if(preg_match('~^[a-z0-9_]+$~i',$Hb))queries("ALTER DATABASE ".idf_escape(DB)." COLLATE $Hb");queries("ALTER DATABASE ".idf_escape(DB)." MODIFY NAME = ".idf_escape($B));return
true;}function
auto_increment(){return" IDENTITY".($_POST["Auto_increment"]!=""?"(".number($_POST["Auto_increment"]).",1)":"")." PRIMARY KEY";}function
alter_table($R,$B,array$o,array$ue,$f,$xd,$Hb,$Na,$tj){$b=[];$Pb=[];$Vi=fields($R);foreach($o
as$n){$d=idf_escape($n[0]);$X=$n[1];if(!$X)$b["DROP"][]=" COLUMN $d";else{$Ti=idx($Vi[$n[0]],"comment");if($X[5]!=($Ti!=""?" COMMENT ".q($Ti):""))$Pb[idf_unescape($X[0])]=[$Ti!="",substr($X[5],9)];unset($X[5]);if(preg_match('~ AS ~',$X[3]))unset($X[1],$X[2]);if($n[0]=="")$b["ADD"][]="\n  ".implode("",$X).($R==""?str_replace(" FOREIGN KEY ($X[0])","",$ue[$X[0]]):"");else{$l=$X[3];unset($X[3]);unset($X[6]);if($d!=$X[0])queries("EXEC sp_rename ".q(table($R).".$d").", ".q(idf_unescape($X[0])).", 'COLUMN'");$b["ALTER COLUMN ".implode("",$X)][]="";$Ui=$Vi[$n[0]];if(default_value($Ui)!=$l){if($Ui["default"]!==null)$b["DROP"][]=" ".idf_escape($Ui["default_constraint"]);if($l)$b["ADD"][]="\n $l FOR $d";}}}}if($R==""){$ma=(array)$b["ADD"];foreach($ue
as$x=>$X){if(!is_string($x))$ma[]="\n$X";}if(!queries("CREATE TABLE ".table($B)." (".implode(",",$ma)."\n)"))return
false;}else{if($R!=$B)queries("EXEC sp_rename ".q(table($R)).", ".q($B));if($ue)$b[""]=$ue;foreach($b
as$x=>$X){if(!queries("ALTER TABLE ".table($B)." $x".implode(",",$X)))return
false;}}$gk="@name = N'MS_Description', @level0type = N'Schema', @level0name = ".q(get_schema()).", @level1type = N'Table', @level1name = ".q($B);foreach($Pb
as$x=>$X){list($ed,$Nb)=$X;$hk="$gk, @level2type = N'Column', @level2name = ".q($x);if($ed)queries("EXEC sp_dropextendedproperty $hk");if($Nb!="")queries("EXEC sp_addextendedproperty $hk, @value = $Nb");}if($f!==null){if(idx(table_status1($B),"Comment")!="")queries("EXEC sp_dropextendedproperty $gk");if($f!="")queries("EXEC sp_addextendedproperty $gk, @value = ".q($f));}return
true;}function
alter_indexes($R,$b){$v=[];$ed=[];$kk=[];foreach($b
as$X){if($X[2]=="DROP"){if($X[0]=="PRIMARY"||($X[0]=="UNIQUE"&&get_val("SELECT is_unique_constraint FROM sys.indexes WHERE object_id = OBJECT_ID(".q(table($R)).") AND name = ".q($X[1]))))$ed[]=idf_escape($X[1]);else$v[]=idf_escape($X[1])." ON ".table($R);}else$kk[]=($X[0]!="PRIMARY"?"CREATE $X[0] ".($X[0]!="INDEX"?"INDEX ":"").idf_escape($X[1]!=""?$X[1]:uniqid($R."_"))." ON ".table($R):"ALTER TABLE ".table($R)." ADD PRIMARY KEY")." (".implode(", ",$X[2]).")";}if($ed)array_unshift($kk,"ALTER TABLE ".table($R)." DROP ".implode(", ",$ed));if($v)array_unshift($kk,"DROP INDEX ".implode(", ",$v));foreach($kk
as$G){if(!queries($G))return
false;}return
true;}function
found_rows(array$S,array$Z){}function
foreign_keys($R){$I=[];$Bi=["CASCADE","NO ACTION","SET NULL","SET DEFAULT"];$L=get_schema();foreach(get_rows("EXEC sp_fkeys @fktable_name = ".q($R).", @fktable_owner = ".q($L))as$J){$q=&$I[$J["FK_NAME"]];$q["db"]=($J["PKTABLE_QUALIFIER"]==DB?"":$J["PKTABLE_QUALIFIER"]);$q["ns"]=($J["PKTABLE_OWNER"]==$L?"":$J["PKTABLE_OWNER"]);$q["table"]=$J["PKTABLE_NAME"];$q["on_update"]=$Bi[$J["UPDATE_RULE"]];$q["on_delete"]=$Bi[$J["DELETE_RULE"]];$q["source"][]=$J["FKCOLUMN_NAME"];$q["target"][]=$J["PKCOLUMN_NAME"];}return$I;}function
truncate_tables(array$T){return
apply_queries("TRUNCATE TABLE",$T);}function
drop_views(array$ko){return
queries("DROP VIEW ".implode(", ",array_map('Adminer\table',$ko)));}function
drop_tables(array$T){return
queries("DROP TABLE ".implode(", ",array_map('Adminer\table',$T)));}function
move_tables(array$T,array$ko,$Lm){return
apply_queries("ALTER SCHEMA ".idf_escape($Lm)." TRANSFER",array_merge($T,$ko));}function
trigger($B,$R){if($B=="")return[];$K=get_rows("SELECT s.name [Trigger],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT'
	WHEN OBJECTPROPERTY(s.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE'
	WHEN OBJECTPROPERTY(s.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing],
c.text
FROM sysobjects s
JOIN syscomments c ON s.id = c.id
WHERE s.xtype = 'TR' AND s.name = ".q($B));$I=reset($K);if($I)$I["Statement"]=preg_replace('~^.+\s+AS\s+~isU','',$I["text"]);return($I?:[]);}function
triggers($R){$I=[];foreach(get_rows("SELECT sys1.name,
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT'
	WHEN OBJECTPROPERTY(sys1.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE'
	WHEN OBJECTPROPERTY(sys1.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing]
FROM sysobjects sys1
JOIN sysobjects sys2 ON sys1.parent_obj = sys2.id
WHERE sys1.xtype = 'TR' AND sys2.name = ".q($R))as$J)$I[$J["name"]]=[$J["Timing"],$J["Event"]];return$I;}function
trigger_options(){return["Timing"=>["AFTER","INSTEAD OF"],"Event"=>["INSERT","UPDATE","DELETE"],"Type"=>["AS"],];}function
routine($B,$U){$Gc=get_val("SELECT m.definition
FROM sys.objects o
JOIN sys.sql_modules m ON m.object_id = o.object_id
WHERE o.schema_id = SCHEMA_ID(".q(get_schema()).") AND o.name = ".q($B)." AND o.type = ".q($U=="PROCEDURE"?"P":"FN"));if(!$Gc)return[];$I=["definition"=>preg_replace('~^(?:[^[]|\[[^]]*])*\s+AS\s+(?=\S)~isU','',$Gc),"fields"=>[]];foreach(get_rows("SELECT p.name, TYPE_NAME(p.user_type_id) [type], p.max_length, p.precision, p.scale, p.is_output
FROM sys.parameters p
JOIN sys.objects o ON p.object_id = o.object_id
WHERE o.schema_id = SCHEMA_ID(".q(get_schema()).") AND o.name = ".q($B)."
ORDER BY p.parameter_id")as$J){$ee=$J["type"];$y=type_length($ee,$J);$n=["field"=>preg_replace('~^@~','',$J["name"]),"type"=>$ee,"length"=>$y,"full_type"=>$ee.($y!=""?"($y)":""),"null"=>true,"inout"=>($J["is_output"]?"OUTPUT":""),];if($n["field"]=="")$I["returns"]=$n;else$I["fields"][]=$n;}return$I;}function
routines(){return
get_rows("SELECT o.name SPECIFIC_NAME, o.name ROUTINE_NAME,
	CASE o.type WHEN 'P' THEN 'PROCEDURE' ELSE 'FUNCTION' END ROUTINE_TYPE, TYPE_NAME(p.user_type_id) DTD_IDENTIFIER
FROM sys.objects o
LEFT JOIN sys.parameters p ON o.object_id = p.object_id AND p.parameter_id = 0
WHERE o.schema_id = SCHEMA_ID(".q(get_schema()).") AND o.type IN ('P', 'FN')
ORDER BY o.name");}function
routine_languages(){return[];}function
routine_options($Qk){return[];}function
routine_id($B,array$J){return
table($B);}function
schemas(){return
get_vals("SELECT name FROM sys.schemas");}function
get_schema(){if($_GET["ns"]!="")return$_GET["ns"];return
get_val("SELECT SCHEMA_NAME()");}function
set_schema($L,$h=null){$_GET["ns"]=$L;return!!get_val("SELECT 1 FROM sys.schemas WHERE name = ".q($L),0,$h);}function
schema_comment($L){return(string)get_val("SELECT CAST(value AS nvarchar(max)) FROM fn_listextendedproperty('MS_Description', 'SCHEMA', ".q($L).", NULL, NULL, NULL, NULL)");}function
set_schema_comment($L,$f){$gk="@name = N'MS_Description', @level0type = N'Schema', @level0name = ".q($L);return(schema_comment($L)==""||queries("EXEC sp_dropextendedproperty $gk"))&&($f==""||queries("EXEC sp_addextendedproperty $gk, @value = ".q($f)));}function
create_sql($R,$Na,$km){$P=table_status1($R);if(is_view($P)){$jo=view($R);return"CREATE VIEW ".table($R)." AS $jo[select]";}$gk="EXEC sp_addextendedproperty @name = N'MS_Description', @level0type = N'Schema', @level0name = ".q(get_schema()).", @level1type = N'Table', @level1name = ".q($R);$Pb=($P["Comment"]!=""?";\n\n$gk, @value = ".q($P["Comment"]):"");$o=[];$Xj=false;foreach(fields($R)as$B=>$n){$X=process_field($n,$n);if($X[6])$Xj=true;unset($X[5]);$o[]=implode("",$X);if($n["comment"]!="")$Pb
.=";\n\n$gk, @level2type = N'Column', @level2name = ".q($B).", @value = ".q($n["comment"]);}foreach(indexes($R)as$B=>$v){if(!$Xj||$v["type"]!="PRIMARY"){$e=[];foreach($v["columns"]as$x=>$X)$e[]=idf_escape($X).($v["descs"][$x]?" DESC":"");$B=idf_escape($B);$o[]=($v["type"]=="INDEX"?"INDEX $B":"CONSTRAINT $B ".($v["type"]=="UNIQUE"?"UNIQUE":"PRIMARY KEY"))." (".implode(", ",$e).")";}}foreach(driver()->checkConstraints($R)as$B=>$tb)$o[]="CONSTRAINT ".idf_escape($B)." CHECK ($tb)";return"CREATE TABLE ".table($R)." (\n\t".implode(",\n\t",$o)."\n)$Pb";}function
foreign_keys_sql($R){$o=[];foreach(foreign_keys($R)as$ue)$o[]=ltrim(format_foreign_key($ue));return($o?"ALTER TABLE ".table($R)." ADD\n\t".implode(",\n\t",$o).";\n\n":"");}function
truncate_sql($R){return"TRUNCATE TABLE ".table($R);}function
use_sql($wc,$km=""){return"USE ".idf_escape($wc);}function
use_schema_sql($L,$km){$B=idf_escape($L);$i="EXEC(".q("CREATE SCHEMA $B").")";$f=schema_comment($L);if($f!="")$i="BEGIN\n$i\nEXEC sp_addextendedproperty @name = N'MS_Description', @level0type = N'Schema', @level0name = ".q($L).", @value = ".q($f)."\nEND";return($km=="DROP+CREATE"?"DROP SCHEMA IF EXISTS $B;\n":"")."IF SCHEMA_ID(".q($L).") IS NULL $i";}function
trigger_sql($R){$I="";foreach(triggers($R)as$B=>$pn)$I
.=create_trigger(" ON ".table($R),trigger($B,$R)).";";return$I;}function
convert_field(array$n){}function
unconvert_field(array$n,$I){return$I;}function
support($Zd){return
preg_match('~^(check|comment|columns|database|drop_col|dump|fast_status|indexes|descidx|procedure|routine|scheme|sql|table|transaction_ddl|trigger|view|view_trigger)$~',$Zd);}}add_driver("oracle","Oracle");if(isset($_GET["oracle"])){define('Adminer\DRIVER',"oracle");function
easy_connect(array$N){return
url_host($N["host"]).($N["port"]!=""?":$N[port]":"").$N["path"];}if(extension_loaded("oci8")&&$_GET["ext"]!="pdo"){class
Db
extends
SqlDb{var$extension="OCI8";var$error_offset=0;var$timeout=0;private$link,$transaction=false;private$query_timeout=0;function
attach(array$N,$V,$E){$this->link=@oci_new_connect($V,$E,easy_connect($N),"AL32UTF8");if($this->link){$this->server_info=oci_server_version($this->link);return'';}$m=oci_error();return($m?$m["message"]:lang(32));}function
quote($Q){return"'".str_replace("'","''",$Q)."'";}function
select_db($wc){return$this->query("ALTER SESSION SET CURRENT_SCHEMA = ".idf_escape($wc));}function
query($G,$An=false){$H=oci_parse($this->link,$G);$this->error="";$this->error_offset=0;if(!$H){$m=oci_error($this->link);$this->errno=$m["code"];$this->error=$m["message"];return
false;}$Vm=$this->timeout;$nk=$this->query_timeout;if($Vm){$this->timeout=0;$this->timeout(1000*$Vm);}$Ed=[];$I=adminer()->executeQuery($G,function()use($H,&$Ed){$Ed=php_errors(function()use($H,&$I){$I=oci_execute($H,($this->transaction?OCI_NO_AUTO_COMMIT:OCI_COMMIT_ON_SUCCESS));});return$I;});if($Vm)$this->timeout($nk);if($Ed)$this->error=end($Ed);if(!$I){$m=oci_error($H);$this->error_offset=($m?$m["offset"]:0);}if($I){if(oci_num_fields($H))return
new
Result($H);$this->affected_rows=oci_num_rows($H);oci_free_statement($H);}return$I;}function
timeout($Gh){$this->query_timeout=$Gh;return
function_exists('oci_set_call_timeout')&&oci_set_call_timeout($this->link,$Gh);}function
inTransaction(){return$this->transaction;}function
begin(){$this->transaction=true;return
true;}function
commit(){return$this->end_transaction($this->transaction_command("COMMIT",'oci_commit'));}function
rollback(){return$this->end_transaction($this->transaction_command("ROLLBACK",'oci_rollback'));}private
function
transaction_command($G,$r){return
adminer()->executeQuery($G,function()use($r){return@$r($this->link);});}private
function
end_transaction($I){$this->transaction=false;if(!$I){$m=oci_error($this->link);$this->errno=$m["code"];$this->error=$m["message"];}return$I;}}class
Result{var$num_rows;private$result,$offset=1;function
__construct($H){$this->result=$H;}private
function
convert($J){foreach((array)$J
as$x=>$X){if(is_a($X,'OCILob')||is_a($X,'OCI-Lob'))$J[$x]=$X->load();}return$J;}function
fetch_assoc(){return$this->convert(oci_fetch_assoc($this->result));}function
fetch_row(){return$this->convert(oci_fetch_row($this->result));}function
fetch_field(){$d=$this->offset++;$I=new
\stdClass;$I->name=oci_field_name($this->result,$d);$U=oci_field_type($this->result,$d);$I->native_type=idx([100=>"binary_float","binary_double"],$U,$U);return$I;}}}elseif(extension_loaded("pdo_oci")){class
Db
extends
PdoDb{var$extension="PDO_OCI";var$timeout=0;private$query_timeout=0;function
attach(array$N,$V,$E){return$this->dsn("oci:dbname=//".easy_connect($N).";charset=AL32UTF8",$V,$E);}function
select_db($wc){return$this->query("ALTER SESSION SET CURRENT_SCHEMA = ".idf_escape($wc));}function
query($G,$An=false){$Vm=$this->timeout;if(!$Vm)return
parent::query($G,$An);$nk=$this->query_timeout;$this->timeout=0;$this->timeout(1000*$Vm);$I=parent::query($G,$An);$this->timeout($nk);return$I;}function
timeout($Gh){$this->query_timeout=$Gh;return$this->pdo->setAttribute(1004,$Gh);}}}class
Driver
extends
SqlDriver{static$extensions=["OCI8","PDO_OCI"];static$jush="oracle";static$serverPath=true;var$insertFunctions=["date"=>"current_date","timestamp"=>"current_timestamp",];var$editFunctions=["number|float|double"=>"+/-","date|timestamp"=>"+ interval/- interval","char|clob"=>"||",];var$functions=["length","lower","round","upper"];var$grouping=["avg","count","count distinct","max","min","sum"];function
operators($um){return["=","<",">","<=",">=","!=","LIKE","LIKE %%","IN","BETWEEN","IS NULL","NOT LIKE","NOT IN","IS NOT NULL","SQL"];}static
function
connect($N,$V,$E){$g=parent::connect($N,$V,$E);if(is_object($g))$g->query("ALTER SESSION SET CURSOR_SHARING = FORCE"." NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'"." NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF'"." NLS_TIMESTAMP_TZ_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF TZH:TZM'");return$g;}function
__construct(Db$g){parent::__construct($g);$this->types=[lang(35)=>["number"=>38,"binary_float"=>12,"binary_double"=>21],lang(36)=>["date"=>19,"timestamp"=>29,"interval year"=>12,"interval day"=>28],lang(37)=>["char"=>2000,"varchar2"=>4000,"nchar"=>2000,"nvarchar2"=>4000,"clob"=>4294967295,"nclob"=>4294967295],lang(38)=>["raw"=>2000,"long raw"=>2147483648,"blob"=>4294967295,"bfile"=>4294967296],lang(40)=>["sdo_geometry"=>0],];}function
begin(){return$this->conn->begin();}function
slowQuery($G,$Vm){$this->conn->timeout=$Vm;return$G;}function
convertSearch($u,array$X,array$n){$U=$n["type"];$Bj=strpos($X["op"],"LIKE")!==false;if($U=="xmltype")return"XMLSERIALIZE(CONTENT $u AS VARCHAR2(4000))";if($U=="json")return"JSON_SERIALIZE($u)";if(preg_match('~^(date|timestamp)~',$U))return"TO_CHAR($u, 'YYYY-MM-DD HH24:MI:SS')";if(preg_match('~char~',$U)||(preg_match('~clob~',$U)&&$Bj))return$u;return(!$Bj&&preg_match(number_type(),$U)?$u:"TO_CHAR($u)");}function
quoteBinary($Zk){return"HEXTORAW(".q(bin2hex($Zk)).")";}function
typeName(\stdClass$n){return
strtolower(parent::typeName($n));}function
hasCStyleEscapes(){return
true;}function
errorLine($G){$C=(isset($this->conn->error_offset)?$this->conn->error_offset:0);return($C?substr_count($G,"\n",0,min($C,strlen($G)))+1:0);}function
tableHelp($B,$hg=false){if(DB=="SYS"){if(preg_match('~^G?V_\$(.+)~',$B,$A))return"refrn/V-$A[1].html";if(preg_match('~^(ALL|DBA|USER)_~',$B))return"refrn/$B.html";}}function
select($R,array$M,array$Z,array$Oe,array$Ni=[],$z=1,$D=0,$Zj=false){if(in_array("*",$M)){$fc=[];$ye=false;foreach(fields($R)as$B=>$n){$Ha=convert_field($n);$ye=($ye||$Ha);$fc[]=($Ha?"$Ha AS ":"").idf_escape($B);}if($ye)$M=$fc;}return
parent::select($R,$M,$Z,$Oe,$Ni,$z,$D,$Zj);}function
allFields(){$I=[];$K=get_rows('SELECT c.table_name "tab", c.column_name "field", c.data_type "type", c.nullable "nullable",
	c.data_precision "precision", c.data_scale "scale", c.char_col_decl_length "char_length"
FROM all_tab_columns c
WHERE '.where_owner("c.owner").'
ORDER BY c.table_name, c.column_id',$this->conn);foreach($K
as$J){$y=($J["precision"]==""&&$J["scale"]!=""?"*":$J["precision"]).",$J[scale]";$J["length"]=(strpos($J["type"],"(")?"":($y==","?$J["char_length"]:$y));$J["type"]=strtolower($J["type"]);$J["null"]=($J["nullable"]=="Y");$I[$J["tab"]][]=$J;}return$I;}}function
idf_escape($u){return'"'.str_replace('"','""',$u).'"';}function
table($u){return
idf_escape($u);}function
get_databases($se){$I=get_vals("SELECT username FROM all_users WHERE oracle_maintained = 'N' ORDER BY 1");return($I?:get_vals("SELECT username FROM all_users ORDER BY 1"));}function
limit($G,$Z,$z,$C=0,$rl=" "){return($C?" * FROM (SELECT t.*, rownum AS rnum FROM (SELECT $G$Z) t WHERE rownum <= ".($z+$C).") WHERE rnum > $C":($z?" * FROM (SELECT $G$Z) WHERE rownum <= ".($z+$C):" $G$Z"));}function
limit1($R,$G,$Z,$rl="\n"){return" $G$Z";}function
db_collation($k,array$Ib){static$I;if($I===null)$I=get_val("SELECT value FROM nls_database_parameters WHERE parameter = 'NLS_CHARACTERSET'");return$I;}function
logged_user(){return
get_val("SELECT USER FROM DUAL");}function
where_owner($dj="owner"){return"$dj = ".q(DB);}function
views_table($e){return"(SELECT $e FROM all_views WHERE ".where_owner().")";}function
objects_table(){return"(SELECT object_name, DECODE(object_type, 'VIEW', 'view', 'table') object_type FROM all_objects WHERE ".where_owner()." AND object_type IN ('TABLE', 'VIEW'))";}function
tables_list(){return
get_key_vals("SELECT * FROM ".objects_table()." ORDER BY 1");}function
count_tables(array$j){$I=[];foreach($j
as$k)$I[$k]=get_val("SELECT COUNT(*) FROM all_objects WHERE object_type IN ('TABLE', 'VIEW') AND owner = ".q($k));return$I;}function
table_status($B="",$Yd=false){$I=[];$fl=q($B);if($Yd||$B!=""){foreach(get_rows('SELECT object_name "Name", object_type "Engine" FROM '.objects_table().($B!=""?" WHERE object_name = $fl":"").' ORDER BY 1')as$J)$I[$J["Name"]]=$J;return$I;}foreach(get_rows('SELECT t.table_name "Name", \'table\' "Engine", s.bytes "Data_length", i.bytes "Index_length", t.num_rows "Rows"
FROM all_tables t
LEFT JOIN (SELECT segment_name, SUM(bytes) bytes FROM user_segments WHERE segment_type LIKE \'TABLE%\' GROUP BY segment_name) s ON s.segment_name = t.table_name
LEFT JOIN (SELECT i.table_name, SUM(s.bytes) bytes FROM user_indexes i
	JOIN user_segments s ON s.segment_name = i.index_name AND s.segment_type LIKE \'INDEX%\' GROUP BY i.table_name) i ON i.table_name = t.table_name
WHERE '.where_owner("t.owner")."
UNION SELECT view_name, 'view', 0, 0, 0 FROM ".views_table("view_name")."
ORDER BY 1")as$J)$I[$J["Name"]]=$J;foreach(get_vals("SELECT table_name FROM all_indexes ai WHERE ".where_owner("table_owner")." AND (status = 'UNUSABLE'
	OR EXISTS (SELECT 1 FROM all_ind_partitions aip WHERE aip.index_owner = ai.owner AND aip.index_name = ai.index_name AND aip.status = 'UNUSABLE')
	OR EXISTS (SELECT 1 FROM all_ind_subpartitions ais WHERE ais.index_owner = ai.owner AND ais.index_name = ai.index_name AND ais.status = 'UNUSABLE'))")as$R){if($I[$R])$I[$R]["invalid_index"]="1";}return$I;}function
is_view(array$S){return$S["Engine"]=="view";}function
fk_support(array$S){return
true;}function
fields($R){$I=[];$vf=null;$Xj=[];foreach(table_constraints($R)as$Yb){if($Yb["type"]=="P")$Xj=$Yb["columns"];}foreach(get_rows("SELECT * FROM all_tab_columns WHERE table_name = ".q($R)." AND ".where_owner()." ORDER BY column_id")as$J){$U=$J["DATA_TYPE"];$y=($J["DATA_PRECISION"]==""&&$J["DATA_SCALE"]!=""?"*":$J["DATA_PRECISION"]).",$J[DATA_SCALE]";if($y==","){$y=($U=="RAW"?$J["DATA_LENGTH"]:(preg_match('~CHAR~',$U)?$J["CHAR_COL_DECL_LENGTH"]:""));if($J["CHAR_USED"]=="C"&&preg_match('~^(VARCHAR2|CHAR)$~',$U))$y="$J[CHAR_LENGTH] CHAR";}elseif(strpos($U,"("))$y="";$l=$J["DATA_DEFAULT"];$Dc=false;if($l!==null){$l=rtrim($l);if(preg_match("~^'(?:[^']|'')*'\$~s",$l))$l=idf_unescape($l);elseif(preg_match('~^NULL$~i',$l))$l=null;else$Dc=!is_numeric($l);}if($J["IDENTITY_COLUMN"]=="YES"){if($vf===null)$vf=get_key_vals("SELECT column_name, generation_type FROM all_tab_identity_cols WHERE table_name = ".q($R)." AND ".where_owner());$l="GENERATED ".$vf[$J["COLUMN_NAME"]].($J["DEFAULT_ON_NULL"]=="YES"?" ON NULL":"")." AS IDENTITY";}$ck=["insert"=>1,"select"=>1,"update"=>1,"order"=>1];if($J["DATA_TYPE_OWNER"]==""||$U=="XMLTYPE")$ck["where"]=1;$I[$J["COLUMN_NAME"]]=["field"=>$J["COLUMN_NAME"],"full_type"=>$U.($y?"($y)":""),"type"=>strtolower($U),"length"=>$y,"default"=>$l,"default_sql"=>($Dc&&$J["IDENTITY_COLUMN"]!="YES"),"null"=>($J["NULLABLE"]=="Y"),"auto_increment"=>($J["IDENTITY_COLUMN"]=="YES"),"privileges"=>$ck,"primary"=>in_array($J["COLUMN_NAME"],$Xj),];}return$I;}function
table_constraints($R,$h=null){static$jb=[];if(!$h&&isset($jb[$R]))return$jb[$R];$I=[];foreach(get_rows('SELECT c.constraint_name "name", c.constraint_type "type", c.r_owner "r_owner", c.r_constraint_name "r_constraint", c.delete_rule "delete_rule", cc.column_name "column"
FROM all_constraints c
JOIN all_cons_columns cc ON cc.owner = c.owner AND cc.constraint_name = c.constraint_name
WHERE c.constraint_type IN (\'P\', \'U\', \'R\') AND '.where_owner("c.owner")." AND c.table_name = ".q($R).'
ORDER BY cc.position',$h)as$J){$B=$J["name"];$I[$B]["type"]=$J["type"];$I[$B]["r_owner"]=$J["r_owner"];$I[$B]["r_constraint"]=$J["r_constraint"];$I[$B]["delete_rule"]=$J["delete_rule"];$I[$B]["columns"][]=$J["column"];}if(!$h)$jb[$R]=$I;return$I;}function
indexes($R,$h=null){$I=[];$Zb=[];$sj=[];foreach(table_constraints($R,$h)as$B=>$Yb)$Zb[$B]=$Yb["type"];foreach(get_rows("SELECT aic.*, atc.data_default, ai.status, ai.partitioned
FROM all_ind_columns aic
JOIN all_indexes ai ON ai.owner = aic.index_owner AND ai.index_name = aic.index_name
LEFT JOIN all_tab_cols atc ON aic.column_name = atc.column_name AND aic.table_name = atc.table_name AND aic.index_owner = atc.owner
WHERE aic.table_name = ".q($R)." AND ".where_owner("aic.table_owner")."
ORDER BY aic.column_position",$h)as$J){$Hf=$J["INDEX_NAME"];$Lb=$J["DATA_DEFAULT"];$Lb=($Lb?trim($Lb,'"'):$J["COLUMN_NAME"]);$U=idx($Zb,$Hf);$I[$Hf]["type"]=($U=="P"?"PRIMARY":($U=="U"?"UNIQUE":"INDEX"));$I[$Hf]["invalid"]=($J["STATUS"]=="UNUSABLE");$I[$Hf]["columns"][]=$Lb;$I[$Hf]["lengths"][]=($J["CHAR_LENGTH"]&&$J["CHAR_LENGTH"]!=$J["COLUMN_LENGTH"]?$J["CHAR_LENGTH"]:null);$I[$Hf]["descs"][]=($J["DESCEND"]&&$J["DESCEND"]=="DESC"?'1':null);if($J["PARTITIONED"]=="YES")$sj[$Hf]=q($Hf);}if($sj){$Af=where_owner("index_owner")." AND index_name IN (".implode(", ",$sj).") AND status = 'UNUSABLE'";foreach(get_rows("SELECT index_name FROM all_ind_partitions WHERE $Af UNION SELECT index_name FROM all_ind_subpartitions WHERE $Af",$h)as$J)$I[$J["INDEX_NAME"]]["invalid"]=true;}uasort($I,function($ia,$Ra){$Ni=["PRIMARY"=>0,"UNIQUE"=>1,"INDEX"=>2];return$Ni[$ia["type"]]-$Ni[$Ra["type"]];});return$I;}function
view($B){$K=get_rows('SELECT text "select" FROM '.views_table("view_name, text").' WHERE view_name = '.q($B));return($K?$K[0]:[]);}function
collations(){return[];}function
information_schema($k,$L=""){return
in_array($L!=""?$L:$k,["INFORMATION_SCHEMA","SYS","SYSTEM"]);}function
error(){return
h(connection()->error);}function
explain(Db$g,$G){$g->query("EXPLAIN PLAN FOR $G");return$g->query("SELECT * FROM plan_table");}function
found_rows(array$S,array$Z){}function
auto_increment(){return"";}function
alter_table($R,$B,array$o,array$ue,$f,$xd,$Hb,$Na,$tj){$b=$ed=[];$Vi=($R?fields($R):[]);foreach($o
as$n){$X=$n[1];if($X&&$n[0]!=""&&idf_escape($n[0])!=$X[0])queries("ALTER TABLE ".table($R)." RENAME COLUMN ".idf_escape($n[0])." TO $X[0]");$Ui=$Vi[$n[0]];if($X&&$Ui){$ti=process_field($Ui,$Ui);if(array_slice($X,1)==array_slice($ti,1))continue;if($X[2]==$ti[2])$X[2]="";}if($X){list($X[2],$X[3])=[$X[3],$X[2]];$b[]=($R!=""?($n[0]!=""?"MODIFY (":"ADD ("):"  ").implode($X).($R!=""?")":"");}else$ed[]=idf_escape($n[0]);}$b=array_merge($b,$ue);if($R=="")return
queries("CREATE TABLE ".table($B)." (\n".implode(",\n",$b)."\n)");return(!$b||queries("ALTER TABLE ".table($R)."\n".implode("\n",$b)))&&(!$ed||queries("ALTER TABLE ".table($R)." DROP (".implode(", ",$ed).")"))&&($R==$B||queries("ALTER TABLE ".table($R)." RENAME TO ".table($B)));}function
alter_indexes($R,$b){$ed=[];$kk=[];foreach($b
as$X){if($X[0]!="INDEX"){$X[2]=preg_replace('~ DESC$~','',$X[2]);$i=($X[2]=="DROP"?"\nDROP CONSTRAINT ".idf_escape($X[1]):"\nADD".($X[1]!=""?" CONSTRAINT ".idf_escape($X[1]):"")." $X[0] ".($X[0]=="PRIMARY"?"KEY ":"")."(".implode(", ",$X[2]).")");array_unshift($kk,"ALTER TABLE ".table($R).$i);}elseif($X[2]=="DROP")$ed[]=idf_escape($X[1]);else$kk[]="CREATE INDEX ".idf_escape($X[1]!=""?$X[1]:uniqid($R."_"))." ON ".table($R)." (".implode(", ",$X[2]).")";}if($ed)array_unshift($kk,"DROP INDEX ".implode(", ",$ed));foreach($kk
as$G){if(!queries($G))return
false;}return
true;}function
foreign_keys($R){$I=[];$Om=[];foreach(table_constraints($R)as$B=>$Yb){if($Yb["type"]=="R"){$I[$B]=["source"=>$Yb["columns"],"target"=>[],"on_delete"=>$Yb["delete_rule"],"on_update"=>null,];$Om[$B]=[$Yb["r_owner"],$Yb["r_constraint"]];}}if($Om){$Z=[];foreach($Om
as$Lm)$Z[]="(owner = ".q($Lm[0])." AND constraint_name = ".q($Lm[1]).")";foreach(get_rows("SELECT owner, constraint_name, table_name, column_name FROM all_cons_columns WHERE ".implode(" OR ",array_unique($Z))." ORDER BY position")as$J){foreach($Om
as$B=>$Lm){if($Lm==[$J["OWNER"],$J["CONSTRAINT_NAME"]]){$I[$B]["db"]=($J["OWNER"]==DB?"":$J["OWNER"]);$I[$B]["table"]=$J["TABLE_NAME"];$I[$B]["target"][]=$J["COLUMN_NAME"];}}}}return$I;}function
trigger($B,$R){if($B=="")return[];$K=get_rows('SELECT trigger_name "Trigger", trigger_type "Type", triggering_event "Event", trigger_body "Statement"
FROM all_triggers
WHERE trigger_name = '.q($B)." AND ".where_owner());$I=reset($K);if($I){$U=$I["Type"];$I["Timing"]=(preg_match('~^(BEFORE|AFTER|INSTEAD OF)~',$U,$A)?$A[1]:$U);$I["Type"]=(preg_match('~EACH ROW~',$U)||$U=="INSTEAD OF"?"FOR EACH ROW":"");}return($I?:[]);}function
triggers($R){$I=[];foreach(get_rows("SELECT trigger_name, trigger_type, triggering_event FROM all_triggers WHERE table_name = ".q($R)." AND ".where_owner())as$J)$I[$J["TRIGGER_NAME"]]=[preg_replace('~ (STATEMENT|EACH ROW)$~','',$J["TRIGGER_TYPE"]),$J["TRIGGERING_EVENT"]];return$I;}function
trigger_options(){return["Timing"=>["BEFORE","AFTER","INSTEAD OF"],"Event"=>["INSERT","UPDATE","DELETE","INSERT OR UPDATE","INSERT OR DELETE","UPDATE OR DELETE","INSERT OR UPDATE OR DELETE"],"Type"=>["FOR EACH ROW",""],];}function
truncate_tables(array$T){return
apply_queries("TRUNCATE TABLE",$T);}function
drop_views(array$ko){return
apply_queries("DROP VIEW",$ko);}function
drop_tables(array$T){return
apply_queries("DROP TABLE",$T);}function
last_id($H){return"0";}function
create_database($k,$Hb){$I=queries("CREATE USER ".idf_escape($k)." NO AUTHENTICATION");return($I?queries("GRANT UNLIMITED TABLESPACE TO ".idf_escape($k)):$I);}function
drop_databases(array$j){$I=true;foreach($j
as$k)$I=!!queries("DROP USER ".idf_escape($k)." CASCADE")&&$I;return$I;}function
rename_database($B,$Hb){return!!queries("ALTER USER ".idf_escape(DB)." RENAME TO ".idf_escape($B));}function
show_variables(){return
get_rows('SELECT name, display_value FROM v$parameter');}function
show_status(){$I=[];$K=get_rows('SELECT * FROM v$instance');foreach(reset($K)as$x=>$X)$I[]=[$x,$X];return$I;}function
process_list(){return
get_rows('SELECT
	sess.process AS "process",
	sess.username AS "user",
	sess.schemaname AS "schema",
	sess.status AS "status",
	sess.wait_class AS "wait_class",
	sess.seconds_in_wait AS "seconds_in_wait",
	sql.sql_text AS "sql_text",
	sess.machine AS "machine",
	sess.port AS "port"
FROM v$session sess
LEFT JOIN v$sql sql ON sql.sql_id = sess.sql_id
WHERE sess.type = \'USER\'
ORDER BY PROCESS
');}function
convert_field(array$n){if($n["type"]=="sdo_geometry")return"SDO_UTIL.TO_WKTGEOMETRY(".idf_escape($n["field"]).")";}function
unconvert_field(array$n,$I){return($n["type"]=="sdo_geometry"?"SDO_UTIL.FROM_WKTGEOMETRY($I)":$I);}function
support($Zd){return
preg_match('~^(columns|database|drop_col|fast_status|indexes|descidx|processlist|sql|status|table|trigger|variables|view|view_trigger)$~',$Zd);}}class
Adminer{static$instance;var$error='';function
name(){return"<a href='https://www.adminer.org/'".target_blank()." id='h1'><img src='".h(preg_replace("~\\?.*~","",ME)."?file=logo.svg&version=6.3.0+9afc53aa")."' width='24' height='24' alt='' id='logo'>Adminer</a>";}function
credentials(){return[SERVER,$_GET["username"],get_password()];}function
connectSsl(){}function
permanentLogin($i=false){return
password_file($i);}function
bruteForceKey(){return$_SERVER["REMOTE_ADDR"];}function
verifyLoginToken(){return
true;}function
serverName($N){return
h($N);}function
database(){return
DB;}function
databases($se=true){return
get_databases($se);}function
pluginsLinks(){}function
operators($um=null){return
driver()->operators($um);}function
schemas(){$I=schemas();if($_GET["ns"]!=""&&!in_array($_GET["ns"],$I))array_unshift($I,$_GET["ns"]);return$I;}function
queryTimeout(){return
2;}function
afterConnect(){}function
headers(){}function
csp(array$nc){return$nc;}function
verifyVersion(){return
true;}function
donation(){return"<a href='https://www.adminer.org/donation/'".target_blank()." id='donation' title='".lang(44)."'>❤️</a>";}function
serviceWorker(){service_worker();}function
manifest(){$qf=$_SERVER["HTTP_HOST"]?:$_SERVER["SERVER_NAME"];$pl=preg_replace('~\?.*~','',ME)?:'.';return['name'=>"Adminer".($qf!=""?" - $qf":""),'short_name'=>'Adminer','description'=>lang(45),'start_url'=>$pl,'scope'=>$pl,'display'=>'minimal-ui','icons'=>[['src'=>preg_replace("~\\?.*~","",ME)."?file=logo.svg&version=6.3.0+9afc53aa",'sizes'=>'any','type'=>'image/svg+xml']],];}function
head($sc=null){return
true;}function
bodyClass(){echo" adminer";}function
css(){$I=[];foreach(["","-dark"]as$Bh){$p="adminer$Bh.css";if(file_exists($p)){$ge=file_get_contents($p);$I["$p?v=".crc32($ge)]=($Bh?"dark":(preg_match('~prefers-color-scheme:\s*dark~',$ge)?'':'light'));}}return$I;}function
loginForm(){echo"<table class='layout'>\n",adminer()->loginFormField('driver','<tr><th>'.lang(46).'<td>',html_select("auth[driver]",SqlDriver::$drivers,DRIVER,on('change','loginDriver'))),adminer()->loginFormField('server','<tr><th>'.lang(47).'<td>',"<input name='auth[server]' value='".h(SERVER)."' title='".lang(48)."' placeholder='localhost' autocapitalize='off'>"),adminer()->loginFormField('username','<tr><th>'.lang(49).'<td>','<input name="auth[username]" id="username" autofocus value="'.h($_GET["username"]).'" autocomplete="username" autocapitalize="off">'.script("fire(qs('#username').form['auth[driver]'], 'change');")),adminer()->loginFormField('password','<tr><th>'.lang(50).'<td>','<input type="password" name="auth[password]" autocomplete="current-password">'),adminer()->loginFormField('db','<tr><th>'.lang(17).'<td>','<input name="auth[db]" value="'.h($_GET["db"]).'" autocapitalize="off">'),"</table>\n","<p><input type='submit' value='".lang(51)."'>\n",checkbox("auth[permanent]",1,$_COOKIE["adminer_permanent"],lang(52))."\n";}function
loginFormField($B,$hf,$Y){return$hf.$Y."\n";}function
login($Sg,$E){if($E=="")return
lang(53).require_password_link(null);if(!Driver::$passwords)return
lang(54).require_password_link($E);if(!password_required())return
lang(55).require_password_link($E);return
true;}function
tableName(array$um){return
h($um["Name"]);}function
fieldName(array$n,$Ni=0){$U=$n["full_type"].($n["null"]?" NULL":"");$f=$n["comment"];return'<span title="'.h($U.($f!=""?($U?": ":"").$f:'')).'">'.h($n["field"]).'</span>';}function
commentValue($U,$f){if($f==""||$U=='SCHEMA'||$U=='TABLE'||$U=='COLUMN')return
h($f);$Qj=function($Zk,$mb='td'){return
preg_replace('~^~m','<tr>',preg_replace('~\|~',"<$mb>",preg_replace('~\|$~m',"",rtrim($Zk))));};$R='(\+--[-+]+\+\n)';$J='(\| .* \|\n)';return"<pre>\n".preg_replace_callback("~^$R?$J$R?($J*)$R?~m",function($A)use($Qj){return"<table>\n".($A[1]?"<thead>".$Qj($A[2],'th')."<tbody>\n":$Qj($A[2])).$Qj($A[4])."\n</table>";},preg_replace('~(\n(    -|mysql)&gt; )(.+)~',"\\1<code class='jush-sql'>\\3</code>",preg_replace('~(.+)\n---+\n~',"<b>\\1</b>\n",h($f))))."</pre>\n";}function
commentInput($U,$c,$f){$Y=h($f);return(preg_match('~\n~',$Y)?"<textarea$c rows='2' cols='".($U=='TABLE'?20:30)."' style='vertical-align: bottom;'>\n$Y</textarea>":"<input$c value='$Y'>");}function
selectLinks(array$um,$O=""){$B=$um["Name"];echo'<p class="links">';$Ng=[];if($B!="")$Ng["select"]=lang(56);if(support("table")||support("indexes"))$Ng["table"]=lang(57);if(support("table")){if(is_view($um)){if(support("view"))$Ng["view"]=lang(58);}elseif(function_exists('Adminer\alter_table')&&$B!="")$Ng["create"]=lang(59);}if($O!==null)$Ng["edit"]=lang(60);foreach($Ng
as$x=>$X)echo" <a href='".h(ME)."$x=".url_escape($B).($x=="edit"?$O:"")."'".bold(isset($_GET[$x])).">$X</a>";echo"\n";}function
foreignKeys($R){return
foreign_keys($R);}function
backwardKeys($R,$tm){return[];}function
backwardKeysPrint(array$Ta,array$J){}function
selectQuery($G,$cm,$Xd=false){$I="\n";if(!$Xd&&($oo=driver()->warnings())){$t="warnings";$I=", <a href='#$t' class='toggle'>".lang(61)."</a>"."$I<div id='$t' class='hidden'>\n$oo</div>\n";}return"<p><code class='sql jush-".JUSH."'>".h(str_replace("\n"," ",$G))."</code> <span class='time'>(".format_time($cm).")</span>".(support("sql")?" <a href='".h(ME)."sql=".url_escape($G)."' class='hover'>".lang(20)."</a>":"").$I;}function
sqlCommandQuery($G){return
shorten_utf8(trim($G),1000);}function
sqlPrintAfter(){}function
sqlUrlLength(){return
2000;}function
explain(Db$g,$G,array$Ri){$H=explain($g,$G);if(!$H)return"";ob_start();print_select_result($H,$g,$Ri);return
ob_get_clean();}function
rowDescription($R){return"";}function
rowDescriptions(array$K,array$ve){return$K;}function
selectLink($X,array$n){}function
selectVal($X,$_,array$n,$Yi){$I=($X===null?"<i>NULL</i>":(preg_match("~char|binary|boolean~",$n["type"])&&!preg_match("~var~",$n["type"])?"<code>$X</code>":(preg_match('~^jsonb?$~',$n["full_type"])?"<code class='jush-json'>$X</code>":$X)));if(is_blob($n)&&!is_utf8($X))$I="<i>".lang(62,strlen($Yi))."</i>";return($_?"<a href='".h($_)."'".(is_url($_)?target_blank():"").">$I</a>":$I);}function
editVal($X,array$n){return$X;}function
config(){return[];}function
tableStructurePrint(array$o,$um=null){echo"<div class='scrollable'>\n","<table class='nowrap odds'>\n","<thead><tr><th>".lang(63)."<th>".lang(64).(support("comment")?"<th>".lang(65):"")."<tbody>\n";$Yn=(support("type")?types():[]);foreach($o
as$n){echo"<tr><th>".h($n["field"]).doc_link([JUSH=>$n["doc"]]);$U=h($n["full_type"]);$Hb=h($n["collation"]);echo"<td><span title='$Hb'>".(in_array($U,$Yn)?"<a href='".h(ME.'type='.url_escape($U))."'>$U</a>":$U.($Hb&&isset($um["Collation"])&&$Hb!=$um["Collation"]?" $Hb":""))."</span>",($n["null"]?" <i>NULL</i>":""),($n["auto_increment"]?" <i>".lang(66)."</i>":""),(isset($n["default"])?" <span title='".lang(67)."'>[<b>".($n["generated"]||$n["default_sql"]?"<code class='sql jush-".JUSH."'>".shorten_utf8(preg_replace('~\s+~',' ',ltrim($n["default"])),80,"</code>"):h($n["default"]))."</b>]</span>":""),(support("comment")?"<td>".adminer()->commentValue('COLUMN',$n["comment"]):""),"\n";}echo"</table>\n","</div>\n";}function
tableIndexesPrint(array$w,array$um){$nj=false;foreach($w
as$B=>$v)$nj|=!!$v["partial"];echo"<table>\n";$Bc=first(driver()->indexAlgorithms($um));foreach($w
as$B=>$v){ksort($v["columns"]);$Zj=[];foreach($v["columns"]as$x=>$X)$Zj[]="<i>".h($X)."</i>".($v["lengths"][$x]?"(".h($v["lengths"][$x]).")":"").($v["descs"][$x]?" DESC":"");echo"<tr title='".h($B)."'>","<th>".h($v["type"]).($Bc&&$v['algorithm']!=$Bc?" (".h($v['algorithm']).")":""),"<td>".implode(", ",$Zj).($v["invalid"]?" <span class='error'>INVALID</span>":"");if($nj)echo"<td>".($v['partial']?"<code class='sql jush-".JUSH."'>WHERE ".h($v['partial']):"");echo"\n";}echo"</table>\n";}function
namePattern($U){if($U=="FOREIGN"||$U=="CHECK")return"";if($U=="TRIGGER")return"{table}_{timing}{event}";return(JUSH=="sql"?"":"{table}_")."{columns}";}function
selectColumnInput($c,array$e,$Y="",$Fj=""){return
select_input($c,$e,$Y,$Fj);}function
selectColumnsPrint(array$M,array$e){print_fieldset("select",lang(68),$M);$s=0;$M[""]=[];foreach($M
as$x=>$X){$X=idx($_GET["columns"],$x,[]);$d=adminer()->selectColumnInput(" name='columns[$s][col]' data-default=''".on('change',($x!==""?'selectFieldChange':'selectAddRow')),$e,$X["col"]);echo"<div>".(driver()->functions||driver()->grouping?html_select("columns[$s][fun]",[-1=>""]+array_filter([lang(69)=>driver()->functions,lang(70)=>driver()->grouping]),$X["fun"]," data-default=''".on('change',($x!==""?'helpClose':'selectFunAddRow')).on_help_value(' (.*)|$','($1)'))."($d)":$d)."</div>\n";$s++;}echo"</div></fieldset>\n";}function
selectSearchPrint(array$Z,array$e,array$w,$um=null){print_fieldset("search",lang(18),$Z);foreach($w
as$s=>$v){if($v["type"]=="FULLTEXT")echo"<div>(<i>".implode("</i>, <i>",array_map('Adminer\h',$v["columns"]))."</i>) ".h(driver()->fulltextOperator)," <input type='search' name='fulltext[$s]' value='".h(idx($_GET["fulltext"],$s))."' data-default=''".on('input','selectFieldChange').">",(JUSH=='sql'?checkbox("boolean[$s]",1,isset($_GET["boolean"][$s]),"BOOL"):''),"</div>\n";}$Gi=adminer()->operators($um);foreach(array_merge((array)$_GET["where"],[[]])as$s=>$X){if(!$X||(("$X[col]$X[val]"!=""||preg_match('~NULL$~',$X["op"]))&&in_array($X["op"],$Gi)))echo"<div>".adminer()->selectColumnInput(" name='where[$s][col]' data-default=''".on('change',($X?'selectFieldChange':'selectAddRow')),$e,$X["col"],"(".lang(71).")"),html_select("where[$s][op]",$Gi,$X["op"]," data-default='".h(first($Gi))."'".on('change','selectFirstChange')),"<input type='search' name='where[$s][val]' value='".h($X["val"])."' data-default=''".on('input','selectFirstChange').on('keydown','selectSearchKeydown').on('search','selectSearchSearch').">","</div>\n";}echo"</div></fieldset>\n";}function
selectOrderPrint(array$Ni,array$e,array$w){print_fieldset("sort",lang(72),$Ni);$s=0;foreach((array)$_GET["order"]as$x=>$X){if($X!=""){echo"<div>".adminer()->selectColumnInput(" name='order[$s]' data-default=''".on('change','selectFieldChange'),$e,$X),checkbox("desc[$s]",1,isset($_GET["desc"][$x]),lang(73))."</div>\n";$s++;}}echo"<div>".adminer()->selectColumnInput(" name='order[$s]' data-default=''".on('change','selectAddRow'),$e),checkbox("desc[$s]",1,false,lang(73))."</div>\n","</div></fieldset>\n";}function
selectLimitPrint($z){echo"<fieldset><legend>".lang(74)."</legend><div>","<input type='number' name='limit' class='size' value='".h($z?:"")."' data-default='50'".on('input','selectFieldChange').">","</div></fieldset>\n";}function
selectLengthPrint($Sm){echo"<fieldset><legend>".lang(75)."</legend><div>","<input type='number' name='text_length' class='size' value='".h($Sm)."' data-default='100'>","</div></fieldset>\n";}function
selectActionPrint(array$w){echo"<fieldset><legend>".lang(76)."</legend><div>","<input type='submit' value='".lang(68)."'>"," <span id='noindex' title='".lang(77)."'></span>","<script".nonce().">\n","const indexColumns = ";$e=[];foreach($w
as$v){$rc=reset($v["columns"]);if($v["type"]!="FULLTEXT"&&$rc)$e[$rc]=1;}$e[""]=1;foreach($e
as$x=>$X)json_row($x);echo";\n","selectFieldChange.call(qs('#form')['select']);\n","</script>\n","</div></fieldset>\n";}function
selectCommandPrint(){return!information_schema(DB);}function
selectImportPrint(){return!information_schema(DB);}function
selectEmailPrint(array$sd,array$e){}function
selectColumnsProcess(array$e,array$w){$M=[];$Oe=[];foreach((array)$_GET["columns"]as$x=>$X){if($X["fun"]=="count"||($X["col"]!=""&&(!$X["fun"]||in_array($X["fun"],driver()->functions)||in_array($X["fun"],driver()->grouping)))){$M[$x]=apply_sql_function($X["fun"],($X["col"]!=""?idf_escape($X["col"]):"*"));if(!in_array($X["fun"],driver()->grouping))$Oe[]=$M[$x];}}return[$M,$Oe];}function
selectSearchProcess(array$o,array$w,$um=null){$I=[];foreach($w
as$s=>$v){if($v["type"]=="FULLTEXT"&&idx($_GET["fulltext"],$s)!="")$I[]=driver()->fulltextSql($s,$v,$_GET["fulltext"][$s],isset($_GET["boolean"][$s]));}$Gi=adminer()->operators($um);foreach((array)$_GET["where"]as$x=>$X){$X+=["col"=>"","op"=>first($Gi),"val"=>""];$_GET["where"][$x]=$X;$Fb=$X["col"];if(("$Fb$X[val]"!=""||preg_match('~NULL$~',$X["op"]))&&in_array($X["op"],$Gi)){if($X["op"]=="SQL"&&(!$_POST||!verify_token()))SqlDb::$untrusted=true;$Sb=[];foreach(($Fb!=""?[$Fb=>idx($o,$Fb,[])]:$o)as$B=>$n){if($Fb!=""||is_searchable($n,$X))$Sb[]=adminer()->selectSearchCondition(driver()->convertSearch(idf_escape($B),$X,$n),$X["op"],$X["val"]);}$I[]=(count($Sb)==1?$Sb[0]:($Sb?"(".implode(" OR ",$Sb).")":"1 = 0"));}}return$I;}function
selectSearchCondition($d,$Ci,$X){if(preg_match('~IN$~',$Ci))return"$d $Ci ".($X!=""?process_in($X):"(NULL)");if($Ci=="BETWEEN")return"$d $Ci ".process_between($X);if($Ci=="SQL")return"$d $X";if(preg_match('~^(I?LIKE) %%$~',$Ci,$A))return"$d $A[1] ".q("%$X%");if($Ci=="FIND_IN_SET")return"$Ci(".q($X).", $d)";return"$d $Ci".(!preg_match('~NULL$~',$Ci)?" ".q($X):"");}function
selectOrderProcess(array$o,array$w){$I=[];foreach((array)$_GET["order"]as$x=>$X){if($X!="")$I[]=(preg_match('~^((COUNT\(DISTINCT |[A-Z0-9_]+\()(`(?:[^`]|``)+`|"(?:[^"]|"")+")\)|COUNT\(\*\))$~',$X)?$X:idf_escape($X)).(isset($_GET["desc"][$x])?" DESC".(JUSH=='pgsql'&&idx($o[$X],"null")?" NULLS LAST":""):"");}return$I;}function
selectLimitProcess(){return(isset($_GET["limit"])?intval($_GET["limit"]):50);}function
selectLengthProcess(){return(isset($_GET["text_length"])?"$_GET[text_length]":"100");}function
selectEmailProcess(array$Z,array$ve){return
false;}function
selectQueryBuild(array$M,array$Z,array$Oe,array$Ni,$z,$D){return"";}function
executeQuery($G,$lb){return$lb();}function
messageQuery($G,$Um,$Xd=false){restart_session();$nf=&get_session("queries");if(!idx($nf,$_GET["db"]))$nf[$_GET["db"]]=[];if(strlen($G)>1e6)$G=preg_replace('~[\x80-\xFF]+$~','',substr($G,0,1e6))."\n…";$nf[$_GET["db"]][]=[$G,time(),$Um];$Xl="sql-".count($nf[$_GET["db"]]);$I="<a href='#$Xl' class='toggle'>".lang(78)."</a> ".copy_icon()."\n";if(!$Xd&&($oo=driver()->warnings())){$t="warnings-".count($nf[$_GET["db"]]);$I="<a href='#$t' class='toggle'>".lang(61)."</a>, $I<div id='$t' class='hidden'>\n$oo</div>\n";}return" <span class='time'>".@date("H:i:s")."</span>"." $I<div id='$Xl' class='hidden'><pre><code class='jush-".JUSH."'>".shorten_utf8($G,1e4)."</code></pre>".($Um?" <span class='time'>($Um)</span>":'').(support("sql")?'<p><a href="'.h(str_replace("db=".url_escape(DB),"db=".url_escape($_GET["db"]),ME).'sql=&history='.(count($nf[$_GET["db"]])-1)).'">'.lang(20).'</a>':'').'</div>';}function
error(){return
error();}function
editRowPrint($R,array$o,$J,$Ln,$G='',$Um=''){echo($G!=""?"<p><code class='sql jush-".JUSH."'>".h(str_replace("\n"," ",$G))."</code> <span class='time'>($Um)</span>\n":"");}function
editFunctions(array$n){$I=($n["null"]?"NULL/":"");$df=isset($_GET["select"])||where($_GET);foreach([driver()->insertFunctions,driver()->editFunctions]as$x=>$Ie){if(!$x||(!isset($_GET["call"])&&$df)){foreach($Ie
as$Bj=>$X){if(!$Bj||preg_match("~$Bj~",$n["type"]))$I
.="/$X";}}if($x&&$Ie&&!preg_match('~set|bool~',$n["type"])&&!is_blob($n))$I
.="/SQL";}if($n["auto_increment"]&&!$df)$I=lang(66);return
explode("/",$I);}function
editInput($R,array$n,$c,$Y){if($n["type"]=="enum")return(isset($_GET["select"])?"<label><input type='radio'$c value='orig' checked><i>".lang(12)."</i></label> ":"").enum_input("radio",$c,$n,$Y,"NULL");return"";}function
editHint($R,array$n,$Y){return"";}function
processInput(array$n,$Y,$r=""){if($r=="SQL")return$Y;$B=$n["field"];$I=q($Y);if(preg_match('~^(now|getdate|uuid)$~',$r))$I="$r()";elseif(preg_match('~^current_(date|timestamp)$~',$r))$I=$r;elseif(preg_match('~^([+-]|\|\|)$~',$r))$I=idf_escape($B)." $r $I";elseif(preg_match('~^[+-] interval$~',$r))$I=idf_escape($B)." $r ".(preg_match("~^(\\d+|'[0-9.: -]') [A-Z_]+\$~i",$Y)&&JUSH!="pgsql"?$Y:$I);elseif(preg_match('~^(addtime|subtime|concat)$~',$r))$I="$r(".idf_escape($B).", $I)";elseif(preg_match('~^(md5|sha1|password|encrypt)$~',$r))$I="$r($I)";return
unconvert_field($n,$I);}function
dumpOutput(){$I=['text'=>lang(79),'file'=>lang(80)];if(function_exists('gzencode'))$I['gz']='gzip';return$I;}function
dumpFormat(){return(support("dump")?['sql'=>'SQL']:[])+['csv'=>'CSV,','csv;'=>'CSV;','tsv'=>'TSV'];}function
dumpPrint(){}function
dumpDatabase($k){}function
dumpTable($R,$km,$hg=0){if($_POST["format"]!="sql"){echo"\xef\xbb\xbf";if($km)dump_csv(array_keys(fields($R)));}else{if($hg==2){$o=[];foreach(fields($R)as$B=>$n)$o[]=idf_escape($B)." ".full_type_sql($n);$i="CREATE TABLE ".table($R)." (".implode(", ",$o).")";}else$i=create_sql($R,$_POST["auto_increment"],$km);set_utf8mb4($i);if($km&&$i){if(($km=="DROP+CREATE"&&!function_exists('Adminer\drop_sql'))||$hg==1)echo"DROP ".($hg==2?"VIEW":"TABLE")." IF EXISTS ".table($R).";\n";if($hg==1)$i=remove_definer($i);echo"$i;\n\n";}}}function
dumpData($R,$km,$G,array$M=[],array$Z=[],array$Oe=[],array$Ni=[]){if($km){$dh=(JUSH=="sqlite"?0:1048576);$o=[];$wf=false;if($_POST["format"]=="sql"){if($km=="TRUNCATE+INSERT"&&!function_exists('Adminer\truncate_all_sql'))echo
truncate_sql($R).";\n";$o=fields($R);if(JUSH=="mssql"){foreach($o
as$n){if($n["auto_increment"]){echo"SET IDENTITY_INSERT ".table($R)." ON;\n";$wf=true;break;}}}}$H=($G!=""?connection()->query($G,1):driver()->select($R,($M?:["*"]),$Z,$Oe,$Ni,0));if($H){$Sf="";$eb="";$qg=[];$Je=[];$mm="";$be=($R!=''?'fetch_assoc':'fetch_row');$jc=0;while($J=$H->$be()){if(!$qg){$fo=[];foreach($J
as$X){$n=$H->fetch_field();if(idx($o[$n->name],'generated')){$Je[$n->name]=true;continue;}$qg[]=$n->name;$x=idf_escape($n->name);$fo[]="$x = VALUES($x)";}$mm=($km=="INSERT+UPDATE"?"\nON DUPLICATE KEY UPDATE ".implode(", ",$fo):"").";\n";}foreach($J
as$x=>$X){if(is_array($X))$J[$x]=json_encode($X,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}if($_POST["format"]!="sql"){if($km=="table"){dump_csv($qg);$km="INSERT";}dump_csv($J);}else{if(!$Sf)$Sf="INSERT INTO ".table($R)." (".implode(", ",array_map('Adminer\idf_escape',$qg)).") VALUES";foreach($J
as$x=>$X){if($Je[$x]){unset($J[$x]);continue;}$n=$o[$x];$J[$x]=($X===null?"NULL":($X===false?0:unconvert_field($n,preg_match(number_type(),$n["type"])&&!preg_match('~\[~',$n["full_type"])&&is_numeric($X)?$X:(!is_blob($n)||is_utf8($X)?q($X):driver()->quoteBinary($X)))));}$Zk=($dh?"\n":" ")."(".implode(",\t",$J).")";if(!$eb)$eb=$Sf.$Zk;elseif(JUSH=='mssql'?$jc%1000!=0:strlen($eb)+4+strlen($Zk)+strlen($mm)<$dh)$eb
.=",$Zk";else{echo$eb.$mm;$eb=$Sf.$Zk;}}$jc++;}if($eb)echo$eb.$mm;}elseif($_POST["format"]=="sql")echo"-- ".str_replace("\n"," ",connection()->error)."\n";else
dump_csv([connection()->error]);if($wf)echo"SET IDENTITY_INSERT ".table($R)." OFF;\n";}}function
dumpFilename($uf){return
friendly_url($uf!=""?$uf:(SERVER?:"localhost"));}function
dumpHeaders($uf,$Ih=false){$cj=$_POST["output"];$Sd=(preg_match('~sql~',$_POST["format"])?"sql":($Ih?"tar":"csv"));header("Content-Type: ".($cj=="gz"?"application/x-gzip":($Sd=="tar"?"application/x-tar":($Sd=="sql"||$cj!="file"?"text/plain":"text/csv")."; charset=utf-8")));if($cj=="gz"){ob_start(function($Q){return
gzencode($Q);},1e6);}return$Sd;}function
dumpFooter(){if($_POST["format"]=="sql")echo"-- ".gmdate("Y-m-d H:i:s e")."\n";}function
importServerPath(){return"adminer.sql";}function
importPrint(){}function
importProcess(){return
false;}function
homepage(){echo'<p class="links">'.($_GET["ns"]==""&&support("database")?'<a href="'.h(ME).'database=">'.lang(81)."</a>\n":""),(support("scheme")?"<a href='".h(ME)."scheme='>".($_GET["ns"]!=""?lang(82):lang(83))."</a>\n":""),($_GET["ns"]!==""?'<a href="'.h(ME).'schema=">'.lang(84)."</a>\n":""),(support("privileges")?"<a href='".h(ME)."privileges='>".lang(85)."</a>\n":"");if($_GET["ns"]!=="")echo(support("routine")?"<a href='#routines'>".lang(86)."</a>\n":""),(support("sequence")?"<a href='#sequences'>".lang(87)."</a>\n":""),(support("type")?"<a href='#user-types'>".lang(0)."</a>\n":""),(support("event")?"<a href='#events'>".lang(88)."</a>\n":"");return
true;}function
navigation($Ah){echo"<h1>".adminer()->name()." <span class='version'>".VERSION;$ci=$_COOKIE["adminer_version"];echo" <a href='https://www.adminer.org/#download'".target_blank()." id='version'>".(version_compare(VERSION,$ci)<0?h($ci):"").version_iframe()."</a>","</span></h1>\n";switch_lang();if($Ah=="auth"){$cj="";foreach((array)$_SESSION["pwds"]as$ho=>$Dl){foreach($Dl
as$N=>$Zn){$B=h(get_setting("vendor-$ho-$N")?:get_driver($ho));foreach($Zn
as$V=>$E){if($B&&$E!==null){$_c=$_SESSION["db"][$ho][$N][$V];foreach(($_c?array_keys($_c):[""])as$k)$cj
.="<li><a href='".h(auth_url($ho,$N,$V,$k))."'>($B) ".h("$V@").($N!=""?adminer()->serverName($N):"").h($k!=""?" - $k":"")."</a>\n";}}}}if($cj)echo"<ul id='logins'".on('mouseover','menuOver').on('mouseout','menuOut').">\n$cj</ul>\n";}else{$T=[];if($_POST&&!$Ah&&DB!=""){connection()->select_db(DB);if(support("scheme")&&$_GET["ns"]!="")set_schema($_GET["ns"]);}if($_GET["ns"]!==""&&!$Ah&&DB!="")$T=table_status('',true);adminer()->syntaxHighlighting($T);adminer()->databasesPrint($Ah);$la=[];if(DB==""||!$Ah){if(support("sql")){$la['sql']="<a href='".h(ME)."sql='".bold(isset($_GET["sql"])&&!isset($_GET["import"])).">".lang(78)."</a>";$la['import']="<a href='".h(ME)."import='".bold(isset($_GET["import"])).">".lang(89)."</a>";}$la['dump']="<a href='".h(ME)."dump=".url_escape(isset($_GET["table"])?$_GET["table"]:$_GET["select"])."' id='dump'".bold(isset($_GET["dump"])).">".lang(90)."</a>";}$Bf=$_GET["ns"]!==""&&!$Ah&&DB!="";if($Bf&&function_exists('Adminer\alter_table'))$la['create']='<a href="'.h(ME).'create="'.bold($_GET["create"]==="").">".lang(91)."</a>";$la=adminer()->menuActions($la,$Ah);echo($la?"<p class='links'>\n".implode("\n",$la)."\n":"");$il=[];if($Bf){if(function_exists('Adminer\alter_table'))$il[]=[lang(91),ME."create="];if(support("view"))$il[]=[lang(92),ME."view="];$ae=['routine'=>['routines',lang(86)],'sequence'=>['sequences',lang(87)],'type'=>['user-types',lang(0)],'event'=>['events',lang(88)],];foreach($ae
as$Zd=>$hl){if(support($Zd))$il[]=[$hl[1],substr(ME,0,-1)."#$hl[0]"];}}if(DB!=""&&!$Ah&&support("extension"))$il[]=[lang(93),preg_replace('~\bns=[^&]*&~','',ME)."ns=#extensions"];echo($il?script("jumpSections.unshift(...".json_encode($il,JSON_UNESCAPED_UNICODE).");"):"");if($Bf){if($T)adminer()->tablesPrint($T);else
echo"<p class='message'>".lang(19)."</p>\n";}}}function
syntaxHighlighting(array$T){echo
script_src(preg_replace("~\\?.*~","",ME)."?file=jush.js&version=6.3.0+9afc53aa",true);$Dh=preg_replace('~<(?=/script)~i','<\\',Driver::jushModule());echo($Dh?script("addEventListener('DOMContentLoaded', () => {\n$Dh\n});"):"");if(support("sql")){echo"<script".nonce().">\n";if($T){$Ng=[];foreach($T
as$R=>$U)$Ng[]=js_escape_re($R);echo"var jushLinks = { ".JUSH.":";json_row(js_escape(ME).(support("table")?"table":"select").'=$&','/\b(?<!\$)('.implode('|',$Ng).')(?!\$)\b/g',false);$Zl=["sql","check","event","procedure","trigger","view","type","table","processlist"];if(support("routine")&&array_intersect_key($_GET,array_flip($Zl))){foreach(routines()as$J)json_row(js_escape(ME).'function='.url_escape($J["SPECIFIC_NAME"]).'&name=$&','/\b'.js_escape_re($J["ROUTINE_NAME"]).'(?=["`\]]?\()/g',false);}json_row('');echo"};\n";foreach(["bac","bra","sqlite_quo","mssql_bra"]as$X)echo"jushLinks.$X = jushLinks.".JUSH.";\n";if(array_intersect_key($_GET,array_flip(["sql","check","event","procedure","trigger","view"]))){$fm=(isset($_GET["trigger"])?['INSERT INTO','UPDATE','DELETE FROM']:(isset($_GET["check"])?[]:(isset($_GET["view"])?['SELECT']:null)));$Pa=Driver::jushAutocomplete($T,$fm);echo($Pa?"addEventListener('DOMContentLoaded', () => { autocompleter = $Pa; });\n":"");}}echo"</script>\n";}echo
script("syntaxHighlighting('".doc_version()."', '".connection()->flavor."');");}function
databasesPrint($Ah){if(support("single_db"))return;$j=adminer()->databases();if(DB&&$j&&!in_array(DB,$j))array_unshift($j,DB);echo"<form>\n<p id='dbs'>\n";hidden_fields_get();$xc=on('mousedown','dbMouseDown').on('change','dbChange');echo"<label title='".lang(17)."'>".lang(94).": ".($j?html_select("db",[""=>""]+group_system($j),DB,$xc):"<input name='db' value='".h(DB)."' autocapitalize='off' size='19'>\n")."</label>","<input type='submit' value='".lang(31)."'".($j?" class='hidden'":"").">\n";if(support("scheme")){if($Ah!="db"&&DB!="")echo"<br><label>".lang(16).": ".html_select("ns",[""=>""]+group_system(adminer()->schemas(),true),$_GET["ns"],$xc)."</label>";}foreach(["import","sql","schema","dump","privileges"]as$X){if(isset($_GET[$X])){echo
input_hidden($X);break;}}echo"</p></form>\n";}function
menuActions(array$la,$Ah){return$la;}function
tablesPrint(array$T){echo"<ul id='tables'".on('mouseover','menuOver').on('mouseout','menuOut').">";foreach($T
as$R=>$P){$R="$R";$B=adminer()->tableName($P);if($B!=""&&!$P["dependent"])echo'<li><a href="'.h(ME).'select='.url_escape($R).'"'.bold($_GET["select"]==$R||$_GET["edit"]==$R,"select hover")." title='".lang(56)."'>".lang(13)."</a> ",(support("table")||support("indexes")?'<a href="'.h(ME).'table='.url_escape($R).'"'.bold(in_array($R,[$_GET["table"],$_GET["create"],$_GET["indexes"],$_GET["foreign"],$_GET["trigger"],$_GET["check"],$_GET["view"]]),(is_view($P)?"view":"structure"))." title='".lang(57)."'>$B</a>":"<span>$B</span>")."\n";}echo"</ul>\n";}function
showVariables(){return
show_variables();}function
showStatus(){return
show_status();}function
processList(){return
process_list();}function
killProcess($t){return
kill_process($t);}function
formatSizeValue($X){return
format_number($X);}}class
Plugins{private
static$append=['dumpFormat'=>true,'dumpOutput'=>true,'editRowPrint'=>true,'editFunctions'=>true,'config'=>true];var$plugins;var$disabled=[];var$drivers=[];var$driverFiles=[];var$error='';private$hooks=[];function
__construct($Jj){$dd=SqlDriver::$drivers;$jf=" href='https://www.adminer.org/plugins/#use'".target_blank();if($Jj===null){$Jj=[];$Xa="adminer-plugins";if(is_dir($Xa)){foreach(glob("$Xa/*.php")as$p){$he=SqlDriver::$drivers;$this->includeOnce($p);foreach(array_diff_key(SqlDriver::$drivers,$he)as$t=>$B)$this->driverFiles[$t]=$p;}}if(file_exists("$Xa.php")){$Df=$this->includeOnce("$Xa.php");if(is_array($Df)){foreach($Df
as$x=>$Gj)$Jj[is_object($Gj)?get_class($Gj):$x]=$Gj;}else$this->error
.=lang(95,"<b>$Xa.php</b>",$jf)."<br>";}foreach(get_declared_classes()as$Bb){if(!$Jj[$Bb]&&(preg_match('~^Adminer\w~i',$Bb)||is_subclass_of($Bb,'Adminer\Plugin'))){$yk=new
\ReflectionClass($Bb);$ac=$yk->getConstructor();if($ac&&$ac->getNumberOfRequiredParameters())$this->error
.=lang(96,$jf,"<b>$Bb</b>","<b>$Xa.php</b>")."<br>";else$Jj[$Bb]=new$Bb;}}}$Xf=array_filter($Jj,function($Gj){return!is_object($Gj);});if($Xf){$this->error
.=lang(97,$jf)."<br>";$Jj=array_diff_key($Jj,$Xf);}$qm=get_setting("plugins","adminer_config");foreach($Jj
as$x=>$Gj){if($Gj
instanceof
Plugin&&$Gj::$optional){$dm=idx($qm,get_class($Gj));if($dm!==null?!$dm:!$Gj->enabled){$this->disabled[]=$Gj;unset($Jj[$x]);}}}$this->drivers=array_diff_key(SqlDriver::$drivers,$dd);$this->plugins=$Jj;$qa=new
Adminer;$Jj[]=$qa;$yk=new
\ReflectionObject($qa);foreach($yk->getMethods()as$yh){foreach($Jj
as$Gj){$B=$yh->getName();if(method_exists($Gj,$B))$this->hooks[$B][]=$Gj;}}}function
includeOnce($p){return
include_once"./$p";}static
function
pluginName($Gj){return
preg_replace('~^ Adminer\\\\? ~','',preg_replace('~[A-Z]~',' $0',get_class($Gj)));}static
function
describe($Gj){$Lc=(method_exists($Gj,'description')?$Gj->description():"");if(!$Lc){$yk=new
\ReflectionObject($Gj);if(preg_match('~^/[\s*]+(.+?)(?:\s*\*/)?$~m',$yk->getDocComment(),$A))$Lc=$A[1];}$el=(method_exists($Gj,'screenshot')?$Gj->screenshot():"");return
h($Lc).($el?($Lc?" ":"")."(<a href='".h($el)."'".target_blank().">".lang(98)."</a>)":"");}static
function
checksum($p){$ge=str_replace("\r","",file_get_contents($p));$ge=preg_replace('~\n\tprotected \$translations = array\(.*?\n\t\);~s','',$ge);return
dechex(crc32($ge));}function
checksums(){$ie=array_values($this->driverFiles);foreach($this->plugins
as$Gj){$yk=new
\ReflectionObject($Gj);$ie[]=$yk->getFileName();}$I=[];foreach($ie
as$p)$I[basename($p,'.php')]=self::checksum($p);return$I;}static
function
officialChecksums(){return['adminer.js'=>'a0599090','backward-keys'=>'1c017d59','before-unload'=>'9674d220','config'=>'7f179b4f','dark-switcher'=>'8c201319','database-hide'=>'e304a899','designs'=>'c0a56b18','donation'=>'365aa745','dump-alter'=>'896b579e','dump-bz2'=>'c0d6de78','dump-date'=>'512aad63','dump-json'=>'f8aa6b99','dump-xml'=>'aedd2d3a','dump-zip'=>'96882e78','edit-foreign'=>'d04a0ac8','edit-textarea'=>'a178d7d9','editor-setup'=>'a7dc3a37','editor-views'=>'5c12b185','enum-option'=>'e9005cb0','file-upload'=>'10add0e8','foreign-system'=>'634ce119','frames'=>'b0e1d11a','highlight-codemirror'=>'ada4b569','highlight-monaco'=>'95cf93b3','highlight-prism'=>'19933b19','import-csv'=>'bcecfad2','login-ip'=>'4d174fea','login-otp'=>'66bd013d','login-passkey'=>'a73c386f','login-password-less'=>'e150daac','login-reverse-proxy'=>'24558ea2','login-servers'=>'19c42e45','login-ssl'=>'2b06a5b7','login-table'=>'811f8cef','menu-links'=>'55b9b15c','name-patterns'=>'84c10d09','remote-color'=>'c5e74631','row-numbers'=>'1ad1b6cf','select-email'=>'c33a2661','select-expression'=>'b6a4b3f1','select-foreign'=>'255d4532','select-image'=>'3cefeee4','slugify'=>'7b422f0e','sql-gemini'=>'c60ab309','sql-log'=>'b9aa4ea6','sql-url-length'=>'cd70f1a0','table-indexes-structure'=>'e0b1433','table-structure'=>'f71ec61b','tables-filter'=>'2729bf7','timeout'=>'9e9bce60','version-github'=>'627cadf9','version-noverify'=>'966937e9','clickhouse'=>'a5a5e5b4','elastic'=>'b7e4a70d','firebird'=>'5f3d9493','igdb'=>'39c028f6','imap'=>'35808394','mongo'=>'e6e3303b','redis'=>'c2f04eae','simpledb'=>'9eb464cf',];}function
__call($B,array$jj){$Fa=[];foreach($jj
as$x=>$X)$Fa[]=&$jj[$x];$I=null;foreach($this->hooks[$B]as$Gj){$Y=call_user_func_array([$Gj,$B],$Fa);if($Y!==null){if(!self::$append[$B])return$Y;$I=$Y+(array)$I;}}return$I;}}abstract
class
Plugin{static$optional=false;var$enabled=true;protected$translations=[];function
disable(){$this->enabled=false;return$this;}function
description(){return$this->lang('');}function
screenshot(){return"";}protected
function
lang($u,$ki=null){$Fa=func_get_args();$Fa[0]=idx($this->translations[LANG],$u)?:$u;return
call_user_func_array('Adminer\lang_format',$Fa);}}class
Password{private$password_hash;private$password_matches=null;function
__construct($yj){$this->password_hash=$yj;}function
description(){return
lang(99);}function
credentials(){$E=get_password();return[SERVER,$_GET["username"],($this->passwordMatches($E)&&!password_required()?"":$E)];}function
login($Sg,$E){if($this->passwordMatches($E))return
true;if(!Driver::$passwords||!password_required())return
false;}protected
function
passwordMatches($E){if($this->password_matches===null)$this->password_matches=(function_exists('password_verify')&&password_verify(strval($E),$this->password_hash));return$this->password_matches;}}Adminer::$instance=(function_exists('adminer_object')?adminer_object():(is_dir("adminer-plugins")||file_exists("adminer-plugins.php")?new
Plugins(null):new
Adminer));SqlDriver::$drivers=["server"=>"MySQL / MariaDB"]+SqlDriver::$drivers;if(!defined('Adminer\DRIVER')){define('Adminer\DRIVER',"server");if(extension_loaded("mysqli")&&$_GET["ext"]!="pdo"){class
Db
extends
\mysqli{static$instance;var$extension="MySQLi",$flavor='';function
__construct(){parent::init();}function
attach(array$N,$V,$E){mysqli_report(MYSQLI_REPORT_OFF);$Kj=$N["port"];$ud=("$N[host]$Kj$N[socket]"=="");$bm=adminer()->connectSsl();$Vn=($bm&&($bm['key']||$bm['cert']||$bm['ca']||isset($bm['verify'])));if($Vn)$this->ssl_set($bm['key'],$bm['cert'],$bm['ca'],'','');$I=@$this->real_connect((!$ud?$N["host"]:ini_get("mysqli.default_host")),(!$ud||$V!=""?$V:ini_get("mysqli.default_user")),(!$ud||$V.$E!=""?$E:ini_get("mysqli.default_pw")),null,($Kj!=""?intval($Kj):ini_get("mysqli.default_port")),($Kj!=""?null:$N["socket"]),($Vn?($bm['verify']!==false?MYSQLI_CLIENT_SSL:64):0));$this->options(MYSQLI_OPT_LOCAL_INFILE,0);return($I?'':$this->error);}function
set_charset($rb){if($this->send_charset($rb))return
true;$this->send_charset('utf8');return$this->query("SET NAMES $rb");}private
function
send_charset($rb){return
adminer()->executeQuery("SET NAMES $rb",function()use($rb){return
parent::set_charset($rb);});}function
select_db($wc){return
adminer()->executeQuery("USE ".idf_escape($wc),function()use($wc){return
parent::select_db($wc);});}function
query($G,$Kk=MYSQLI_STORE_RESULT){return
adminer()->executeQuery($G,function()use($G,$Kk){return
parent::query($G,$Kk);});}function
multi_query($G){return
adminer()->executeQuery($G,function()use($G){return
parent::multi_query($G);});}function
next_result(){return
self::more_results()&&parent::next_result();}function
quote($Q){return"'".$this->escape_string($Q)."'";}function
inTransaction(){return
false;}function
begin(){return(method_exists($this,'begin_transaction')?adminer()->executeQuery("BEGIN",function(){return$this->begin_transaction();}):!!$this->query("BEGIN"));}function
commit($qe=0,$B=null){return
adminer()->executeQuery("COMMIT",function(){return
parent::commit();});}function
rollback($qe=0,$B=null){return
adminer()->executeQuery("ROLLBACK",function(){return
parent::rollback();});}}}elseif(extension_loaded("mysql")&&!((ini_bool("sql.safe_mode")||ini_bool("mysql.allow_local_infile"))&&extension_loaded("pdo_mysql"))){class
Db
extends
SqlDb{private$link;function
attach(array$N,$V,$E){if(ini_bool("mysql.allow_local_infile"))return
lang(100,"'mysql.allow_local_infile'","MySQLi","PDO_MySQL");$Kj="$N[port]$N[socket]";$B=$N["host"].($Kj!=""?":$Kj":"");$this->link=@mysql_connect(($B!=""?$B:ini_get("mysql.default_host")),($B.$V!=""?$V:ini_get("mysql.default_user")),($B.$V.$E!=""?$E:ini_get("mysql.default_password")),true,131072);if(!$this->link)return
mysql_error();$this->server_info=mysql_get_server_info($this->link);return'';}function
set_charset($rb){return$this->send_charset($rb)||$this->send_charset('utf8');}private
function
send_charset($rb){return
adminer()->executeQuery("SET NAMES $rb",function()use($rb){return
mysql_set_charset($rb,$this->link);});}function
quote($Q){return"'".mysql_real_escape_string($Q,$this->link)."'";}function
select_db($wc){return
adminer()->executeQuery("USE ".idf_escape($wc),function()use($wc){return
mysql_select_db($wc,$this->link);});}function
query($G,$An=false){$H=adminer()->executeQuery($G,function()use($G,$An){return@($An?mysql_unbuffered_query($G,$this->link):mysql_query($G,$this->link));});$this->error="";if(!$H){$this->errno=mysql_errno($this->link);$this->error=mysql_error($this->link);return
false;}if($H===true){$this->affected_rows=mysql_affected_rows($this->link);$this->info=mysql_info($this->link);return
true;}return
new
Result($H);}}class
Result{var$num_rows;private$result;private$offset=0;function
__construct($H){$this->result=$H;$this->num_rows=mysql_num_rows($H);}function
fetch_assoc(){return
mysql_fetch_assoc($this->result);}function
fetch_row(){return
mysql_fetch_row($this->result);}function
fetch_field(){$I=mysql_fetch_field($this->result,$this->offset++);$I->orgtable=$I->table;$I->native_type=idx(["string"=>"varchar","real"=>"double"],$I->type,$I->type);return$I;}}}elseif(extension_loaded("pdo_mysql")){class
Db
extends
PdoDb{var$extension="PDO_MySQL";function
attach(array$N,$V,$E){$Ki=[\PDO::MYSQL_ATTR_LOCAL_INFILE=>false];if(isset($_GET["select"]))$Ki[1012]=false;$bm=adminer()->connectSsl();if($bm){if($bm['key'])$Ki[\PDO::MYSQL_ATTR_SSL_KEY]=$bm['key'];if($bm['cert'])$Ki[\PDO::MYSQL_ATTR_SSL_CERT]=$bm['cert'];if($bm['ca'])$Ki[\PDO::MYSQL_ATTR_SSL_CA]=$bm['ca'];if(isset($bm['verify']))$Ki[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=$bm['verify'];}$qf=$N["host"];$Kj=$N["port"];$Nl=$N["socket"];return$this->dsn("mysql:charset=utf8".($qf!=""?";host=$qf":'').($Kj!=""?";port=$Kj":($Nl!=""?";unix_socket=$Nl":"")),$V,$E,$Ki);}function
set_charset($rb){return$this->query("SET NAMES $rb");}function
select_db($wc){return$this->query("USE ".idf_escape($wc));}function
query($G,$An=false){$this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,!$An);return
parent::query($G,$An);}}}class
Driver
extends
SqlDriver{static$extensions=["MySQLi","MySQL","PDO_MySQL"];static$jush="sql";static$serverSocket=true;var$unsigned=["unsigned","zerofill","unsigned zerofill"];var$functions=["char_length","date","from_unixtime","lower","round","floor","ceil","sec_to_time","time_to_sec","upper"];var$grouping=["avg","count","count distinct","group_concat","max","min","sum"];var$partitionBy=["HASH","LINEAR HASH","KEY","LINEAR KEY","RANGE","LIST"];function
operators($um){return["=","<",">","<=",">=","!=","&","LIKE","LIKE %%","REGEXP","IN","BETWEEN","FIND_IN_SET","IS NULL","NOT LIKE","NOT REGEXP","NOT IN","IS NOT NULL","SQL"];}static
function
connect($N,$V,$E){$g=parent::connect($N,$V,$E);if(is_string($g)){if(function_exists('iconv')&&!is_utf8($g)&&strlen($Zk=iconv("windows-1252","utf-8//IGNORE",$g))>strlen($g))$g=$Zk;return$g;}$g->set_charset(charset($g));$g->query("SET sql_quote_show_create = 1, autocommit = 1");$g->flavor=(preg_match('~MariaDB~',$g->server_info)?'maria':'mysql');add_driver(DRIVER,($g->flavor=='maria'?"MariaDB":"MySQL"));return$g;}function
__construct(Db$g){parent::__construct($g);$this->types=[lang(35)=>["tinyint"=>3,"smallint"=>5,"mediumint"=>8,"int"=>10,"bigint"=>20,"decimal"=>66,"float"=>12,"double"=>21],lang(36)=>["date"=>10,"datetime"=>19,"timestamp"=>19,"time"=>10,"year"=>4],lang(37)=>["char"=>255,"varchar"=>65535,"tinytext"=>255,"text"=>65535,"mediumtext"=>16777215,"longtext"=>4294967295],lang(101)=>["enum"=>65535,"set"=>64],lang(38)=>["bit"=>20,"binary"=>255,"varbinary"=>65535,"tinyblob"=>255,"blob"=>65535,"mediumblob"=>16777215,"longblob"=>4294967295],lang(40)=>["geometry"=>0,"point"=>0,"linestring"=>0,"polygon"=>0,"multipoint"=>0,"multilinestring"=>0,"multipolygon"=>0,"geometrycollection"=>0],];$this->insertFunctions=["char"=>"md5/sha1/password/encrypt/uuid","binary"=>"md5/sha1","date|time"=>"now",];$this->editFunctions=[number_type()=>"+/-","date"=>"+ interval/- interval","time"=>"addtime/subtime","char|text"=>"concat",];if(min_version('5.7.8',10.2,$g))$this->types[lang(37)]["json"]=4294967295;if(min_version('',10.7,$g)){$this->types[lang(37)]["uuid"]=128;$this->insertFunctions['uuid']='uuid';}if(min_version('',10.5,$g)){$this->types[lang(39)]["inet6"]=39;if(min_version('','10.10',$g))$this->types[lang(39)]["inet4"]=15;}if(min_version(9,11.7,$g))$this->types[lang(35)]["vector"]=16383;if(min_version(5.7,10.2,$g))$this->generated=["STORED","VIRTUAL"];}function
unconvertFunction(array$n){return(preg_match("~binary~",$n["type"])?"<code class='jush-sql'>UNHEX</code>":($n["type"]=="bit"?doc_link(['sql'=>'bit-value-literals.html'],"<code>b''</code>"):($n["type"]=="vector"?"<code class='jush-sql'>".($this->conn->flavor=='maria'?"VEC_FromText":"STRING_TO_VECTOR")."</code>":(preg_match("~geom|point|linestring|polygon~",$n["type"])?"<code class='jush-sql'>GeomFromText</code>":""))));}function
insert($R,array$O){return($O?parent::insert($R,$O):queries("INSERT INTO ".table($R)." ()\nVALUES ()"));}function
insertUpdate($R,array$K,array$Xj){$e=array_keys(reset($K));$Sj="INSERT INTO ".table($R)." (".implode(", ",$e).") VALUES\n";$fo=[];foreach($e
as$x)$fo[$x]="$x = VALUES($x)";$mm="\nON DUPLICATE KEY UPDATE ".implode(", ",$fo);$fo=[];$y=0;foreach($K
as$O){$Y="(".implode(", ",$O).")";if($fo&&(strlen($Sj)+$y+strlen($Y)+strlen($mm)>1e6)){if(!queries($Sj.implode(",\n",$fo).$mm))return
false;$fo=[];$y=0;}$fo[]=$Y;$y+=strlen($Y)+2;}return
queries($Sj.implode(",\n",$fo).$mm);}function
slowQuery($G,$Vm){if(min_version('5.7.8','10.1.2')){if($this->conn->flavor=='maria')return"SET STATEMENT max_statement_time=$Vm FOR $G";elseif(preg_match('~^(SELECT\b)(.+)~is',$G,$A))return"$A[1] /*+ MAX_EXECUTION_TIME(".($Vm*1000).") */ $A[2]";}}function
convertColumn($u,array$n){if(preg_match("~binary~",$n["type"]))return"HEX($u)";if($n["type"]=="bit")return"BIN($u + 0)";if($n["type"]=="vector")return($this->conn->flavor=='maria'?"VEC_ToText":"VECTOR_TO_STRING")."($u)";if(preg_match("~geom|point|linestring|polygon~",$n["type"]))return(min_version(8)?"ST_":"")."AsWKT($u)";return"";}function
convertSearch($u,array$X,array$n){return($this->convertColumn($u,$n)?:(preg_match('~'.text_type().'~',$n["type"])&&!preg_match("~^utf8~",$n["collation"])&&preg_match('~[\x80-\xFF]~',$X['val'])?"CONVERT($u USING ".charset($this->conn).")":$u));}function
typeName(\stdClass$n){$B=parent::typeName($n);if($B!=""){$_n=["TINY"=>"tinyint","SHORT"=>"smallint","LONG"=>"int","INT24"=>"mediumint","LONGLONG"=>"bigint","NEWDECIMAL"=>"decimal","VAR_STRING"=>"varchar","STRING"=>"char",];return
idx($_n,$B,strtolower($B));}$_n=["decimal","tinyint","smallint","int","float","double",7=>"timestamp","bigint","mediumint","date","time","datetime","year",15=>"varchar","bit",242=>"vector",245=>"json","decimal","enum","set","tinytext","mediumtext","longtext","text","varchar","char","geometry",];$I=idx($_n,$n->type,"");return($n->charsetnr==63?str_replace(["text","varchar","char"],["blob","varbinary","binary"],$I):$I);}function
quoteBinary($Zk){return"X".q(bin2hex($Zk));}function
md5($d,array$n){if(is_blob($n)||preg_match('~'.text_type().'~',$n["type"]))return"MD5(".(is_blob($n)||preg_match("~^utf8~",$n["collation"])?$d:"CONVERT($d USING ".charset($this->conn).")").")";}function
warnings(){$H=$this->conn->query("SHOW WARNINGS");if($H&&$H->num_rows){ob_start();print_select_result($H);return
ob_get_clean();}}function
errorLine($G){return(preg_match('~ at line (\d+)$~',$this->conn->error,$A)?(int)$A[1]:0);}function
tableHelp($B,$hg=false){$Ug=($this->conn->flavor=='maria');if(information_schema(DB))return
strtolower(str_replace("_","-",DB)."-".($Ug?"$B-table/":str_replace("_","-",$B)."-table.html"));if(DB=="sys")return($Ug?"sys-schema/":strtolower("sys-".str_replace("_","-",preg_replace('~^x\$~','',$B)).".html"));if(DB=="mysql")return($Ug?"mysql$B-table/":"system-schema.html");}function
partitionsInfo($R){$Ce="FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ".q(DB)." AND TABLE_NAME = ".q($R);$H=$this->conn->query("SELECT PARTITION_METHOD, PARTITION_EXPRESSION, PARTITION_ORDINAL_POSITION $Ce ORDER BY PARTITION_ORDINAL_POSITION DESC LIMIT 1");$J=($H?$H->fetch_row():null);if(!$J)return[];$I=[];list($I["partition_by"],$I["partition"],$I["partitions"])=$J;$uj=get_key_vals("SELECT PARTITION_NAME, PARTITION_DESCRIPTION $Ce AND PARTITION_NAME != '' ORDER BY PARTITION_ORDINAL_POSITION");$I["partition_names"]=array_keys($uj);$I["partition_values"]=array_values($uj);return$I;}function
checkConstraints($R){$I=parent::checkConstraints($R);return($this->conn->flavor=='maria'?$I:array_map('stripslashes',$I));}function
hasCStyleEscapes(){static$hb;if($hb===null){$Yl=get_val("SHOW VARIABLES LIKE 'sql_mode'",1,$this->conn);$hb=(strpos($Yl,'NO_BACKSLASH_ESCAPES')===false);}return$hb;}function
hasEstimatedRows(){return
true;}function
isSystem($k,$L=""){return
information_schema($k,$L)||in_array($k,["mysql","sys"]);}function
lineComment(){return"#|-- ";}function
engines(){$I=[];foreach(get_rows("SHOW ENGINES")as$J){if(preg_match("~YES|DEFAULT~",$J["Support"]))$I[]=$J["Engine"];}return$I;}function
indexAlgorithms(array$um){return(preg_match('~^(MEMORY|NDB)$~',$um["Engine"])?["HASH","BTREE"]:[]);}}function
idf_escape($u){return"`".str_replace("`","``",$u)."`";}function
table($u){return
idf_escape($u);}function
get_databases($se){$I=get_session("dbs");if($I===null){$G="SELECT SCHEMA_NAME FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME";$cm=microtime(true);$I=($se?slow_query($G):get_vals($G));if(microtime(true)-$cm>0.1){restart_session();set_session("dbs",$I);stop_session();}}return$I;}function
limit($G,$Z,$z,$C=0,$rl=" "){return" $G$Z".($z?$rl."LIMIT $z".($C?" OFFSET $C":""):"");}function
limit1($R,$G,$Z,$rl="\n"){return
limit($G,$Z,1,0,$rl);}function
db_collation($k,array$Ib){static$I;if($I===null)$I=get_key_vals("SELECT SCHEMA_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA");return$I[$k];}function
logged_user(){return
get_val("SELECT CURRENT_USER()");}function
tables_list(){return
get_key_vals("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");}function
count_tables(array$j){$I=[];foreach($j
as$k)$I[$k]=count(get_vals("SHOW TABLES IN ".idf_escape($k)));return$I;}function
table_status($B="",$Yd=false){$I=[];$G="SELECT ENGINE AS Engine, TABLE_NAME AS Name, TABLE_COMMENT AS Comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ".($B!=""?"AND TABLE_NAME = ".q($B):"ORDER BY Name");$L=[];foreach(($Yd?[]:get_rows($G))as$J)$L[$J["Name"]]=$J;$Wj=null;foreach(get_rows($Yd?$G:"SHOW TABLE STATUS".($B!=""?" LIKE ".q(addcslashes($B,"%_\\")):""))as$J){$Yi=idx($L,$J["Name"]);if($Yi){if($J["Comment"]!==$Yi["Comment"]&&$J["Comment"]!==$Wj)$J["Error"]=$J["Comment"];$Wj=$J["Comment"];$J["Comment"]=$Yi["Comment"];$J["Engine"]=$Yi["Engine"];}if($J["Engine"]=="InnoDB")$J["Comment"]=preg_replace('~(?:(.+); )?InnoDB free: .*~','\1',$J["Comment"]);if(!isset($J["Engine"]))$J["Comment"]="";if($B!="")$J["Name"]=$B;$I[$J["Name"]]=$J;}return$I;}function
is_view(array$S){return$S["Engine"]===null;}function
fk_support(array$S){return
preg_match('~InnoDB|IBMDB2I'.(min_version(5.6)?'|NDB':'').'~i',$S["Engine"]);}function
parse_type($Fe){preg_match('~^([^( ]+)(?:\((.+)\))?( unsigned)?( zerofill)?$~',$Fe,$A);return[$A[1],$A[2],ltrim($A[3].$A[4])];}function
fields($R){$Ug=(connection()->flavor=='maria');$I=[];foreach(get_rows("SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ".q($R)." ORDER BY ORDINAL_POSITION")as$J){$n=$J["COLUMN_NAME"];$U=$J["COLUMN_TYPE"];$Ke=$J["GENERATION_EXPRESSION"];$Vd=$J["EXTRA"];preg_match('~^(VIRTUAL|PERSISTENT|STORED)~',$Vd,$Je);list($zn,$y,$Jn)=parse_type($U);$l=$J["COLUMN_DEFAULT"];$Dc=false;if($l!=""){$pk=$Ug;if($Ug)$Dc=!preg_match("~^('(?:[^'\\\\]|\\\\.|'')*'|NULL|[-+]?[\\d.]+)$~s",$l);elseif(preg_match('~text|json~',$zn)||preg_match('~\bDEFAULT_GENERATED\b~',$Vd)){$l=stripslashes($l);$pk=preg_match("~^(_\\w+)?('(?:[^'\\\\]|\\\\.|'')*')$~s",$l,$A);if($pk)$l=$A[2];else$Dc=true;}elseif(preg_match("~^[bx]'[\\da-f]*'$~i",$l))$Dc=true;if($pk)$l=($l=="NULL"?null:(preg_match("~^'.*'$~s",$l)?stripslashes(idf_unescape($l)):$l));if(preg_match('~binary~',$zn)&&preg_match("~^(?:0x(\\w*)|x'(\\w*)')$~",$l,$A)){$l=pack("H*",$A[1].idx($A,2));$Dc=false;}}$I[$n]=["field"=>$n,"full_type"=>$U,"type"=>$zn,"length"=>$y,"unsigned"=>$Jn,"default"=>($Je?($Ug?$Ke:stripslashes($Ke)):$l),"default_sql"=>(!$Je&&$Dc),"null"=>($J["IS_NULLABLE"]=="YES"),"auto_increment"=>($Vd=="auto_increment"),"on_update"=>(preg_match('~\bon update current_timestamp\b~i',$Vd)?"CURRENT_TIMESTAMP":""),"collation"=>$J["COLLATION_NAME"],"privileges"=>array_flip(explode(",","$J[PRIVILEGES],where,order")),"comment"=>$J["COLUMN_COMMENT"],"primary"=>($J["COLUMN_KEY"]=="PRI"),"generated"=>($Je[1]=="PERSISTENT"?"STORED":$Je[1]),];}return$I;}function
indexes($R,$h=null){$I=[];foreach(get_rows("SHOW INDEX FROM ".table($R),$h)as$J){$B=$J["Key_name"];$I[$B]["type"]=($B=="PRIMARY"?"PRIMARY":($J["Index_type"]=="FULLTEXT"?"FULLTEXT":($J["Non_unique"]?(preg_match('~^(SPATIAL|VECTOR)$~',$J["Index_type"])?$J["Index_type"]:"INDEX"):"UNIQUE")));$I[$B]["columns"][]=$J["Column_name"];$I[$B]["lengths"][]=($J["Index_type"]=="SPATIAL"?null:$J["Sub_part"]);$I[$B]["descs"][]=null;$I[$B]["algorithm"]=$J["Index_type"];}return$I;}function
foreign_keys($R){static$Bj='(?:`(?:[^`]|``)+`|"(?:[^"]|"")+")';$I=[];$kc=get_val("SHOW CREATE TABLE ".table($R),1);if($kc){preg_match_all("~CONSTRAINT ($Bj) FOREIGN KEY ?\\(((?:$Bj,? ?)+)\\) REFERENCES ($Bj)(?:\\.($Bj))? \\(((?:$Bj,? ?)+)\\)(?: ON DELETE (".driver()->onActions."))?(?: ON UPDATE (".driver()->onActions."))?~",$kc,$Xg,PREG_SET_ORDER);foreach($Xg
as$A){preg_match_all("~$Bj~",$A[2],$Rl);preg_match_all("~$Bj~",$A[5],$Lm);$I[idf_unescape($A[1])]=["db"=>idf_unescape($A[4]!=""?$A[3]:$A[4]),"table"=>idf_unescape($A[4]!=""?$A[4]:$A[3]),"source"=>array_map('Adminer\idf_unescape',$Rl[0]),"target"=>array_map('Adminer\idf_unescape',$Lm[0]),"on_delete"=>($A[6]?:"RESTRICT"),"on_update"=>($A[7]?:"RESTRICT"),];}}return$I;}function
view($B){return["select"=>preg_replace('~^(?:[^`]|`[^`]*`)*\s+AS\s+~isU','',get_val("SHOW CREATE VIEW ".table($B),1))];}function
collations(){$I=[];foreach(get_rows("SHOW COLLATION")as$J)$I[$J["Charset"]][]=$J["Collation"];ksort($I);foreach($I
as$x=>$X)sort($I[$x]);return$I;}function
information_schema($k,$L=""){return($k=="information_schema")||(min_version(5.5)&&$k=="performance_schema");}function
error(){return
h(preg_replace('~^You have an error.*syntax to use~U',"Syntax error",connection()->error));}function
create_database($k,$Hb){return
queries("CREATE DATABASE ".idf_escape($k).($Hb?" COLLATE ".q($Hb):""));}function
drop_databases(array$j){$I=apply_queries("DROP DATABASE",$j,'Adminer\idf_escape');restart_session();set_session("dbs",null);return$I;}function
rename_database($B,$Hb){$I=false;if(create_database($B,$Hb)){$T=[];$ko=[];foreach(tables_list()as$R=>$U){if($U=='VIEW')$ko[]=$R;else$T[]=$R;}$I=(!$T&&!$ko)||move_tables($T,$ko,$B);drop_databases($I?[DB]:[]);}return$I;}function
auto_increment(){$Oa=" PRIMARY KEY";if($_GET["create"]!=""&&$_POST["auto_increment_col"]){foreach(indexes($_GET["create"])as$v){if(in_array($_POST["fields"][$_POST["auto_increment_col"]]["orig"],$v["columns"],true)){$Oa="";break;}if($v["type"]=="PRIMARY")$Oa=" UNIQUE";}}return" AUTO_INCREMENT$Oa";}function
alter_table($R,$B,array$o,array$ue,$f,$xd,$Hb,$Na,$tj){$b=[];foreach($o
as$n){if($n[1]){$l=$n[1][3];if(preg_match('~ GENERATED~',$l)){$n[1][3]=(connection()->flavor=='maria'?"":$n[1][2]);$n[1][2]=$l;}$b[]=($R!=""?($n[0]!=""?"CHANGE ".idf_escape($n[0]):"ADD"):" ")." ".implode($n[1]).($R!=""?$n[2]:"");}else$b[]="DROP ".idf_escape($n[0]);}$b=array_merge($b,$ue);$P=($f!==null?" COMMENT=".q($f):"").($xd?" ENGINE=".q($xd):"").($Hb?" COLLATE ".q($Hb):"").($Na!=""?" AUTO_INCREMENT=$Na":"");if($tj){$uj=[];if($tj["partition_by"]=='RANGE'||$tj["partition_by"]=='LIST'){foreach($tj["partition_names"]as$x=>$X){$Y=$tj["partition_values"][$x];$uj[]="\n  PARTITION ".idf_escape($X)." VALUES ".($tj["partition_by"]=='RANGE'?"LESS THAN":"IN").($Y!=""?" ($Y)":" MAXVALUE");}}$P
.="\nPARTITION BY $tj[partition_by]($tj[partition])";if($uj)$P
.=" (".implode(",",$uj)."\n)";elseif($tj["partitions"])$P
.=" PARTITIONS ".(+$tj["partitions"]);}elseif($tj===null)$P
.="\nREMOVE PARTITIONING";if($R=="")return
queries("CREATE TABLE ".table($B)." (\n".implode(",\n",$b)."\n)$P");if($R!=$B)$b[]="RENAME TO ".table($B);if($P)$b[]=ltrim($P);return($b?queries("ALTER TABLE ".table($R)."\n".implode(",\n",$b)):true);}function
alter_indexes($R,$b){$pb=[];foreach($b
as$X)$pb[]=($X[2]=="DROP"?"\nDROP INDEX ".idf_escape($X[1]):"\nADD $X[0] ".($X[0]=="PRIMARY"?"KEY ":"").($X[1]!=""?idf_escape($X[1])." ":"")."(".implode(", ",$X[2]).")");return
queries("ALTER TABLE ".table($R).implode(",",$pb));}function
truncate_tables(array$T){return
apply_queries("TRUNCATE TABLE",$T);}function
drop_views(array$ko){return
queries("DROP VIEW ".implode(", ",array_map('Adminer\table',$ko)));}function
drop_tables(array$T){return
queries("DROP TABLE ".implode(", ",array_map('Adminer\table',$T)));}function
move_tables(array$T,array$ko,$Lm){$Dk=[];foreach($T
as$R)$Dk[]=table($R)." TO ".idf_escape($Lm).".".table($R);if(!$Dk||queries("RENAME TABLE ".implode(", ",$Dk))){$Hc=[];foreach($ko
as$R)$Hc[table($R)]=view($R);connection()->select_db($Lm);$k=idf_escape(DB);foreach($Hc
as$B=>$jo){if(!queries("CREATE VIEW $B AS ".str_replace(" $k."," ",$jo["select"]))||!queries("DROP VIEW $k.$B"))return
false;}return
true;}return
false;}function
copy_tables(array$T,array$ko,$Lm){queries("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");foreach($T
as$R){$B=($Lm==DB?table("copy_$R"):idf_escape($Lm).".".table($R));if(($_POST["overwrite"]&&!queries("\nDROP TABLE IF EXISTS $B"))||!queries("CREATE TABLE $B LIKE ".table($R))||!queries("INSERT INTO $B SELECT * FROM ".table($R)))return
false;foreach(get_rows("SHOW TRIGGERS LIKE ".q(addcslashes($R,"%_\\")))as$J){$pn=$J["Trigger"];list($Hd,$ni)=trigger_event($J);if(!queries("CREATE TRIGGER ".($Lm==DB?idf_escape("copy_$pn"):idf_escape($Lm).".".idf_escape($pn))." $J[Timing] $Hd".($ni!=""?" $ni":"")." ON $B FOR EACH ROW\n$J[Statement];"))return
false;}}foreach($ko
as$R){$B=($Lm==DB?table("copy_$R"):idf_escape($Lm).".".table($R));$jo=view($R);if(($_POST["overwrite"]&&!queries("DROP VIEW IF EXISTS $B"))||!queries("CREATE VIEW $B AS $jo[select]"))return
false;}return
true;}function
trigger_event(array$J){$Jd=explode(",",$J["Event"]);$I=[];foreach(["DELETE","INSERT","UPDATE"]as$Hd){if(in_array($Hd,$Jd))$I[]=$Hd;}$I=implode(" OR ",$I);if(in_array("UPDATE",$Jd)&&min_version('','12.0.1')&&preg_match('~\s(?:BEFORE|AFTER)\s+(.+?)\s+ON\s~is',get_val("SHOW CREATE TRIGGER ".idf_escape($J["Trigger"]),2),$A)&&preg_match('~\bOF\s+(.+)~is',$A[1],$ni))return["$I OF",$ni[1]];return[$I,""];}function
trigger($B,$R){if($B=="")return[];$K=get_rows("SHOW TRIGGERS WHERE `Trigger` = ".q($B));$I=reset($K);if($I)list($I["Event"],$I["Of"])=trigger_event($I);return($I?:[]);}function
triggers($R){$I=[];foreach(get_rows("SHOW TRIGGERS LIKE ".q(addcslashes($R,"%_\\")))as$J){list($Hd)=trigger_event($J);$I[$J["Trigger"]]=[$J["Timing"],$Hd];}return$I;}function
trigger_options(){return["Timing"=>["BEFORE","AFTER"],"Event"=>(min_version('','12.0.1')?["INSERT","UPDATE","UPDATE OF","DELETE","INSERT OR UPDATE","INSERT OR UPDATE OF","DELETE OR INSERT","DELETE OR UPDATE","DELETE OR UPDATE OF","DELETE OR INSERT OR UPDATE","DELETE OR INSERT OR UPDATE OF",]:["INSERT","UPDATE","DELETE"]),"Type"=>["FOR EACH ROW"],];}function
routine($B,$U){$K=get_rows("SELECT PARAMETER_NAME, DTD_IDENTIFIER, PARAMETER_MODE, COLLATION_NAME
FROM information_schema.PARAMETERS
WHERE SPECIFIC_SCHEMA = DATABASE() AND ROUTINE_TYPE = '$U' AND SPECIFIC_NAME = ".q($B)."
ORDER BY ORDINAL_POSITION");$o=[];foreach($K
as$J){$Fe=$J["DTD_IDENTIFIER"];list($zn,$y,$Jn)=parse_type($Fe);$o[]=["field"=>$J["PARAMETER_NAME"],"type"=>$zn,"length"=>$y,"unsigned"=>$Jn,"null"=>true,"full_type"=>$Fe,"inout"=>($U=="FUNCTION"?"":$J["PARAMETER_MODE"]),"collation"=>$J["COLLATION_NAME"],];}$I=connection()->query("SELECT
	ROUTINE_COMMENT comment,
	ROUTINE_DEFINITION definition,
	LOWER(EXTERNAL_LANGUAGE) language,
	IF(DEFINER = CURRENT_USER(), '', DEFINER) definer,
	IF(IS_DETERMINISTIC = 'YES', 'DETERMINISTIC', 'NOT DETERMINISTIC') is_deterministic,
	SQL_DATA_ACCESS data_access,
	CONCAT('SQL SECURITY ', SECURITY_TYPE) security
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = '$U' AND ROUTINE_NAME = ".q($B))->fetch_assoc();if(!$I)return[];$I['options']=["DEFINER"=>$I['definer'],"DETERMINISTIC"=>$I['is_deterministic'],"SQL_DATA_ACCESS"=>$I['data_access'],"SQL_SECURITY"=>$I['security'],"COMMENT"=>$I['comment'],];if($o&&$o[0]['field']=='')$I['returns']=array_shift($o);$I['fields']=$o;return$I;}function
routines(){return
get_rows("SELECT SPECIFIC_NAME, ROUTINE_NAME, ROUTINE_TYPE, DTD_IDENTIFIER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()");}function
routine_languages(){return(min_version(9,99)?["sql"=>"sql","javascript"=>"js"]:[]);}function
routine_options($Qk){return["DEFINER"=>[],"DETERMINISTIC"=>["NOT DETERMINISTIC","DETERMINISTIC"],"SQL_DATA_ACCESS"=>["CONTAINS SQL","NO SQL","READS SQL DATA","MODIFIES SQL DATA"],"SQL_SECURITY"=>["SQL SECURITY DEFINER","SQL SECURITY INVOKER"],"COMMENT"=>[],];}function
routine_id($B,array$J){return
idf_escape($B);}function
last_id($H){return
get_val("SELECT LAST_INSERT_ID()");}function
explain(Db$g,$G){return$g->query("EXPLAIN ".(min_version(5.7)?"":"PARTITIONS ").$G);}function
found_rows(array$S,array$Z){return($Z||$S["Engine"]!="InnoDB"?null:$S["Rows"]);}function
create_sql($R,$Na,$km){$I=get_val("SHOW CREATE TABLE ".table($R),1);if(!$Na)$I=preg_replace('~(\n\)[^\n]*?) AUTO_INCREMENT=\d+~','\1',$I);return$I;}function
truncate_sql($R){return"TRUNCATE ".table($R);}function
use_sql($wc,$km=""){$B=idf_escape($wc);$I="";if(preg_match('~CREATE~',$km)&&($i=get_val("SHOW CREATE DATABASE $B",1))){set_utf8mb4($i);if($km=="DROP+CREATE")$I="DROP DATABASE IF EXISTS $B;\n";$I
.="$i;\n";}return$I."USE $B";}function
trigger_sql($R){$I="";foreach(get_rows("SHOW TRIGGERS LIKE ".q(addcslashes($R,"%_\\")),null,"-- ")as$J){list($J["Event"],$J["Of"])=trigger_event($J);$I
.="\n".create_trigger(" ON ".table($J["Table"]),$J+["Type"=>"FOR EACH ROW"]).";\n";}return$I;}function
show_variables(){return
get_rows("SHOW VARIABLES");}function
show_status(){return
get_rows("SHOW STATUS");}function
process_list(){return
get_rows("SHOW FULL PROCESSLIST");}function
convert_field(array$n){return
driver()->convertColumn(idf_escape($n["field"]),$n);}function
unconvert_field(array$n,$I){if(preg_match("~binary~",$n["type"]))$I="UNHEX($I)";if($n["type"]=="bit")$I="CONVERT(b$I, UNSIGNED)";if($n["type"]=="vector")$I=(connection()->flavor=='maria'?"VEC_FromText":"STRING_TO_VECTOR")."($I)";if(preg_match("~geom|point|linestring|polygon~",$n["type"])){$Sj=(min_version(8)?"ST_":"");$I=$Sj."GeomFromText($I, $Sj"."SRID($n[field]))";}return$I;}function
support($Zd){return
preg_match('~^(comment|columns|copy|database|drop_col|dump|event|indexes|kill|privileges|move_col|procedure|processlist|routine|sql|status|table|trigger|variables|view'.(min_version(8)?'|descidx':'').(min_version('8.0.16','10.2.1')?'|check':'').(min_version(8,99)?'|fast_status':'').')$~',$Zd);}function
kill_process($t){return
queries("KILL ".number($t));}function
max_connections(){return
get_val("SELECT @@max_connections");}function
types($Ud=false){return[];}function
type_values($t){return"";}function
type_definition($t){return["kind"=>"","definition"=>""];}function
schemas(){return[];}function
get_schema(){return"";}function
set_schema($L,$h=null){return
true;}function
schema_comment($L){return"";}function
set_schema_comment($L,$f){return
true;}}define('Adminer\JUSH',Driver::$jush);define('Adminer\SERVER',"".$_GET[DRIVER]);define('Adminer\DB',"$_GET[db]");define('Adminer\ME',preg_replace('~\?.*~','',relative_uri()).'?'.(sid()?SID.'&':'').($_GET["ext"]?"ext=".url_escape($_GET["ext"]).'&':'').(isset($_GET[DRIVER])?DRIVER."=".url_escape(SERVER).'&':'').(isset($_GET["username"])?"username=".url_escape($_GET["username"]).'&':'').(isset($_GET["db"])?'db='.url_escape(DB).'&'.(isset($_GET["ns"])?"ns=".url_escape($_GET["ns"])."&":""):''));if(isset($_GET["manifest"])){header("Content-Type: application/manifest+json; charset=utf-8");header("Cache-Control: no-cache");echo
json_encode(adminer()->manifest(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}function
page_header($Xm,$m="",$db=[],$Ym="",$gi=false,$Zc=""){if($gi){header("HTTP/1.1 404 Not Found");$m=($m?:lang(102));}page_headers();if(is_ajax()&&$m){page_messages($m);exit;}if(!ob_get_level())ob_start('ob_gzhandler',4096);$Zm=$Xm.($Ym!=""?": $Ym":"");$an=strip_tags($Zm.(SERVER!=""&&SERVER!="localhost"?h(" - ".SERVER):"")." - ".adminer()->name());echo'<!DOCTYPE html>
<html lang=\'',LANG,'\' dir=\'',lang(103),'\' class=\'',lang(103),' nojs\'>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="robots" content="noindex">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>',$an,'</title>
<link rel="stylesheet" href="',h(preg_replace("~\\?.*~","",ME)."?file=default.css&version=6.3.0+9afc53aa"),'">
';$oc=adminer()->css();if(is_int(key($oc)))$oc=array_fill_keys($oc,'light');$af=in_array('light',$oc)||in_array('',$oc);$Ye=in_array('dark',$oc)||in_array('',$oc);$sc=($af?($Ye?null:false):($Ye?:null));$mh=" media='(prefers-color-scheme: dark)'";if($sc!==false)echo"<link rel='stylesheet'".($sc?"":$mh)." href='".h(preg_replace("~\\?.*~","",ME)."?file=dark.css&version=6.3.0+9afc53aa")."'>\n";echo"<meta name='color-scheme' content='".($sc===null?"light dark":($sc?"dark":"light"))."'>\n",script_src(preg_replace("~\\?.*~","",ME)."?file=functions.js&version=6.3.0+9afc53aa");if(adminer()->head($sc))echo"<link rel='icon' href='data:image/gif;base64,"."R0lGODlhEAAQAJEAAAQCBPz+/PwCBAROZCH5BAEAAAAALAAAAAAQABAAAAI2hI+pGO1rmghihiUdvUBnZ3XBQA7f05mOak1RWXrNq5nQWHMKvuoJ37BhVEEfYxQzHjWQ5qIAADs='>\n","<link rel='apple-touch-icon' href='".h(preg_replace("~\\?.*~","",ME)."?file=logo.svg&version=6.3.0+9afc53aa")."'>\n";if(adminer()->manifest())echo"<link rel='manifest' href='".h(preg_replace('~\?.*~','',ME)."?manifest=")."' crossorigin='use-credentials'>\n";foreach($oc
as$Qn=>$Bh){$c=($Bh=='dark'&&!$sc?$mh:($Bh=='light'&&$Ye?" media='(prefers-color-scheme: light)'":""));echo"<link rel='stylesheet'$c href='".h($Qn)."'>\n";}echo"\n<body class='";adminer()->bodyClass();echo"'>\n",script((isset($_COOKIE["adminer_version"])||!adminer()->verifyVersion()?"":"onload = partial(verifyVersion, '".VERSION."');\n")."
const offlineMessage = '".js_escape(lang(104))."';
const numberFormat = '".js_escape(lang(6))."';
const numberDigits = '".js_escape(lang(7))."';
const urlSeparators = '".js_escape(ini_get("arg_separator.input"))."';"),"<div id='help' class='jush-".JUSH." jsonly hidden'".on('mouseover','helpKeep').on('mouseout','helpMouseout')."></div>\n","<div id='content'>\n","<span id='menuopen' class='jsonly'".on('click','menuToggle')."><button title='".lang(105)."' class='icon icon-move' aria-expanded='false'></button></span>\n";if($db!==null){$lg=(class_exists('Adminer\Db')&&connection());$_=substr(preg_replace('~\b(username|db|ns)=[^&]*&~','',ME),0,-1);echo'<p id="breadcrumb">'.($lg?jump_input():'').'<a href="'.h($_?:".").'">'.get_driver(DRIVER).'</a> » ';$_=substr(preg_replace('~\b(db|ns)=[^&]*&~','',ME),0,-1);$N=adminer()->serverName(SERVER);$N=($N!=""?$N:lang(47));if($db===false)echo"$N\n";else{echo"<a href='".h($_.(DB!=""&&support("single_db")?"&db=":""))."' accesskey='1' title='Alt+Shift+1'>$N</a> » ";$hl="";if(is_string($db)){$hl=$db;$db=[];}if($_GET["ns"]!=""||(DB!=""&&is_array($db))){$yc="$_&db=".url_escape(DB).(support("scheme")?"&ns=":"").(support("single_table")?"&select=":"");echo'<a href="'.h($yc.($_GET["ns"]==""?$hl:"")).'">'.h(DB).'</a> » ';}if(is_array($db)){if($_GET["ns"]!="")echo'<a href="'.h(substr(ME,0,-1).$hl).'">'.h($_GET["ns"]).'</a> » ';foreach($db
as$x=>$X){$Kc=(is_array($X)?$X[1]:h($X));if($Kc!="")echo"<a href='".h(ME."$x=").url_escape(is_array($X)?$X[0]:$X)."'>$Kc</a> » ";}}echo"$Xm\n";}if($lg){echo"<form id='jump-form'>\n";hidden_fields_get();echo(DB!=""?input_hidden("db",DB):""),(isset($_GET["ns"])?input_hidden("ns",$_GET["ns"]):""),"</form>\n",script("jumpSetup();");}}echo"<h2>$Zm$Zc</h2>\n","<div id='ajaxstatus' role='status' class='jsonly'></div>\n";restart_session();page_messages($m);adminer()->serviceWorker();$j=&get_session("dbs");if(DB!=""&&$j&&!in_array(DB,$j,true))$j=null;stop_session();define('Adminer\PAGE_HEADER',1);ob_flush();flush();if($gi){page_footer($gi===true?"":$gi);exit;}}function
service_worker(){$Ak=has_passwords();$Eb=($Ak?"navigator.serviceWorker.register('".js_escape(preg_replace('~\?.*~','',ME)."?file=worker.js&version=6.3.0+9afc53aa")."', {scope: location.pathname}).catch(() => {});":"navigator.serviceWorker.getRegistration().then(registration => registration && registration.unregister());
	caches.keys().then(keys => keys.forEach(key => key.startsWith('adminer-') && caches.delete(key)));");echo
script("if (navigator.serviceWorker) {\n\t$Eb\n}");}function
has_passwords(){foreach((array)$_SESSION["pwds"]as$Dl){foreach($Dl
as$Zn){foreach($Zn
as$E){if($E!==null)return
true;}}}return
false;}function
page_headers(){header("Content-Type: text/html; charset=utf-8");header("Cache-Control: no-cache");header("X-Frame-Options: deny");header("X-XSS-Protection: 0");header("X-Content-Type-Options: nosniff");header("Referrer-Policy: origin-when-cross-origin");foreach(adminer()->csp(csp())as$nc){$ff=[];foreach($nc
as$x=>$X)$ff[]="$x $X";header("Content-Security-Policy: ".implode("; ",$ff));}adminer()->headers();}function
csp(){return[["script-src"=>"'self' 'unsafe-inline' 'nonce-".get_nonce()."' 'strict-dynamic'","connect-src"=>"'self' https://www.adminer.org","frame-src"=>"https://www.adminer.org","object-src"=>"'none'","base-uri"=>"'none'","form-action"=>"'self'",],];}function
design_checksums(){$Wn=[];foreach(array_keys(adminer()->css())as$Qn)$Wn[preg_replace('~\?.*~','',$Qn)]=true;$I=[];foreach(["adminer.css","adminer-dark.css"]as$p){if($Wn[$p]&&file_exists($p)){preg_match('~^/\* Adminer design ([-\w]+) \*/~',file_get_contents($p),$A);$I[$p]=[(string)$A[1],Plugins::checksum($p)];}}return$I;}function
official_design_checksums(){return['adminer-dark/adminer-dark.css'=>'a26bcd7b','brade/adminer.css'=>'be4161f0','bueltge/adminer.css'=>'1a8f00b4','cpanel/adminer.css'=>'59ce604e','dracula/adminer-dark.css'=>'cfaf61dd','esterka/adminer.css'=>'1f805f36','flat/adminer.css'=>'49a61af9','galkaev/adminer-dark.css'=>'16c46f94','haeckel/adminer.css'=>'147a3565','hever/adminer.css'=>'c9d1d11c','konya/adminer.css'=>'2b409696','lavender-light/adminer.css'=>'bf03f5d7','lucas-sandery/adminer.css'=>'6596353','mancave/adminer-dark.css'=>'d9169f12','mvt/adminer.css'=>'ebd3afdc','nette/adminer.css'=>'a8babfc5','ng9/adminer.css'=>'488583cf','nicu/adminer.css'=>'a38b3b6a','pappu687/adminer.css'=>'b58d128c','paranoiq/adminer.css'=>'64d27e5','pepa-linha/adminer.css'=>'baf25f0','pokorny/adminer.css'=>'ee9eea6d','price/adminer.css'=>'81be9a85','rmsoft/adminer.css'=>'6cd4a237','rmsoft_blue-dark/adminer.css'=>'32102a8','rmsoft_blue/adminer.css'=>'7d8d5b18','win98/adminer.css'=>'e82d63c3',];}function
version_iframe(){return(isset($_COOKIE["adminer_version"])||!adminer()->verifyVersion()?"":"<noscript><iframe sandbox src='https://www.adminer.org/version/?current=".VERSION."&amp;noscript=1'></iframe></noscript>");}function
get_nonce(){static$fi;if(!$fi)$fi=base64_encode(rand_string());return$fi;}function
page_messages($m){$Pn=preg_replace('~^[^?]*~','',$_SERVER["REQUEST_URI"]);$th=idx($_SESSION["messages"],$Pn);if($th){echo"<div class='message'>".implode("</div>\n<div class='message'>",$th)."</div>".script("messagesPrint();");unset($_SESSION["messages"][$Pn]);}if($m)echo"<div class='error'>$m</div>\n";if(adminer()->error)echo"<div class='error'>".adminer()->error."</div>\n";}function
page_footer($Ah=""){echo"</div>\n\n<div id='foot' class='foot'>\n<div id='menu'>\n";adminer()->navigation($Ah);echo"</div>\n";if($Ah!="auth")echo'<form method="post">
<p class="logout">
<span title="',lang(49),'">',h($_GET["username"])."\n",'</span>
<input type=\'submit\' name=\'logout\' value=\'',lang(106),'\' id=\'logout\'>
',adminer()->donation()."\n",input_token(),'</form>
';echo"</div>\n\n",script("setupSubmitHighlight(document);");}function
int32($Kh){while($Kh>=2147483648)$Kh-=4294967296;while($Kh<=-2147483649)$Kh+=4294967296;return(int)$Kh;}function
long2str(array$W,$mo){$Zk='';foreach($W
as$X)$Zk
.=pack('V',$X);if($mo)return
substr($Zk,0,end($W));return$Zk;}function
str2long($Zk,$mo){$W=array_values(unpack('V*',str_pad($Zk,4*ceil(strlen($Zk)/4),"\0")));if($mo)$W[]=strlen($Zk);return$W;}function
xxtea_mx($yo,$xo,$nm,$ng){return
int32((($yo>>5&0x7FFFFFF)^$xo<<2)+(($xo>>3&0x1FFFFFFF)^$yo<<4))^int32(($nm^$xo)+($ng^$yo));}function
encrypt_string($hm,$x){if($hm=="")return"";$x=array_values(unpack("V*",pack("H*",md5($x))));$W=str2long($hm,true);$Kh=count($W)-1;$yo=$W[$Kh];$xo=$W[0];$ik=floor(6+52/($Kh+1));$nm=0;while($ik-->0){$nm=int32($nm+0x9E3779B9);$kd=$nm>>2&3;for($ej=0;$ej<$Kh;$ej++){$xo=$W[$ej+1];$Jh=xxtea_mx($yo,$xo,$nm,$x[$ej&3^$kd]);$yo=int32($W[$ej]+$Jh);$W[$ej]=$yo;}$xo=$W[0];$Jh=xxtea_mx($yo,$xo,$nm,$x[$ej&3^$kd]);$yo=int32($W[$Kh]+$Jh);$W[$Kh]=$yo;}return
long2str($W,false);}function
decrypt_string($hm,$x){if($hm=="")return"";if(!$x)return
false;$x=array_values(unpack("V*",pack("H*",md5($x))));$W=str2long($hm,false);$Kh=count($W)-1;$yo=$W[$Kh];$xo=$W[0];$ik=floor(6+52/($Kh+1));$nm=int32($ik*0x9E3779B9);while($nm){$kd=$nm>>2&3;for($ej=$Kh;$ej>0;$ej--){$yo=$W[$ej-1];$Jh=xxtea_mx($yo,$xo,$nm,$x[$ej&3^$kd]);$xo=int32($W[$ej]-$Jh);$W[$ej]=$xo;}$yo=$W[$Kh];$Jh=xxtea_mx($yo,$xo,$nm,$x[$ej&3^$kd]);$xo=int32($W[0]-$Jh);$W[0]=$xo;$nm=int32($nm-0x9E3779B9);}return
long2str($W,true);}$Ej=[];if($_COOKIE["adminer_permanent"]){foreach(explode(" ",$_COOKIE["adminer_permanent"])as$X){list($x)=explode(":",$X);$Ej[$x]=$X;}}function
add_invalid_login(){$Va=get_temp_dir()."/adminer-invalid";foreach(glob("$Va*")?:[$Va]as$p){$_e=file_open_lock($p);if($_e)break;}if(!$_e)$_e=file_open_lock("$Va-".rand_string());if(!$_e)return;$Zf=json_decode(stream_get_contents($_e),true);$Um=time();if($Zf){foreach($Zf
as$ag=>$X){if($X[0]<$Um)unset($Zf[$ag]);}}$Xf=&$Zf[adminer()->bruteForceKey()];if(!$Xf)$Xf=[$Um+30*60,0];$Xf[1]++;file_write_unlock($_e,json_encode($Zf));}function
check_invalid_login(array&$Ej){$Zf=[];foreach(glob(get_temp_dir()."/adminer-invalid*")as$p){$_e=file_open_lock($p);if($_e){$Zf=json_decode(stream_get_contents($_e),true);file_unlock($_e);break;}}$x=adminer()->bruteForceKey();$Xf=idx($Zf,$x,[]);$ei=($Xf[1]>29?$Xf[0]-time():0);if($ei>0){$m=lang(107,ceil($ei/60));if($_SERVER["HTTP_X_FORWARDED_FOR"]!=""&&$x==$_SERVER["REMOTE_ADDR"])$m
.='<br>'.lang(108,'<b>login-reverse-proxy</b>'," href='https://www.adminer.org/plugins/?version=".VERSION."'".target_blank());auth_error($m,$Ej,false);}}function
password_required(){static$I;if($I===null){$I=(bool)get_session("password_required");if(!$I){$mc=adminer()->credentials();$I=!is_object(Driver::connect($mc[0],$mc[1],""));if($I)set_session("password_required",true);}}return$I;}function
require_password_link($E){$Fh="<a href='https://www.adminer.org/password/'".target_blank().">".lang(109)."</a>";if(!function_exists('password_hash'))return" $Fh";$Hj=($E!==null?$E:base64_encode(substr(hex2bin(rand_string()),0,12)));$ef=password_hash($Hj,PASSWORD_DEFAULT);$p="adminer-plugins.php";$Od=file_exists("adminer-plugins.php");if($Od)$Vf=($E!==null?lang(110,"<b>$p</b>"):lang(111,"<b>$p</b>","<b>$Hj</b>"));else{$p="<button name='password_less' value='".h($ef)."' class='link'>$p</button>";$Vf=($E!==null?lang(112,$p):lang(113,$p,"<b>$Hj</b>"));}$Lg="\t<a>new</a> Adminer\\Password(<span class='jush-apo'>'".h($ef)."'</span>),";$I="<p>$Vf
<pre><code class='jush'>".($Od?$Lg:"&lt;?php\n<a>return</a> <a>array</a>(\n$Lg\n);")."</code></pre>
<p>$Fh
";return" <a href='#password-less' class='toggle'>".lang(114)."</a>
<div id='password-less' class='hidden'>".($Od?$I:"<form method='post'>\n".$I.input_token()."</form>")."</div>";}if(preg_match('~^[-\w$./]+$~',$_POST["password_less"])&&verify_token()){header("Content-Type: application/octet-stream");header("Content-Disposition: attachment; filename=adminer-plugins.php");echo"<?php\nreturn array(\n\tnew Adminer\\Password('$_POST[password_less]'),\n);\n";exit;}$Ma=$_POST["auth"];if($Ma&&(!adminer()->verifyLoginToken()||verify_token())){session_regenerate_id();$ho=$Ma["driver"];$N=$Ma["server"];$V=$Ma["username"];$E=(string)$Ma["password"];$k=$Ma["db"];set_password($ho,$N,$V,$E);$_SESSION["db"][$ho][$N][$V][$k]=true;if($Ma["permanent"]){$x=implode("-",array_map('base64_encode',[$ho,$N,$V,$k]));$ak=adminer()->permanentLogin(true);$Ej[$x]="$x:".base64_encode($ak?encrypt_string($E,$ak):"");cookie("adminer_permanent",implode(" ",$Ej));}if(!array_diff(array_keys($_POST),["auth","token"])||$ho!=DRIVER||$N!=SERVER||$V!==$_GET["username"]||$k!=DB)redirect(auth_url($ho,$N,$V,$k));}elseif($_POST["logout"]&&(!$_SESSION["token"]||verify_token())){Driver::disconnect();foreach(["pwds","db","dbs","queries"]as$x)set_session($x,null);unset_permanent($Ej);redirect(substr(preg_replace('~\b(username|db|ns)=[^&]*&~','',ME),0,-1),lang(115).' '.lang(116," href='https://www.adminer.org/donation/'".target_blank()));}elseif($Ej&&!$_SESSION["pwds"]){session_regenerate_id();$ak=adminer()->permanentLogin();foreach($Ej
as$x=>$X){list(,$Ab)=explode(":",$X);list($ho,$N,$V,$k)=array_map('base64_decode',explode("-",$x));set_password($ho,$N,$V,decrypt_string(base64_decode($Ab),$ak));$_SESSION["db"][$ho][$N][$V][$k]=true;}}function
unset_permanent(array&$Ej){foreach($Ej
as$x=>$X){list($ho,$N,$V,$k)=array_map('base64_decode',explode("-",$x));if($ho==DRIVER&&$N==SERVER&&$V==$_GET["username"]&&$k==DB)unset($Ej[$x]);}cookie("adminer_permanent",implode(" ",$Ej));}function
auth_error($m,array&$Ej,$Yf=true){$El=session_name();if(isset($_GET["username"])){header("HTTP/1.1 403 Forbidden");if(($_COOKIE[$El]||$_GET[$El])&&!$_SESSION["token"])$m=lang(117);elseif($Yf&&($E=get_password())!==null){restart_session();add_invalid_login();if($E===false)$m
.=($m?'<br>':'').lang(118,target_blank(),'<code>permanentLogin()</code>');set_password(DRIVER,SERVER,$_GET["username"],null);unset_permanent($Ej);}}if(!$_COOKIE[$El]&&$_GET[$El]&&ini_bool("session.use_only_cookies"))$m=lang(119);$jj=session_get_cookie_params();cookie("adminer_key",($_COOKIE["adminer_key"]?:rand_string()),$jj["lifetime"]);if(!$_SESSION["token"])$_SESSION["token"]=rand(1,1e6);page_header(lang(51),$m,null);echo"<form method='post'>\n","<div>";if(hidden_fields($_POST,["auth","token"]))echo"<p class='message'>".lang(120)."\n";echo
input_token(),"</div>\n";adminer()->loginForm();echo"</form>\n";page_footer("auth");exit;}if(isset($_GET["username"])&&!class_exists('Adminer\Db')){unset($_SESSION["pwds"][DRIVER]);unset_permanent($Ej);page_header(lang(121),lang(122,implode(", ",Driver::$extensions)),false);page_footer("auth");exit;}$g='';if(isset($_GET["username"])&&is_string(get_password())){check_invalid_login($Ej);$mc=adminer()->credentials();$g=Driver::connect($mc[0],$mc[1],$mc[2]);if(is_object($g)){Db::$instance=$g;Driver::$instance=new
Driver($g);if($g->flavor)save_settings(["vendor-".DRIVER."-".SERVER=>get_driver(DRIVER)]);}}$Sg=null;if(!is_object($g)||($Sg=adminer()->login($_GET["username"],get_password()))!==true){$m=(is_string($g)?nl_br(h($g)):(is_string($Sg)?$Sg:lang(123))).(preg_match('~^ | $~',get_password())?'<br>'.lang(124):'');auth_error($m,$Ej);}if($_POST["logout"]&&$_SESSION["token"]&&!verify_token()){page_header(lang(106),lang(125));page_footer("db");exit;}if(!$_SESSION["token"])$_SESSION["token"]=rand(1,1e6);stop_session(true);$m='';if($_POST){if(!verify_token()){header("HTTP/1.1 403 Forbidden");$m=lang(125).' '.lang(126);}}elseif($_SERVER["REQUEST_METHOD"]=="POST"){header("HTTP/1.1 413 Content Too Large");$m=lang(127,"<b>post_max_size</b>");if(isset($_GET["sql"]))$m
.=' '.lang(128);}function
print_select_result($H,$h=null,array$Ri=[],&$z=0,&$ld=false){$Ng=[];$w=[];$e=[];$T=[];$Xj=[];$pd=[];$_n=[];$I=[];$Ch=$ld;$ld=false;for($s=0;(!$z||$s<$z)&&is_array($J=$H->fetch_row());$s++){if(!$s){echo"<div class='scrollable'>\n","<table class='nowrap odds'".($Ch?on('click','tableClick').on('dblclick','tableClick').on('keydown','editingKeydown'):"").">\n","<thead><tr>";for($jg=0;$jg<count($J);$jg++){$n=$H->fetch_field();$B=$n->name;$R=(isset($n->table)?$n->table:"");$Qi=(isset($n->orgtable)?$n->orgtable:"");$Pi=(isset($n->orgname)?$n->orgname:$B);$zn=driver()->typeName($n);if($Ri&&JUSH=="sql")$Ng[$jg]=($B=="table"?"table=":($B=="possible_keys"?"indexes=":null));elseif($Qi!=""){$wa=($R!=""?$R:$Qi);if($R!="")$I[$R]=$Qi;if(!isset($w[$wa])){if(!isset($Xj[$Qi])){$Xj[$Qi]=[];foreach(indexes($Qi,$h)as$v){if($v["type"]=="PRIMARY"){$Xj[$Qi]=array_flip($v["columns"]);break;}}}$T[$wa]=$Qi;$w[$wa]=$Xj[$Qi];$e[$wa]=$Xj[$Qi];}if(isset($e[$wa][$Pi])){unset($e[$wa][$Pi]);$w[$wa][$Pi]=$jg;$Ng[$jg]=$wa;}elseif($Ch&&isset($n->orgname)&&$n->db==DB&&!is_blob(["type"=>$zn]))$pd[$jg]=[$wa,$Pi,preg_match('~text|json|lob~',$zn)];}$_n[$jg]=$zn;echo"<th title='".h(trim(($Qi!=""?"$Qi.$Pi":($n->name!=$Pi?$Pi:""))." ".$zn))."'>".h($B).($Ri?doc_link(['sql'=>"explain-output.html#explain_".strtolower($B),'mariadb'=>"explain/#the-columns-in-explain-select",]):"");}foreach($pd
as$jg=>$mb){if($e[$mb[0]])unset($pd[$jg]);}echo"<tbody>\n";}$xf=[];foreach($w
as$wa=>$v){if($v&&!$e[$wa]){$u="";foreach($v
as$Fb=>$jg){if($J[$jg]===null){$u=null;break;}$u
.="&where[".url_escape(bracket_escape($Fb))."]=".url_escape($J[$jg]);}$xf[$wa]=$u;}}echo"<tr>";foreach($J
as$x=>$X){$_="";if(isset($Ng[$x])){if($Ri&&JUSH=="sql"){$R=$J[array_search("table=",$Ng)];$_=ME.$Ng[$x].url_escape($Ri[$R]!=""?$Ri[$R]:$R);}elseif(idx($xf,$Ng[$x])!==null)$_=ME."edit=".url_escape($T[$Ng[$x]]).$xf[$Ng[$x]];}$sf=select_value($X,$_,['type'=>(preg_match('~binary~',$_n[$x])?'blob':$_n[$x])],null);$c="";$mb=idx($pd,$x);if($mb&&idx($xf,$mb[0])!==null&&is_utf8($X)){$ld=true;$c=" data-name='".h("val[".bracket_escape($T[$mb[0]])."][".bracket_escape(substr($xf[$mb[0]],1))."][".bracket_escape($mb[1])."]")."' data-text='".($mb[2]?1:0)."'".(html_entity_decode(strip_tags($sf),ENT_QUOTES,"UTF-8")!==$X?" data-value='".h($X)."'":"");}echo"<td".(preg_match(number_type(),$_n[$x])?" class='number'":"")."$c>$sf";}}$z=$s;echo($s?"</table>\n</div>":"<p class='message'>".lang(22))."\n";return$I;}function
textarea($B,$Y,$K=10,$Jb=80,$mg=JUSH,$Cd=0){echo"<textarea name='".h($B)."' rows='$K' cols='$Jb' class='sqlarea jush-".h($mg)."' spellcheck='false' wrap='off'".($Cd?" data-error-line='$Cd'":"").">\n";if(is_array($Y)){foreach($Y
as$X)echo
h($X[0])."\n\n\n";}else
echo
h($Y);echo"</textarea>";}function
select_input($c,array$Ki,$Y="",$Fj=""){if($Ki&&$Y!=""&&!isset($Ki[$Y]))$Ki=[$Y=>$Y]+$Ki;$Km=($Ki?"select":"input");return"<$Km$c".($Ki?"><option value=''>$Fj".optionlist($Ki,$Y,true)."</select>":" size='10' value='".h($Y)."' placeholder='$Fj'>");}function
json_row($x,$X=null,$Gd=true){static$me=true;if($me)echo"{";if($x!=""){echo($me?"":",")."\n\t\"".addcslashes($x,"\r\n\t\"\\/").'": '.($X!==null?($Gd?'"'.addcslashes($X,"\r\n\"\\/").'"':$X):'null');$me=false;}else{echo"\n}\n";$me=true;}}function
flat_collations(){$Ib=collations();return(is_array(reset($Ib))?call_user_func_array('array_merge',array_values($Ib)):$Ib);}function
edit_type($x,array$n,array$Ib,array$we=[],array$Wd=[]){$U=(string)$n["type"];echo"<td><select name='".h($x)."[type]' class='type' aria-labelledby='label-type'".on_help_value().">";if($U&&!array_key_exists($U,driver()->types())&&!isset($we[$U])&&!in_array($U,$Wd))$Wd[]=$U;$im=driver()->structuredTypes();if($we)$im[lang(129)]=$we;echo
optionlist(array_merge($Wd,$im),$U),"</select><td>","<input name='".h($x)."[length]' value='".h($n["length"])."' size='3'".(!$n["length"]&&preg_match('~var(char|binary)$~',$U)?" class='required'":"")." aria-labelledby='label-length'>","<td class='options'>",($Ib?"<input list='collations' name='".h($x)."[collation]'".option_types($U,'('.text_type().')$')." value='".h($n["collation"])."' placeholder='(".lang(130).")'>":''),(driver()->unsigned?"<select name='".h($x)."[unsigned]'".option_types($U,'^$|'.number_type()).'><option>'.optionlist(driver()->unsigned,$n["unsigned"]).'</select>':''),(isset($n['on_update'])?"<select name='".h($x)."[on_update]'".option_types($U,'timestamp|datetime').'>'.optionlist([""=>"(".lang(131).")","CURRENT_TIMESTAMP"],(preg_match('~^CURRENT_TIMESTAMP~i',$n["on_update"])?"CURRENT_TIMESTAMP":$n["on_update"])).'</select>':''),($we?"<select name='".h($x)."[on_delete]'".option_types($U,'`')."><option value=''>(".lang(132).")".optionlist(explode("|",driver()->onActions),$n["on_delete"])."</select> ":" ");}function
option_types($U,$_n){return" data-types='".h($_n)."'".(preg_match("~$_n~",$U)?"":" class='hidden'");}function
process_length($y){if(JUSH=="mssql"&&preg_match('~^\s*\(?\s*max\s*\)?\s*$~i',$y))return"(max)";if(JUSH=="oracle"&&preg_match('~^\s*\(?\s*(\d+)\s+(CHAR|BYTE)\s*\)?\s*$~i',$y,$A))return"($A[1] ".strtoupper($A[2]).")";if(JUSH=="oracle"&&preg_match('~^\s*\(?\s*\*\s*,\s*(-?\d+)\s*\)?\s*$~',$y,$A))return"(*,$A[1])";$_d=driver()->enumLength;return(preg_match("~^\\s*\\(?\\s*$_d(?:\\s*,\\s*$_d)*+\\s*\\)?\\s*\$~",$y)&&preg_match_all("~$_d~",$y,$Xg)?"(".implode(",",$Xg[0]).")":preg_replace('~^[0-9].*~','(\0)',preg_replace('~[^-0-9,+()[\]]~','',$y)));}function
process_in($X){$X=preg_replace('~^\s*\(\s*(.*?)\s*\)\s*$~s','\1',$X);$pk="'(?:[^']|'')*+'";$ig=(preg_match("~^\\s*$pk(?:\\s*,\\s*$pk)*+\\s*\$~",$X)&&preg_match_all("~$pk~",$X,$Xg)?array_map('Adminer\idf_unescape',$Xg[0]):array_map('trim',explode(",",$X)));return"(".implode(", ",array_map('Adminer\q',$ig)).")";}function
process_between($X){$pk="('(?:[^']|'')*+')";$rk=(preg_match("~^\\s*$pk\\s+AND\\s+$pk\\s*\$~i",$X,$A)?[idf_unescape($A[1]),idf_unescape($A[2])]:preg_split('~\s+AND\s+~i',trim($X)));return(count($rk)==2?q($rk[0])." AND ".q($rk[1]):"NULL AND NULL");}function
process_type(array$n,$Gb="COLLATE"){return" ".(is_user_type($n["type"])?idf_escape($n["type"]):$n["type"]).process_length($n["length"]).(preg_match(number_type(),$n["type"])&&in_array($n["unsigned"],driver()->unsigned)?" $n[unsigned]":"").(preg_match('~'.text_type().'~',$n["type"])&&$n["collation"]?" $Gb ".(JUSH=="mssql"&&preg_match('~^\w+$~',$n["collation"])?$n["collation"]:q($n["collation"])):"");}function
process_field(array$n,array$wn){return[idf_escape(trim($n["field"])),process_type($wn),($n["null"]?" NULL":" NOT NULL"),default_value($n),(preg_match('~timestamp|datetime~',$n["type"])&&$n["on_update"]?" ON UPDATE $n[on_update]".process_length($n["length"]):""),(support("comment")&&$n["comment"]!=""?" COMMENT ".q($n["comment"]):""),($n["auto_increment"]?auto_increment():null),];}function
default_value(array$n){if($n["default"]===null)return"";$l=str_replace("\r","",$n["default"]);$Je=$n["generated"];$Q=!preg_match('~]$~',$n["length"])&&(preg_match('~char|binary|text|clob|json|enum|set|String~',$n["type"])||driver()->enumLength($n));$kj=(JUSH=="sqlite"||(JUSH=="sql"&&$n["default_sql"]&&!preg_match("~^(current_timestamp(\\(\\d*\\))?|[bx]'[\\da-f]*')$~i",$l)));return(in_array($Je,driver()->generated)?(JUSH=="mssql"?" AS ($l)".($Je=="VIRTUAL"?"":" $Je"):" GENERATED ALWAYS AS ($l) $Je"):(preg_match('~^GENERATED ~i',$l)?" $l":" DEFAULT ".(!$n["default_sql"]&&($Q||preg_match('~^(?![a-z])~i',$l))?(JUSH=="sql"&&preg_match('~text|json~',$n["type"])?"(".q($l).")":q($l)):($kj?"($l)":$l))));}function
edit_fields(array$o,array$Ib,$U="TABLE",array$we=[]){$o=array_values($o);$Cc=(($_POST?$_POST["defaults"]:get_setting("defaults"))?"":" class='hidden'");$Ob=(($_POST?$_POST["comments"]:get_setting("comments"))?"":" class='hidden'");echo"<thead><tr>\n",($U=="PROCEDURE"?"<td>":""),"<th id='label-name'>".($U=="TABLE"?lang(133):lang(134)),"<th id='label-type'>".lang(64)."<textarea id='enum-edit' rows='4' cols='12' wrap='off' hidden></textarea>".script("qs('#enum-edit').onblur = editingLengthBlur;"),"<th id='label-length'>".lang(135),"<th>".lang(136);if($U=="TABLE")echo"<th id='label-null'>NULL\n","<th><input type='radio' name='auto_increment_col' value=''><abbr id='label-ai' title='".lang(66)."'>A_I</abbr>",doc_link(['sql'=>"example-auto-increment.html",'mariadb'=>"auto_increment/",'sqlite'=>"autoinc.html",'pgsql'=>"datatype-numeric.html#DATATYPE-SERIAL",'cockroach'=>"serial",'mssql'=>"t-sql/statements/create-table-transact-sql-identity-property",]),"<th id='label-default'$Cc>".lang(67),(support("comment")?"<th id='label-comment'$Ob>".lang(65):"");$Ag=!support("move_col");echo"<td>".icon("plus","add[".($Ag?count($o):0)."]","+",lang(137),($Ag?on('click','editingAddLastRow'):"")),"<tbody".on('click','editingClick').on('input','editingInput').on('keydown','editingKeydown').">\n";foreach($o
as$s=>$n){$s++;$Si=$n[($_POST?"orig":"field")];$Rc=(isset($_POST["add"][$s-1])||(isset($n["field"])&&!idx($_POST["drop_col"],$s)))&&(support("drop_col")||$Si=="");echo"<tr".($Rc?"":" hidden").">\n",($U=="PROCEDURE"?"<td>".html_select("fields[$s][inout]",explode("|",driver()->inout),$n["inout"]):"")."<th>",(support("move_col")?icon("move","","↕",lang(138))." ":"");if($Rc)echo"<input name='fields[$s][field]' value='".h($n["field"])."' data-maxlength='64' autocapitalize='off' aria-labelledby='label-name'".(isset($_POST["add"][$s-1])?" autofocus":"").">";echo
input_hidden("fields[$s][orig]",$Si);edit_type("fields[$s]",$n,$Ib,$we);if($U=="TABLE"){echo"<td><label class='block'>".checkbox("fields[$s][null]",1,$n["null"],"","","","label-null")."</label>","<td><label class='block'><input type='radio' name='auto_increment_col' value='$s'".($n["auto_increment"]?" checked":"")." aria-labelledby='label-ai'></label>","<td$Cc>".html_select("fields[$s][generated]",array_merge(["","DEFAULT","DEFAULT SQL"],driver()->generated),$n["generated"],"","label-default")." ";$c=" name='fields[$s][default]' aria-labelledby='label-default'";$Y=h($n["default"]);echo(preg_match('~\n~',$n["default"])?"<textarea$c rows='2' cols='30' style='vertical-align: bottom;'>\n$Y</textarea>":"<input$c value='$Y'>");if(support("comment")){$c=" name='fields[$s][comment]' data-maxlength='".(min_version(5.5)?1024:255)."' aria-labelledby='label-comment'";echo"<td$Ob>".adminer()->commentInput('COLUMN',$c,$n["comment"]);}}echo"<td>",(support("move_col")?icon("plus","add[$s]","+",lang(137))." ":""),($Si==""||support("drop_col")?icon("cross","drop_col[$s]","x",lang(139)):"");}}function
process_fields(array&$o){if($_POST["add"]){$o=array_values($o);array_splice($o,key($_POST["add"]),0,[[]]);}return$_POST["add"]||$_POST["drop_col"];}function
drop_create($ed,$i,$gd,$Qm,$id,$Rg,$sh,$qh,$rh,$wi,$Xh){if($_POST["drop"])query_redirect($ed,$Rg,$sh);elseif($wi=="")query_redirect($i,$Rg,$rh);elseif(support("transaction_ddl")){driver()->begin();queries_redirect($Rg,$qh,queries($ed)&&queries($i)&&driver()->commit());driver()->rollback();}elseif($wi!=$Xh){$lc=queries($i);queries_redirect($Rg,$qh,$lc&&queries($ed));if($lc&&$gd)queries($gd);}else
queries_redirect($Rg,$qh,queries($Qm)&&queries($id)&&queries($ed)&&queries($i));}function
create_trigger($_i,array$J){$Wm=" $J[Timing] $J[Event]".(preg_match('~ OF~',$J["Event"])?" $J[Of]":"");return"CREATE TRIGGER ".idf_escape($J["Trigger"]).(JUSH=="mssql"?$_i.$Wm:$Wm.$_i).preg_replace('~[\s;]+$~',''," $J[Type]\n$J[Statement]").";";}function
q_dollar($Q){$Ic='$$';while(strpos($Q.$Ic,$Ic)!=strlen($Q))$Ic='$_'.substr($Ic,1);return$Ic.$Q.$Ic;}function
routine_collate($Hb){static$sb=[];if($Hb&&!$sb){foreach(collations()as$rb=>$eo){foreach((array)$eo
as$X)$sb[$X]=$rb;}}return($sb[$Hb]?"CHARACTER SET ".q($sb[$Hb])." ":"")."COLLATE";}function
create_routine($Qk,array$J){$O=[];$o=$J["fields"];ksort($o);foreach($o
as$n){if($n["field"]!=""){$Qf=(preg_match("~^(".driver()->inout.")\$~",$n["inout"])?$n["inout"]:"");$O[]="\n  ".(JUSH=="mssql"?"@$n[field]".process_type($n).($Qf?" $Qf":""):($Qf?"$Qf ":"").idf_escape($n["field"]).process_type($n,routine_collate($n["collation"])));}}$Fc="";$Ki=[];foreach(routine_options($Qk)as$x=>$fo){$Y=idx($J["options"],$x,"");if($x=="DEFINER")$Fc=($Y?" $x=".implode("@",array_map('Adminer\q',explode("@",$Y,2))):"");elseif(!$fo){if($Y!="")$Ki[]="$x ".q($Y);}elseif($Y!=reset($fo)&&in_array($Y,$fo))$Ki[]=$Y;}$zg=$J["language"];$Gc=preg_replace('~[\s;]+$~','',$J["definition"]);$ad=(JUSH=="pgsql"||($zg&&$zg!="sql"));$ij=($O?implode(",",$O)."\n":"");return"CREATE$Fc $Qk ".table(trim($J["name"])).(JUSH=="mssql"&&$Qk=="PROCEDURE"?rtrim($ij):" ($ij)").($Qk=="FUNCTION"?"\nRETURNS".process_type($J["returns"],routine_collate($J["returns"]["collation"])):"").($zg?" LANGUAGE $zg":"").($Ki?"\n".implode(" ",$Ki):"").($ad?" AS ".q_dollar("\n".trim($Gc)."\n"):(JUSH=="mssql"?"\nAS":"")."\n$Gc;");}function
remove_definer($G){$Fc=implode("@",array_map('Adminer\idf_escape',explode("@",logged_user(),2)));return
preg_replace('(^([A-Z =]+) DEFINER='.preg_quote($Fc).')','\1',$G);}function
object_name($U,$R,array$e){return
str_replace(["{table}","{columns}"],[$R,implode("_",$e)],adminer()->namePattern($U));}function
format_foreign_key(array$q,$B=""){$k=$q["db"];$hi=$q["ns"];return($B!=""?" CONSTRAINT ".idf_escape($B):"")." FOREIGN KEY (".implode(", ",array_map('Adminer\idf_escape',$q["source"])).") REFERENCES ".($k!=""&&$k!=$_GET["db"]?idf_escape($k).".":"").($hi!=""&&$hi!=$_GET["ns"]?idf_escape($hi).".":"").idf_escape($q["table"])." (".implode(", ",array_map('Adminer\idf_escape',$q["target"])).")".(preg_match("~^(".driver()->onActions.")\$~",$q["on_delete"])?" ON DELETE $q[on_delete]":"").(preg_match("~^(".driver()->onActions.")\$~",$q["on_update"])?" ON UPDATE $q[on_update]":"").($q["deferrable"]?" $q[deferrable]":"");}function
tar_file($p,$bn){$I=pack("a100a8a8a8a12a12",$p,644,0,0,decoct($bn->size),decoct(time()));$yb=8*32;for($s=0;$s<strlen($I);$s++)$yb+=ord($I[$s]);$I
.=sprintf("%06o",$yb)."\0 ";echo$I,str_repeat("\0",512-strlen($I));$bn->send();echo
str_repeat("\0",511-($bn->size+511)%512);}function
doc_version(){$Cl=connection()->server_info;if(JUSH=='oracle'){preg_match('~(?:.* |^)(\d+)\.\d+\.\d+\.\d+\.\d+~s',$Cl,$A);return($A[1]>=18?$A[1]:"19");}$_k=(JUSH=='sql'||connection()->flavor=='cockroach'?'~^\d+\.\d+~':'~^\d\.?\d~');$io=(preg_match($_k,$Cl,$A)?$A[0]:"");if(JUSH=='mssql')return($io>=15?"sql-server-ver$io":($io==12?"azuresqldb-current":"sql-server-2017"));return$io;}function
doc_link(array$Aj,$Rm="📖"){$io=doc_version();$Rn=['sql'=>"https://dev.mysql.com/doc/refman/$io/en/",'sqlite'=>"https://www.sqlite.org/",'pgsql'=>"https://www.postgresql.org/docs/".(connection()->flavor=='cockroach'?"current":$io)."/",'mssql'=>"https://learn.microsoft.com/en-us/sql/",'oracle'=>"https://docs.oracle.com/en/database/oracle/oracle-database/$io/",];if(connection()->flavor=='maria'){$Rn['sql']="https://mariadb.com/kb/en/";$Aj['sql']=($Aj['mariadb']?:str_replace(".html","/",$Aj['sql']));}if(connection()->flavor=='cockroach'&&$Aj['cockroach']){$Rn['pgsql']="https://docs.cockroachlabs.com/docs/v$io/";$Aj['pgsql']=$Aj['cockroach'];}return($Aj[JUSH]?" <a href='".h($Rn[JUSH].$Aj[JUSH].(JUSH=='mssql'?"?view=$io":""))."'".target_blank()." class='doc' title='".lang(140)."'>$Rm</a>":"");}function
db_size($k){if(!connection()->select_db($k))return"?";$I=0;foreach(table_status()as$S)$I+=$S["Data_length"]+$S["Index_length"];return
adminer()->formatSizeValue($I);}function
set_utf8mb4($i){static$O=false;if(!$O&&preg_match('~\butf8mb4~i',$i)){$O=true;echo"SET NAMES ".charset(connection()).";\n\n";}}if(DB==""&&isset($_GET["ns"]))redirect(remove_from_uri('ns'),null,301);if(!(DB!=""?connection()->select_db(DB):isset($_GET["sql"])||isset($_GET["dump"])||isset($_GET["database"])||isset($_GET["processlist"])||isset($_GET["privileges"])||isset($_GET["user"])||isset($_GET["variables"])||$_GET["script"]=="connect")){if(DB!=""||$_GET["refresh"]){restart_session();set_session("dbs",null);}if(DB!="")page_header(lang(17).": ".h(DB),adminer()->error(),true,"","db");else{if(!isset($_GET["db"])&&support("single_db")){$j=adminer()->databases();if($j)redirect(ME."db=".url_escape($j[0]));}if($_POST["db"]&&!$m)queries_redirect(substr(ME,0,-1),lang(141),drop_databases($_POST["db"]));page_header(lang(142),$m,false);echo"<p class='links'>\n";foreach(['database'=>lang(143),'privileges'=>lang(85),'processlist'=>lang(144),'variables'=>lang(145),'status'=>lang(146),]as$x=>$X){if(support($x))echo"<a href='".h(ME)."$x='>$X</a>\n";}echo"<p>".lang(147,get_driver(DRIVER),"<b>".h(connection()->server_info)."</b>","<b>".connection()->extension."</b>")."\n","<p>".lang(148,"<b>".h(logged_user())."</b>")."\n";$j=adminer()->databases();$ke=$_GET["filter"];if($ke!=""){foreach($j
as$x=>$k){if(stripos($k,$ke)===false)unset($j[$x]);}}if($j){$dl=support("scheme");echo"<form method='post'>\n","<table class='checkable odds'".on('click','tableClick').on('dblclick','tableClick').">\n","<thead><tr>".(support("database")?"<td class='hover'>":"")."<th".(JUSH!='mssql'?" aria-sort='ascending'":"").">".lang(17).(get_session("dbs")!==null?" - <a href='".h(ME)."refresh=1'>".lang(149)."</a>":"")."<th>".lang(150)."<th>".lang(151)."<th>".lang(152)." - <a href='".h(ME)."dbsize=1'".on('click','ajaxSetHtml',ME."script=connect").">".lang(153)."</a>"."<tbody>\n";$j=($_GET["dbsize"]?count_tables($j):array_flip($j));foreach($j
as$k=>$T){$Pk=h(preg_replace('~&db=[^&]*~','',ME))."db=".url_escape($k);$t=h("Db-".$k);echo"<tr>".(support("database")?"<td class='hover'>".checkbox("db[]",$k,in_array($k,(array)$_POST["db"]),"","","",$t):""),"<th><a href='$Pk' id='$t'>".h($k)."</a>";$Hb=h(db_collation($k,[]));echo"<td>".(support("database")?"<a href='$Pk".($dl?"&amp;ns=":"")."&amp;database=' title='".lang(81)."'>$Hb</a>":$Hb),"<td align='right'><a href='$Pk&amp;schema=' id='tables-".h($k)."' title='".lang(84)."'>".($_GET["dbsize"]?format_number($T):"?")."</a>","<td align='right' id='size-".h($k)."'>".($_GET["dbsize"]?db_size($k):"?"),"\n";}echo"</table>\n",(support("database")?"<div class='footer'><div>\n"."<fieldset><legend>".lang(154)." <span id='selected'></span></legend><div>\n"."<input type='hidden' name='all' value=''".on('click','countDbs').">\n"."<input type='submit' name='drop' value='".lang(155)."'".confirm().">\n"."</div></fieldset>\n"."</div></div>\n":""),input_token(),"</form>\n",script("tableCheck();");}$qa=adminer();$Jj=($qa
instanceof
Plugins?$qa->plugins:[]);$dd=($qa
instanceof
Plugins?$qa->drivers:[]);$Oc=design_checksums();if($Jj||$dd||$Oc){$zb=($qa
instanceof
Plugins?$qa->checksums():[]);$pi=Plugins::officialChecksums();$Mn=function($Qn){return" (<a href='$Qn'".target_blank()." class='update'>".VERSION."</a>)";};$Ij=function($ge)use($zb,$pi,$Mn){return($zb[$ge]&&$pi[$ge]&&$zb[$ge]!==$pi[$ge]?$Mn("https://www.adminer.org/plugins/?version=".VERSION):"");};echo"<div class='plugins'>\n","<h3>".lang(156)."</h3>\n<ul>\n";foreach($Jj
as$Gj){$yk=new
\ReflectionObject($Gj);$Lc=Plugins::describe($Gj);echo"<li><b>".h(Plugins::pluginName($Gj))."</b>".($Lc!=""?": $Lc":"").$Ij(basename((string)$yk->getFileName(),'.php'))."\n";}foreach($dd
as$t=>$B)echo"<li><b>".h($t)."</b>: ".h($B).$Ij(basename((string)$qa->driverFiles[$t],'.php'))."\n";if($Oc){$ri=official_design_checksums();foreach($Oc
as$p=>$Nc){list($B,$yb)=$Nc;$qi=$ri["$B/$p"];echo"<li><b>".h($p)."</b>".h($B?": $B":"").($qi&&$qi!==$yb?$Mn("https://www.adminer.org/?version=".VERSION."#extras"):"")."\n";}}echo"</ul>\n";adminer()->pluginsLinks();echo"</div>\n";}}page_footer("db");exit;}if(support("scheme")){if(DB!=""&&$_GET["ns"]!==""){if(!isset($_GET["ns"]))redirect(preg_replace('~&db=[^&]+~','\0&ns='.url_escape(get_schema()),relative_uri()));if(!set_schema($_GET["ns"]))page_header(lang(16).h(": $_GET[ns]"),adminer()->error(),true,"","ns");}}adminer()->afterConnect();class
TmpFile{private$handler;var$size=0;function
__construct(){$this->handler=tmpfile();}function
write($cc){$this->size+=strlen($cc);fwrite($this->handler,$cc);}function
send(){fseek($this->handler,0);fpassthru($this->handler);fclose($this->handler);}}if($_GET["select"]!=""&&($_POST["edit"]||$_POST["clone"])&&!$_POST["save"])$_GET["edit"]=$_GET["select"];if(isset($_GET["callf"]))$_GET["call"]=$_GET["callf"];if(isset($_GET["function"]))$_GET["procedure"]=$_GET["function"];if(isset($_GET["download"])){$a=$_GET["download"];$o=fields($a);header("Content-Type: application/octet-stream");$fo=array_merge((array)$_GET["where"],(array)$_GET["val"]);header("Content-Disposition: attachment; filename=".friendly_url("$a-".implode("_",$fo)).".".friendly_url($_GET["field"]));$M=[idf_escape($_GET["field"])];$H=driver()->select($a,$M,[where($_GET,$o)],$M);$J=($H?$H->fetch_row():[]);echo
driver()->value($J[0],$o[$_GET["field"]]);exit;}elseif(isset($_GET["table"])){$a=$_GET["table"];$o=fields($a);if(!$o)$m=adminer()->error();$S=table_status1($a);$B=adminer()->tableName($S);$m=$m?:h($S["Error"]);page_header(($o&&is_view($S)?$S['Engine']=='materialized view'?lang(157):lang(158):lang(159)).": ".($B!=""?$B:h($a)),$m,[],"",!$o,($o?doc_link([JUSH=>driver()->tableHelp($a,is_view($S))]):""));$Ok=[];foreach($o
as$x=>$n)$Ok+=$n["privileges"];adminer()->selectLinks($S,(isset($Ok["insert"])||!support("table")?"":null));$f=$S["Comment"];if($f!="")echo"<p class='nowrap'>".lang(65).": ".adminer()->commentValue('TABLE',$f)."\n";if($o)adminer()->tableStructurePrint($o,$S);function
tables_links(array$T){echo"<ul>\n";foreach($T
as$J){$_=preg_replace('~&ns=[^&]*~',"&ns=".url_escape($J["ns"]),ME);echo"<li><a href='".h($_."table=".url_escape($J["table"]))."'>".($J["ns"]!=$_GET["ns"]?"<b>".h($J["ns"])."</b>.":"").h($J["table"])."</a>";}echo"</ul>\n";}$Of=driver()->inheritsFrom($a);if($Of){echo"<h3>".lang(160)."</h3>\n";tables_links($Of);}if(support("indexes")&&driver()->supportsIndex($S)){echo"<div>\n","<h3 id='indexes'>".lang(161)."</h3>\n";$w=indexes($a);if($w)adminer()->tableIndexesPrint($w,$S);if(driver()->supportsAlterIndex($S))echo'<p class="links hover"><a href="'.h(ME).'indexes='.url_escape($a).'">'.lang(162)."</a>\n";echo"</div>\n";}if(!is_view($S)&&driver()->supportsAlterTable($S)){if(fk_support($S)){echo"<div>\n","<h3 id='foreign-keys'>".lang(129)."</h3>\n";$we=foreign_keys($a);$Aa=function_exists('Adminer\alter_table');if($we){echo"<table>\n","<thead><tr><th>".lang(163)."<th>".lang(164)."<th>".lang(132)."<th>".lang(131).($Aa?"<td class='hover'>":"")."<tbody>\n";foreach($we
as$B=>$q){echo"<tr".(!is_int($B)?" title='".h($B)."'":"").">","<th><i>".implode("</i>, <i>",array_map('Adminer\h',$q["source"]))."</i>";$_=($q["db"]!=""?preg_replace('~&db=[^&]*~',"&db=".url_escape($q["db"]),ME):($q["ns"]!=""?preg_replace('~&ns=[^&]*~',"&ns=".url_escape($q["ns"]),ME):ME));echo"<td><a href='".h($_."table=".url_escape($q["table"]))."'>".($q["db"]!=""&&$q["db"]!=DB?"<b>".h($q["db"])."</b>.":"").($q["ns"]!=""&&$q["ns"]!=$_GET["ns"]?"<b>".h($q["ns"])."</b>.":"").h($q["table"])."</a>","(<i>".implode("</i>, <i>",array_map('Adminer\h',$q["target"]))."</i>)","<td>".h($q["on_delete"]),"<td>".h($q["on_update"]),($Aa?'<td class="hover"><a href="'.h(ME.'foreign='.url_escape($a).'&name='.url_escape($B)).'">'.lang(165).'</a>':''),"\n";}echo"</table>\n";}echo($Aa?'<p class="links hover"><a href="'.h(ME).'foreign='.url_escape($a).'">'.lang(166)."</a>\n":''),"</div>\n";}if(support("check")){echo"<div>\n","<h3 id='checks'>".lang(167)."</h3>\n";$ub=driver()->checkConstraints($a);if($ub){echo"<table>\n";foreach($ub
as$x=>$X)echo"<tr".($x!=$X?" title='".h($x)."'":"").">","<td><code class='sql jush-".JUSH."'>".shorten_utf8(preg_replace('~\s+~',' ',ltrim($X)),80,"</code>"),"<td class='hover'><a href='".h(ME.'check='.url_escape($a).'&name='.url_escape($x))."'>".lang(165)."</a>","\n";echo"</table>\n";}echo'<p class="links hover"><a href="'.h(ME).'check='.url_escape($a).'">'.lang(168)."</a>\n","</div>\n";}}if(support(is_view($S)?"view_trigger":"trigger")&&driver()->supportsAlterTable($S)){echo"<div>\n","<h3 id='triggers'>".lang(169)."</h3>\n";$tn=triggers($a);if($tn){echo"<table>\n";foreach($tn
as$x=>$X){echo"<tr valign='top'><td>".h($X[0])."<td>".h($X[1])."<th>".h($x)."<td class='hover'><a href='".h(ME.'trigger='.url_escape($a).'&name='.url_escape($x))."'>".lang(165)."</a>";$Qk=$X[2];if($Qk){$Sk=preg_replace('~&ns=[^&]*~',"&ns=".url_escape($Qk["ns"]),ME).'function='.url_escape($Qk["function"]).'&name='.url_escape($Qk["name"]);echo", <a href='".h($Sk)."' title='".h($Qk["name"])."'>".lang(170)."</a>";}echo"\n";}echo"</table>\n";}echo'<p class="links hover"><a href="'.h(ME).'trigger='.url_escape($a).'">'.lang(171)."</a>\n","</div>\n";}$Hl=driver()->shadowTables($a);if($Hl){echo"<h3 id='shadow-tables'>".lang(172)."</h3>\n";tables_links($Hl);}$Nf=driver()->inheritedTables($a);if($Nf){echo"<h3 id='partitions'>".lang(173)."</h3>\n";$oj=driver()->partitionsInfo($a);if($oj)echo"<p><code class='sql jush-".JUSH."'>BY ".h("$oj[partition_by]($oj[partition])")."</code>\n";tables_links($Nf);}}elseif(isset($_GET["schema"])){page_header(lang(84),"",[],h(DB.($_GET["ns"]?".$_GET[ns]":"")));function
schema_column($R,array$xk,array&$e){if(!isset($e[$R])){$e[$R]=0;foreach((array)idx($xk,$R)as$B=>$zk){if($B!=$R)$e[$R]=max($e[$R],schema_column($B,$xk,$e)+1);}}return$e[$R];}function
type_class($U){foreach(['char'=>'text','date'=>'time|year','binary'=>'blob','enum'=>'set',]as$x=>$X){if(preg_match("~$x|$X~",$U))return" class='$x'";}}$Bm=[];$Dm=[];$Cm=[];$de=[];$da=($_GET["schema"]?:$_COOKIE["adminer_schema-".str_replace(".","_",DB)]);preg_match_all('~([^:]+):([-0-9.]+)x([-0-9.]+)(_|$)~',$da,$Xg,PREG_SET_ORDER);foreach($Xg
as$s=>$A){$Bm[$A[1]]=[(float)$A[2],(float)$A[3]];$Dm[]="\n\t'".js_escape($A[1])."': [ $A[2], $A[3] ]";}$L=[];$xk=[];$we=[];$za=driver()->allFields();$kf=[];$Em=[];foreach(table_status('',true)as$R=>$S){if(!is_view($S)){if(adminer()->tableName($S)!=""&&!$S["dependent"])$Em[$R]=$S;else$kf[$R]=true;}}foreach($Em
as$R=>$S){$F=0;$L[$R]["fields"]=[];foreach($za[$R]as$n){$F+=1.25;$de[$R][$n["field"]]=$F;$L[$R]["fields"][$n["field"]]=$n;}foreach(adminer()->foreignKeys($R)as$X){if($X["db"]==""&&$X["ns"]==""&&!$kf[$X["table"]]){$we[$R][]=$X;$xk[$X["table"]][$R]=[];}}}$e=[];$Ne=[];$vo=[];$Ue=[];foreach(array_keys($L)as$B)schema_column($B,$xk,$e);arsort($e);foreach($e
as$B=>$d){$zh=null;foreach((array)idx($we,$B)as$X){if($X["table"]!=$B&&$L[$X["table"]])$zh=($zh===null?$e[$X["table"]]:min($zh,$e[$X["table"]]));}$e[$B]=max($d,(int)$zh-1);}$Gm=[];foreach($L
as$B=>$R){$d=$e[$B];$Ne[$d][]=$B;$Tm=.75*strlen($B);foreach($R["fields"]as$n)$Tm=max($Tm,.65*strlen($n["field"]));$Gm[$B]=ceil($Tm)+1;$vo[$d]=max(idx($vo,$d,0),$Gm[$B]);}foreach($we
as$B=>$eo){foreach($eo
as$X){$Te=$e[$B]+(idx($e,$X["table"],$e[$B])>$e[$B]?1:0);$Ue[$Te]=idx($Ue,$Te,0)+1;}}ksort($Ne);$if=0;$uo=0;$Kb=0;$Vj=null;$ym=[];foreach($Ne
as$d=>$T){if($Vj!==null){$Kb=round($Kb+$vo[$Vj]+1.7+idx($Ue,$d,0)*.1,1);$Ni=[];foreach($T
as$B){$nm=0;$jc=0;$Sh=array_keys((array)idx($xk,$B));foreach((array)idx($we,$B)as$X)$Sh[]=$X["table"];foreach($Sh
as$Lh){if($L[$Lh]&&$e[$Lh]<$d){$nm+=$L[$Lh]["pos"][0];$jc++;}}$Ni[$B]=($jc?$nm/$jc:$if);}asort($Ni);$T=array_keys($Ni);}$en=0;foreach($T
as$B){$F=1.25*count($L[$B]["fields"]);$L[$B]["pos"]=($Bm[$B]?:[$en,$Kb]);$ym[$B]=$L[$B]["pos"][1];$en+=2.5+$F;$if=max($if,$L[$B]["pos"][0]+2.5+$F);$uo=max($uo,round($L[$B]["pos"][1]+$vo[$d],1));if(!$Bm[$B])$Cm[]="\n\t'".js_escape($B)."': [ ".$L[$B]["pos"][0].", ".$L[$B]["pos"][1]." ]";}$Vj=$d;}$Eg=[];$Wa=[];foreach($we
as$B=>$eo){foreach($eo
as$X){$Mm=idx($ym,$X["table"],$ym[$B]);$Sl=$ym[$B]+$vo[$e[$B]];$Nk=($Mm-1>$Sl);$Cg=($Nk?$Sl+1:min($ym[$B],$Mm)-1);$Va=idx($Wa,(string)$Cg,0);$Wa[(string)$Cg]=$Va+1;$Cg=round($Nk?min($Cg+$Va*.1,$Mm-1):$Cg-$Va*.1,1);while($Eg[(string)$Cg])$Cg-=.0001;$L[$B]["references"][$X["table"]][(string)$Cg]=[$X["source"],$X["target"]];$xk[$X["table"]][$B][(string)$Cg]=$X["target"];$Eg[(string)$Cg]=true;}}echo'<div id="schema" style="height: ',$if,'em; width: ',$uo,'em;">
<script',nonce(),'>
const tablePos = {',implode(",",$Dm)."\n",'};
const tablePosDefault = {',implode(",",$Cm)."\n",'};
const em = qs(\'#schema\').offsetHeight / ',$if,';
document.onmousemove = schemaMousemove;
document.onmouseup = event => schemaMouseup(event, \'',js_escape(DB),'\');
</script>
';foreach($L
as$B=>$R){echo"<div class='table'".on('mousedown','schemaMousedown')." style='top: ".$R["pos"][0]."em; left: ".$R["pos"][1]."em; width: ".$Gm[$B]."em;'>",'<a href="'.h(ME).'table='.url_escape($B).'"><b>'.h($B)."</b></a>";foreach($R["fields"]as$n){$X='<span'.type_class($n["type"]).' title="'.h($n["type"].($n["length"]?"($n[length])":"").($n["null"]?" NULL":'')).'">'.h($n["field"]).'</span>';echo"<br>".($n["primary"]?"<i>$X</i>":$X);}foreach((array)$R["references"]as$Nm=>$zk){foreach($zk
as$Cg=>$uk){$Dg=$Cg-$R["pos"][1];$km=($Dg>0?"left: 100%; width: calc($Dg"."em - 100%)":"left: $Dg"."em");$uo=($Dg>0?"100%":(-$Dg)."em");$s=0;foreach($uk[0]as$Rl)echo"\n<div class='references' title='".h($Nm)."' id='refs$Cg-".($s++)."' style='$km"."; top: ".$de[$B][$Rl]."em; padding-top: .5em;'>"."<div style='border-top: 1px solid gray; width: $uo;'></div></div>";}}foreach((array)$xk[$B]as$Nm=>$zk){foreach($zk
as$Cg=>$Om){$Dg=$Cg-$R["pos"][1];$s=0;foreach($Om
as$Lm)echo"\n<div class='references arrow' title='".h($Nm)."' id='refd$Cg-".($s++)."' style='left: $Dg"."em; top: ".$de[$B][$Lm]."em;'>"."<div style='height: .5em; border-bottom: 1px solid gray; width: ".(-$Dg)."em;'></div>"."</div>";}}echo"\n</div>\n";}foreach($L
as$B=>$R){foreach((array)$R["references"]as$Nm=>$zk){if($L[$Nm]){foreach($zk
as$Cg=>$uk){$_h=$if;$fh=-10;foreach($uk[0]as$x=>$Rl){$Lj=$R["pos"][0]+$de[$B][$Rl];$Mj=$L[$Nm]["pos"][0]+$de[$Nm][$uk[1][$x]];$_h=min($_h,$Lj,$Mj);$fh=max($fh,$Lj,$Mj);}echo"<div class='references' id='refl$Cg' style='left: $Cg"."em; top: $_h"."em; padding: .5em 0;'><div style='border-right: 1px solid gray; margin-top: 1px; height: ".($fh-$_h)."em;'></div></div>\n";}}}}echo'</div>
<p class="links"><a href="',h(ME."schema=".url_escape($da)),'" id="schema-link">',lang(174),'</a>
';}elseif(isset($_GET["dump"])){$a=$_GET["dump"];if($_POST&&!$m){$l=["auto_increment"=>''];foreach(["type","routine","event","trigger"]as$pm){if(support($pm))$l[$pm."s"]='';}save_settings(array_intersect_key($_POST+$l,array_flip(["output","format","db_style","schema_style","table_style","data_style"])+$l),"adminer_export");$ya=(DB==""||$_GET["ns"]==="");$T=array_flip((array)$_POST["tables"])+array_flip((array)$_POST["data"]);$Sd=dump_headers((count($T)==1?key($T):DB),($ya||count($T)>1));$fg=preg_match('~sql~',$_POST["format"]);if($fg){echo"-- Adminer ".VERSION." ".get_driver(DRIVER)." ".str_replace("\n"," ",connection()->server_info)." dump\n\n";if(JUSH=="sql"){echo"SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
".($_POST["data_style"]?"SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';
":"")."
";connection()->query("SET time_zone = '+00:00'");connection()->query("SET sql_mode = ''");}}$km=$_POST["db_style"];$j=[DB];if(DB==""){$j=$_POST["databases"];if(is_string($j))$j=explode("\n",rtrim(str_replace("\r","",$j),"\n"));}foreach((array)$j
as$k){adminer()->dumpDatabase($k);if(connection()->select_db($k)){if($fg&&$km)echo
use_sql($k,$km).";\n\n";foreach(($_GET["ns"]===""?(array)$_POST["schemas"]:(DB!=""||!support("scheme")?[""]:adminer()->schemas()))as$L){if($L!=""){if(DB==""&&information_schema(DB,$L))continue;set_schema($L);}if($fg&&$_POST["schema_style"]&&function_exists('Adminer\use_schema_sql'))echo
use_schema_sql($_GET["ns"],$_POST["schema_style"]).";\n\n";$gm=($_POST["table_style"]||$_POST["data_style"]?table_status('',true):[]);$Rd=[];$vc=[];foreach($gm
as$B=>$S){if($ya||in_array($B,(array)$_POST["tables"]))$Rd[$B]=$S;if($ya||in_array($B,(array)$_POST["data"]))$vc[$B]=$S;}if($fg){if($_POST["table_style"]=="DROP+CREATE"&&function_exists('Adminer\drop_sql'))echo
drop_sql($Rd);if($_POST["data_style"]=="TRUNCATE+INSERT"&&function_exists('Adminer\truncate_all_sql')){$un=[];foreach($vc
as$B=>$S){if(!is_view($S)&&!($_POST["table_style"]=="DROP+CREATE"&&isset($Rd[$B])))$un[]=$B;}echo
truncate_all_sql($un);}$bj="";if($_POST["types"]){foreach(types()as$t=>$U){$Gc=type_definition($t);$li=($Gc["kind"]=='d'?"DOMAIN":"TYPE");if($Gc["definition"])$bj
.=($km!='DROP+CREATE'?"DROP $li IF EXISTS ".table($U).";;\n":"")."CREATE $li ".table($U)." $Gc[definition];\n\n";else$bj
.="-- Could not export type ".preg_replace('~[\r\n]+~',' ',$U)."\n\n";}}if($_POST["routines"]){foreach(routines()as$J){$B=$J["ROUTINE_NAME"];$Qk=$J["ROUTINE_TYPE"];$Mf=routine($J["SPECIFIC_NAME"],$Qk);if($Mf["definition"]!=""){$i=create_routine($Qk,["name"=>$B]+$Mf);set_utf8mb4($i);$bj
.=($km!='DROP+CREATE'?"DROP $Qk IF EXISTS ".table($B).";;\n":"")."$i;\n\n";}else$bj
.="-- Could not export ".strtolower($Qk)." ".preg_replace('~[\r\n]+~',' ',$B)."\n\n";}}if($_POST["events"]){foreach(get_rows("SHOW EVENTS",null,"-- ")as$J){$i=remove_definer(get_val("SHOW CREATE EVENT ".idf_escape($J["Name"]),3));set_utf8mb4($i);$bj
.=($km!='DROP+CREATE'?"DROP EVENT IF EXISTS ".idf_escape($J["Name"]).";;\n":"")."$i;;\n\n";}}echo($bj&&JUSH=='sql'?"DELIMITER ;;\n\n$bj"."DELIMITER ;\n\n":$bj);}if($_POST["table_style"]||$_POST["data_style"]){$ko=[];foreach($gm
as$B=>$S){$R=array_key_exists($B,$Rd);$tc=array_key_exists($B,$vc);if($R||$tc){$bn=null;if($Sd=="tar"){$bn=new
TmpFile;ob_start([$bn,'write'],1e5);}adminer()->dumpTable($B,($R?$_POST["table_style"]:""),(is_view($S)?2:0));if(is_view($S))$ko[]=$B;elseif($tc){$o=fields($B);$M=["*"];$gc=convert_fields($o,$o);if($gc)$M[]=substr($gc,2);adminer()->dumpData($B,$_POST["data_style"],"",$M);}if($fg&&$_POST["triggers"]&&$R&&($tn=trigger_sql($B)))echo"\nDELIMITER ;;\n$tn\nDELIMITER ;\n";if($Sd=="tar"){ob_end_flush();tar_file((DB!=""?"":"$k/")."$B.csv",$bn);}elseif($fg)echo"\n";}}if($fg&&$_POST["table_style"]&&function_exists('Adminer\foreign_keys_sql')){foreach($Rd
as$B=>$S){if(!is_view($S))echo
foreign_keys_sql($B);}}if($fg){foreach($ko
as$jo)adminer()->dumpTable($jo,$_POST["table_style"],1);}if($Sd=="tar")echo
pack("x1024");}}}}adminer()->dumpFooter();exit;}page_header(lang(90),$m,($_GET["export"]!=""?["table"=>$_GET["export"]]:[]),h(DB));echo'
<form method="post">
<table class="layout">
';$zc=['','USE','DROP+CREATE','CREATE'];$bl=(JUSH=="mssql"?['','DROP+CREATE','CREATE']:$zc);$Fm=['','DROP+CREATE','CREATE'];$uc=['','TRUNCATE+INSERT','INSERT'];if(JUSH=="sql")$uc[]='INSERT+UPDATE';$J=get_settings("adminer_export");if(!$J)$J=["output"=>"text","format"=>"sql","db_style"=>(DB!=""?"":"CREATE"),"schema_style"=>"","table_style"=>"DROP+CREATE","data_style"=>"INSERT"];echo"<tr><th>".lang(175)."<td>".html_radios("output",adminer()->dumpOutput(),$J["output"])."\n","<tr><th>".lang(176)."<td>".html_radios("format",adminer()->dumpFormat(),$J["format"])."\n",(JUSH=="sqlite"?"":"<tr><th>".lang(17)."<td>".html_select('db_style',$zc,$J["db_style"]).(support("type")?checkbox("types",1,$J["types"],lang(0)):"").(support("routine")?checkbox("routines",1,$J["routines"],lang(86)):"").(support("event")?checkbox("events",1,$J["events"],lang(88)):"")),(function_exists('Adminer\use_schema_sql')?"<tr><th>".lang(16)."<td>".html_select('schema_style',$bl,$J["schema_style"]):""),"<tr><th>".lang(151)."<td>".html_select('table_style',$Fm,$J["table_style"]).checkbox("auto_increment",1,$J["auto_increment"],lang(66)).(support("trigger")?checkbox("triggers",1,$J["triggers"],lang(169)):""),"<tr><th>".lang(177)."<td>".html_select('data_style',$uc,$J["data_style"]),'</table>
';adminer()->dumpPrint();echo'<p><input type=\'submit\' value=\'',lang(90),'\'>
',input_token(),'
<table',on('click','dumpClick'),'>
';$Tj=[];if($_GET["ns"]===""&&support("scheme")){echo"<thead><tr><th style='text-align: left;'>","<label class='block'><input type='checkbox' id='check-schemas' checked class='jsonly' title='".lang(178)."'".on('click','formCheck','^schemas\[').">".lang(16)."</label>","<tbody>\n";foreach(adminer()->schemas()as$L){if(!information_schema(DB,$L))echo"<tr><td>".checkbox("schemas[]",$L,true,$L,"","block")."\n";}}elseif(DB!=""){$wb=($a!=""?"":" checked");echo"<thead><tr>","<th style='text-align: left;'><label class='block'><input type='checkbox' id='check-tables'$wb class='jsonly' title='".lang(178)."'".on('click','formCheck','^tables\[').">".lang(159)."</label>","<th style='text-align: right;'><label class='block'>".lang(177)."<input type='checkbox' id='check-data'$wb class='jsonly' title='".lang(178)."'".on('click','formCheck','^data\[')."></label>","<tbody>\n";$ko="";$Im=tables_list();foreach($Im
as$B=>$U){$Sj=preg_replace('~_.*~','',$B);$wb=($a==""||$a==(substr($a,-1)=="%"?"$Sj%":$B));$Zj="<tr><td>".checkbox("tables[]",$B,$wb,$B,"","block");if($U!==null&&!preg_match('~table~i',$U))$ko
.="$Zj\n";else
echo"$Zj<td align='right'><label class='block'><span id='Rows-".h($B)."'></span>".checkbox("data[]",$B,$wb)."</label>\n";$Tj[$Sj]++;}echo$ko;if($Im)echo
script("ajaxSetHtml('".js_escape(ME)."script=db');");}else{$j=adminer()->databases();echo"<thead><tr><th style='text-align: left;'>","<label class='block'>".($j?"<input type='checkbox' id='check-databases'".($a==""?" checked":"")." class='jsonly' title='".lang(178)."'".on('click','formCheck','^databases\[').">":"").lang(17)."</label>","<tbody>\n";if($j){foreach($j
as$k){if(!information_schema($k)){$Sj=preg_replace('~_.*~','',$k);echo"<tr><td>".checkbox("databases[]",$k,$a==""||$a=="$Sj%",$k,"","block")."\n";$Tj[$Sj]++;}}}else
echo"<tr><td><textarea name='databases' rows='10' cols='20'></textarea>";}echo'</table>
</form>
';$me=true;foreach($Tj
as$x=>$X){if($x!=""&&$X>1){echo($me?"<p>":" ")."<a href='".h(ME)."dump=".url_escape("$x%")."'>".h($x)."</a>";$me=false;}}}elseif(isset($_GET["privileges"])){page_header(lang(85));echo'<p class="links"><a href="'.h(ME).'user=">'.lang(179)."</a>";$H=connection()->query("SELECT User, Host FROM mysql.".(DB==""?"user":"db WHERE ".q(DB)." LIKE Db")." ORDER BY Host, User");$Le=$H;if(!$H)$H=connection()->query("SELECT SUBSTRING_INDEX(CURRENT_USER, '@', 1) AS User, SUBSTRING_INDEX(CURRENT_USER, '@', -1) AS Host");echo"<form><p>\n";hidden_fields_get();echo
input_hidden("db",DB),($Le?"":input_hidden("grant")),"<table class='odds'>\n","<thead><tr><th>".lang(49)."<th>".lang(47)."<td class='hover'><tbody>\n";while($J=$H->fetch_assoc())echo'<tr><td>'.h($J["User"]),"<td>".h($J["Host"]),'<td class="hover"><a href="'.h(ME.'user='.url_escape($J["User"]).'&host='.url_escape($J["Host"])).'">'.lang(20)."</a>\n";if(!$Le||DB!="")echo"<tr><td><input name='user' autocapitalize='off'>","<td><input name='host' value='localhost' autocapitalize='off'>","<td class='hover'><input type='submit' value='".lang(20)."'>\n";echo"</table>\n","</form>\n";}elseif(isset($_GET["sql"])){if(!$m&&$_POST["export"]){save_settings(["output"=>$_POST["output"],"format"=>$_POST["format"]],"adminer_import");dump_headers("sql");if($_POST["format"]=="sql")echo"$_POST[query]\n";else{adminer()->dumpTable("","");adminer()->dumpData("","table",$_POST["query"]);adminer()->dumpFooter();}exit;}if(!$m&&$_POST["val"]){$ta=0;$lm=true;$nb=[];$Yk=0;foreach($_POST["val"]as$K)$Yk+=count($K);$Ya=$Yk>1&&driver()->begin();foreach($_POST["val"]as$wm=>$K){$R=bracket_escape($wm,true);$o=fields($R);$xm=indexes($R);foreach($K
as$u=>$J){parse_str(bracket_escape($u,true),$Z);$Dn=[];foreach($Z["where"]as$x=>$X)$Dn[bracket_escape($x,true)]=$X;if(!$o||$Z["null"]||array_diff_key($Dn,$o)||!unique_array($Dn,$xm)){$lm=false;break
2;}$O=[];$M=[];foreach($J
as$og=>$X){$x=bracket_escape($og,true);$n=idx($o,$x);if(!$n){$lm=false;break
3;}$O[idf_escape($x)]=(preg_match('~char|text~',$n["type"])||$X!=""?adminer()->processInput($n,$X):"NULL");$M[$og]=$x;}$ok=where($Z,$o);if(!driver()->update($R,$O," WHERE $ok",0," ")){$lm=false;break
2;}$ta+=connection()->affected_rows;$e=[];foreach($M
as$x)$e[]=idf_escape($x);$Nn=driver()->select($R,$e,[$ok],$e);$Zh=($Nn?$Nn->fetch_row():[]);$jg=0;foreach($M
as$og=>$x){$n=$o[$x];$jm=['type'=>(preg_match('~binary~',$n["type"])?'blob':$n["type"])];$nb["val[$wm][$u][$og]"]=select_value(idx($Zh,$jg++),"",$jm,null);}}}if($Ya&&$lm)$lm=driver()->commit();queries_redirect(null,lang(180,$ta),$lm);if($Ya&&!$lm)driver()->rollback();page_headers();page_messages($m);foreach($nb
as$B=>$X)echo"<div data-name='".h($B)."' hidden>$X</div>\n";exit;}restart_session();$of=&get_session("queries");$nf=&$of[DB];if(!$m&&$_POST["clear"]){$nf=[];redirect(remove_from_uri("history"));}stop_session();$ra=get_settings("adminer_import");if($_POST&&$ra)save_settings($ra,"adminer_import");page_header((isset($_GET["import"])?lang(89):lang(78)),$m);$Mg=driver()->lineComment();$Cd=0;function
query_error_line($Rm,$C,$G,$Lg){return
substr_count(substr($Rm,0,$C),"\n")+($Lg?:substr_count(substr($G,0,strspn($G," \t\r\n")),"\n")+1);}if(!$m&&$_POST&&!(isset($_GET["import"])&&adminer()->importProcess())){$Ic=driver()->delimiter;$_e=false;if(!isset($_GET["import"]))$G=$_POST["query"];elseif($_POST["webfile"]){$Wl=adminer()->importServerPath();$_e=@fopen((file_exists($Wl)?$Wl:"compress.zlib://$Wl.gz"),"rb");$G=($_e?fread($_e,1e6):false);}else$G=get_file("sql_file",true,$Ic);if(is_string($G)){if(($nh=ini_bytes("memory_limit"))!="-1")ini_set("memory_limit",max($nh,strval(2*strlen($G)+memory_get_usage()+8e6)));if($G!=""&&strlen($G)<1e6){$ik=$G.(preg_match("~$Ic\\s*\$~",$G)?"":$Ic);if(!$nf||first(end($nf))!=$ik){restart_session();$nf[]=[$ik,time()];set_session("queries",$of);stop_session();}}$Tl="(?:\\s|\xEF\xBB\xBF|/\\*[\s\S]*?\\*/|(?:$Mg)[^\n]*\n?|--\r?\n)";$C=0;$mk=0;$ud=true;$ic=false;$uh=false;$h=connect();if($h&&DB!=""){$h->select_db(DB);if($_GET["ns"]!="")set_schema($_GET["ns"],$h);}$Mb=0;$Ed=[];$lj='[\'"'.(JUSH=="sql"?'`':(JUSH=="sqlite"?'`[':(JUSH=="mssql"?'[':''))).']|/\*|'.$Mg.'|$'.(JUSH=="pgsql"?'|\$([a-zA-Z]\w*)?\$':'');$gn=microtime(true);while($G!=""){if(!$C&&preg_match("~^$Tl*+DELIMITER\\s+(\\S+)~i",$G,$A)){$Ic=preg_quote($A[1]);$G=substr($G,strlen($A[0]));$mk+=strlen($A[0]);}elseif(!$C&&JUSH=='pgsql'&&preg_match("~^($Tl*+COPY\\s+)[^;]+\\s+FROM\\s+stdin;~i",$G,$A)){$Ic="\n\\\\\\.\r?\n";$ic=true;$C=strlen($A[0]);}elseif(!$C&&JUSH=='pgsql'&&preg_match("~^$Tl*+\\\\connect[ \t][^\n]*~",$G,$A)){$Ic="\n";$uh=true;$C=strlen($A[0]);}else{preg_match("($Ic\\s*|$lj)",$G,$A,PREG_OFFSET_CAPTURE,$C);list($ye,$F)=$A[0];if(!$ye&&$_e&&!feof($_e))$G
.=fread($_e,1e5);else{if(!$ye&&rtrim($G)=="")break;$C=$F+strlen($ye);if($ye&&!preg_match("(^$Ic)",$ye)){$ib=driver()->hasCStyleEscapes()||(JUSH=="pgsql"&&($F>0&&strtolower($G[$F-1])=="e"));$Bj=($ye=='/*'?'\*/':($ye=='['?']':(preg_match("~^(?:$Mg)~",$ye)?"\n":preg_quote($ye).($ib?'|\\\\.':''))));while(preg_match("($Bj|\$)s",$G,$A,PREG_OFFSET_CAPTURE,$C)){$Zk=$A[0][0];if(!$Zk&&$_e&&!feof($_e))$G
.=fread($_e,1e5);else{$C=$A[0][1]+strlen($Zk);if(!$Zk||$Zk[0]!="\\")break;}}}else{$ik=substr($G,0,$F+($ic?3:0));$G=substr($G,$C);$jk=$mk;$mk+=$C;$C=0;if($ic||$uh){$Ic=driver()->delimiter;$ic=false;$uh=false;}$Eb="<code class='jush-".JUSH."'>".adminer()->sqlCommandQuery($ik)."</code>";if(preg_match("~^$Tl*+\$~",$ik)&&!preg_match('~/\*M?!~',$ik)){echo($_POST["only_errors"]?"":"<pre>$Eb</pre>\n");continue;}$ud=false;$Mb++;$Zj="<pre id='sql-$Mb'>$Eb</pre>\n";if(JUSH=="sqlite"&&preg_match("~^$Tl*+(ATTACH|VACUUM\\b.*\\bINTO)\\b~is",$ik,$A)!==0){echo$Zj,"<p class='error'>".lang(181,preg_match('~ATTACH~i',$A[1])?'ATTACH':'VACUUM INTO')."\n";$Ed[]=" <a href='#sql-$Mb'>$Mb</a>";if(!$Cd&&!isset($_GET["import"]))$Cd=query_error_line($_POST["query"],$jk,$ik,0);if($_POST["error_stops"])break;}else{if(!$_POST["only_errors"]){echo$Zj;ob_flush();flush();}$cm=microtime(true);if(JUSH=="pgsql"&&preg_match("~^$Tl*+\\\\connect\\s+(\"(?:[^\"]|\"\")+\"|[^\\s\";]+)\\s*;*\\s*\$~",$ik,$A)){$k=idf_unescape($A[1]);if(connection()->select_db($k)){if($h)$h->select_db($k);echo($_POST["only_errors"]?"":"<p class='message'>".lang(17).": ".h($k)." <span class='time'>(".format_time($cm).")</span>\n");continue;}}elseif(connection()->multi_query($ik)&&$h&&preg_match("~^$Tl*+USE\\b~i",$ik))$h->query($ik);do{$H=connection()->store_result();if(connection()->error){echo($_POST["only_errors"]?$Zj:""),"<p class='error'>".lang(182).(connection()->errno?" (".connection()->errno.")":"").": ".adminer()->error()."\n";$Ed[]=" <a href='#sql-$Mb'>$Mb</a>";if(!$Cd&&!isset($_GET["import"]))$Cd=query_error_line($_POST["query"],$jk,$ik,driver()->errorLine($ik));if($_POST["error_stops"])break
2;}else{$_=ME."sql=".url_escape(trim($ik));$Um=" <span class='time'>(".format_time($cm).")</span>".(strlen($_)<adminer()->sqlUrlLength()-100?" <a href='".h($_)."'>".lang(20)."</a>":"");$ta=connection()->affected_rows;$oo=($_POST["only_errors"]?"":driver()->warnings());$po="warnings-$Mb";if($oo)$Um
.=", <a href='#$po' class='toggle'>".lang(61)."</a>";$Pd="";$Qd="explain-$Mb";if(is_object($H)){$z=$_POST["limit"];$ji=$z;$ld=!$_POST["only_errors"];if($ld)echo"<form method='post'>\n";$Ri=print_select_result($H,$h,[],$ji,$ld);if(!$_POST["only_errors"]){$ji=max($H->num_rows,$ji);echo"<p class='sql-footer'>".($ji?($z&&$ji>$z?lang(183,$z):"").lang(184,$ji):""),$Um;if($h&&preg_match("~^($Tl|\\()*+SELECT\\b~i",$ik)&&($Pd=adminer()->explain($h,$ik,$Ri))!="")echo", <a href='#$Qd' class='toggle'>Explain</a>";if($ld)echo", <input type='submit' name='save' value='".lang(24)."' class='jsonly' disabled"." title='".lang(185)."'".on('click','sqlSave',lang(27)).">";$t="export-$Mb";echo", <a href='#$t' class='toggle'>".lang(90)."</a><span id='$t' class='hidden'>: ".html_select("output",adminer()->dumpOutput(),$ra["output"])." ".html_select("format",adminer()->dumpFormat(),$ra["format"]).input_hidden("query",$ik)."<input type='submit' name='export' value='".lang(90)."'".($z?"":on('click','sqlExport')).">".input_token()."</span>\n"."</form>\n";}}else{if(preg_match("~^$Tl*+(CREATE|DROP|ALTER)$Tl++(DATABASE|SCHEMA)\\b~i",$ik)){restart_session();set_session("dbs",null);stop_session();}if(!$_POST["only_errors"])echo"<p class='message' title='".h(connection()->info)."'>".lang(186,$ta)."$Um\n";}echo($oo?"<div id='$po' class='hidden'>\n$oo</div>\n":""),($Pd!=""?"<div id='$Qd' class='hidden explain'>\n$Pd</div>\n":"");}$cm=microtime(true);}while(connection()->next_result());}}}}}if($ud)echo"<p class='message'>".lang(187)."\n";else{$Cf=connection()->inTransaction();driver()->rollback();if($Cf)echo"<pre><code class='jush-".JUSH."'>ROLLBACK".(JUSH=="mssql"?" TRANSACTION":"")." -- Adminer</code></pre>\n";if($_POST["only_errors"])echo"<p class='message'>".lang(188,$Mb-count($Ed))," <span class='time'>(".format_time($gn).")</span>\n";elseif($Ed&&$Mb>1)echo"<p class='error'>".lang(182).": ".implode("",$Ed)."\n";}}else
echo"<p class='error'>".upload_error($G)."\n";}echo'
<form method="post" enctype="multipart/form-data" id="form"';$On="";if(!isset($_GET["import"]))echo
on('submit','sqlSubmit',remove_from_uri("sql|limit|error_stops|only_errors|history"),adminer()->sqlUrlLength());else
echo
on_upload_progress($On);echo'>
';$Md="<input type='submit' value='".lang(189)."' title='Ctrl+Enter'>";if(!isset($_GET["import"])){$ik=$_GET["sql"];if($_POST)$ik=$_POST["query"];elseif($_GET["history"]=="all")$ik=$nf;elseif($_GET["history"]!="")$ik=idx($nf[$_GET["history"]],0);echo"<p>";textarea("query",$ik,20,80,JUSH,$Cd);echo($_POST?"":script("qs('textarea').focus();")),"<p>";adminer()->sqlPrintAfter();echo"$Md\n",lang(190).": <input type='number' name='limit' class='size' value='".h($_POST?$_POST["limit"]:$_GET["limit"])."'>\n";}else{$Ve=(extension_loaded("zlib")?"[.gz]":"");echo"<fieldset><legend>".lang(191)."</legend><div>",($On?input_hidden(ini_get("session.upload_progress.name"),$On):""),"SQL$Ve: ".file_input(" name='sql_file[]' multiple","\n$Md"),($On?" <progress class='jsonly hidden' max='1' value='0'></progress>":""),"</div></fieldset>\n";$_f=adminer()->importServerPath();if($_f)echo"<fieldset><legend>".lang(192)."</legend><div>",lang(193,"<code>".h($_f)."$Ve</code>")," <input type='submit' name='webfile' value='".lang(194)."'>","</div></fieldset>\n";adminer()->importPrint();echo"<p>";}echo
checkbox("error_stops",1,($_POST?$_POST["error_stops"]:isset($_GET["import"])||$_GET["error_stops"]),lang(195))."\n",checkbox("only_errors",1,($_POST?$_POST["only_errors"]:isset($_GET["import"])||$_GET["only_errors"]),lang(196))."\n",input_token();if(!isset($_GET["import"])&&$nf){print_fieldset("history",lang(197),$_GET["history"]!="");for($X=end($nf);$X;$X=prev($nf)){$x=key($nf);list($ik,$Um,$qd)=$X;echo'<div><a href="'.h(ME."sql=&history=$x").'" class="hover">'.lang(20)."</a>"." <span class='time' title='".@date('Y-m-d',$Um)."'>".@date("H:i:s",$Um)."</span>"." <code class='sql jush-".JUSH."'>".shorten_utf8(preg_replace('~\s+~',' ',ltrim(preg_replace("~^(?:$Mg).*~m",'',$ik))),80,"</code>").($qd?" <span class='time'>($qd)</span>":"")."</div>\n";}echo"<input type='submit' name='clear' value='".lang(198)."'>\n","<a href='".h(ME."sql=&history=all")."'>".lang(199)."</a>\n","</div></fieldset>\n";}echo'</form>
';}elseif(isset($_GET["edit"])){$a=$_GET["edit"];$o=fields($a);$Z=(isset($_GET["select"])?($_POST["check"]&&count($_POST["check"])==1?where_check($_POST["check"][0],$o):""):where($_GET,$o));$Ln=(isset($_GET["select"])?$_POST["edit"]:$Z);foreach($o
as$B=>$n){if((!$Ln&&!isset($n["privileges"]["insert"]))||adminer()->fieldName($n)=="")unset($o[$B]);}if($_POST&&!$m&&!isset($_GET["select"])){$Rg=relative_uri((string)$_POST["referer"]);if($_POST["insert"])$Rg=($Ln?null:relative_uri());elseif(!preg_match('~^.+&select=.+$~',$Rg))$Rg=ME."select=".url_escape($a);$w=indexes($a);$En=unique_array($_GET["where"],$w);$ok="\nWHERE $Z";if(isset($_POST["delete"]))queries_redirect($Rg,lang(200),driver()->delete($a,$ok,$En?0:1));else{$O=[];foreach($o
as$B=>$n){$X=process_input($n);if($X!==false&&$X!==null)$O[idf_escape($B)]=$X;}if($Ln){if(!$O)redirect($Rg);queries_redirect($Rg,lang(201),driver()->update($a,$O,$ok,$En?0:1));if(is_ajax()){page_headers();page_messages($m);exit;}}else{$H=driver()->insert($a,$O);$Bg=($H?last_id($H):0);queries_redirect($Rg,lang(202,($Bg?" $Bg":"")),$H);}}}$J=null;$G="";$Um="";if($Z){$M=[];$ml=["*"];foreach($o
as$B=>$n){if(isset($n["privileges"]["select"])){$Ha=($_POST["clone"]&&$n["auto_increment"]?"''":convert_field($n));$d=($Ha?"$Ha AS ":"").idf_escape($B);$M[]=$d;if($Ha)$ml[]=$d;}}$J=[];if(!support("table")){$M=["*"];$ml=$M;}if($M){$cm=microtime(true);$H=driver()->select($a,$M,[$Z],$M,[],(isset($_GET["select"])?2:1));$G=str_replace("SELECT ".implode(", ",$M),"SELECT ".implode(", ",$ml),driver()->query);$Um=format_time($cm);if(!$H)$m=adminer()->error();else{$J=$H->fetch_assoc();if(!$J)$J=false;}if(isset($_GET["select"])&&(!$J||$H->fetch_assoc()))$J=null;}}if(!$o&&driver()->primary!=""){if(!$Z){$H=driver()->select($a,["*"],[],["*"]);$J=($H?$H->fetch_assoc():false);if(!$J)$J=[driver()->primary=>""];}if($J){foreach($J
as$x=>$X){if(!$Z)$J[$x]=null;$o[$x]=["field"=>$x,"null"=>($x!=driver()->primary),"auto_increment"=>($x==driver()->primary)];}}}if($_POST["save"]){$Nj=[];foreach((array)$_POST["fields"]as$x=>$X)$Nj[bracket_escape($x,true)]=$X;$J=$Nj+($J?$J:[]);}edit_form($a,$o,$J,$Ln,$m,$G,$Um);}elseif(isset($_GET["create"])){function
referencable_primary($pl){$I=[];foreach(table_status('',true)as$_m=>$R){if($_m!=$pl&&!$R["dependent"]&&fk_support($R)){foreach(fields($_m)as$n){if($n["primary"]){if($I[$_m]){unset($I[$_m]);break;}$I[$_m]=$n;}}}}return$I;}$a=$_GET["create"];$qj=driver()->partitionBy;$vj=($qj&&$a!=""?driver()->partitionsInfo($a):[]);$wk=referencable_primary($a);$we=[];foreach($wk
as$_m=>$n)$we[str_replace("`","``",$_m)."`".str_replace("`","``",$n["field"])]=$_m;$Vi=[];$S=[];$gi=false;if($a!=""){$Vi=fields($a);$S=table_status1($a);$gi=(count($S)<2);}$Aa=($a==""||driver()->supportsAlterTable($S));$J=$_POST;$J["fields"]=(array)$J["fields"];if($J["auto_increment_col"])$J["fields"][$J["auto_increment_col"]]["auto_increment"]=true;if($_POST&&!$m)save_settings(["comments"=>$_POST["comments"],"defaults"=>$_POST["defaults"]]);if($_POST&&!process_fields($J["fields"])&&!$m){if($_POST["drop"])queries_redirect(substr(ME,0,-1),lang(203),drop_tables([$a]));else{$o=[];$za=[];$Sn=false;$ue=[];$Ui=reset($Vi);$va=" FIRST";foreach($J["fields"]as$n){$q=$we[$n["type"]];$wn=($q!==null?$wk[$q]:$n);if($n["field"]!=""){if(!$n["generated"])$n["default"]=null;$n["default_sql"]=($n["generated"]=="DEFAULT SQL");$ek=process_field($n,$wn);$za[]=[$n["orig"],$ek,$va];if(!$Ui||$ek!==process_field($Ui,$Ui)){$o[]=[$n["orig"],$ek,$va];if($n["orig"]!=""||$va||(JUSH=="sqlite"&&$n["comment"]!=""))$Sn=true;}if($q!==null)$ue[idf_escape($n["field"])]=($a!=""&&JUSH!="sqlite"?"ADD":" ").format_foreign_key(['table'=>$we[$n["type"]],'source'=>[$n["field"]],'target'=>[$wn["field"]],'on_delete'=>$n["on_delete"],],object_name("FOREIGN",trim($J["name"]),[$n["field"]]));$va=" AFTER ".idf_escape($n["field"]);}elseif($n["orig"]!=""){$Sn=true;$o[]=[$n["orig"]];}if($n["orig"]!=""){$Ui=next($Vi);if(!$Ui)$va="";}}$tj=[];if(in_array($J["partition_by"],$qj)){foreach($J
as$x=>$X){if(preg_match('~^partition~',$x))$tj[$x]=$X;}foreach($tj["partition_names"]as$x=>$B){if($B==""){unset($tj["partition_names"][$x]);unset($tj["partition_values"][$x]);}}$tj["partition_names"]=array_values($tj["partition_names"]);$tj["partition_values"]=array_values($tj["partition_values"]);if($tj==$vj)$tj=[];}elseif(preg_match("~partitioned~",$S["Create_options"]))$tj=null;$ph=lang(204);if($a==""){cookie("adminer_engine",$J["Engine"]);$ph=lang(205);}$B=trim($J["name"]);$Rg=ME.(support("table")?"table=":"select=").url_escape($B);$f=($J["Comment"]!=$S["Comment"]?$J["Comment"]:null);$xd=($J["Engine"]&&$J["Engine"]!=$S["Engine"]?$J["Engine"]:"");$H=alter_table($a,$B,(JUSH=="sqlite"&&($Sn||$ue||$f!==null||$xd)?$za:$o),$ue,$f,$xd,($J["Collation"]&&$J["Collation"]!=$S["Collation"]?$J["Collation"]:""),($J["Auto_increment"]!=""?number($J["Auto_increment"]):""),$tj);if($H&&!Queries::$queries&&$a!=""&&!$o&&!$ue)redirect($Rg);queries_redirect($Rg,$ph,$H);}}$em=($a!=""?"alter":"create");page_header(($a!=""?lang(59):lang(91)),$m,["table"=>$a],h($a),$gi,doc_link(['sql'=>"$em-table.html",'mariadb'=>($a!=""?"$em-table":""),'pgsql'=>"sql-$em"."table.html",'cockroach'=>"$em-table",'mssql'=>"t-sql/statements/$em-table-transact-sql",'sqlite'=>"lang_{$em}table.html",'oracle'=>"sqlrf/".strtoupper($em)."-TABLE.html",]));if(!$_POST){$_n=driver()->types();$J=["Engine"=>$_COOKIE["adminer_engine"],"fields"=>[["field"=>"","type"=>(isset($_n["int"])?"int":(isset($_n["integer"])?"integer":"")),"on_update"=>""]],"partition_names"=>[""],];if($a!=""){$J=$S;$J["name"]=$a;$J["fields"]=[];if(!$_GET["auto_increment"])$J["Auto_increment"]="";foreach($Vi
as$n){if($n["generated"])$n["default"]=ltrim($n["default"]);$n["generated"]=$n["generated"]?:(isset($n["default"])?($n["default_sql"]?"DEFAULT SQL":"DEFAULT"):"");$J["fields"][]=$n;}if($qj){$J+=$vj;$J["partition_names"][]="";$J["partition_values"][]="";}}}$Ib=flat_collations();$yd=driver()->engines();foreach($yd
as$xd){if(!strcasecmp($xd,$J["Engine"])){$J["Engine"]=$xd;break;}}$ah=max_input_vars(12,20);if($ah){$kf=(count($J["fields"])>$ah?"":" hidden");echo"<p".($kf?" id='max-fields' data-columns='$ah'":"")." class='error$kf'>".max_input_vars_error()."\n";}echo'
<form method="post" id="form">
<p>
';if(support("columns")||$a==""){echo
lang(206).": <input name='name'".($a==""&&!$_POST?" autofocus":"")." data-maxlength='64' value='".h($J["name"])."' autocapitalize='off'>\n",(!$Aa?h($S["Engine"])."\n":($yd?html_select("Engine",[""=>"(".lang(207).")"]+$yd,$J["Engine"],on('change','helpClose').on_help_value())."\n":""));if($Ib)echo"<datalist id='collations'>".optionlist($Ib)."</datalist>\n",(preg_match("~sqlite|mssql~",JUSH)?"":"<input list='collations' name='Collation' value='".h($J["Collation"])."' placeholder='(".lang(130).")'>\n");echo"<input type='submit' value='".lang(24)."'>\n";}if(support("columns")&&$Aa){echo"<div class='scrollable'>\n","<table id='edit-fields' class='nowrap'>\n";edit_fields($J["fields"],$Ib,"TABLE",$we);echo"</table>\n",script("editFields();"),"</div>\n<p>\n",lang(66).": <input type='number' name='Auto_increment' class='size' value='".h($J["Auto_increment"])."'>\n",checkbox("defaults",1,($_POST?$_POST["defaults"]:get_setting("defaults")),lang(208),on('click','columnShowClick',6),"jsonly");$Pb=($_POST?$_POST["comments"]:get_setting("comments"));if(support("comment")){echo
checkbox("comments",1,$Pb,lang(65),on('click','editingCommentsClick',true),"jsonly").' ';$c=" name='Comment' data-maxlength='".(min_version(5.5)?2048:60)."'".($Pb?"":" class='hidden'");echo
adminer()->commentInput('TABLE',$c,$J["Comment"]);}echo'<p>
<input type=\'submit\' value=\'',lang(24),'\'>
';}echo'
';if($a!="")echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,$a)),'>
';if($qj&&(JUSH=='sql'||$a=="")){$rj=preg_match('~RANGE|LIST~',$J["partition_by"]);print_fieldset("partition",lang(210),$J["partition_by"]);echo"<p>".html_select("partition_by",array_merge([""],$qj),$J["partition_by"],on('change','partitionByChange').on_help_value('.','PARTITION BY $&'))."\n","(<input name='partition' value='".h($J["partition"])."'>)\n",lang(211).": <input type='number' name='partitions' class='size".($rj||!$J["partition_by"]?" hidden":"")."' value='".h($J["partitions"])."'>\n","<table id='partition-table'".($rj?"":" class='hidden'").">\n","<thead><tr><th>".lang(212)."<th>".lang(213)."<tbody>\n";foreach($J["partition_names"]as$x=>$X)echo'<tr>','<td><input name="partition_names[]" value="'.h($X).'" autocapitalize="off"'.($x==count($J["partition_names"])-1?on('input','partitionNameChange'):'').'>','<td><input name="partition_values[]" value="'.h(idx($J["partition_values"],$x)).'">';echo"</table>\n</div></fieldset>\n";}echo
input_token(),'</form>
';}elseif(isset($_GET["indexes"])){$a=$_GET["indexes"];$If=["PRIMARY","UNIQUE","INDEX"];$S=table_status1($a,true);$Ff=driver()->indexAlgorithms($S);if(preg_match('~MyISAM|M?aria'.(min_version(5.6,'10.0.5')?'|InnoDB':'').'~i',$S["Engine"]))$If[]="FULLTEXT";if(preg_match('~MyISAM|M?aria'.(min_version(5.7,'10.2.2')?'|InnoDB':'').'~i',$S["Engine"]))$If[]="SPATIAL";if(min_version('',11.7)&&preg_match('~MyISAM|InnoDB~i',$S["Engine"]))$If[]="VECTOR";$w=indexes($a);$o=fields($a);$Xj=[];if(JUSH=="mongo"){$Xj=$w["_id_"];unset($If[0]);unset($w["_id_"]);}$J=$_POST;if($J)save_settings(["index_options"=>$J["options"]]);if($_POST&&!$m&&!$_POST["add"]&&!$_POST["drop_col"]){$b=[];foreach($J["indexes"]as$v){$B=$v["name"];if(in_array($v["type"],$If)){$e=[];$Ig=[];$Mc=[];$Ei=[];$Gf=(support("partial_indexes")?$v["partial"]:"");$Ef=(in_array($v["algorithm"],$Ff)?$v["algorithm"]:"");$O=[];ksort($v["columns"]);foreach($v["columns"]as$x=>$d){if($d!=""){$y=idx($v["lengths"],$x);$Kc=idx($v["descs"],$x);$Di=idx($v["opclasses"],$x);$O[]=($o[$d]?idf_escape($d):$d).($y?"(".(+$y).")":"").($Di!=""?" ".idf_escape($Di):"").($Kc?" DESC":"");$e[]=$d;$Ig[]=($y?:null);$Mc[]=$Kc;$Ei[]="$Di";}}$Nd=$w[$B];if($Nd){ksort($Nd["columns"]);ksort($Nd["lengths"]);ksort($Nd["descs"]);if($v["type"]==$Nd["type"]&&array_values($Nd["columns"])===$e&&(!$Nd["lengths"]||array_values($Nd["lengths"])===$Ig)&&array_values($Nd["descs"])===$Mc&&(!$Nd["opclasses"]||array_values($Nd["opclasses"])===$Ei)&&$Nd["partial"]==$Gf&&(!$Ff||$Nd["algorithm"]==$Ef)&&!$Nd["invalid"]){unset($w[$B]);continue;}}if($e)$b[]=[$v["type"],$B,$O,$Ef,$Gf];}}foreach($w
as$B=>$Nd)$b[]=[$Nd["type"],$B,"DROP"];if(!$b)redirect(ME."table=".url_escape($a));queries_redirect(ME."table=".url_escape($a),lang(214),alter_indexes($a,$b));}page_header(lang(161),$m,["table"=>$a],h($a),false,doc_link(['sql'=>"create-index.html",'pgsql'=>"sql-createindex.html",'cockroach'=>"create-index",'mssql'=>"t-sql/statements/create-index-transact-sql",'sqlite'=>"lang_createindex.html",'oracle'=>"sqlrf/CREATE-INDEX.html",]));$fe=array_keys($o);if($_POST["add"]){foreach($J["indexes"]as$x=>$v){if($v["columns"][count($v["columns"])]!="")$J["indexes"][$x]["columns"][]="";}$v=end($J["indexes"]);if($v["type"]||array_filter($v["columns"],'strlen'))$J["indexes"][]=["columns"=>[1=>""]];}if(!$J){foreach($w
as$x=>$v){$w[$x]["name"]=$x;$w[$x]["columns"][]="";}$w[]=["columns"=>[1=>""]];$J["indexes"]=$w;}$Ig=(JUSH=="sql"||JUSH=="mssql");$Ei=driver()->indexOpclasses();$Il=($_POST?$_POST["options"]:get_setting("index_options"));$Nh=[];foreach($If
as$U)$Nh[$U]=str_replace("{table}",$a,adminer()->namePattern($U));echo'
<form method="post">
<div class="scrollable">
<table class="nowrap odds">
<thead><tr>
<th id="label-type">',lang(215);$yf=" class='idxopts".($Il?"":" hidden")."'";if($Ff)echo"<th id='label-algorithm'$yf>".lang(216).doc_link(['sql'=>'create-index.html#create-index-storage-engine-index-types','mariadb'=>'storage-engine-index-types/','pgsql'=>'indexes-types.html','cockroach'=>'create-index#parameters',]);echo'<th><input type="submit" hidden>',lang(217).($Ig?"<span$yf> (".lang(218).")</span>":"");if($Ig||support("descidx"))echo
checkbox("options",1,$Il,lang(136),on('click','indexOptionsShow'),"jsonly")."\n";echo'<th id="label-name">',lang(219);if(support("partial_indexes"))echo"<th id='label-condition'$yf>".lang(220);echo'<td><noscript>',icon("plus","add[0]","+",lang(137)),'</noscript>
<tbody>
';if($Xj){echo"<tr><td>PRIMARY<td>";foreach($Xj["columns"]as$x=>$d)echo
select_input(" disabled",array_combine($fe,$fe),$d),"<label><input disabled type='checkbox'>".lang(73)."</label> ";echo"<td><td>\n";}$jg=1;foreach($J["indexes"]as$v){if(!$_POST["drop_col"]||$jg!=key($_POST["drop_col"])){echo"<tr><td>".html_select("indexes[$jg][type]",[-1=>""]+$If,$v["type"],on('change','indexesChangeType',$Nh),"label-type");if($Ff)echo"<td$yf>".html_select("indexes[$jg][algorithm]",array_merge([""],$Ff),$v['algorithm'],"","label-algorithm");echo"<td>";ksort($v["columns"]);$s=1;foreach($v["columns"]as$x=>$d){echo"<span>".select_input(" name='indexes[$jg][columns][$s]' title='".lang(63)."'".on('change','indexesChangeColumn',$Nh),($o&&($d==""||$o[$d])?array_combine($fe,$fe):[]),$d)," <span$yf>",($Ig?"<input type='number' name='indexes[$jg][lengths][$s]' class='size' value='".h(idx($v["lengths"],$x))."' title='".lang(135)."'>":"");if($Ei){$Di=idx($v["opclasses"],$x);echo
html_select("indexes[$jg][opclasses][$s]",[""=>"(".lang(221).")"]+array_combine($Ei,$Ei)+($Di!=""?[$Di=>$Di]:[]),$Di),doc_link(['pgsql'=>'indexes-opclass.html']);}echo(support("descidx")?checkbox("indexes[$jg][descs][$s]",1,idx($v["descs"],$x),lang(73)):""),"<br>","</span></span>";$s++;}echo"<td><input name='indexes[$jg][name]' value='".h($v["name"])."' autocapitalize='off' aria-labelledby='label-name'>\n";if(support("partial_indexes"))echo"<td$yf><input name='indexes[$jg][partial]' value='".h($v["partial"])."' autocapitalize='off' aria-labelledby='label-condition'>\n";echo"<td>".icon("cross","drop_col[$jg]","x",lang(139),on('click','editingRemoveRow','indexes$1[type]')),(idx($w[$v["name"]],"invalid")?" <span class='error'>INVALID</span>":""),"\n";}$jg++;}echo'</table>
</div>
<p>
<input type=\'submit\' value=\'',lang(24),'\'>
';if(support("concurrent_indexes"))echo
checkbox("concurrently",1,$_POST["concurrently"],"concurrently").doc_link(['pgsql'=>'sql-createindex.html#SQL-CREATEINDEX-CONCURRENTLY'])."\n";echo
input_token(),'</form>
';}elseif(isset($_GET["database"])){$J=$_POST;if($_POST&&!$m&&!$_POST["add"]){$B=trim($J["name"]);if($_POST["drop"]){$_GET["db"]="";queries_redirect(remove_from_uri("db|database"),lang(222),drop_databases([DB]));}elseif($B!==DB){if(DB!=""){$_GET["db"]=$B;queries_redirect(preg_replace('~\bdb=[^&]*&~','',ME)."db=".url_escape($B),lang(223),rename_database($B,(string)$J["collation"]));}else{$j=explode("\n",str_replace("\r","",$B));$lm=true;$_g="";foreach($j
as$k){if(count($j)==1||$k!=""){if(!create_database($k,(string)$J["collation"]))$lm=false;$_g=$k;}}restart_session();set_session("dbs",null);queries_redirect(preg_replace('~&db=[^&]*~','',ME)."db=".url_escape($_g),lang(224),$lm);}}else{if(!$J["collation"])redirect(substr(ME,0,-1));query_redirect("ALTER DATABASE ".idf_escape($B).(preg_match('~^[a-z0-9_]+$~i',$J["collation"])?" COLLATE $J[collation]":""),substr(ME,0,-1),lang(225));}}$em=(DB!=""?"alter":"create");page_header(DB!=""?lang(81):lang(143),$m,[],h(DB),false,doc_link(['sql'=>"$em-database.html",'mariadb'=>(DB!=""?"":"$em-database"),'pgsql'=>"sql-$em"."database.html",'cockroach'=>"$em-database",'mssql'=>"t-sql/statements/$em-database-transact-sql",'oracle'=>"sqlrf/".strtoupper($em)."-USER.html",]));$Ib=collations();$B=DB;if($_POST)$B=$J["name"];elseif(DB!="")$J["collation"]=db_collation(DB,$Ib);elseif(JUSH=="sql"){foreach(get_vals("SHOW GRANTS")as$Le){if(preg_match('~ ON (`(([^\\\\`]|``|\\\\.)*)%`\.\*)?~',$Le,$A)&&$A[1]){$B=stripcslashes(idf_unescape("`$A[2]`"));break;}}}echo'
<form method="post">
<p>
',($_POST["add"]||strpos($B,"\n")?"<textarea autofocus name='name' rows='10' cols='40'>\n".h($B).'</textarea><br>':'<input name="name" autofocus value="'.h($B).'" data-maxlength="64" autocapitalize="off">')."\n",($Ib?"<datalist id='collations'>".optionlist(flat_collations())."</datalist>"."<input list='collations' name='collation' value='".h($J["collation"])."' placeholder='(".lang(130).")'>".doc_link(['sql'=>"charset-charsets.html",'mariadb'=>"supported-character-sets-and-collations/",'mssql'=>"relational-databases/system-functions/sys-fn-helpcollations-transact-sql",]):"")."\n",'<input type=\'submit\' value=\'',lang(24),'\'>
';if(DB!="")echo"<input type='submit' name='drop' value='".lang(155)."'".confirm(lang(209,DB)).">\n";elseif(!$_POST["add"]&&$_GET["db"]=="")echo
icon("plus","add[0]","+",lang(137))."\n";echo
input_token(),'</form>
';}elseif(isset($_GET["scheme"])){$J=$_POST;$f=(support("comment")&&$_GET["ns"]!=""?schema_comment($_GET["ns"]):"");if($_POST&&!$m){$_=preg_replace('~&ns=[^&]*~','',ME)."ns=";if($_POST["drop"])query_redirect("DROP SCHEMA ".idf_escape($_GET["ns"]),$_,lang(226));else{$B=trim($J["name"]);$_
.=url_escape($B);$Uh=(support("comment")?$J["comment"]:$f);$H=true;$ph=lang(227);if($_GET["ns"]==""){$H=queries("CREATE SCHEMA ".idf_escape($B));$ph=lang(228);}elseif($_GET["ns"]!=$B)$H=queries("ALTER SCHEMA ".idf_escape($_GET["ns"])." RENAME TO ".idf_escape($B));elseif($Uh===$f)redirect($_);if($H&&$Uh!==$f)$H=set_schema_comment($B,$Uh);queries_redirect($_,$ph,$H);}}$em=($_GET["ns"]!=""?"alter":"create");page_header($_GET["ns"]!=""?lang(82):lang(83),$m,[],"",false,doc_link(['pgsql'=>"sql-$em"."schema.html",'cockroach'=>"$em-schema",'mssql'=>"t-sql/statements/create-schema-transact-sql",]));if(!$J){$J["name"]=$_GET["ns"];$J["comment"]=$f;}echo'
<form method="post">
<p><input name="name" autofocus value="',h($J["name"]),'" autocapitalize="off">
<input type=\'submit\' value=\'',lang(24),'\'>
';if($_GET["ns"]!="")echo"<input type='submit' name='drop' value='".lang(155)."'".confirm(lang(209,$_GET["ns"])).">\n";echo(support("comment")?"<p>".lang(65).": ".adminer()->commentInput('SCHEMA'," name='comment'",$J["comment"])."\n":""),input_token(),'</form>
';}elseif(isset($_GET["call"])){$ca=($_GET["name"]?:$_GET["call"]);$Vk=(isset($_GET["callf"])?"FUNCTION":"PROCEDURE");$Qk=routine($_GET["call"],$Vk);$gg=(idx($Qk["returns"],"type")=="trigger");page_header(($gg?lang(169):lang(229)).": ".h($ca),$m,"#routines","",!$Qk,(isset($_GET["callf"])?"":doc_link(['sql'=>"call.html",'pgsql'=>"sql-call.html",'cockroach'=>"call",'mssql'=>"t-sql/language-elements/execute-transact-sql",])));$Af=[];$bj=[];foreach($Qk["fields"]as$s=>$n){if(substr($n["inout"],-3)=="OUT"&&JUSH=='sql')$bj[$s]="@".idf_escape($n["field"])." AS ".idf_escape($n["field"]);if(!$n["inout"]||preg_match('~^(IN|OUTPUT)~',$n["inout"]))$Af[]=$s;}if(!$m&&$_POST){$kb=[];foreach($Qk["fields"]as$x=>$n){$X="";if(in_array($x,$Af)){$X=process_input($n);if($X===false)$X="''";if(isset($bj[$x]))connection()->query("SET @".idf_escape($n["field"])." = $X");}if(isset($bj[$x]))$kb[]="@".idf_escape($n["field"]);elseif(in_array($x,$Af))$kb[]=$X;}$Fa=implode(", ",$kb);$G=(isset($_GET["callf"])||JUSH!="mssql"?(isset($_GET["callf"])?"SELECT ":"CALL ").(idx($Qk["returns"],"type")=="record"?"* FROM ":"").table($ca)."($Fa)":"EXEC ".table($ca).($Fa!=""?" $Fa":""));$cm=microtime(true);$H=connection()->multi_query($G);$ta=connection()->affected_rows;echo
adminer()->selectQuery($G,$cm,!$H);if(!$H)echo"<p class='error'>".adminer()->error()."\n";else{$h=connect();if($h)$h->select_db(DB);do{$H=connection()->store_result();if(is_object($H))print_select_result($H,$h);else
echo"<p class='message'>".lang(230,$ta)." <span class='time'>".@date("H:i:s")."</span>\n";}while(connection()->next_result());if($bj)print_select_result(connection()->query("SELECT ".implode(", ",$bj)));}}if($gg){$tn=driver()->routineTriggers($_GET["call"]);if($tn){echo"<table>\n";foreach($tn
as$J){$_=preg_replace('~&ns=[^&]*~',"&ns=".url_escape($J["ns"]),ME);echo"<tr><th>".h($J["trigger"])."<td><a href='".h($_."table=".url_escape($J["table"])."#triggers")."'>".($J["ns"]!=$_GET["ns"]?"<b>".h($J["ns"])."</b>.":"").h($J["table"])."</a>"."<td class='hover'><a href='".h($_."trigger=".url_escape($J["table"])."&name=".url_escape($J["trigger"]))."'>".lang(165)."</a>\n";}echo"</table>\n";}}else{echo'<form method="post">
';if($Af){echo"<table class='layout'>\n";foreach($Af
as$x){$n=$Qk["fields"][$x];$B=$n["field"];echo"<tr><th>".adminer()->fieldName($n);$Y=idx($_POST["fields"],$B);if($Y!=""){if($n["type"]=="set")$Y=implode(",",$Y);}input($n,$Y,idx($_POST["function"],$B,""));echo"\n";}echo"</table>\n";}echo'<p>
<input type=\'submit\' value=\'',lang(229),'\'>
',input_token(),'</form>
';}echo'
',adminer()->commentValue($Vk,$Qk['comment']);}elseif(isset($_GET["foreign"])){$a=$_GET["foreign"];$B=$_GET["name"];$J=$_POST;if($_POST&&!$m&&!$_POST["add"]&&!$_POST["change"]&&!$_POST["change-js"]){if(!$_POST["drop"]){$J["source"]=array_filter($J["source"],'strlen');ksort($J["source"]);$Lm=[];foreach($J["source"]as$x=>$X)$Lm[$x]=$J["target"][$x];$J["target"]=$Lm;}$Ya=false;if(JUSH=="sqlite")$H=recreate_table($a,$a,[],[],[" $B"=>($J["drop"]?"":" ".format_foreign_key($J))]);else{$Yb=$J["constraint"];$b="ALTER TABLE ".table($a);$ed="$b DROP ".(JUSH=="sql"?"FOREIGN KEY ":"CONSTRAINT ").idf_escape($B?:'');if($J["drop"])$H=queries($ed);else{$ma=" ADD".format_foreign_key($J,$Yb);if($B=="")$H=queries($b.$ma);elseif(JUSH=="pgsql"||(JUSH=="sql"&&$Yb!=$B))$H=queries("$ed,$ma");else{$Ya=support("transaction_ddl")&&driver()->begin();$H=($Ya||idx(foreign_keys($a),$B)?queries($ed):true)&&queries($b.$ma)&&(!$Ya||driver()->commit());}}}queries_redirect(ME."table=".url_escape($a),($J["drop"]?lang(231):($B!=""?lang(232):lang(233))),$H);if($Ya)driver()->rollback();if(!$J["drop"])$m=lang(234);}$gi=false;if(!$_POST&&$B!=""){$we=foreign_keys($a);$J=idx($we,$B,[]);$gi=!$J;}$Mh=str_replace("{table}",$a,adminer()->namePattern("FOREIGN"));$Ph="^".str_replace('\{columns\}','.*',preg_quote($Mh))."$";page_header(($B!=""?lang(235):lang(166)),$m,["table"=>$a],h($B!=""?$B:$a),$gi,doc_link(['sql'=>"innodb-foreign-key-constraints.html",'mariadb'=>"foreign-keys/",'pgsql'=>"sql-createtable.html#SQL-CREATETABLE-PARMS-REFERENCES",'cockroach'=>"foreign-key",'mssql'=>"t-sql/statements/create-table-transact-sql",'oracle'=>"sqlrf/constraint.html",]));if($_POST){ksort($J["source"]);if($_POST["change"]||$_POST["change-js"])$J["target"]=[];else$J["source"][]="";}elseif($B!=""){$J["source"][]="";$J["constraint"]=$B;}else{$J["table"]=$a;$J["source"]=[""];$J["constraint"]=str_replace("{columns}","",$Mh);}echo'
<form method="post">
';$Rl=array_keys(fields($a));if($J["db"]!="")connection()->select_db($J["db"]);$Wi=null;if($J["ns"]!=""){$Wi=get_schema();set_schema($J["ns"]);}$vk=array_keys(array_filter(table_status('',true),function(array$S){return!$S["dependent"]&&fk_support($S);}));$Lm=array_keys(fields(in_array($J["table"],$vk)?$J["table"]:reset($vk)));$c=on('change','foreignChange');echo"<p><label>".lang(236).": ".html_select("table",$vk,$J["table"],$c)."</label>\n";if(support("scheme")){$hi=($J["ns"]!=""?$J["ns"]:$_GET["ns"]);$cl=array_filter(adminer()->schemas(),function($L)use($hi){return!driver()->isSystem(DB,$L)||$L==$hi||$L==$_GET["ns"];});echo"<label>".lang(16).": ".html_select("ns",$cl,$hi,$c)."</label>";if($Wi!==null)set_schema($Wi);}elseif(JUSH!="sqlite"){$nl=($J["db"]!=""?$J["db"]:DB);$_c=[];foreach(adminer()->databases()as$k){if(!driver()->isSystem($k)||$k==$nl||$k==DB)$_c[]=$k;}echo"<label>".lang(94).": ".html_select("db",$_c,$nl,$c)."</label>";}if($J["db"]!="")connection()->select_db(DB);echo
input_hidden("change-js"),'<noscript><p><input type=\'submit\' name=\'change\' value=\'',lang(237),'\'></noscript>
<table',on('change','foreignName',$Ph,$Mh),'>
<thead><tr><th id="label-source">',lang(163),'<th id="label-target">',lang(164),'<tbody>
';$jg=0;foreach($J["source"]as$x=>$X){echo"<tr>","<td>".html_select("source[".(+$x)."]",[-1=>""]+$Rl,$X,($jg==count($J["source"])-1?on('change','foreignAddRow'):""),"label-source"),"<td>".html_select("target[".(+$x)."]",$Lm,idx($J["target"],$x),"","label-target");$jg++;}echo'</table>
<p>
<label>',lang(132),': ',html_select("on_delete",[-1=>""]+explode("|",driver()->onActions),$J["on_delete"]),'</label>
<label>',lang(131),': ',html_select("on_update",[-1=>""]+explode("|",driver()->onActions),$J["on_update"]),'</label>
',(support("deferrable")?html_select("deferrable",['NOT DEFERRABLE','DEFERRABLE','DEFERRABLE INITIALLY DEFERRED'],$J["deferrable"]):''),'<p>
<input type=\'submit\' value=\'',lang(24),'\'>
<noscript><p><input type=\'submit\' name=\'add\' value=\'',lang(238),'\'></noscript>
';if($B!="")echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,$B)),'>
';if(JUSH!="sqlite"){print_fieldset("options",lang(136),$J["constraint"]!=$B&&!preg_match("($Ph)",$J["constraint"]));echo"<label>".lang(219).": <input name='constraint' value='".h($J["constraint"])."' autocapitalize='off'></label>\n","</div></fieldset>\n";}echo
input_token(),'</form>
';}elseif(isset($_GET["view"])){$a=$_GET["view"];$J=$_POST;$Xi="VIEW";if(JUSH=="pgsql"&&$a!=""){$P=table_status1($a);$Xi=strtoupper($P["Engine"]);}if($_POST&&!$m){$B=trim($J["name"]);$Ha=" AS\n$J[select]";$Rg=ME."table=".url_escape($B);$ph=lang(239);$U=($_POST["materialized"]?"MATERIALIZED VIEW":"VIEW");if(!$_POST["drop"]&&$a==$B&&JUSH!="sqlite"&&$U=="VIEW"&&$Xi=="VIEW")query_redirect((JUSH=="mssql"?"ALTER":"CREATE OR REPLACE")." VIEW ".table($B).$Ha,$Rg,$ph);else{$Pm="adminer_".uniqid();drop_create("DROP $Xi ".table($a),"CREATE $U ".table($B).$Ha,"DROP $U ".table($B),"CREATE $U ".table($Pm).$Ha,"DROP $U ".table($Pm),($_POST["drop"]?substr(ME,0,-1):$Rg),lang(240),$ph,lang(241),$a,$B);}}$gi=false;if(!$_POST&&$a!=""){$J=view($a);$gi=!$J["select"];$J["name"]=$a;$J["materialized"]=($Xi!="VIEW");if(!$m)$m=adminer()->error();}page_header(($a!=""?lang(58):lang(92)),$m,["table"=>$a],h($a),$gi,doc_link(['sql'=>"create-view.html",'pgsql'=>"sql-createview.html",'cockroach'=>"create-view",'mssql'=>"t-sql/statements/create-view-transact-sql",'sqlite'=>"lang_createview.html",'oracle'=>"sqlrf/CREATE-VIEW.html",]));echo'
<form method="post">
<p>',lang(219),': <input name="name" value="',h($J["name"]),'" data-maxlength="64" autocapitalize="off">
',(support("materializedview")?" ".checkbox("materialized",1,$J["materialized"],lang(157)):""),'<p>';textarea("select",$J["select"]);echo'<p>
<input type=\'submit\' value=\'',lang(24),'\'>
';if($a!="")echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,$a)),'>
';echo
input_token(),'</form>
';}elseif(isset($_GET["event"])){$aa=$_GET["event"];$Wf=["YEAR","QUARTER","MONTH","DAY","HOUR","MINUTE","WEEK","SECOND","YEAR_MONTH","DAY_HOUR","DAY_MINUTE","DAY_SECOND","HOUR_MINUTE","HOUR_SECOND","MINUTE_SECOND"];$gm=["ENABLED"=>"ENABLE","DISABLED"=>"DISABLE","SLAVESIDE_DISABLED"=>"DISABLE ON SLAVE"];$J=$_POST;if($_POST&&!$m){if($_POST["drop"])query_redirect("DROP EVENT ".idf_escape($aa),substr(ME,0,-1),lang(242));elseif(in_array($J["INTERVAL_FIELD"],$Wf)&&isset($gm[$J["STATUS"]])){$al="\nON SCHEDULE ".($J["INTERVAL_VALUE"]?"EVERY ".q($J["INTERVAL_VALUE"])." $J[INTERVAL_FIELD]".($J["STARTS"]?" STARTS ".q($J["STARTS"]):"").($J["ENDS"]?" ENDS ".q($J["ENDS"]):""):"AT ".q($J["STARTS"]))." ON COMPLETION".($J["ON_COMPLETION"]?"":" NOT")." PRESERVE";queries_redirect(substr(ME,0,-1),($aa!=""?lang(243):lang(244)),queries(($aa!=""?"ALTER EVENT ".idf_escape($aa).$al.($aa!=$J["EVENT_NAME"]?"\nRENAME TO ".idf_escape($J["EVENT_NAME"]):""):"CREATE EVENT ".idf_escape($J["EVENT_NAME"]).$al)."\n".$gm[$J["STATUS"]]." COMMENT ".q($J["EVENT_COMMENT"]).rtrim(" DO\n$J[EVENT_DEFINITION]",";").";"));}}$gi=false;if(!$J&&$aa!=""){$K=get_rows("SELECT * FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ".q(DB)." AND EVENT_NAME = ".q($aa));$gi=!$K;$J=reset($K);}page_header(($aa!=""?lang(245).": ".h($aa):lang(246)),$m,"#events","",$gi,doc_link(['sql'=>"create-event.html"]));echo'
<form method="post">
<table class="layout">
<tr><th>',lang(219),'<td><input name="EVENT_NAME" value="',h($J["EVENT_NAME"]),'" data-maxlength="64" autocapitalize="off">
<tr><th title="datetime">',lang(247),'<td><input name="STARTS" value="',h("$J[EXECUTE_AT]$J[STARTS]"),'">
<tr><th title="datetime">',lang(248),'<td><input name="ENDS" value="',h($J["ENDS"]),'">
<tr><th>',lang(249),'<td><input type="number" name="INTERVAL_VALUE" value="',h($J["INTERVAL_VALUE"]),'" class="size"> ',html_select("INTERVAL_FIELD",$Wf,$J["INTERVAL_FIELD"]),'<tr><th>',lang(146),'<td>',html_select("STATUS",$gm,$J["STATUS"]),'<tr><th>',lang(65),'<td><input name="EVENT_COMMENT" value="',h($J["EVENT_COMMENT"]),'" data-maxlength="64">
<tr><th><td>',checkbox("ON_COMPLETION","PRESERVE",$J["ON_COMPLETION"]=="PRESERVE",lang(250)),'</table>
<p>';textarea("EVENT_DEFINITION",$J["EVENT_DEFINITION"]);echo'<p>
<input type=\'submit\' value=\'',lang(24),'\'>
';if($aa!="")echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,$aa)),'>
';echo
input_token(),'</form>
';}elseif(isset($_GET["procedure"])){$ca=($_GET["name"]?:$_GET["procedure"]);$Qk=(isset($_GET["function"])?"FUNCTION":"PROCEDURE");$J=$_POST;$J["fields"]=(array)$J["fields"];if($_POST&&!process_fields($J["fields"])&&!$m){foreach($J["fields"]as$x=>$n){if($n["field"]=="")unset($J["fields"][$x]);}$xi=routine($_GET["procedure"],$Qk);$vi=($xi?routine_id($ca,$xi):"");$Wh=routine_id($J["name"],$J);$i=create_routine($Qk,$J);$Rg=substr(ME,0,-1);$ph=lang(251);if(!$_POST["drop"]&&$vi==$Wh&&connection()->flavor!="mysql")queries_redirect($Rg,$ph,queries(substr_replace($i,(JUSH=="mssql"?' OR ALTER':' OR REPLACE'),6,0)));else{$Pm="adminer_".uniqid();drop_create("DROP $Qk $vi",$i,"DROP $Qk $Wh",create_routine($Qk,["name"=>$Pm]+$J),"DROP $Qk ".routine_id($Pm,$J),$Rg,lang(252),$ph,lang(253),$ca,$J["name"]);}}$gi=false;if(!$_POST&&$ca!=""){$J=routine($_GET["procedure"],$Qk);$gi=!$J;$J["name"]=$ca;}$Tk=strtolower($Qk);page_header(($ca!=""?(isset($_GET["function"])?lang(170):lang(254)).": ".h($ca):(isset($_GET["function"])?lang(255):lang(256))),$m,"#routines","",$gi,doc_link(['sql'=>"create-procedure.html",'mariadb'=>"create-$Tk/",'pgsql'=>"sql-create$Tk.html",'cockroach'=>"create-$Tk",'mssql'=>"t-sql/statements/create-$Tk-transact-sql",]));if(!$_POST&&$ca=="")$J["language"]="sql";$Ib=(JUSH=="sql"?flat_collations():[]);$Rk=routine_languages();echo($Ib?"<datalist id='collations'>".optionlist($Ib)."</datalist>":""),'
<form method="post" id="form">
<p>',lang(219),': <input name="name" value="',h($J["name"]),'" data-maxlength="64" autocapitalize="off">
',($Rk?"<label>".lang(30).": ".html_select("language",array_keys($Rk),$J["language"],on('change','routineLanguage',$Rk))."</label>\n":""),'<input type=\'submit\' value=\'',lang(24),'\'>
<div class="scrollable">
<table id="edit-fields" class="nowrap">
';edit_fields($J["fields"],$Ib,$Qk);if(isset($_GET["function"])){echo"<tr><td>".lang(257);edit_type("returns",(array)$J["returns"],$Ib,[],(JUSH=="pgsql"?["void","trigger"]:[]));}echo'</table>
',script("editFields();"),'</div>
<p>';textarea("definition",$J["definition"],20,80,($Rk[$J["language"]]?:JUSH));echo'<p>
<input type=\'submit\' value=\'',lang(24),'\'>
';if($ca!="")echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,$ca)),'>
';$Uk=routine_options($Qk);if($Uk){$Li=false;foreach($Uk
as$x=>$fo){$l=($fo?reset($fo):"");$J["options"][$x]=idx($J["options"],$x,$l);if($J["options"][$x]!=$l)$Li=true;}print_fieldset("options",lang(136),$Li);echo"<table class='layout'>\n";foreach($Uk
as$x=>$fo){$ug="label-option-$x";$Xm=str_replace("_"," ",$x);$M=[];foreach($fo
as$Y)$M[$Y]=(strpos($Y,"$Xm ")===0?substr($Y,strlen($Xm)+1):$Y);echo"<tr><th id='$ug'>$Xm<td>".($M?html_select("options[$x]",$M,$J["options"][$x],"",$ug):"<input name='options[$x]' value='".h($J["options"][$x])."' aria-labelledby='$ug' autocapitalize='off'>")."\n";}echo"</table>\n</div></fieldset>\n";}echo
input_token(),'</form>
';}elseif(isset($_GET["sequence"])){$ea=$_GET["sequence"];$J=$_POST;if($_POST&&!$m){$_=substr(ME,0,-1);$B=trim($J["name"]);if($_POST["drop"])query_redirect("DROP SEQUENCE ".idf_escape($ea),$_,lang(258));elseif($ea=="")query_redirect("CREATE SEQUENCE ".idf_escape($B),$_,lang(259));elseif($ea!=$B)query_redirect("ALTER SEQUENCE ".idf_escape($ea)." RENAME TO ".idf_escape($B),$_,lang(260));else
redirect($_);}$gi=(!$_POST&&$ea!=""&&!get_val("SELECT relname FROM pg_class WHERE relkind = 'S' AND relnamespace = ".driver()->nsOid." AND relname = ".q($ea)));page_header(($ea!=""?lang(261).": ".h($ea):lang(262)),$m,"#sequences","",$gi,doc_link(['pgsql'=>"sql-createsequence.html",'cockroach'=>"create-sequence",]));if(!$J)$J["name"]=$ea;echo'
<form method="post">
<p><input name="name" value="',h($J["name"]),'" autocapitalize="off">
<input type=\'submit\' value=\'',lang(24),'\'>
';if($ea!="")echo"<input type='submit' name='drop' value='".lang(155)."'".confirm(lang(209,$ea)).">\n";echo
input_token(),'</form>
';}elseif(isset($_GET["type"])){function
enum_values($Gc){$Y="'(?:[^']|'')*'";if(!preg_match('~^AS\s+ENUM\s*\(\s*('.$Y.'(?:\s*,\s*'.$Y.')*)\s*\)$~i',$Gc,$A))return
null;preg_match_all('~'.$Y.'~',$A[1],$Xg);return$Xg[0];}function
add_enum_values($U,$ti,$Th){$zi=enum_values($ti);$bi=enum_values($Th);if($zi===null||$bi===null)return
null;$I=[];$s=0;foreach($bi
as$Y){if($Y===idx($zi,$s))$s++;else$I[]="ALTER TYPE ".idf_escape($U)." ADD VALUE $Y".($s<count($zi)?" BEFORE ".$zi[$s]:"");}return($s==count($zi)?$I:null);}$fa=$_GET["type"];$J=$_POST;$xn=($fa!=""?array_search($fa,types(true)):0);$U=($xn?type_definition(+$xn):[]);$li=($U["kind"]=='d'?"DOMAIN":"TYPE");if($_POST&&!$m){$_=substr(ME,0,-1);$B=trim($J["name"]);$Ha=trim(str_replace("\r","",$J["as"]));$Yh=(preg_match('~^AS\s+(?!ENUM\b|RANGE\b|\()~i',$Ha)?"DOMAIN":"TYPE");$ph=lang(263);$b=(!$_POST["drop"]&&$fa!=""&&$Yh==$li?($Ha==$U["definition"]?[]:add_enum_values($fa,$U["definition"],$Ha)):null);if($b!==null){if($fa!=$B)$b[]="ALTER $li ".idf_escape($fa)." RENAME TO ".idf_escape($B);if(!$b)redirect($_);$Xd=false;foreach($b
as$G){if(!queries($G)){$Xd=true;break;}}queries_redirect($_,$ph,!$Xd);}else{$Pm="adminer_".uniqid();drop_create("DROP $li ".idf_escape($fa),"CREATE $Yh ".idf_escape($B)." $Ha","DROP $Yh ".idf_escape($B),"CREATE $Yh ".idf_escape($Pm)." $Ha","DROP $Yh ".idf_escape($Pm),$_,lang(264),$ph,lang(265),$fa,$B);}}page_header(($fa!=""?lang(266).": ".h($fa):lang(267)),$m,"#user-types","",($xn===false),doc_link(['pgsql'=>"sql-createtype.html",'cockroach'=>"create-type",]));if(!$J){$J["name"]=$fa;$J["as"]=($fa!=""?$U["definition"]:"AS ");}echo'
<form method="post">
<p>
',lang(219).": <input name='name' value='".h($J['name'])."' autocapitalize='off'>\n";textarea("as",$J["as"]);echo"<p><input type='submit' value='".lang(24)."'>\n";if($fa!="")echo"<input type='submit' name='drop' value='".lang(155)."'".confirm(lang(209,$fa)).">\n";echo
input_token(),'</form>
';}elseif(isset($_GET["check"])){$a=$_GET["check"];$B="$_GET[name]";$J=$_POST;if($J&&!$m){$Rg=ME."table=".url_escape($a);$sh=lang(268);$qh=lang(269);$rh=lang(270);if(JUSH=="sqlite"&&!(min_version(3.53)&&($B==""||idx(driver()->checkConstraints($a),$B)!=$B))){$ma=($J["drop"]?"":($J["name"]!=""?"CONSTRAINT ".idf_escape($J["name"])." ":"")."CHECK ($J[clause])");queries_redirect($Rg,($J["drop"]?$sh:($B!=""?$qh:$rh)),recreate_table($a,$a,[],[],[],"",[],"$B",$ma));}else{$b="ALTER TABLE ".table($a);$tb=" CHECK ($J[clause])";$Pm="adminer_".uniqid();drop_create("$b DROP CONSTRAINT ".idf_escape($B),"$b ADD".($J["name"]!=""?" CONSTRAINT ".idf_escape($J["name"]):"").$tb,"$b DROP CONSTRAINT ".idf_escape($J["name"]),"$b ADD CONSTRAINT ".idf_escape($Pm).$tb,"$b DROP CONSTRAINT ".idf_escape($Pm),$Rg,$sh,$qh,$rh,$B,$J["name"]);}}$gi=false;if(!$J){$xb=driver()->checkConstraints($a);$gi=($B!=""&&!$xb[$B]);$J=["name"=>($B==""?object_name("CHECK",$a,[]):($xb[$B]!=$B?$B:"")),"clause"=>$xb[$B]];}page_header(($B!=""?lang(271):lang(168)),$m,["table"=>$a],h($B!=""?$B:$a),$gi,doc_link(['sql'=>"create-table-check-constraints.html",'mariadb'=>"constraint/",'pgsql'=>"ddl-constraints.html#DDL-CONSTRAINTS-CHECK-CONSTRAINTS",'cockroach'=>"check",'mssql'=>"relational-databases/tables/create-check-constraints",'sqlite'=>"lang_createtable.html#check_constraints",]));echo'
<form method="post">
<p><label>',lang(219),': <input name="name" value="',h($J["name"]),'" data-maxlength="64" autocapitalize="off"></label>
<p>';textarea("clause",$J["clause"]);echo'<p><input type=\'submit\' value=\'',lang(24),'\'>
';if($B!="")echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,$B)),'>
';echo
input_token(),'</form>
';}elseif(isset($_GET["trigger"])){$a=$_GET["trigger"];$B="$_GET[name]";$sn=trigger_options();$J=trigger($B,$a);$gi=($B!=""&&!$J);$Mh=str_replace("{table}",$a,adminer()->namePattern("TRIGGER"));$J+=["Trigger"=>strtr($Mh,["{timing}"=>"b","{event}"=>"i","{columns}"=>"","{type}"=>"row"])];if($_POST){if(!$m&&in_array($_POST["Timing"],$sn["Timing"])&&in_array($_POST["Event"],$sn["Event"])&&in_array($_POST["Type"],$sn["Type"])){$_i=" ON ".table($a);$ed="DROP TRIGGER ".idf_escape($B).(JUSH=="pgsql"?$_i:"");$Rg=ME."table=".url_escape($a);if($_POST["drop"])query_redirect($ed,$Rg,lang(272));else{if($B!="")queries($ed);queries_redirect($Rg,($B!=""?lang(273):lang(274)),queries(create_trigger($_i,$_POST)));if($B!="")queries(create_trigger($_i,$J+["Type"=>reset($sn["Type"])]));}}$J=$_POST;}page_header(($B!=""?lang(275):lang(171)),$m,["table"=>$a],h($B!=""?$B:$a),$gi,doc_link(['sql'=>"create-trigger.html",'pgsql'=>"sql-createtrigger.html",'cockroach'=>"create-trigger",'mssql'=>"t-sql/statements/create-trigger-transact-sql",'sqlite'=>"lang_createtrigger.html",'oracle'=>"lnpls/CREATE-TRIGGER-statement.html",]));$Ph=strtr(preg_quote($Mh),['\{timing\}'=>'[abi]','\{event\}'=>'[iud]*','\{columns\}'=>'.*','\{type\}'=>'(row|statement)']);$qn=on('change','triggerChange',"^$Ph$",$Mh);$oi=on('input','triggerChange',"^$Ph$",$Mh);echo'
<form method="post" id="form">
<table class="layout">
<tr><th>',lang(276),'<td>',html_select("Timing",$sn["Timing"],$J["Timing"],$qn),'<tr><th>',lang(277),'<td>',html_select("Event",$sn["Event"],$J["Event"],$qn),(in_array("UPDATE OF",$sn["Event"])?" <input name='Of' value='".h($J["Of"])."' class='hidden'$oi>":""),'<tr><th>',lang(64),'<td>',html_select("Type",$sn["Type"],$J["Type"],$qn),'<tr><th>',lang(219),'<td><input name="Trigger" value="',h($J["Trigger"]),'" data-maxlength="64" autocapitalize="off">
</table>
',script("fire(qs('#form')['Timing'], 'change');"),'<p>';textarea("Statement",$J["Statement"]);echo'<p>
<input type=\'submit\' value=\'',lang(24),'\'>
';if($B!="")echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,$B)),'>
';echo
input_token(),'</form>
';}elseif(isset($_GET["user"])){function
grant($Le,array$ck,$e,$_i){if(!$ck)return
true;if($ck==["ALL PRIVILEGES","GRANT OPTION"])return($Le=="GRANT"?queries("$Le ALL PRIVILEGES$_i WITH GRANT OPTION"):queries("$Le ALL PRIVILEGES$_i")&&queries("$Le GRANT OPTION$_i"));return
queries("$Le ".preg_replace('~(GRANT OPTION)\([^)]*\)~','\1',implode("$e, ",$ck).$e).$_i);}$ga=$_GET["user"];$ck=[""=>["All privileges"=>""]];foreach(get_rows("SHOW PRIVILEGES")as$J){foreach(explode(",",($J["Privilege"]=="Grant option"?"":$J["Context"]))as$dc)$ck[$dc=="File access on server"?"Server Admin":$dc][$J["Privilege"]]=$J["Comment"];}unset($ck["Server Admin"]["Usage"]);foreach($ck["Tables"]as$x=>$X)unset($ck["Databases"][$x]);$Vh=[];if($_POST){foreach($_POST["objects"]as$x=>$X)$Vh[$X]=(array)$Vh[$X]+idx($_POST["grants"],$x,[]);}$Me=[];$H=(isset($_GET["host"])?connection()->query("SHOW GRANTS FOR ".q($ga)."@".q($_GET["host"])):null);$gi=(isset($_GET["host"])&&!$H);if($H){while($J=$H->fetch_row()){if(preg_match('~GRANT (.*) ON (.*) TO ~',$J[0],$A)&&preg_match_all('~ *([^(,]*[^ ,(])( *\([^)]+\))?~',$A[1],$Xg,PREG_SET_ORDER)){foreach($Xg
as$X){if($X[1]!="USAGE")$Me["$A[2]$X[2]"][$X[1]]=true;if(preg_match('~ WITH GRANT OPTION~',$J[0]))$Me["$A[2]$X[2]"]["GRANT OPTION"]=true;}}}}if($_POST&&!$m){$yi=(isset($_GET["host"])?q($ga)."@".q($_GET["host"]):"''");if($_POST["drop"])query_redirect("DROP USER $yi",ME."privileges=",lang(278));else{$ai=q($_POST["user"])."@".q($_POST["host"]);$xj=$_POST["pass"];$lc=false;$H=true;if($yi!=$ai){$lc=queries("CREATE USER $ai IDENTIFIED BY ".($_POST["hashed"]?"PASSWORD ":"").q($xj));$H=$lc;}elseif($xj!="")$H=queries("SET PASSWORD FOR $ai = ".(min_version(8,99)||$_POST["hashed"]?q($xj):"PASSWORD(".q($xj).")"));if($H){$Mk=[];foreach($Vh
as$li=>$Le){if(isset($_GET["grant"]))$Le=array_filter($Le);$Le=array_keys($Le);if(isset($_GET["grant"]))$Mk=array_diff(array_keys(array_filter($Vh[$li],'strlen')),$Le);elseif($yi==$ai){$ui=array_keys((array)$Me[$li]);$Mk=array_diff($ui,$Le);$Le=array_diff($Le,$ui);unset($Me[$li]);}if(preg_match('~^(.+)\s*(\(.*\))?$~U',$li,$A)&&(!grant("REVOKE",$Mk,$A[2]," ON $A[1] FROM $ai")||!grant("GRANT",$Le,$A[2]," ON $A[1] TO $ai"))){$H=false;break;}}}if($H&&isset($_GET["host"])){if($yi!=$ai)queries("DROP USER $yi");elseif(!isset($_GET["grant"])){foreach($Me
as$li=>$Mk){if(preg_match('~^(.+)(\(.*\))?$~U',$li,$A))grant("REVOKE",array_keys($Mk),$A[2]," ON $A[1] FROM $ai");}}}if($H&&!Queries::$queries)redirect(ME."privileges=");queries_redirect(ME."privileges=",(isset($_GET["host"])?lang(279):lang(280)),$H);if($lc)connection()->query("DROP USER $ai");}}page_header((isset($_GET["host"])?lang(49).": ".h("$ga@$_GET[host]"):lang(179)),$m,["privileges"=>['',lang(85)]],"",$gi,doc_link(['sql'=>"grant.html",'mariadb'=>"grant"]));$J=$_POST;if($J)$Me=$Vh;else{$J=$_GET+["host"=>get_val("SELECT SUBSTRING_INDEX(CURRENT_USER, '@', -1)")];$Me[(DB==""||$Me?"":idf_escape(addcslashes(DB,"%_\\"))).".*"]=[];}echo'<form method="post">
<table class="layout">
<tr><th>',lang(47),'<td><input name="host" data-maxlength="60" value="',h($J["host"]),'" autocapitalize="off">
<tr><th>',lang(49),'<td><input name="user" data-maxlength="80" value="',h($J["user"]),'" autocapitalize="off">
<tr><th>',lang(50),'<td><input name="pass" id="pass" value="',h($J["pass"]),'" autocomplete="new-password">
',($J["hashed"]?"":script("typePassword(qs('#pass'));")),(min_version(8,99)?"":checkbox("hashed",1,$J["hashed"],lang(281),on('click','hashedClick'))),'</table>

',"<table class='odds'>\n","<thead><tr><th colspan='2'>".lang(85);$s=0;foreach($Me
as$li=>$Le){echo'<th>'.($li!="*.*"?"<input name='objects[$s]' value='".h($li)."' size='10' autocapitalize='off'>":input_hidden("objects[$s]","*.*")."*.*");$s++;}echo"<tbody>\n";foreach([""=>"","Server Admin"=>lang(47),"Databases"=>lang(17),"Tables"=>lang(159),"Procedures"=>lang(282),]as$dc=>$Kc){foreach((array)$ck[$dc]as$bk=>$f){echo"<tr><td".($Kc?">$Kc<td":" colspan='2'").' lang="en" title="'.h($f).'">'.h($bk);$s=0;foreach($Me
as$li=>$Le){$B="'grants[$s][".h(strtoupper($bk))."]'";$Y=$Le[strtoupper($bk)];if($dc=="Server Admin"&&$li!=(isset($Me["*.*"])?"*.*":".*"))echo"<td>";elseif(isset($_GET["grant"]))echo"<td><select name=$B><option><option value='1'".($Y?" selected":"").">".lang(283)."<option value='0'".($Y=="0"?" selected":"").">".lang(284)."</select>";else
echo"<td align='center'><label class='block'>","<input type='checkbox' name=$B value='1'".($Y?" checked":"").($bk=="All privileges"?" id='grants-$s-all'":($bk=="Grant option"?"":on('click','grantsClick',"grants-$s-all"))).">","</label>";$s++;}}}echo"</table>\n",'<p>
<input type=\'submit\' value=\'',lang(24),'\'>
';if(isset($_GET["host"]))echo'<input type=\'submit\' name=\'drop\' value=\'',lang(155),'\'',confirm(lang(209,"$ga@$_GET[host]")),'>
';echo
input_token(),'</form>
';}elseif(isset($_GET["processlist"])){if(support("kill")){if($_POST&&!$m){$rg=0;foreach((array)$_POST["kill"]as$X){if(adminer()->killProcess($X))$rg++;}queries_redirect(ME."processlist=",lang(285,$rg),$rg||!$_POST["kill"]);}}page_header(lang(144),$m);echo'
<form method="post">
<div class="scrollable">
<table class="nowrap checkable odds"',on('click','tableClick').on('dblclick','tableClick'),'>
';$s=-1;foreach(adminer()->processList()as$s=>$J){if(!$s){echo"<thead><tr lang='en'>".(support("kill")?"<td class='hover'>":"");foreach($J
as$x=>$X)echo"<th>$x".doc_link(['sql'=>"show-processlist.html#processlist_".strtolower($x),'pgsql'=>"monitoring-stats.html#PG-STAT-ACTIVITY-VIEW",'oracle'=>"refrn/V-SESSION.html",]);echo"<tbody>\n";}echo"<tr>".(support("kill")?"<td class='hover'>".checkbox("kill[]",$J[JUSH=="sql"?"Id":"pid"],0):"");foreach($J
as$x=>$X)echo"<td>".($X!=""&&((JUSH=="sql"&&$x=="Info"&&preg_match("~Query|Killed~",$J["Command"]))||(JUSH=="pgsql"&&$x=="query")||(JUSH=="oracle"&&$x=="sql_text"))?"<code class='sql jush-".JUSH."' data-full='".h($X)."'>".shorten_utf8($X,100,"</code>").' <a href="'.h(($J["db"]!=""?preg_replace('~&db=[^&]*~','',ME)."db=".url_escape($J["db"])."&":ME)."sql=".url_escape($X)).'">'.lang(286).'</a>'.' '.copy_icon():h($X));echo"\n";}echo'</table>
</div>
<p>
',script("copyCode(qsl('table'));");if(support("kill"))echo
format_number($s+1)."/".lang(287,max_connections()),"<p><input type='submit' value='".lang(288)."'>\n";echo
input_token(),'</form>
',script("tableCheck();");}elseif($_GET["select"]!=""){$a=$_GET["select"];$S=table_status1($a);$w=indexes($a);$o=fields($a);$we=column_foreign_keys($a);$si=$S["Oid"];$Ok=[];$e=[];$gl=[];$Oi=[];$Sm=null;foreach($o
as$x=>$n){$B=adminer()->fieldName($n);$Oh=html_entity_decode(strip_tags($B),ENT_QUOTES);if(isset($n["privileges"]["select"])&&$B!=""){$e[$x]=$Oh;if(is_shortable($n))$Sm=adminer()->selectLengthProcess();}if(isset($n["privileges"]["where"])&&$B!="")$gl[$x]=$Oh;if(isset($n["privileges"]["order"])&&$B!="")$Oi[$x]=$Oh;$Ok+=$n["privileges"];}list($M,$Oe)=adminer()->selectColumnsProcess($e,$w);$M=array_unique($M);$Oe=array_unique($Oe);$dg=count($Oe)<count($M);$Z=adminer()->selectSearchProcess($o,$w,$S);$Ni=adminer()->selectOrderProcess($o,$w);$z=adminer()->selectLimitProcess();if($_GET["val"]&&is_ajax()){header("Content-Type: text/plain; charset=utf-8");foreach($_GET["val"]as$Fn=>$J){$Ha=convert_field($o[key($J)]);$M=[$Ha?:idf_escape(key($J))];$Z[]=where_check(bracket_escape($Fn,true),$o);$I=driver()->select($a,$M,$Z,$M);if($I)echo
first($I->fetch_row());}exit;}$Xj=$In=[];foreach($w
as$v){if($v["type"]=="PRIMARY"){$Xj=array_flip($v["columns"]);$In=($M?$Xj:[]);foreach($In
as$x=>$X){if(in_array(idf_escape($x),$M))unset($In[$x]);}break;}}if($si&&!$Xj){$Xj=$In=[$si=>0];$w[]=["type"=>"PRIMARY","columns"=>[$si]];}if($_POST&&!$m){$ro=$Z;if(!$_POST["all"]&&is_array($_POST["check"])){$xb=[];foreach($_POST["check"]as$tb)$xb[]=where_check($tb,$o);$ro[]="((".implode(") OR (",$xb)."))";}$to=$ro;$ro=($ro?"\nWHERE ".implode(" AND ",$ro):"");if($_POST["export"]){save_settings(["output"=>$_POST["output"],"format"=>$_POST["format"]],"adminer_import");dump_headers($a);adminer()->dumpTable($a,"");$ll=($M?:["*"]);$gc=convert_fields($e,$o,$M);if($gc)$ll[]=substr($gc,2);$G="";if(is_array($_POST["check"])&&!$Xj){$Ce=implode(", ",$ll)."\nFROM ".table($a);$Qe=($Oe&&$dg?"\nGROUP BY ".implode(", ",$Oe):"").($Ni?"\nORDER BY ".implode(", ",$Ni):"");$Cn=[];foreach($_POST["check"]as$X)$Cn[]="(SELECT".limit($Ce,"\nWHERE ".($Z?implode(" AND ",$Z)." AND ":"").where_check($X,$o).$Qe,1).")";$G=implode(" UNION ALL ",$Cn);}adminer()->dumpData($a,"table",$G,$ll,$to,($dg?$Oe:[]),$Ni);adminer()->dumpFooter();exit;}if(!adminer()->selectEmailProcess($Z,$we)){if($_POST["save"]||$_POST["delete"]){$H=true;$ta=0;$Ya=false;$O=[];if(!$_POST["delete"]){foreach($o
as$B=>$X){$u=bracket_escape($B);if(isset($_POST["fields"][$u])||$_FILES["fields-$u"]){$X=process_input($o[$B]);if($X!==null&&($_POST["clone"]||$X!==false))$O[idf_escape($B)]=($X!==false?$X:idf_escape($B));}}}if($_POST["delete"]||$O){$G=($_POST["clone"]?"INTO ".table($a)." (".implode(", ",array_keys($O)).")\nSELECT ".implode(", ",$O)."\nFROM ".table($a):"");if($_POST["all"]||($Xj&&is_array($_POST["check"]))||$dg){$H=($_POST["delete"]?driver()->delete($a,$ro):($_POST["clone"]?queries("INSERT $G$ro".driver()->insertReturning($a)):driver()->update($a,$O,$ro)));$ta=connection()->affected_rows;if(is_object($H))$ta+=$H->num_rows;}else{$Ya=count((array)$_POST["check"])>1&&driver()->begin();foreach((array)$_POST["check"]as$X){$qo="\nWHERE ".($Z?implode(" AND ",$Z)." AND ":"").where_check($X,$o);$H=($_POST["delete"]?driver()->delete($a,$qo,1):($_POST["clone"]?queries("INSERT".limit1($a,$G,$qo)):driver()->update($a,$O,$qo,1)));if(!$H)break;$ta+=connection()->affected_rows;}if($Ya&&$H&&!driver()->commit())$H=false;}}$ph=lang(180,$ta);if($_POST["clone"]&&$H&&$ta==1){$Bg=last_id($H);if($Bg)$ph=lang(202," $Bg");}queries_redirect(remove_from_uri($_POST["all"]&&$_POST["delete"]?"page|next":""),$ph,$H);if($Ya)driver()->rollback();if(!$_POST["delete"]){$Nj=(array)$_POST["fields"];edit_form($a,array_intersect_key($o,$Nj),$Nj,!$_POST["clone"],$m);page_footer();exit;}}elseif(!$_POST["import"]){$H=true;$ta=0;$Ya=count((array)$_POST["val"])>1&&driver()->begin();foreach((array)$_POST["val"]as$Fn=>$J){$O=[];foreach($J
as$x=>$X){$x=bracket_escape($x,true);$O[idf_escape($x)]=(preg_match('~char|text~',$o[$x]["type"])||$X!=""?adminer()->processInput($o[$x],$X):"NULL");}$H=driver()->update($a,$O," WHERE ".($Z?implode(" AND ",$Z)." AND ":"").where_check(bracket_escape($Fn,true),$o),($dg||$Xj?0:1)," ");if(!$H)break;$ta+=connection()->affected_rows;}if($Ya)$H=$H&&driver()->commit();queries_redirect(remove_from_uri(),lang(180,$ta),$H);if($Ya)driver()->rollback();}else{save_settings(["format"=>$_POST["separator"]],"adminer_import");$ge=get_file("csv_file",true);if(!is_string($ge))$m=upload_error($ge);elseif(!preg_match('~~u',$ge))$m=lang(289);else{$Jb=array_keys($o);$rl=($_POST["separator"]=="csv"?",":($_POST["separator"]=="tsv"?"\t":";"));$pc=parse_csv($ge,$rl);$ta=count($pc);driver()->begin();$K=[];foreach($pc
as$x=>$fo){if(!$x&&!array_diff($fo,$Jb)){$Jb=$fo;$ta--;}else{$O=[];foreach($fo
as$s=>$Fb)$O[idf_escape($Jb[$s])]=($Fb==""&&$o[$Jb[$s]]["null"]?"NULL":q(csv_value($Fb)));$K[]=$O;}}$H=(!$K||driver()->insertUpdate($a,$K,$Xj));if($H)driver()->commit();queries_redirect(remove_from_uri("page|next"),lang(290,$ta),$H);driver()->rollback();}}}}$_m=adminer()->tableName($S);if(is_ajax()){page_headers();ob_start();}else
page_header(lang(68).": $_m",$m,[],"",(!$o&&support("table")),($o?doc_link([JUSH=>driver()->tableHelp($a,is_view($S))]):""));$O=null;if(isset($Ok["insert"])||!support("table")){$O="";foreach((array)$_GET["where"]as$X){$Y=$X["val"];if(is_array($Y))$Y=(count($Y)==1&&preg_match('~^val-(.*)~s',reset($Y),$A)?$A[1]:"");if($X["col"]!=""&&$Y!=""&&($X["op"]=="="||(!$X["op"]&&(is_array($X["val"])||!preg_match('~[_%]~',$Y)))))$O
.="&set[".url_escape(bracket_escape($X["col"]))."]=".url_escape($Y);}}adminer()->selectLinks($S,$O);if(!$e&&support("table"))echo"<p class='error'>".lang(291)."\n";else{echo"<form id='form'>\n","<div hidden>";hidden_fields_get();echo(DB!=""?input_hidden("db",DB).(isset($_GET["ns"])?input_hidden("ns",$_GET["ns"]):""):""),input_hidden("select",$a),"</div>\n";adminer()->selectColumnsPrint($M,$e);adminer()->selectSearchPrint($Z,$gl,$w,$S);adminer()->selectOrderPrint($Ni,$Oi,$w);adminer()->selectLimitPrint($z);if($Sm!==null)adminer()->selectLengthPrint($Sm);adminer()->selectActionPrint($w);echo"</form>\n";foreach((array)$_GET["where"]as$X){if($X["op"]=="SQL"&&!in_array($_SERVER["HTTP_SEC_FETCH_SITE"],["","same-origin"])){echo"<p class='error'>".lang(125).' '.lang(126)."\n";page_footer();exit;}}$D=$_GET["page"];$ze=null;if($D=="last"){$ze=get_val(count_rows($a,$Z,$dg,$Oe));$D=floor(max(0,intval($ze)-1)/$z);}$kl=$M;$Pe=$Oe;if(!$kl){$kl[]="*";$gc=convert_fields($e,$o,$M);if($gc)$kl[]=substr($gc,2);}foreach($M
as$x=>$X){$n=$o[idf_unescape($X)];if($n&&($Ha=convert_field($n)))$kl[$x]="$Ha AS $X";}if(JUSH=="pgsql"||JUSH=="mssql"){foreach((array)$_GET["columns"]as$x=>$X){if(isset($kl[$x])&&$X["fun"])$kl[$x].=" AS ".idf_escape(apply_sql_function($X["fun"],($X["col"]!=""?$X["col"]:"*")));}}if(!$dg&&$In){foreach($In
as$x=>$X){$kl[]=idf_escape($x);if($Pe)$Pe[]=idf_escape($x);}}$H=driver()->select($a,$kl,$Z,$Pe,$Ni,$z,$D,true);if(!is_object($H))echo"<p class='error'>".(adminer()->error()?:lang(32))."\n";else{if(JUSH=="mssql"&&$D)$H->seek($z*$D);$td=[];$K=[];while($J=$H->fetch_assoc()){if($D&&JUSH=="oracle")unset($J["RNUM"]);$K[]=$J;}$bf=($z&&(support("cursor")?$_GET["next"]!="":count($K)>=$z));if(is_ajax()&&$bf)header("X-Next-Page: ".pagination_href($D+1));if($_GET["modify"]&&$K){$gh=max_input_vars(count($K[0])+1,20);echo($gh&&count($K)>$gh?"<p class='error'>".max_input_vars_error()."\n":"");}echo"<form method='post' enctype='multipart/form-data'".on_upload_progress($On).">\n";if($_GET["page"]!="last"&&$z&&$Oe&&$dg&&JUSH=="sql")$ze=get_val(" SELECT FOUND_ROWS()");if(!$K)echo"<p class='message'>".lang(22)."\n";else{$Ua=adminer()->backwardKeys($a,$_m);$Jk=[];reset($M);foreach($K[0]as$x=>$X){if(!isset($In[$x])){$X=idx($_GET["columns"],key($M))?:[];$Jk[$x]=["fun"=>$X["fun"],"col"=>($M?$X["col"]:$x)];next($M);}}echo"<div class='scrollable'>","<table id='table' class='nowrap checkable odds'".on('click','tableClick').on('dblclick','tableClick').on('keydown','editingKeydown').">\n","<thead><tr>".(!$Oe&&$M?"":"<td class='hover check sticky'><input type='checkbox' id='all-page' class='jsonly' title='".lang(292)."'".on('click','formCheck','^check').">");$Qh=[];$sk=1;foreach($Jk
as$x=>$X){$n=$o[$X["col"]];$B=($n?adminer()->fieldName($n,$sk):($X["fun"]?($X["col"]!=""?h($X["col"]):"*"):h($x)));if($B!=""){$sk++;$Qh[$x]=$B;$d=idf_escape($x);$rf=remove_from_uri('(order|desc)[^=]*|page|next').'&order[0]='.url_escape($x);$Kc="&desc[0]=1";$Ol=preg_replace('~ DESC( NULLS LAST)?$~','',$Ni[0]);$Ql=($Ol==$d||$Ol==$x);echo"<th id='th[".h(bracket_escape($x))."]'".($Ql?" aria-sort='".($Ol==$Ni[0]?"ascending":"descending")."'":"").">";$He=apply_sql_function(h($X["fun"]),$B);$Pl=isset($n["privileges"]["order"])||$X["fun"];echo($Pl?"<a href='".h($rf.($Ql&&$Ol==$Ni[0]?$Kc:''))."'>$He</a>":$He),doc_link([JUSH=>$n["doc"]]);$oh=($Pl?"<a href='".h($rf.$Kc)."' title='".lang(73)."' class='text'> ↓</a>":'');if(!$X["fun"]&&isset($n["privileges"]["where"]))$oh
.="<a href='#fieldset-search' title='".lang(18)."' class='text jsonly'".on('click','selectSearch',$x)."> =</a>";echo($oh?"<span class='column'>$oh</span>":"");}}$Ig=[];if($_GET["modify"]){foreach($K
as$J){foreach($J
as$x=>$X)$Ig[$x]=max($Ig[$x],min(40,utf8_length((string)$X)));}}$mf=[];$lf=[];foreach((array)$_GET["where"]as$X){$X+=["col"=>"","op"=>"","val"=>""];$Fb=$X["col"];$fl=$X["val"];if(!is_array($fl)&&($fl!=""||preg_match('~NULL$~',$X["op"]))&&(!$X["op"]||in_array($X["op"],adminer()->operators($S)))){$Kg=strtr(preg_quote($fl),["%"=>".*?","_"=>"."]);$Cj=["LIKE %%"=>$Kg,"ILIKE %%"=>$Kg,"REGEXP"=>$fl]+(JUSH=="pgsql"?["~"=>$fl,"~*"=>$fl]:[])+($Fb!=""?[]:["="=>'^'.preg_quote($fl).'\z',"IN"=>'^(?:'.implode("|",array_map('preg_quote',array_map('trim',explode(",",$fl)))).')\z',"LIKE"=>"^$Kg\\z","ILIKE"=>"^$Kg\\z","FIND_IN_SET"=>'(?<=^|,)'.preg_quote($fl).'(?=,|\z)',]);foreach(($Fb!=""?[$Fb=>$o[$Fb]]:$o)as$B=>$n){if($Fb!=""||is_searchable($n,$X)){$Ci=$X["op"]?:(!preg_match('~'.text_type().'~',$n["type"])?"IN":(preg_match('~%~',$fl)?"LIKE":"LIKE %%"));if(isset($Cj[$Ci])){$_b=preg_match('~^ILIKE|\*$~',$Ci)||($Ci!="~"&&preg_match('~^(sql|mssql|sqlite)$~',JUSH));$mf[$B][]="(?".($_b?"i":"").":$Cj[$Ci])";}elseif($Ci=="IS NULL"&&$Fb=="")$lf[$B]=true;}}}}echo($Ua?"<th>".lang(293):"")."<tbody>\n";if(is_ajax())ob_end_clean();foreach(adminer()->rowDescriptions($K,$we)as$Kh=>$J){$En=unique_array($K[$Kh],$w);if(!$En){$En=[];foreach($K[$Kh]as$x=>$X){if(!in_array(idx(idx($Jk,$x,[]),"fun"),driver()->grouping))$En[$x]=$X;}}$Fn="";$s=0;foreach($En
as$x=>$X){$Ik=idx($Jk,$x,[]);if($M&&$Ik&&!isset($o[$Ik["col"]]))continue;$He=idx($Ik,"fun","");$Fb=($He?$Ik["col"]:$x);$n=(array)$o[$Fb];$cg=is_blob($n);if(!$He&&strlen($X)>64&&driver()->md5(idf_escape($Fb),$n)){$He="md5";$X=md5($cg?(string)driver()->value($X,$n):$X);}if($He){$Fn
.="&fun[$s]=".url_escape($He)."&col[$s]=".url_escape($Fb).($X!==null?"&val[$s]=".url_escape($X===false?"f":$X):"");$s++;}else$Fn
.="&".($X!==null?"where[".url_escape(bracket_escape($Fb))."]=".url_escape($X===false?"f":$X):"null[]=".url_escape($Fb));}echo"<tr>".(!$Oe&&$M?"":"<td class='hover check sticky'>".($dg||information_schema(DB)?"":"<a href='".h(ME."edit=".url_escape($a).$Fn)."' class='edit'>".lang(294)."</a> ").checkbox("check[]",substr($Fn,1),in_array(substr($Fn,1),(array)$_POST["check"])));foreach($J
as$x=>$X){if(isset($Qh[$x])){$He=$Jk[$x]["fun"];$Fb=$Jk[$x]["col"];$n=(array)$o[$x];if($X!=""&&(!isset($td[$x])||$td[$x]!=""))$td[$x]=(is_mail($X)?$Qh[$x]:"");$_="";if(is_blob($n)&&$X!="")$_=ME.'download='.url_escape($a).'&field='.url_escape($x).$Fn;if(!$_&&$X!==null){foreach((array)$we[$x]as$q){if(count($we[$x])==1||end($q["source"])==$x){$_="";foreach($q["source"]as$s=>$Rl)$_
.=where_link($s,$q["target"][$s],$K[$Kh][$Rl]);$_=($q["db"]!=""?preg_replace('~&db=[^&]*~',"&db=".url_escape($q["db"]),ME):ME).'select='.url_escape($q["table"]).$_;if($q["ns"]!="")$_=preg_replace('~&ns=[^&]*~',"&ns=".url_escape($q["ns"]),$_);if(count($q["source"])==1)break;}}}if($He=="count"&&$Fb==""){$_=ME."select=".url_escape($a);$s=0;foreach((array)$_GET["where"]as$W){if(!array_key_exists($W["col"],$En))$_
.=where_link($s++,$W["col"],$W["val"],$W["op"]);}foreach($En
as$ng=>$W){if(idx(idx($Jk,$ng,[]),"fun")){$_="";break;}$_
.=where_link($s++,$ng,$W);}}$sf=select_value($X,$_,$n,$Sm,($He?[]:idx($mf,$x,[])));if($X===null&&!$He&&isset($lf[$x]))$sf="<mark>$sf</mark>";$u=bracket_escape($Fn);$t=h("val[$u][".bracket_escape($x)."]");$Pj=idx(idx($_POST["val"],$u),bracket_escape($x));$Ln=idx($n["privileges"],"update")&&!is_identity_always($n);$od=$K[$Kh][$x];$pd=!is_array($J[$x])&&!is_blob($n)&&is_utf8($od)&&!$He&&!$n["generated"]&&$Ln;$U=($He=="min"||$He=="max"?$o[$Fb]["type"]:$n["type"]);$Rm=preg_match('~text|json|lob~',$U);$eg=preg_match(number_type(),$U)||preg_match('~^(avg|ceil|char_length|count|count distinct|floor|len|length|round|sum|time_to_sec)$~',$He);echo"<td id='$t'".($eg&&($X===null||is_numeric(strip_tags($sf))||$U=="money")?" class='number'":"");if(($_GET["modify"]&&$pd&&$od!==null)||$Pj!==null){$We=h($Pj!==null?$Pj:$od);echo">".($Rm?"<textarea name='$t' cols='30' rows='".(substr_count($od,"\n")+1)."'>\n$We</textarea>":"<input name='$t' value='$We' size='$Ig[$x]'>");}else{$Tg=strpos($sf,"<i>…</i>");$nd=adminer()->editVal($od,$n);echo($Ln?" data-text='".($Tg?2:($Rm?1:0))."'".($pd?"":" data-warning='".lang(295)."'").($pd&&!$Tg&&html_entity_decode(strip_tags($sf),ENT_QUOTES,"UTF-8")!==$nd?" data-value='".h($nd)."'":""):"").">$sf";}}}if($Ua)echo"<td>";adminer()->backwardKeysPrint($Ua,$K[$Kh]);echo"</tr>\n";}if(is_ajax())exit;echo"</table>\n","</div>\n";}if(!is_ajax()){$sa=get_settings("adminer_import");if($K||$D||$bf){$Ld=true;if($_GET["page"]!="last"){if(!$z||(count($K)<$z&&($K||!$D)))$ze=($D?$D*$z:0)+count($K);elseif(JUSH!="sql"||!$dg){$ze=($dg?null:found_rows($S,$Z));$Ld=!driver()->hasEstimatedRows();if($ze===null||(!$Ld&&$ze<max(1e4,2*($D+1)*$z))){$ze=first(slow_query(count_rows($a,$Z,$dg,$Oe)));$Ld=true;}}}if(!support("cursor"))$bf=(($ze===false?count($K)+1:$ze-$D*$z)>$z);$gj=($z&&($bf||$D));if($gj)echo($bf?'<p><a href="'.h(pagination_href($D+1)).'" class="loadmore"'.on('click','selectLoadMore',lang(296)).'>'.lang(297).'</a>':''),"\n";echo"<div class='footer'><div>\n";if($gj){$eh=($ze===false?$D+($K?(count($K)>=$z?2:1):0):floor(($ze-1)/$z));echo"<fieldset><legend>".lang(298)."</legend>";if(!support("cursor")){echo
pagination(0,$D).($D>5?" …":"");for($s=max(1,$D-4);$s<min($eh,$D+5);$s++)echo
pagination($s,$D);if($eh>0)echo($D+5<$eh?" …":""),($Ld&&$ze!==false?pagination($eh,$D):" <a href='".h(remove_from_uri("page")."&page=last")."' title='~$eh'>".lang(299)."</a>");}else
echo
pagination(0,$D).($D>1?" …":""),($D?pagination($D,$D):""),($bf?pagination($D+1,$D)." …":"");echo"</fieldset>\n";}echo"<fieldset>","<legend>".lang(300)."</legend>";$Sc=($Ld?"":"~ ").$ze;$ug=($ze!==false?($Ld?"":"~ ").lang(184,$ze):"");echo
checkbox("all",1,0,$ug,on('click','countRows',$Sc))."\n","</fieldset>\n";if(adminer()->selectCommandPrint())echo'<fieldset',($_GET["modify"]?'':" title='".lang(185)."'"),'>
<legend><a href=\'',h($_GET["modify"]?remove_from_uri("modify"):relative_uri()."&modify=1"),'\'>',lang(301),'</a></legend><div>
<input type=\'submit\' id=\'save\' value=\'',lang(24),'\'',($_GET["modify"]||$_POST["val"]?'':" class='jsonly' disabled"),'>
</div></fieldset>

<fieldset><legend>',lang(154),' <span id="selected"></span></legend><div>
<input type=\'submit\' name=\'edit\' value=\'',lang(20),'\'>
<input type=\'submit\' name=\'clone\' value=\'',lang(286),'\'>
<input type=\'submit\' name=\'delete\' value=\'',lang(28),'\'',confirm(),'>
</div></fieldset>
';$xe=adminer()->dumpFormat();foreach((array)$_GET["columns"]as$d){if($d["fun"]||($d["col"]!=""&&!isset($o[$d["col"]]))){unset($xe['sql']);break;}}if($xe){print_fieldset("export",lang(90)." <span id='selected2'></span>");$cj=adminer()->dumpOutput();echo($cj?html_select("output",$cj,$sa["output"])." ":""),html_select("format",$xe,$sa["format"])," <input type='submit' name='export' value='".lang(90)."'>\n","</div></fieldset>\n";}adminer()->selectEmailPrint(array_filter($td,'strlen'),$e);echo"</div></div>\n";}if(adminer()->selectImportPrint())echo"<p>","<a href='#import' class='toggle'>".lang(89)."</a>","<span id='import'".($_POST["import"]?"":" class='hidden'").">: ",($On?input_hidden(ini_get("session.upload_progress.name"),$On):""),file_input(" name='csv_file'"," ".html_select("separator",["csv"=>"CSV,","csv;"=>"CSV;","tsv"=>"TSV"],$sa["format"])." <input type='submit' name='import' value='".lang(89)."'>".($On?" <progress class='jsonly hidden' max='1' value='0'></progress>":"")),"</span>";echo
input_token(),"</form>\n",(!$Oe&&$M?"":script("tableCheck();"));}}}if(is_ajax()){ob_end_clean();exit;}}elseif(isset($_GET["variables"])){$P=isset($_GET["status"]);page_header($P?lang(146):lang(145));$go=($P?adminer()->showStatus():adminer()->showVariables());if(!$go)echo"<p class='message'>".lang(22)."\n";else{echo"<table>\n";foreach($go
as$J){echo"<tr>";$x=array_shift($J);echo"<th><code class='jush-".JUSH.($P?"status":"set")."'>".h($x)."</code>";foreach($J
as$X)echo"<td>".nl_br(h($X));}echo"</table>\n";}}elseif(isset($_GET["script"])){header("Content-Type: application/json; charset=utf-8");if($_GET["script"]=="db"){$om=["Data_length"=>0,"Index_length"=>0,"Data_free"=>0];foreach(table_status()as$B=>$S){json_row("Comment-$B",h($S["Comment"]).($S["Error"]?" <span class='error'>".h($S["Error"])."</span>":""));if(!is_view($S)||preg_match('~materialized~i',$S["Engine"])){foreach(["Engine","Collation"]as$x)json_row("$x-$B",h($S[$x]));foreach(array_keys($om+["Auto_increment"=>0,"Rows"=>0])as$x){if(array_key_exists($x,$S))json_row("$x-$B",format_status($S,$x));if($S[$x]!=""&&isset($om[$x]))$om[$x]+=($S["Engine"]!="InnoDB"||$x!="Data_free"?$S[$x]:0);}}}if(function_exists('Adminer\db_status'))$om=db_status();foreach($om
as$x=>$X)json_row("sum-$x",adminer()->formatSizeValue($X));json_row("");}else{foreach(count_tables(adminer()->databases(false))as$k=>$X){json_row("tables-$k",format_number($X));json_row("size-$k",db_size($k));}json_row("");}exit;}else{if(!isset($_GET["select"])&&support("single_table")){$T=tables_list();if($T)redirect(ME.(support("table")?"table=":"select=").url_escape(key($T)));}$lh=ME.(isset($_GET["select"])?"select=&":"");$Jm=array_merge((array)$_POST["tables"],(array)$_POST["views"]);if($Jm&&!$m&&!$_POST["search"]){$H=true;$ph="";if(JUSH=="sql"&&$_POST["tables"]&&count($_POST["tables"])>1&&($_POST["drop"]||$_POST["truncate"]||$_POST["copy"]))queries("SET foreign_key_checks = 0");if($_POST["truncate"]){if($_POST["tables"])$H=truncate_tables($_POST["tables"]);$ph=lang(302);}elseif($_POST["move"]){$H=move_tables((array)$_POST["tables"],(array)$_POST["views"],$_POST["target"]);$ph=lang(303);}elseif($_POST["copy"]){$H=copy_tables((array)$_POST["tables"],(array)$_POST["views"],$_POST["target"]);$ph=lang(304);}elseif($_POST["drop"]){if($_POST["views"])$H=drop_views($_POST["views"]);if($H&&$_POST["tables"])$H=drop_tables($_POST["tables"]);$ph=lang(305);}elseif(JUSH=="sqlite"&&$_POST["check"]){foreach((array)$_POST["tables"]as$R){foreach(get_rows("PRAGMA integrity_check(".q($R).")")as$J)$ph
.="<b>".h($R)."</b>: ".h($J["integrity_check"])."<br>";}}elseif(JUSH=="mssql"&&$_POST["check"]){foreach((array)$_POST["tables"]as$R){foreach(get_rows("DBCC CHECKTABLE (".q(table($R)).") WITH TABLERESULTS")as$J)$ph
.="<b>".h($R)."</b>: ".h($J["MessageText"])."<br>";}}elseif(JUSH!="sql"){$H=(JUSH=="sqlite"?queries("VACUUM"):apply_queries("VACUUM".($_POST["optimize"]?" ANALYZE":""),(array)$_POST["tables"]));$ph=lang(306);}elseif(!$_POST["tables"])$ph=lang(19);elseif($H=queries(($_POST["optimize"]?"OPTIMIZE":($_POST["check"]?"CHECK":($_POST["repair"]?"REPAIR":"ANALYZE")))." TABLE ".implode(", ",array_map('Adminer\idf_escape',$_POST["tables"])))){while($J=$H->fetch_assoc())$ph
.="<b>".h($J["Table"])."</b>: ".h($J["Msg_text"])."<br>";}queries_redirect(relative_uri(),$ph,$H);}page_header(($_GET["ns"]==""?lang(17).": ".h(DB):lang(16).": ".h($_GET["ns"])),$m,true);if(adminer()->homepage()){if($_GET["ns"]!==""){if(support("scheme")&&support("comment")){$f=schema_comment($_GET["ns"]);if($f!="")echo"<p class='nowrap'>".lang(65).": ".adminer()->commentValue('SCHEMA',$f)."\n";}$Ni=$_GET["order"];$Ee=($Ni||support("fast_status"));echo"<div>\n","<h3 id='tables-views'>".lang(307)."</h3>\n";$Im=($Ee?table_status():tables_list());$ke=$_GET["filter"];if($ke!=""){foreach($Im
as$R=>$P){if(stripos("$R",$ke)===false)unset($Im[$R]);}}if(!$Im)echo"<p class='message'>".lang(19)."\n";else{echo"<form method='post'>\n";if(support("table")){echo"<fieldset><legend>".lang(15)." <span id='selected2'></span></legend><div>",html_select("op",adminer()->operators(),idx($_POST,"op",JUSH=="elastic"?"should":"LIKE %%"));$G=($_POST?$_POST["query"]:$_GET["query"]);echo" <input type='search' name='query' value='".h($G!=""?$G:$ke)."'".on('keydown','submitKeydown','search').">"," <input type='submit' name='search' value='".lang(18)."'>\n","</div></fieldset>\n";if(!$m&&(!$_POST||$_POST["search"])&&$G!=""){$_GET["where"][0]["op"]=idx($_POST,"op",JUSH=="elastic"?"should":"LIKE %%");search_tables($G);}}echo"<div class='scrollable'>\n","<table class='nowrap checkable odds'".on('click','tableClick').on('dblclick','tableClick').">\n",'<thead><tr>','<td class="hover"><input id="check-all" type="checkbox" class="jsonly" title="'.lang(178).'"'.on('click','formCheck','^(tables|views)\[').'>','<th class="sticky"'.(!$Ni&&JUSH!='sqlite'?" aria-sort='ascending'":'').'><a href="'.h(substr($lh,0,-1)).'">'.lang(159).'</a>';$e=["Engine"=>[lang(308).doc_link(['sql'=>'storage-engines.html'])]];$Ib=collations();if($Ib)$e["Collation"]=[lang(150).doc_link(['sql'=>'charset-charsets.html','mariadb'=>'supported-character-sets-and-collations/'])];if(function_exists('Adminer\alter_table'))$e["Data_length"]=[lang(309).doc_link(['sql'=>'show-table-status.html','pgsql'=>'functions-admin.html#FUNCTIONS-ADMIN-DBOBJECT','oracle'=>'refrn/ALL_TABLES.html']),"create",lang(59),];if(support("indexes"))$e["Index_length"]=[lang(310).doc_link(['sql'=>'show-table-status.html','pgsql'=>'functions-admin.html#FUNCTIONS-ADMIN-DBOBJECT']),"indexes",lang(162),];$e["Data_free"]=[lang(311).doc_link(['sql'=>'show-table-status.html']),"edit",lang(60)];if(function_exists('Adminer\alter_table'))$e["Auto_increment"]=[lang(66).doc_link(['sql'=>'example-auto-increment.html','mariadb'=>'auto_increment/']),"auto_increment=1&create",lang(59),];$e["Rows"]=[lang(312).doc_link(['sql'=>'show-table-status.html','pgsql'=>'catalog-pg-class.html#CATALOG-PG-CLASS','oracle'=>'refrn/ALL_TABLES.html']),"select",lang(56),];if(support("comment"))$e["Comment"]=[lang(65).doc_link(['sql'=>'show-table-status.html','pgsql'=>'functions-info.html#FUNCTIONS-INFO-COMMENT-TABLE','cockroach'=>'comment-on']),];$Ia=['Engine','Collation','Comment'];foreach($e
as$x=>$d)echo"<th".($Ni==$x?" aria-sort='".(in_array($x,$Ia)?"ascending":"descending")."'":"")."><a href='".h($lh)."order=$x'>$d[0]</a>";echo"<tbody>\n";if($Ni){uasort($Im,function($ia,$Ra)use($Ni,$Ia){$I=($ia[$Ni]<$Ra[$Ni]?-1:($ia[$Ni]>$Ra[$Ni]?1:0));return(in_array($Ni,$Ia)?$I:-$I);});}$T=0;$om=["Data_length"=>0,"Index_length"=>0,"Data_free"=>0];foreach($Im
as$B=>$P){$jo=($Ee?is_view($P):$P!==null&&!preg_match('~table|sequence~i',$P));$P=($Ee?$P:['Engine'=>$P]);$t=h("Table-".$B);echo'<tr><td class="hover">'.checkbox(($jo?"views[]":"tables[]"),$B,in_array("$B",$Jm,true),"","","",$t),'<th class="sticky">'.(support("table")||support("indexes")?"<a href='".h(ME)."table=".url_escape($B)."' title='".lang(57)."' id='$t'>".h($B).'</a>':h($B));if($jo&&!preg_match('~materialized~i',$P['Engine'])){$Xm=lang(158);echo'<td colspan="'.(count($e)-(support("comment")?2:1)).'">'.(support("view")?"<a href='".h(ME)."view=".url_escape($B)."' title='".lang(58)."'>$Xm</a>":$Xm),"<td align='right'><a href='".h(ME)."select=".url_escape($B)."' title='".lang(56)."'>?</a>";if(support("comment"))echo'<td>'.h($P['Comment']);}else{if($Ee){foreach(array_keys($om)as$x)$om[$x]+=($P["Engine"]!="InnoDB"||$x!="Data_free"?idx($P,$x):0);}foreach($e
as$x=>$d){$t=" id='$x-".h($B)."'";echo($d[1]?"<td align='right'>".($x=="Index_length"&&$P["invalid_index"]?"<span class='error'>INVALID</span> ":"")."<a href='".h(ME."$d[1]=").url_escape($B)."'$t title='$d[2]'>".format_status($P,$x)."</a>":"<td$t>".h(idx($P,$x,'?')).($x=="Comment"&&$P["Error"]?" <span class='error'>".h($P["Error"])."</span>":""));}$T++;}echo"\n";}echo"<tr><td class='hover'><th class='sticky'>".lang(287,count($Im)),"<td>".h(JUSH=="sql"?get_val("SELECT @@default_storage_engine"):""),($Ib?"<td>".h(db_collation(DB,$Ib)):'');if($Ee&&function_exists('Adminer\db_status'))$om=db_status();foreach($om
as$x=>$nm)echo($e[$x]?"<td align='right' id='sum-$x'>".($Ee?adminer()->formatSizeValue($nm):""):"");echo"\n","</table>\n",($Ee?'':script("ajaxSetHtml('".js_escape(ME)."script=db');")),"</div>\n";if(!information_schema(DB)){$bo="<input type='submit' value='".lang(313)."'".on_help("VACUUM")."> ";$Hi="<input type='submit' name='optimize' value='".lang(314)."'".on_help(JUSH=="sql"?"OPTIMIZE TABLE":"VACUUM ANALYZE")."> ";$Zj=(JUSH=="sqlite"?$bo."<input type='submit' name='check' value='".lang(315)."'".on_help("PRAGMA integrity_check")."> ":(JUSH=="pgsql"?$bo.$Hi:(JUSH=="mssql"?"<input type='submit' name='check' value='".lang(315)."'".on_help("DBCC CHECKTABLE")."> ":(JUSH=="sql"?"<input type='submit' value='".lang(316)."'".on_help("ANALYZE TABLE")."> ".$Hi."<input type='submit' name='check' value='".lang(315)."'".on_help("CHECK TABLE")."> "."<input type='submit' name='repair' value='".lang(317)."'".on_help("REPAIR TABLE")."> ":"")))).(function_exists('Adminer\truncate_tables')?"<input type='submit' name='truncate' value='".lang(318)."'".confirm().on_help(JUSH=="sqlite"?"DELETE":"TRUNCATE".(JUSH=="pgsql"?"":" TABLE"))."> ":"").(function_exists('Adminer\drop_tables')?"<input type='submit' name='drop' value='".lang(155)."'".confirm().on_help("DROP TABLE").">":"");echo($Zj?"<div class='footer'><div>\n<fieldset><legend>".lang(154)." <span id='selected'></span></legend><div>$Zj\n</div></fieldset>\n":"");$j=(support("scheme")?adminer()->schemas():adminer()->databases());if(count($j)!=1&&function_exists('Adminer\move_tables')){echo"<fieldset><legend>".lang(319)." <span id='selected3'></span></legend><div>";$k=(isset($_POST["target"])?$_POST["target"]:(support("scheme")?$_GET["ns"]:DB));echo($j?html_select("target",$j,$k):'<input name="target" value="'.h($k).'" autocapitalize="off">'),"</label> <input type='submit' name='move' value='".lang(138)."'>",(support("copy")?" <input type='submit' name='copy' value='".lang(29)."'> ".checkbox("overwrite",1,$_POST["overwrite"],lang(320)):""),"</div></fieldset>\n";}echo"<input type='hidden' name='all' value=''".on('click','countTables',$T).">\n",input_token(),"</div></div>\n";}echo"</form>\n",script("tableCheck();");}echo(function_exists('Adminer\alter_table')?"<p class='links hover'><a href='".h(ME)."create='>".lang(91)."</a>\n":''),(support("view")?"<a href='".h(ME)."view='>".lang(92)."</a>\n":""),"</div>\n";if(support("routine")){echo"<div>\n","<h3 id='routines'>".lang(86)."</h3>\n";$Wk=routines();if($Wk){echo"<table class='odds'>\n",'<thead><tr><th>'.lang(219).'<th>'.lang(64).'<th>'.lang(257)."<td class='hover'><tbody>\n";foreach($Wk
as$J){$B=($J["SPECIFIC_NAME"]==$J["ROUTINE_NAME"]?"":"&name=".url_escape($J["ROUTINE_NAME"]));echo'<tr>','<th><a href="'.h(ME.($J["ROUTINE_TYPE"]!="PROCEDURE"?'callf=':'call=').url_escape($J["SPECIFIC_NAME"]).$B).'"'.' title="'.($J["DTD_IDENTIFIER"]=="trigger"?lang(169):lang(229)).'">'.h($J["ROUTINE_NAME"]).'</a>','<td>'.h($J["ROUTINE_TYPE"]),'<td>'.h($J["DTD_IDENTIFIER"]),'<td class="hover"><a href="'.h(ME.($J["ROUTINE_TYPE"]!="PROCEDURE"?'function=':'procedure=').url_escape($J["SPECIFIC_NAME"]).$B).'">'.lang(165)."</a>";}echo"</table>\n";}echo'<p class="links hover">'.(support("procedure")?'<a href="'.h(ME).'procedure=">'.lang(256).'</a>':'').'<a href="'.h(ME).'function=">'.lang(255)."</a>\n","</div>\n";}if(support("sequence")){echo"<div>\n","<h3 id='sequences'>".lang(87)."</h3>\n";$vl=get_vals("SELECT relname FROM pg_class WHERE relkind = 'S' AND relnamespace = ".driver()->nsOid." ORDER BY relname");if($vl){echo"<table class='odds'>\n","<thead><tr><th>".lang(219)."<tbody>\n";foreach($vl
as$X)echo"<tr><th><a href='".h(ME)."sequence=".url_escape($X)."'>".h($X)."</a>\n";echo"</table>\n";}echo"<p class='links hover'><a href='".h(ME)."sequence='>".lang(262)."</a>\n","</div>\n";}if(support("type")){echo"<div>\n","<h3 id='user-types'>".lang(0)."</h3>\n";$Yn=types();if($Yn){echo"<table class='odds'>\n","<thead><tr><th>".lang(219)."<tbody>\n";foreach($Yn
as$X)echo"<tr><th><a href='".h(ME)."type=".url_escape($X)."'>".h($X)."</a>\n";echo"</table>\n";}echo"<p class='links hover'><a href='".h(ME)."type='>".lang(267)."</a>\n","</div>\n";}if(support("event")){echo"<div>\n","<h3 id='events'>".lang(88)."</h3>\n";$K=get_rows("SHOW EVENTS");if($K){echo"<table>\n","<thead><tr><th>".lang(219)."<th>".lang(321)."<th>".lang(247)."<th>".lang(248)."<td class='hover'><tbody>\n";foreach($K
as$J)echo"<tr>","<th>".h($J["Name"]),"<td>".($J["Execute at"]?lang(322)."<td>".h($J["Execute at"]):lang(249)." ".h($J["Interval value"])." ".h($J["Interval field"])."<td>".h($J["Starts"])),"<td>".h($J["Ends"]),'<td class="hover"><a href="'.h(ME).'event='.url_escape($J["Name"]).'">'.lang(165).'</a>';echo"</table>\n";$Id=get_val("SELECT @@event_scheduler");if($Id&&$Id!="ON")echo"<p class='error'><code class='jush-sqlset'>event_scheduler</code>: ".h($Id)."\n";}echo'<p class="links hover"><a href="'.h(ME).'event=">'.lang(246)."</a>\n","</div>\n";}}elseif(support("extension")){$Ud=get_rows("SELECT e.extname, e.extversion, n.nspname, obj_description(e.oid, 'pg_extension') AS comment
FROM pg_extension e
JOIN pg_namespace n ON n.oid = e.extnamespace
ORDER BY e.extname");if($Ud){echo"<div>\n","<h3 id='extensions'>".lang(93)."</h3>\n","<table class='odds'>\n","<thead><tr><th>".lang(219)."<th>".lang(323)."<th>".lang(16)."<th>".lang(65)."<tbody>\n";foreach($Ud
as$J)echo"<tr><th><code class='jush-pgsqlext'>".h($J["extname"])."</code>","<td>".h($J["extversion"]),"<td><a href='".h(substr(ME,0,-1).url_escape($J["nspname"]))."'>".h($J["nspname"])."</a>","<td>".h($J["comment"]),"\n";echo"</table>\n","</div>\n";}}}}page_footer();