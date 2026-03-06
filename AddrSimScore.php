<?php namespace Vanderbilt\CensusExternalModule;

final class AddrSimScore
{
/**
 * Address similarity score in [0,1] using normalized Levenshtein.
 * - Normalizes case/punctuation/whitespace
 * - Canonicalizes common street words (STREET->ST, etc.)
 * - Optionally strips unit/apartment/suite info (APT 2L, #5B, UNIT 3, etc.)
 *
 * Caveat: unit stripping is heuristic. If it ever removes too much, turn it off or narrow the regex.
 */

    static function normalize_address(string $s, bool $stripUnit = true): string
    {
        $s = strtoupper($s);

        // Replace punctuation with spaces (keep # because it's useful for unit parsing)
        $s = preg_replace('/[.,;:()\[\]{}"\'`]/', ' ', $s);

        // Normalize common words (add to this list as you see patterns)
        $repls = [
            // directions
            '/\bNORTH\b/' => 'N',
            '/\bSOUTH\b/' => 'S',
            '/\bEAST\b/'  => 'E',
            '/\bWEST\b/'  => 'W',

            // street types
            '/\bSTREET\b/'    => 'ST',
            '/\bAVENUE\b/'    => 'AVE',
            '/\bROAD\b/'      => 'RD',
            '/\bDRIVE\b/'     => 'DR',
            '/\bBOULEVARD\b/' => 'BLVD',
            '/\bLANE\b/'      => 'LN',
            '/\bCOURT\b/'     => 'CT',
            '/\bPLACE\b/'     => 'PL',
            '/\bCIRCLE\b/'    => 'CIR',
            '/\bTERRACE\b/'   => 'TER',
            '/\bPARKWAY\b/'   => 'PKWY',

            // state normalization example (optional; keep if your inputs vary)
            // '/\bCONNECTICUT\b/' => 'CT',
        ];
        $s = preg_replace(array_keys($repls), array_values($repls), $s);

        if ($stripUnit) {
            $s = self::strip_unit_designator($s);
        }

        // Collapse whitespace
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * Heuristic removal of unit/apartment/suite parts.
     * Targets common patterns:
     *  - "APT 2L", "APARTMENT 2L", "UNIT 3", "SUITE 200", "STE 5B", "FL 3"
     *  - "# 5B", "#5B"
     *  - "RM 12", "ROOM 12"
     *
     * It tries to remove only the unit token + following identifier(s), not the whole tail of the address.
     */
    static function strip_unit_designator(string $s): string
    {
        // Normalize "APT." etc already handled by punctuation stripping.

        // 1) Remove hash-based units: "#5B" or "# 5B"
        // Replace with nothing, but keep surrounding spacing sane.
        $s = preg_replace('/\s#\s*[A-Z0-9-]+\b/', ' ', $s);

        // 2) Remove explicit unit keywords + their identifier (sometimes 1-2 tokens: "2", "2L", "12-B")
        // Examples: "APT 2L", "UNIT 3", "STE 200", "SUITE 5B", "FL 3", "FLOOR 3", "RM 12"
        //$s = preg_replace('/\b(APT|APARTMENT|UNIT|STE|SUITE|FL|FLOOR|RM|ROOM)\s+[A-Z0-9-]+(\s+[A-Z0-9-]+)?\b/', ' ', $s);
        //$s = preg_replace('/\b(APT|APARTMENT|UNIT|STE|SUITE|FL|FLOOR|RM|ROOM)\s+[A-Z0-9-]+\b/', ' ', $s);

        // Remove: "APT 2L" or "APT 2 L" or "UNIT 12 B" but NOT "APT 2L GLEN ..."
        $s = preg_replace(
            '/\b(APT|APARTMENT|UNIT|STE|SUITE|FL|FLOOR|RM|ROOM)\s+([A-Z0-9-]+)(?:\s+(?:[A-Z]|\d{1,2}|REAR|FRONT|LEFT|RIGHT|LOWER|UPPER))?\b/',
            ' ',
            $s
        );


        // 3) Some people write "BLDG 2" or "BUILDING 2" — optional:
        $s = preg_replace('/\b(BLDG|BUILDING)\s+[A-Z0-9-]+\b/', ' ', $s);

        return $s;
    }

    static function extract_state_zip(string $s): array
    {
        // Look for a state abbreviation followed by a zip code at the end of the string
        if (preg_match('/\b([A-Z]{2})\s+(\d{5}(?:-\d{4})?)\b/', $s, $matches)) {
            return [
                'state' => $matches[1],
                'zip' => $matches[2] 
            ];
        }
        return ['state' => '', 'zip' => ''];
    }

    static function stateAndZipCompareString(string $a, string $b): string
    {
        $compareString = "";    

        $stateZipA = self::extract_state_zip($a);
        $stateZipB = self::extract_state_zip($b);

        if ( $stateZipA['state'] !== $stateZipB['state'] ) {
            $compareString .= "_STATE";
        }

        if ( $stateZipA['zip'] !== $stateZipB['zip'] ) {
            $compareString .= "_ZIP";
        }

        if ( $compareString !== "" ) {

            // strip off the leading underscore
            $compareString = substr($compareString, 1) . "_MISMATCH";
            
        }

        return $compareString;
    }

    /**
     * Match assessment based on Levenshtein distance.
     * Score is in [0,1] where 1 means identical and 0 means completely different.
     * 
     * returns:
     * 
     *  - EXACT_MATCH ( score == 1 )
     *  - GOOD_MATCH ( states and zips match, score >= 0.9 )
     *  - FAIR_MATCH ( states and zips match, score >= 0.75 )  
     *  - POOR_MATCH ( states and zips match, score > 0 )
     *  - NO_MATCH ( score == 0 )
     *  - STATE_MISMATCH ( states do not match, score > 0 )
     *  - ZIP_MISMATCH ( zips do not match, score > 0 )
     *  - STATE_ZIP_MISMATCH ( states and zips do not match, score > 0 )
     */
    static function address_similarity(string $a, string $b, bool $stripUnit = true): array
    {
        $na = self::normalize_address($a, $stripUnit);
        $nb = self::normalize_address($b, $stripUnit);
        $maxLen = max(strlen($na), strlen($nb));

        $retObj = [
            'addressA' => $a,
            'addressB' => $b,
            'normalizedA' => $na,
            'normalizedB' => $nb,
            'maxLength' => $maxLen
        ];

        if ($na === $nb) {
            return array_merge($retObj, ['similarityScore' => 1.0, 'levenshteinDistance' => 0, 'matchResult' => 'EXACT_MATCH']);
        }

        if ($na === '' || $nb === '') return array_merge($retObj, ['similarityScore' => 0.0, 'levenshteinDistance' => 0, 'matchResult' => 'NO_MATCH']);

        // #edits required to transform na into nb (or vice versa)
        $dist = levenshtein($na, $nb);

        // compute a score in [0,1] where 1 means identical and 0 means completely different
        $score = 1.0 - ($dist / $maxLen);

        // Clamp just in case
        if ($score < 0.0) $score = 0.0;
        if ($score > 1.0) $score = 1.0;

        $stateZipMismatch = self::stateAndZipCompareString($na, $nb);

        if ( $stateZipMismatch !== "" ) {
            $matchResult = $stateZipMismatch;
        } else if ($score >= 0.9) {
            $matchResult = 'GOOD_MATCH';
        } else if ($score >= 0.75) {
            $matchResult = 'FAIR_MATCH';
        } else {
            $matchResult = 'POOR_MATCH';
        }

        return array_merge($retObj, [
            'similarityScore' => $score,
            'levenshteinDistance' => $dist,
            'matchResult' => $matchResult
        ]);
    }
}
