<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;

final class NotesPerformanceReport extends Command
{
    protected $signature = 'notes:performance {--minutes=15 : Recent measurement window, 1 to 1440 minutes}';
    protected $description = 'Show private numeric API/database timings without note contents or credentials';
    public function handle(): int
    {
        $minutes=filter_var($this->option('minutes'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>1440]]);
        if($minutes===false){$this->error('Minutes must be 1 to 1440');return 1;}
        $since=time()-60*$minutes; $durations=[]; $errors=0; $dbMax=0; $slowQueries=0; $upstreamFailures=0; $started=[]; $finished=[]; $operations=[];
        foreach(glob(storage_path('logs/notes-performance-*.log'))?:[] as $file){
            if(filemtime($file)<$since)continue;
            $stream=fopen($file,'r');if(!$stream)continue;
            while(($line=fgets($stream,16384))!==false){
                if(!preg_match('/^\[([^\]]+)\].*notes_performance (\{.*\}) /',$line,$match))continue;
                $timestamp=strtotime($match[1]);if(!$timestamp||$timestamp<$since)continue;
                $row=json_decode($match[2],true);if(!is_array($row))continue;
                $id=$row['request_id']??'';
                if(($row['event']??'')==='start')$started[$id]=$timestamp;
                if(($row['event']??'')==='slow_query')$slowQueries++;
                if(($row['event']??'')!=='finish')continue;
                $finished[$id]=true;
                $durations[]=(float)($row['duration_ms']??0);
                if(($row['status']??0)>=500)$errors++;
                $dbMax=max($dbMax,(float)($row['db_max_ms']??0));
                $upstreamFailures+=(int)($row['upstream_failures']??0);
                $op=$row['operation']??'other';$operations[$op]=($operations[$op]??0)+1;
            }
            fclose($stream);
        }
        sort($durations);$count=count($durations);
        $unfinished=count(array_filter($started,fn($time,$id)=>$time<time()-30&&!isset($finished[$id]),ARRAY_FILTER_USE_BOTH));
        $this->line(json_encode(['window_minutes'=>$minutes,'completed'=>$count,'server_errors'=>$errors,
            'p95_ms'=>$count?$durations[max(0,(int)ceil(.95*$count)-1)]:null,'max_ms'=>$count?end($durations):null,
            'max_query_ms'=>$dbMax,'slow_query_samples'=>$slowQueries,'upstream_failures'=>$upstreamFailures,
            'starts_without_finish_over_30s'=>$unfinished,'operations'=>$operations,
            'note'=>'Unfinished starts can also reflect restart or log limits; failed connection durations are unavailable.'],JSON_PRETTY_PRINT));
        return 0;
    }
}
