<?php

function h($x): string { return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function respond(string $body,int $status=200,string $type='text/html; charset=utf-8'): void {
    http_response_code($status);
    header('Content-Type: '.$type);
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') echo $body;
    exit;
}
function json_reply($value,int $status=200): void { respond(enc($value),$status,'application/json; charset=utf-8'); }
function go(string $route,int $status=303): void { header('Location: '.SITE_ORIGIN.$route,true,$status); exit; }
function post_guard(): void {
    if (($_SERVER['HTTP_ORIGIN']??'')!==SITE_ORIGIN ||
        (int)($_SERVER['CONTENT_LENGTH']??0)>16384) respond('Запрос отклонён.',403,'text/plain; charset=utf-8');
}
function safe_city(string $id): bool { return (bool)preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D',$id) && strlen($id)<=45; }
function normalized_ids(array $values): array {
    $result=[];
    foreach ($values as $v) {
        if (!is_scalar($v) || !preg_match('/^[1-9]\d{0,9}$/D',(string)$v)) throw new InvalidArgumentException('Проверьте ID Bitrix24.');
        $result[]=(string)$v;
    }
    if (count($result)>20) throw new InvalidArgumentException('Укажите до 20 подразделений.');
    return array_values(array_unique($result));
}
function google_id(string $text): string {
    $url=parse_url($text);
    if (!$url || ($url['scheme']??'')!=='https' || isset($url['user']) || isset($url['pass']) ||
        isset($url['port']) || !in_array($url['host']??'',['docs.google.com','drive.google.com'],true)) {
        throw new InvalidArgumentException('Проверьте адрес таблицы Google.');
    }
    parse_str($url['query']??'',$query);
    preg_match('~^/(?:spreadsheets|file)/d/([a-zA-Z0-9_-]+)(?:/|$)~',$url['path']??'',$m);
    $id=$m[1]??($query['id']??'');
    if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{15,120}$/D',$id)) throw new InvalidArgumentException('В ссылке нет ID таблицы.');
    return $id;
}
function clean_city(array $data): array {
    $id=(string)($data['id']??''); $name=trim((string)($data['name']??''));
    $region=trim((string)($data['region']??'')); $address=trim((string)($data['address']??''));
    $manager=trim((string)($data['manualManager']??'')); $profile=trim((string)($data['managerId']??''));
    $lat=filter_var($data['lat']??false,FILTER_VALIDATE_FLOAT); $lon=filter_var($data['lon']??false,FILTER_VALIDATE_FLOAT);
    if (!safe_city($id) || !$name || !$region || strlen($name)>360 || strlen($region)>480 ||
        $lat===false || $lon===false || $lat<39 || $lat>57 || $lon<44 || $lon>89) {
        throw new InvalidArgumentException('Проверьте код города, название, регион и координаты.');
    }
    if ($profile && !preg_match('/^[1-9]\d{0,9}$/D',$profile)) throw new InvalidArgumentException('Проверьте ID профиля Bitrix24.');
    if (strlen($manager)>400 || strlen($address)>2000) throw new InvalidArgumentException('Адрес или имя слишком длинные.');
    $files=array_values(array_unique((array)($data['files']??[])));
    if (count($files)>8 || array_filter($files,static fn($file)=>!is_string($file)||!preg_match('/^[a-zA-Z0-9_-]{15,120}$/D',$file))) {
        throw new InvalidArgumentException('Проверьте ссылки на таблицы.');
    }
    return ['id'=>$id,'name'=>$name,'region'=>$region,'lat'=>$lat,'lon'=>$lon,'address'=>$address,
        'manualManager'=>$manager,'managerId'=>$profile,'wall'=>!empty($data['wall']),'files'=>$files,
        'departmentIds'=>normalized_ids((array)($data['departmentIds']??[]))];
}
function db_open(string $path): PDO {
    $mask=umask(0077);
    try {
        $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=4000; CREATE TABLE IF NOT EXISTS state(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
        return $db;
    } finally { umask($mask); }
}
function db_read(PDO $db,string $key) {
    $q=$db->prepare('SELECT value FROM state WHERE key=?'); $q->execute([$key]);
    return $q->fetchColumn();
}
function db_init(PDO $db,array $input): void {
    $webhook=''; $password=$input['password']??'';
    if (!is_string($password) || strlen($password)<10 || strlen($password)>70) {
        throw new InvalidArgumentException('Invalid credentials');
    }
    $departments=[];
    foreach ((array)($input['departments']??[]) as $city=>$ids) {
        if (!safe_city((string)$city)) throw new InvalidArgumentException('Invalid mapping');
        $departments[$city]=normalized_ids((array)$ids);
    }
    $overrides=[];
    foreach ((array)($input['overrides']??[]) as $row) {
        $city=clean_city($row); $overrides[$city['id']]=$city;
    }
    $accounts=[]; $occupied=[]; $keys=[];
    $cities=array_replace(array_column(json_decode(base64_decode(SITE_DATA),true)['cities'],null,'id'),$overrides);
    foreach ((array)($input['accounts']??[]) as $login=>$record) {
        $city=$record['city']??''; $id=$record['id']??'';
        if (!director_name((string)$login) || !is_string($city) || !isset($cities[$city]) || isset($occupied[$city]) ||
            !is_string($id) || !preg_match('/^[a-f0-9]{16}$/D',$id) || isset($keys[$id]) ||
            !preg_match('/^[a-f0-9]{32}$/D',$record['salt']??'') || !preg_match('/^[a-f0-9]{64}$/D',$record['hash']??'')) {
            throw new InvalidArgumentException('Invalid account');
        }
        $occupied[$city]=true;
        $accounts[$login]=['city'=>$city,'id'=>$id,'salt'=>$record['salt'],'hash'=>$record['hash']];
        $list=$input['credentials'][$id]??[];
        if (!is_array($list)||count($list)>10) throw new InvalidArgumentException('Invalid passkey set');
        $keys[$id]=[];
        foreach ($list as $item) {
            if (strlen($item['id']??'')>1024||strlen($item['publicKey']??'')>2048) throw new InvalidArgumentException('Invalid passkey');
            $keys[$id][]=['id'=>$item['id'],'pem'=>passkey_import($item['id'],$item['publicKey']),'counter'=>(int)$item['counter'],'used'=>[]];
        }
    }
    $config=['webhook'=>$webhook,'departments'=>$departments,
        'password'=>password_hash($password,PASSWORD_DEFAULT),'sessionKey'=>bin2hex(random_bytes(32))];
    $db->beginTransaction();
    try {
        $write=$db->prepare('INSERT INTO state(key,value) VALUES(?,?)');
        $write->execute(['config',enc($config)]);
        $write->execute(['cities',enc($overrides)]);
        $write->execute(['directors',enc($accounts)]);
        foreach ($keys as $id=>$list) if ($list) $write->execute(['passkeys:'.$id,enc($list)]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
function projection(array $site,array $city): array {
    $phi1=deg2rad(43); $phi2=deg2rad(52);
    $tan=static fn($angle)=>tan(M_PI/4+$angle/2);
    $n=log(cos($phi1)/cos($phi2))/log($tan($phi2)/$tan($phi1));
    $f=cos($phi1)*$tan($phi1)**$n/$n;
    $r=$f/$tan(deg2rad($city['lat']))**$n;
    $theta=$n*deg2rad($city['lon']-68);
    [$left,$top,$right,$bottom]=$site['bounds'];
    $scale=min(695/($right-$left),395/($bottom-$top));
    return [round(410+($r*sin($theta)-($left+$right)/2)*$scale,2),
        round(252+($r*cos($theta)-($top+$bottom)/2)*$scale,2)];
}
function city_replace(array $site,array $city): array {
    [$x,$y]=projection($site,$city);
    return ['atlas-template-id'=>$city['id'],'ATLAS_NAME'=>h($city['name']),'ATLAS_REGION'=>h($city['region']),
        'ATLAS_SEARCH'=>h(str_replace('ё','е',lower($city['name'].' '.$city['region']))),
        'ATLAS_COORDS'=>"$x $y",'ATLAS_VIEWBOX'=>($x-530).' '.($y-133).' 780 460'];
}
function home_page(array $site,array $cities): string {
    $t=$site['templates']; $pins=''; $rows=''; $num=0;
    foreach ($cities as $city) {
        $replace=city_replace($site,$city);
        $rows.=strtr($t['row'],$replace);
        if ($city['wall']) {
            $pin=strtr($t['pin'],$replace);
            $length=preg_match_all('/./u',$city['name']); $width=max(36,10*$length);
            $pin=preg_replace('/<rect[^>]+>/', '<rect fill="transparent" x="'.(-$width/2).'" y="-30" width="'.$width.'" height="49"/>',$pin,1);
            [$rect,$text]=$t['pinOffsets'][$city['id']]??[null,null];
            if ($rect) $pin=preg_replace('/<rect[^>]+>/',$rect,$pin,1);
            if ($text) $pin=preg_replace('/<text [^>]+>/',$text,$pin,1);
            $pins.=str_replace('--delay:0s','--delay:'.($num*0.24).'s',$pin); $num++;
        }
    }
    return strtr($t['home'],['ATLAS_PINS'=>$pins,'ATLAS_ROWS'=>$rows,'ATLAS_COUNT'=>(string)$num]);
}
function nfc_links_page(array $site,array $cities): string {
    $rows='';
    foreach ($cities as $city) {
        if (!$city['wall']) continue;
        $url=SITE_ORIGIN.'/filial/'.$city['id'].'/';
        $rows.='<tr><th scope="row">'.h($city['name']).'</th><td><a href="'.h($url).'">'.h($url).'</a></td>'.
            '<td><button type="button" class="copy" data-nfc-url="'.h($url).'">Копировать</button></td></tr>';
    }
    $body='<main class="frame nfc-links"><a class="back" href="/">← К карте филиалов</a><h1>Ссылки для NFC</h1>'.
        '<p>Скопируйте адрес филиала и запишите его на метку как URL.</p>'.
        '<table><thead><tr><th scope="col">Филиал</th><th scope="col">URL</th><th scope="col"><span class="sr-only">Копирование</span></th></tr></thead><tbody>'.$rows.'</tbody></table>'.
        '<p class="sr-only" id="nfc-copy-message" role="status"></p></main>';
    return page($site,'Ссылки для NFC',$body);
}
function branch_page(array $site,array $city,?array $access): string {
    $t=$site['templates']; $reports='';
    if (can_finance($access,$city['id'])) {
        foreach ($city['files'] as $i=>$id) {
            $reports.=$t['reports'][$id]??str_replace(['ATLAS_FILE_PLACEHOLDER','Таблица ДДС'],[$id,'Таблица ДДС '.($i+1)],$t['report']);
        }
        $finance='<section class="financial" data-budget-branch="'.h($city['id']).'"><h2>Движение денежных средств</h2>'.($reports?:$t['empty']).'</section>';
    } else {
        $assigned=$access['city']??'';
        $note=$assigned?'Ваш логин даёт доступ только к ДДС своего филиала.':'Отчёты ДДС доступны директорам после входа.';
        $link=$assigned?'/filial/'.$assigned.'/':finance_href($city['id']);
        $label=$assigned?'Открыть свой филиал':'Войти для просмотра';
        $finance='<section class="financial"><div class="report finance-gate"><h2>Финансы филиала</h2><p>'.$note.'</p><a class="copy" href="'.h($link).'">'.$label.'</a></div></section>';
    }
    $address=$city['address']??'';
    $office=$address ? '<div class="office"><strong>Адрес</strong><span class="branch-address">'.h($address).'</span></div>' : '';
    return strtr($t['skeleton'],city_replace($site,$city)+[
        'ATLAS_FINANCE'=>$finance,'ATLAS_OFFICE'=>$office,'ATLAS_TAG'=>$city['wall']?$t['nfc']:''
    ]);
}
function page(array $site,string $title,string $content): string {
    return strtr($site['templates']['page'],['ATLAS_NAME'=>h($title),'ATLAS_MAIN'=>$content]);
}
function set_session(string $value,int $expires): void {
    setcookie('__Host-atlas-local',$value,[
        'expires'=>$expires,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'
    ]);
}
function session_tag(string $key,string $expires): string {return hash_hmac('sha256','admin:'.$expires,$key);}
function authenticated(array $config): bool {
    $cookie=$_COOKIE['__Host-atlas-local']??'';
    return is_string($cookie) && preg_match('/^(\d{10})\.([a-f0-9]{64})$/D',$cookie,$parts) &&
        (int)$parts[1]>time() && hash_equals(session_tag($config['sessionKey'],$parts[1]),$parts[2]);
}
function admin_password_login(PDO $db,array $config,string $login,string $password): int {
    if ($login!=='administrator') $status=401;
    elseif (!auth_rate($db,'admin')) $status=429;
    elseif (strlen($password)>72 || !password_verify($password,$config['password'])) $status=401;
    else $status=200;
    audit_auth($db,audit_login_name($login),'password',audit_status($status));
    if ($status!==200) return $status;
    $clear=$db->prepare('DELETE FROM login_attempts WHERE subject=?');
    $clear->execute(['admin']);
    start_admin_session($config);
    return 200;
}
function start_admin_session(array $config): void {
    $expires=time()+28800;
    set_session($expires.'.'.session_tag($config['sessionKey'],(string)$expires),$expires);
}
function login_page(array $site,string $message=''): string {
    return page($site,'Вход в админку','<main class="frame admin"><form class="admin-form admin-login" method="post" action="/admin/"><h1>Вход в админку</h1>'.
        ($message?'<p class="admin-message" role="alert">'.h($message).'</p>':'').
        '<input type="hidden" name="action" value="login"><label>Логин<input name="username" autocomplete="username" required></label><label>Пароль<input name="password" type="password" autocomplete="current-password" required></label><button class="copy">Войти с паролем</button><button class="copy" type="button" data-passkey="auth" data-passkey-entry="admin" hidden>Войти без пароля</button><p class="admin-message" data-passkey-status role="status" hidden></p></form></main>');
}
function admin_form(array $site,array $cities,array $config,?array $city,string $revision,string $notice=''): string {
    $editing=$city!==null; $city=$city??['wall'=>true,'files'=>[]];
    $field=static fn(string $label,string $key,string $attrs=''): string=>'<label>'.h($label).'<input name="'.$key.'" value="'.h($city[$key]??'').'" '.$attrs.'></label>';
    $nav=''; foreach ($cities as $item) $nav.='<a class="admin-city" href="/admin/?city='.h($item['id']).'"'.(($city['id']??'')===$item['id']?' aria-current="page"':'').'>'.h($item['name']).'</a>';
    $urls=[]; foreach ($city['files'] as $id) $urls[]=$site['documents'][$id]['url']??'/sample-report.html';
    $ids=($city['departmentIds']??[])?:($config['departments'][$city['id']??'']??[]);
    $body='<main class="frame admin"><div class="admin-heading"><h1>Филиалы</h1><form method="post" action="/admin/"><button class="copy" name="action" value="logout">Выйти</button></form></div><div class="admin-layout"><nav class="admin-nav" aria-label="Города"><a class="copy" href="/admin/">Добавить город</a><a class="admin-city" href="/admin/?next=finance-access">Доступ к финансам</a><a class="admin-city" href="/admin/?next=audit">Журнал входов</a>'.$nav.'</nav><form class="admin-form" method="post" action="/admin/"><h2>'.h($editing?$city['name']:'Новый город').'</h2>';
    if ($editing) $body.='<a class="back" href="/filial/'.h($city['id']).'/">Открыть карточку</a><a class="back" href="'.h(finance_href($city['id'])).'">Ссылка для NFC: вход в финансы</a>';
    if ($notice) $body.='<p class="admin-message" role="status">'.h($notice).'</p>';
    $body.='<input type="hidden" name="action" value="save"><input type="hidden" name="etag" value="'.h($revision).'"><input type="hidden" name="existing" value="'.h($city['id']??'').'">'.
        $field('Код для ссылки','id','required maxlength="45" '.($editing?'readonly':'')).
        $field('Название города','name','required').$field('Регион','region','required').
        '<div class="admin-coordinates">'.$field('Широта','lat','required inputmode="decimal"').$field('Долгота','lon','required inputmode="decimal"').'</div>'.
        '<label class="admin-check"><input name="wall" type="checkbox" '.($city['wall']?'checked':'').'> Показывать метку на карте</label>'.
        '<label>Адрес<textarea name="address" rows="2">'.h($city['address']??'').'</textarea></label>'.
        $field('Руководитель вручную','manualManager','placeholder="Пусто — использовать демо-профиль"').
        $field('Ссылка или ID профиля руководителя в Bitrix24','managerId').
        '<label>ID подразделений в Bitrix24<input name="departmentIds" value="'.h(implode(', ',(array)$ids)).'" placeholder="Через запятую"></label>'.
        '<label>Ссылки на таблицы ДДС<textarea name="files" rows="4" placeholder="Одна ссылка на строку">'.h(implode("\n",$urls)).'</textarea></label>'.
        '<button class="copy">Сохранить карточку</button></form></div></main>';
    return page($site,'Админка',$body);
}
function admin(PDO $db,array $site,array $config,array $cities,string $state,string $method): void {
    $action=$method==='POST'?input('action'):'';
    $next=$_GET['next']??'';
    if ($method==='POST') post_guard();
    if ($action==='passkey-defer'||($method!=='POST'&&$next==='passkey-setup')) enrollment_page($db,$site,$config,$cities,$action==='passkey-defer');
    if (str_starts_with($action,'passkey-')) {
        try {passkey_route($db,$config,$action);}
        catch (Throwable $e) {
            if ($action==='passkey-auth-finish') audit_auth($db,'','passkey','denied');
            error_log('Atlas passkey request rejected ('.get_class($e).')');json_reply(['status'=>'invalid'],400);
        }
    }
    if (($method!=='POST'&&$next==='finance')||in_array($action,['finance-login','finance-logout'],true)) finance_route($db,$site,$config,$cities,$action);
    if ($method==='POST' && $action==='login') {
        $status=admin_password_login($db,$config,trim(input('username')),input('password'));
        if ($status!==200) respond(login_page($site,$status===429?'Слишком много попыток. Повторите через 15 минут.':'Неверный логин или пароль.'),$status);
        passkey_after_password($db,admin_passkey_identity($config),'admin','');
    }
    if (!authenticated($config)) respond(login_page($site),$method==='POST'?401:200);
    if (($method!=='POST'&&$next==='finance-access')||in_array($action,['director-save','director-revoke'],true)) {
        finance_admin($db,$site,$cities,$action);
    }
    if ($method==='POST' && $action==='logout') {
        audit_auth($db,'administrator','logout','ok');
        set_session('',time()-3600);go('/admin/');
    }
    if ($method!=='POST' && $next==='audit') audit_page($db,$site);
    $key=$method==='POST'?($_POST['existing']??''):($_GET['city']??'');
    $existing=is_string($key)?($cities[$key]??null):null;
    $revision=hash('sha256',$state);
    if ($method==='GET' && ($_GET['format']??'')==='json') json_reply(json_decode($state));
    if ($method!=='POST') respond(admin_form($site,$cities,$config,$existing,$revision,isset($_GET['saved'])?'Карточка сохранена.':''));
    try {
        if ($action!=='save') throw new InvalidArgumentException('Действие неизвестно.');
        if (!hash_equals($revision,(string)($_POST['etag']??''))) throw new InvalidArgumentException('Карточку изменили в другой вкладке. Откройте её снова.');
        $data=$_POST;
        foreach (['lat','lon'] as $coordinate) $data[$coordinate]=str_replace(',','.',trim((string)($data[$coordinate]??'')));
        $data['files']=array_map('google_id',array_filter(array_map('trim',preg_split('/\R/u',(string)($data['files']??'')))));
        $data['departmentIds']=preg_split('/[,\s]+/',trim((string)($data['departmentIds']??'')),-1,PREG_SPLIT_NO_EMPTY);
        $data['managerId']=preg_replace('~^https://directory\.example\.test/company/personal/user/([1-9]\d{0,9})/?$~D','$1',trim((string)($data['managerId']??'')));
        $city=clean_city($data);
        if (($existing && $city['id']!==$existing['id']) || (!$existing && isset($cities[$city['id']]))) throw new InvalidArgumentException('Этот код нельзя поменять или использовать повторно.');
        if (!$existing && count($cities)>=200) throw new InvalidArgumentException('Допустимо до 200 городов.');
        $overrides=json_decode($state,true); $overrides[$city['id']]=$city;
        $stmt=$db->prepare("UPDATE state SET value=? WHERE key='cities' AND value=?");
        $stmt->execute([enc($overrides),$state]);
        if (!$stmt->rowCount()) throw new InvalidArgumentException('Карточку изменили в другой вкладке. Откройте её снова.');
        go('/admin/?city='.rawurlencode($city['id']).'&saved=1');
    } catch (InvalidArgumentException $e) {respond(admin_form($site,$cities,$config,$existing,$revision,$e->getMessage()),400);}
}
function run_site(): void {
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Atlas-Backend: host-php');
    if (!is_demo_loopback() && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off')) go('/',308);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','HEAD','POST'],true)) respond('Method not allowed',405);
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    $db=db_open(demo_database_path());
    $stored=db_read($db,'config');
    if (!$stored) respond('Сайт подготавливается.',503);
    $config=json_decode($stored,true,24,JSON_THROW_ON_ERROR);
    $site=json_decode(base64_decode(SITE_DATA),true,30,JSON_THROW_ON_ERROR);
    $assets=['/style.css'=>'text/css','/app.js'=>'text/javascript','/brand.svg'=>'image/svg+xml','/kazakhstan.svg'=>'image/svg+xml','/sample-report.html'=>'text/html'];
    if ($method!=='POST' && isset($assets[$path])) respond($site['assets'][$path],200,$assets[$path].'; charset=utf-8');
    if ($method!=='POST' && $path==='/favicon.ico') go('/brand.svg',302);
    $state=(string)db_read($db,'cities');
    $cities=array_column($site['cities'],null,'id');
    $cities=array_replace($cities,json_decode($state,true,24,JSON_THROW_ON_ERROR));
    uasort($cities,static fn($a,$b)=>strcmp(lower($a['name']),lower($b['name'])));
    if ($path==='/admin/' || $path==='/admin') admin($db,$site,$config,$cities,$state,$method);
    if ($method==='POST') respond('Method not allowed',405);
    if ($path==='/'||$path==='/index.html') {
        respond(($_GET['nfc']??'')==='1'?nfc_links_page($site,$cities):home_page($site,$cities));
    }
    if (preg_match('~^/api/(branch|budget)/([a-z0-9-]+)$~D',$path,$m)) {
        if ($m[1]==='budget') {
            $access=finance_view($db,$config);
            if (!$access) json_reply(['status'=>'authentication-required'],401);
            if (!can_finance($access,$m[2])) json_reply(['status'=>'forbidden'],403);
        }
        if (!isset($cities[$m[2]])) json_reply(['status'=>'not-found'],404);
        $city=$cities[$m[2]];
        if ($method==='HEAD') json_reply([]);
        if ($m[1]==='branch') {
            try {json_reply(branch_profile($city,$config));} catch (Throwable $e) {error_log('Atlas Bitrix request failed for '.$city['id']);json_reply(['status'=>'unavailable'],502);}
        }
        $files=[];
        foreach ($city['files'] as $id) {
            try {$files[$id]=['status'=>'live','data'=>google_budget($id)];}
            catch (Throwable $e) {$files[$id]=['status'=>'unavailable']; error_log('Atlas budget request failed for '.$city['id'].': '.$e->getMessage());}
        }
        json_reply(['files'=>(object)$files,'checkedAt'=>gmdate('c')]);
    }
    if (preg_match('~^/filial/([a-z0-9-]+)(?:/(?:index\.html)?)?$~D',$path,$m) && isset($cities[$m[1]])) {
        if (!str_ends_with($path,'/') && !str_ends_with($path,'/index.html')) go('/filial/'.$m[1].'/',308);
        respond(branch_page($site,$cities[$m[1]],finance_view($db,$config)));
    }
    respond(page($site,'Страница не найдена','<main class="frame"><h1>Страница не найдена</h1><a href="/">К карте филиалов</a></main>'),404);
}
if (PHP_SAPI!=='cli') {
    try {run_site();} catch (Throwable $e) {
        error_log('Atlas local request failed: '.$e->getMessage());
        respond('Сайт временно недоступен.',502,'text/plain; charset=utf-8');
    }
}
