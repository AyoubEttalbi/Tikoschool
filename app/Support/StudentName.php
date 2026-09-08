<?php

namespace App\Support;

/**
 * Normalized student-name keys for duplicate detection.
 *
 * ONE definition of "same name": lowercase, accents stripped, every
 * non-letter dropped (spaces included, so "FATIMA EZZAHRA" ≡ "FatimaEzzahra").
 * Deliberately NOT phonetic — names differing by a letter (zaynab vs Zaineb)
 * do not match. A match warns with a confirm dialog; it never blocks.
 */
class StudentName
{
    /**
     * @return array{0: string, 1: string} [firstKey, lastKey]
     */
    public static function keys(string $firstName, string $lastName): array
    {
        return [self::normalize($firstName), self::normalize($lastName)];
    }

    public static function normalize(string $value): string
    {
        // iconv-only on purpose: ext-intl is absent from local PHP, composer.json
        // and the Alpine image, so Transliterator would be dead code here and
        // behave differently per environment. glibc iconv handles French accents
        // deterministically (é→e, ç→c); scripts it cannot transliterate collapse
        // to '' (see duplicates(): empty keys never match).
        $value = mb_strtolower(trim($value), 'UTF-8');
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return (string) preg_replace('/[^a-z]/', '', $transliterated === false ? $value : $transliterated);
    }

    /**
     * Students with the same normalized name in the SAME school, live and
     * trashed alike.
     *
     * School-scoped on purpose: candidates from other schools are unactionable
     * noise, and an assistant must not learn about another school's pupils by
     * guessing names. Empty keys never match (untransliterable scripts would
     * otherwise all collide on '').
     *
     * ONE indexed query (composite index on the key columns). Called once per
     * submit — never per keystroke.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    public static function duplicates(string $firstName, string $lastName, int $schoolId, ?int $exceptId = null)
    {
        [$firstKey, $lastKey] = self::keys($firstName, $lastName);

        if ($firstKey === '' || $lastKey === '') {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return \App\Models\Student::withTrashed()
            ->with(['level', 'class'])
            ->where('schoolId', $schoolId)
            ->where('firstNameKey', $firstKey)
            ->where('lastNameKey', $lastKey)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->orderBy('id')
            ->limit(10)
            ->get();
    }
}
