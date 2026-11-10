<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Avisos personales de la campana. Cada usuario ve y marca solo los suyos: sin permiso, como el perfil.
 */
class NotificationController extends Controller
{
    /**
     * Marca leído un aviso y abre el pedido del que habla. Uno ajeno responde 404, igual que uno que no existe.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $userNotification = $request->user()->notifications()->findOrFail($notification);
        $userNotification->markAsRead();

        $salesOrderId = $userNotification->data['sales_order_id'] ?? null;

        return is_int($salesOrderId)
            ? redirect()->route('sales-orders.show', $salesOrderId)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
