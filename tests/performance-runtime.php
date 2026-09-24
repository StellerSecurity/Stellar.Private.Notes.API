<?php
// Real Laravel events and middleware; synthetic in-memory DB, no application credentials.
$root=dirname(__DIR__);
require $argv[1] ?? $root.'/vendor/autoload.php';
require $root.'/app/Support/PerformanceMeter.php';
require $root.'/app/Http/Middleware/MeasurePerformance.php';
require $root.'/app/Providers/PerformanceServiceProvider.php';
require $root.'/app/Console/Commands/NotesPerformanceReport.php';
$scratch=sys_get_temp_dir().'/notes-metrics-test-'.bin2hex(random_bytes(8));
mkdir($scratch.'/logs',0700,true);
try {
 $app=new Illuminate\Foundation\Application($root);
 $app->useStoragePath($scratch);
 $app->instance('config',new Illuminate\Config\Repository([
   'app'=>['env'=>'testing'], 'logging'=>['default'=>'null','channels'=>['null'=>['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class]]],
   'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
 ]));
 Illuminate\Support\Facades\Facade::setFacadeApplication($app);
 $app->register(Illuminate\Events\EventServiceProvider::class);
 $app->register(Illuminate\Log\LogServiceProvider::class);
 $app->register(Illuminate\Database\DatabaseServiceProvider::class);
 $app->register(App\Providers\PerformanceServiceProvider::class);
 $app->boot();
 $middleware=$app->make(App\Http\Middleware\MeasurePerformance::class);
 $response=new Symfony\Component\HttpFoundation\JsonResponse(['notes'=>[],'folders'=>[]]);
 $request=Illuminate\Http\Request::create('/api/v1/notescontroller/download?secret=HIDDEN_TOKEN','POST',['text'=>'HIDDEN_NOTE']);
 $actual=$middleware->handle($request,function()use($response){
   Illuminate\Support\Facades\DB::select('SELECT ? AS value',['HIDDEN_BINDING']);
   $request=new Illuminate\Http\Client\Request(new GuzzleHttp\Psr7\Request('POST','https://synthetic.invalid/?token=HIDDEN_TOKEN'));
   $upstream=new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(503,[],'HIDDEN_BODY'));
   Illuminate\Support\Facades\Event::dispatch(new Illuminate\Http\Client\Events\ResponseReceived($request,$upstream));
   return $response;
 });
 if($actual!==$response)throw new RuntimeException('Response modified');
 $files=glob($scratch.'/logs/notes-performance-*.log');
 if(count($files)!==1)throw new RuntimeException('Metrics log missing');
 $log=file_get_contents($files[0]);
 foreach(['HIDDEN_TOKEN','HIDDEN_NOTE','HIDDEN_BINDING','HIDDEN_BODY','synthetic.invalid','SELECT']as$private){
   if(str_contains($log,$private))throw new RuntimeException('Private content leaked');
 }
 if(!str_contains($log,'"db_count":1')||!str_contains($log,'"upstream_failures":1')||!str_contains($log,'"event":"finish"'))throw new RuntimeException('Events not measured');
 $command=new App\Console\Commands\NotesPerformanceReport();
 $command->setLaravel($app);
 $tester=new Symfony\Component\Console\Tester\CommandTester($command);
 $tester->execute(['--minutes'=>15]);
 $report=json_decode($tester->getDisplay(),true);
 if(($report['completed']??0)!==1||($report['upstream_failures']??0)!==1)throw new RuntimeException('Report counters incorrect');
 $tester->execute(['--minutes'=>0]);
 if($tester->getStatusCode()!==1)throw new RuntimeException('Invalid window accepted');
 echo "Performance runtime: real DB/HTTP events recorded; original response preserved; private data excluded\n";
}finally{
 foreach(glob($scratch.'/logs/*')as$file)unlink($file);
 rmdir($scratch.'/logs');rmdir($scratch);
}
