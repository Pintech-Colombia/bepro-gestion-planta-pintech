/**
 * Estilo único de pestañas con línea inferior (DESIGN.md §10): compartido entre `<NavTabs>` (enlaces a
 * páginas distintas) y Radix `<Tabs>` (`components/ui/tabs.tsx`, paneles dentro de una página) para que
 * toda la aplicación use la misma apariencia, solo con tokens.
 */
export const tabBarClasses = 'relative w-full min-w-0 border-b border-border';

export const tabItemClasses =
    'relative inline-flex shrink-0 items-center justify-center gap-1.5 rounded-t-md px-2.5 py-2 text-sm font-medium whitespace-nowrap transition-colors after:pointer-events-none after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:transition-colors focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-inset [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0';

export const tabItemActiveClasses = 'text-foreground after:bg-foreground';

export const tabItemInactiveClasses =
    'text-muted-foreground after:bg-transparent hover:text-foreground';
