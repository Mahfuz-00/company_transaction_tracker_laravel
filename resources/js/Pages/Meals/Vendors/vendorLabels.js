/**
 * Small, shared label formatters for the vendor module.
 *
 * Extracted so the Vendors list page and the Vendor form modal render category
 * and recurrence labels identically, without duplicating the mapping.
 */

/** "meat_fish" -> "Meat Fish" */
export const categoryLabel = (value) =>
    value
        ? value
            .split('_')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ')
        : '';

/** Recurrence key -> human label, with a graceful fallback to the raw value. */
export const recurrenceLabel = (value) =>
({
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Every two weeks',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    on_demand: 'On demand',
}[value] || value || '');
