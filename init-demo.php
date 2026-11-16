<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/public/index.php';
$db=db_open(demo_database_path());
if (db_read($db,'config')) { fwrite(STDERR,"Demo already initialized.
"); exit(1); }
$password=rtrim((string)fgets(STDIN),"
");
db_init($db,['password'=>$password]);
echo "Demo account initialized. Login: administrator
";
