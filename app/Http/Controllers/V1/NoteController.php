<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{

    public function create(Request $request): JsonResponse
    {
        $user_id = $request->input('user_id');
        $json_content = $request->input('json_content');

        if(empty($user_id) || empty($json_content)) {
            return response()->json(['response_code' => 400, 'response_message' => 'User ID or json_content is empty.'], 400);
        }

        $note = Note::where('user_id', $user_id)->first();

        if($note !== null) {
            return response()->json(['response_code' => 400, 'response_message' => 'Note info already exists, use UPDATE/PATCH'], 400);
        }

        Note::create($request->all());

        return response()->json(['response_code' => 200, 'response_message' => 'Note created successfully.'], 200);

    }

    public function updateOrCreate(Request $request): JsonResponse
    {

        $user_id = $request->input('user_id');
        $json_content = base64_decode($request->input('json_content'));

        if(empty($user_id) || empty($json_content)) {
            return response()->json(['response_code' => 400, 'response_message' => 'User ID or json_content is empty.'], 400);
        }

        $note = Note::where('user_id', $user_id)->first();

        if($note === null) {
            Note::create($request->all());
        } else {
            $note->update($request->all());
        }

        return response()->json(['response_code' => 200, 'response_message' => 'Note created successfully.'], 200);

    }

    public function find(Request $request): JsonResponse
    {

        $user_id = $request->input('user_id');

        if(empty($user_id)) {
            return response()->json(['response_code' => 400, 'response_message' => 'User ID  is empty.'], 400);
        }

        $note = Note::where('user_id', $user_id)->first();

        if($note === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'Note info not found'], 400);
        }

        return response()->json($note, 200);

    }

    public function delete(Request $request): JsonResponse
    {

        $user_id = $request->input('user_id');

        if(empty($user_id)) {
            return response()->json(['response_code' => 400, 'response_message' => 'User ID  is empty.'], 400);
        }

        $note = Note::where('user_id', $user_id)->first();

        if($note === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'Note info not found'], 400);
        }

        $note->delete();

        return response()->json(['response_code' => 200, 'response_message' => 'Note deleted successfully.'], 200);

    }

    public function update(Request $request): JsonResponse
    {

        $user_id = $request->input('user_id');
        $json_content = $request->input('json_content');

        if(empty($user_id) || empty($json_content)) {
            return response()->json(['response_code' => 400, 'response_message' => 'User ID or json_content is empty.'], 400);
        }

        $note = Note::where('user_id', $user_id)->first();

        if($note == null) {
            return response()->json(['response_code' => 400, 'response_message' => 'Note info not found, use CREATE.'], 400);
        }

        $note->update($request->all());

        return response()->json(['response_code' => 200, 'response_message' => 'Note created successfully.'], 200);

    }

}