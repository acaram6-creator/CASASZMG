<?php
declare(strict_types=1);
$configPath=__DIR__.'/config.php';
if(!file_exists($configPath)){http_response_code(503);exit('Falta la instalación. Abre install.php.');}
$config=require $configPath;
function db(): PDO {
 static $pdo=null; global $config;
 if($pdo===null){$pdo=new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
 return $pdo;
}
function h($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function money($n):string{return '$'.number_format((float)$n,0,'.',',').' MXN';}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verify_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(400);exit('Solicitud no válida. Recarga la página.');}}
function logged_in():bool{return !empty($_SESSION['admin_id']);}
function require_admin():void{if(!logged_in()){header('Location: admin.php');exit;}}
function whatsapp_url(string $text=''):string{global $config;return 'https://wa.me/'.$config['whatsapp'].'?text='.rawurlencode($text);}

function ensure_schema():void {
 $pdo=db();
 $pdo->exec("CREATE TABLE IF NOT EXISTS site_visits (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, page_type VARCHAR(20) NOT NULL, property_id INT UNSIGNED NOT NULL DEFAULT 0, visitor_key CHAR(64) NOT NULL, visit_date DATE NOT NULL, source_label VARCHAR(80) NOT NULL DEFAULT 'Acceso directo', medium VARCHAR(40) NOT NULL DEFAULT 'Directo', device_type VARCHAR(20) NOT NULL DEFAULT 'Otro', country VARCHAR(100) NOT NULL DEFAULT '', region VARCHAR(120) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_visit(visitor_key,page_type,property_id,visit_date), INDEX idx_visit_date(visit_date), INDEX idx_visit_property(property_id), INDEX idx_visit_source(source_label), INDEX idx_visit_region(country,region)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $visitCols=$pdo->query("SHOW COLUMNS FROM site_visits")->fetchAll(PDO::FETCH_COLUMN);
 $visitMigrations=['source_label'=>"ALTER TABLE site_visits ADD source_label VARCHAR(80) NOT NULL DEFAULT 'Acceso directo'",'medium'=>"ALTER TABLE site_visits ADD medium VARCHAR(40) NOT NULL DEFAULT 'Directo'",'device_type'=>"ALTER TABLE site_visits ADD device_type VARCHAR(20) NOT NULL DEFAULT 'Otro'",'country'=>"ALTER TABLE site_visits ADD country VARCHAR(100) NOT NULL DEFAULT ''",'region'=>"ALTER TABLE site_visits ADD region VARCHAR(120) NOT NULL DEFAULT ''"];
 foreach($visitMigrations as $col=>$sql)if(!in_array($col,$visitCols,true))$pdo->exec($sql);
 $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value LONGTEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS property_images (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, property_id INT UNSIGNED NOT NULL, image_path VARCHAR(255) NOT NULL, sort_order INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_property_images(property_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS site_documents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(180) NOT NULL, file_path VARCHAR(255) NOT NULL, uploaded_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS gallery_images (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, image_path VARCHAR(255) NOT NULL, target_url VARCHAR(1000) DEFAULT '', sort_order INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS contract_templates (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,file_path VARCHAR(255) NOT NULL,access_mode VARCHAR(20) NOT NULL DEFAULT 'all',is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS contract_template_users (template_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,PRIMARY KEY(template_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS generated_contracts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,template_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,client_name VARCHAR(180) DEFAULT '',file_path VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(user_id),INDEX(template_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, property_id INT UNSIGNED NULL, owner_id INT UNSIGNED NULL, name VARCHAR(120) NOT NULL, email VARCHAR(160) NOT NULL, phone VARCHAR(40) DEFAULT '', property_title VARCHAR(180) DEFAULT '', message TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_msg_owner(owner_id), INDEX idx_msg_read(is_read)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $cols=$pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN);
 $adminMigrations=['is_active'=>"ALTER TABLE admins ADD is_active TINYINT(1) NOT NULL DEFAULT 1",'phone'=>"ALTER TABLE admins ADD phone VARCHAR(40) DEFAULT ''",'address'=>"ALTER TABLE admins ADD address VARCHAR(255) DEFAULT ''",'profile_image'=>"ALTER TABLE admins ADD profile_image VARCHAR(255) DEFAULT ''",'show_profile'=>"ALTER TABLE admins ADD show_profile TINYINT(1) NOT NULL DEFAULT 1",'display_name'=>"ALTER TABLE admins ADD display_name VARCHAR(120) DEFAULT ''",'full_name'=>"ALTER TABLE admins ADD full_name VARCHAR(120) DEFAULT ''"];
 foreach($adminMigrations as $col=>$sql)if(!in_array($col,$cols,true))$pdo->exec($sql);
 if(!in_array('role',$cols,true))$pdo->exec("ALTER TABLE admins ADD role VARCHAR(30) NOT NULL DEFAULT 'Administrador'");
 $cols=$pdo->query("SHOW COLUMNS FROM properties")->fetchAll(PDO::FETCH_COLUMN);
 if(!in_array('owner_id',$cols,true))$pdo->exec("ALTER TABLE properties ADD owner_id INT UNSIGNED NULL, ADD INDEX idx_property_owner(owner_id)");
 $migrations=[
  'construction_m2'=>"ALTER TABLE properties ADD construction_m2 DECIMAL(10,2) NOT NULL DEFAULT 0",
  'parking'=>"ALTER TABLE properties ADD parking SMALLINT UNSIGNED NOT NULL DEFAULT 0",
  'conservation'=>"ALTER TABLE properties ADD conservation VARCHAR(30) NOT NULL DEFAULT ''",
  'equipment'=>"ALTER TABLE properties ADD equipment LONGTEXT NULL",
  'amenities'=>"ALTER TABLE properties ADD amenities LONGTEXT NULL",
  'views'=>"ALTER TABLE properties ADD views INT UNSIGNED NOT NULL DEFAULT 0",
  'commission_pct'=>"ALTER TABLE properties ADD commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00",
  'maintenance_fee'=>"ALTER TABLE properties ADD maintenance_fee DECIMAL(12,2) NOT NULL DEFAULT 0",'no_maintenance'=>"ALTER TABLE properties ADD no_maintenance TINYINT(1) NOT NULL DEFAULT 0",'youtube_url'=>"ALTER TABLE properties ADD youtube_url VARCHAR(500) DEFAULT ''",'map_address'=>"ALTER TABLE properties ADD map_address VARCHAR(500) DEFAULT ''",'seo_title'=>"ALTER TABLE properties ADD seo_title VARCHAR(180) DEFAULT ''",'seo_description'=>"ALTER TABLE properties ADD seo_description VARCHAR(320) DEFAULT ''",'seo_keywords'=>"ALTER TABLE properties ADD seo_keywords VARCHAR(500) DEFAULT ''",'international_content'=>"ALTER TABLE properties ADD international_content LONGTEXT NULL"
 ];
 foreach($migrations as $col=>$sql)if(!in_array($col,$cols,true))$pdo->exec($sql);
 $pdo->exec("ALTER TABLE properties MODIFY bathrooms DECIMAL(4,1) NOT NULL DEFAULT 0");
}
function analytics_text_cut(string $value,int $length):string { return function_exists('mb_substr')?mb_substr($value,0,$length):substr($value,0,$length); }
function visit_traffic_data():array {
 $source='Acceso directo';$medium='Directo';$ref=trim((string)($_SERVER['HTTP_REFERER']??''));$host=strtolower((string)parse_url($ref,PHP_URL_HOST));$own=strtolower((string)($_SERVER['HTTP_HOST']??''));$own=preg_replace('/^www\\./','',$own);$host=preg_replace('/^www\\./','',$host);
 $utmSource=trim((string)($_GET['utm_source']??''));$utmMedium=trim((string)($_GET['utm_medium']??''));
 if($utmSource!==''){$source=analytics_text_cut($utmSource,80);$medium=$utmMedium!==''?analytics_text_cut($utmMedium,40):'Campaña';}
 elseif($host!==''&&$host!==$own){$source=$host;$medium='Referencia';$map=['google.'=>'Google','bing.'=>'Bing','yahoo.'=>'Yahoo','facebook.com'=>'Facebook','fb.com'=>'Facebook','instagram.com'=>'Instagram','l.instagram.com'=>'Instagram','wa.me'=>'WhatsApp','whatsapp.com'=>'WhatsApp','t.co'=>'X','twitter.com'=>'X','tiktok.com'=>'TikTok','youtube.com'=>'YouTube','youtu.be'=>'YouTube'];foreach($map as $needle=>$label)if(str_contains($host,$needle)){$source=$label;$medium=in_array($label,['Google','Bing','Yahoo'])?'Búsqueda orgánica':(in_array($label,['Facebook','Instagram','WhatsApp','X','TikTok','YouTube'])?'Social':'Referencia');break;}}
 elseif(!empty($_SESSION['traffic_source']['source'])){$source=(string)$_SESSION['traffic_source']['source'];$medium=(string)$_SESSION['traffic_source']['medium'];}
 $_SESSION['traffic_source']=['source'=>$source,'medium'=>$medium];
 $ua=strtolower((string)($_SERVER['HTTP_USER_AGENT']??''));$device=preg_match('/tablet|ipad/i',$ua)?'Tablet':(preg_match('/mobile|android|iphone|ipod/i',$ua)?'Móvil':'Escritorio');
 $country='';$region='';
 if(empty($_SESSION['traffic_geo_checked'])){$_SESSION['traffic_geo_checked']=1;$ip=(string)($_SERVER['REMOTE_ADDR']??'');if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){$ctx=stream_context_create(['http'=>['timeout'=>1.5,'header'=>"User-Agent: AlianzaInmobiliariaAnalytics/1.0\r\n"]]);$raw=@file_get_contents('https://ipwho.is/'.rawurlencode($ip),false,$ctx);if($raw){$geo=json_decode($raw,true);if(is_array($geo)&&!empty($geo['success'])){$country=analytics_text_cut((string)($geo['country']??''),100);$region=analytics_text_cut((string)($geo['region']??''),120);}}}$_SESSION['traffic_geo']=['country'=>$country,'region'=>$region];}
 $geo=$_SESSION['traffic_geo']??[];return ['source_label'=>$source,'medium'=>$medium,'device_type'=>$device,'country'=>(string)($geo['country']??''),'region'=>(string)($geo['region']??'')];
}
function track_site_visit(string $pageType,int $propertyId=0):void {
 $visitorKey=hash('sha256',session_id());$data=visit_traffic_data();$st=db()->prepare('INSERT IGNORE INTO site_visits(page_type,property_id,visitor_key,visit_date,source_label,medium,device_type,country,region) VALUES(?,?,?,CURDATE(),?,?,?,?,?)');$st->execute([$pageType,$propertyId,$visitorKey,$data['source_label'],$data['medium'],$data['device_type'],$data['country'],$data['region']]);
}
function setting(string $key,string $default=''):string { $st=db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');$st->execute([$key]);$v=$st->fetchColumn();return $v===false?$default:(string)$v; }
function save_setting(string $key,string $value):void { $st=db()->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$st->execute([$key,$value]); }
function current_admin():array { if(!logged_in())return []; $st=db()->prepare('SELECT id,username,role,full_name,display_name,phone,address,profile_image,is_active FROM admins WHERE id=? AND is_active=1');$st->execute([$_SESSION['admin_id']]);return $st->fetch()?:[]; }
function is_admin():bool { $a=current_admin();return ($a['role']??'')==='Administrador'; }
