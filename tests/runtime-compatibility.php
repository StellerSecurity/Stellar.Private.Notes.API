<?php
/** Real Laravel/SQLite smoke test. No PHPUnit or production data required. */
if (PHP_SAPI !== 'cli' || getenv('STELLAR_RUNTIME_TEST') !== '1') {
    fwrite(STDERR, "Run only in an explicitly isolated runtime-test process.\n"); exit(2);
}
$root = dirname(__DIR__);
$scratch = sys_get_temp_dir().'/stellar-notes-runtime-'.bin2hex(random_bytes(12));
if (!mkdir($scratch, 0700)) { fwrite(STDERR, "Cannot create isolated test directory.\n"); exit(1); }
foreach (['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $dir) {
    if (!mkdir($scratch.'/'.$dir, 0700, true)) { fwrite(STDERR, "Cannot create isolated test storage.\n"); exit(1); }
}
$environment = [
    'APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'APP_KEY'=>'base64:'.base64_encode(str_repeat('k',32)),
    'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>':memory:', 'CACHE_STORE'=>'array',
    'SESSION_DRIVER'=>'array', 'LOG_CHANNEL'=>'null', 'QUEUE_CONNECTION'=>'sync',
    'APP_CONFIG_CACHE'=>$scratch.'/config.php', 'APP_SERVICES_CACHE'=>$scratch.'/services.php',
    'APP_PACKAGES_CACHE'=>$scratch.'/packages.php', 'APP_ROUTES_CACHE'=>$scratch.'/routes.php',
    'APP_EVENTS_CACHE'=>$scratch.'/events.php',
];
foreach ($environment as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
$checks=0;
class RuntimeCheckFailure extends RuntimeException {}
function checkRuntime(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeCheckFailure($label);
    ++$checks; echo 'PASS runtime: '.$label."\n";
}
try {
    require $root.'/vendor/autoload.php';
    $app=require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath($scratch);
    $app->useStoragePath($scratch.'/storage');
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
        'notes_service.username'=>'runtime-test-user','notes_service.password'=>'runtime-test-password',
        'notes_conflicts.enabled'=>false]);
    Illuminate\Support\Facades\DB::purge('sqlite');
    checkRuntime(Illuminate\Support\Facades\DB::connection()->getDatabaseName()===':memory:', 'database is isolated in memory');
    Illuminate\Support\Facades\Schema::create('notes', function ($t) {
        $t->id(); $t->unsignedBigInteger('user_id'); $t->string('note_id');
        $t->text('title')->default(''); $t->text('text')->default(''); $t->unsignedBigInteger('last_modified');
        foreach (['protected','auto_wipe','deleted','pinned','favorite'] as $flag) $t->boolean($flag)->default(false);
        $t->string('checksum_hmac')->nullable(); $t->string('folder_id')->nullable(); $t->string('folder')->nullable();
        $t->timestamps(); $t->unique(['user_id','note_id']);
    });
    Illuminate\Support\Facades\Schema::create('folders', function ($t) {
        $t->id(); $t->unsignedBigInteger('user_id'); $t->string('folder_id'); $t->string('name');
        $t->unsignedBigInteger('last_modified'); $t->boolean('deleted')->default(false); $t->timestamps();
        $t->unique(['user_id','folder_id']);
    });
    $call=function(string $action,array $data=[],bool $auth=true) use ($kernel): array {
        $server=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'];
        if($auth){$server['PHP_AUTH_USER']='runtime-test-user';$server['PHP_AUTH_PW']='runtime-test-password';}
        $request=Illuminate\Http\Request::create('/api/v1/notecontroller/'.$action,'POST',[],[],[],$server,json_encode(array_replace(['user_id'=>'11'],$data)));
        $response=$kernel->handle($request);
        return [$response->getStatusCode(),json_decode($response->getContent(),true)];
    };
    foreach(['upload','download','find','sync-plan'] as $action) checkRuntime($call($action,[],false)[0]===401,'unauthenticated '.$action.' denied');
    $note=['id'=>'runtime-note','title'=>'Encrypted title','text'=>"  cipher\nline two\nline three  ",'last_modified'=>100,'favorite'=>true];
    checkRuntime($call('upload',['notes'=>[$note]])===[200,['ok'=>true]],'legacy upload without new migration retains response');
    [$status,$download]=$call('download',['since'=>999999]);
    checkRuntime($status===200 && $download['notes'][0]['text']===$note['text'],'legacy download preserves whitespace and line breaks');
    checkRuntime($call('download',['user_id'=>'12'])===[200,['notes'=>[],'folders'=>[]]],'second account cannot read first account');
    $legacy=['id'=>'runtime-note','text'=>'edited','last_modified'=>200];
    checkRuntime($call('upload',['notes'=>[$legacy]])[0]===200,'legacy edit without newer fields accepted');
    checkRuntime($call('download')[1]['notes'][0]['favorite']===true,'legacy edit preserves favorite');
    checkRuntime($call('upload',['notes'=>[$note]])===[200,['ok'=>true]],'stale legacy retry retains old response');
    checkRuntime($call('download')[1]['notes'][0]['text']==='edited','stale retry does not replace current text');
    (require $root.'/database/migrations/2026_09_22_000000_add_edit_session_to_notes.php')->up();
    config(['notes_conflicts.enabled'=>true]);
    $modern=['id'=>'runtime-note','text'=>'device A','last_modified'=>300,'base_version'=>200,'edit_session'=>'desktop-session-0001'];
    checkRuntime($call('upload',['notes'=>[$modern],'require_note_ack'=>true])===[200,['ok'=>true,'note_ack_v1'=>true]],'modern opt-in write accepted');
    foreach(['desktop-session-0002','mobile-session-00001','mobile-session-00002'] as $device) {
        $conflict=array_replace($modern,['text'=>'stale device','last_modified'=>900000,'edit_session'=>$device]);
        checkRuntime($call('upload',['notes'=>[$conflict],'require_note_ack'=>true])[0]===409,'stale device cannot replace selected version: '.$device);
    }
    $delete=['id'=>'runtime-note','text'=>'','last_modified'=>400,'deleted'=>true];
    checkRuntime($call('upload',['notes'=>[$delete]])===[200,['ok'=>true]],'old client can delete guarded note');
    checkRuntime($call('upload',['notes'=>[array_replace($legacy,['last_modified'=>900001])]])===[200,['ok'=>true]],'legacy replay keeps legacy response');
    checkRuntime($call('download')[1]['notes'][0]['deleted']===true,'offline replay does not resurrect deleted note');
    $bulk=[];$seed=[];
    for($i=0;$i<831;$i++) {
        $bulk[]=['id'=>'bulk-'.$i,'text'=>'synthetic','last_modified'=>100];
        $seed[]=['user_id'=>21,'note_id'=>'bulk-'.$i,'title'=>'','text'=>'synthetic','last_modified'=>100];
    }
    Illuminate\Support\Facades\DB::table('notes')->insert($seed);
    $reads=0;
    Illuminate\Support\Facades\DB::listen(function($q)use(&$reads){if(str_starts_with(strtolower($q->sql),'select')&&str_contains($q->sql,'notes'))$reads++;});
    checkRuntime($call('upload',['user_id'=>21,'notes'=>$bulk])===[200,['ok'=>true]],'831-note unchanged legacy upload accepted');
    checkRuntime($reads===1,'831-note upload uses one note lookup instead of 831');
    $duplicates=[['id'=>'duplicate-batch','text'=>'first','last_modified'=>100],['id'=>'duplicate-batch','text'=>'second','last_modified'=>200],['id'=>'duplicate-batch','text'=>'stale','last_modified'=>150]];
    checkRuntime($call('upload',['user_id'=>21,'notes'=>$duplicates])[0]===200,'duplicate IDs within legacy batch accepted');
    checkRuntime(App\Models\Note::where('user_id',21)->where('note_id','duplicate-batch')->count()===1,'duplicate batch creates one row');
    checkRuntime(App\Models\Note::where('user_id',21)->where('note_id','duplicate-batch')->first()->text==='second','duplicate batch preserves newest version');
    echo 'Runtime compatibility: '.$checks.' checks passed; PHP '.PHP_VERSION.'; Laravel '.$app->version()."\n";
} catch (Throwable $e) {
    // No request payloads, environment values or remote bodies in failure output.
    fwrite(STDERR,'Runtime compatibility failed: '.($e instanceof RuntimeCheckFailure ? $e->getMessage() : get_class($e).' at '.basename($e->getFile()).':'.$e->getLine())."\n");
    $failed=true;
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry) { if($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($scratch);
}
exit(isset($failed)?1:0);
