<?php
// Declaration audit only: not a complete hook/storage-key collision analysis.
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);$declarations=[];$failures=[];
foreach (file($root.'/release-files.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $file) {
    if (substr($file,-4)!=='.php') continue;
    $tokens=token_get_all(file_get_contents($root.'/'.$file));
    $namespace='';$depth=0;$classDepth=[];$pendingClass=false;
    foreach ($tokens as $i=>$token) {
        if ($token==='{') { $depth++;if ($pendingClass) { $classDepth[]=$depth;$pendingClass=false; } continue; }
        if ($token==='}') { if (end($classDepth)===$depth) array_pop($classDepth);$depth--;continue; }
        if (!is_array($token)) continue;
        if (in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)) { $depth++;continue; }
        if ($token[0]===T_NAMESPACE) {
            $namespace='';
            for ($j=$i+1;$j<count($tokens) && $tokens[$j]!==';' && $tokens[$j]!=='{';$j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0]!==T_WHITESPACE) $namespace.=$tokens[$j][1];
            }
        }
        if (!in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_FUNCTION],true)) continue;
        // ::class is not a declaration.
        if ($token[0]===T_CLASS) {
            $j=$i-1;while ($j>=0 && is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) $j--;
            if ($j>=0 && is_array($tokens[$j]) && $tokens[$j][0]===T_DOUBLE_COLON) continue;
        }
        $name=null;
        for ($j=$i+1;$j<count($tokens);$j++) {
            $next=$tokens[$j];
            if (is_array($next) && in_array($next[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) continue;
            if (is_array($next) && $next[0]===T_STRING) $name=$next[1];
            break;
        }
        if ($token[0]!==T_FUNCTION) $pendingClass=true;
        if (!$name || ($token[0]===T_FUNCTION && $classDepth)) continue;
        $qualified=$namespace ? $namespace.'\\'.$name : $name;
        $ok=str_starts_with($qualified,'YUZTRA\\') || preg_match('/^(?:YUZ_|YUZTRA_|yuz_|clar_)/',$qualified);
        $row=['file'=>$file,'line'=>$token[2],'name'=>$qualified,'prefixed'=>(bool)$ok];
        $declarations[]=$row;if (!$ok) $failures[]=$row;
    }
}
echo json_encode(['scope'=>'PHP named global declarations, excluding comments and class methods; legacy YUZ_/yuz_ are four-character prefixes, not proof of no cross-plugin collision',
    'count'=>count($declarations),'unprefixed'=>$failures,'declarations'=>$declarations],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($failures?1:0);
