<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasTone;

enum SalesOrderStatus: string implements HasTone
{
    // Primero los de la aprobación: es el orden del filtro de estado.
    case PendingApproval = 'pending_approval';
    case OnHold = 'on_hold';
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => __('Por aprobar'),
            self::OnHold => __('Retenido'),
            self::Pending => __('Pendiente'),
            self::InProgress => __('En progreso'),
            self::Ready => __('Lista'),
            self::Delivered => __('Entregada'),
            self::Cancelled => __('Cancelada'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            // Espera la decisión de quien aprueba.
            self::PendingApproval => Tone::Warning,
            // Gris: espera al cliente, nadie de la empresa tiene que hacer nada.
            self::OnHold => Tone::Neutral,
            self::Pending => Tone::Warning,
            self::InProgress => Tone::Info,
            self::Ready => Tone::Success,
            self::Delivered => Tone::Success,
            self::Cancelled => Tone::Danger,
        };
    }

    /**
     * Devuelve los estados a los que se puede transicionar desde el estado actual.
     *
     * «Por aprobar» no tiene transiciones: solo sale aprobándolo o reteniéndolo, con sus acciones. Aprobar un pedido
     * retenido tampoco es una transición (va por su acción); desde «Retenido» solo se cancela si el cliente desiste.
     *
     * @return array<int, self>
     */
    public function nextTransitions(): array
    {
        return match ($this) {
            self::PendingApproval => [],
            self::OnHold => [self::Cancelled],
            self::Pending => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Ready, self::Cancelled],
            self::Ready => [self::Delivered, self::Cancelled],
            self::Delivered => [],
            self::Cancelled => [],
        };
    }
}
