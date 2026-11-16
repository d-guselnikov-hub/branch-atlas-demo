<?php
function branch_profile(array $city,array $config): array {
    return ['status'=>'ready','managers'=>[($city['manualManager']??'')?:'Демо-руководитель'],
        'photos'=>[],'staff'=>2,'team'=>[['name'=>'Демо-сотрудник А','photo'=>''],['name'=>'Демо-сотрудник Б','photo'=>'']]];
}
function google_budget(string $id): array {
    $site=json_decode(base64_decode(SITE_DATA),true,30,JSON_THROW_ON_ERROR);
    if (!isset($site['budgets'][$id])) throw new RuntimeException('Unknown demo report');
    return $site['budgets'][$id];
}
