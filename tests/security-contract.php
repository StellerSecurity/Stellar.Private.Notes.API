<?php
// Executes production middleware with in-memory framework/database doubles.
// Real Laravel/MySQL concurrency must also be verified before deployment.
namespace Audit {
    class Bag { public array $values=[]; function get($k,$d=null){return $this->values[$k]??$d;} function set($k,$v){$this->values[$k]=$v;} }
    class Response {
        function __construct(public string $body, public int $status=200, public array $headers=[]){}
        function getStatusCode(){return $this->status;}
        function getContent(){return $this->body;}
        function json($data,$status=200,$headers=[]){return new self(json_encode($data),$status,$headers);}
    }
    class Query {
        private string $key='';
        function where($field,$value){if($field!=='scope_key')throw new \Exception('Unscoped query'); $this->key=$value;return $this;}
        function insertOrIgnore($data){$k=$data['scope_key']; if(isset(\Illuminate\Support\Facades\DB::$rows[$k]))return 0; \Illuminate\Support\Facades\DB::$rows[$k]=(object)array_merge(['response_status'=>null,'response_body'=>null],$data);return 1;}
        function lockForUpdate(){return $this;}
        function first(){return \Illuminate\Support\Facades\DB::$rows[$this->key]??null;}
        function update($values){foreach($values as $k=>$v) \Illuminate\Support\Facades\DB::$rows[$this->key]->$k=$v;}
        function delete(){unset(\Illuminate\Support\Facades\DB::$rows[$this->key]);}
    }
}
namespace Illuminate\Http {
    class Request {
        public \Audit\Bag $attributes;
        function __construct(public array $data=[], public mixed $key=null, public ?string $username='expected', public ?string $password='expected-pass', public string $endpoint='api/v1/notecontroller/upload'){$this->attributes=new \Audit\Bag;}
        function input($key){return $this->data[$key]??null;}
        function getUser(){return $this->username;}
        function getPassword(){return $this->password;}
        function header($key){return $this->key;}
        function all(){return $this->data;}
        function method(){return 'POST';}
        function path(){return $this->endpoint;}
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        public static array $rows=[];
        static function table($name){if($name!=='notes_upload_receipts')throw new \Exception('Legacy receipt table accessed');return new \Audit\Query;}
        static function transaction($fn){$saved=unserialize(serialize(self::$rows));try{return $fn();}catch(\Throwable $e){self::$rows=$saved;throw $e;}}
    }
    class Route {
        public static array $routes=[];
        public static array $stack=[];
        static function middleware($class){return new class($class){function __construct(private $class){}function group($fn){Route::$stack[]=$this->class;$fn();array_pop(Route::$stack);}};}
        static function post($path,$handler){$i=count(self::$routes);self::$routes[]=[$path,self::$stack];return new class($i){function __construct(private $i){}function middleware($class){Route::$routes[$this->i][1][]=$class;return $this;}};}
    }
}
namespace {
    $settings=['notes_service.username'=>'expected','notes_service.password'=>'expected-pass'];
    function config($key){global $settings;return $settings[$key]??null;}
    function response($body='', $status=200, $headers=[]){return new \Audit\Response($body,$status,$headers);}
    function check($name,$pass){if(!$pass)throw new \RuntimeException($name);echo "PASS: $name\n";}
    require __DIR__.'/../app/Http/Middleware/BasicAuthentication.php';
    require __DIR__.'/../app/Http/Middleware/Idempotency.php';
    require __DIR__.'/../routes/api.php';
    $auth=new \App\Http\Middleware\BasicAuthentication;
    $idempotency=new \App\Http\Middleware\Idempotency;
    $next=fn($r)=>response()->json(['ok'=>true]);
    foreach([[null,null],['wrong','wrong'],['expected','wrong'],['wrong','expected-pass'],['','']] as [$u,$p]){
        check('invalid service credentials denied', $auth->handle(new \Illuminate\Http\Request(['user_id'=>42],null,$u,$p),$next)->status===401);
    }
    $request=new \Illuminate\Http\Request(['user_id'=>'42']);
    check('existing Basic Auth and string user identity supported',$auth->handle($request,$next)->status===200 && $request->attributes->get('auth_user_id')===42);
    foreach([null,0,-1,[],true,'1 OR 1=1'] as $id)check('malformed identity denied',$auth->handle(new \Illuminate\Http\Request(['user_id'=>$id]),$next)->status===422);
    $settings['notes_service.password']='';
    check('missing server configuration fails closed',$auth->handle($request,$next)->status===503);
    $settings['notes_service.password']='expected-pass';
    check('all four Notes routes protected',count(\Illuminate\Support\Facades\Route::$routes)===4 && count(array_filter(\Illuminate\Support\Facades\Route::$routes,fn($r)=>$r[1][0]===\App\Http\Middleware\BasicAuthentication::class))===4);
    $upload=array_values(array_filter(\Illuminate\Support\Facades\Route::$routes,fn($r)=>str_ends_with($r[0],'/upload')))[0];
    check('authentication precedes upload receipts',$upload[1]===[\App\Http\Middleware\BasicAuthentication::class,\App\Http\Middleware\Idempotency::class]);
    $calls=0;
    $handler=function($r)use(&$calls){$calls++;return response()->json($r->input('require_note_ack') ? ['ok'=>true,'note_ack_v1'=>true] : ['ok'=>true]);};
    $run=function($data,$key=null,$endpoint='api/v1/notecontroller/upload')use($auth,$idempotency,$handler){return $auth->handle(new \Illuminate\Http\Request($data,$key,endpoint:$endpoint),fn($r)=>$idempotency->handle($r,$handler));};
    $data=['user_id'=>42,'notes'=>[['id'=>'same','text'=>'cipher','last_modified'=>1]]];
    $run($data);$run($data);
    check('legacy requests without header bypass receipts',$calls===2 && count(\Illuminate\Support\Facades\DB::$rows)===0);
    $a=$run($data,'shared');$b=$run($data,'shared');
    check('retry replays original legacy response exactly',$calls===3 && $a->body===$b->body && $b->status===200);
    $run(array_replace($data,['user_id'=>43]),'shared');
    check('same key in other account does not suppress upload',$calls===4);
    $run($data,'shared','different-path');
    check('keys isolated by endpoint',$calls===5);
    $changed=$data;$changed['notes'][0]['text']='other';
    check('same key with different content conflicts',$run($changed,'shared')->status===409 && $calls===5);
    $reordered=['notes'=>$data['notes'],'user_id'=>42];
    check('JSON object order does not cause conflicts',$run($reordered,'shared')->status===200 && $calls===5);
    $modern=array_replace($data,['require_note_ack'=>true]);
    $run($modern,'modern');$replay=$run($modern,'modern');
    check('modern acknowledgment preserved on retry',json_decode($replay->body,true)===['ok'=>true,'note_ack_v1'=>true] && $calls===6);
    $request=new \Illuminate\Http\Request($data,'failed');$request->attributes->set('auth_user_id',42);
    $idempotency->handle($request,fn()=>response()->json(['error'=>true],500));
    $before=$calls;$run($data,'failed');
    check('failure is retryable',$calls===$before+1);
    $request->key='throws';$rows=count(\Illuminate\Support\Facades\DB::$rows);
    try{$idempotency->handle($request,fn()=>throw new \RuntimeException('simulated failure'));}catch(\RuntimeException){}
    check('exception rolls back receipt',count(\Illuminate\Support\Facades\DB::$rows)===$rows);
    check('receipt requires trusted identity',$idempotency->handle(new \Illuminate\Http\Request($data,'x'),$handler)->status===401);
    check('oversized keys rejected',$run($data,str_repeat('x',256))->status===422);
}
