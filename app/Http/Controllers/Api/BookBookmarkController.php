<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookBookmark;
use Illuminate\Http\Request;

/**
 * Penanda halaman buku.
 *
 * Endpoint utamanya `toggle`: menandai halaman yang belum ditandai, dan
 * melepas penandanya kalau sudah. Satu tombol, satu arti -- tanpa itu sisi
 * web harus lebih dulu mencari tahu apakah halaman ini sudah ditandai,
 * dan dua klik cepat bisa menyisakan penanda ganda.
 */
class BookBookmarkController extends Controller
{
    /** Semua penanda sebuah buku, diurutkan menurut halaman. */
    public function index($bookId)
    {
        $book = Book::find($bookId);

        if (! $book) {
            return response()->json(['error' => 'Buku tidak ditemukan.'], 404);
        }

        return response()->json([
            'bookmarks' => BookBookmark::where('book_id', $book->id)
                ->orderBy('page_number')
                ->get(),
        ]);
    }

    public function toggle(Request $request, $bookId)
    {
        $book = Book::find($bookId);

        if (! $book) {
            return response()->json(['error' => 'Buku tidak ditemukan.'], 404);
        }

        $halaman = (int) $request->input('page_number');

        // Halaman 0 berarti "belum mulai baca", bukan sebuah halaman.
        if ($halaman < 1) {
            return response()->json(['error' => 'Nomor halaman minimal 1.'], 422);
        }

        // Batas atas mengikuti total halaman buku, tapi dilonggarkan kalau
        // total_pages masih nilai bawaan (1) -- banyak buku diisi tanpa
        // jumlah halaman yang benar, dan itu tidak boleh menghalangi.
        if ($book->total_pages > 1 && $halaman > $book->total_pages) {
            return response()->json([
                'error' => "Halaman {$halaman} melebihi total halaman buku ({$book->total_pages}).",
            ], 422);
        }

        $ada = BookBookmark::where('book_id', $book->id)
            ->where('page_number', $halaman)
            ->first();

        if ($ada) {
            $ada->delete();

            return response()->json([
                'success'  => true,
                'action'   => 'dihapus',
                'bookmark' => null,
            ]);
        }

        $bookmark = BookBookmark::create([
            'book_id'     => $book->id,
            'page_number' => $halaman,
            'label'       => $request->filled('label') ? trim((string) $request->input('label')) : null,
            'color'       => $request->filled('color') ? (string) $request->input('color') : null,
        ]);

        return response()->json([
            'success'  => true,
            'action'   => 'ditambah',
            'bookmark' => $bookmark,
        ]);
    }

    /** Mengubah label / warna penanda yang sudah ada. */
    public function update(Request $request, $id)
    {
        $bookmark = BookBookmark::find($id);

        if (! $bookmark) {
            return response()->json(['error' => 'Penanda tidak ditemukan.'], 404);
        }

        $ubah = [];

        if ($request->has('label')) {
            $label = trim((string) $request->input('label'));
            $ubah['label'] = $label === '' ? null : $label;
        }

        if ($request->has('color')) {
            $ubah['color'] = $request->input('color') ?: null;
        }

        if ($ubah !== []) {
            $bookmark->update($ubah);
        }

        return response()->json(['success' => true, 'bookmark' => $bookmark->fresh()]);
    }

    public function destroy($id)
    {
        $bookmark = BookBookmark::find($id);

        if (! $bookmark) {
            return response()->json(['error' => 'Penanda tidak ditemukan.'], 404);
        }

        $bookmark->delete();

        return response()->json(['success' => true]);
    }
}
