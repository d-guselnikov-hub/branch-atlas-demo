<?php

function ensure_audit(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS auth_audit (
        id INTEGER PRIMARY KEY, happened INTEGER NOT NULL, login TEXT NOT NULL, method TEXT NOT NULL,
        result TEXT NOT NULL, ip TEXT NOT NULL, agent TEXT NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS auth_audit_time ON auth_audit(happened)');
}
function audit_auth(PDO $db,string $login,string $method,string $result): void {
    ensure_audit($db);
    $ip=$_SERVER['REMOTE_ADDR']??'';
    $agent=preg_replace('/[\x00-\x1f\x7f]/u',' ',substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,400))??'';
    $write=$db->prepare('INSERT INTO auth_audit(happened,login,method,result,ip,agent) VALUES (?,?,?,?,?,?)');
    $write->execute([time(),$login,$method,$result,filter_var($ip,FILTER_VALIDATE_IP)?$ip:'',$agent]);
    if (random_int(1,30)===1) {
        $prune=$db->prepare('DELETE FROM auth_audit WHERE happened < ?');
        $prune->execute([time()-90*86400]);
    }
}
function audit_login_name(string $login): string {
    $login=strtolower(trim($login));
    return preg_match('/^[a-z][a-z0-9._-]{2,39}$/D',$login)?$login:'';
}
function audit_status(int $status): string {
    return $status===200?'ok':($status===429?'rate':'denied');
}
function audit_location_reader(): ?\MaxMind\Db\Reader {
    $directory=__DIR__.'/atlas-geoip';
    $archive=$directory.'/dbip-city.mmdb.gz';
    if (!is_readable($archive) || !is_readable($directory.'/autoload.php')) return null;
    $database=dirname(__DIR__).'/.atlas-dbip-city-2026-11.mmdb';
    if (!is_readable($database)) {
        $lock=fopen($database.'.lock','c');
        if (!$lock) return null;
        try {
            if (!flock($lock,LOCK_EX)) return null;
            clearstatcache(true,$database);
            if (!is_readable($database)) {
                $temp=tempnam(dirname($database),'.atlas-geo-');
                if ($temp===false) return null;
                try {
                    $input=fopen('compress.zlib://'.$archive,'rb');
                    $output=fopen($temp,'wb');
                    try {
                        if (!$input||!$output||stream_copy_to_stream($input,$output,126873351)!==126873350) return null;
                    } finally {
                        if ($input) fclose($input);
                        if ($output) fclose($output);
                    }
                    if (!rename($temp,$database)) return null;
                } finally {
                    if (is_file($temp)) unlink($temp);
                }
            }
        } finally {
            fclose($lock);
        }
    }
    require_once $directory.'/autoload.php';
    return new \MaxMind\Db\Reader($database);
}
function audit_ip_location(?\MaxMind\Db\Reader $reader,string $ip): string {
    if (!$reader||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return '—';
    try {
        $location=$reader->get($ip);
        if (!is_array($location)) return '—';
        $city=$location['city']['names']['ru']??$location['city']['names']['en']??
            $location['subdivisions'][0]['names']['ru']??$location['subdivisions'][0]['names']['en']??'';
        $country=$location['country']['names']['ru']??$location['country']['names']['en']??'';
        return implode(', ',array_unique(array_filter([$city,$country])))?:'—';
    } catch (Throwable $error) {
        return '—';
    }
}
function audit_device(string $agent): string {
    return match (true) {
        preg_match('/\biPhone\b/i',$agent)===1 => 'Apple iPhone · iOS',
        preg_match('/\biPad\b/i',$agent)===1 => 'Apple iPad · iPadOS',
        stripos($agent,'Android')!==false => audit_android_device($agent),
        stripos($agent,'Windows Phone')!==false => 'Телефон · Windows Phone',
        stripos($agent,'Windows NT')!==false => 'Компьютер · Windows',
        stripos($agent,'CrOS')!==false => 'Компьютер · ChromeOS',
        stripos($agent,'Macintosh')!==false => 'Apple Mac · macOS',
        stripos($agent,'Linux')!==false => 'Компьютер · Linux',
        stripos($agent,'Mobile')!==false => 'Телефон',
        default => 'Не определено'
    };
}
function audit_android_device(string $agent): string {
    $type=stripos($agent,'Mobile')!==false?'Телефон':'Планшет';
    if (preg_match('~Android[^;)]*;\s*([^;)]+?)\s+Build/~i',$agent,$matches)) {
        return $type.' · Android · '.trim($matches[1]);
    }
    return $type.' · Android';
}
function audit_browser(string $agent): string {
    foreach (['(?:Edg|EdgiOS|EdgA)'=>'Edge','(?:OPR|Opera)'=>'Opera','SamsungBrowser'=>'Samsung Internet',
        '(?:Firefox|FxiOS)'=>'Firefox','(?:Chrome|CriOS)'=>'Chrome','Version'=>'Safari'] as $pattern=>$name) {
        if (preg_match('~'.$pattern.'/([0-9]+)~i',$agent,$matches)) return $name.' '.$matches[1];
    }
    return 'Подробнее';
}
function audit_page(PDO $db,array $site): void {
    ensure_audit($db);
    $filter=audit_login_name(is_string($_GET['account']??null)?$_GET['account']:'');
    $before=filter_var($_GET['before']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $clause=['happened >= ?']; $params=[time()-90*86400];
    if ($before) { $clause[]='id < ?'; $params[]=$before; }
    if ($filter) { $clause[]='login = ?'; $params[]=$filter; }
    $query=$db->prepare('SELECT id,happened,login,method,result,ip,agent FROM auth_audit WHERE '.implode(' AND ',$clause).' ORDER BY id DESC LIMIT 101');
    $query->execute($params);
    $records=$query->fetchAll(PDO::FETCH_ASSOC);
    $more=count($records)>100;
    if ($more) array_pop($records);
    $timezone=new DateTimeZone('Asia/Yekaterinburg');
    $methods=['password'=>'Пароль','passkey'=>'Ключ входа','logout'=>'Выход'];
    $results=['ok'=>'Успешно','denied'=>'Отказано','rate'=>'Лимит попыток'];
    try { $reader=$records?audit_location_reader():null; }
    catch (Throwable $error) { error_log('Atlas IP location database unavailable');$reader=null; }
    $locations=[];
    $rows='';
    foreach ($records as $event) {
        $time=(new DateTimeImmutable('@'.$event['happened']))->setTimezone($timezone)->format('d.m.Y H:i:s');
        $ip=$event['ip'];
        $location=$locations[$ip]??=audit_ip_location($reader,$ip);
        $agent=$event['agent'];
        $browser=$agent?'<details><summary>'.h(audit_browser($agent)).'</summary><p>'.h($agent).'</p></details>':'—';
        $rows.='<tr><td>'.h($time).'</td><th scope="row">'.h($event['login']?:'—').'</th>'.
            '<td>'.h($methods[$event['method']]??$event['method']).'</td><td>'.h($results[$event['result']]??$event['result']).'</td>'.
            '<td>'.h($ip?:'—').'</td><td class="audit-location">'.h($location).'</td>'.
            '<td>'.h(audit_device($agent)).'</td><td class="audit-agent">'.$browser.'</td></tr>';
    }
    $older=$more?'<a class="copy" href="/admin/?'.h(http_build_query(['next'=>'audit','account'=>$filter,'before'=>end($records)['id']])).'">Более ранние записи</a>':'';
    $body='<main class="frame admin auth-audit"><a class="back" href="/admin/">← В админку</a><h1>Журнал входов</h1>'.
        '<p>Время UTC+5. Здесь показаны события за последние 90 дней. Местоположение определено приблизительно по IP. <a class="back" href="https://db-ip.com/" rel="noopener noreferrer">IP Geolocation by DB-IP</a></p>'.
        '<form action="/admin/" method="get" class="audit-filter"><input type="hidden" name="next" value="audit">'.
        '<label>Аккаунт <input name="account" value="'.h($filter).'" maxlength="40" placeholder="Все аккаунты"></label>'.
        '<button class="copy">Найти</button><a class="back" href="/admin/?next=audit">Сбросить</a></form>'.
        ($rows?'<div class="table-wrap report"><table><thead><tr><th scope="col">Время</th><th scope="col">Аккаунт</th>'.
            '<th scope="col">Способ</th><th scope="col">Результат</th><th scope="col">IP-адрес</th>'.
            '<th scope="col">Примерное местоположение</th><th scope="col">Устройство</th><th scope="col">Браузер</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'.$older:
            '<div class="no-data">Записей пока нет.</div>').'</main>';
    respond(page($site,'Журнал входов',$body));
}
