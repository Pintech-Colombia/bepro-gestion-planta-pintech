<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RawMaterialType;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RawMaterialCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Familias base: solo se buscan o crean cuando se va a crear una categoría suya. Así, una familia renombrada desde
        // Configuración no reaparece con el nombre viejo al volver a sembrar (DatabaseSeederTest).
        $families = [
            'chemicals' => ['Químicos', 'Pigmentos, resinas, solventes, aditivos y demás componentes químicos'],
            'packaging' => ['Envase y empaque', 'Envases, etiquetas y material del termoencogido'],
        ];

        $categories = [
            [
                'family' => 'chemicals',
                'name' => 'Químicos y Reactivos',
                'description' => 'Pigmentos, resinas, solventes, aditivos y otros componentes químicos',
                'type' => RawMaterialType::Chemical,
            ],
            [
                'family' => 'packaging',
                'name' => 'Envases Metálicos',
                'description' => 'Cuñetes, galones, tambores y otros recipientes metálicos para productos terminados',
                'type' => RawMaterialType::Container,
            ],
            [
                'family' => 'packaging',
                'name' => 'Envases Plásticos',
                'description' => 'Galones, bidones, tambores y otros recipientes plásticos',
                'type' => RawMaterialType::Container,
            ],
            [
                'family' => 'packaging',
                'name' => 'Etiquetas',
                'description' => 'Etiquetas con el diseño del producto que se pegan al envase',
                'type' => RawMaterialType::Label,
            ],
            [
                'family' => 'packaging',
                'name' => 'Bandejas',
                'description' => 'Bandejas para el termoencogido',
                'type' => RawMaterialType::SecondaryPackaging,
            ],
            [
                'family' => 'packaging',
                'name' => 'Bolsas',
                'description' => 'Bolsas para el termoencogido',
                'type' => RawMaterialType::SecondaryPackaging,
            ],
        ];

        // Solo la primera vez, con la tabla vacía. Sin código, una categoría renombrada desde Configuración no se
        // reconoce por nombre: buscarla volvería a crear la base con el nombre viejo (DatabaseSeederTest). Y con las
        // categorías reales ya cargadas (import:base-data), sembrar no agrega estas.
        if (RawMaterialCategory::query()->exists()) {
            $this->command->info('Raw material categories already exist: skipped.');

            return;
        }

        // Todas o ninguna: si falla a mitad, la tabla queda vacía y la próxima corrida las crea completas.
        DB::transaction(function () use ($categories, $families): void {
            foreach ($categories as $category) {
                $family = $this->family(...$families[$category['family']]);
                unset($category['family']);

                RawMaterialCategory::create([...$category, 'family_id' => $family->id, 'is_active' => true]);
            }
        });

        $this->command->info(RawMaterialCategory::count().' raw material categories.');
    }

    private function family(string $name, string $description): RawMaterialFamily
    {
        return RawMaterialFamily::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? RawMaterialFamily::create(['name' => $name, 'description' => $description, 'is_active' => true]);
    }
}
