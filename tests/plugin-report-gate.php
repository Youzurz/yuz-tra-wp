<?php
// CLI fixtures deliberately exercise failure cases; no WordPress or provider calls.
if (PHP_SAPI !== 'cli') exit(1);
$header="file,line,column,type,code,message\n";
$cases=[
    'valid empty report'=>[$header,0],
    'warnings remain visible'=>[$header."a.php,1,1,WARNING,style,review\n",0],
    'errors block'=>[$header."a.php,1,1,ERROR,security,fix\n",1],
    'empty response blocks'=>['',1],
    'HTML response blocks'=>['<!doctype html>',1],
    'unknown severity blocks'=>[$header."a.php,1,1,SUCCESS,unknown,invalid\n",1],
    'truncated row blocks'=>[$header."a.php,1\n",1],
];
foreach ($cases as $name=>[$csv,$expected]) {
    $file=tempnam(sys_get_temp_dir(),'yuz-pcp-');
    if ($file===false) exit(1);
    try {
        file_put_contents($file,$csv);
        $output=[];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../tools/check-plugin-report.php').' '.escapeshellarg($file).' 2>&1',$output,$status);
        if ($status!==$expected) {fwrite(STDERR,"FAIL $name\n");exit(1);}
        echo "PASS $name\n";
    } finally {unlink($file);}
}
