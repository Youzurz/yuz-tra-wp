<?php
// Mandatory control, risk-based decision. WARNING is not a vulnerability severity.
// Optional third argument: reviewed dispositions bound to this exact report hash.
if (PHP_SAPI !== 'cli') exit(1);
$file=$argv[1] ?? '';
$stream=fopen($file,'r');
if (!$stream) exit(1);
$header=fgetcsv($stream);
if ($header !== ['file','line','column','type','code','message']) { fwrite(STDERR,"Invalid Plugin Check report header\n"); exit(1); }
$reportHash=hash_file('sha256',$file);
$reviews=[];
if (isset($argv[2])) {
    $review=json_decode(file_get_contents($argv[2]),true);
    if (!is_array($review) || ($review['report_sha256']??'')!==$reportHash || !is_array($review['findings']??null)) {
        fwrite(STDERR,"Invalid or mismatched triage manifest\n"); exit(1);
    }
    foreach ($review['findings'] as $item) {
        $fp=$item['fingerprint']??'';
        if (!preg_match('/^[a-f0-9]{64}$/',$fp) || isset($reviews[$fp])) {
            fwrite(STDERR,"Invalid or duplicate triage fingerprint\n"); exit(1);
        }
        $reviews[$fp]=$item;
    }
}
// These diagnostics alone assert direct DB use / absent cache, NOT unsafe SQL.
// PreparedSQL/Security/InputValidation findings remain separate and blocking for triage.
$advisoryCodes=[
    'WordPress.DB.DirectDatabaseQuery.DirectQuery',
    'WordPress.DB.DirectDatabaseQuery.NoCaching',
    // Performance heuristic only: it does not establish a security or functional defect.
    'WordPress.DB.SlowDBQuery.slow_db_query_meta_query',
    // Advisory reads in request/render paths; Missing remains blocking.
    'WordPress.Security.NonceVerification.Recommended',
];
$counts=['ERROR'=>0,'WARNING'=>0];$categories=[];$findings=[];$blocking=0;$advisories=0;
while (($row=fgetcsv($stream))!==false) {
    if (count($row)!==count($header) || !array_key_exists($row[3],$counts)) { fwrite(STDERR,"Invalid Plugin Check row\n"); exit(1); }
    $counts[$row[3]]++;
    $categories[$row[4]]=($categories[$row[4]]??0)+1;
    $fp=hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES));
    $advisory=$row[3]==='WARNING' && in_array($row[4],$advisoryCodes,true);
    $disposition=$advisory?'TRACKED_ADVISORY':'REVIEW_REQUIRED';
    $severity=$advisory?'LOW':'UNASSESSED';
    $blocks=!$advisory;
    $decision=null;
    if (isset($reviews[$fp])) {
        $decision=$reviews[$fp]; unset($reviews[$fp]);
        foreach (['owner','approved_by','approval_evidence','rationale','evidence','issue','reviewed_at','expires_at','severity','disposition'] as $field) {
            if (!is_string($decision[$field]??null) || trim($decision[$field])==='') {
                fwrite(STDERR,"Incomplete triage decision\n"); exit(1);
            }
        }
        foreach (['reviewed_at','expires_at'] as $field) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',$decision[$field])) {
                fwrite(STDERR,"Triage dates must be absolute ISO-8601\n");exit(1);
            }
        }
        $reviewed=strtotime($decision['reviewed_at']); $expires=strtotime($decision['expires_at']);
        if (!$reviewed || !$expires || $reviewed>time() || $expires<=time() || $expires<=$reviewed || $expires-$reviewed>30*86400 ||
            !in_array($decision['severity'],['NONE','LOW','MEDIUM','HIGH','CRITICAL'],true) ||
            !in_array($decision['disposition'],['FALSE_POSITIVE','ACCEPTED_RISK','CONFIRMED'],true)) {
            fwrite(STDERR,"Invalid or expired triage decision\n"); exit(1);
        }
        $severity=$decision['severity']; $disposition=$decision['disposition'];
        if ($disposition==='FALSE_POSITIVE' && $severity!=='NONE') {
            fwrite(STDERR,"False positive must have severity NONE\n"); exit(1);
        }
        if ($disposition!=='FALSE_POSITIVE' && $severity==='NONE') {
            fwrite(STDERR,"Real finding must have a severity\n"); exit(1);
        }
        // Platform ERROR cannot be accepted away; a documented false positive can.
        $blocks=!($disposition==='FALSE_POSITIVE' ||
            ($row[3]==='WARNING' && $disposition==='ACCEPTED_RISK' && in_array($severity,['LOW','MEDIUM'],true)));
    }
    if ($blocks) $blocking++; else $advisories++;
    $findings[]=['file'=>$row[0],'line'=>(int)$row[1],'column'=>(int)$row[2],
        'type'=>$row[3],'code'=>$row[4],
        'fingerprint'=>$fp,'severity'=>$severity,'status'=>$disposition,'blocking'=>$blocks,'review'=>$decision];
}
if ($reviews) {fwrite(STDERR,"Triage contains findings absent from report\n");exit(1);}
arsort($categories);
echo json_encode(['policy'=>'risk-based-plugin-check-v2','report_sha256'=>$reportHash,'measured_at'=>gmdate('c'),
    'errors'=>$counts['ERROR'],'warnings'=>$counts['WARNING'],'nonblocking_findings'=>$advisories,
    'blocking_findings'=>$blocking,'verdict'=>$blocking?'FAIL':($advisories?'PASS_WITH_FINDINGS':'PASS'),
    'categories'=>$categories,'findings'=>$findings],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($blocking ? 1 : 0);
