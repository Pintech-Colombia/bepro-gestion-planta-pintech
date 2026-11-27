import { router } from '@inertiajs/react';
import { ExternalLink, FileStack, FileText, Trash2 } from 'lucide-react';
import { useState } from 'react';

import {
    destroy as destroyProductDocument,
    download as downloadProductDocument,
} from '@/actions/App/Http/Controllers/ProductDocumentController';
import { DetailItem, DetailList } from '@/components/detail-list';
import { FormattedDate } from '@/components/formatted-date';
import Heading from '@/components/heading';
import { DocumentUploadDialog } from '@/components/products/document-upload-dialog';
import type {
    ProductDocumentType,
    ProductDocumentTypeOption,
} from '@/components/products/document-upload-dialog';
import { ToneBadge } from '@/components/tone-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useConfirm } from '@/hooks/use-confirm';
import { formatNumber } from '@/lib/formatters';

export type ProductDocumentItem = {
    id: number;
    document_type: ProductDocumentType;
    file_name: string;
    file_size: number;
    version: number;
    created_at: string;
};

export type CertificateRange = { label: string; range: string };

type ProductQualityTabProps = {
    productId: number;
    /** Rango de cada indicador del certificado de calidad que tiene uno. */
    certificateRanges: CertificateRange[];
    documents: ProductDocumentItem[];
    documentTypes: ProductDocumentTypeOption[];
    canManageDocuments: boolean;
    canDownloadDocuments: boolean;
};

/** Pestaña «Calidad y documentos» de la ficha de producto: rangos del certificado y documentación técnica. */
export function ProductQualityTab({
    productId,
    certificateRanges,
    documents,
    documentTypes,
    canManageDocuments,
    canDownloadDocuments,
}: ProductQualityTabProps) {
    const confirm = useConfirm();
    const [isDocumentDialogOpen, setIsDocumentDialogOpen] = useState(false);

    const handleDeleteDocument = async (document: ProductDocumentItem) => {
        if (
            await confirm({
                title: '¿Eliminar este documento técnico?',
                confirmLabel: 'Eliminar',
                destructive: true,
            })
        ) {
            router.delete(
                destroyProductDocument({ document: document.id }).url,
                { preserveScroll: true },
            );
        }
    };

    return (
        <div className="space-y-6">
            {certificateRanges.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>Rangos del certificado de calidad</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DetailList columns={3}>
                            {certificateRanges.map(({ label, range }) => (
                                <DetailItem
                                    key={label}
                                    label={label}
                                    value={range}
                                    valueClassName="font-mono"
                                />
                            ))}
                        </DetailList>
                    </CardContent>
                </Card>
            )}

            <section className="space-y-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <Heading
                        title="Documentación técnica"
                        description="PDFs reutilizables vinculados a este producto. Aparecen automáticamente en la landing pública de cada orden completada, junto al certificado de calidad del lote."
                    />
                    {canManageDocuments && (
                        <DocumentUploadDialog
                            productId={productId}
                            documentTypes={documentTypes}
                            open={isDocumentDialogOpen}
                            onOpenChange={setIsDocumentDialogOpen}
                            trigger={
                                <Button size="sm" className="shrink-0">
                                    Subir documento
                                </Button>
                            }
                        />
                    )}
                </div>

                <Card className="overflow-hidden py-0">
                    <CardContent className="p-0">
                        {documents.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
                                <div className="flex size-14 items-center justify-center rounded-full bg-muted">
                                    <FileStack className="size-7 text-muted-foreground" />
                                </div>
                                <div className="max-w-sm space-y-1">
                                    <p className="text-sm font-medium text-foreground">
                                        Sin documentos técnicos
                                    </p>
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        Carga la ficha técnica y la hoja de
                                        seguridad para que los lotes completados
                                        muestren enlaces de descarga públicos
                                        coherentes con tu catálogo.
                                    </p>
                                </div>
                                {canManageDocuments && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setIsDocumentDialogOpen(true)
                                        }
                                    >
                                        Subir el primero
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <ul className="divide-y divide-border">
                                {documents.map((document) => {
                                    const typeLabel =
                                        documentTypes.find(
                                            (type) =>
                                                type.value ===
                                                document.document_type,
                                        )?.label ?? document.document_type;

                                    return (
                                        <li
                                            key={document.id}
                                            className="flex flex-col gap-4 px-6 py-4 md:flex-row md:items-center md:justify-between"
                                        >
                                            <div className="flex min-w-0 flex-1 items-start gap-4">
                                                <FileText
                                                    aria-hidden="true"
                                                    className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                                />
                                                <div className="min-w-0 space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="truncate text-sm font-medium text-foreground">
                                                            {document.file_name}
                                                        </p>
                                                        <ToneBadge className="shrink-0">
                                                            {typeLabel}
                                                        </ToneBadge>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatBytes(
                                                            document.file_size,
                                                        )}
                                                        <span className="mx-1.5 text-border">
                                                            ·
                                                        </span>
                                                        Versión{' '}
                                                        {document.version}
                                                        <span className="mx-1.5 text-border">
                                                            ·
                                                        </span>
                                                        <FormattedDate
                                                            value={
                                                                document.created_at
                                                            }
                                                            format="date"
                                                        />
                                                    </p>
                                                </div>
                                            </div>
                                            {(canDownloadDocuments ||
                                                canManageDocuments) && (
                                                <div className="flex shrink-0 items-center justify-end gap-1 md:pl-4">
                                                    {canDownloadDocuments && (
                                                        <Tooltip>
                                                            <TooltipTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="outline"
                                                                    size="icon"
                                                                    asChild
                                                                >
                                                                    <a
                                                                        href={
                                                                            downloadProductDocument(
                                                                                {
                                                                                    document:
                                                                                        document.id,
                                                                                },
                                                                            )
                                                                                .url
                                                                        }
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                    >
                                                                        <ExternalLink className="size-4" />
                                                                        <span className="sr-only">
                                                                            Ver
                                                                            PDF
                                                                        </span>
                                                                    </a>
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                Ver PDF
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    )}
                                                    {canManageDocuments && (
                                                        <Tooltip>
                                                            <TooltipTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="text-destructive-text hover:bg-destructive/10 hover:text-destructive-text"
                                                                    onClick={() =>
                                                                        handleDeleteDocument(
                                                                            document,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                    <span className="sr-only">
                                                                        Eliminar
                                                                        documento
                                                                    </span>
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                Eliminar
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </section>
        </div>
    );
}

function formatBytes(bytes: number) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${formatNumber(bytes / (1024 * 1024), { maxDecimals: 1 })} MB`;
}
