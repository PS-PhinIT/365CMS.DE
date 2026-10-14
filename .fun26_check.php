<?php
// usage: php check.php <dir> [ref-file-for-old-methods]
$dir = $argv[1];
$files = glob($dir.'/*.php');
$defs = []; $calls = [];
foreach ($files as $f) {
  $src = file_get_contents($f);
  // split by class/trait
  $toks = token_get_all($src);
  $cur = null; $depth = 0; $curDepth = -1; $uses=[];
  $n = count($toks);
  for ($i=0;$i<$n;$i++) {
    $t = $toks[$i];
    if (is_array($t) && in_array($t[0],[T_CLASS,T_TRAIT],true)) {
      // next string
      for ($j=$i+1;$j<$n;$j++) if (is_array($toks[$j]) && $toks[$j][0]===T_STRING) { $cur=$toks[$j][1]; break; }
      $curDepth = $depth; $defs[$cur] = $defs[$cur] ?? ['methods'=>[],'uses'=>[],'file'=>basename($f)];
      $i=$j; continue;
    }
    if ($t==='{' || (is_array($t) && in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES]))) $depth++;
    if ($t==='}') { $depth--; if ($cur && $depth===$curDepth) $cur=null; }
    if (!$cur) continue;
    if (is_array($t) && $t[0]===T_USE && $depth===$curDepth+1) {
      for ($j=$i+1;$toks[$j]!==';';$j++) if (is_array($toks[$j]) && in_array($toks[$j][0],[T_STRING,T_NAME_QUALIFIED])) $defs[$cur]['uses'][]=$toks[$j][1];
    }
    if (is_array($t) && $t[0]===T_FUNCTION) {
      for ($j=$i+1;$j<$n;$j++) if (is_array($toks[$j]) && $toks[$j][0]===T_STRING) { $defs[$cur]['methods'][strtolower($toks[$j][1])]=1; break; } elseif ($toks[$j]==='(') break;
    }
    if (is_array($t) && ($t[0]===T_VARIABLE && $t[1]==='$this' || $t[0]===T_STRING && in_array(strtolower($t[1]),['self','static']))) {
      $k=$i+1; while(is_array($toks[$k])&&$toks[$k][0]===T_WHITESPACE)$k++;
      if (is_array($toks[$k]) && in_array($toks[$k][0],[T_OBJECT_OPERATOR,T_DOUBLE_COLON,T_NULLSAFE_OBJECT_OPERATOR])) {
        $k++; while(is_array($toks[$k])&&$toks[$k][0]===T_WHITESPACE)$k++;
        if (is_array($toks[$k]) && $toks[$k][0]===T_STRING) { $m=$toks[$k][1]; $k2=$k+1; while(is_array($toks[$k2])&&$toks[$k2][0]===T_WHITESPACE)$k2++;
          if ($toks[$k2]==='(') $calls[$cur][strtolower($m)][] = basename($f).':'.$t[2]; }
      }
    }
  }
}
function allm($c,$defs,&$seen=[]) { if(isset($seen[$c])||!isset($defs[$c])) return []; $seen[$c]=1; $m=$defs[$c]['methods']; foreach($defs[$c]['uses'] as $u) $m+=allm($u,$defs,$seen); return $m; }
// map traits -> classes using them
$bad=0;
foreach ($calls as $owner=>$ms) {
  $contexts = [$owner];
  if (str_contains($defs[$owner]['file'],'trait')) { $contexts=[]; foreach($defs as $c=>$d) if(in_array($owner,$d['uses'])) $contexts[]=$c; }
  foreach ($ms as $m=>$locs) foreach ($contexts as $ctx) { $all=allm($ctx,$defs); if(!isset($all[$m])) { $bad++; echo "MISSING $ctx::$m called in ".implode(',',array_slice($locs,0,3))."\n"; } }
}
foreach ($defs as $c=>$d) echo "$c uses=[".implode(',',$d['uses'])."] methods=".count($d['methods'])."\n";
// duplicates
foreach ($defs as $c=>$d) foreach($d['uses'] as $u) if(isset($defs[$u])) { $dup=array_intersect_key($d['methods'],$defs[$u]['methods']); if($dup) echo "DUP $c vs $u: ".count($dup)." ".implode(',',array_keys($dup))."\n"; }
echo "missing count: $bad\n";
