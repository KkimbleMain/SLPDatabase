// Page module: dashboard
console.log('Page module: dashboard loaded');

function init() {
    // nothing aggressive here; placeholder for custom dashboard interactions
    document.querySelectorAll('.quick-actions a.action-card').forEach(a => {
        a.addEventListener('click', (e) => {
            // allow normal navigation but log
            console.log('quick action', a.getAttribute('href'));
        });
    });
}

init();
export default { init };
