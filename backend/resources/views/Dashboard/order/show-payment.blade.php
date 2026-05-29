@if ($payment->paymentStatus)
    <p><strong>{{ trans('order.transaction_number') }} :</strong> {{ $payment->paymentStatus->pay_transaction_id }}</p>
    <p><strong>{{ trans('order.pay_order_number') }} :</strong> {{ $payment->paymentStatus->pay_order_id }}</p>
    <p><strong>{{ trans('order.amount_cents') }} :</strong> {{ $payment->paymentStatus->amount_cents /100 }} {{ trans('mainBtn.pounds') }}</p>
    <p><strong>{{ trans('order.success') }} :</strong>
    @if ($payment->paymentStatus->success === "true")
        {{ trans('order.yes') }} <i style="font-size: 20px;" class="bi bi-check2-circle"></i>
    @else
        {{ trans('order.no') }} <i style="font-size: 20px;" class="bi bi-x-circle"></i>
    @endif
    </p>
@else
    <p>No payment status available for this order</p>
@endif
