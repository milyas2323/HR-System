<?php
include_once __DIR__ . "/../includes/db.php";
include_once __DIR__ . "/../includes/functions.php";

/**
 * UNIFIED DAILY PENALTY AUDITOR ENGINE
 * Shift: ~6 PM–3 AM | 7 hourly slots + end report
 * Absences: PKR 5,000 per weekday (only after first clock-in, not before join)
 * Missed updates: 3 free/month, then PKR 1,000 each
 */

runMonthlyPenaltyAudit($conn);
