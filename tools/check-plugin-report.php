<?php
// CLI-only strict Plugin Check CSV gate. Preserve warnings; reject errors and malformed output.
if (PHP_SAPI !== 'cli') exit(1);
$file=$argv[1] ?? '';
$stream=fopen($file,'r');
if (!$stream) exit(1);
$header=fgetcsv($stream);
if ($header !== ['file','line','column','type','code','message']) { fwrite(STDERR,"Invalid Plugin Check report header\n"); exit(1); }
$counts=['ERROR'=>0,'WARNING'=>0];
while (($row=fgetcsv($stream))!==false) {
    if (count($row)!==count($header) || !array_key_exists($row[3],$counts)) { fwrite(STDERR,"Invalid Plugin Check row\n"); exit(1); }
    $counts[$row[3]]++;
}
echo json_encode(['errors'=>$counts['ERROR'],'warnings'=>$counts['WARNING']],JSON_PRETTY_PRINT)."\n";
exit($counts['ERROR'] ? 1 : 0);
