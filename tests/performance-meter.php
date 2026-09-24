<?php
require __DIR__.'/../app/Support/PerformanceMeter.php';
use App\Support\PerformanceMeter;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$records = [];
$m = new PerformanceMeter(function ($data) use (&$records) { $records[] = $data; });
$m->begin('api/v1/notescontroller/download');
$m->query(12, 'select ?');
$m->query(400, "select secret-value where token = ?");
$m->upstream(1300, 502);
$m->finish(502);
$end = end($records);
check($end['db_count'] === 2 && $end['db_ms'] === 412.0 && $end['db_max_ms'] === 400.0, 'DB metrics');
check($end['upstream_failures'] === 1 && $end['status'] === 502, 'Upstream failure');
check(!str_contains(json_encode($records), 'secret-value'), 'SQL privacy');
check($records[0]['request_id'] === $end['request_id'], 'Correlation');
$id = $end['request_id'];
$m->begin('api/private-email@example.com?token=secret');
$m->finish(200);
$end = end($records);
check($end['operation'] === 'other' && $end['db_count'] === 0 && $end['request_id'] !== $id, 'Request isolation');
check(!str_contains(json_encode($records), 'private-email'), 'Path privacy');
$n = count($records); $m->query(400, 'ignored'); $m->finish(500); check(count($records) === $n, 'Inactive meter');
$m->begin('up'); for ($i=0;$i<100;$i++) $m->query(300,'select ?'); $m->finish(200);
check(count($records) - $n === 7, 'Bounded detail logging');
$broken = new PerformanceMeter(function () { throw new RuntimeException('disk unavailable'); });
$broken->begin('upload'); $broken->query(500,'select ?'); $broken->finish(200);
echo "Performance meter: 9 privacy, isolation, timing and failure checks passed\n";
