<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use App\Models\Board;

class BoardController extends Controller
{
    // =========================
    // INDEX
    // =========================

    public function index()
    {
        return response()->json(

            Board::orderBy(
                'position'
            )->get()
        );
    }

    // =========================
    // STORE
    // =========================

    public function store(
        Request $request
    ) {

        $board =
            Board::create([

                'project_id' =>
                    $request->project_id,

                'name' =>
                    $request->name,
                
                'emoji' =>
                    $request->emoji,

                'position' =>
                    $request->position ?? 0,
            ]);

        return response()->json([

            'board' =>
                $board,
        ]);
    }

    // =========================
    // UPDATE
    // =========================

    public function update(
        Request $request,
        $id
    ) {

        $board =
            Board::findOrFail(
                $id
            );

        $board->update([

            'project_id' =>
                $request->project_id,

            'name' =>
                $request->name,

            'emoji' =>
                $request->emoji,

            'position' =>
                $request->position ?? 0,
        ]);

        return response()->json([

            'board' =>
                $board,
        ]);
    }

    // =========================
    // DELETE
    // =========================

    public function destroy(
        $id
    ) {

        $board =
            Board::findOrFail(
                $id
            );

        $board->delete();

        return response()->json([

            'success' =>
                true,
        ]);
    }

    // =========================
    // REORDER
    // =========================

    public function reorder(
        Request $request
    ) {

        foreach (
            $request->boards as $item
        ) {

            Board::where(
                'id',
                $item['id']
            )->update([

                'position' =>
                    $item['position'],
            ]);
        }

        return response()->json([

            'success' =>
                true,
        ]);
    }
}