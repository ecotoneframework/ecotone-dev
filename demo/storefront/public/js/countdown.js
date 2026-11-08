(function () {
    function remaining(expiresAt) {
        const milliseconds = Date.parse(expiresAt) - Date.now();
        if (Number.isNaN(milliseconds)) {
            return '';
        }
        if (milliseconds <= 0) {
            return 'hold expired';
        }
        const seconds = Math.ceil(milliseconds / 1000);
        return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') + ' left';
    }

    function tick() {
        document.querySelectorAll('.countdown[data-expires-at]').forEach(function (countdown) {
            countdown.textContent = remaining(countdown.dataset.expiresAt);
        });
    }

    window.demoCountdown = { tick: tick };
    tick();
    setInterval(tick, 1000);
})();
