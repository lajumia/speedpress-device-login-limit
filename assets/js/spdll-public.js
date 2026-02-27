document.addEventListener("DOMContentLoaded", function() {
    // Set countdown time in seconds (10 minutes)
    var timeLeft = 10 * 60;
    var countdownEl = document.getElementById('spdll-countdown');

    if(!countdownEl) return;

    var timer = setInterval(function() {
        var minutes = Math.floor(timeLeft / 60);
        var seconds = timeLeft % 60;

        // Add leading zero
        if(seconds < 10) seconds = '0' + seconds;

        if(timeLeft > 0){
            countdownEl.innerHTML = '<strong>Time remaining: ' + minutes + ':' + seconds + '</strong>';
        } else {
            countdownEl.innerHTML = '<strong style="color:#d63638;">Expired</strong>';
            clearInterval(timer);
        }

        timeLeft--;
    }, 1000);
});