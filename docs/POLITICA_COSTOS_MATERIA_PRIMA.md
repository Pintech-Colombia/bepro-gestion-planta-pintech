# Política de costos de materia prima

## Objetivo
Unificar la regla de costo de referencia para evitar diferencias entre:
- el costo teórico de las fórmulas y la lista de precios (`raw_materials.current_price`);
- el costo real del consumo en una OP (FIFO por lotes).

El precio de referencia de una materia prima **con control de inventario** sale de sus lotes, según la política
configurada. Una sin control de inventario (agua, etiquetas) tiene precio manual y el recálculo nunca lo toca.

### Archivos principales
- `app/Services/RawMaterialReferencePriceService.php` (`calculateReferencePrice`: la regla)
- `app/Jobs/RecalculateRawMaterialReferencePrice.php` (el recálculo en segundo plano)
- `config/production.php` (la política)

## Políticas disponibles
Se define en `config/production.php`, fija en el código e igual en todos los entornos (es una regla del negocio, no
del servidor):

```php
'raw_material_reference_price_policy' => 'conservative_max',
```

Valores permitidos: `conservative_max` (la que se usa), `weighted_average` y `last_lot`.

## Regla de cada política
Ninguna política compara precios entre sí ni toma «el máximo de todos»: cada una toma **el primer valor que exista** en
un orden fijo. «Lote con existencias» es un lote con `remaining_quantity > 0`.

| Política | 1.º | 2.º, si no hay el 1.º | 3.º, si no hay ninguno |
| :-- | :-- | :-- | :-- |
| `conservative_max` | El **lote más caro con existencias** | El precio actual (se conserva) | El precio del último lote ingresado |
| `weighted_average` | El promedio ponderado de los lotes con existencias | El precio del último lote ingresado | El precio actual |
| `last_lot` | El precio del último lote ingresado | El promedio ponderado de los lotes con existencias | El precio actual |

### `conservative_max`, con un ejemplo
Tres lotes de la misma materia prima a 1.200, 1.150 y 1.240 por kg, los tres con existencias: el precio de referencia es
**1.240**. Cuando se gasta todo el lote de 1.240, pasa a **1.200**, el más caro de los que quedan: no se sigue cobrando
un precio de una materia prima que ya no está en bodega. Si se gastan todos, se conserva el último precio calculado hasta
la próxima compra.

Por qué: protege el margen (la lista se costea con el lote más caro que la planta todavía puede consumir) sin inflarla con
compras que ya se acabaron. Ojo: no es el precio al que la OP consume, que es FIFO por lotes (del más viejo al más nuevo).

## Cuándo se recalcula el precio de referencia
En segundo plano (`RecalculateRawMaterialReferencePrice`, después de confirmar la transacción), nunca en la petición:
1. al registrar un movimiento de inventario de materia prima (`InventoryService::storeMovement`);
2. al completar una OP, para cada materia prima que consumió;
3. al registrar un termoencogido, para los materiales que gastó;
4. al activar el control de inventario de una materia prima que ya tiene lotes.

Los movimientos de inventario no se editan ni se eliminan: un error se corrige con un movimiento contrario
(`docs/POLITICA_ELIMINACION.md`). El seeder de lotes (`InventoryBatchSeeder`) también sincroniza el precio al sembrar.

## Impacto en costos de produccion
Cuando cambia `raw_materials.current_price` por esta politica, se dispara
`ProductionCostRecalculationService::recalculateForRawMaterial`:
- los productos cuya **fórmula de referencia de precio** usa la materia prima se recalculan completos (granel y
  presentaciones). Las demás fórmulas activas sirven para producir, pero no costean (D-FOR1);
- las presentaciones que la usan como **envase o etiqueta** se recalculan solas, con el costo de granel que ya tiene su
  producto (`repriceVariantsUsingPackagingMaterial`). No se recalcula el granel, que no cambió, ni se deja un registro
  sin variación en su historial. Cubre también los productos sin fórmula de referencia, pero **no** los que no tienen costo de
  granel (`products.current_cost` vacío): sus presentaciones quedarían en envase + etiqueta, así que no se tocan.

Guardar una presentación la recalcula de la misma forma (`repriceVariantsOfProduct`), con la misma excepción.

## Costo de granel de la fórmula
`ProductionCostRecalculationService::recalculateForProduct` costea con la fórmula de referencia: por cada línea, la
cantidad se pasa a la unidad de su materia prima con `FormulaService::convertQuantity()` (cantidad × equivalencia de
origen ÷ de destino, 10 decimales) y se multiplica por su precio de referencia. Antes del PR 9 de 3.6 no se convertía:
500 g de una MP que se compra por kg se costeaban como 500 kg (FOR-06).

Se recalcula también:
- al cambiar la fórmula de referencia, o las líneas de la que lo es;
- al corregir la equivalencia de una unidad en uso (`RecalculateUnitDependentCosts`, en cola): los productos cuya
  referencia la usa en una línea o como unidad de la materia prima. Una unidad en uso no cambia de base (volumen ↔ peso).

Un producto que no se puede costear (una línea cuya unidad no se convierte, que la aplicación ya no deja guardar: solo
llega por una carga directa) no frena los recorridos ni hace fallar un cambio de fórmula ya guardado: queda en el log con
`Log::warning` y su costo se deja como estaba (`recalculateForProductOrLog`).

El plan de la OP no usa esta cuenta: calcula con el factor de 6 decimales que guarda en cada línea
(`FormulaService::orderLineRequirement()`), el mismo con que el servidor convierte el consumo real
(`ProductionOrderLineUnits`). Con L↔galón difiere un 0,0002 % del costo de lista.

**Producto como ingrediente (PT consumible).** Una línea de producto vale sus kilos × su **costo teórico por kilo**
(`ProductLineTheoreticalCost`): sale de la fórmula de referencia del producto fuente a precios de hoy, **con su CIF y sin
su margen** (el margen se gana una vez, en la venta del producto final):
- a granel: `current_cost` del producto (granel por galón) × (1 + CIF) ÷ peso por galón;
- con una presentación: `current_cost` de la presentación (granel × galones + envase + etiqueta) × (1 + CIF) ÷
  (galones × peso por galón).

Peso por galón: el de referencia del producto (`reference_density_kg_per_gallon`); si no tiene, la densidad de su última
OP completada. Si no se puede calcular (sin forma de costeo, sin peso o sin costo del producto fuente), el producto que la
lleva conserva su costo (`recalculateForProductOrLog`) y la ficha de la fórmula muestra el motivo. Se recalcula **en
cadena y en segundo plano** (`RecalculateProductDependentCosts`): MP → blanco → RAL 7016 → …; al cambiar el costo del
producto fuente, sus presentaciones, su CIF, su peso de referencia o su último lote. Los ciclos se rechazan al guardar
o activar la fórmula (`FormulaProductGraph`). Ese costo teórico es el de la lista de precios: en una OP, el producto
cuesta lo de sus fuentes reales (ver «Producto terminado usado en otra OP»).

Costo teórico de una presentación: `(granel × presentación + envase + etiqueta) × (1 + CIF)` (`VariantPricingService`).
El precio solo cambia si el costo varía más que el umbral del producto.

Esto no altera el costo real FIFO del cierre de OP.

## Costo real del empaque al completar la OP
Por cada presentación del plan de envasado, al completar la OP (`CompleteProductionOrderAction`):
- se consumen solo los **envases nuevos** (`new_containers_used`, por defecto las unidades envasadas); los reutilizados
  no se descuentan ni cuestan;
- se consumen las **etiquetas usadas** (`labels_used`, por defecto las unidades envasadas) de la etiqueta del plan;
- los dos con la regla de cualquier materia prima: si controla inventario, por FIFO al precio de sus lotes; si no, sin
  lote y a su precio de referencia (`current_price`). Un envase o una etiqueta pueden estar en cualquiera de los dos;
- costo por unidad del lote = granel por unidad + (costo de envases nuevos + costo de etiquetas) ÷ unidades envasadas,
  sin CIF. Sin tope en las cantidades: la merma (envase dañado, etiqueta mal pegada) es costo del lote. Es el `cost_price`
  del movimiento de entrada, que sigue sin CIF;
- el lote guarda además su **costo de traslado** (`finished_product_batches.transfer_unit_cost`): ese costo por unidad
  con el CIF del producto, con la regla del saldo (CIF nulo o 0: el mismo costo). Queda fijo desde que se completa la
  OP, aunque después cambie el CIF. Un envase reutilizado no suma: su costo ya viajó con el lote del que salió. Los lotes
  anteriores a la columna lo tienen estimado (`transfer_cost_estimated`): el costo de su entrada con el CIF que tenía el
  producto al migrar.

La vista previa de costos de la OP aplica la misma regla con precios estimados.

## Producto terminado usado en otra OP («Productos a usar»)
Una OP puede llevar producto terminado como ingrediente (`production_order_product_lines`). Cada kilo sale de una
fuente del mismo producto, y su costo entra al **granel** de la OP junto con la MP, los ajustes y los saldos
(`ProductionOrderCostSummary`: `products_used`):
- **saldo**: los galones descontados × el costo por galón que el saldo guardó al nacer (con el CIF de su OP);
- **lote de PT** (`production_order_batch_consumptions`), al completar (`BatchSourceDrawdown`):
  - salen del inventario **las unidades de la fuente** (las que se sacaron de bodega), no unas calculadas desde los kilos
    usados: el peso por unidad es teórico (galones de la presentación × peso por galón de la OP del lote,
    `FinishedProductBatch::kgPerUnit`, la única cuenta) y un llenado de más no abre otra unidad;
  - si quedó producto en un envase abierto, se **pesa** (nunca se calcula como resto: un saldo solo existe si alguien lo
    pesó) y vuelve al **saldo de la OP que fabricó el lote**, a **precio de granel**: el costo por galón con CIF de esa OP
    (el de su saldo si ya lo tiene; si no, `production_costs.unit_cost` con el `cif_percentage` que guardó, la misma
    cuenta de su sobrante), sin envase. Valor = kilos × costo por galón ÷ peso por galón (se multiplica antes de dividir);
  - **costo de la fuente = unidades × costo de traslado − valor de lo que volvió al saldo**. La OP que abrió el envase
    paga el envase completo; los envases vacíos se cuentan perdidos;
  - un lote sin costo de traslado (creado por una imagen anterior a la columna) lo recibe estimado en ese momento, con la
    regla del relleno (`EstimatedTransferCostBackfill`), marcado como estimado.

Ejemplo (hoja de planta): 20 cuñetes de 4 gal a 5,85 kg/gal (23,4 kg cada uno) con costo de traslado 226.908,2885; granel
de su OP 41.827,8888 por galón con CIF 15 % = 48.102,0721. Se usaron 462,3 kg y quedaron 5,7 kg pesados: el saldo de
origen sube 5,7 kg por 5,7 × 48.102,0721 ÷ 5,85 = 46.868,6856, y la OP paga 20 × 226.908,2885 − 46.868,6856 =
4.491.297,0844. Lo que paga la OP más lo que vuelve al saldo es exactamente lo que salió del lote.

Lo que vuelve al saldo no suma al rendimiento ni al sobrante de la OP que abrió el envase.

## Termoencogido: gasto general, no costo del lote
El termoencogido (3.8) se registra aparte de la OP, cuando se hace (`RegisterShrinkWrapAction`): una OP completada, un
tipo y las aplicaciones.
- se consume receta × aplicaciones de la **bodega de la OP**, con la regla de cualquier materia prima (FIFO si controla
  inventario; si no, sin lote y a su precio de referencia);
- su costo queda en el registro (`shrink_wraps.total_cost`) como **gasto general**: no entra al costo del lote, de la
  presentación ni de la lista de precios;
- por eso sus salidas se enlazan al registro (`inventory_movements.shrink_wrap_id`) y **no** a la OP: un reporte de
  consumo por OP no debe contarlas;
- las salidas se fechan con el **día en que se registra**, aunque el termoencogido haya sido semanas antes (esa fecha
  queda en el registro): el FIFO toma los lotes con saldo hoy, y fecharlas atrás podría dejar una salida anterior a la
  entrada de su lote;
- un costo que no cabe en su columna (posible solo sin control de inventario, que no tiene stock que lo frene) se
  rechaza como error de validación, nunca como un 500.

## Materias primas sin control de inventario
Las que no se compran por lotes ni se cuentan (`tracks_inventory = false`: agua, etiquetas) no tienen compras de donde
sacar el precio. Su `current_price` se escribe a mano en el formulario de la materia prima, solo con el permiso
`costs.update`, y al cambiarlo se guarda el anterior en `previous_price` y se encola
`RecalculateRawMaterialDependentCosts`. La OP registra su consumo sin lote y lo costea a ese precio.

- El precio es **obligatorio**, aunque sea 0 escrito a propósito, y no se puede borrar: vacío, los costos lo tomarían
  como 0 sin que nadie lo decidiera. Por eso solo quien tiene `costs.update` crea materias primas sin control de
  inventario o cambia el control de una, en cualquier sentido.
- Activar el control de una materia prima que usan OP abiertas pide confirmación: desde ahí necesita saldo y esas OP no
  se podrán completar hasta registrar sus compras.
- Al cambiar el control se reevalúa la alerta de stock bajo: sin control no hay alerta (la abierta se resuelve).
- No admiten movimientos manuales (compras ni salidas): un lote suyo nunca se descontaría.
- El cambio dispara la misma alerta de variación de precio que las compras, si la materia prima tiene umbral.
- El recálculo desde lotes nunca toca su precio, aunque tenga lotes (una compra registrada por error o de cuando sí
  controlaba inventario).
- Una materia prima con control de inventario no acepta precio manual.
- No se puede quitar el control de inventario con saldo en bodega (quedaría congelado); activarlo siempre se puede, y
  desde ese momento el precio sale de sus lotes (si ya tiene, se recalcula al activarlo).

## Como cambiar la politica mas adelante
Con un PR que cambie el valor en `config/production.php` (por ejemplo a `'weighted_average'`): queda revisado y en el
historial, y llega a staging y a producción con el despliegue, que vuelve a armar la caché de configuración. Los precios
de referencia se recalculan a medida que cada materia prima tiene movimientos (entradas, salidas, OP completadas,
termoencogido) o cuando empieza a controlar inventario. No hay un comando que las recalcule todas de una vez: hasta
que cada una tenga su próximo movimiento, convivirán precios calculados con la política anterior y con la nueva.
Un valor que no sea uno de los tres permitidos hace fallar la prueba de configuración y el recálculo.

## Recomendacion operativa
Si deseas modo conservador (criterio de gerencia actual), mantener `conservative_max`.
Si en el futuro desean precios mas cercanos al costo medio real del inventario vivo, migrar a `weighted_average`.
