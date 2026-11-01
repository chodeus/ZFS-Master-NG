<?php
# Self-test for check-plugin-functions.php: each case is one fixture file and the exit code the checker must return.
$checker = $argv[1] ?? __DIR__ . "/check-plugin-functions.php";
$cases = array(
    "defined and called resolves"        => array(0, '<?php function zfsmA() {} zfsmA();'),
    "missing global is reported"         => array(1, '<?php zfsmMissing();'),
    "class method is not a global"       => array(1, '<?php class K { function zfsmF() {} } zfsmF();'),
    "Foo::class is not a declaration"    => array(0, '<?php class M {} $n = M::class; function zfsmH() {} zfsmH();'),
    "by-reference declaration counts"    => array(0, '<?php function &zfsmG() { static $x; return $x; } zfsmG();'),
    "anonymous class args with a closure" => array(1, '<?php $o = new class((function () {})()) { public function zfsmE() {} }; zfsmE();'),
    "method call is not a global call"   => array(0, '<?php class P { function zfsmQ() {} } (new P)->zfsmQ(); P::zfsmQ();'),
);
$failed = 0;
foreach ($cases as $name => $case) {
    list($want, $src) = $case;
    $dir = sys_get_temp_dir() . "/acng-checker-" . getmypid() . "-" . md5($name);
    @mkdir($dir); file_put_contents($dir . "/fixture.php", $src);
    $out = array(); $rc = 1;
    exec("php " . escapeshellarg($checker) . " " . escapeshellarg($dir) . " 2>&1", $out, $rc);
    unlink($dir . "/fixture.php"); rmdir($dir);
    $ok = ($rc === $want);
    printf("%s  %s (exit %d, want %d)\n", $ok ? "PASS" : "FAIL", $name, $rc, $want);
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
