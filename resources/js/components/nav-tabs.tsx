import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { CSSProperties } from 'react';
import { Fragment, useCallback, useEffect, useRef } from 'react';

import { useCurrentUrl } from '@/hooks/use-current-url';
import { useHorizontalOverflow } from '@/hooks/use-horizontal-overflow';
import { scrollLeftToRevealItem } from '@/lib/horizontal-overflow';
import { cn, toUrl } from '@/lib/utils';
import type { NavGroup } from '@/types';

const FADE_WIDTH_PX = 32;
const MIN_STEP_SCROLL_PX = 160;

export type NavTabsProps = {
    groups: NavGroup[];
    /** Nombre accesible de la navegación (`aria-label`), en español. */
    label: string;
    className?: string;
};

/**
 * Pestañas que navegan a páginas distintas (DESIGN.md §10): enlaces de Inertia con `aria-current="page"`
 * dentro de un `<nav>`, nunca Radix `Tabs`. Se desliza en horizontal cuando no cabe, mantiene visible la
 * pestaña activa, desvanece con `mask-image` solo del lado con más contenido y muestra controles `<` / `>`
 * discretos en punteros de precisión (`pointer: fine`).
 */
export function NavTabs({ groups, label, className }: NavTabsProps) {
    const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const scrollRef = useRef<HTMLDivElement | null>(null);
    const {
        ref: overflowRef,
        canScrollLeft,
        canScrollRight,
        measure,
    } = useHorizontalOverflow<HTMLDivElement>();

    const containerRef = useCallback(
        (node: HTMLDivElement | null) => {
            scrollRef.current = node;
            const cleanup = overflowRef(node);

            return () => {
                scrollRef.current = null;
                cleanup?.();
            };
        },
        [overflowRef],
    );

    const scrollByDirection = useCallback((direction: -1 | 1) => {
        const container = scrollRef.current;

        if (!container) {
            return;
        }

        const step = Math.max(
            MIN_STEP_SCROLL_PX,
            Math.round(container.clientWidth * 0.6),
        );

        container.scrollBy({ left: direction * step, behavior: 'smooth' });
    }, []);

    useEffect(() => {
        const container = scrollRef.current;

        if (!container) {
            return;
        }

        const activeTab = container.querySelector<HTMLElement>(
            '[aria-current="page"]',
        );

        if (!activeTab) {
            return;
        }

        const containerRect = container.getBoundingClientRect();
        const itemRect = activeTab.getBoundingClientRect();
        const nextScrollLeft = scrollLeftToRevealItem({
            scrollLeft: container.scrollLeft,
            clientWidth: container.clientWidth,
            scrollWidth: container.scrollWidth,
            itemStart:
                itemRect.left - containerRect.left + container.scrollLeft,
            itemWidth: itemRect.width,
            padding: FADE_WIDTH_PX,
        });

        if (Math.abs(nextScrollLeft - container.scrollLeft) > 0.5) {
            container.scrollLeft = nextScrollLeft;
            measure();
        }
    }, [currentUrl, measure]);

    const visibleGroups = groups.filter((group) => group.items.length > 0);

    if (visibleGroups.length === 0) {
        return null;
    }

    const maskGradient =
        canScrollLeft && canScrollRight
            ? 'linear-gradient(to right, transparent, currentColor 2rem, currentColor calc(100% - 2rem), transparent)'
            : canScrollLeft
              ? 'linear-gradient(to right, transparent, currentColor 2rem, currentColor)'
              : canScrollRight
                ? 'linear-gradient(to right, currentColor, currentColor calc(100% - 2rem), transparent)'
                : undefined;

    const maskStyle: CSSProperties | undefined = maskGradient
        ? { maskImage: maskGradient, WebkitMaskImage: maskGradient }
        : undefined;

    return (
        <nav
            aria-label={label}
            className={cn(
                'relative w-full min-w-0 border-b border-border',
                className,
            )}
        >
            <div
                ref={containerRef}
                style={maskStyle}
                className="relative -mb-px [scrollbar-width:none] overflow-x-auto [&::-webkit-scrollbar]:hidden"
            >
                <ul className="flex w-max items-center gap-1">
                    {visibleGroups.map((group, groupIndex) => (
                        <Fragment key={group.label || `group-${groupIndex}`}>
                            {groupIndex > 0 && (
                                <li
                                    aria-hidden="true"
                                    className="mx-1.5 h-4 w-px shrink-0 bg-border"
                                />
                            )}
                            {group.items.map((item) => {
                                const active = isCurrentOrParentUrl(item.href);

                                return (
                                    <li key={toUrl(item.href)}>
                                        <Link
                                            href={item.href}
                                            prefetch
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            data-touch-target="default"
                                            className={cn(
                                                'relative inline-flex shrink-0 scroll-mx-8 items-center justify-center gap-1.5 rounded-t-md px-2.5 py-2 text-sm font-medium whitespace-nowrap transition-colors after:pointer-events-none after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:transition-colors focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-inset [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
                                                active
                                                    ? 'text-foreground after:bg-foreground'
                                                    : 'text-muted-foreground after:bg-transparent hover:text-foreground',
                                            )}
                                        >
                                            {item.icon && <item.icon />}
                                            {item.title}
                                        </Link>
                                    </li>
                                );
                            })}
                        </Fragment>
                    ))}
                </ul>
            </div>

            {canScrollLeft && (
                <button
                    type="button"
                    tabIndex={-1}
                    aria-hidden="true"
                    onClick={() => scrollByDirection(-1)}
                    className="absolute top-1/2 left-0 z-10 hidden size-7 -translate-y-1/2 items-center justify-center rounded-md border border-border bg-background/90 text-muted-foreground shadow-xs backdrop-blur-xs transition-colors hover:text-foreground pointer-fine:inline-flex"
                >
                    <ChevronLeft className="size-4" />
                </button>
            )}
            {canScrollRight && (
                <button
                    type="button"
                    tabIndex={-1}
                    aria-hidden="true"
                    onClick={() => scrollByDirection(1)}
                    className="absolute top-1/2 right-0 z-10 hidden size-7 -translate-y-1/2 items-center justify-center rounded-md border border-border bg-background/90 text-muted-foreground shadow-xs backdrop-blur-xs transition-colors hover:text-foreground pointer-fine:inline-flex"
                >
                    <ChevronRight className="size-4" />
                </button>
            )}
        </nav>
    );
}
