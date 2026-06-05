<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\BookNote;
use Illuminate\Http\Request;

class BookNoteController extends Controller
{
    public function store(
        Request $request
    ) {

        $note = BookNote::create([
            'book_id' => $request->book_id,
            'page_number' => $request->page_number,
            'chapter' => $request->chapter,
            'title' => $request->title,
            'content' => $request->content,
        ]);

        return response()->json([
            'note' => $note
        ]);
    }

    public function update(
        Request $request,
        BookNote $bookNote
    ) {

        $bookNote->update([
            'page_number' => $request->page_number,
            'chapter' => $request->chapter,
            'title' => $request->title,
            'content' => $request->content,
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function destroy(
        BookNote $bookNote
    ) {

        $bookNote->delete();

        return response()->json([
            'success' => true
        ]);
    }
}