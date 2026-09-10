<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
fwrite(STDERR,"Ange ett långt lösenord (minst 12 tecken). Texten syns i terminalen:\n");
$password=rtrim(fgets(STDIN),"\r\n");
if(strlen($password)<12){fwrite(STDERR,"För kort lösenord.\n");exit(1);}
echo "Klistra in denna hash som password_hash i config.php:\n".password_hash($password,PASSWORD_DEFAULT)."\n";
