// Page module: reports
console.log('Page module: reports loaded');

function init() {
    const form = document.getElementById('createReportForm');
    if (!form) return;
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        // minimal client-side validation
        const student = form.querySelector('select[name="student_id"]').value;
        const type = form.querySelector('select[name="report_type"]').value;
        if (!student || !type) return alert('Please select a student and report type');
        // submit via normal form post for now
        form.submit();
    });
}

init();
export default { init };
