<?php
/** Unknown cutover modes must fail closed; never interpret a typo as legacy ingestion. */
if (PHP_SAPI !== 'cli') exit(1);
$root=sys_get_temp_dir().'/latra-relay-config-'.bin2hex(random_bytes(6));mkdir($root,0700);
mkdir($root.'/includes',0700);
copy(__DIR__.'/../config.php',$root.'/config.php');
copy(__DIR__.'/../includes/autoload.php',$root.'/includes/autoload.php');
try {
    foreach (['relay','legacy','unknown'] as $mode) {
        file_put_contents($root.'/.env',"DELIVERY_ARCHITECTURE=$mode\n");
        $pipes=[];$p=proc_open([PHP_BINARY,'-r','$_SERVER["SCRIPT_NAME"]="config-test.php"; require "config.php"; echo DELIVERY_ARCHITECTURE;'],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        if(!is_resource($p))throw new RuntimeException('Cannot execute config fixture');
        $out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        if($mode==='unknown' ? $exit===0 : ($exit!==0||$out!==$mode))throw new RuntimeException('Cutover configuration did not fail closed');
    }
    echo "PASS explicit relay/legacy modes and fail-closed unknown architecture configuration\n";
} finally {
    foreach(glob($root.'/logs/*')?:[] as $p)unlink($p);
    if(is_dir($root.'/logs'))rmdir($root.'/logs');
    unlink($root.'/includes/autoload.php');
    if(is_dir($root.'/includes'))rmdir($root.'/includes');
    unlink($root.'/.env');unlink($root.'/config.php');rmdir($root);
}
