<?php

use App\Domain\Notifications\OrderEmailData;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderFulfilment;
use App\Mail\AdminOrderNotification;
use App\Mail\OrderConfirmation;
use App\Mail\OrderStatusUpdate;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\artisan;

/*
 * The PORT itself: that the templates are the legacy templates, that the data they need is the
 * data core hands them, and that nothing in a rendered order e-mail reaches back to the legacy
 * application.
 *
 * ── Why a contract test and not "it renders without an exception" ────────────────────────────
 *
 * Blade does not fail on a missing variable in these templates — `{{ $cityEn }}` for a key that
 * was never passed is an empty string, not an error. The legacy builder produced thirty keys from
 * Eloquent models; core's produces them from query builders. A key that quietly stopped being
 * produced would show up as a blank line in a customer's receipt and in no test at all, so the
 * key list is asserted, and the RENDERED output is asserted to contain the actual values.
 */

beforeEach(function () {
    config([
        'notifications.admin_emails' => ['ops@watchizer.test'],
        'notifications.send.inline' => true,
        'notifications.send.inside_transaction' => true,
    ]);
});

/**
 * `artisan()` returns `PendingCommand|int`, so the narrowing is explicit rather than a chain the
 * analyser has to guess at -- the same helper shape `EnvParityCheckTest` uses.
 *
 * @param  array<string, mixed>  $args
 */
function contractDrain(string $command, array $args = []): PendingCommand
{
    $pending = artisan($command, $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

/** An order with one line, a city and a note — enough for every panel of all three templates. */
function contractOrder(string $status = 'processing'): int
{
    $row = T::row(
        DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderBy('id')->first(['id'])
    );

    $orderId = PaymentFixture::order(total: 1250.0, status: $status);
    DB::table('orders')->where('id', $orderId)->update([
        'guest_email' => 'shopper@example.test',
        'guest_name' => 'Contract Test',
        'guest_phone' => '01000000000',
        'payment_method' => 'cash',
        'note' => 'اتصل قبل التوصيل',
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => Row::int($row, 'id'), 'offer_id' => null,
        'quantity' => 2, 'piece_price' => '500.00', 'total_price' => '1000.00',
        'type_stock' => 'Express', 'color_dial' => '#111111', 'color_band' => '#C8A45C',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $orderId;
}

// ── the templates are the legacy templates ───────────────────────────────────────────────────

it('carries the three templates and the three partials the legacy app renders', function () {
    foreach ([
        'emails.order-confirmation',
        'emails.admin-order-notification',
        'emails.order-status-update',
        'emails.partials.header',
        'emails.partials.footer',
        'emails.partials.product-row',
    ] as $view) {
        expect(view()->exists($view))->toBeTrue("missing ported view [{$view}]");
    }
});

it('ports them byte for byte, except the one config key core does not have', function () {
    $legacy = base_path('../backend/resources/views/emails');
    $core = resource_path('views/emails');

    // Asserted, not skipped: `backend/` is part of this repository, so an absent directory would
    // mean the comparison silently stopped happening -- which is how a ported template drifts.
    expect(is_dir($legacy))->toBeTrue('the legacy templates must be present to compare against');

    foreach ([
        'order-confirmation.blade.php',
        'admin-order-notification.blade.php',
        'order-status-update.blade.php',
        'partials/header.blade.php',
        'partials/product-row.blade.php',
    ] as $file) {
        // Identical. The copy is not to be "improved": it is live customer-facing wording in two
        // languages, and the brief for this port said so explicitly.
        expect(md5_file($core.'/'.$file))->toBe(md5_file($legacy.'/'.$file), "{$file} has drifted from the legacy original");
    }

    /*
     * `partials/footer.blade.php` differs in exactly ONE line: the copyright fallback reads
     * `config('notifications.brand.copyright')` because core has no `config/watchizer.php`. The
     * difference is asserted to be that and nothing else, by diffing the two files line by line.
     */
    $coreLines = file($core.'/partials/footer.blade.php', FILE_IGNORE_NEW_LINES) ?: [];
    $legacyLines = file($legacy.'/partials/footer.blade.php', FILE_IGNORE_NEW_LINES) ?: [];

    $onlyInCore = array_values(array_diff($coreLines, $legacyLines));
    $onlyInLegacy = array_values(array_diff($legacyLines, $coreLines));

    expect($onlyInLegacy)->toBe(["@php(\$copyright = \$copyright ?? config('watchizer.brand.copyright'))"])
        // The replacement line, plus the comment explaining it. Nothing else may appear here.
        ->and($onlyInCore[0])->toBe("@php(\$copyright = \$copyright ?? config('notifications.brand.copyright'))");
});

it('loads NO asset from the legacy application for its branding', function () {
    $files = glob(resource_path('views/emails').'/{*.blade.php,partials/*.blade.php}', GLOB_BRACE) ?: [];
    expect($files)->toHaveCount(6);

    foreach ($files as $file) {
        $body = (string) file_get_contents($file);

        /*
         * The header and footer render the WATCHIZER wordmark as TEXT, deliberately (the legacy
         * partial's own comment: the dark logo was invisible on the black bar and Gmail strips the
         * CSS filter that would recolour it). So there is no branding <img> to host anywhere —
         * which is what makes "served from core, not the legacy host" true by construction rather
         * than by configuration.
         */
        expect($body)->not->toContain('dash.watchizereg')
            ->and($body)->not->toContain('watchizer.brand.logo')
            // Core has no `config/watchizer.php` at all; a reference to it would render empty.
            ->and($body)->not->toContain("config('watchizer.");
    }
});

// ── the data contract ────────────────────────────────────────────────────────────────────────

it('hands the templates every key they can read', function () {
    $orderId = contractOrder();
    $data = OrderEmailData::for($orderId);

    expect($data)->toBeArray();
    /** @var array<string, mixed> $data */
    expect(array_keys($data))->toEqualCanonicalizing(OrderEmailData::keys());

    // The keys the LEGACY trait produced, minus the two deliberately dropped (`order`, the
    // Eloquent model no template reads, and `logo`, a legacy-host URL no template reads). Listed
    // here rather than derived, so dropping a third one has to be a deliberate edit to this test.
    $legacyKeys = [
        'orderNumber', 'orderId', 'createdAt', 'status', 'statusEn', 'statusAr',
        'customerName', 'customerEmail', 'customerPhone', 'isGuest',
        'addressLine', 'cityEn', 'cityAr',
        'items', 'subtotal', 'shippingCost', 'discount', 'total', 'note',
        'paymentEn', 'paymentAr', 'paymentStatus',
        'brandName', 'copyright', 'whatsappUrl', 'trackUrl', 'dashboardUrl',
    ];
    expect(OrderEmailData::keys())->toEqualCanonicalizing($legacyKeys);
});

it('gives every LINE the keys the product-row partial reads', function () {
    $orderId = contractOrder();
    $data = OrderEmailData::for($orderId);
    expect($data)->toBeArray();

    /** @var array<string, mixed> $data */
    $items = $data['items'];
    expect($items)->toBeArray()->toHaveCount(1);

    /** @var array<string, mixed> $line */
    $line = is_array($items) ? $items[0] : [];
    expect(array_keys($line))->toEqualCanonicalizing([
        'name_en', 'name_ar', 'image', 'qty', 'unit_price', 'line_total',
        'code', 'model', 'type_stock', 'color_band', 'color_dial',
    ])
        ->and($line['qty'])->toBe(2)
        ->and($line['line_total'])->toBe(1000.0);
});

it('returns null for an order that does not exist, instead of a half-built array', function () {
    expect(OrderEmailData::for(0))->toBeNull();
});

// ── the rendered output ──────────────────────────────────────────────────────────────────────

it('renders the customer confirmation with the order’s own numbers', function () {
    $orderId = contractOrder();
    $data = OrderEmailData::for($orderId);
    expect($data)->toBeArray();
    /** @var array<string, mixed> $data */
    $html = (new OrderConfirmation($data))->render();
    $number = PaymentFixture::orderNumber($orderId);

    expect($html)
        ->toContain('#'.$number)
        ->toContain('Order Confirmed')
        ->toContain('تم استلام طلبك')
        // The money, formatted as the template formats it.
        ->toContain('1,250 EGP')
        ->toContain('Cash on Delivery')
        // The note the shopper typed.
        ->toContain('اتصل قبل التوصيل')
        // The footer's support link and the wordmark, both from the ported partials.
        ->toContain('wa.me/')
        ->toContain('WATCH');

    // The tracking link points at the ORDER'S storefront, from core's own table.
    expect($html)->toContain('https://watchizereg.com/order-list');
});

it('renders the admin notification with what an operator triages on', function () {
    $orderId = contractOrder();
    $data = OrderEmailData::for($orderId);
    expect($data)->toBeArray();
    /** @var array<string, mixed> $data */
    $mail = new AdminOrderNotification($data);
    $html = $mail->render();

    expect($html)
        ->toContain('New Order Received')
        ->toContain('Guest')                            // the customer TYPE, which decides the call
        ->toContain('Cash on Delivery (unpaid)')        // the payment state
        ->toContain('1,250 EGP')
        // The dashboard button opens CORE's order screen, not the legacy dashboard's.
        ->toContain('/manage/orders/'.$orderId);

    /*
     * The subject carries the three things the legacy subject carried, in the same order. Read off
     * `build()` rather than `envelope()`: these mailables keep the legacy `build()` shape on
     * purpose, because the port is of working code and not an occasion to modernise it.
     */
    $subject = $mail->build()->subject;
    expect($subject)->toContain('طلب جديد')
        ->and($subject)->toContain(PaymentFixture::orderNumber($orderId))
        ->and($subject)->toContain('1,250 EGP');
});

it('renders each status’s own copy, in both languages', function () {
    $expected = [
        'pending' => ['Pending', 'awaiting confirmation', 'في انتظار التأكيد'],
        'processing' => ['Processing', 'being prepared for delivery', 'جاري تجهيز طلبك'],
        'shipped' => ['Shipped', 'on its way to you', 'طلبك في الطريق إليك'],
        'delivered' => ['Delivered', 'has been delivered', 'تم توصيل طلبك'],
        'completed' => ['Completed', 'is complete', 'تم اكتمال طلبك'],
        'cancelled' => ['Cancelled', 'has been cancelled', 'تم إلغاء طلبك'],
    ];

    // Every status the enum can hold has copy — including the two M1i added, which is WHY the
    // enum was widened rather than mapping shipment onto `completed`.
    expect(array_keys($expected))->toEqualCanonicalizing(OrderFulfilment::STATUSES);

    foreach ($expected as $status => $needles) {
        $orderId = contractOrder($status);
        $data = OrderEmailData::for($orderId);
        expect($data)->toBeArray();
        /** @var array<string, mixed> $data */
        $html = (new OrderStatusUpdate($data))->render();

        foreach ($needles as $needle) {
            expect($html)->toContain($needle);
        }
    }
});

// ── the empty-recipient cases, recorded and not dropped ──────────────────────────────────────

it('records a visible failure when no admin recipient is configured', function () {
    config(['notifications.admin_emails' => []]);
    $orderId = contractOrder('pending');

    app(OrderMailer::class)->placedNow($orderId, notifyCustomer: false);

    /*
     * The legacy `foreach (config('watchizer.admin_emails', []) as $email)` over an empty array
     * is a silent no-op: nobody in the shop hears about the order and nothing says so. That is
     * the exact silence prerequisite (a) exists to close, so core writes a FAILED row naming the
     * missing environment variable.
     */
    $rows = OrderMailer::forOrder($orderId);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('failed')
        ->and($rows[0]['recipient'])->toBeNull()
        ->and($rows[0]['last_error'])->toContain('ORDER_ADMIN_EMAILS');

    // …and `mail:drain --report` is non-zero while it stands, so a cron or a deploy check sees it.
    contractDrain('mail:drain', ['--report' => true, '--order' => $orderId])->assertExitCode(Command::FAILURE);
});

it('records a skip, not a silence, when the order has no customer address', function () {
    $orderId = contractOrder('processing');
    DB::table('orders')->where('id', $orderId)->update(['guest_email' => null]);

    app(OrderMailer::class)->statusChangedNow($orderId, 'shipped');

    $rows = OrderMailer::forOrder($orderId);
    expect($rows)->toHaveCount(1)
        // `skipped`, not `failed`: there was nobody to tell. That is information, not a fault,
        // and the two must not look the same to an operator.
        ->and($rows[0]['status'])->toBe('skipped')
        ->and($rows[0]['last_error'])->toContain('no customer e-mail');

    // A skipped row is not a failure, so the report stays green.
    contractDrain('mail:drain', ['--report' => true, '--order' => $orderId])->assertExitCode(Command::SUCCESS);
});
