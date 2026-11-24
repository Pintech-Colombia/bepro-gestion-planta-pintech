<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RawMaterialType;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use Illuminate\Database\Seeder;

class RawMaterialCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Familias base: agrupan las categorías. Se buscan sin distinguir mayúsculas, porque el nombre se edita desde
        // Configuración; si ya existe, no se toca (firstOrCreate, DatabaseSeederTest).
        $families = [
            'chemicals' => $this->family('Químicos', 'Pigmentos, resinas, solventes, aditivos y demás componentes químicos'),
            'packaging' => $this->family('Envase y empaque', 'Envases, etiquetas y material del termoencogido'),
        ];

        $categories = [
            [
                'code' => 'QUIMICOS',
                'family' => 'chemicals',
                'name' => 'Químicos y Reactivos',
                'description' => 'Pigmentos, resinas, solventes, aditivos y otros componentes químicos',
                'type' => RawMaterialType::Chemical,
            ],
            [
                'code' => 'ENV-METAL',
                'family' => 'packaging',
                'name' => 'Envases Metálicos',
                'description' => 'Cuñetes, galones, tambores y otros recipientes metálicos para productos terminados',
                'type' => RawMaterialType::Container,
            ],
            [
                'code' => 'ENV-PLAST',
                'family' => 'packaging',
                'name' => 'Envases Plásticos',
                'description' => 'Galones, bidones, tambores y otros recipientes plásticos',
                'type' => RawMaterialType::Container,
            ],
            [
                'code' => 'ETIQUETAS',
                'family' => 'packaging',
                'name' => 'Etiquetas',
                'description' => 'Etiquetas con el diseño del producto que se pegan al envase',
                'type' => RawMaterialType::Label,
            ],
            [
                'code' => 'BANDEJAS',
                'family' => 'packaging',
                'name' => 'Bandejas',
                'description' => 'Bandejas para el termoencogido',
                'type' => RawMaterialType::SecondaryPackaging,
            ],
            [
                'code' => 'BOLSAS',
                'family' => 'packaging',
                'name' => 'Bolsas',
                'description' => 'Bolsas para el termoencogido',
                'type' => RawMaterialType::SecondaryPackaging,
            ],
        ];

        foreach ($categories as $category) {
            // Código y nombre son únicos y los dos se editan desde Configuración: si uno ya existe, la categoría ya
            // está. Buscar solo por código intentaría crear otra con el mismo nombre y chocaría con el índice único.
            $exists = RawMaterialCategory::query()
                ->where('code', $category['code'])
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($category['name'])])
                ->exists();

            if (! $exists) {
                $family = $families[$category['family']];
                unset($category['family']);

                // Solo al crearla: una categoría existente conserva la familia que le hayan puesto en Configuración.
                RawMaterialCategory::create([...$category, 'family_id' => $family->id, 'is_active' => true]);
            }
        }

        $this->command->info(RawMaterialCategory::count().' raw material categories.');
    }

    private function family(string $name, string $description): RawMaterialFamily
    {
        return RawMaterialFamily::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? RawMaterialFamily::create(['name' => $name, 'description' => $description, 'is_active' => true]);
    }
}
