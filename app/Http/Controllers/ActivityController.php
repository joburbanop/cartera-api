<?php

namespace App\Http\Controllers;

use App\Enums\PermissionName;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Financial\Refinancing\RefinanceContractService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    use ApiResponse;

    /**
     * @var array<string, class-string<Model>>
     */
    private const SUBJECT_MAP = [
        'customer' => Customer::class,
        'lot' => Lot::class,
        'contract' => Contract::class,
        'project' => Project::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'subject_type' => ['required', 'string', Rule::in(array_keys(self::SUBJECT_MAP))],
            'subject_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $seesFullLog = (bool) $request->user()?->can(PermissionName::BITACORA_VIEW->value);

        if (! $seesFullLog && $validated['subject_type'] !== 'contract') {
            abort(403, 'Solo puede consultar la bitácora de refinanciaciones de un contrato.');
        }

        $modelClass = self::SUBJECT_MAP[$validated['subject_type']];
        $subject = $modelClass::query()->findOrFail((int) $validated['subject_id']);

        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));

        $paginator = Activity::query()
            ->with('causer')
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->when(! $seesFullLog, fn (Builder $query) => $query->where(
                fn (Builder $refinancings) => $refinancings
                    ->where('log_name', RefinanceContractService::LOG_NAME)
                    // Refinanciaciones anteriores al canal propio de bitácora.
                    ->orWhere('description', 'like', 'Refinanció el contrato%'),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (Activity $activity) {
            $properties = $activity->properties?->toArray() ?? [];
            $before = $properties['old'] ?? $properties['before'] ?? [];
            $after = $properties['attributes'] ?? $properties['after'] ?? [];
            unset($properties['old'], $properties['before'], $properties['attributes'], $properties['after']);

            return [
                'id' => $activity->id,
                'date' => $activity->created_at?->toIso8601String(),
                'description' => $activity->description,
                'causer_name' => $activity->causer?->name ?? 'Sistema',
                'changes' => [
                    'before' => (object) $before,
                    'after' => (object) $after,
                ],
                'properties' => (object) $properties,
            ];
        });

        return $this->successResponse($paginator, 'Bitácora obtenida exitosamente.');
    }
}
