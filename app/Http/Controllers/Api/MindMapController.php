<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MindMap;
use App\Models\MindMapNode;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Modul Mind Map: pohon topik & task tak terbatas kedalamannya.
 *
 * TAUTAN KE PAPAN (opsional per node): node bertipe `task` boleh ditautkan ke
 * satu baris `tasks`. Begitu tertaut, status centangnya MENGIKUTI kolom task
 * di papan -- bukan kolom `done` lokal -- supaya tidak ada dua sumber
 * kebenaran. Mencentang di Mind Map memindahkan task ke kolom "done" lewat
 * TaskController (jadi `completed_at`, ActivityLog, dan Memories ikut
 * terisi seperti kalau di-drag di papan), dan sebaliknya menyelesaikan task
 * di papan otomatis membuat node-nya tercentang.
 */
class MindMapController extends Controller
{
    // =========================
    // MAPS
    // =========================

    public function index()
    {
        $maps = MindMap::withCount('nodes')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (MindMap $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'description' => $m->description,
                'nodeCount' => $m->nodes_count,
                'updatedAt' => $m->updated_at?->timestamp * 1000,
            ]);

        return response()->json($maps);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $map = MindMap::create([
            'user_id' => 1,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($this->mapPayload($map), 201);
    }

    public function show(MindMap $mindMap)
    {
        return response()->json($this->mapPayload($mindMap));
    }

    public function update(Request $request, MindMap $mindMap)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $mindMap->update($validated);

        return response()->json($this->mapPayload($mindMap->fresh()));
    }

    public function destroy(MindMap $mindMap)
    {
        // Node ikut terhapus lewat ON DELETE CASCADE di migrasi.
        $mindMap->delete();

        return response()->json(['success' => true]);
    }

    // =========================
    // NODES
    // =========================

    public function storeNode(Request $request)
    {
        $validated = $request->validate([
            'mind_map_id' => ['required', 'exists:mind_maps,id'],
            'parent_id' => ['nullable', 'exists:mind_map_nodes,id'],
            'type' => ['required', Rule::in(MindMapNode::TYPES)],
            'title' => ['required', 'string', 'max:255'],
        ]);

        // Node baru selalu ditaruh paling bawah di antara saudaranya.
        $position = (int) MindMapNode::where('mind_map_id', $validated['mind_map_id'])
            ->where('parent_id', $validated['parent_id'] ?? null)
            ->max('position');

        $node = MindMapNode::create([
            'mind_map_id' => $validated['mind_map_id'],
            'parent_id' => $validated['parent_id'] ?? null,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'done' => false,
            'position' => $position + 1,
        ]);

        return response()->json(['success' => true, 'node' => $this->nodePayload($node)], 201);
    }

    public function updateNode(Request $request, MindMapNode $node)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'done' => ['sometimes', 'boolean'],
            'collapsed' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::in(MindMapNode::TYPES)],
        ]);

        // Centang/lepas centang: kalau node tertaut ke papan, papan yang
        // diubah (biar completed_at & riwayatnya konsisten), lalu status
        // lokalnya menyusul dari sana.
        if (array_key_exists('done', $validated)) {
            $done = (bool) $validated['done'];

            if ($node->task_id && $node->task) {
                $this->applyToBoard($node->task, $done);
            }

            $node->done = $done;
        }

        foreach (['title', 'collapsed', 'type'] as $field) {
            if (array_key_exists($field, $validated)) {
                $node->{$field} = $validated[$field];
            }
        }

        $node->save();

        return response()->json([
            'success' => true,
            'node' => $this->nodePayload($node->fresh('task')),
        ]);
    }

    public function destroyNode(MindMapNode $node)
    {
        // Anak-anaknya ikut terhapus lewat ON DELETE CASCADE.
        // Task di papan yang tertaut TIDAK ikut dihapus -- menghapus node
        // hanya membuang simpul di mind map, bukan pekerjaannya.
        $node->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Tautkan node ke task papan. Dua mode:
     *  - kirim `task_id`  -> tautkan ke task yang sudah ada
     *  - kirim `board_id` -> buatkan task BARU di papan itu, lalu tautkan
     */
    public function linkNode(Request $request, MindMapNode $node)
    {
        $validated = $request->validate([
            'task_id' => ['nullable', 'exists:tasks,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
        ]);

        if (empty($validated['task_id']) && empty($validated['board_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Kirim task_id (tautkan ke task yang ada) atau board_id (buat task baru).',
            ], 422);
        }

        if (! empty($validated['task_id'])) {
            $task = Task::findOrFail($validated['task_id']);
        } else {
            $response = (new TaskController())->store(Request::create('/api/tasks', 'POST', [
                'title' => $node->title,
                'board_id' => $validated['board_id'],
                'priority' => 'med',
                'column_key' => $node->done ? 'done' : 'todo',
            ]));
            $created = json_decode($response->getContent(), true);
            $task = Task::findOrFail($created['task']['id']);

            // Node yang sudah tercentang duluan -> task barunya langsung
            // ditandai selesai juga, supaya keduanya sinkron sejak awal.
            if ($node->done) {
                $this->applyToBoard($task, true);
            }
        }

        // Node bertipe topic tidak masuk akal ditautkan ke task -- naikkan
        // jadi task supaya centangnya bermakna.
        $node->type = 'task';
        $node->task_id = $task->id;
        $node->done = $task->column_key === 'done';
        $node->save();

        return response()->json([
            'success' => true,
            'node' => $this->nodePayload($node->fresh('task')),
        ]);
    }

    /**
     * Pindahkan node: ganti induk dan/atau urutan (dipakai drag & drop).
     *
     * `parent_id` null = jadikan node akar. `position` adalah indeks 0-based
     * di antara calon saudaranya SETELAH node ini dikeluarkan dari posisi
     * lamanya, jadi memindahkan node ke bawah dalam induk yang sama tetap
     * mendarat di tempat yang diharapkan.
     */
    public function moveNode(Request $request, MindMapNode $node)
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:mind_map_nodes,id'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $parentId = $validated['parent_id'] ?? null;

        if ($parentId !== null) {
            $parent = MindMapNode::findOrFail($parentId);

            // Induk baru harus berada di map yang sama -- kalau tidak, node
            // akan "hilang" dari map asalnya tanpa muncul di mana pun.
            if ($parent->mind_map_id !== $node->mind_map_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Node hanya bisa dipindahkan di dalam map yang sama.',
                ], 422);
            }

            // PENJAGAAN SIKLUS: menjatuhkan node ke dalam dirinya sendiri atau
            // ke salah satu keturunannya akan memutus cabang itu dari pohon
            // (tidak akan pernah terbaca lagi oleh buildTree) dan bisa membuat
            // rekursi tak berujung. Ini kasus yang gampang terjadi saat drag.
            if ($parentId === $node->id || $this->isDescendant($node->id, $parentId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak bisa memindahkan node ke dalam cabangnya sendiri.',
                ], 422);
            }
        }

        // Saudara di tujuan, TANPA node yang sedang dipindah.
        $siblings = MindMapNode::where('mind_map_id', $node->mind_map_id)
            ->where('parent_id', $parentId)
            ->where('id', '!=', $node->id)
            ->orderBy('position')
            ->get()
            ->values();

        $index = $validated['position'] ?? $siblings->count();
        $index = max(0, min($index, $siblings->count()));

        $ordered = $siblings->all();
        array_splice($ordered, $index, 0, [$node]);

        $node->parent_id = $parentId;
        $node->save();

        // Nomori ulang semuanya mulai 1 supaya tidak ada posisi kembar yang
        // bikin urutan tampil acak.
        foreach ($ordered as $i => $sibling) {
            $sibling->position = $i + 1;
            $sibling->save();
        }

        return response()->json([
            'success' => true,
            'node' => $this->nodePayload($node->fresh('task')),
        ]);
    }

    /** Apakah $candidateId berada di dalam cabang milik $ancestorId? */
    private function isDescendant(int $ancestorId, int $candidateId): bool
    {
        $current = MindMapNode::find($candidateId);
        $guard = 0;

        while ($current && $current->parent_id !== null) {
            if ($current->parent_id === $ancestorId) {
                return true;
            }

            // Jaring pengaman kalau data lama terlanjur punya siklus --
            // jangan sampai permintaan ini menggantung selamanya.
            if (++$guard > 500) {
                break;
            }

            $current = MindMapNode::find($current->parent_id);
        }

        return false;
    }

    public function unlinkNode(MindMapNode $node)
    {
        // Status centang terakhir dibekukan jadi nilai lokal, supaya node
        // tidak tiba-tiba berubah tampilannya setelah tautannya dilepas.
        $node->done = $node->isDone();
        $node->task_id = null;
        $node->save();

        return response()->json([
            'success' => true,
            'node' => $this->nodePayload($node->fresh()),
        ]);
    }

    // =========================
    // HELPERS
    // =========================

    /**
     * Pindahkan task papan ke/dari kolom "done" LEWAT TaskController, bukan
     * update langsung -- supaya `completed_at`, ActivityLog, dan pencatatan
     * Memories tetap berjalan persis seperti kalau di-drag di papan.
     */
    private function applyToBoard(Task $task, bool $done): void
    {
        $targetColumn = $done ? 'done' : 'todo';

        if ($task->column_key === $targetColumn) {
            return;
        }

        (new TaskController())->update(
            Request::create("/api/tasks/{$task->id}", 'PATCH', ['column_key' => $targetColumn]),
            $task->id
        );
    }

    private function mapPayload(MindMap $map): array
    {
        $nodes = MindMapNode::with('task:id,title,column_key,board_id')
            ->where('mind_map_id', $map->id)
            ->orderBy('position')
            ->get();

        return [
            'id' => $map->id,
            'title' => $map->title,
            'description' => $map->description,
            'updatedAt' => $map->updated_at?->timestamp * 1000,
            'nodes' => $this->buildTree($nodes, null),
        ];
    }

    /**
     * Susun daftar datar jadi pohon. Sengaja rekursif di PHP dari SATU query
     * (bukan query per level) supaya kedalaman berapa pun tetap 1 query.
     */
    private function buildTree(Collection $nodes, ?int $parentId): array
    {
        return $nodes
            ->where('parent_id', $parentId)
            ->map(fn (MindMapNode $n) => [
                ...$this->nodePayload($n),
                'children' => $this->buildTree($nodes, $n->id),
            ])
            ->values()
            ->all();
    }

    private function nodePayload(MindMapNode $node): array
    {
        return [
            'id' => $node->id,
            'parentId' => $node->parent_id,
            'type' => $node->type,
            'title' => $node->title,
            'done' => $node->isDone(),
            'collapsed' => (bool) $node->collapsed,
            'position' => $node->position,
            'taskId' => $node->task_id,
            'linkedTask' => $node->task_id && $node->task
                ? [
                    'id' => $node->task->id,
                    'title' => $node->task->title,
                    'column' => $node->task->column_key,
                    'boardId' => $node->task->board_id,
                ]
                : null,
        ];
    }
}
