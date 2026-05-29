<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ReportExport implements FromCollection, WithHeadings
{
 protected $orders;

    public function __construct($orders)
    {
        $this->orders = $orders;
    }

    public function collection()
    {
        return $this->orders->map(function ($order) {
            return [
                'Order Number'   => $order->order_number,
                'User Name'      => $order->user->name,
                'Address'        => $order->address->address_line . ' - ' . $order->address->shipping_city->city_name,
                'payment Method' => $order->payment_method,
                'Total Amount'   => $order->total_price_for_order,
                'Status'         => $order->status,
                'Note'           => $order->note,
                'Created At'     => $order->created_at->format('Y-m-d H:i:s'),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Order Number',
            'User Name',
            'Address',
            'payment Method',
            'Total Amount',
            'Status',
            'Note',
            'Created At',
        ];
    }
}
