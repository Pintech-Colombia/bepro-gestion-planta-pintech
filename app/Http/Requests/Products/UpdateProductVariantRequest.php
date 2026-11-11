<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

/**
 * Editar valida igual que crear: la variante actual sale de la ruta (`currentVariant`).
 */
class UpdateProductVariantRequest extends StoreProductVariantRequest {}
