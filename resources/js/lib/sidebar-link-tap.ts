/** Lo que importa del toque: las teclas modificadoras (un `MouseEvent` de React o del navegador lo cumple). */
export type SidebarTap = {
    altKey: boolean;
    ctrlKey: boolean;
    metaKey: boolean;
    shiftKey: boolean;
};

/** El enlace tocado (el `<a href>` más cercano al punto del toque), o `null` si no se tocó un enlace. */
export type SidebarTapLink = {
    target: string;
    download: boolean;
    origin: string;
} | null;

/**
 * Indica si un toque dentro del menú lateral del celular navega en esta misma pestaña de la app y, por lo tanto, debe
 * cerrar el panel. No lo cierran: tocar algo que no es un enlace, abrir en otra pestaña o ventana (Ctrl/⌘, Mayús,
 * `target="_blank"`), Alt (descarga en algunos navegadores), una descarga ni un enlace a otro sitio (también
 * `mailto:` y `tel:`, cuyo origen no es el de la página). El clic medio no llega como `click`: no hace falta mirarlo.
 */
export function closesMobileSidebar(
    tap: SidebarTap,
    link: SidebarTapLink,
    pageOrigin: string,
): boolean {
    if (link === null) {
        return false;
    }

    if (tap.altKey || tap.ctrlKey || tap.metaKey || tap.shiftKey) {
        return false;
    }

    if (link.target !== '' && link.target !== '_self') {
        return false;
    }

    return !link.download && link.origin === pageOrigin;
}
