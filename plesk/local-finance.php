<?php

function input(string $name): string {
    $value=$_POST[$name]??'';
    return is_string($value)?$value:'';
}
function kv_cas(PDO $db,string $key,string $before,array $after): bool {
    if ($before==='') {
        $stmt=$db->prepare('INSERT OR IGNORE INTO state(key,value) VALUES(?,?)');
        $stmt->execute([$key,enc($after)]);
    } else {
        $stmt=$db->prepare('UPDATE state SET value=? WHERE key=? AND value=?');
        $stmt->execute([enc($after),$key,$before]);
    }
    return $stmt->rowCount()===1;
}
function auth_rate(PDO $db,string $subject): bool {
    $bucket=intdiv(time(),900);
    $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (subject TEXT PRIMARY KEY, bucket INTEGER NOT NULL, count INTEGER NOT NULL)');
    $write=$db->prepare('INSERT INTO login_attempts(subject,bucket,count) VALUES (?,?,1)
        ON CONFLICT(subject) DO UPDATE SET bucket=excluded.bucket,count=CASE WHEN login_attempts.bucket<>excluded.bucket THEN 1 ELSE login_attempts.count+1 END
        WHERE login_attempts.bucket<>excluded.bucket OR login_attempts.count<8');
    $write->execute([$subject,$bucket]);
    return $write->rowCount()===1;
}
function finance_href(string $id=''): string {
    return '/admin/?next=finance'.($id?'&city='.rawurlencode($id):'');
}
function finance_accounts(PDO $db): array {
    return json_decode((string)db_read($db,'directors'),true)??[];
}
function director_name(string $name): bool {
    return $name!=='administrator' && (bool)preg_match('/^[a-z][a-z0-9._-]{2,39}$/D',$name);
}
function director_hash(string $password,string $salt): string { return hash_pbkdf2('sha256',$password,$salt,100000,64); }
function director_ticket(array $config,array $account,int $expires): string {
    return hash_hmac('sha256','director:'.$account['id'].':'.$account['hash'].':'.$expires,$config['sessionKey']);
}
function director_cookie(array $config,?array $account=null): void {
    $expires=time()+28800;
    $proof=$account?$account['id'].'.'.$expires.'.'.director_ticket($config,$account,$expires):'';
    setcookie('__Host-atlas-director',$proof,['expires'=>$account?$expires:1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function director_lookup(array $accounts,string $id): ?array {
    foreach ($accounts as $login=>$item) if (hash_equals($item['id'],$id)) return $item+['login'=>$login];
    return null;
}
function finance_view(PDO $db,array $config): ?array {
    if (authenticated($config)) return admin_passkey_identity($config);
    $cookie=$_COOKIE['__Host-atlas-director']??'';
    if (!is_string($cookie)||!preg_match('/^([a-f0-9]{16})\.(\d{10})\.([a-f0-9]{64})$/D',$cookie,$m)||
        (int)$m[2]<=time()) return null;
    $account=director_lookup(finance_accounts($db),$m[1]);
    return $account&&hash_equals(director_ticket($config,$account,(int)$m[2]),$m[3])?$account:null;
}
function can_finance(?array $account,string $city): bool {
    return !empty($account['admin']) || in_array($account['city']??null,['*',$city],true);
}
function finance_login_page(array $site,string $city,string $notice='',int $status=200): void {
    $body='<main class="frame admin"><form class="admin-form admin-login" method="post" action="/admin/"><h1>Финансы филиалов</h1><p>Вход для директоров и администратора</p>'.
        ($notice?'<p class="admin-message" role="alert">'.h($notice).'</p>':'').
        '<input type="hidden" name="action" value="finance-login"><input type="hidden" name="city" value="'.h($city).'">'.
        '<label>Логин<input name="username" autocomplete="username" maxlength="40" required></label>'.
        '<label>Пароль<input name="password" type="password" autocomplete="current-password" maxlength="72" required></label>'.
        '<button class="copy">Войти с паролем</button><button class="copy" type="button" data-passkey="auth" data-passkey-city="'.h($city).'" hidden>Войти без пароля</button>'.
        '<p class="admin-message" data-passkey-status role="status" hidden></p>'.
        '<a class="back" href="'.($city?'/filial/'.h($city).'/':'/').'">Вернуться</a></form></main>';
    respond(page($site,'Вход в финансы',$body),$status);
}
function finance_route(PDO $db,array $site,array $config,array $cities,string $action): void {
    $raw=$_GET['city']??input('city');
    $city=is_string($raw)&&isset($cities[$raw])?$raw:'';
    if ($action==='finance-logout') {
        $access=finance_view($db,$config);
        if ($access) audit_auth($db,$access['login'],'logout','ok');
        director_cookie($config);set_session('',1);go(finance_href());
    }
    if ($action==='finance-login') {
        $login=strtolower(trim(input('username'))); $pass=input('password');
        if ($login==='administrator') {
            $status=admin_password_login($db,$config,$login,$pass);
            if ($status!==200) finance_login_page($site,$city,$status===429?'Слишком много попыток. Повторите через 15 минут.':'Неверный логин или пароль.',$status);
            passkey_after_password($db,admin_passkey_identity($config),'finance',$city);
        }
        $account=director_name($login)?(finance_accounts($db)[$login]??null):null;
        if ($account && !auth_rate($db,'director:'.$account['id'])) {
            audit_auth($db,$login,'password','rate');
            finance_login_page($site,$city,'Слишком много попыток. Повторите через 15 минут.',429);
        }
        if (!$account || ($account['city']!=='*'&&!isset($cities[$account['city']])) || strlen($pass)>72 ||
            !hash_equals($account['hash'],director_hash($pass,$account['salt']))) {
            audit_auth($db,audit_login_name($login),'password','denied');
            finance_login_page($site,$city,'Неверный логин или пароль.',401);
        }
        audit_auth($db,$login,'password','ok');
        director_cookie($config,$account);
        passkey_after_password($db,$account,'finance',$account['city']==='*'?$city:$account['city']);
    }
    $access=finance_view($db,$config);
    if (!$access) finance_login_page($site,$city);
    if ($city) go(passkey_next($access,'finance',$city));
    $links='';
    foreach ($cities as $id=>$item) if (can_finance($access,$id)) $links.='<a class="admin-city" href="/filial/'.h($id).'/">'.h($item['name']).'</a>';
    $phone='<section class="report finance-device" id="passkey-setup" hidden><h2>Вход с телефона</h2><p>Добавьте ключ входа на своём устройстве.</p><button class="copy" type="button" data-passkey="register" hidden>Настроить вход без пароля</button><p class="admin-message" data-passkey-status role="status" hidden></p></section>';
    respond(page($site,'Финансы филиалов','<main class="frame admin"><div class="admin-heading"><h1>Финансы филиалов</h1><form method="post" action="/admin/"><button class="copy" name="action" value="finance-logout">Выйти</button></form></div><nav class="admin-nav" aria-label="Филиалы с отчётами">'.$links.'</nav>'.$phone.'</main>'));
}
function finance_admin(PDO $db,array $site,array $cities,string $action): void {
    $before=(string)db_read($db,'directors');
    $accounts=json_decode($before,true)??[]; $error='';
    if ($action==='director-save'||$action==='director-revoke') {
        $login=strtolower(trim(input('director'))); $city=input('directorCity'); $pw=input('directorPassword');
        $name=trim(input('directorDisplayName'));
        if (!director_name($login)) $error='Проверьте логин филиала.';
        elseif ($action==='director-revoke') unset($accounts[$login]);
        elseif (($city!=='*'&&!isset($cities[$city])) || strlen($pw)<10 || strlen($pw)>72 || strlen($name)>160) $error='Проверьте область доступа, имя и длину пароля.';
        elseif (count($accounts)>=100 && !isset($accounts[$login])) $error='Достигнут лимит учётных записей.';
        else {
            $salt=bin2hex(random_bytes(16));
            $accounts[$login]=['id'=>bin2hex(random_bytes(8)),'city'=>$city,'name'=>$name,'salt'=>$salt,'hash'=>director_hash($pw,$salt)];
        }
        if (!$error) {
            if (kv_cas($db,'directors',$before,$accounts)) go('/admin/?next=finance-access&saved=1');
            $error='Список изменился. Обновите страницу и повторите.';
        }
    }
    $users=''; ksort($accounts); $options='';
    foreach ($accounts as $login=>$item) {
        $scope=$item['city']==='*'?'Все филиалы':($cities[$item['city']]['name']??'Без филиала');
        $label=($item['name']??'')?$item['name'].' · ':'';
        $users.='<form class="admin-heading" method="post" action="/admin/"><strong>'.h($label.$login.' · '.$scope).'</strong><input type="hidden" name="director" value="'.h($login).'"><button class="copy" name="action" value="director-revoke">Отозвать</button></form>';
    }
    foreach ($cities as $id=>$item) $options.='<option value="'.h($id).'">'.h($item['name']).'</option>';
    $notice=$error?: (isset($_GET['saved'])?'Доступ обновлён.':'');
    $html='<main class="frame admin"><a class="back" href="/admin/">К редактированию филиалов</a><h1>Доступ к финансам</h1>'.
        '<div class="admin-form"><p>Область доступа определяет, какие отчёты увидит пользователь. Для изменения доступа или пароля сохраните тот же логин.</p>'.$users.
        '<form class="finance-account" method="post" action="/admin/">'.
        ($notice?'<p class="admin-message" role="status">'.h($notice).'</p>':'').
        '<label>Область доступа<select name="directorCity" required><option value="">Выберите область</option><option value="*">Все филиалы</option>'.$options.'</select></label>'.
        '<label>Имя сотрудника<input name="directorDisplayName" maxlength="80"></label>'.
        '<label>Логин<input name="director" maxlength="40" placeholder="Латиницей" required></label>'.
        '<label>Пароль<input name="directorPassword" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></label>'.
        '<button class="copy" name="action" value="director-save">Сохранить доступ</button></form></div></main>';
    respond(page($site,'Доступ к финансам',$html),$error?400:200);
}
