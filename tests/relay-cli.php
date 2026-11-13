<?php
/** CLI enrollment/status never loads runtime credentials, connects upstream or submits payloads. */
if (PHP_SAPI !== 'cli') exit(1);
$dsn=getenv('LATRA_RELAY_TEST_DSN') ?: '';
if (getenv('LATRA_RELAY_TEST_ALLOW_RESET') !== '1' || !preg_match('/^mysql:.*;dbname=latra_test_relay_app_[a-z0-9_]+(?:;|$)/', $dsn)) {
    throw new RuntimeException('Dedicated SQL fixture required');
}
$root=sys_get_temp_dir().'/latra-relay-cli-'.bin2hex(random_bytes(6)); mkdir($root,0700);mkdir($root.'/bin',0700);
copy(__DIR__.'/../bin/relay.php',$root.'/bin/relay.php');
$autoload=var_export(realpath(__DIR__.'/../includes/autoload.php'),true);
$config='<?php define("DELIVERY_ARCHITECTURE","relay"); const DATE_FORMAT="Y-m-d H:i:s";'
    . 'require_once '.$autoload.';'
    . '$pdo=new PDO(getenv("LATRA_RELAY_TEST_DSN"),getenv("LATRA_RELAY_TEST_USER")?:"root",getenv("LATRA_RELAY_TEST_PASSWORD")?:"",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'
    . '(new ReflectionProperty(Database::class,"instance"))->setValue(null,$pdo);'
    . '(new ReflectionProperty(Database::class,"sensitiveConfigScrubbed"))->setValue(null,true);';
file_put_contents($root.'/config.php',$config);
function cli(string $root,array $args):array {
    $pipes=[];$p=proc_open(array_merge([PHP_BINARY,$root.'/bin/relay.php'],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($p))throw new RuntimeException('CLI did not start');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),$out,$err];
}
function expect(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
try {
    [$code,$help]=cli($root,['--help']);
    expect($code===0 && str_contains($help,'--enroll') && str_contains($help,'--recover-review')
        && !str_contains($help,'--mock-url'),'Collection CLI still mixes delivery or lacks explicit actions');
    [$code,$output]=cli($root,['--vehicle=2','--enroll','--profile=generic','--max-records=100','--max-bytes=1048576','--max-pending-records=100','--max-pending-bytes=1048576']);
    $enrolled=json_decode($output,true);
    expect($code===0 && ($enrolled['enrolled']??false)===true,'Explicit enrollment failed');
    [$code,$output]=cli($root,['--vehicle=2','--status']);
    $status=json_decode($output,true);
    expect($code===0 && ($status['profile']??null)==='generic' && ($status['queue']['unresolved']??null)===0,'Status required collection arguments or lost enrollment');
    [$code]=cli($root,['--vehicle=2','--enroll','--profile=unknown','--max-records=100','--max-bytes=1048576','--max-pending-records=100','--max-pending-bytes=1048576']);
    expect($code!==0,'Unsupported mapping profile accepted');
    [$code]=cli($root,['--vehicle=2','--enroll','--profile=generic','--max-records=0','--max-bytes=1048576','--max-pending-records=100','--max-pending-bytes=1048576']);
    expect($code!==0,'Zero budget accepted');
    [$code]=cli($root,['--vehicle=2','--from=1']);
    expect($code!==0,'Partial explicit window accepted');
    [$code]=cli($root,['--vehicle=2','--status','--recover-review']);
    expect($code!==0,'Conflicting relay actions accepted');
    echo "PASS actual collection CLI enrollment/status, explicit budgets and unsupported-profile/window rejection\n";
} finally {unlink($root.'/bin/relay.php');rmdir($root.'/bin');unlink($root.'/config.php');rmdir($root);}
