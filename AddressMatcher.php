<?php namespace Vanderbilt\CensusExternalModule;

final class AddressMatcher
{
    // ===== Public API =====

    /**
     * Compare two US address strings.
     *
     * Options:
     *  - require_unit (bool) default true: if either has a unit, require unit match for EXACT
     *  - zip_mode ('zip5'|'zip9'|'ignore') default 'zip5'
     *  - require_locality (bool) default false: if true, require city/state (when present) to match for EXACT
     */

    const MATCHRESULT_EXACT = 'EXACT';
    const MATCHRESULT_INEXACT = 'INEXACT';
    const MATCHRESULT_NO_MATCH = 'NO_MATCH';

    public static function compare(string $addr1, string $addr2, array $opts = []): array
    {
        $opts = array_merge([
            'require_unit' => true,
            'zip_mode' => 'zip5',          // zip5 treats 12345 == 12345-6789
            'require_locality' => false,   // if true, city/state mismatches prevent EXACT
        ], $opts);

        $a = self::parse($addr1);
        $b = self::parse($addr2);

        $cmp = self::compareParsed($a, $b, $opts);

        return [
            'result'  => $cmp['result'],
            'reasons' => $cmp['reasons'],
            'a'       => $a,
            'b'       => $b,
        ];
    }

    // ===== Parsing =====

    private static function parse(string $raw): array
    {
        $s = self::normalizeBasic($raw);
        $s = self::canonicalizeTokens($s);

        // Pull out city/state/zip if we can
        $csz = self::extractCityStateZip($s);
        $streetPart = $csz['street_part'];

        // Extract unit
        $unitRes = self::extractUnit($streetPart);
        $streetNoUnit = $unitRes['street_wo_unit'];

        // Normalize ordinals and street structure
        $streetNoUnit = self::normalizeOrdinals($streetNoUnit);

        // PO BOX / RR / etc
        $special = self::extractSpecial($streetNoUnit);

        // Standard street parse: house number + street remainder
        $streetParsed = self::parseStreet($special['street_part']);

        // Canonicalize street remainder (directionals, suffix)
        $streetCanon = self::canonicalStreet($streetParsed['street'] ?? '');

        return [
            'raw' => $raw,
            'norm' => $s,
            'house' => $streetParsed['house'],
            'street' => $streetCanon,
            'unit' => $unitRes['unit'],
            'city' => $csz['city'],
            'state' => $csz['state'],
            'zip' => $csz['zip'],
            'zip5' => self::zip5($csz['zip']),
            'special_type' => $special['type'],   // PO_BOX, RR, HC, NONE
            'special_id' => $special['id'],       // number for PO BOX / RR / etc
        ];
    }

    private static function normalizeBasic(string $s): string
    {
        $s = strtoupper($s);

        // Normalize apostrophe variants
        $s = str_replace(["’","`"], "'", $s);

        // Convert separators to spaces (keep # for unit)
        $s = str_replace(["\r","\n","\t"], " ", $s);
        $s = preg_replace('/[.,;:()\/\\\\]/', ' ', $s);

        // Surround # with space to make tokenization predictable, then collapse later
        $s = preg_replace('/\s*#\s*/', ' #', $s);

        // Collapse whitespace
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private static function canonicalizeTokens(string $s): string
    {
        // Normalize P.O. Box patterns early
        $s = preg_replace('/\bP\s*O\s*BOX\b/', 'PO BOX', $s);
        $s = preg_replace('/\bP\s*O\b/', 'PO', $s);

        // Some people write "APT#4"
        $s = preg_replace('/\b(APT|STE|UNIT|SUITE|APARTMENT)\s*#\s*([A-Z0-9\-]+)\b/', '$1 $2', $s);

        // Replace longer keys first
        $repl = self::tokenMap();
        uksort($repl, fn($a,$b)=>strlen($b)<=>strlen($a));

        foreach ($repl as $from => $to) {
            $s = preg_replace('/\b' . preg_quote($from, '/') . '\b/', $to, $s);
        }

        // Collapse whitespace again
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private static function tokenMap(): array
    {
        return [
            // Directionals
            'NORTHEAST' => 'NE', 'NORTHWEST' => 'NW', 'SOUTHEAST' => 'SE', 'SOUTHWEST' => 'SW',
            'NORTH' => 'N', 'SOUTH' => 'S', 'EAST' => 'E', 'WEST' => 'W',

            // Street suffixes (common USPS)
            'STREET' => 'ST', 'AVENUE' => 'AVE', 'BOULEVARD' => 'BLVD', 'ROAD' => 'RD',
            'DRIVE' => 'DR', 'LANE' => 'LN', 'COURT' => 'CT', 'CIRCLE' => 'CIR',
            'PLACE' => 'PL', 'TERRACE' => 'TER', 'PARKWAY' => 'PKWY', 'HIGHWAY' => 'HWY',
            'FREEWAY' => 'FWY', 'EXPRESSWAY' => 'EXPY', 'TURNPIKE' => 'TPKE',
            'TRAIL' => 'TRL', 'WAY' => 'WAY', 'SQUARE' => 'SQ', 'CENTER' => 'CTR',
            'CRESCENT' => 'CRES', 'COMMONS' => 'CMNS', 'CROSSING' => 'XING',
            'MOUNTAIN' => 'MTN', 'JUNCTION' => 'JCT', 'STATION' => 'STA',
            'EXTENSION' => 'EXT', 'HEIGHTS' => 'HTS', 'HARBOR' => 'HBR',
            'RIDGE' => 'RDG', 'GARDENS' => 'GDNS', 'ESTATES' => 'EST',
            'MEADOWS' => 'MDWS', 'SPRINGS' => 'SPGS',

            // Unit markers
            'APARTMENT' => 'APT', 'SUITE' => 'STE', 'FLOOR' => 'FL', 'UNIT' => 'UNIT',
            'BUILDING' => 'BLDG', 'DEPARTMENT' => 'DEPT', 'ROOM' => 'RM',
            'BASEMENT' => 'BSMT', 'LOWER' => 'LOWR', 'UPPER' => 'UPPR',
            'FRONT' => 'FRNT', 'REAR' => 'REAR',
        ];
    }

    private static function extractCityStateZip(string $s): array
    {
        $out = ['street_part' => $s, 'city' => null, 'state' => null, 'zip' => null];

        // ZIP at end
        if (!preg_match('/\b(\d{5})(?:-(\d{4}))?\b\s*$/', $s, $m)) {
            return $out;
        }

        $zip5 = $m[1];
        $zip4 = $m[2] ?? null;
        $out['zip'] = $zip4 ? ($zip5 . '-' . $zip4) : $zip5;

        $s2 = trim(preg_replace('/\b' . preg_quote($out['zip'], '/') . '\b\s*$/', '', $s));

        // State at end (2 letters, must be valid USPS state/territory)
        if (!preg_match('/\b([A-Z]{2})\b\s*$/', $s2, $m2)) {
            $out['street_part'] = $s; // give up cleanly
            return $out;
        }
        $state = $m2[1];
        if (!self::isValidState($state)) {
            $out['street_part'] = $s; // don't accidentally treat "IN" as Indiana, etc.
            return $out;
        }
        $out['state'] = $state;

        $s3 = trim(preg_replace('/\b' . $state . '\b\s*$/', '', $s2));

        // City: prefer comma form "... , CITY"
        if (preg_match('/,\s*([A-Z0-9 \'-]+)\s*$/', $s3, $m3)) {
            $out['city'] = trim($m3[1]);
            $out['street_part'] = rtrim(trim(substr($s3, 0, -strlen($m3[0]))), ', ');
            return $out;
        }

        // No comma: heuristic—take last 1-3 tokens as city, but avoid swallowing street suffix tokens
        $tokens = preg_split('/\s+/', $s3);
        if (count($tokens) >= 3) {
            // City is commonly 1-2 words; 3 for things like "SAN LUIS OBISPO"
            for ($take = 3; $take >= 1; $take--) {
                if (count($tokens) <= $take) continue;
                $city = implode(' ', array_slice($tokens, -$take));
                $street = implode(' ', array_slice($tokens, 0, -$take));
                // Street should still have digits or suffix-ish tokens; city should be alphabetic-ish
                if (preg_match('/\d/', $street) && preg_match('/[A-Z]/', $city)) {
                    $out['city'] = trim($city);
                    $out['street_part'] = trim($street);
                    return $out;
                }
            }
        }

        $out['street_part'] = $s; // fallback
        return $out;
    }

    private static function extractUnit(string $s): array
    {
        // Canonicalized tokens already (APT/STE/UNIT/RM/BSMT/etc)
        $out = ['street_wo_unit' => $s, 'unit' => null];

        // Common unit patterns anywhere; prefer last occurrence (units often near end)
        $patterns = [
            '/\b(APT|STE|UNIT|RM|FL|BLDG|DEPT)\s*([A-Z0-9][A-Z0-9\-]*)\b/',
            '/\b(BSMT|LOWR|UPPR|FRNT|REAR)\b/',
            '/\s#([A-Z0-9][A-Z0-9\-]*)\b/',
        ];

        $best = null;
        foreach ($patterns as $rx) {
            if (preg_match_all($rx, $s, $m, PREG_OFFSET_CAPTURE)) {
                // take last match
                $idx = count($m[0]) - 1;
                $matchText = $m[0][$idx][0];
                $pos = $m[0][$idx][1];
                $best = ['rx' => $rx, 'match' => $m, 'idx' => $idx, 'text' => $matchText, 'pos' => $pos];
            }
        }

        if (!$best) return $out;

        $rx = $best['rx'];
        $m = $best['match'];
        $i = $best['idx'];

        if ($rx === $patterns[0]) {
            $out['unit'] = $m[1][$i][0] . ' ' . $m[2][$i][0];
            $out['street_wo_unit'] = self::removeOnceAt($s, $best['text'], $best['pos']);
        } elseif ($rx === $patterns[1]) {
            $out['unit'] = $best['text']; // e.g., BSMT
            $out['street_wo_unit'] = self::removeOnceAt($s, $best['text'], $best['pos']);
        } else { // hash
            $out['unit'] = '#' . $m[1][$i][0];
            $out['street_wo_unit'] = self::removeOnceAt($s, $best['text'], $best['pos']);
        }

        $out['street_wo_unit'] = preg_replace('/\s+/', ' ', trim($out['street_wo_unit']));
        return $out;
    }

    private static function removeOnceAt(string $s, string $needle, int $pos): string
    {
        // remove substring at a specific offset safely
        return trim(substr($s, 0, $pos) . ' ' . substr($s, $pos + strlen($needle)));
    }

    private static function extractSpecial(string $s): array
    {
        // PO BOX, RR, HC: treat as “special street”
        // e.g., "PO BOX 123", "RR 2 BOX 10", "HC 1 BOX 22"
        $out = ['type' => 'NONE', 'id' => null, 'street_part' => $s];

        if (preg_match('/\bPO BOX\s+(\d+)\b/', $s, $m)) {
            return ['type' => 'PO_BOX', 'id' => $m[1], 'street_part' => 'PO BOX ' . $m[1]];
        }

        // Rural route
        if (preg_match('/\bRR\s*(\d+)\b.*\bBOX\s*(\d+)\b/', $s, $m)) {
            return ['type' => 'RR', 'id' => $m[1] . '-BOX' . $m[2], 'street_part' => 'RR ' . $m[1] . ' BOX ' . $m[2]];
        }

        // Highway contract
        if (preg_match('/\bHC\s*(\d+)\b.*\bBOX\s*(\d+)\b/', $s, $m)) {
            return ['type' => 'HC', 'id' => $m[1] . '-BOX' . $m[2], 'street_part' => 'HC ' . $m[1] . ' BOX ' . $m[2]];
        }

        return $out;
    }

    private static function parseStreet(string $s): array
    {
        // House number + remainder
        $out = ['house' => null, 'street' => null];

        // Allow: "12", "12B", "12-14", "12 1/2" (normalize 1/2 to HALF)
        $s = preg_replace('/\b(\d+)\s*1\/2\b/', '$1 HALF', $s);

        if (preg_match('/^\s*(\d+[A-Z]?(?:-\d+[A-Z]?)?)\s+(.*)$/', $s, $m)) {
            $out['house'] = $m[1];
            $out['street'] = trim($m[2]);
        } else {
            $out['street'] = trim($s);
        }

        return $out;
    }

    private static function canonicalStreet(string $street): string
    {
        $street = preg_replace('/\s+/', ' ', trim($street));
        if ($street === '') return '';

        // Normalize directionals anywhere: keep at most one pre-dir and one post-dir.
        $tokens = preg_split('/\s+/', $street);

        $dirs = ['N','S','E','W','NE','NW','SE','SW'];

        $preDir = null;
        $postDir = null;

        // If first token is dir, treat as preDir
        if (in_array($tokens[0], $dirs, true)) {
            $preDir = array_shift($tokens);
        }

        // If last token is dir, treat as postDir
        if (count($tokens) > 0 && in_array($tokens[count($tokens)-1], $dirs, true)) {
            $postDir = array_pop($tokens);
        }

        // Also remove stray directionals inside (e.g., "MAIN N ST"): keep them only if no pre/post set
        $clean = [];
        foreach ($tokens as $t) {
            if (in_array($t, $dirs, true)) {
                if ($preDir === null) { $preDir = $t; continue; }
                if ($postDir === null) { $postDir = $t; continue; }
                continue;
            }
            $clean[] = $t;
        }
        $tokens = $clean;

        // Normalize suffix position: if suffix appears not at end, try to move it to end (common in bad inputs)
        $suffixes = array_values(array_unique(array_filter(self::tokenMap(), fn($v)=>in_array($v, [
            'ST','AVE','RD','DR','BLVD','LN','CT','CIR','PL','TER','PKWY','HWY','FWY','EXPY','TPKE','TRL','SQ','CTR','CRES','CMNS','XING','MTN','JCT','STA','EXT','HTS','HBR','RDG','GDNS','EST','MDWS','SPGS'
        ], true))));

        $suffix = null;
        for ($i = 0; $i < count($tokens); $i++) {
            if (in_array($tokens[$i], $suffixes, true)) {
                $suffix = $tokens[$i];
                array_splice($tokens, $i, 1);
                break;
            }
        }
        // If last token now looks like suffix, prefer that
        if (count($tokens) > 0 && in_array($tokens[count($tokens)-1], $suffixes, true)) {
            $suffix = $tokens[count($tokens)-1];
            array_pop($tokens);
        }

        // Rebuild: [preDir] name parts [suffix] [postDir]
        $rebuilt = [];
        if ($preDir) $rebuilt[] = $preDir;
        foreach ($tokens as $t) $rebuilt[] = $t;
        if ($suffix) $rebuilt[] = $suffix;
        if ($postDir) $rebuilt[] = $postDir;

        return trim(preg_replace('/\s+/', ' ', implode(' ', $rebuilt)));
    }

    // ===== Ordinals =====

    private static function normalizeOrdinals(string $s): string
    {
    
        // 1) Normalize "1 ST" -> "1ST", "22 ND" -> "22ND"
        $s = preg_replace('/\b(\d+)\s+(ST|ND|RD|TH)\b/', '$1$2', $s);

        // 2) Normalize word ordinals for common street names (FIRST, SECOND, THIRD...) to numeric ordinals
        // This is best-effort and intentionally limited to avoid false positives.
        $wordMap = [
            'FIRST' => '1ST', 'SECOND' => '2ND', 'THIRD' => '3RD', 'FOURTH' => '4TH', 'FIFTH' => '5TH',
            'SIXTH' => '6TH', 'SEVENTH' => '7TH', 'EIGHTH' => '8TH', 'NINTH' => '9TH', 'TENTH' => '10TH',
            'ELEVENTH' => '11TH', 'TWELFTH' => '12TH', 'THIRTEENTH' => '13TH', 'FOURTEENTH' => '14TH',
            'FIFTEENTH' => '15TH', 'SIXTEENTH' => '16TH', 'SEVENTEENTH' => '17TH', 'EIGHTEENTH' => '18TH',
            'NINETEENTH' => '19TH', 'TWENTIETH' => '20TH',
        ];

        foreach ($wordMap as $from => $to) {
            $s = preg_replace('/\b' . $from . '\b/', $to, $s);
        }

        // 3) Handle "TWENTY FIRST" .. "TWENTY NINTH" -> 21ST..29TH (common)
        $tens = [
            'TWENTY' => 20, 'THIRTY' => 30, 'FORTY' => 40, 'FIFTY' => 50,
            'SIXTY' => 60, 'SEVENTY' => 70, 'EIGHTY' => 80, 'NINETY' => 90,
        ];
        $ones = [
            'FIRST' => [1,'ST'], 'SECOND' => [2,'ND'], 'THIRD' => [3,'RD'], 'FOURTH' => [4,'TH'], 'FIFTH' => [5,'TH'],
            'SIXTH' => [6,'TH'], 'SEVENTH' => [7,'TH'], 'EIGHTH' => [8,'TH'], 'NINTH' => [9,'TH'],
        ];

        foreach ($tens as $tw => $tv) {
            foreach ($ones as $ow => [$ov, $osuf]) {
                $n = $tv + $ov;
                $s = preg_replace('/\b' . $tw . '\s+' . $ow . '\b/', $n . $osuf, $s);
            }
        }

        $s = preg_replace('/\s+/', ' ', trim($s));
        return $s;
    }

    // ===== Comparison =====

    private static function compareParsed(array $a, array $b, array $opts): array
    {
        // Special types: must match exactly (PO BOX vs street is NO_MATCH)
        if (($a['special_type'] ?? 'NONE') !== ($b['special_type'] ?? 'NONE')) {
            return ['result' => 'NO_MATCH', 'reasons' => ['SPECIAL_TYPE_MISMATCH']];
        }
        if (($a['special_type'] ?? 'NONE') !== 'NONE') {
            if (($a['special_id'] ?? null) === ($b['special_id'] ?? null)) {
                // still optionally consider locality / zip
                $reasons = self::compareLocality($a, $b, $opts);
                return ['result' => empty($reasons) ? self::MATCHRESULT_EXACT : self::MATCHRESULT_INEXACT, 'reasons' => $reasons];
            }
            return ['result' => self::MATCHRESULT_NO_MATCH, 'reasons' => ['SPECIAL_ID_MISMATCH']];
        }

        // House + street are the core
        if (($a['house'] ?? '') !== ($b['house'] ?? '')) {
            return ['result' => 'NO_MATCH', 'reasons' => ['HOUSE_MISMATCH']];
        }
        if (($a['street'] ?? '') !== ($b['street'] ?? '')) {
            return ['result' => 'NO_MATCH', 'reasons' => ['STREET_MISMATCH']];
        }

        $reasons = [];

        // Unit handling
        $aHasUnit = !empty($a['unit']);
        $bHasUnit = !empty($b['unit']);
        $unitSame = (!$aHasUnit && !$bHasUnit) || (($a['unit'] ?? '') === ($b['unit'] ?? ''));

        if ($opts['require_unit'] && ($aHasUnit || $bHasUnit) && !$unitSame) {
            // still allow "EXACT_NO_UNIT" if everything else lines up
            $reasons[] = 'UNIT_MISMATCH';
        }

        // Locality/ZIP
        $reasons = array_merge($reasons, self::compareLocality($a, $b, $opts));

        // Decide result
        if (empty($reasons)) {
            return ['result' => self::MATCHRESULT_EXACT, 'reasons' => []];
        }

        // If only unit mismatch, call it EXACT_NO_UNIT
        if (count($reasons) === 1 && $reasons[0] === 'UNIT_MISMATCH') {
            return ['result' => self::MATCHRESULT_EXACT . '_NO_UNIT', 'reasons' => $reasons];
        }

        // If only ZIP mismatch but ZIP5 matches, call it EXACT_ZIP5 (when zip_mode is zip5)
        if (count($reasons) === 1 && $reasons[0] === 'ZIP_MISMATCH' && $opts['zip_mode'] === 'zip5') {
            if (!empty($a['zip5']) && !empty($b['zip5']) && $a['zip5'] === $b['zip5']) {
                return ['result' => self::MATCHRESULT_EXACT . '_ZIP5', 'reasons' => ['ZIP9_DIFFERENT']];
            }
        }

        // If require_locality is false, and only locality mismatches, still call it CLOSE
        return ['result' => self::MATCHRESULT_INEXACT, 'reasons' => $reasons];
    }

    private static function compareLocality(array $a, array $b, array $opts): array
    {
        $reasons = [];

        // City/State: only compare if both present
        foreach (['city','state'] as $k) {
            if (!empty($a[$k]) && !empty($b[$k]) && $a[$k] !== $b[$k]) {
                $reasons[] = strtoupper($k) . '_MISMATCH';
            }
        }

        // ZIP comparison mode
        $zipMode = $opts['zip_mode'];
        if ($zipMode !== 'ignore') {
            if (!empty($a['zip']) && !empty($b['zip'])) {
                if ($zipMode === 'zip9') {
                    if ($a['zip'] !== $b['zip']) $reasons[] = 'ZIP_MISMATCH';
                } else { // zip5
                    if (self::zip5($a['zip']) !== self::zip5($b['zip'])) $reasons[] = 'ZIP_MISMATCH';
                }
            }
        }

        // If locality required, treat missing locality on either side as mismatch (when the other has it)
        if ($opts['require_locality']) {
            foreach (['city','state'] as $k) {
                if (empty($a[$k]) xor empty($b[$k])) {
                    $reasons[] = strtoupper($k) . '_MISSING';
                }
            }
            if ($zipMode !== 'ignore' && (empty($a['zip']) xor empty($b['zip']))) {
                $reasons[] = 'ZIP_MISSING';
            }
        }

        return $reasons;
    }

    // ===== Utilities =====

    private static function zip5(?string $zip): ?string
    {
        if (!$zip) return null;
        if (preg_match('/^(\d{5})/', $zip, $m)) return $m[1];
        return null;
    }

    private static function isValidState(string $st): bool
    {
        static $states = [
            'AL'=>1,'AK'=>1,'AZ'=>1,'AR'=>1,'CA'=>1,'CO'=>1,'CT'=>1,'DE'=>1,'FL'=>1,'GA'=>1,
            'HI'=>1,'ID'=>1,'IL'=>1,'IN'=>1,'IA'=>1,'KS'=>1,'KY'=>1,'LA'=>1,'ME'=>1,'MD'=>1,
            'MA'=>1,'MI'=>1,'MN'=>1,'MS'=>1,'MO'=>1,'MT'=>1,'NE'=>1,'NV'=>1,'NH'=>1,'NJ'=>1,
            'NM'=>1,'NY'=>1,'NC'=>1,'ND'=>1,'OH'=>1,'OK'=>1,'OR'=>1,'PA'=>1,'RI'=>1,'SC'=>1,
            'SD'=>1,'TN'=>1,'TX'=>1,'UT'=>1,'VT'=>1,'VA'=>1,'WA'=>1,'WV'=>1,'WI'=>1,'WY'=>1,
            // Territories/DC
            'DC'=>1,'PR'=>1,'VI'=>1,'GU'=>1,'MP'=>1,'AS'=>1,
        ];
        return isset($states[$st]);
    }
}