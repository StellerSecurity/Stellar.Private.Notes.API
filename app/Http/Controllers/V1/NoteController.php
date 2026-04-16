<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NoteController extends Controller
{
    public function find(Request $request) {
        $note = $this->canonicalNoteQuery((int) $request->input('user_id'))
            ->where('note_id', $request->input('id'))
            ->first();

        if ($note) {
            $note->folder = $note->folderEntity?->name ?? $note->folder;
        }

        return response()->json($note);
    }

    public function plan(Request $req)
    {
        $userId = $req->input('user_id');
        $client = collect($req->input('notes', []))->keyBy('id');
        $clientFolders = collect($req->input('folders', []))->keyBy('id');

        foreach ((array)$req->input('deleted_ids', []) as $did) {
            Note::updateOrCreate(
                ['user_id' => $userId, 'note_id' => $did],
                [
                    'title' => '',
                    'text'  => '',
                    'deleted' => true,
                    'last_modified' => max(
                        Note::where('user_id',$userId)->where('note_id',$did)->value('last_modified') ?? 0,
                        now()->valueOf()
                    ),
                    'folder_id' => null,
                    'folder' => null,
                ]
            );
        }

        foreach ((array)$req->input('deleted_folder_ids', []) as $did) {
            Folder::updateOrCreate(
                ['user_id' => $userId, 'folder_id' => $did],
                [
                    'name' => '',
                    'deleted' => true,
                    'last_modified' => max(
                        Folder::where('user_id',$userId)->where('folder_id',$did)->value('last_modified') ?? 0,
                        now()->valueOf()
                    ),
                ]
            );
        }

        $server = $this->canonicalNoteQuery($userId)
            ->whereIn('note_id', $client->keys())
            ->get()
            ->keyBy('note_id');

        $serverFolders = $this->canonicalFolderQuery($userId)
            ->whereIn('folder_id', $clientFolders->keys())
            ->get()
            ->keyBy('folder_id');

        $upload = []; $download = []; $noop = []; $conflicts = [];
        foreach ($client as $id => $c) {
            $s = $server->get($id);
            if (!$s) { $upload[] = $id; continue; }

            $cm = (int) ($c['last_modified'] ?? 0);
            $sm = (int) $s->last_modified;

            if ($cm > $sm) { $upload[] = $id; continue; }
            if ($cm < $sm) { $download[] = $id; continue; }

            $cSum = $c['checksum_hmac'] ?? null;
            if ($cSum && $s->checksum_hmac && $cSum !== $s->checksum_hmac) {
                $conflicts[] = ['id'=>$id,'reason'=>'same_timestamp_different_checksum'];
            } else {
                $noop[] = $id;
            }
        }

        $folderUpload = []; $folderDownload = []; $folderNoop = []; $folderConflicts = [];
        foreach ($clientFolders as $id => $c) {
            $s = $serverFolders->get($id);
            if (!$s) { $folderUpload[] = $id; continue; }

            $cm = (int) ($c['last_modified'] ?? 0);
            $sm = (int) $s->last_modified;

            if ($cm > $sm) { $folderUpload[] = $id; continue; }
            if ($cm < $sm) { $folderDownload[] = $id; continue; }

            if (($c['name'] ?? '') !== $s->name || (bool)($c['deleted'] ?? false) !== (bool)$s->deleted) {
                $folderConflicts[] = ['id'=>$id,'reason'=>'same_timestamp_different_payload'];
            } else {
                $folderNoop[] = $id;
            }
        }

        $serverNew = $this->canonicalNoteQuery($userId)->pluck('note_id')->diff($client->keys());
        $download = array_values(array_unique(array_merge($download, $serverNew->all())));

        $serverNewFolders = $this->canonicalFolderQuery($userId)->pluck('folder_id')->diff($clientFolders->keys());
        $folderDownload = array_values(array_unique(array_merge($folderDownload, $serverNewFolders->all())));

        return response()->json([
            'upload' => $upload,
            'download' => $download,
            'noop' => $noop,
            'conflicts' => $conflicts,
            'upload_folders' => $folderUpload,
            'download_folders' => $folderDownload,
            'noop_folders' => $folderNoop,
            'conflicts_folders' => $folderConflicts,
        ]);
    }

    public function upload(Request $req)
    {
        $userId = $req->input('user_id');
        $incomingFolders = collect($req->input('folders', []));
        $incoming = collect($req->input('notes', []));

        DB::transaction(function () use ($incomingFolders, $incoming, $userId) {
            foreach ($incomingFolders as $f) {
                $id = $f['id'] ?? null;
                if (!$id) {
                    continue;
                }

                $existing = Folder::where('user_id',$userId)->where('folder_id',$id)->lockForUpdate()->first();
                $payload = [
                    'name' => trim((string)($f['name'] ?? '')),
                    'last_modified' => (int)($f['last_modified'] ?? 0),
                    'deleted' => (bool)($f['deleted'] ?? false),
                ];

                if (!$existing) {
                    Folder::create(array_merge($payload, [
                        'user_id' => $userId,
                        'folder_id' => $id,
                    ]));
                    continue;
                }

                if ((int)$payload['last_modified'] > (int)$existing->last_modified) {
                    $existing->fill($payload)->save();
                }
            }

            foreach ($incoming as $n) {
                $id = $n['id'];
                $existing = Note::where('user_id',$userId)->where('note_id',$id)->lockForUpdate()->first();

                [$resolvedFolderId, $resolvedFolderName] = $this->resolveFolderForIncomingNote($userId, $n);

                $payload = [
                    'title'         => $n['title'] ?? '',
                    'text'          => $n['text'] ?? '',
                    'last_modified' => (int)($n['last_modified'] ?? 0),
                    'protected'     => ($n['protected'] ?? false),
                    'auto_wipe'     => (bool)($n['auto_wipe'] ?? false),
                    'deleted'       => (bool)($n['deleted'] ?? false),
                    'pinned'        => (bool)($n['pinned'] ?? false),
                    'favorite'      => (bool)($n['favorite'] ?? false),
                    'checksum_hmac' => $n['checksum_hmac'] ?? null,
                    'folder_id'     => $resolvedFolderId,
                    'folder'        => $resolvedFolderName,
                ];

                if (!$existing) {
                    Note::create(array_merge($payload, [
                        'user_id' => $userId,
                        'note_id' => $id,
                    ]));
                    continue;
                }

                if ((int)$payload['last_modified'] > (int)$existing->last_modified) {
                    $existing->fill($payload)->save();
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    public function download(Request $req)
    {
        $userId = $req->input('user_id');
        $ids = (array) $req->input('ids', []);
        $folderIds = (array) $req->input('folder_ids', []);

        $notes = $this->canonicalNoteQuery($userId)
            ->with('folderEntity')
            ->when($ids, fn($q)=>$q->whereIn('note_id',$ids))
            ->get()->map(fn($n)=>[
                'id'            => $n->note_id,
                'title'         => $n->title,
                'last_modified' => (int)$n->last_modified,
                'text'          => $n->text,
                'protected'     => (bool)$n->protected,
                'auto_wipe'     => (bool)$n->auto_wipe,
                'deleted'       => (bool)$n->deleted,
                'pinned'        => (bool)$n->pinned,
                'favorite'      => (bool)$n->favorite,
                'checksum_hmac' => $n->checksum_hmac,
                'folder_id'     => $n->folder_id,
                'folder'        => $n->folderEntity?->name ?? $n->folder ?? '',
            ])->values();

        $folders = $this->canonicalFolderQuery($userId)
            ->when($folderIds, fn($q) => $q->whereIn('folder_id', $folderIds))
            ->get()->map(fn($f) => [
                'id' => $f->folder_id,
                'name' => $f->name,
                'last_modified' => (int)$f->last_modified,
                'deleted' => (bool)$f->deleted,
            ])->values();

        return response()->json(['notes' => $notes, 'folders' => $folders]);
    }


    private function canonicalNoteQuery(int|string $userId)
    {
        return Note::where('user_id', $userId)
            ->orderBy('last_modified', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->unique('note_id')
            ->values()
            ->toQuery();
    }

    private function canonicalFolderQuery(int|string $userId)
    {
        return Folder::where('user_id', $userId)
            ->orderBy('last_modified', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->unique('folder_id')
            ->values()
            ->toQuery();
    }

    private function resolveFolderForIncomingNote(int|string $userId, array $note): array
    {
        $folderId = $note['folder_id'] ?? null;
        $folderName = trim((string)($note['folder'] ?? ''));
        $lastModified = (int)($note['last_modified'] ?? now()->valueOf());

        if ($folderId) {
            $folder = Folder::where('user_id', $userId)->where('folder_id', $folderId)->lockForUpdate()->first();
            if ($folder) {
                if ($folderName !== '' && $folder->name !== $folderName && $lastModified >= (int)$folder->last_modified) {
                    $folder->fill([
                        'name' => $folderName,
                        'last_modified' => $lastModified,
                        'deleted' => false,
                    ])->save();
                }
                return [$folder->folder_id, $folder->name];
            }

            Folder::create([
                'user_id' => $userId,
                'folder_id' => $folderId,
                'name' => $folderName,
                'last_modified' => $lastModified,
                'deleted' => false,
            ]);

            return [$folderId, $folderName];
        }

        if ($folderName === '') {
            return [null, null];
        }

        $existingByName = Folder::where('user_id', $userId)
            ->where('name', $folderName)
            ->lockForUpdate()
            ->first();

        if ($existingByName) {
            if ($lastModified > (int)$existingByName->last_modified) {
                $existingByName->fill([
                    'last_modified' => $lastModified,
                    'deleted' => false,
                ])->save();
            }
            return [$existingByName->folder_id, $existingByName->name];
        }

        $newFolderId = (string) Str::uuid();
        Folder::create([
            'user_id' => $userId,
            'folder_id' => $newFolderId,
            'name' => $folderName,
            'last_modified' => $lastModified,
            'deleted' => false,
        ]);

        return [$newFolderId, $folderName];
    }
}
