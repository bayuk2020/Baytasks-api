<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Memory;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    // =========================
    // FORMAT MEMORY
    // =========================
    private function formatMemory(Memory $memory): array
    {
        return [
            'id' => $memory->id,
            'type' => $memory->type,
            'source' => $memory->source,
            'title' => $memory->title,
            'content' => $memory->content,
            'tags' => $memory->tags,
            'occurredAt' => $memory->occurred_at ? $memory->occurred_at->timestamp * 1000 : null,
        ];
    }

    // =========================
    // GET LIST (terbaru duluan, opsional filter tanggal)
    // =========================
    public function index(Request $request)
    {
        $query = Memory::orderByDesc('occurred_at');

        // ?date=YYYY-MM-DD -- dipakai UI Timeline di halaman Calendar untuk
        // menampilkan aktivitas pada satu tanggal tertentu saja.
        if ($request->filled('date')) {
            $query->whereDate('occurred_at', $request->input('date'));
        }

        $memories = $query->limit(200)
            ->get()
            ->map(fn (Memory $memory) => $this->formatMemory($memory));

        return response()->json($memories);
    }
}
