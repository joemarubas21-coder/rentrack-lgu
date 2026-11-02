/* ==========================================
   RENTRACK LGU
   STAFF DASHBOARD JAVASCRIPT
========================================== */

document.addEventListener("DOMContentLoaded", function () {

    /* =====================================
       SIDEBAR TOGGLE
    ====================================== */

    const menuToggle = document.getElementById("menuToggle");
    const sidebar = document.querySelector(".sidebar");

    if (menuToggle && sidebar) {

        menuToggle.addEventListener("click", function () {

            sidebar.classList.toggle("active");

        });

    }

    /* =====================================
       AUTO CLOSE SIDEBAR (MOBILE)
    ====================================== */

    document.addEventListener("click", function (e) {

        if (window.innerWidth <= 991) {

            if (
                sidebar &&
                !sidebar.contains(e.target) &&
                menuToggle &&
                !menuToggle.contains(e.target)
            ) {

                sidebar.classList.remove("active");

            }

        }

    });

    /* =====================================
       DASHBOARD CARD ANIMATION
    ====================================== */

    const cards = document.querySelectorAll(".dashboard-card");

    cards.forEach(function (card, index) {

        card.style.opacity = "0";
        card.style.transform = "translateY(20px)";

        setTimeout(function () {

            card.style.transition = ".5s ease";
            card.style.opacity = "1";
            card.style.transform = "translateY(0)";

        }, index * 150);

    });

    /* =====================================
       ACTION CARD HOVER
    ====================================== */

    const actions = document.querySelectorAll(".action-card");

    actions.forEach(function (card) {

        card.addEventListener("mouseenter", function () {

            card.style.transform = "translateY(-6px)";

        });

        card.addEventListener("mouseleave", function () {

            card.style.transform = "translateY(0px)";

        });

    });

    /* =====================================
       TABLE ROW HOVER
    ====================================== */

    const rows = document.querySelectorAll(".table tbody tr");

    rows.forEach(function (row) {

        row.addEventListener("mouseenter", function () {

            row.style.transition = ".3s";

        });

    });

    /* =====================================
       LIVE CLOCK
    ====================================== */

    function updateClock() {

        const clock = document.getElementById("currentDate");

        if (!clock) return;

        const now = new Date();

        const options = {
            year: "numeric",
            month: "long",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit"
        };

        clock.innerHTML =
            now.toLocaleString("en-PH", options);

    }

    updateClock();

    setInterval(updateClock, 1000);

    /* =====================================
       NOTIFICATION BADGE EFFECT
    ====================================== */

    const badge = document.querySelector(".notify-badge");

    if (badge) {

        setInterval(function () {

            badge.style.transform = "scale(1.2)";

            setTimeout(function () {

                badge.style.transform = "scale(1)";

            }, 300);

        }, 2500);

    }

    /* =====================================
       PAGE FADE-IN
    ====================================== */

    document.body.style.opacity = "0";

    setTimeout(function () {

        document.body.style.transition = ".4s";
        document.body.style.opacity = "1";

    }, 100);

});