document.querySelectorAll('.tx-analytics-performance-period-form select').forEach((sel) => {
    sel.addEventListener('change', () => sel.form?.submit());
});
