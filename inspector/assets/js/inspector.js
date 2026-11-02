/*=========================================================
    RENTRACK LGU
    INSPECTOR DASHBOARD JAVASCRIPT
=========================================================*/

// =====================================
// SIDEBAR TOGGLE
// =====================================

const menuBtn = document.getElementById("menu-toggle");
const sidebar = document.querySelector(".sidebar");
const main = document.querySelector(".main");

if(menuBtn){

    menuBtn.addEventListener("click",function(){

        if(window.innerWidth <= 992){

            sidebar.classList.toggle("show");

        }else{

            sidebar.classList.toggle("hide");

            main.classList.toggle("expand");

        }

    });

}

// =====================================
// PROFILE DROPDOWN
// =====================================

const profile = document.querySelector(".profile");
const dropdown = document.querySelector(".profile-dropdown");

if(profile && dropdown){

    profile.addEventListener("click",function(e){

        e.stopPropagation();

        dropdown.classList.toggle("active");

    });

    document.addEventListener("click",function(){

        dropdown.classList.remove("active");

    });

}

// =====================================
// LIVE CLOCK
// =====================================

function updateClock(){

    const clock=document.getElementById("liveClock");

    if(clock){

        const now=new Date();

        clock.innerHTML=now.toLocaleTimeString();

    }

}

updateClock();

setInterval(updateClock,1000);

// =====================================
// ACTIVE SIDEBAR MENU
// =====================================

const currentPage=window.location.pathname.split("/").pop();

document.querySelectorAll(".menu a").forEach(function(link){

    let page=link.getAttribute("href");

    if(page===currentPage){

        link.parentElement.classList.add("active");

    }else{

        link.parentElement.classList.remove("active");

    }

});

// =====================================
// AUTO CLOSE SIDEBAR (MOBILE)
// =====================================

document.querySelectorAll(".menu a").forEach(function(item){

    item.addEventListener("click",function(){

        if(window.innerWidth<=992){

            sidebar.classList.remove("show");

        }

    });

});

// =====================================
// DASHBOARD COUNTER ANIMATION
// =====================================

document.querySelectorAll(".stat-card h2").forEach(function(counter){

    let target=parseInt(counter.innerText);

    if(isNaN(target)) return;

    let count=0;

    let speed=Math.ceil(target/40);

    counter.innerText="0";

    function animate(){

        count+=speed;

        if(count>=target){

            counter.innerText=target;

        }else{

            counter.innerText=count;

            requestAnimationFrame(animate);

        }

    }

    animate();

});

// =====================================
// FADE-UP EFFECT
// =====================================

const observer=new IntersectionObserver(function(entries){

    entries.forEach(function(entry){

        if(entry.isIntersecting){

            entry.target.classList.add("fade-up");

        }

    });

});

document.querySelectorAll(".card").forEach(function(card){

    observer.observe(card);

});

// =====================================
// NOTIFICATION BADGE ANIMATION
// =====================================

const badge=document.querySelector(".badge");

if(badge){

    setInterval(function(){

        badge.classList.toggle("pulse");

    },1000);

}

// =====================================
// SEARCH FILTER
// =====================================

const searchInput=document.querySelector(".search-box input");

if(searchInput){

    searchInput.addEventListener("keyup",function(){

        let keyword=this.value.toLowerCase();

        document.querySelectorAll("table tbody tr").forEach(function(row){

            row.style.display=row.innerText.toLowerCase().includes(keyword)

            ? ""

            : "none";

        });

    });

}

// =====================================
// PAGE LOADER
// =====================================

window.addEventListener("load",function(){

    document.body.classList.add("loaded");

});

// =====================================
// SCROLL TO TOP
// =====================================

const topBtn=document.createElement("button");

topBtn.innerHTML="<i class='fa-solid fa-arrow-up'></i>";

topBtn.id="topButton";

document.body.appendChild(topBtn);

topBtn.style.position="fixed";
topBtn.style.bottom="25px";
topBtn.style.right="25px";
topBtn.style.width="45px";
topBtn.style.height="45px";
topBtn.style.border="none";
topBtn.style.borderRadius="50%";
topBtn.style.background="#ff7a00";
topBtn.style.color="#fff";
topBtn.style.cursor="pointer";
topBtn.style.display="none";
topBtn.style.zIndex="9999";

window.addEventListener("scroll",function(){

    if(window.scrollY>300){

        topBtn.style.display="block";

    }else{

        topBtn.style.display="none";

    }

});

topBtn.addEventListener("click",function(){

    window.scrollTo({

        top:0,

        behavior:"smooth"

    });

});

// =====================================
// CONSOLE MESSAGE
// =====================================

console.log("RENTRACK LGU - INSPECTOR DASHBOARD LOADED SUCCESSFULLY");