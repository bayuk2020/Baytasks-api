<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoryController extends Controller
{
    // =========================
    // FORMAT STORY
    // =========================
    private function formatStory(Story $story): array
    {
        return [
            'id' => $story->id,
            // image_path di database sekarang adalah FULL URL (http://api.kabyra.my.id/...)
            'imagePath' => $story->image_path,
            'imageUrl' => $story->image_path, // Langsung pakai nilai aslinya
            'caption' => $story->caption,
            'createdAt' => $story->created_at ? $story->created_at->timestamp * 1000 : null,
        ];
    }

    // =========================
    // GET FEED (terbaru duluan)
    // =========================
    public function index()
    {
        $stories = Story::orderByDesc('created_at')
            ->get()
            ->map(fn (Story $story) => $this->formatStory($story));

        return response()->json($stories);
    }

    // =========================
    // UPLOAD DARI WEB (teks dan/atau gambar)
    // =========================
    public function store(Request $request)
    {
        $request->validate([
            'caption' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:10240'], // maks 10MB
        ]);

        if (! $request->hasFile('image') && ! $request->filled('caption')) {
            return response()->json([
                'message' => 'Isi caption atau lampirkan gambar dulu ya.',
            ], 422);
        }

        $path = null;
        if ($request->hasFile('image')) {
            // Simpan ke folder public/stories
            $relativePath = $request->file('image')->store('stories', 'public');

            // Full absolute URL ke domain publik (BUKAN asset()/APP_URL server --
            // lihat Story::publicStorageUrl()).
            $path = Story::publicStorageUrl($relativePath);
        }

        $story = Story::create([
            'image_path' => $path,
            'caption' => $request->input('caption'),
        ]);

        return response()->json($this->formatStory($story), 201);
    }

    // =========================
    // DELETE
    // =========================
    public function destroy(Story $story)
    {
        // Hapus file fisik jika ada. Strip berdasarkan Story::publicStorageUrl()
        // (bukan asset('storage')) karena image_path selalu domain publik
        // eksplisit, yang bisa beda dari asset()'s hasil di server saat ini.
        if ($story->image_path) {
            Storage::disk('public')->delete(
                str_replace(
                    Story::publicStorageUrl(''),
                    '',
                    $story->image_path
                )
            );
        }

        $story->delete();

        return response()->json(['success' => true]);
    }
}