<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * ONE product's history, for the History tab of its form (M1w, 2026-10-30, Michael: "who created this product, who
 * changed its price, who changed its stock, and when").
 *
 * Two sources, merged newest first:
 *  • the ACTIVITY LOG rows of the product and of its variants — created, edited (old → new per field), archived,
 *    price changes (single or bulk), placement — except stock adjustments, which the second source states better;
 *  • the STOCK LEDGER (`inventory_movements`) — every movement of the product's units, by a person OR by the system:
 *    an order taking units, a cancellation giving them back, an import, a rebase. The ledger is the authority for
 *    stock (InventoryService), so the tab reads it rather than the log's copy of the human half.
 *
 * Times are the stored wall-clock times, which are Cairo time (APP_TIMEZONE=Africa/Cairo).
 */
final class ProductHistory
{
    public const LIMIT = 80;

    /**
     * @return list<array{at: string|null, who: string, source: string, action: string, changes: list<array{field: string, from: mixed, to: mixed}>, note: string|null}>
     */
    public static function for(int $productId, int $limit = self::LIMIT): array
    {
        $variantIds = Coerce::intList(DB::table('catalog_product_variants')->where('product_id', $productId)->pluck('id'));
        $system = ManageText::t('activity.system', 'النظام');

        $out = [];
        $log = DB::table(ActivityLog::TABLE)
            ->where(function (Builder $q) use ($productId, $variantIds): void {
                $q->where(fn (Builder $p) => $p->where('subject_type', 'catalog_products')->where('subject_id', $productId));
                if ($variantIds !== []) {
                    $q->orWhere(fn (Builder $v) => $v->where('subject_type', 'catalog_product_variants')->whereIn('subject_id', $variantIds));
                }
            })
            ->where('action', '!=', ActivityLog::ADJUSTED)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get(['user_name', 'action', 'context', 'subject_type', 'subject_label', 'changes', 'created_at']);
        foreach ($log as $raw) {
            $row = Row::cast($raw);
            $changes = [];
            $decoded = json_decode(Row::nstr($row, 'changes') ?? '[]', true);
            foreach (is_array($decoded) ? $decoded : [] as $field => $change) {
                $change = Coerce::arr($change);
                $changes[] = ['field' => (string) $field, 'from' => $change['from'] ?? null, 'to' => $change['to'] ?? null];
            }
            $out[] = [
                'at' => Row::nstr($row, 'created_at'),
                'who' => Row::nstr($row, 'user_name') ?? $system,
                'source' => Row::str($row, 'subject_type') === 'catalog_product_variants' ? 'variant' : 'product',
                'action' => self::actionWord(Row::str($row, 'action'), Row::nstr($row, 'context')),
                'changes' => $changes,
                'note' => Row::nstr($row, 'subject_label'),
            ];
        }

        $movements = DB::table('inventory_movements as m')
            ->leftJoin('users as u', function (JoinClause $j): void {
                $j->on('u.id', '=', 'm.actor_id')->whereIn('m.actor_type', ['user', 'admin']);
            })
            ->leftJoin('orders as o', function (JoinClause $j): void {
                $j->on('o.id', '=', 'm.reference_id')->where('m.reference_type', '=', 'orders');
            })
            ->where('m.product_id', $productId)
            ->orderByDesc('m.created_at')->orderByDesc('m.id')->limit($limit)
            ->get(['m.bucket', 'm.quantity_delta', 'm.quantity_after', 'm.reason', 'm.actor_type', 'm.note', 'm.created_at',
                'u.first_name', 'u.last_name', 'u.email', 'o.order_number']);
        foreach ($movements as $raw) {
            $row = Row::cast($raw);
            $after = Row::int($row, 'quantity_after');
            $delta = Row::int($row, 'quantity_delta');
            $name = trim((Row::nstr($row, 'first_name') ?? '').' '.(Row::nstr($row, 'last_name') ?? ''));
            $order = Row::nstr($row, 'order_number');
            $out[] = [
                'at' => Row::nstr($row, 'created_at'),
                'who' => $name !== '' ? $name : (Row::nstr($row, 'email') ?? $system),
                'source' => 'stock',
                'action' => self::reasonWord(Row::str($row, 'reason')),
                'changes' => [['field' => Row::str($row, 'bucket') === 'market' ? 'stock_market' : 'stock_express',
                    'from' => $after - $delta, 'to' => $after]],
                'note' => $order !== null
                    ? ManageText::t('activity.history_order', 'الطلب :number', ['number' => $order])
                    : Row::nstr($row, 'note'),
            ];
        }

        usort($out, fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($out, 0, $limit);
    }

    private static function actionWord(string $action, ?string $context): string
    {
        $word = match ($action) {
            ActivityLog::CREATED => ManageText::t('activity.action_created', 'إنشاء'),
            ActivityLog::UPDATED => ManageText::t('activity.action_updated', 'تعديل'),
            ActivityLog::DELETED => ManageText::t('activity.action_deleted', 'حذف'),
            ActivityLog::RESTORED => ManageText::t('activity.action_restored', 'استرجاع'),
            default => $action,
        };

        return $context === null ? $word : $word.' · '.ManageText::t('activity.kind_bulk', 'تغيير جماعي');
    }

    private static function reasonWord(string $reason): string
    {
        return match ($reason) {
            'order' => ManageText::t('activity.reason_order', 'طلب'),
            'order_cancel' => ManageText::t('activity.reason_order_cancel', 'إلغاء طلب'),
            'payment_failed' => ManageText::t('activity.reason_payment_failed', 'فشل الدفع'),
            'order_reinstate' => ManageText::t('activity.reason_order_reinstate', 'إعادة حجز بعد دفع متأخر'),
            'reinstate_cancel' => ManageText::t('activity.reason_reinstate_cancel', 'إرجاع طلب أُعيد بعد دفع متأخر'),
            'restock' => ManageText::t('activity.reason_restock', 'توريد'),
            'adjustment', 'manual' => ManageText::t('activity.reason_adjustment', 'تعديل مخزون'),
            'import' => ManageText::t('activity.reason_import', 'استيراد'),
            'transform' => ManageText::t('activity.reason_transform', 'نقل من النظام القديم'),
            default => $reason,
        };
    }
}
