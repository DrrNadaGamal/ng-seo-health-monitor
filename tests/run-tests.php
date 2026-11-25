<?php
ini_set('display_errors',1); error_reporting(E_ALL);
register_shutdown_function(function(){ $e=error_get_last(); if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) echo "SHUTDOWN ".$e['message']." @".$e['file'].":".$e['line']."\n"; });
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/template.php'; require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php'; require_once ABSPATH.'wp-admin/includes/taxonomy.php';
header('Content-Type: text/plain'); wp_set_current_user(1);
activate_plugin('ng-seo-health-monitor/ng-seo-health-monitor.php'); NGSHM::install();
global $wpdb; $wpdb->query('TRUNCATE '.NGSHM::t404()); $wpdb->query('TRUNCATE '.NGSHM::tred());
$pass=0;$fail=0; $t=function($n,$c) use(&$pass,&$fail){ if($c){$pass++;echo "PASS $n\n";}else{$fail++;echo "FAIL $n\n";} };
$t('tables exist',$wpdb->get_var("SHOW TABLES LIKE '".NGSHM::t404()."'")&&$wpdb->get_var("SHOW TABLES LIKE '".NGSHM::tred()."'"));
$cols=$wpdb->get_col('DESCRIBE '.NGSHM::t404()); $t('404 table has no ip / user agent columns',!array_intersect($cols,array('ip','ip_address','user_agent','ua')));
$t('cron purge scheduled',(bool)wp_next_scheduled('ngshm_purge'));
// norm
$t('norm strips query + trailing slash + lowercases',NGSHM::norm('/Old-Page/?utm=1')==='/old-page');
$t('norm collapses double slashes',NGSHM::norm('//a//b/')==='/a/b');
$t('norm decodes arabic path',NGSHM::norm('/%D9%83%D9%84%D9%89/')==='/كلى');
$t('norm length capped 255',mb_strlen(NGSHM::norm('/'.str_repeat('a',900)))===255);
// logging
NGSHM::log('/missing-one'); NGSHM::log('/missing-one'); NGSHM::log('/missing-one'); NGSHM::log('/other');
$r=$wpdb->get_row("SELECT * FROM ".NGSHM::t404()." WHERE path='/missing-one'"); $t('hit counter aggregates (3 hits, 1 row)',$r&&(int)$r->hits===3&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.NGSHM::t404())===2);
$t('first_seen/last_seen set',$r->first_seen&&$r->last_seen);
// referrer privacy
$_SERVER['REMOTE_ADDR']='203.0.113.9'; $_SERVER['HTTP_USER_AGENT']='SecretUA/1.0';
NGSHM::log('/ref-test','https://evil.example/page?x=1'); $r=$wpdb->get_row("SELECT * FROM ".NGSHM::t404()." WHERE path='/ref-test'"); $t('referrer host off by default (not stored)',$r->referrer_host==='');
update_option(NGSHM::OPT,array_merge(NGSHM::defaults(),array('referrer'=>1))); NGSHM::log('/ref-test2','https://evil.example/page?x=1'); $r=$wpdb->get_row("SELECT * FROM ".NGSHM::t404()." WHERE path='/ref-test2'"); $t('referrer: host only, no path/query',$r->referrer_host==='evil.example');
$dump=json_encode($wpdb->get_results('SELECT * FROM '.NGSHM::t404(),ARRAY_A)); $t('IP and user agent appear nowhere in stored rows',strpos($dump,'203.0.113.9')===false&&strpos($dump,'SecretUA')===false);
NGSHM::log('/same-site','http://'.wp_parse_url(home_url(),PHP_URL_HOST).'/x'); $r=$wpdb->get_row("SELECT * FROM ".NGSHM::t404()." WHERE path='/same-site'"); $t('own-site referrer not stored',$r->referrer_host==='');
update_option(NGSHM::OPT,NGSHM::defaults());
// ignore ext
$t('asset extensions ignored',NGSHM::ignored('/wp-content/x.png')&&NGSHM::ignored('/a.css')&&!NGSHM::ignored('/page')&&!NGSHM::ignored('/page.html'));
// suggestions
$p1=wp_insert_post(array('post_title'=>'Kidney stones prevention guide','post_name'=>'kidney-stones-prevention-guide','post_status'=>'publish','post_content'=>'x'));
$p2=wp_insert_post(array('post_title'=>'Dialysis diet','post_name'=>'dialysis-diet','post_status'=>'publish','post_content'=>'x'));
$p3=wp_insert_post(array('post_title'=>'Draft only','post_name'=>'kidney-draft-secret','post_status'=>'draft','post_content'=>'x'));
$s=NGSHM::suggest('/blog/kidney-stone-prevention-guide'); $t('typo slug suggests closest post',$s&&$s['post_id']===$p1);
$s=NGSHM::suggest('/old/dialysis-diet.html'); $t('extension stripped when matching',$s&&$s['post_id']===$p2);
$t('unrelated slug gets no suggestion',NGSHM::suggest('/zzzz-qqqq-wwww')===null);
$s=NGSHM::suggest('/kidney-draft-secret'); $t('drafts never suggested',!$s||$s['post_id']!==$p3);
$t('too-short slug gets no suggestion',NGSHM::suggest('/ab')===null);
$t('score identical = 100',NGSHM::score('abc-def','abc-def')==100.0);
$t('score threshold setting respected',(function(){ update_option(NGSHM::OPT,array_merge(NGSHM::defaults(),array('min_score'=>100))); $r=NGSHM::suggest('/kidney-stone-prevention-guide'); update_option(NGSHM::OPT,NGSHM::defaults()); return $r===null; })());
// redirect validation
$t('add_redirect relative target',NGSHM::add_redirect('/old-a','/new-a')===true&&NGSHM::find_redirect('/old-a')->to_url===home_url('/new-a'));
$t('add_redirect external https allowed (admin set)',NGSHM::add_redirect('/old-b','https://example.org/x')===true);
$t('javascript: target rejected',is_wp_error(NGSHM::add_redirect('/old-c','javascript:alert(1)')));
$t('protocol-relative // target is treated as external, not a local path',NGSHM::valid_target('//evil.example/x')==='https://evil.example/x'&&NGSHM::valid_target('/ok')===home_url('/ok'));
$t('self-loop rejected',is_wp_error(NGSHM::add_redirect('/loop',home_url('/loop/'))));
$t('home path not redirectable',is_wp_error(NGSHM::add_redirect('/','/x')));
$t('code whitelisted (999 -> 301)',NGSHM::add_redirect('/old-d','/n',999)===true&&(int)NGSHM::find_redirect('/old-d')->code===301);
NGSHM::log('/old-a'); NGSHM::add_redirect('/old-a','/new-a2'); $t('approving marks the 404 row redirected',$wpdb->get_var("SELECT status FROM ".NGSHM::t404()." WHERE path='/old-a'")==='redirected');
$t('replace keeps one row per source',(int)$wpdb->get_var("SELECT COUNT(*) FROM ".NGSHM::tred()." WHERE from_path='/old-a'")===1);
// runtime redirect: simulate 404 request
$_SERVER['REQUEST_URI']='/old-a/?x=1'; global $wp_query; $wp_query->set_404();
$got=null; add_filter('wp_redirect',function($loc,$st) use(&$got){ $got=array($loc,$st); throw new Exception('redir'); },10,2);
try{ NGSHM::maybe_redirect(); }catch(Exception $e){}
$t('404 request is redirected with approved code',$got&&$got[0]===home_url('/new-a2')&&$got[1]===301);
$t('redirect hits counted',(int)NGSHM::find_redirect('/old-a')&&(int)$wpdb->get_var("SELECT hits FROM ".NGSHM::tred()." WHERE from_path='/old-a'")===1);
$got=null; $_SERVER['REQUEST_URI']='/never-approved'; try{ NGSHM::maybe_redirect(); }catch(Exception $e){} $t('no redirect without approval (suggestions never auto-apply)',$got===null);
// logging via template hook
$_SERVER['REQUEST_URI']='/hook-logged/'; $wp_query->set_404(); $wp_query->is_404=true; NGSHM::maybe_log(); $t('maybe_log logs a 404 request',(bool)$wpdb->get_var("SELECT id FROM ".NGSHM::t404()." WHERE path='/hook-logged'"));
$_SERVER['REQUEST_URI']='/pic.png'; NGSHM::maybe_log(); $t('maybe_log skips ignored extension',!$wpdb->get_var("SELECT id FROM ".NGSHM::t404()." WHERE path='/pic.png'"));
update_option(NGSHM::OPT,array_merge(NGSHM::defaults(),array('log'=>0))); $_SERVER['REQUEST_URI']='/log-off'; NGSHM::maybe_log(); $t('logging switch off',!$wpdb->get_var("SELECT id FROM ".NGSHM::t404()." WHERE path='/log-off'")); update_option(NGSHM::OPT,NGSHM::defaults());
// row cap
update_option(NGSHM::OPT,array_merge(NGSHM::defaults(),array('max_rows'=>100))); $wpdb->query('TRUNCATE '.NGSHM::t404()); for($i=0;$i<120;$i++){ NGSHM::log('/cap-'.$i); } $c=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.NGSHM::t404()); $t('row cap enforced ('.$c.' rows)',$c<=120&&$c>=20&&$c<120); update_option(NGSHM::OPT,NGSHM::defaults());
// purge
$wpdb->query('TRUNCATE '.NGSHM::t404()); NGSHM::log('/old-open'); $wpdb->update(NGSHM::t404(),array('last_seen'=>gmdate('Y-m-d H:i:s',time()-200*DAY_IN_SECONDS)),array('path'=>'/old-open')); NGSHM::log('/fresh'); NGSHM::purge();
$t('purge removes rows older than keep_days, keeps fresh',!$wpdb->get_var("SELECT id FROM ".NGSHM::t404()." WHERE path='/old-open'")&&(bool)$wpdb->get_var("SELECT id FROM ".NGSHM::t404()." WHERE path='/fresh'"));
// images
$up=wp_get_upload_dir(); wp_mkdir_p($up['path']); $good=$up['path'].'/good.jpg'; file_put_contents($good,base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
$gu=$up['url'].'/good.jpg'; $bu=$up['url'].'/gone.jpg'; $ext='https://elsewhere.example/p.jpg';
$pi=wp_insert_post(array('post_title'=>'Imgs','post_status'=>'publish','post_content'=>'<img src="'.$gu.'"><img src="'.$bu.'?v=2"><img src="'.$ext.'"><p>t</p>'));
$items=NGSHM::scan_images(); $mine=array_values(array_filter($items,function($i) use($pi){return $i['post_id']===$pi;}));
$t('scan: exactly the missing local image flagged',count($mine)===1&&strpos($mine[0]['src'],'gone.jpg')!==false);
$t('scan: existing local image not flagged',strpos(json_encode($items),'good.jpg')===false);
$t('scan: external image not checked (listed as limit)',strpos(json_encode($items),'elsewhere')===false);
$pj=wp_insert_post(array('post_title'=>'Trav','post_status'=>'publish','post_content'=>'<img src="'.$up['url'].'/../wp-config.php">')); $items=NGSHM::scan_images(); $t('scan: path traversal src ignored',empty(array_filter($items,function($i) use($pj){return $i['post_id']===$pj;})));
$att=wp_insert_attachment(array('post_title'=>'a','post_mime_type'=>'image/jpeg','post_status'=>'inherit'),$good,$pi); set_post_thumbnail($pi,$att); unlink($good); $items=NGSHM::scan_images();
$t('scan: featured image with missing file flagged',(bool)array_filter($items,function($i) use($pi){return $i['post_id']===$pi&&$i['why']==='featured';}));
$t('scan result stored',(bool)get_option(NGSHM::IMG));
// admin security
$o=''; ob_start(); NGSHM::page(); $o=ob_get_clean(); $t('admin page renders tabs',strpos($o,'nav-tab')!==false);
$_GET['tab']='redirects'; ob_start(); NGSHM::page(); $o=ob_get_clean(); $t('redirects tab lists rules',strpos($o,'/old-a')!==false&&strpos($o,'_wpnonce')!==false);
$_GET['tab']='404'; NGSHM::log('/<script>alert(1)</script>'); ob_start(); NGSHM::page(); $o=ob_get_clean(); $t('404 path output is escaped',strpos($o,'<script>alert(1)')===false);
wp_set_current_user(0); $died=false; $h=function() use(&$died){ return function() use(&$died){ $died=true; throw new Exception('die'); }; }; add_filter('wp_die_handler',$h); add_filter('wp_die_ajax_handler',$h); add_filter('wp_die_json_handler',$h); add_filter('wp_die_xmlrpc_handler',$h); try{ NGSHM::handle_approve(); }catch(Exception $e){} $t('approve handler refuses logged-out/no-cap user',$died); wp_set_current_user(1);
$died=false; $_REQUEST['_wpnonce']='bad'; try{ NGSHM::handle_ignore(); }catch(Exception $e){} $t('handler refuses bad nonce',$died);
// i18n
$t('i18n AR/FR/DE',(function(){ $m=NGSHM::T(); return $m['ar']['404 log']==='سجل 404'&&$m['fr']['Redirects']==='Redirections'&&$m['de']['Broken images']==='Defekte Bilder'; })());
add_filter('ngshm_locale',function(){return 'ar';}); $t('gettext applies AR',__('Settings','ng-shm')==='الإعدادات'); remove_all_filters('ngshm_locale');
echo "TOTAL pass=$pass fail=$fail\n";
