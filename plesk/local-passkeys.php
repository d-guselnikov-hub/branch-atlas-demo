<?php

define('PASSKEY_RP',(string)parse_url(SITE_ORIGIN,PHP_URL_HOST));
function admin_passkey_identity(array $config): array {
    $id=substr(hash_hmac('sha256','admin-passkey:'.$config['password'],$config['sessionKey']),0,16);
    return ['admin'=>true,'id'=>$id,'login'=>'administrator'];
}
function passkey_next(array $account,string $entry,string $city): string {
    if (isset($account['city']) && $account['city']!=='*') return '/filial/'.$account['city'].'/';
    if ($entry==='admin') return '/admin/';
    return safe_city($city)?'/filial/'.$city.'/':finance_href();
}
function passkey_after_password(PDO $db,array $account,string $entry,string $city): void {
    $registered=json_decode((string)db_read($db,'passkeys:'.$account['id']),true);
    if (!$registered && ($_COOKIE['__Host-atlas-passkey-later']??'')!==$account['id']) {
        go('/admin/?'.http_build_query(['next'=>'passkey-setup','entry'=>$entry,'city'=>$city]));
    }
    go(passkey_next($account,$entry,$city));
}
function enrollment_page(PDO $db,array $site,array $config,array $cities,bool $defer): void {
    $account=finance_view($db,$config);
    if (!$account) go(finance_href());
    $entry=($defer?input('entry'):($_GET['entry']??''))==='admin'?'admin':'finance';
    $raw=$defer?input('city'):($_GET['city']??'');
    $city=is_string($raw)&&isset($cities[$raw])?$raw:'';
    $next=passkey_next($account,$entry,$city);
    if ($defer) {
        setcookie('__Host-atlas-passkey-later',$account['id'],['expires'=>time()+31536000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        go($next);
    }
    $body='<main class="frame admin"><section class="admin-form admin-login"><h1>Настройте вход без пароля</h1>'.
        '<p>Подтвердите создание ключа отпечатком, Face ID или кодом экрана. Подтверждение проходит на вашем устройстве.</p>'.
        '<button class="copy" type="button" data-passkey="register" data-passkey-return="'.h($next).'" hidden>Подключить ключ входа</button>'.
        '<p class="admin-message" data-passkey-status role="status" hidden></p>'.
        '<form method="post" action="/admin/"><input type="hidden" name="entry" value="'.h($entry).'"><input type="hidden" name="city" value="'.h($city).'">'.
        '<button type="submit" class="copy" name="action" value="passkey-defer">Позже</button></form></section></main>';
    respond(page($site,'Вход без пароля',$body));
}
function b64u(string $bytes): string {return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
function unb64u($value): string {
    if (!is_string($value)||!preg_match('/^[a-zA-Z0-9_-]+$/D',$value)) throw new InvalidArgumentException('Invalid webauthn field');
    $bytes=base64_decode(strtr($value,'-_','+/'),true);
    if ($bytes===false||b64u($bytes)!==$value) throw new InvalidArgumentException('Invalid base64url');
    return $bytes;
}
function passkey_vendor(): \lbuchs\WebAuthn\WebAuthn {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    return new \lbuchs\WebAuthn\WebAuthn('Branch Atlas',PASSKEY_RP,['none'],true);
}
function passkey_mac(array $config,string $mode,string $challenge,string $id,int $expires): string {
    return hash_hmac('sha256',enc(['passkey',$mode,$challenge,$id,$expires]),$config['sessionKey']);
}
function passkey_issue(array $config,object $options,string $mode,string $id=''): void {
    $expires=time()+150;
    $challenge=(string)$options->challenge->jsonSerialize();
    json_reply(['options'=>$options,'ticket'=>$expires.'.'.passkey_mac($config,$mode,$challenge,$id,$expires)]);
}
function passkey_unpack(array $config,string $mode,string $id=''): array {
    $token=input('ticket'); $challenge=input('challenge');
    if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/D',$token,$parts) || strlen($challenge)!==43 ||
        (int)$parts[1]<time()||(int)$parts[1]>time()+150||
        !hash_equals(passkey_mac($config,$mode,$challenge,$id,(int)$parts[1]),$parts[2])) throw new InvalidArgumentException('Invalid challenge');
    $credential=json_decode(input('credential'),true,14,JSON_THROW_ON_ERROR);
    $reply=$credential['response']??null; $keyId=$credential['id']??null;
    if (!is_string($keyId)||strlen($keyId)>1024||!is_array($reply)||($credential['rawId']??'')!==$keyId||($credential['type']??'')!=='public-key') {
        throw new InvalidArgumentException('Invalid passkey response');
    }
    unb64u($keyId);
    $client=unb64u($reply['clientDataJSON']??'');
    $payload=json_decode($client,true,10,JSON_THROW_ON_ERROR);
    if (($payload['origin']??'')!==SITE_ORIGIN || !empty($payload['crossOrigin'])) throw new InvalidArgumentException('Invalid webauthn origin');
    return [$challenge,(int)$parts[1],$keyId,$reply,$client];
}
function passkey_import(string $credential,string $cose): string {
    passkey_vendor();
    $id=unb64u($credential);
    $authData=hash('sha256',PASSKEY_RP,true)."\x45".pack('N',0).str_repeat("\0",16).pack('n',strlen($id)).$id.unb64u($cose);
    return (new \lbuchs\WebAuthn\Attestation\AuthenticatorData($authData))->getPublicKeyPem();
}
function passkey_route(PDO $db,array $config,string $action): void {
    $lib=passkey_vendor();
    if ($action==='passkey-auth-start') passkey_issue($config,$lib->getGetArgs([],90,false,false,false,true,true,true)->publicKey,'auth');
    if ($action==='passkey-register-start'||$action==='passkey-register-finish') {
        $user=finance_view($db,$config);
        if (!$user||empty($user['id'])) json_reply(['status'=>'authentication-required'],401);
        $id=$user['id']; $row='passkeys:'.$id;
        $before=(string)db_read($db,$row); $items=json_decode($before,true)??[];
        if (count($items)>=10) json_reply(['status'=>'limit'],409);
        if ($action==='passkey-register-start') {
            $exclude=array_map(static fn($item)=>unb64u($item['id']),$items);
            $options=$lib->getCreateArgs($id,$user['login'],$user['login'],90,true,true,false,$exclude)->publicKey;
            passkey_issue($config,$options,'register',$id);
        }
        [$challenge,,$keyId,$reply,$client]=passkey_unpack($config,'register',$id);
        $out=$lib->processCreate($client,unb64u($reply['attestationObject']??''),unb64u($challenge),true,true);
        if (!hash_equals(b64u($out->credentialId),$keyId)) json_reply(['status'=>'invalid'],400);
        foreach ($items as $item) if (hash_equals($item['id'],$keyId)) json_reply(['status'=>'invalid'],400);
        $items[]=['id'=>$keyId,'pem'=>$out->credentialPublicKey,'counter'=>$out->signatureCounter??0,'used'=>[]];
        if (!kv_cas($db,$row,$before,$items)) json_reply(['status'=>'busy'],409);
        json_reply(['status'=>'registered']);
    }
    if ($action==='passkey-auth-finish') {
        [$challenge,$expires,$keyId,$reply,$client]=passkey_unpack($config,'auth');
        $id=unb64u($reply['userHandle']??'');
        $admin=admin_passkey_identity($config);
        $entry=input('entry');
        $account=hash_equals($admin['id'],$id)?$admin:
            (preg_match('/^[a-f0-9]{16}$/D',$id)?director_lookup(finance_accounts($db),$id):null);
        if (!$account || ($entry==='admin'&&empty($account['admin']))) passkey_denied($db);
        $row='passkeys:'.$id; $before=(string)db_read($db,$row);
        $items=json_decode($before,true)??[];
        foreach ($items as $idx=>$item) {
            if (!hash_equals($item['id'],$keyId)) continue;
            $used=array_values(array_filter($item['used']??[],static fn($pair)=>$pair[1]>=time()));
            if (count($used)>=40) passkey_denied($db);
            foreach ($used as $pair) if (hash_equals($pair[0],$challenge)) passkey_denied($db);
            if (!$lib->processGet($client,unb64u($reply['authenticatorData']??''),unb64u($reply['signature']??''),
                $item['pem'],unb64u($challenge),(int)$item['counter'],true,true)) passkey_denied($db);
            $items[$idx]['counter']=$lib->getSignatureCounter()??0;
            $used[]=[$challenge,$expires]; $items[$idx]['used']=$used;
            if (!kv_cas($db,$row,$before,$items)) json_reply(['status'=>'busy'],409);
            audit_auth($db,$account['login'],'passkey','ok');
            if (!empty($account['admin'])) start_admin_session($config);
            else director_cookie($config,$account);
            json_reply(['next'=>passkey_next($account,$entry,input('city'))]);
        }
        passkey_denied($db);
    }
    json_reply(['status'=>'not-found'],404);
}
function passkey_denied(PDO $db): void {
    audit_auth($db,'','passkey','denied');
    json_reply(['status'=>'invalid'],401);
}
