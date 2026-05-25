<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Attachment;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    // =========================
    // UPLOAD
    // =========================

    public function store(Request $request)
    {
        try {

            if (
                !$request->hasFile('file')
            ) {

                return response()->json([
                    'success' => false,
                    'message' => 'No file uploaded',
                ], 400);
            }

            $file =
                $request->file(
                    'file'
                );

            $path =
                $file->store(
                    'attachments',
                    'public'
                );

            $attachment =
                Attachment::create([

                    'task_id' =>
                        (int) $request->task_id,

                    'name' =>
                        $file->getClientOriginalName(),

                    'size' =>
                        $file->getSize(),

                    'path' =>
                        $path,
                ]);

            return response()->json([
                'success' => true,

                'attachment' => [

                    'id' =>
                        (string) $attachment->id,

                    'name' =>
                        $attachment->name,

                    'size' =>
                        $attachment->size,

                    'url' =>
                        asset(
                            'storage/' .
                            $attachment->path
                        ),
                ],
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,

                'message' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    // =========================
    // DELETE
    // =========================

    public function destroy($id)
    {
        try {

            $attachment =
                Attachment::findOrFail(
                    $id
                );

            Storage::disk('public')
                ->delete(
                    $attachment->path
                );

            $attachment->delete();

            return response()->json([
                'success' => true,
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,

                'message' =>
                    $e->getMessage(),
            ], 500);
        }
    }
}