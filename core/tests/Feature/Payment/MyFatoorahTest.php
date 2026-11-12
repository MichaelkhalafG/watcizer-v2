<?php

use App\Domain\Payment\CallbackPolicy;
use App\Domain\Payment\MyFatoorahProvider;
use App\Domain\Payment\PaymentIntent;
use App\Domain\Payment\ProviderCredentials;
use App\Domain\Payment\ProviderRegistry;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * MyFatoorah for Brand Fashion (Block B, 2026-11-12): the hosted payment page. MyFatoorah's API is faked here
 * (Http::fake) with the shapes its documentation gives — InitiatePayment's PaymentMethods, ExecutePayment's
 * PaymentURL / InvoiceId, GetPaymentStatus's InvoiceStatus / InvoiceValue / CustomerReference / InvoiceTransactions.
 * The same flow ran end to end against MyFatoorah's TEST environment (record OVERNIGHT_20261112, Block B).
 */

const MF_BASE = 'https://apitest.myfatoorah.test';

beforeEach(fn () => config(['services.myfatoorah.base_url' => MF_BASE]));

function mfCredentials(?string $webhookSecret = null): ProviderCredentials
{
    return ProviderCredentials::fromArray(array_filter(['api_key' => 'mf-test-key', 'webhook_secret' => $webhookSecret]));
}

function mfIntent(string $code = 'vm', int $amountMinor = 123450): PaymentIntent
{
    return new PaymentIntent(orderId: 77, orderNumber: 'BF000077', storefrontId: 2, methodId: 5, method: 'card', integrationId: $code,
        amountMinor: $amountMinor, currency: 'EGP', billing: ['first_name' => 'Test', 'last_name' => 'Buyer', 'phone_number' => '+20 100 123 4567', 'email' => 'buyer@example.com'],
        redirectUrl: 'https://api.example.test/api/pay/brandfashion/myfatoorah/callback', notifyUrl: 'https://api.example.test/api/pay/brandfashion/myfatoorah/callback');
}

/** @param array<string, mixed> $status GetPaymentStatus's Data */
function mfFake(array $status = []): void
{
    Http::fake([
        MF_BASE.'/v2/InitiatePayment' => Http::response(['IsSuccess' => true, 'Data' => ['PaymentMethods' => [
            ['PaymentMethodId' => 2, 'PaymentMethodCode' => 'vm', 'PaymentMethodEn' => 'VISA/MASTER', 'CurrencyIso' => 'EGP'],
            ['PaymentMethodId' => 6, 'PaymentMethodCode' => 'md', 'PaymentMethodEn' => 'Meeza', 'CurrencyIso' => 'EGP'],
        ]]]),
        MF_BASE.'/v2/ExecutePayment' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceId' => 5551, 'PaymentURL' => 'https://demo.myfatoorah.test/pay/abc']]),
        MF_BASE.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => $status + [
            'InvoiceId' => 5551, 'InvoiceStatus' => 'Paid', 'InvoiceValue' => 1234.5, 'CustomerReference' => 'BF000077',
            'UserDefinedField' => '{"order_id":77,"storefront_id":2,"method":"vm"}',
            'InvoiceTransactions' => [['PaymentId' => 'PAY-1', 'TransactionStatus' => 'Succss', 'PaymentGateway' => 'VISA/MASTER']],
        ]]),
    ]);
}

it('is registered, needs an API key (and takes a webhook key), and accepts a method CODE as the integration id', function () {
    $p = app(ProviderRegistry::class)->get(MyFatoorahProvider::KEY);
    expect($p)->toBeInstanceOf(MyFatoorahProvider::class)
        ->and($p->credentialFields())->toBe(['api_key', 'webhook_secret'])
        ->and($p->integrationIdProblem('vm'))->toBeNull()
        ->and($p->integrationIdProblem(''))->toBe('missing')
        ->and($p->integrationIdProblem('vm; drop'))->toBe('malformed');
});

it('starts a payment on the method the ACCOUNT offers under that code, and hands back its hosted page', function () {
    mfFake();
    $r = (new MyFatoorahProvider)->initiate(mfIntent(), mfCredentials());

    expect($r->ok)->toBeTrue()
        ->and($r->checkoutUrl)->toBe('https://demo.myfatoorah.test/pay/abc')
        ->and($r->providerReference)->toBe('5551');
    Http::assertSent(fn (ClientRequest $req): bool => $req->url() === MF_BASE.'/v2/ExecutePayment'
        && $req['PaymentMethodId'] === 2 && $req['InvoiceValue'] === 1234.5 && $req['CustomerReference'] === 'BF000077'
        && $req['CallBackUrl'] === mfIntent()->redirectUrl && MyFatoorahProvider::methodCodeOf(T::str($req['UserDefinedField'])) === 'vm' && $req['CustomerMobile'] === '1001234567' && $req['MobileCountryCode'] === '+20'
        && ! isset($req['DisplayCurrencyIso'])
        && $req->hasHeader('Authorization', 'Bearer mf-test-key'));
});

it('refuses to start a payment the account does not offer, without a host, or without a key', function () {
    mfFake();
    expect((new MyFatoorahProvider)->initiate(mfIntent('kn'), mfCredentials())->ok)->toBeFalse();
    Http::assertNotSent(fn (ClientRequest $req): bool => str_ends_with($req->url(), '/ExecutePayment'));

    config(['services.myfatoorah.base_url' => null]);
    expect((new MyFatoorahProvider)->initiate(mfIntent(), mfCredentials())->failureReason)->toContain('MYFATOORAH_BASE_URL');
    expect((new MyFatoorahProvider)->initiate(mfIntent(), ProviderCredentials::fromArray([]))->ok)->toBeFalse();
});

it('takes NOTHING from the return but the payment id: amount, status and reference come from GetPaymentStatus', function () {
    mfFake();
    // a forged return claiming a different amount and order is ignored — only `paymentId` is read
    $v = (new MyFatoorahProvider)->verifyCallback(['paymentId' => 'PAY-1', 'InvoiceValue' => '1', 'CustomerReference' => 'OTHER'], mfCredentials());

    expect($v->signatureValid)->toBeTrue()
        ->and($v->amountMinor)->toBe(123450)
        ->and($v->orderReference)->toBe('BF000077')
        ->and($v->providerTransactionId)->toBe('PAY-1')
        ->and($v->isSuccess)->toBeTrue()
        ->and($v->integrationId)->toBe('vm')                                     // our code, back from the invoice
        ->and($v->outcome)->toBe(CallbackPolicy::OUTCOME_SUCCESS);
    Http::assertSent(fn (ClientRequest $req): bool => str_ends_with($req->url(), '/GetPaymentStatus') && $req['Key'] === 'PAY-1' && $req['KeyType'] === 'PaymentId');

    expect(MyFatoorahProvider::outcome('Pending', 'Failed'))->toBe(CallbackPolicy::OUTCOME_FAILED)
        ->and(MyFatoorahProvider::outcome('Pending', 'InProgress'))->toBe(CallbackPolicy::OUTCOME_PENDING)
        ->and((new MyFatoorahProvider)->verifyCallback([], mfCredentials())->signatureValid)->toBeFalse()
        ->and((new MyFatoorahProvider)->verifyCallback(['paymentId' => 'PAY-1'], ProviderCredentials::fromArray([]))->signatureValid)->toBeFalse();
});

it('refuses a webhook whose signature does not match the contract\'s webhook key, and accepts one that does', function () {
    mfFake();
    $data = ['PaymentId' => 'PAY-1', 'InvoiceId' => 5551, 'CustomerReference' => 'BF000077', 'TransactionStatus' => 'SUCCESS', 'CustomerEmail' => null];
    // MyFatoorah's rule, written out independently: keys sorted case-insensitively, key=value joined by commas
    $string = 'CustomerEmail=,CustomerReference=BF000077,InvoiceId=5551,PaymentId=PAY-1,TransactionStatus=SUCCESS';
    $good = base64_encode(hash_hmac('sha256', $string, 'wh-secret', true));
    $p = new MyFatoorahProvider;

    expect($p->verifyCallback(['Data' => $data, MyFatoorahProvider::HEADER_KEY => $good], mfCredentials('wh-secret'))->signatureValid)->toBeTrue()
        ->and($p->verifyCallback(['Data' => $data, MyFatoorahProvider::HEADER_KEY => 'AAAA'], mfCredentials('wh-secret'))->signatureValid)->toBeFalse()
        ->and($p->verifyCallback(['Data' => $data], mfCredentials('wh-secret'))->signatureValid)->toBeFalse()
        // with no webhook key on the contract the status call alone is the authority
        ->and($p->verifyCallback(['Data' => $data], mfCredentials())->signatureValid)->toBeTrue();
});

it('settles a Brand Fashion order through the callback route: paid once, a replay recorded once, a wrong amount never paid', function () {
    StorefrontPaymentProvider::query()->updateOrCreate(['storefront_id' => 2, 'provider' => MyFatoorahProvider::KEY],
        ['is_enabled' => true, 'credentials' => ['api_key' => 'mf-test-key'], 'settings' => null]);

    $orderId = PaymentFixture::order(1234.50, 2);
    $number = PaymentFixture::orderNumber($orderId);
    mfFake(['CustomerReference' => $number]);
    get('/api/pay/brandfashion/myfatoorah/callback?paymentId=PAY-1');
    $order = T::row(DB::table('orders')->where('id', $orderId)->first(['status', 'paid_via_provider']));
    expect($order->status)->toBe('processing')
        ->and(T::str($order->paid_via_provider))->toBe(MyFatoorahProvider::KEY)
        ->and(DB::table('payment_statuses')->where('order_id', $orderId)->count())->toBe(1);

    // the webhook of the same payment: on record already, nothing more written
    postJson('/api/pay/brandfashion/myfatoorah/callback', ['Event' => 'TransactionsStatusChanged', 'Data' => ['PaymentId' => 'PAY-1']]);
    expect(DB::table('payment_statuses')->where('order_id', $orderId)->count())->toBe(1);
});

it('never pays an order when MyFatoorah reports another amount, or an unpaid invoice', function () {
    StorefrontPaymentProvider::query()->updateOrCreate(['storefront_id' => 2, 'provider' => MyFatoorahProvider::KEY],
        ['is_enabled' => true, 'credentials' => ['api_key' => 'mf-test-key'], 'settings' => null]);
    $orderId = PaymentFixture::order(1234.50, 2);
    mfFake(['CustomerReference' => PaymentFixture::orderNumber($orderId), 'InvoiceValue' => 1000,
        'InvoiceTransactions' => [['PaymentId' => 'PAY-2', 'TransactionStatus' => 'Succss']]]);
    get('/api/pay/brandfashion/myfatoorah/callback?paymentId=PAY-2');
    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending');
});
