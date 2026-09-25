<?php
// CLI fixtures deliberately exercise failure cases; no WordPress or provider calls.
if (PHP_SAPI !== 'cli') exit(1);
$header="file,line,column,type,code,message\n";
$cases=[
    'valid empty report'=>[$header,0],
    'unknown warnings require assessment'=>[$header."a.php,1,1,WARNING,style,review\n",1],
    'direct query advisory permits release'=>[$header."a.php,1,1,WARNING,WordPress.DB.DirectDatabaseQuery.DirectQuery,review\n",0],
    'cache advisory permits release'=>[$header."a.php,1,1,WARNING,WordPress.DB.DirectDatabaseQuery.NoCaching,review\n",0],
    'slow query advisory permits release'=>[$header."a.php,1,1,WARNING,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,review\n",0],
    'recommended nonce advisory permits release'=>[$header."a.php,1,1,WARNING,WordPress.Security.NonceVerification.Recommended,review\n",0],
    'advisory does not hide SQL risk'=>[$header."a.php,1,1,WARNING,WordPress.DB.DirectDatabaseQuery.DirectQuery,review\na.php,1,1,WARNING,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,review\n",1],
    'security warning blocks despite zero errors'=>[$header."a.php,1,1,WARNING,WordPress.Security.NonceVerification.Missing,review\n",1],
    'SQL warning blocks despite zero errors'=>[$header."a.php,1,1,WARNING,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,review\n",1],
    'prefix warning requires triage'=>[$header."a.php,1,1,WARNING,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,review\n",1],
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
$csv=$header."a.php,1,1,WARNING,WordPress.Security.NonceVerification.Missing,review\n";
$row=['a.php','1','1','WARNING','WordPress.Security.NonceVerification.Missing','review'];
$decision=['fingerprint'=>hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES)),
    'owner'=>'fixture-owner','approved_by'=>'fixture-reviewer','approval_evidence'=>'fixture-only-not-an-authorization',
    'rationale'=>'Fixture only','evidence'=>'fixture-test','issue'=>'fixture-ticket',
    'reviewed_at'=>gmdate('c',time()-60),'expires_at'=>gmdate('c',time()+86400),
    'severity'=>'MEDIUM','disposition'=>'ACCEPTED_RISK'];
$cases=[
    'medium accepted with trace permits'=>[[],0],
    'low accepted permits'=>[['severity'=>'LOW'],0],
    'confirmed medium without acceptance blocks'=>[['disposition'=>'CONFIRMED'],1],
    'high cannot be accepted away'=>[['severity'=>'HIGH'],1],
    'critical cannot be accepted away'=>[['severity'=>'CRITICAL'],1],
    'documented false positive permits'=>[['severity'=>'NONE','disposition'=>'FALSE_POSITIVE'],0],
    'false positive inconsistent severity blocks'=>[['disposition'=>'FALSE_POSITIVE'],1],
    'missing approval evidence blocks'=>[['approval_evidence'=>''],1],
    'expired acceptance blocks'=>[['expires_at'=>gmdate('c',time()-1)],1],
    'unbounded acceptance blocks'=>[['expires_at'=>gmdate('c',time()+40*86400)],1],
    'relative expiry cannot renew itself'=>[['expires_at'=>'tomorrow'],1],
    'different finding blocks'=>[['fingerprint'=>str_repeat('a',64)],1],
];
foreach ($cases as $name=>[$changes,$expected]) {
    $file=tempnam(sys_get_temp_dir(),'yuz-pcp-');$triage=tempnam(sys_get_temp_dir(),'yuz-triage-');
    try {
        file_put_contents($file,$csv);
        file_put_contents($triage,json_encode(['report_sha256'=>hash('sha256',$csv),'findings'=>[array_replace($decision,$changes)]]));
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../tools/check-plugin-report.php').' '.escapeshellarg($file).' '.escapeshellarg($triage).' 2>&1',$output,$status);
        if ($status!==$expected) {fwrite(STDERR,"FAIL $name\n");exit(1);}
        echo "PASS $name\n";
    } finally {unlink($file);unlink($triage);}
}
$file=tempnam(sys_get_temp_dir(),'yuz-pcp-');$triage=tempnam(sys_get_temp_dir(),'yuz-triage-');
try {
    file_put_contents($file,$csv);
    file_put_contents($triage,json_encode(['report_sha256'=>str_repeat('0',64),'findings'=>[$decision]]));
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../tools/check-plugin-report.php').' '.escapeshellarg($file).' '.escapeshellarg($triage).' 2>&1',$output,$status);
    if ($status!==1) {fwrite(STDERR,"FAIL mismatched report\n");exit(1);}
    echo "PASS mismatched report rejects stale approval\n";
} finally {unlink($file);unlink($triage);}
