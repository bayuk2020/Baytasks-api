<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\ReadingSession;
use App\Models\BookNote;

class BookController extends Controller
{
    public function index()
    {
        $books = Book::with([
            'notes',
            'readingSessions'
        ])
        ->latest()
        ->get()
        ->map(function ($book) {

            if ($book->cover_path) {

                $book->cover_path =
                    asset(
                        ltrim(
                            $book->cover_path,
                            '/'
                        )
                    );
            }

            if ($book->file_path) {

                $book->file_path =
                    asset(
                        ltrim(
                            $book->file_path,
                            '/'
                        )
                    );
            }

            return $book;
        });

return response()->json([
    'books' => $books,
    'bookNotes' => BookNote::latest()->get(),
    'readingSessions' => ReadingSession::latest()->get(),
]);
    }

public function store(Request $request)
{
    try {

$book = Book::create([

    'title' =>
        $request->title,

    'author' =>
        $request->author,

    'cover_image' =>
        $request->cover_image,

    'cover_path' =>
        $request->cover_path,

    'format' =>
        $request->format ??
        'physical',

    'file_path' =>
        $request->file_path,

    'total_pages' =>
        $request->total_pages,

    'current_page' =>
        $request->current_page ?? 0,

    'status' =>
        $request->status ?? 'wishlist',
]);

        return response()->json([
            'book' => $book
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ], 500);
    }
}

    public function update(
        Request $request,
        Book $book
    ) {

    $payload = [];

    if ($request->has('title'))
        $payload['title'] = $request->title;

    if ($request->has('author'))
        $payload['author'] = $request->author;

    if ($request->has('cover_image'))
        $payload['cover_image'] = $request->cover_image;

    if ($request->has('total_pages'))
        $payload['total_pages'] = $request->total_pages;

    if ($request->has('current_page'))
        $payload['current_page'] = $request->current_page;

    if ($request->has('status'))
        $payload['status'] = $request->status;

    if ($request->has('format'))
        $payload['format'] =
            $request->format;
            
    if ($request->has('file_path'))
        $payload['file_path'] =
            $request->file_path;
    if ($request->has('cover_path'))
    $payload['cover_path'] =
        $request->cover_path;

    // =========================
    // DELETE OLD COVER
    // =========================

    if (
        isset($payload['cover_path']) &&
        $book->cover_path &&
        $payload['cover_path'] !== $book->cover_path
    ) {

        Storage::disk('public')
            ->delete(
                str_replace(
                    asset('storage') . '/',
                    '',
                    $book->cover_path
                )
            );
    }

    // =========================
    // DELETE OLD PDF
    // =========================

    if (
        isset($payload['file_path']) &&
        $book->file_path &&
        $payload['file_path'] !== $book->file_path
    ) {

        Storage::disk('public')
            ->delete(
                str_replace(
                    asset('storage') . '/',
                    '',
                    $book->file_path
                )
            );
    }
    $book->update($payload);

        return response()->json([
            'success' => true
        ]);
    }

public function destroy(
    Book $book
) {

    if ($book->cover_path) {

Storage::disk('public')
    ->delete(
        str_replace(
            asset('storage') . '/',
            '',
            $book->cover_path
        )
    );
    }

    if ($book->file_path) {

    Storage::disk('public')
    ->delete(
        str_replace(
            asset('storage') . '/',
            '',
            $book->file_path
        )
    );
    }

    $book->delete();

    return response()->json([
        'success' => true
    ]);
}

    public function updateProgress(
        Request $request,
        Book $book
    ) {

        $oldPage =
            $book->current_page;

        $newPage =
            (int) $request->current_page;

        $book->current_page =
            $newPage;

        if (
            $newPage >=
            $book->total_pages
        ) {

            $book->status =
                'completed';

        } elseif (
            $newPage > 0 &&
            $book->status === 'wishlist'
        ) {

            $book->status =
                'reading';
        }

        $book->save();

        ReadingSession::create([
            'book_id' => $book->id,
            'previous_page' => $oldPage,
            'new_page' => $newPage,
            'pages_read' => max(
                0,
                $newPage - $oldPage
            ),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function uploadCover(
    Request $request
) {

    $request->validate([

        'file' =>
            'required|image|max:5120',
    ]);

    $path =
        $request
            ->file('file')
            ->store(
                'book-covers',
                'public'
            );

return response()->json([
    'path' =>
        asset(
            'storage/' . $path
        ),
]);
}
public function uploadPdf(
    Request $request
) {

    $request->validate([

        'file' =>
            'required|mimes:pdf|max:51200',
    ]);

    $path =
        $request
            ->file('file')
            ->store(
                'books',
                'public'
            );

    return response()->json([

        'path' =>
            asset(
                'storage/' . $path
            ),
    ]);
}
}