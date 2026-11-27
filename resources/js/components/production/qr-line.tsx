import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { ProductionOrder } from '@/types/production-orders';

type QrLineProps = {
    order: ProductionOrder;
    landingLinkCopied: boolean;
    onCopyLandingLink: () => void;
};

/**
 * El QR del lote en una línea: abrir la página pública, descargar la imagen y copiar el enlace. La imagen y la URL
 * escrita se ven en el módulo de QR. Botones sin ícono: el texto ya lo dice (DESIGN.md §6).
 */
export function QrLine({
    order,
    landingLinkCopied,
    onCopyLandingLink,
}: QrLineProps) {
    return (
        <Card>
            {/* La columna derecha es angosta: el título arriba y los botones repartidos a lo ancho; si no caben, bajan de
                a uno a todo el ancho (nunca uno suelto a la izquierda). */}
            <CardContent className="space-y-3">
                <p className="text-sm font-medium">QR del lote</p>
                <div className="flex flex-wrap gap-2 *:flex-1 *:basis-28">
                    <Button variant="outline" size="sm" asChild>
                        <a
                            href={order.qr_landing_url ?? '#'}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Abrir página
                        </a>
                    </Button>
                    {order.qr_image_url && (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={order.qr_image_url}
                                download={`qr-lote-${order.lot_number}.png`}
                            >
                                Descargar QR
                            </a>
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onCopyLandingLink}
                    >
                        {landingLinkCopied ? 'Copiado' : 'Copiar enlace'}
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
