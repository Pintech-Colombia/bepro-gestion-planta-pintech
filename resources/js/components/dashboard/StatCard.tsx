import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface StatCardProps {
    icon: LucideIcon;
    label: string;
    /** Sin valor (el usuario no tiene permiso sobre el dato), la tarjeta no se muestra. */
    value?: number;
    iconClassName?: string;
    /** Con enlace, la tarjeta entera abre la pantalla del dato (p. ej. el listado ya filtrado). */
    href?: string;
}

export function StatCard({
    icon: Icon,
    label,
    value,
    iconClassName,
    href,
}: StatCardProps) {
    if (value === undefined) {
        return null;
    }

    const card = (
        <Card
            className={cn(
                'border-none shadow-lg',
                href && 'h-full transition hover:bg-muted/40',
            )}
        >
            <CardContent className="flex items-center gap-4 p-5">
                <div className={`rounded-full p-3 ${iconClassName}`}>
                    <Icon className="h-5 w-5" />
                </div>
                <div>
                    <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        {label}
                    </p>
                    <p className="text-3xl font-semibold">{value}</p>
                </div>
            </CardContent>
        </Card>
    );

    return href ? (
        <Link
            href={href}
            className="block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {card}
        </Link>
    ) : (
        card
    );
}
