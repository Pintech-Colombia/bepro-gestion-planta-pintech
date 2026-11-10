<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SalesOrderPriority;
use App\Enums\SalesOrderStatus;
use App\Enums\SystemRole;
use App\Models\Client;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Support\DeploymentEnvironment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Pedidos de demostración: tres clientes ficticios con dos pedidos cada uno, más uno «Por aprobar» y uno «Retenido».
 * Datos fijos, sin factories: las factories usan Faker, que es una dependencia de desarrollo y no está en la imagen del
 * servidor, y estos datos también se siembran en staging.
 */
class SalesOrderSeeder extends Seeder
{
    public function run(): void
    {
        if (! DeploymentEnvironment::allowsDemoData()) {
            return;
        }

        // En staging no hay usuarios sembrados: el primero es el superadministrador creado con `users:create-admin`.
        $creator = User::query()->role(SystemRole::Commercial->value)->first()
            ?? User::query()->role(SystemRole::Admin->value)->first()
            ?? User::query()->role(SystemRole::SuperAdmin->value)->first();

        if ($creator === null) {
            $this->command?->warn('SalesOrderSeeder omitido: no hay un usuario que figure como creador. Crea el primero con: php artisan users:create-admin');

            return;
        }

        $variants = ProductVariant::query()->where('is_active', true)->orderBy('id')->limit(6)->get();

        if ($variants->isEmpty()) {
            $this->command?->warn('SalesOrderSeeder omitido: no hay presentaciones. Corre antes ProductVariantSeeder.');

            return;
        }

        $clients = [];
        $alreadySeeded = SalesOrder::query()->exists();

        $demoClients = [
            ['business_name' => 'Demo Cliente Alpha', 'nit' => '900100001'],
            ['business_name' => 'Demo Cliente Beta', 'nit' => '900100002'],
            ['business_name' => 'Demo Cliente Gamma', 'nit' => '900100003'],
        ];

        // Dos pedidos por cliente: uno pendiente y otro en curso, con una a tres líneas.
        $orders = [
            ['status' => SalesOrderStatus::Pending, 'priority' => SalesOrderPriority::Medium, 'days' => 10, 'lines' => [[0, 12], [1, 4]]],
            ['status' => SalesOrderStatus::InProgress, 'priority' => SalesOrderPriority::High, 'days' => 5, 'lines' => [[2, 20], [3, 6], [4, 2]]],
        ];

        foreach ($demoClients as $clientIndex => $clientData) {
            $client = Client::firstOrCreate(
                ['nit' => $clientData['nit']],
                [
                    'business_name' => $clientData['business_name'],
                    'contact_name' => 'Contacto Demo',
                    'phone' => '3000000000',
                ]
            );
            $clients[] = $client;

            // Los seis pedidos base se siembran una vez: si ya hay pedidos, no se repiten.
            if ($alreadySeeded) {
                continue;
            }

            foreach ($orders as $order) {
                $salesOrder = SalesOrder::create([
                    'client_id' => $client->id,
                    'status' => $order['status']->value,
                    'priority' => $order['priority']->value,
                    'required_date' => now()->addDays($order['days'] + $clientIndex)->toDateString(),
                    'client_business_name' => $client->business_name,
                    'client_nit' => $client->nit,
                    'client_contact_name' => $client->contact_name,
                    'client_phone' => $client->phone,
                    'created_by' => $creator->id,
                ]);

                foreach ($order['lines'] as [$variantIndex, $quantity]) {
                    $variant = $variants[($variantIndex + $clientIndex) % $variants->count()];

                    SalesOrderItem::create([
                        'sales_order_id' => $salesOrder->id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'quantity' => $quantity,
                    ]);
                }
            }
        }

        // Quien retiene es quien aprueba (Admin o SuperAdmin); sin ninguno, el mismo creador.
        $approver = User::query()->role(SystemRole::Admin->value)->first()
            ?? User::query()->role(SystemRole::SuperAdmin->value)->first()
            ?? $creator;

        $this->seedApprovalDemo($clients, $variants, $creator, $approver);
    }

    /**
     * Un pedido «Por aprobar» y uno «Retenido», para ver la aprobación. Se buscan por su nota (`firstOrCreate`): si se
     * aprueban desde la aplicación no se vuelven a crear, y una base sembrada antes de la aprobación los recibe al
     * sembrar de nuevo.
     *
     * @param  list<Client>  $clients
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function seedApprovalDemo(array $clients, Collection $variants, User $creator, User $approver): void
    {
        $demos = [
            [
                'client' => $clients[0],
                'status' => SalesOrderStatus::PendingApproval,
                'notes' => 'Pedido de demostración por aprobar.',
                'line' => [5, 8],
                'held' => false,
            ],
            [
                'client' => $clients[1],
                'status' => SalesOrderStatus::OnHold,
                'notes' => 'Pedido de demostración retenido.',
                'line' => [0, 3],
                'held' => true,
            ],
        ];

        foreach ($demos as $demo) {
            $client = $demo['client'];

            $salesOrder = SalesOrder::firstOrCreate(
                ['client_id' => $client->id, 'notes' => $demo['notes']],
                [
                    'status' => $demo['status']->value,
                    'priority' => SalesOrderPriority::Medium->value,
                    'required_date' => now()->addDays(7)->toDateString(),
                    'client_business_name' => $client->business_name,
                    'client_nit' => $client->nit,
                    'client_contact_name' => $client->contact_name,
                    'client_phone' => $client->phone,
                    'created_by' => $creator->id,
                    'held_by' => $demo['held'] ? $approver->id : null,
                    'held_at' => $demo['held'] ? now() : null,
                    'hold_reason' => $demo['held'] ? 'Cartera vencida: se aprueba cuando el cliente se ponga al día.' : null,
                ]
            );

            if (! $salesOrder->wasRecentlyCreated) {
                continue;
            }

            [$variantIndex, $quantity] = $demo['line'];
            $variant = $variants[$variantIndex % $variants->count()];

            SalesOrderItem::create([
                'sales_order_id' => $salesOrder->id,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
            ]);
        }
    }
}
