<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Payment\PaymentBadges;
use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The picture of one payment method's badge, on one storefront (the twelve updates, Part 7, 2026-11-05).
 *
 * `PUT /manage/storefronts/{storefront}/payments/badges/{method}` with `image_path` — a file the media
 * endpoint just stored — or null to remove the picture. It changes how a badge LOOKS and nothing else:
 * whether the badge exists is decided by the enabled payment methods ({@see PaymentBadges}).
 *
 * A picture can only be set for a method this storefront OFFERS: the screen lists exactly those, and the
 * server refuses anything else, so the table cannot fill with pictures for methods nobody enabled.
 * Removing is always allowed — a stale picture must be removable after its method is switched off.
 */
final class PaymentBadgeController
{
    public function __construct(private readonly PaymentBadges $badges) {}

    public function update(Request $request, Storefront $storefront, string $method): RedirectResponse
    {
        $data = Coerce::arr($request->validate(['image_path' => ['nullable', 'string', 'max:255']]));
        $path = Coerce::nstr($data['image_path'] ?? null);
        $path = $path === null || trim($path) === '' ? null : trim($path);

        $before = PaymentBadges::pictures($storefront->id)[$method] ?? null;
        if ($path !== null && ! in_array($method, array_column($this->badges->forStorefront($storefront->id), 'method'), true)) {
            throw ValidationException::withMessages(['badge' => ManageText::t(
                'payments.badge_not_offered',
                'هذه الوسيلة غير مفعّلة على هذا المتجر، فلا شارة لها. فعّلها أولًا من القائمة أعلاه.',
            )]);
        }

        // No cache to flush: the storefront endpoint reads the table on every request (it is not under
        // http.cache and not in the storefront cache), so the picture changes within the browser's minute.
        PaymentBadges::set($storefront->id, $method, $path, Coerce::nint($request->user()?->getAuthIdentifier()));

        ActivityLog::record(
            'storefront_payment_badges',
            (int) $storefront->id,
            ActivityLog::UPDATED,
            ['method' => $method, 'image_path' => $before],
            ['method' => $method, 'image_path' => $path],
            label: $method,
            storefrontId: $storefront->id,
        );

        return back()->with('status', $path === null
            ? ManageText::t('payments.badge_removed', 'أُزيلت صورة الشارة: يظهر اسم الوسيلة مكانها.')
            : ManageText::t('payments.badge_saved', 'تم حفظ صورة الشارة.'));
    }
}
