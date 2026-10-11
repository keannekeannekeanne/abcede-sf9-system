<?php

/**
 * Pure calculation functions that mirror the verified formulas from the
 * school's original Excel grade sheet. Do NOT change these formulas
 * without re-confirming against the source workbook — see the project
 * README for the confirmed rules and the open questions that shaped them.
 *
 * Confirmed rules (as of the CSNHS/Abcede grade sheet inspection):
 *  - The 8 required learning areas per trimester are: Filipino, English,
 *    Mathematics, Science, Araling Panlipunan, EPP/TLE, MAPEH, GMRC.
 *  - MAPEH is never entered directly; it is
 *        ROUND(AVERAGE(Music and Arts, Physical Education and Health), 0)
 *    (two separate grading subjects, each with its own teacher assignment)
 *  - Subject Average (per trimester) requires ALL 8 areas present
 *    (English included — confirmed required, not optional). If any is
 *    missing, the trimester's averages/rank/descriptor/award are left
 *    blank, exactly like the spreadsheet — grades are never assumed to
 *    be zero and never silently finalized when incomplete.
 *  - General Average (per trimester) = ROUND(Subject Average, 0)
 *  - Rank = 1 + count of students in the same section/trimester with a
 *    strictly higher General Average (ties share a rank; DENSE-style).
 *  - Descriptor = looked up from descriptor_bands (0-64 Emerging,
 *    65-74 Developing, 75-79 Connecting, 80-89 Benchmarking,
 *    90-100 Advancing).
 *  - Academic Excellence Award = General Average >= 90 AND the MINIMUM
 *    of all 8 areas (GMRC included) is >= 80. This follows the Final
 *    Summary sheet's version of the rule, applied consistently at both
 *    the trimester and final-SF9 level (per school confirmation).
 *  - Final Grade per subject (SF9) = ROUND(AVERAGE(term1, term2, term3), 0),
 *    requiring all 3 trimesters present for that subject.
 *  - Final General Average (SF9) = ROUND(AVERAGE(8 final subject
 *    grades), 0), requiring all 8 present.
 *  - A student with status 'nlpa' (No Longer Participating in Learning
 *    Activities) on ANY required subject for a trimester cannot have
 *    that trimester finalized: the trimester is reported as NLPA rather
 *    than computing a number from partial data.
 */
class GradeCalculator
{
    /** The 8 required "top-level" learning areas, in SF9 display order. */
    public const REQUIRED_AREAS = [
        'Filipino', 'English', 'Mathematics', 'Science',
        'Araling Panlipunan', 'EPP/TLE', 'MAPEH', 'GMRC',
    ];

    /** The two MAPEH grading components, in display order. */
    public const MAPEH_COMPONENTS = ['Music and Arts', 'Physical Education and Health'];

    /**
     * MAPEH = (Music and Arts + Physical Education and Health) / 2, rounded.
     * Read-only — never entered directly; the two components are.
     *
     * @return float|null Rounded MAPEH grade, or null if either component is missing.
     */
    public static function mapeh(?float $musicAndArts, ?float $peAndHealth): ?float
    {
        if ($musicAndArts === null || $peAndHealth === null) {
            return null;
        }
        return (float) round(($musicAndArts + $peAndHealth) / 2, 0);
    }

    /**
     * @param array<string,float|null> $areaGrades Keyed by REQUIRED_AREAS names.
     *        MAPEH must already be the computed value (see mapeh()).
     * @return float|null Unrounded subject average, or null if incomplete.
     */
    public static function subjectAverage(array $areaGrades): ?float
    {
        $values = [];
        foreach (self::REQUIRED_AREAS as $area) {
            $v = $areaGrades[$area] ?? null;
            if ($v === null) {
                return null; // incomplete trimester — never assume zero
            }
            $values[] = (float) $v;
        }
        return array_sum($values) / count($values);
    }

    public static function generalAverage(?float $subjectAverage): ?int
    {
        return $subjectAverage === null ? null : (int) round($subjectAverage, 0);
    }

    /**
     * @param int|null $studentAverage
     * @param int[] $allAveragesInGroup All non-null General Averages of the
     *        comparison group (same section+trimester, or same section for
     *        the final SF9 ranking).
     */
    public static function rank(?int $studentAverage, array $allAveragesInGroup): ?int
    {
        if ($studentAverage === null) {
            return null;
        }
        $higherCount = count(array_filter($allAveragesInGroup, fn($g) => $g > $studentAverage));
        return 1 + $higherCount;
    }

    /**
     * @param array<int,array{min_score:float,max_score:float,descriptor:string}> $bands
     *        Typically loaded once from the descriptor_bands table.
     */
    public static function descriptor(?int $generalAverage, array $bands): ?string
    {
        if ($generalAverage === null) {
            return null;
        }
        foreach ($bands as $band) {
            if ($generalAverage >= $band['min_score'] && $generalAverage <= $band['max_score']) {
                return $band['descriptor'];
            }
        }
        return null; // out of range — flag rather than guess
    }

    /**
     * @param array<string,float|null> $areaGrades Same 8 areas as subjectAverage().
     */
    public static function academicExcellenceAward(?int $generalAverage, array $areaGrades): bool
    {
        if ($generalAverage === null || $generalAverage < 90) {
            return false;
        }
        foreach (self::REQUIRED_AREAS as $area) {
            $v = $areaGrades[$area] ?? null;
            if ($v === null || $v < 80) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{1:?float,2:?float,3:?float} $termGrades Subject grade per trimester.
     */
    public static function finalSubjectGrade(array $termGrades): ?int
    {
        foreach ([1, 2, 3] as $t) {
            if (($termGrades[$t] ?? null) === null) {
                return null; // all 3 terms required
            }
        }
        $avg = ($termGrades[1] + $termGrades[2] + $termGrades[3]) / 3;
        return (int) round($avg, 0);
    }

    /**
     * @param array<string,int|null> $finalSubjectGrades Keyed by REQUIRED_AREAS.
     */
    public static function finalGeneralAverage(array $finalSubjectGrades): ?int
    {
        $values = [];
        foreach (self::REQUIRED_AREAS as $area) {
            $v = $finalSubjectGrades[$area] ?? null;
            if ($v === null) {
                return null;
            }
            $values[] = $v;
        }
        return (int) round(array_sum($values) / count($values), 0);
    }
}
