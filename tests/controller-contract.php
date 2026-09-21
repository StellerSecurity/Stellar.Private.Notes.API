<?php
// Execute the production controller with an in-memory Eloquent test double.
// No network, credentials, Laravel runtime or database is used by this test.
namespace Contract {
    class Rows implements \IteratorAggregate {
        public function __construct(public array $items) {}
        public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
        public function unique($field) { $seen=[]; return new self(array_values(array_filter($this->items, function($r) use (&$seen,$field) { if(isset($seen[$r->$field])) return false; return $seen[$r->$field]=true; }))); }
        public function modelKeys() { return array_map(fn($r)=>$r->id,$this->items); }
        public function filter($fn) { return new self(array_values(array_filter($this->items,$fn))); }
        public function map($fn) { return new self(array_map($fn,$this->items)); }
        public function values() { return array_values($this->items); }
    }
    class Query {
        private array $filters=[]; private array $orders=[]; private $folderOwner=null;
        public function __construct(private string $model) {}
        public function where($field,$value) { $this->filters[]=fn($r)=>$r->$field==$value; return $this; }
        public function whereIn($field,$ids) { $this->filters[]=fn($r)=>in_array($r->$field,$ids,true); return $this; }
        public function whereKey($ids) { return $this->whereIn('id',$ids); }
        public function orderBy($field,$direction) { $this->orders[]=[$field,$direction]; return $this; }
        public function with($relation) {
            if (is_array($relation) && isset($relation['folderEntity'])) {
                $constraint=new class {public $owner=null; public function where($field,$value){if($field==='user_id')$this->owner=$value;return $this;}};
                $relation['folderEntity']($constraint); $this->folderOwner=$constraint->owner;
            }
            return $this;
        }
        public function lockForUpdate() { return $this; }
        public function when($condition,$fn) { return $condition ? $fn($this) : $this; }
        public function get($columns=['*']) {
            $rows=array_values(array_filter($this->model::$rows,fn($r)=>array_reduce($this->filters,fn($ok,$f)=>$ok&&$f($r),true)));
            usort($rows,function($a,$b) { foreach($this->orders as [$f,$direction]) { $v=$a->$f<=>$b->$f; if($v) return $direction==='desc'?-$v:$v; } return 0; });
            foreach ($rows as $row) $row->relationOwner=$this->folderOwner;
            return new Rows($rows);
        }
        public function first() { return $this->get()->items[0]??null; }
    }
    class Record {
        public $relationOwner=null;
        public function __construct(public array $data) {}
        public function __get($key) {
            if ($key==='folderEntity') {
                foreach (\App\Models\Folder::$rows as $folder) {
                    if ($folder->folder_id===($this->data['folder_id']??null) && ($this->relationOwner===null || $folder->user_id==$this->relationOwner)) return $folder;
                }
            }
            return $this->data[$key]??null;
        }
        public function toArray() { return $this->data; }
        public function fill($data) { $this->data=array_replace($this->data,$data); return $this; }
        public function save() { return true; }
        public static function where($field,$value) { return (new Query(static::class))->where($field,$value); }
        public static function create($data) {
            $row=new static(array_replace(['id'=>count(static::$rows)+1,'protected'=>false,'auto_wipe'=>false,'deleted'=>false,'pinned'=>false,'favorite'=>false,'checksum_hmac'=>null,'folder_id'=>null,'folder'=>null],$data));
            static::$rows[]=$row; return $row;
        }
    }
}
namespace App\Models { class Note extends \Contract\Record {public static array $rows=[];} class Folder extends \Contract\Record {public static array $rows=[];} }
namespace App\Http\Controllers { class Controller {} }
namespace Illuminate\Http {
    class Request { public function __construct(private array $data) {} public function input($key,$default=null) { return $this->data[$key]??$default; } }
    class JsonResponse {public function __construct(public mixed $data, public int $status=200) {}}
}
namespace Illuminate\Support\Facades { class DB {public static function transaction($fn) {return $fn();}} }
namespace Illuminate\Support { class Str {public static function uuid() {return 'generated-folder';}} }
namespace {
    function collect($items) {return new \Contract\Rows($items);}
    function response() {return new class {function json($data,$status=200) {return new \Illuminate\Http\JsonResponse($data,$status);}};}
    function now() {return new class {function valueOf() {return 1000;}};}
    require __DIR__.'/../app/Support/KnownNotes.php';
    require __DIR__.'/../app/Http/Controllers/V1/NoteController.php';
    $controller=new \App\Http\Controllers\V1\NoteController();
    function check($name,$condition) {if(!$condition) throw new \RuntimeException($name); echo "PASS: $name\n";}
    function request($data=[]) {return new \Illuminate\Http\Request(array_replace(['user_id'=>1],$data));}
    function upload($notes) {global $controller; return $controller->upload(request(['notes'=>$notes]));}
    function download($extra=[]) {global $controller; return $controller->download(request($extra))->data;}
    check('empty legacy account',download()===['notes'=>[],'folders'=>[]]);
    upload([['id'=>'legacy','text'=>'cipher-v1','last_modified'=>100]]);
    $full=download();
    check('legacy upload and response shape',count($full['notes'])===1 && $full['notes'][0]['text']==='cipher-v1' && array_keys($full)===['notes','folders']);
    check('legacy boolean types',is_bool($full['notes'][0]['pinned']) && is_bool($full['notes'][0]['protected']));
    check('legacy since remains unchanged',count(download(['since'=>999999])['notes'])===1);
    check('optional manifest suppresses unchanged note',download(['known_notes'=>['legacy'=>100]])['notes']===[]);
    check('malformed manifest falls back',count(download(['known_notes'=>'invalid'])['notes'])===1);
    check('legacy ids filtering',download(['ids'=>['missing']])['notes']===[]);
    \App\Models\Note::create(['user_id'=>2,'note_id'=>'private','text'=>'other-user','last_modified'=>200]);
    check('account isolation',count(download()['notes'])===1);
    upload([['id'=>'legacy','text'=>'cipher-v2','last_modified'=>200,'pinned'=>true,'favorite'=>true,'protected'=>true,'auto_wipe'=>true,'folder_id'=>'folder','folder'=>'Folder']]);
    \App\Models\Folder::$rows[0]->fill(['name'=>'Renamed','last_modified'=>250])->save();
    upload([['id'=>'legacy','text'=>'cipher-v3','last_modified'=>300]]);
    $note=download()['notes'][0];
    check('old app preserves newer flags',$note['pinned'] && $note['favorite'] && $note['protected'] && $note['auto_wipe']);
    check('old app preserves folder',$note['folder_id']==='folder' && $note['folder']==='Renamed');
    upload([['id'=>'legacy','text'=>'cipher-v4','last_modified'=>400,'favorite'=>false,'folder_id'=>null,'folder'=>'']]);
    $note=download()['notes'][0];
    check('explicit false still clears flag',!$note['favorite']);
    check('explicit empty folder still clears folder',$note['folder_id']===null);
    upload([['id'=>'legacy','text'=>'stale','last_modified'=>350,'folder_id'=>'folder','folder'=>'Stale rename']]);
    check('stale upload cannot rename folder',\App\Models\Folder::$rows[0]->name==='Renamed');
    check('stale upload cannot replace text',download()['notes'][0]['text']==='cipher-v4');
    upload([['id'=>'legacy','text'=>'','last_modified'=>500,'deleted'=>true]]);
    check('tombstones always delivered',download(['known_notes'=>['legacy'=>500]])['notes'][0]['deleted']===true);
    check('missing find retains legacy null',$controller->find(request(['id'=>'missing']))->data===null);
    $note=['id'=>'ack-note','text'=>'cipher-one','title'=>'title','last_modified'=>9000,'checksum_hmac'=>'stable-keyed-checksum'];
    $ack=$controller->upload(request(['require_note_ack'=>true,'notes'=>[$note]]));
    check('opt-in upload confirms persisted data',$ack->status===200 && $ack->data['note_ack_v1']===true);
    $same=$note; $same['text']='different-nonce-same-content';
    $ack=$controller->upload(request(['require_note_ack'=>true,'notes'=>[$same]]));
    check('same keyed content retry is acknowledged',$ack->status===200 && $ack->data['note_ack_v1']===true);
    $older=$note; $older['last_modified']=1000; $older['checksum_hmac']='new-edit';
    $ack=$controller->upload(request(['require_note_ack'=>true,'notes'=>[$older]]));
    check('clock reset is not falsely acknowledged',$ack->status===409 && $ack->data['note_ack_v1']===false);
    $different=$note; $different['checksum_hmac']='conflicting-content';
    $ack=$controller->upload(request(['require_note_ack'=>true,'notes'=>[$different]]));
    check('equal timestamp conflict is not acknowledged',$ack->status===409);
    $legacy=$controller->upload(request(['notes'=>[$older]]));
    check('old app response remains exactly unchanged',$legacy->status===200 && $legacy->data===['ok'=>true]);

    \App\Models\Folder::create(['user_id'=>2,'folder_id'=>'collision','name'=>'OTHER USER PRIVATE NAME','last_modified'=>1]);
    \App\Models\Folder::create(['user_id'=>1,'folder_id'=>'collision','name'=>'My folder','last_modified'=>1]);
    \App\Models\Note::create(['user_id'=>1,'note_id'=>'collision-note','folder_id'=>'collision','last_modified'=>1]);
    $found=$controller->find(request(['id'=>'collision-note']))->data;
    check('find folder lookup scoped to note owner',$found['folder']==='My folder');
    $downloaded=download(['ids'=>['collision-note']]);
    check('download folder lookup scoped to note owner',$downloaded['notes'][0]['folder']==='My folder');

}
