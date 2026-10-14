<?php
$names = explode(' ', 'build_code_block build_figure_block build_header_block build_image_block build_list_block build_paragraph_like_block build_quote_block build_table_block consume_gallery_sequence convert_dom_nodes_to_editorjs_blocks convert_html_to_editorjs_blocks extract_slug_from_url is_editorjs_active is_editorjs_payload is_language_path_segment normalize_content_for_active_editor normalize_content_reference_from_url normalize_content_reference_path normalize_list_item prepare_content_payload preserve_source_slug resolve_author_id resolve_content_source_reference resolve_import_author_display_name resolve_import_author_id resolve_import_author_preview resolve_import_slug resolve_original_created_at resolve_original_updated_at safe_date sanitize_slug sanitize_title slugify unique_slug');
$lines = file('old.php');
$src = implode('', $lines);
$toks = token_get_all($src);
$out = []; $n=count($toks);
for ($i=0;$i<$n;$i++) {
  if (!is_array($toks[$i]) || $toks[$i][0]!==T_FUNCTION) continue;
  $j=$i+1; while(is_array($toks[$j]) && $toks[$j][0]===T_WHITESPACE) $j++;
  if (!is_array($toks[$j]) || !in_array($toks[$j][1],$names,true)) continue;
  $name=$toks[$j][1];
  // start: walk back over modifiers/docblock
  $k=$i-1; $startLine=$toks[$i][2];
  while ($k>=0 && is_array($toks[$k]) && in_array($toks[$k][0],[T_WHITESPACE,T_PRIVATE,T_PUBLIC,T_PROTECTED,T_STATIC,T_DOC_COMMENT,T_FINAL])) { if($toks[$k][0]!==T_WHITESPACE) $startLine=$toks[$k][2]; $k--; }
  // end: matching brace
  $d=0; for($m=$j;$m<$n;$m++){ $t=$toks[$m]; if($t==='{'||(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES]))) $d++; if($t==='}'){ $d--; if($d===0) break; } }
  // end line
  $endLine=null; for($q=$m;$q>=0;$q--) if(is_array($toks[$q])) { $endLine=$toks[$q][2]+substr_count($toks[$q][1],"\n"); break; }
  $out[$name]=[$startLine,$endLine];
}
ksort($out); foreach($out as $k=>$v) echo "$k {$v[0]}-{$v[1]}\n";
echo count($out),"\n";
