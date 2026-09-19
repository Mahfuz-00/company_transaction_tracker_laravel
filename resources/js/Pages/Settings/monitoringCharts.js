/**
 * Chart.js option presets for the Platform Monitoring dashboard.
 * Extracted so the (fiddly) nested config lives in one clearly-braced place.
 */

export const lineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false,
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: { color: '#f1f5f9' },
            ticks: { font: { size: 10 } },
        },
        x: {
            grid: { display: false },
            ticks: { font: { size: 10 } },
        },
    },
};

export const barOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'bottom',
            labels: { boxWidth: 10, font: { size: 10 } },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: { color: '#f1f5f9' },
            ticks: { font: { size: 10 } },
        },
        x: {
            grid: { display: false },
            ticks: { font: { size: 10 } },
        },
    },
};

export const doughnutOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '62%',
    plugins: {
        legend: {
            position: 'bottom',
            labels: { boxWidth: 10, font: { size: 10 } },
        },
    },
};

/** Count institutions per health verdict for the doughnut. */
export function healthDoughnut(institutions) {
    const counts = { healthy: 0, at_risk: 0, critical: 0, attention: 0 };

    institutions.forEach((i) => {
        const key = i.health?.key || 'healthy';
        if (counts[key] !== undefined) counts[key] += 1;
    });

    return {
        labels: ['Healthy', 'Attention', 'At risk', 'Critical'],
        datasets: [
            {
                data: [counts.healthy, counts.attention, counts.at_risk, counts.critical],
                backgroundColor: ['#10b981', '#0ea5e9', '#f59e0b', '#f43f5e'],
                borderWidth: 0,
            },
        ],
    };
}
/**
 * Dual-axis line options: a money line on the left axis and a count line on the
 * right. Used by the SaaS analytics revenue/growth chart.
 */
export const dualAxisOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'bottom',
            labels: { boxWidth: 10, font: { size: 10 } },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            position: 'left',
            grid: { color: '#f1f5f9' },
            ticks: { font: { size: 10 } },
        },
        y1: {
            beginAtZero: true,
            position: 'right',
            grid: { display: false },
            ticks: { font: { size: 10 } },
        },
        x: {
            grid: { display: false },
            ticks: { font: { size: 10 } },
        },
    },
};
