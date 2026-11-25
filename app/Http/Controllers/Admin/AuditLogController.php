<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filters\AuditLogFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAuditLogRequest;
use App\Services\Audit\AuditLogPresenter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogPresenter $presenter) {}

    public function index(IndexAuditLogRequest $request): Response
    {
        // `latest('id')` desempata: sin él la paginación puede repetir o saltarse registros.
        $baseQuery = Activity::with('causer')->latest()->latest('id');

        $logs = (new AuditLogFilter($request))
            ->apply($baseQuery)
            ->paginate(20)
            ->onEachSide(1)
            ->withQueryString();

        $logs->setCollection($this->presenter->present($logs->getCollection()));

        $logNames = Activity::select('log_name')->distinct()->whereNotNull('log_name')->pluck('log_name');
        $events = Activity::select('event')->distinct()->whereNotNull('event')->pluck('event');

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $request->validated(),
            // Por la etiqueta sin tildes: por bytes, «Órdenes de producción» quedaría al final de la lista.
            'options' => [
                'logNames' => $logNames
                    ->map(fn (string $name): array => ['value' => $name, 'label' => AuditLogPresenter::moduleLabel($name)])
                    ->sortBy(fn (array $option): string => Str::lower(Str::ascii($option['label'])))
                    ->values(),
                'events' => $events
                    ->map(fn (string $event): array => ['value' => $event, 'label' => AuditLogPresenter::eventLabel($event)])
                    ->sortBy(fn (array $option): string => Str::lower(Str::ascii($option['label'])))
                    ->values(),
            ],
        ]);
    }
}
