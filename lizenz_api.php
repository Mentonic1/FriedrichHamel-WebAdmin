<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function fail(string $m,int $c=400,string $code='api_error'):never{out(['status'=>'error','code'=>$code,'message'=>$m],$c);}
function req():array{$d=$_POST;$ct=(string)($_SERVER['CONTENT_TYPE']??'');if(stripos($ct,'application/json')!==false){$j=json_decode((string)file_get_contents('php://input'),true);if(is_array($j))$d=array_merge($d,$j);}return $d;}
function s(array $d,string $k,string $def=''):string{return isset($d[$k])&&!is_array($d[$k])?trim((string)$d[$k]):$def;}
function hsec(string $v):string{return hash('sha256',$v);}
function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}

function license_by_code(PDO $pdo,string $code):array{
 $q=$pdo->prepare("SELECT l.id lizenz_id,l.benutzer_id,l.aktiv lizenz_aktiv,l.gueltig_bis,b.name benutzer_name,b.email,b.aktiv benutzer_aktiv,b.max_geraete FROM aufmass_cloud_lizenzen l JOIN aufmass_cloud_benutzer b ON b.id=l.benutzer_id WHERE l.lizenz_hash=? LIMIT 1");$q->execute([hsec($code)]);$r=$q->fetch();
 if(!$r)fail('Lizenzcode nicht gefunden.',404,'license_not_found'); if((int)$r['benutzer_aktiv']!==1)fail('Benutzer gesperrt.',403,'user_disabled'); if((int)$r['lizenz_aktiv']!==1)fail('Lizenz gesperrt.',403,'license_disabled');
 if(!$r['gueltig_bis']){$until=(new DateTimeImmutable())->modify('+'.DEFAULT_LICENSE_DAYS.' days')->format('Y-m-d H:i:s');$pdo->prepare('UPDATE aufmass_cloud_lizenzen SET gueltig_bis=?,aktiviert_am=COALESCE(aktiviert_am,NOW()) WHERE id=?')->execute([$until,$r['lizenz_id']]);$r['gueltig_bis']=$until;}
 if(new DateTimeImmutable((string)$r['gueltig_bis'])<=new DateTimeImmutable())fail('Lizenz abgelaufen.',403,'license_expired'); return $r;
}
function auth_device(PDO $pdo,array $d):array{
 $fp=s($d,'geraet_id');$tk=s($d,'geraet_token');if($fp===''||$tk==='')fail('Geräte-Anmeldung fehlt.',401,'auth_missing');
 $q=$pdo->prepare("SELECT g.id geraet_db_id,g.benutzer_id,g.lizenz_id,g.aktiv geraet_aktiv,l.aktiv lizenz_aktiv,l.gueltig_bis,b.name benutzer_name,b.email,b.aktiv benutzer_aktiv,b.max_geraete FROM aufmass_cloud_geraete g JOIN aufmass_cloud_lizenzen l ON l.id=g.lizenz_id JOIN aufmass_cloud_benutzer b ON b.id=g.benutzer_id WHERE g.geraet_fingerprint=? AND g.token_hash=? LIMIT 1");$q->execute([$fp,hsec($tk)]);$r=$q->fetch();
 if(!$r)fail('Gerät nicht angemeldet.',401,'device_auth_failed');if((int)$r['geraet_aktiv']!==1)fail('Gerät gesperrt.',403,'device_disabled');if((int)$r['benutzer_aktiv']!==1||(int)$r['lizenz_aktiv']!==1)fail('Zugang gesperrt.',403,'access_disabled');if($r['gueltig_bis']&&new DateTimeImmutable((string)$r['gueltig_bis'])<=new DateTimeImmutable())fail('Lizenz abgelaufen.',403,'license_expired');
 $pdo->prepare('UPDATE aufmass_cloud_geraete SET zuletzt_online=NOW(),letzte_ip=?,user_agent=? WHERE id=?')->execute([substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$r['geraet_db_id']]);return $r;
}
function auth_payload(array $r,array $x=[]):array{return array_merge(['status'=>'success','nutzer'=>(string)$r['benutzer_name'],'benutzer_id'=>(int)$r['benutzer_id'],'ablauf'=>(string)$r['gueltig_bis'],'max_geraete'=>(int)$r['max_geraete']],$x);}
function iso(?string $v):?string{if(!$v)return null;try{return(new DateTimeImmutable($v,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');}catch(Throwable){return null;}}
function mysql_time(string $v):?string{try{return(new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}catch(Throwable){return null;}}

try{
 $pdo=db();$d=req();$a=s($d,'aktion');if($a===''&&($_SERVER['REQUEST_METHOD']??'')==='GET')$a='ping';
 if($a==='ping')out(['status'=>'success','api'=>'Friedrich Hamel Aufmaß Cloud','version'=>API_VERSION,'serverzeit'=>date('Y-m-d H:i:s')]);
 if($a==='geraet_anmelden'||$a==='pruefe_lizenz'){
  $lic=license_by_code($pdo,s($d,'code'));$fp=s($d,'geraet_id');if($fp==='')fail('Geräte-ID fehlt.');$name=substr(s($d,'geraet_name','Gerät'),0,255);$pdo->beginTransaction();
  $q=$pdo->prepare('SELECT id,aktiv FROM aufmass_cloud_geraete WHERE lizenz_id=? AND geraet_fingerprint=? LIMIT 1 FOR UPDATE');$q->execute([$lic['lizenz_id'],$fp]);$g=$q->fetch();
  if(!$g){$c=$pdo->prepare('SELECT COUNT(*) FROM aufmass_cloud_geraete WHERE benutzer_id=? AND aktiv=1');$c->execute([$lic['benutzer_id']]);if((int)$c->fetchColumn()>=(int)$lic['max_geraete']){$pdo->rollBack();fail('Maximale Geräteanzahl erreicht.',403,'device_limit');}}
  elseif((int)$g['aktiv']!==1){$pdo->rollBack();fail('Dieses Gerät wurde gesperrt.',403,'device_disabled');}
  $plain=token();$hash=hsec($plain);$ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);
  if($g)$pdo->prepare('UPDATE aufmass_cloud_geraete SET token_hash=?,geraet_name=?,zuletzt_online=NOW(),letzte_ip=?,user_agent=? WHERE id=?')->execute([$hash,$name,$ip,$ua,$g['id']]);
  else $pdo->prepare('INSERT INTO aufmass_cloud_geraete(benutzer_id,lizenz_id,geraet_fingerprint,geraet_name,token_hash,aktiv,zuletzt_online,letzte_ip,user_agent) VALUES(?,?,?,?,?,1,NOW(),?,?)')->execute([$lic['benutzer_id'],$lic['lizenz_id'],$fp,$name,$hash,$ip,$ua]);
  $pdo->prepare('UPDATE aufmass_cloud_lizenzen SET letzter_login=NOW() WHERE id=?')->execute([$lic['lizenz_id']]);$pdo->commit();out(auth_payload($lic,['geraet_token'=>$plain,'message'=>'Gerät freigeschaltet.']));
 }
 if($a==='pruefe_geraet'){out(auth_payload(auth_device($pdo,$d),['message'=>'Geräte-Lizenz gültig.']));}
 if($a==='geraet_abmelden'){$r=auth_device($pdo,$d);$pdo->prepare('UPDATE aufmass_cloud_geraete SET aktiv=0,token_hash=? WHERE id=?')->execute([hsec(token()),$r['geraet_db_id']]);out(['status'=>'success','message'=>'Gerät abgemeldet.']);}
 if($a==='lade_gesamten_katalog'){
  $pdo->exec("INSERT IGNORE INTO katalog_kategorien(name) SELECT DISTINCT CASE WHEN TRIM(COALESCE(kategorie,''))='' THEN 'Ohne Kategorie' ELSE TRIM(kategorie) END FROM material_katalog WHERE TRIM(COALESCE(material,''))<>''");$pdo->exec("INSERT IGNORE INTO globale_raum_vorlagen(name) SELECT name FROM katalog_kategorien WHERE TRIM(name)<>''");
  $cats=$pdo->query("SELECT name FROM katalog_kategorien ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);$m=$pdo->query("SELECT material,kategorie FROM material_katalog ORDER BY kategorie,material")->fetchAll();$vs=$pdo->query("SELECT id,name FROM globale_raum_vorlagen ORDER BY name")->fetchAll();$rooms=[];$q=$pdo->prepare('SELECT material FROM globale_raum_inhalte WHERE vorlage_id=? ORDER BY id');foreach($vs as $v){$q->execute([$v['id']]);$rooms[$v['name']]=$q->fetchAll(PDO::FETCH_COLUMN);}out(['status'=>'success','kategorien'=>$cats,'materialien'=>$m,'raum_vorlagen'=>$rooms]);
 }
 if($a==='sync_account_data'){
  $auth=auth_device($pdo,$d);$raw=$d['payload']??[];$p=is_array($raw)?$raw:json_decode((string)$raw,true);if(!is_array($p))fail('Ungültige Sync-Daten.');$uid=(int)$auth['benutzer_id'];$did=(int)$auth['geraet_db_id'];$uploaded=0;$deleted=0;$pdo->beginTransaction();
  foreach(($p['projekte']??[]) as $pr){if(!is_array($pr))continue;$uuid=trim((string)($pr['cloud_uuid']??''));$tm=mysql_time((string)($pr['updated_at']??''));if(!preg_match('/^[0-9a-fA-F-]{36}$/',$uuid)||!$tm)continue;$q=$pdo->prepare('SELECT id,updated_at,deleted_at FROM aufmass_cloud_projekte WHERE benutzer_id=? AND projekt_uuid=? LIMIT 1 FOR UPDATE');$q->execute([$uid,$uuid]);$old=$q->fetch();$oldtm=$old?((string)($old['deleted_at']?:$old['updated_at'])):'';if($old&&$oldtm>=$tm)continue;$json=json_encode($pr,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);$name=substr(trim((string)($pr['name']??'Projekt')),0,255);if($old)$pdo->prepare('UPDATE aufmass_cloud_projekte SET name=?,daten_json=?,updated_at=?,deleted_at=NULL,letztes_geraet_id=? WHERE id=?')->execute([$name,$json,$tm,$did,$old['id']]);else $pdo->prepare('INSERT INTO aufmass_cloud_projekte(benutzer_id,projekt_uuid,name,daten_json,updated_at,letztes_geraet_id) VALUES(?,?,?,?,?,?)')->execute([$uid,$uuid,$name,$json,$tm,$did]);$uploaded++;}
  foreach(($p['geloeschte_projekte']??[]) as $del){if(!is_array($del))continue;$uuid=trim((string)($del['cloud_uuid']??''));$tm=mysql_time((string)($del['deleted_at']??''));if(!preg_match('/^[0-9a-fA-F-]{36}$/',$uuid)||!$tm)continue;$q=$pdo->prepare('SELECT id,updated_at,deleted_at FROM aufmass_cloud_projekte WHERE benutzer_id=? AND projekt_uuid=? LIMIT 1 FOR UPDATE');$q->execute([$uid,$uuid]);$old=$q->fetch();$oldtm=$old?((string)($old['deleted_at']?:$old['updated_at'])):'';if($old&&$oldtm>=$tm)continue;if($old)$pdo->prepare('UPDATE aufmass_cloud_projekte SET daten_json=NULL,updated_at=?,deleted_at=?,letztes_geraet_id=? WHERE id=?')->execute([$tm,$tm,$did,$old['id']]);else $pdo->prepare("INSERT INTO aufmass_cloud_projekte(benutzer_id,projekt_uuid,name,daten_json,updated_at,deleted_at,letztes_geraet_id) VALUES(?,?,'',NULL,?,?,?)")->execute([$uid,$uuid,$tm,$tm,$did]);$deleted++;}
  $pdo->commit();$q=$pdo->prepare('SELECT projekt_uuid,name,daten_json,updated_at,deleted_at FROM aufmass_cloud_projekte WHERE benutzer_id=? ORDER BY id LIMIT 2000');$q->execute([$uid]);$pros=[];$dels=[];foreach($q->fetchAll() as $r){if($r['deleted_at']){$dels[]=['cloud_uuid'=>$r['projekt_uuid'],'deleted_at'=>iso($r['deleted_at'])];continue;}$j=json_decode((string)$r['daten_json'],true);if(!is_array($j))continue;$j['cloud_uuid']=$r['projekt_uuid'];$j['name']=$r['name'];$j['updated_at']=iso($r['updated_at']);$pros[]=$j;}out(auth_payload($auth,['message'=>'Cloud-Synchronisation erfolgreich.','hochgeladen'=>$uploaded,'geloescht'=>$deleted,'projekte'=>$pros,'geloeschte_projekte'=>$dels]));
 }
 fail($a===''?'Keine Aktion angegeben.':'Unbekannte Aktion: '.$a,404,'unknown_action');
}catch(PDOException $e){error_log($e->getMessage());fail('Datenbankfehler auf dem Server.',500,'database_error');}catch(Throwable $e){error_log($e->getMessage());fail('Interner Serverfehler.',500,'internal_error');}
