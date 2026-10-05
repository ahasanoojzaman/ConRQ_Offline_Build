// ConrQ - shared app JS
document.addEventListener('DOMContentLoaded', function () {
  // Auto-hide flash alerts after 5s
  document.querySelectorAll('.alert-success').forEach(function (el) {
    setTimeout(function () { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(()=>el.remove(), 400); }, 5000);
  });
});

function pingLicenseServer() {
    const appToken = localStorage.getItem('conrq_license_token');
    
    // If no token exists, the user hasn't activated yet.
    if (!appToken) return;

    // Note: In the final ExeOutput build, the hardwareId and appHash 
    // will be pulled dynamically using HEScript bindings. 
    const hardwareId = localStorage.getItem('conrq_hw_id') || 'TEST-HW-ID-001';
    const appHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    fetch('http://license.conrq.krenx.in/telemetry.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            token: appToken, 
            hardware_id: hardwareId, 
            app_hash: appHash 
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'suspended' || data.status === 'expired' || data.status === 'invalid') {
            // Instantly lock the app by redirecting to the gate
            window.location.href = '/app/partials/subscription_gate.php?reason=' + data.status;
        } else if (data.tier) {
            // Save the tier (standard/premium) to unlock specific hybrid features
            localStorage.setItem('conrq_tier', data.tier);
        }
    })
    .catch(err => console.error('Telemetry ping failed', err));
}

// Generate a random interval between 45 and 180 minutes
const randomInterval = Math.floor(Math.random() * (180 - 45 + 1) + 45) * 60000;

// Start the background worker
setInterval(pingLicenseServer, randomInterval);

// Execute an initial check 5 seconds after the app launches
setTimeout(pingLicenseServer, 5000);