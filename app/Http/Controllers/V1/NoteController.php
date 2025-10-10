<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoteController extends Controller
{

    public function find(Request $request) {
        $note = Note::where('note_id',$request->input('id'))->first();
        return response()->json($note);
    }

    public function plan(Request $req)
    {
        $userId = $req->input('user_id');
        $client = collect($req->input('notes', []))->keyBy('id'); // [{id, last_modified, checksum_hmac?}]

        // Soft-deletes coming from client (optional)
        foreach ((array)$req->input('deleted_ids', []) as $did) {
            Note::updateOrCreate(
                ['user_id' => $userId, 'note_id' => $did],
                [
                    'title' => '',
                    'text'  => '',
                    'deleted' => true,
                    'last_modified' => max(
                        Note::where('user_id',$userId)->where('note_id',$did)->value('last_modified') ?? 0,
                        now()->valueOf() // ms
                    ),
                ]
            );
        }

        $server = Note::where('user_id', $userId)
            ->whereIn('note_id', $client->keys())
            ->get()->keyBy('note_id');

        $upload = []; $download = []; $noop = []; $conflicts = [];

        foreach ($client as $id => $c) {
            $s = $server->get($id);
            if (!$s) { $upload[] = $id; continue; }

            $cm = (int) ($c['last_modified'] ?? 0);
            $sm = (int) $s->last_modified;

            if ($cm > $sm) { $upload[] = $id; continue; }
            if ($cm < $sm) { $download[] = $id; continue; }

            // equal timestamps — optional checksum conflict detection
            $cSum = $c['checksum_hmac'] ?? null;
            if ($cSum && $s->checksum_hmac && $cSum !== $s->checksum_hmac) {
                $conflicts[] = ['id'=>$id,'reason'=>'same_timestamp_different_checksum'];
            } else {
                $noop[] = $id;
            }
        }

        // Any server notes missing on client (e.g., edits from other device)
        $serverNew = Note::where('user_id', $userId)->pluck('note_id')->diff($client->keys());
        $download = array_values(array_unique(array_merge($download, $serverNew->all())));

        return response()->json(compact('upload','download','noop','conflicts'));
    }

    public function upload(Request $req)
    {
        $userId = $req->input('user_id');
        $incoming = collect($req->input('notes', []));

        DB::transaction(function () use ($incoming, $userId) {
            foreach ($incoming as $n) {
                $id = $n['id'];
                $existing = Note::where('user_id',$userId)->where('note_id',$id)->lockForUpdate()->first();

                $payload = [
                    'title'         => $n['title'] ?? '',
                    'text'          => $n['text'] ?? '',
                    'last_modified' => (int)($n['last_modified'] ?? 0),
                    'protected'     => ($n['protected'] ?? false),
                    'auto_wipe'     => (bool)($n['auto_wipe'] ?? false),
                    'deleted'       => (bool)($n['deleted'] ?? false),
                    'checksum_hmac' => $n['checksum_hmac'] ?? null,
                ];

                if (!$existing) {
                    Note::create(array_merge($payload, [
                        'user_id' => $userId,
                        'note_id' => $id,
                    ]));
                    continue;
                }

                // accept strictly newer writes only
                if ((int)$payload['last_modified'] > (int)$existing->last_modified) {
                    $existing->fill($payload)->save();
                }
                // equal/older -> ignore (client should re-download)
            }
        });

        return response()->json(['ok' => true]);
    }

    public function download(Request $req)
    {
        $userId = (int) $req->attributes->get('auth_user_id');
        $ids = (array) $req->input('ids', []);

        $notes = Note::where('user_id',$userId)
            ->when($ids, fn($q)=>$q->whereIn('note_id',$ids))
            ->get()->map(fn($n)=>[
                'id'            => $n->note_id,
                'title'         => $n->title,
                'last_modified' => (int)$n->last_modified,
                'text'          => $n->text,
                'protected'     => (bool)$n->protected,
                'auto_wipe'     => (bool)$n->auto_wipe,
                'deleted'       => (bool)$n->deleted,
                'checksum_hmac' => $n->checksum_hmac,
            ])->values();

        return response()->json(['notes' => $notes]);
    }
}