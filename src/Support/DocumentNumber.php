<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The next document number of a series — unbroken, per seller (tenant) and year, as accounting
 * practice expects of invoice numbers. Atomic: the sequence row is locked for the increment, so
 * two documents issued at the same moment never share a number (sqlite, without row locks,
 * serializes writes anyway).
 *
 * The format comes from config ('INV-{Y}-{000000}'): {Y} is the year, a run of zeros is the counter
 * padded to that width, {N} the counter unpadded.
 */
final class DocumentNumber
{
    public static function next(string $series, ?string $tenantId, string $format, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $scope = (string) $tenantId;

        try {
            $counter = self::increment($series, $scope, $year);
        } catch (UniqueConstraintViolationException) {
            // Two first documents of a series at once: both found no row and both inserted it. The
            // row exists now — take the next number from it.
            $counter = self::increment($series, $scope, $year);
        }

        return self::format($format, $year, $counter);
    }

    private static function increment(string $series, string $scope, int $year): int
    {
        return DB::transaction(function () use ($series, $scope, $year) {
            $sequence = DB::table('billing_document_sequences')
                ->where(['series' => $series, 'scope' => $scope, 'year' => $year])
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                DB::table('billing_document_sequences')->insert([
                    'series' => $series, 'scope' => $scope, 'year' => $year, 'last' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return 1;
            }

            DB::table('billing_document_sequences')->where('id', $sequence->id)->update(['last' => $sequence->last + 1, 'updated_at' => now()]);

            return $sequence->last + 1;
        });
    }

    public static function format(string $format, int $year, int $counter): string
    {
        $number = preg_replace_callback('/\{(0+)\}/', fn (array $m) => str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT), $format);

        return strtr((string) $number, ['{Y}' => (string) $year, '{N}' => (string) $counter]);
    }
}
