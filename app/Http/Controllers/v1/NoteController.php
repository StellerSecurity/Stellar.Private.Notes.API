<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Laravel\Prompts\Note;

class NoteController extends Controller
{

    public function index()
    {
        $notes = Note::all();
    }

}