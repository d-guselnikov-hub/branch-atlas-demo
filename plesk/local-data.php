<?php
declare(strict_types=1);

const SITE_DATA = '@@SITE_BUNDLE@@';
define('SITE_ORIGIN',getenv('DEMO_ORIGIN') ?: 'http://localhost:8080');
function enc($value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function lower(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value); }
function is_demo_loopback(): bool {
    return PHP_SAPI==='cli-server' && in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) &&
        in_array(parse_url(SITE_ORIGIN,PHP_URL_HOST),['localhost','127.0.0.1'],true);
}
function demo_database_path(): string {
    $dir=dirname(__DIR__).'/var';
    if (!is_dir($dir)) mkdir($dir,0700,true);
    return $dir.'/branch-atlas.sqlite';
}
