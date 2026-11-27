import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { store as storeProductDocument } from '@/actions/App/Http/Controllers/ProductDocumentController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type ProductDocumentType = 'technical_data_sheet' | 'safety_data_sheet';

export type ProductDocumentTypeOption = {
    value: ProductDocumentType;
    label: string;
};

type DocumentUploadDialogProps = {
    productId: number;
    documentTypes: ProductDocumentTypeOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Botón que abre el diálogo (va dentro de `DialogTrigger`). */
    trigger: ReactNode;
};

/** Diálogo de subir un documento técnico (PDF) del producto. */
export function DocumentUploadDialog({
    productId,
    documentTypes,
    open,
    onOpenChange,
    trigger,
}: DocumentUploadDialogProps) {
    const documentForm = useForm({
        document_type: documentTypes[0]?.value ?? 'technical_data_sheet',
        document: null as File | null,
    });

    // Al cerrar sin guardar se descarta el archivo en memoria y los errores: el `<input type="file">` se desmonta.
    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            documentForm.reset();
            documentForm.clearErrors();
        }

        onOpenChange(nextOpen);
    };

    const handleDocumentSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        documentForm.post(storeProductDocument({ product: productId }).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                handleOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Subir documento técnico</DialogTitle>
                    <DialogDescription>
                        Elige el tipo y adjunta un PDF. Si ya existe un
                        documento del mismo tipo, se archiva la versión anterior
                        y queda vigente la nueva.
                    </DialogDescription>
                </DialogHeader>
                <form
                    noValidate
                    onSubmit={handleDocumentSubmit}
                    className="space-y-4"
                >
                    <div className="space-y-2">
                        <Label htmlFor="document_type">
                            Tipo de documento
                            <span
                                className="ml-0.5 text-destructive"
                                aria-hidden="true"
                            >
                                *
                            </span>
                        </Label>
                        <Select
                            value={documentForm.data.document_type}
                            onValueChange={(value) =>
                                documentForm.setData(
                                    'document_type',
                                    value as ProductDocumentType,
                                )
                            }
                        >
                            <SelectTrigger
                                id="document_type"
                                aria-required
                                aria-invalid={
                                    !!documentForm.errors.document_type
                                }
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {documentTypes.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {documentForm.errors.document_type && (
                            <p className="text-xs text-destructive">
                                {documentForm.errors.document_type}
                            </p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="document">
                            Archivo PDF
                            <span
                                className="ml-0.5 text-destructive"
                                aria-hidden="true"
                            >
                                *
                            </span>
                        </Label>
                        <Input
                            id="document"
                            type="file"
                            accept="application/pdf,.pdf"
                            aria-required
                            aria-invalid={!!documentForm.errors.document}
                            className="cursor-pointer file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary"
                            onChange={(event) =>
                                documentForm.setData(
                                    'document',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Tamaño máximo 10 MB. Solo se aceptan archivos PDF.
                        </p>
                        {documentForm.errors.document && (
                            <p className="text-xs text-destructive">
                                {documentForm.errors.document}
                            </p>
                        )}
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => handleOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={documentForm.processing}
                        >
                            {documentForm.processing
                                ? 'Subiendo...'
                                : 'Guardar'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
