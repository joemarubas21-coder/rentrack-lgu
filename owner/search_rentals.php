<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once "../config/db.php";

date_default_timezone_set("Asia/Manila");

/* =========================================================
   OWNER SESSION
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];


/* =========================================================
   NOTIFICATION COUNT (PDO Safe)
========================================================= */

$notif_count = 0;

try {
    $notifStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = :uid
        AND (is_read = 0 OR is_read = FALSE)
    ");
    $notifStmt->execute([':uid' => $user_id]);
    $notif_count_row = $notifStmt->fetch(PDO::FETCH_ASSOC);
    if ($notif_count_row) {
        $notif_count = (int) $notif_count_row['total'];
    }
} catch (PDOException $e) {
    $notif_count = 0;
}


/* =========================================================
   RECENT NOTIFICATIONS (PDO Safe)
========================================================= */

$notifications = [];

try {
    $notifListStmt = $conn->prepare("
        SELECT *
        FROM notifications
        WHERE user_id = :uid
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $notifListStmt->execute([':uid' => $user_id]);
    $notifications = $notifListStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notifications = [];
}

?>


<nav class="top-navbar">

    <!-- =====================================================
         LEFT SIDE
    ====================================================== -->

    <div class="left-nav">

        <button id="menu-toggle" type="button">

            <i class="fa-solid fa-bars"></i>

        </button>


        <div class="navbar-title">

            <h4>Owner Dashboard</h4>

            <small>
                Welcome back,
                <strong>
                    <?php echo htmlspecialchars($_SESSION['fullname'] ?? 'Owner'); ?>
                </strong>
            </small>

        </div>

    </div>



    <!-- =====================================================
         RIGHT SIDE
    ====================================================== -->

    <div class="right-nav">


        <!-- =================================================
             SEARCH
        ================================================== -->

        <div class="search-wrapper">

            <div class="search-box">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    id="ownerSearch"
                    placeholder="Search Rental..."
                    autocomplete="off"
                >

            </div>


            <!-- SEARCH RESULTS -->

            <div
                id="searchResults"
                class="search-results"
            ></div>

        </div>



        <!-- =================================================
             NOTIFICATIONS
        ================================================== -->

        <div class="notification-wrapper">

            <button
                type="button"
                class="notification-btn"
                id="notificationBtn"
            >

                <i class="fa-solid fa-bell"></i>

                <?php if ($notif_count > 0) { ?>

                    <span class="notification-badge">

                        <?php echo $notif_count; ?>

                    </span>

                <?php } ?>

            </button>


            <!-- NOTIFICATION DROPDOWN -->

            <div
                class="notification-dropdown"
                id="notificationDropdown"
            >

                <div class="notification-header">

                    <div>

                        <strong>Notifications</strong>

                        <?php if ($notif_count > 0) { ?>

                            <span class="notification-small-count">

                                <?php echo $notif_count; ?> unread

                            </span>

                        <?php } ?>

                    </div>


                    <?php if ($notif_count > 0) { ?>

                        <button
                            type="button"
                            id="markAllRead"
                            class="mark-read-btn"
                        >

                            Mark all as read

                        </button>

                    <?php } ?>

                </div>


                <div class="notification-list">

                    <?php if (count($notifications) > 0) { ?>


                        <?php foreach ($notifications as $notification) { ?>


                            <?php

                            $isUnread = (
                                isset($notification['is_read']) &&
                                ($notification['is_read'] == 0 || $notification['is_read'] === false)
                            );

                            $notificationId =
                                (int) $notification['id'];

                            $title =
                                htmlspecialchars(
                                    $notification['title'] ?? 'Notification'
                                );

                            $message =
                                htmlspecialchars(
                                    $notification['message'] ?? ''
                                );

                            $created =
                                $notification['created_at'] ?? '';

                            $timeText = '';

                            if (!empty($created)) {

                                $timeText = date(
                                    "M d, Y h:i A",
                                    strtotime($created)
                                );

                            }

                            ?>


                            <div
                                class="notification-item
                                <?php echo $isUnread ? 'unread' : ''; ?>"
                                data-id="<?php echo $notificationId; ?>"
                            >

                                <div class="notification-icon">

                                    <?php

                                    $type =
                                        strtolower(
                                            $notification['notification_type'] ?? ''
                                        );

                                    if (strpos($type, 'inspection') !== false) {

                                        echo '<i class="fa-solid fa-clipboard-check"></i>';

                                    }
                                    elseif (strpos($type, 'payment') !== false) {

                                        echo '<i class="fa-solid fa-money-bill-wave"></i>';

                                    }
                                    elseif (strpos($type, 'rental') !== false) {

                                        echo '<i class="fa-solid fa-building"></i>';

                                    }
                                    elseif (strpos($type, 'document') !== false) {

                                        echo '<i class="fa-solid fa-file"></i>';

                                    }
                                    else {

                                        echo '<i class="fa-solid fa-bell"></i>';

                                    }

                                    ?>

                                </div>


                                <div class="notification-content">

                                    <strong>
                                        <?php echo $title; ?>
                                    </strong>

                                    <p>
                                        <?php echo $message; ?>
                                    </p>

                                    <?php if ($timeText != '') { ?>

                                        <small>
                                            <i class="fa-regular fa-clock"></i>
                                            <?php echo $timeText; ?>
                                        </small>

                                    <?php } ?>

                                </div>


                                <?php if ($isUnread) { ?>

                                    <span class="unread-dot"></span>

                                <?php } ?>

                            </div>


                        <?php } ?>


                    <?php } else { ?>


                        <div class="no-notifications">

                            <i class="fa-regular fa-bell-slash"></i>

                            <strong>No notifications</strong>

                            <span>
                                You're all caught up!
                            </span>

                        </div>


                    <?php } ?>

                </div>


                <div class="notification-footer">

                    <a href="notifications.php">

                        View All Notifications

                        <i class="fa-solid fa-arrow-right"></i>

                    </a>

                </div>

            </div>

        </div>



        <!-- =================================================
             DATE
        ================================================== -->

        <div class="today">

            <i class="fa-solid fa-calendar-days"></i>

            <span>
                <?php echo date("F d, Y"); ?>
            </span>

        </div>



        <!-- =================================================
             LIVE TIME
        ================================================== -->

        <div class="today">

            <i class="fa-solid fa-clock"></i>

            <span id="liveClock"></span>

        </div>



        <!-- =================================================
             PROFILE
        ================================================== -->

        <div
            class="profile"
            id="profileButton"
        >

            <i class="fa-solid fa-circle-user"></i>

            <span>

                <?php
                echo htmlspecialchars($_SESSION['fullname'] ?? 'Owner');
                ?>

            </span>

            <i class="fa-solid fa-chevron-down profile-arrow"></i>

        </div>


    </div>

</nav>



<!-- =========================================================
     PROFILE DROPDOWN
========================================================= -->

<div
    class="profile-dropdown"
    id="profileDropdown"
>

    <a href="profile.php">

        <i class="fa-solid fa-user"></i>

        My Profile

    </a>


    <a href="documents.php">

        <i class="fa-solid fa-folder-open"></i>

        My Documents

    </a>


    <a href="rentals.php">

        <i class="fa-solid fa-building"></i>

        My Rentals

    </a>


    <hr>


    <a href="../logout.php">

        <i class="fa-solid fa-right-from-bracket"></i>

        Logout

    </a>

</div>



<!-- =========================================================
     SEARCH + NOTIFICATION JAVASCRIPT
========================================================= -->

<script>

document.addEventListener("DOMContentLoaded", function () {


    /* =====================================================
       LIVE CLOCK
    ====================================================== */

    function updateClock() {

        const clock =
            document.getElementById("liveClock");

        if (!clock) return;

        const now = new Date();

        let hours = now.getHours();

        let minutes = now.getMinutes();

        let seconds = now.getSeconds();

        let ampm = hours >= 12 ? "PM" : "AM";

        hours = hours % 12;

        hours = hours ? hours : 12;

        minutes =
            minutes < 10 ? "0" + minutes : minutes;

        seconds =
            seconds < 10 ? "0" + seconds : seconds;

        clock.textContent =
            hours + ":" +
            minutes + ":" +
            seconds + " " +
            ampm;

    }


    updateClock();

    setInterval(updateClock, 1000);



    /* =====================================================
       NOTIFICATION DROPDOWN
    ====================================================== */

    const notificationBtn =
        document.getElementById("notificationBtn");

    const notificationDropdown =
        document.getElementById("notificationDropdown");


    if (notificationBtn && notificationDropdown) {

        notificationBtn.addEventListener(
            "click",
            function (event) {

                event.stopPropagation();

                notificationDropdown.classList.toggle(
                    "show"
                );

            }
        );

    }


    /* =====================================================
       CLOSE NOTIFICATION WHEN CLICKING OUTSIDE
    ====================================================== */

    document.addEventListener(
        "click",
        function (event) {

            if (
                notificationDropdown &&
                !notificationDropdown.contains(event.target) &&
                notificationBtn &&
                !notificationBtn.contains(event.target)
            ) {

                notificationDropdown.classList.remove(
                    "show"
                );

            }

        }
    );



    /* =====================================================
       MARK ALL NOTIFICATIONS AS READ
    ====================================================== */

    const markAllRead =
        document.getElementById("markAllRead");


    if (markAllRead) {

        markAllRead.addEventListener(
            "click",
            function () {

                fetch("mark_notifications_read.php", {

                    method: "POST",

                    headers: {
                        "Content-Type":
                            "application/x-www-form-urlencoded"
                    },

                    body: "mark_all=1"

                })

                .then(response => response.text())

                .then(data => {

                    if (data.trim() === "success") {

                        location.reload();

                    }
                    else {

                        alert(
                            "Unable to update notifications."
                        );

                    }

                })

                .catch(error => {

                    console.error(error);

                    alert(
                        "Something went wrong."
                    );

                });

            }
        );

    }



    /* =====================================================
       SEARCH RENTALS
    ====================================================== */

    const searchInput =
        document.getElementById("ownerSearch");

    const searchResults =
        document.getElementById("searchResults");


    if (searchInput && searchResults) {

        let searchTimer;


        searchInput.addEventListener(
            "input",
            function () {

                const keyword =
                    this.value.trim();


                clearTimeout(searchTimer);


                if (keyword.length < 2) {

                    searchResults.innerHTML = "";

                    searchResults.classList.remove(
                        "show"
                    );

                    return;

                }


                searchTimer = setTimeout(
                    function () {

                        fetch(
                            "search_rentals.php?search=" +
                            encodeURIComponent(keyword)
                        )

                        .then(response =>
                            response.json()
                        )

                        .then(data => {

                            searchResults.innerHTML = "";


                            if (
                                data.success &&
                                data.results.length > 0
                            ) {

                                data.results.forEach(
                                    function (rental) {

                                        const item =
                                            document.createElement(
                                                "a"
                                            );

                                        item.className =
                                            "search-result-item";

                                        item.href =
                                            "view_rental.php?id=" +
                                            rental.id;


                                        item.innerHTML =

                                            '<div class="search-result-icon">' +
                                            '<i class="fa-solid fa-building"></i>' +
                                            '</div>' +

                                            '<div class="search-result-info">' +

                                            '<strong>' +
                                            escapeHtml(
                                                rental.rental_name
                                            ) +
                                            '</strong>' +

                                            '<small>' +
                                            escapeHtml(
                                                rental.business_name || ''
                                            ) +
                                            '</small>' +

                                            '<span>' +
                                            escapeHtml(
                                                rental.tracking_no || ''
                                            ) +
                                            '</span>' +

                                            '</div>';


                                        searchResults.appendChild(
                                            item
                                        );

                                    }
                                );

                            }
                            else {

                                searchResults.innerHTML =

                                    '<div class="no-search-results">' +

                                    '<i class="fa-solid fa-magnifying-glass"></i>' +

                                    '<span>No rentals found.</span>' +

                                    '</div>';

                            }


                            searchResults.classList.add(
                                "show"
                            );

                        })

                        .catch(error => {

                            console.error(error);

                            searchResults.innerHTML =

                                '<div class="no-search-results">' +
                                'Unable to search rentals.' +
                                '</div>';

                            searchResults.classList.add(
                                "show"
                            );

                        });

                    },
                    300
                );

            }
        );


        /* CLOSE SEARCH */

        document.addEventListener(
            "click",
            function (event) {

                if (
                    !event.target.closest(
                        ".search-wrapper"
                    )
                ) {

                    searchResults.classList.remove(
                        "show"
                    );

                }

            }
        );

    }



    /* =====================================================
       PROFILE DROPDOWN
    ====================================================== */

    const profileButton =
        document.getElementById("profileButton");

    const profileDropdown =
        document.getElementById("profileDropdown");


    if (profileButton && profileDropdown) {

        profileButton.addEventListener(
            "click",
            function (event) {

                event.stopPropagation();

                profileDropdown.classList.toggle(
                    "show"
                );

            }
        );


        document.addEventListener(
            "click",
            function (event) {

                if (
                    !profileDropdown.contains(event.target) &&
                    !profileButton.contains(event.target)
                ) {

                    profileDropdown.classList.remove(
                        "show"
                    );

                }

            }
        );

    }



    /* =====================================================
       HTML ESCAPE
    ====================================================== */

    function escapeHtml(text) {

        const div =
            document.createElement("div");

        div.textContent =
            text ?? "";

        return div.innerHTML;

    }

});

</script>