<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Notifications\ReEngagement;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AN UPLOADED LIST (2026-11-24): the template's three columns - e-mail, name, optional phone - read from an .xlsx or a
 * .csv, every row checked BEFORE anything is saved. A row with a bad e-mail or a bad phone is an error the screen lists
 * (row number and reason); a repeated address is dropped (counted); an address unsubscribed on this shop is dropped
 * (counted). Saving needs the tick "these people agreed to receive our e-mails", stored with who ticked it and when.
 */
final class ListUpload
{
    public const MAX_ROWS = 5000;

    public const HEADER = ['email', 'name', 'phone'];

    /**
     * @return array{rows: list<array{email: string, name: string|null, phone: string|null}>, errors: list<array{row: int, reason: string}>, duplicates: int, unsubscribed: int, read: int}
     */
    public static function parse(int $storefrontId, string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $lines = match ($ext) {
            'xlsx' => Xlsx::read($path, self::MAX_ROWS + 2),
            'csv', 'txt' => self::csv($path),
            default => throw new RuntimeException('format'),
        };
        // the header row: its own column order (the template's, or any order with these names)
        $columns = ['email' => 0, 'name' => 1, 'phone' => 2];
        $first = array_map(fn (string $c): string => mb_strtolower(trim($c)), $lines[0] ?? []);
        $start = 0;
        if (in_array('email', $first, true) || in_array('e-mail', $first, true) || in_array('البريد الإلكتروني', $first, true)) { // i18n-exempt: the column headers an uploaded sheet may carry, matched, never shown
            foreach ($first as $i => $name) {
                $key = match ($name) {
                    'email', 'e-mail', 'البريد الإلكتروني' => 'email', // i18n-exempt: the column headers an uploaded sheet may carry, matched, never shown
                    'name', 'الاسم' => 'name', // i18n-exempt: the column headers an uploaded sheet may carry, matched, never shown
                    'phone', 'mobile', 'الهاتف', 'الموبايل' => 'phone', // i18n-exempt: the column headers an uploaded sheet may carry, matched, never shown
                    default => null,
                };
                if ($key !== null) {
                    $columns[$key] = $i;
                }
            }
            $start = 1;
        }
        $optout = DB::table('core_marketing_optouts')->where('storefront_id', $storefrontId)->pluck('email')->map(fn ($e): string => mb_strtolower(Coerce::str($e)))->flip()->all();
        $out = ['rows' => [], 'errors' => [], 'duplicates' => 0, 'unsubscribed' => 0, 'read' => 0];
        $seen = [];
        for ($i = $start; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (implode('', $line) === '') {
                continue;                                    // an empty row
            }
            $out['read']++;
            $rowNumber = $i + 1;
            if ($out['read'] > self::MAX_ROWS) {
                $out['errors'][] = ['row' => $rowNumber, 'reason' => 'too_many_rows'];
                break;
            }
            $email = mb_strtolower(trim($line[$columns['email']] ?? ''));
            $name = trim($line[$columns['name']] ?? '');
            $phone = preg_replace('/\D+/', '', $line[$columns['phone']] ?? '') ?? '';
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $out['errors'][] = ['row' => $rowNumber, 'reason' => 'bad_email'];

                continue;
            }
            if ($phone !== '') {
                $phone = preg_replace('/^(?:0020|20)/', '0', $phone) ?? $phone;
                if (strlen($phone) === 10 && $phone[0] === '1') {
                    $phone = '0'.$phone;                     // a leading zero a spreadsheet dropped
                }
                if (preg_match('/^01[0125]\d{8}$/', $phone) !== 1) {
                    $out['errors'][] = ['row' => $rowNumber, 'reason' => 'bad_phone'];

                    continue;
                }
            }
            if (isset($seen[$email])) {
                $out['duplicates']++;

                continue;
            }
            $seen[$email] = true;
            if (isset($optout[$email])) {
                $out['unsubscribed']++;

                continue;
            }
            $out['rows'][] = ['email' => $email, 'name' => $name === '' ? null : mb_substr($name, 0, 120), 'phone' => $phone === '' ? null : $phone];
        }

        return $out;
    }

    /**
     * Save the checked rows as a list of this shop, with the consent tick's author and time.
     *
     * @param  list<array{email: string, name: string|null, phone: string|null}>  $rows
     */
    public static function save(int $storefrontId, string $name, array $rows, int $consentBy): int
    {
        return DB::transaction(function () use ($storefrontId, $name, $rows, $consentBy): int {
            $id = (int) DB::table('core_email_lists')->insertGetId([
                'storefront_id' => $storefrontId, 'name' => mb_substr($name, 0, 120), 'rows' => count($rows),
                'consent_by' => $consentBy, 'consent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('core_email_list_rows')->insertOrIgnore(array_map(fn (array $r): array => ['list_id' => $id] + $r, $chunk));
            }

            return $id;
        });
    }

    /** @return list<list<string>> */
    private static function csv(string $path): array
    {
        $out = [];
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('unreadable');
        }
        $first = true;
        while (($row = fgetcsv($h, 4096, ',', '"', '')) !== false && count($out) <= self::MAX_ROWS + 1) {
            $row = array_map(fn ($v): string => (string) $v, $row);
            if ($first) {
                $row[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);     // Excel's BOM
                $first = false;
            }
            $out[] = $row;
        }
        fclose($h);

        return $out;
    }

    /** Kept on ReEngagement: the one opt-out list every marketing e-mail honours. */
    public static function optedOut(int $storefrontId, string $email): bool
    {
        return ReEngagement::optedOut($storefrontId, $email);
    }
}
