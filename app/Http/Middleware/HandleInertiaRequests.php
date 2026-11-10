<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AlertType;
use App\Enums\Permission;
use App\Models\Alert;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AlertService;
use App\Services\TimezoneService;
use App\Services\WarehouseContextService;
use App\Support\ToneEnums;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly WarehouseContextService $warehouseContextService,
        private readonly AlertService $alertService,
        private readonly TimezoneService $timezone,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Closures: Inertia solo las evalúa al renderizar una página, no en los POST que redirigen ni en las descargas.
            'auth' => fn (): array => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'job_title' => $user->job_title,
                    'email_verified_at' => $user->email_verified_at,
                    'signature_url' => $user->signature_url,
                    'is_active' => (bool) $user->is_active,
                    'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                ] : null,
            ],
            // Función: solo se ejecuta (y saca las alertas de la sesión) cuando la respuesta incluye el prop, no en las
            // recargas parciales que no lo piden.
            'newAlerts' => fn (): array => $this->visibleNewAlerts($request, $user),
            // Hoy en la planta: valor por defecto y límite de los campos de fecha, el mismo «hoy» que valida PlantDate.
            // `always`: también llega en las recargas parciales (las que abren los cajones de movimientos), para que una
            // página cargada ayer no proponga la fecha de ayer.
            'plantToday' => Inertia::always(fn (): string => $this->timezone->todayInPlant()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'warehouseContext' => fn (): ?array => $user ? $this->warehouseContext($request, $user) : null,
            'unresolvedAlertsCount' => fn (): int => $user?->can(Permission::AlertsView->value)
                ? $this->alertService->unresolvedCount($user)
                : 0,
            'recentAlerts' => fn (): array => $user?->can(Permission::AlertsView->value)
                ? $this->alertService->recentUnresolved($user, 5)
                : [],
            // Avisos personales de la campana: cada usuario, los suyos. No dependen de ningún permiso.
            'notifications' => fn (): ?array => $user ? $this->unreadNotifications($user) : null,
            // Contador del ítem «Pedidos» del menú: lo que espera la decisión de quien aprueba. Los retenidos esperan
            // al cliente, no cuentan.
            'pendingApprovalCount' => fn (): int => $user?->can(Permission::SalesOrdersApprove->value)
                ? SalesOrder::query()->visibleTo($user)->awaitingApproval()->count()
                : 0,
            // Etiqueta y tono de cada caso de los enums con color, para `<StatusBadge>` (ToneEnums). `once`: el cliente
            // la guarda y no se vuelve a enviar al navegar. Solo con sesión: si el login la recibiera vacía, el cliente
            // la daría por cargada y no la pediría tras entrar.
            ...($user ? ['enums' => Inertia::once(fn (): array => ToneEnums::shared())] : []),
        ];
    }

    /**
     * Cuántos avisos sin leer tiene el usuario y los diez más recientes.
     *
     * @return array{unread_count: int, items: list<array{id: string, message: string, sales_order_id: int|null, created_at: string|null}>}
     */
    private function unreadNotifications(User $user): array
    {
        return [
            'unread_count' => $user->unreadNotifications()->count(),
            // La relación ya los trae del más reciente al más antiguo.
            'items' => $user->unreadNotifications()
                ->limit(10)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'message' => (string) ($notification->data['message'] ?? ''),
                    'sales_order_id' => $notification->data['sales_order_id'] ?? null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Bodega activa y bodegas disponibles del usuario; la activa queda en sesión. Los controladores de movimientos la
     * resuelven por su cuenta, así que no dependen de que esta prop se haya evaluado en la petición.
     *
     * @return array<string, mixed>
     */
    private function warehouseContext(Request $request, User $user): array
    {
        $availableWarehouses = $this->warehouseContextService->availableWarehouses($user);
        $currentWarehouse = $this->warehouseContextService->resolveCurrentWarehouse(
            $user,
            $request->session()->get('current_warehouse_id'),
        );

        if ($currentWarehouse) {
            $request->session()->put('current_warehouse_id', $currentWarehouse->id);
        }

        return [
            'current' => $currentWarehouse ? [
                'id' => $currentWarehouse->id,
                'name' => $currentWarehouse->name,
                'city' => $currentWarehouse->city,
            ] : null,
            'available' => $availableWarehouses
                ->map(fn ($warehouse) => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'city' => $warehouse->city,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Alertas creadas en la petición anterior (notificación emergente), solo de los tipos que ve el usuario.
     * Se retiran de la sesión igualmente.
     *
     * @return array<int, mixed>
     */
    private function visibleNewAlerts(Request $request, ?User $user): array
    {
        $newAlerts = $request->session()->pull('new_alerts', []);
        $visibleTypes = array_map(fn (AlertType $type): string => $type->value, Alert::visibleTypesFor($user));

        return array_values(array_filter(
            is_array($newAlerts) ? $newAlerts : [],
            fn (mixed $alert): bool => is_array($alert) && in_array($alert['type'] ?? null, $visibleTypes, true),
        ));
    }
}
