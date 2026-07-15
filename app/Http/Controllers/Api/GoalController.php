<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\GoalLink;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class GoalController extends Controller
{
    private array $morphMap = [
        'task'        => 'App\Models\Task',
        'habit'       => 'App\Models\Habit',
        'book'        => 'App\Models\Book',
        'debt'        => 'App\Models\Debt',
        'account'     => 'App\Models\Account',
        'transaction' => 'App\Models\Transaction',
    ];

    private function getLovableType(string $modelClass): string
    {
        $flipped = array_flip($this->morphMap);
        return $flipped[$modelClass] ?? 'task';
    }

    /**
     * Helper Transformer tunggal biar skema data CamelCase seragam di semua rute API
     */
    private function transformGoal($goal): array
    {
        $areaMappingReverse = [
            1 => 'finance', 2 => 'career', 3 => 'health', 
            4 => 'relationship', 5 => 'learning', 6 => 'spiritual', 7 => 'business'
        ];

        return [
            'id'               => $goal->id,
            'title'            => $goal->title,
            'name'             => $goal->title,
            'description'      => $goal->description,
            'target_amount'    => (float) $goal->target_amount,
            'current_amount'   => (float) $goal->current_amount,
            'targetValue'      => (float) $goal->target_amount, 
            'currentValue'     => (float) $goal->current_amount, 
            'progress_percent' => (int) $goal->progress_percent,
            'status'           => $goal->completed ? 'completed' : 'active',
            'lifeArea'         => $areaMappingReverse[$goal->area_id] ?? 'career',
            
            'milestones' => $goal->milestones->map(function ($m) {
                return [
                    'id'           => $m->id,
                    'goal_id'      => (string) $m->goal_id,
                    'goalId'       => (string) $m->goal_id,
                    'title'        => $m->name,
                    'name'         => $m->name,
                    'target_value' => (float) $m->target_value,
                    'current_value'=> (float) $m->current_value,
                    'targetValue'  => (float) $m->target_value,
                    'currentValue' => (float) $m->current_value,
                    'dueAt'        => $m->due_date ? strtotime($m->due_date) * 1000 : null,
                    'completed'    => (bool) $m->completed,
                ];
            }),

            'quarterlyPlans' => $goal->quarterlyPlans->map(function ($q) {
                return [
                    'id'       => (string) $q->id,
                    'goal_id'  => (string) $q->goal_id,
                    'goalId'   => (string) $q->goal_id,
                    'year'     => (int) $q->year,
                    'quarter'  => (int) $q->quarter,
                    'target'   => (float) $q->target_amount,
                    'current'  => (float) $q->current_amount,
                ];
            }),

            'links' => $goal->links->map(function ($l) {
                // 🌟 FIX UTAMA: Ambil objek model asli (Eager Loaded via morphTo)
                $realResource = $l->linkable;
                
                // Cari properti penamaan teks yang tersedia dari resource tersebut
                $resolvedTitle = "Unknown Link";
                if ($realResource) {
                    $resolvedTitle = $realResource->title ?? $realResource->name ?? $realResource->heading ?? "Link #{$l->linkable_id}";
                }

                return [
                    'id'    => (string) $l->id,
                    'type'  => $this->getLovableType($l->linkable_type),
                    'refId' => (string) $l->linkable_id,
                    'label' => $resolvedTitle // 🌟 Tampilkan judul asli reaktif (bukan string kaku lagi)
                ];
            }),
            
            'created_at' => $goal->created_at ? $goal->created_at->toIso8601String() : null,
            'updated_at' => $goal->updated_at ? $goal->updated_at->toIso8601String() : null,
        ];
    }

    public function index(): JsonResponse
    {
        // 🌟 Ambil relasi bersarang links.linkable sekalian
        $goals = Goal::with(['area', 'milestones', 'quarterlyPlans', 'links.linkable', 'reviews'])
            ->orderBy('id', 'desc')
            ->get();

        $transformed = $goals->map(function ($goal) {
            return $this->transformGoal($goal);
        });

        return response()->json([
            'goals' => $transformed,
            'milestones' => $transformed->pluck('milestones')->flatten(1),
            'quarterlyPlans' => $transformed->pluck('quarterlyPlans')->flatten(1)
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'area_id'       => 'required|integer',
            'title'         => 'required|string|max:255',
            'target_amount' => 'numeric',
            'current_amount'=> 'numeric',
            'due_date'      => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $goal = Goal::create([
            'user_id'          => $request->input('user_id', 1),
            'area_id'          => $request->input('area_id'),
            'title'            => $request->input('title'),
            'description'      => $request->input('description'),
            'target_amount'    => $request->input('target_amount', 0),
            'current_amount'   => $request->input('current_amount', 0),
            'due_date'         => $request->input('due_date'),
            'completed'        => $request->input('completed', 0),
            'progress_percent' => $request->input('progress_percent', 0),
        ]);

        if ($request->has('links') && is_array($request->input('links'))) {
            foreach ($request->input('links') as $link) {
                if (isset($this->morphMap[$link['type']])) {
                    GoalLink::create([
                        'goal_id'       => $goal->id,
                        'linkable_type' => $this->morphMap[$link['type']],
                        'linkable_id'   => $link['refId']
                    ]);
                }
            }
        }

        $goal->load(['area', 'milestones', 'quarterlyPlans', 'links.linkable']);

        return response()->json([
            'message' => 'Goal successfully created!',
            'goal'    => $this->transformGoal($goal)
        ], 201);
    }

    public function show($id): JsonResponse
    {
        // 🌟 Ambil relasi bersarang links.linkable sekalian
        $goal = Goal::with(['area', 'milestones', 'quarterlyPlans', 'links.linkable', 'reviews'])->find($id);

        if (!$goal) {
            return response()->json(['message' => 'Goal not found'], 404);
        }

        return response()->json($this->transformGoal($goal));
    }

    public function update(Request $request, $id): JsonResponse
    {
        $goal = Goal::find($id);

        if (!$goal) {
            return response()->json(['message' => 'Goal not found'], 404);
        }

        $inputs = $request->only([
            'area_id', 'title', 'description', 'target_amount', 
            'current_amount', 'due_date', 'completed', 'progress_percent'
        ]);

        if (isset($inputs['current_amount']) || isset($inputs['target_amount'])) {
            $current = isset($inputs['current_amount']) ? $inputs['current_amount'] : $goal->current_amount;
            $target = isset($inputs['target_amount']) ? $inputs['target_amount'] : $goal->target_amount;
            if ($target > 0) {
                $inputs['progress_percent'] = (int) min(100, max(0, round(($current / $target) * 100)));
            }
        }

        $goal->update($inputs);

        if ($request->has('links') && is_array($request->input('links'))) {
            GoalLink::where('goal_id', $goal->id)->delete();

            foreach ($request->input('links') as $link) {
                if (isset($this->morphMap[$link['type']])) {
                    GoalLink::create([
                        'goal_id'       => $goal->id,
                        'linkable_type' => $this->morphMap[$link['type']],
                        'linkable_id'   => $link['refId']
                    ]);
                }
            }
        }

        $goal->load(['area', 'milestones', 'quarterlyPlans', 'links.linkable']);

        return response()->json([
            'message' => 'Goal successfully updated!',
            'goal'    => $this->transformGoal($goal)
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $goal = Goal::find($id);

        if (!$goal) {
            return response()->json(['message' => 'Goal not found'], 404);
        }

        $goal->delete();

        return response()->json([
            'message' => 'Goal successfully deleted!'
        ]);
    }
}