/**
 * Página de error del dominio del QR (bootstrap/app.php). La ve un cliente que escaneó una estampita, no un usuario del
 * ERP: sin enlace al login, que en este dominio no existe, y con el portafolio de la marca como salida.
 */
import { Head } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';

type Reason = 'not_found' | 'too_many_requests' | 'server_error';

const MESSAGES: Record<Reason, { title: string; description: string }> = {
    not_found: {
        title: 'Este lote no está disponible',
        description:
            'El enlace no corresponde a un lote activo. Si escaneaste el código de un envase, comunícate con nosotros.',
    },
    too_many_requests: {
        title: 'Demasiadas consultas',
        description: 'Intenta en un minuto.',
    },
    server_error: {
        title: 'No pudimos cargar la página',
        description: 'Intenta de nuevo en unos minutos.',
    },
};

type Props = {
    reason: Reason;
    portfolioUrl: string;
};

export default function PublicQrLandingUnavailable({
    reason,
    portfolioUrl,
}: Props) {
    const message = MESSAGES[reason] ?? MESSAGES.not_found;

    return (
        <>
            <Head title={message.title} />
            <main className="flex min-h-svh flex-col items-center justify-center bg-bepro-bg px-6 py-10 text-bepro-text antialiased">
                <div className="flex w-full max-w-md flex-col items-center gap-6 text-center">
                    <img
                        src="/images/beprocoatings.png"
                        alt="BePro Coatings"
                        className="h-20 w-auto object-contain"
                    />

                    <div className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight text-bepro-primary">
                            {message.title}
                        </h1>
                        <p className="text-sm leading-relaxed">
                            {message.description}
                        </p>
                    </div>

                    <a
                        href={portfolioUrl}
                        className="inline-flex items-center gap-2 rounded-lg bg-bepro-action px-4 py-2 text-sm font-semibold text-white transition-colors duration-200 hover:bg-bepro-action-hover"
                    >
                        Conocer BePro Coatings
                        <ExternalLink className="size-4" />
                    </a>
                </div>
            </main>
        </>
    );
}
