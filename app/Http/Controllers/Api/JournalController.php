<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Journal;
use App\Models\JournalTag;

use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function index()
    {
        return Journal::with(
            'tags'
        )
        ->latest()
        ->get();
    }

    public function store(
        Request $request
    ) {

        $journal =
            Journal::create([

                'title' =>
                    $request->title,

                'content' =>
                    $request->content,

                'mood' =>
                    $request->mood,
            ]);

        foreach (
            $request->tags ?? []
            as $tag
        ) {

            JournalTag::create([

                'journal_id' =>
                    $journal->id,

                'tag' =>
                    $tag,
            ]);
        }

        return $journal->load(
            'tags'
        );
    }

    public function show(
        Journal $journal
    ) {

        return $journal->load(
            'tags'
        );
    }

    public function update(
        Request $request,
        Journal $journal
    ) {


        $journal->update([

            'title' =>

                $request->title
                ?? $journal->title,

            'content' =>

                $request->content
                ?? $journal->content,

            'mood' =>

                $request->mood
                ?? $journal->mood,
        ]);

        if (
            $request->has('tags')
        ) {

            $journal->tags()->delete();

            foreach (
                $request->tags
                as $tag
            ) {

                JournalTag::create([

                    'journal_id' =>
                        $journal->id,

                    'tag' =>
                        $tag,
                ]);
            }
        }

    }

    public function destroy(
        Journal $journal
    ) {

        $journal->delete();

        return response()->json([

            'success' => true,
        ]);
    }
}