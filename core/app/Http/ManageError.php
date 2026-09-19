<?php

namespace App\Http;

use App\Domain\Access\Role;
use App\Support\ManageText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The dashboard's own dead-end page (D-17, browser walkthrough 2026-09-18).
 *
 * ── What it replaces ────────────────────────────────────────────────────────────────────────
 *
 * `403 | This action is unauthorized.` and `404 | Not Found`, in English, on white, with no
 * chrome and no link anywhere. An Egyptian data-entry operator who mistypes a URL, or clicks a
 * bookmark from before the storefront scope existed, lands there and has nothing to do but press
 * Back — if it occurs to them that the dashboard is not broken.
 *
 * ── What each status has to say ─────────────────────────────────────────────────────────────
 *
 * Not "an error occurred". Each one answers a different question, and the answers are different:
 *
 *  • **403** — you are signed in and this is not yours. The useful sentence names who CAN open it,
 *    because the operator's next act is to ask that person rather than to retry.
 *  • **404** — this address does not exist. The most likely cause on this dashboard by far is a
 *    product URL without its storefront, so that is said out loud rather than hinted at.
 *  • **405** — the address exists but not for that verb. Almost always a stale form or a
 *    double-submit; the honest advice is to start again from the screen.
 *  • **419** — the session expired while the page was open. This one is not an error at all and
 *    must not read like one: nothing was lost that signing in again will not restore.
 *
 * Everything is resolved through `ManageText`, so the page speaks the reader's language — which is
 * half of what was wrong with the pages it replaces.
 */
final class ManageError
{
    public static function render(Request $request, int $status, ?Throwable $exception = null): SymfonyResponse
    {
        return Inertia::render('Manage/Error', [
            'status' => $status,
            'title' => self::title($status),
            'body' => self::reason($exception) ?? self::body($status),
            'who_to_ask' => self::whoToAsk($request, $status),
            // Where "back to the dashboard" goes. A guest cannot reach `/manage`, so they are sent
            // to the one page they CAN open rather than through a redirect they will not
            // understand.
            'home' => $request->user() === null ? route('manage.login') : route('manage.home'),
            'signed_in' => $request->user() !== null,
        ])->toResponse($request)->setStatusCode($status);
    }

    /**
     * The refusal's OWN sentence, when whoever refused wrote one.
     *
     * Several places in this application already say exactly why — `abort(403, 'تنزيل ملف التصدير
     * يحتاج صلاحية مدير.')` is the clearest — and a generic page that talked over them would be a
     * step backwards: a specific reason the operator can act on, replaced by a general one they
     * cannot. So the specific sentence wins wherever it exists, and {@see self::body()} is the
     * fallback for the refusals that never had words.
     *
     * The framework's OWN default messages are excluded by the match below. `This action is
     * unauthorized.` and `Not Found` are not explanations somebody wrote; they are the very
     * strings this page exists to replace, and passing them through would reproduce the defect
     * inside the new chrome.
     */
    private static function reason(?Throwable $exception): ?string
    {
        if (! $exception instanceof HttpExceptionInterface) {
            return null;
        }

        $message = trim($exception->getMessage());
        $frameworkDefaults = [
            '', 'This action is unauthorized.', 'Not Found', 'Forbidden', 'Unauthorized',
            'Method Not Allowed', 'Page Expired', 'The MAC is invalid.',
        ];

        return in_array($message, $frameworkDefaults, true) ? null : $message;
    }

    private static function title(int $status): string
    {
        return match ($status) {
            403 => ManageText::t('errors.forbidden_title', 'هذه الشاشة ليست ضمن صلاحياتك'),
            405 => ManageText::t('errors.method_title', 'هذا الطلب لم يصل بالشكل المتوقع'),
            419 => ManageText::t('errors.expired_title', 'انتهت الجلسة أثناء فتح الصفحة'),
            default => ManageText::t('errors.not_found_title', 'لا يوجد شيء على هذا العنوان'),
        };
    }

    private static function body(int $status): string
    {
        return match ($status) {
            403 => ManageText::t(
                'errors.forbidden_body',
                'الحساب الذي تعمل به لا يملك صلاحية فتح هذه الشاشة. لا شيء تعطّل، ولم يُحذف أي شيء — الصلاحيات تُمنح لكل حساب على حدة.',
            ),
            405 => ManageText::t(
                'errors.method_body',
                'غالبًا صفحة قديمة ما زالت مفتوحة في تبويب آخر، أو ضغطة حفظ تكررت. ارجع إلى الشاشة وابدأ الخطوة من أولها.',
            ),
            419 => ManageText::t(
                'errors.expired_body',
                'مرّ وقت طويل على فتح الصفحة فانتهت الجلسة. سجّل الدخول من جديد وستجد كل شيء كما تركته؛ لم يُفقد شيء محفوظ.',
            ),
            default => ManageText::t(
                'errors.not_found_body',
                'العنوان الذي فُتح غير موجود. أكثر سبب شيوعًا هنا رابط منتجات بدون اسم المتجر: شاشة المنتجات تُفتح من داخل متجر معيّن، فابدأ من لوحة التحكم واختر المتجر.',
            ),
        };
    }

    /**
     * Who can open this, for a 403 — and nothing at all for the other statuses.
     *
     * A 404 has no gatekeeper, so inviting the reader to "ask the administrator" about an address
     * that does not exist would send them to waste somebody else's afternoon. The line is only
     * printed when there is genuinely a person on the other side of it.
     */
    private static function whoToAsk(Request $request, int $status): ?string
    {
        if ($status !== 403) {
            return null;
        }

        // An administrator seeing a 403 is in the RESTRICTED band — an ability no role holds
        // implicitly, such as `media:prune`. Telling them to ask an administrator would be telling
        // them to ask themselves.
        if (Gate::allows(Role::MANAGE_USERS)) {
            return ManageText::t(
                'errors.forbidden_restricted',
                'هذه الشاشة محجوزة عمدًا ولا تُفتح بصلاحية المدير وحدها؛ فتحها يحتاج قرارًا من مطوّر النظام.',
            );
        }

        return ManageText::t(
            'errors.forbidden_ask',
            'لو كنت تحتاج هذه الشاشة فعلًا، اطلبها من مدير اللوحة — هو الوحيد الذي يستطيع إضافتها لحسابك.',
        );
    }
}
