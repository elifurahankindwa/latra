<?php
/** Enforce the direct dependency direction without bootstrapping a runtime or vendor client. */
if (PHP_SAPI !== 'cli') exit(1);
$root=realpath(__DIR__.'/../src');
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$checked=0;
foreach ($files as $file) {
    $relative=substr($file->getPathname(),strlen($root)+1);
    if (!$file->isFile() || $file->getExtension()!=='php'
        || !preg_match('#^(Domain|Application)/#',$relative,$layer)) continue;
    $code='';
    foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
        if (is_array($token) && in_array($token[0],[T_COMMENT,T_DOC_COMMENT,T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE],true)) continue;
        $code.=is_array($token) ? $token[1] : $token;
    }
    $forbidden = str_contains($code, 'Latra\\Infrastructure\\')
        || ($layer[1]==='Domain' && str_contains($code,'Latra\\Application\\'))
        || preg_match('/\b(?:Database|PDO|WialonAPI|LatraAdapter)::/', $code);
    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE,T_INCLUDE,T_INCLUDE_ONCE],true)) $forbidden=true;
    }
    preg_match_all('/\buse\s+([^;]+);/', $code, $imports);
    foreach ($imports[1] as $import) {
        if (in_array(ltrim(trim($import), chr(92)), ['Database','PDO','WialonAPI','LatraAdapter','Auth','Automation','AITools'],true)) $forbidden=true;
    }
    if ($forbidden) throw new RuntimeException('Forbidden runtime dependency in '.$relative);
    $checked++;
}
if (!$checked) throw new RuntimeException('No application/domain files checked');
require __DIR__.'/../includes/autoload.php';
if (!class_exists(Latra\Domain\Ingestion\NewestSourceMessagePolicy::class)
    || !class_exists(Database::class)) throw new RuntimeException('Namespaced or legacy autoload failed');
echo "PASS $checked domain/application files preserve dependency direction and runtime autoload\n";
