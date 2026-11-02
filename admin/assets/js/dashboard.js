/*=========================================================
    RENTRACK LGU
    Dashboard JavaScript
=========================================================*/

// ===============================
// Sidebar Toggle
// ===============================

const menuBtn = document.getElementById("menu-toggle");
const sidebar = document.querySelector(".sidebar");
const main = document.querySelector(".main");

if(menuBtn){

    menuBtn.addEventListener("click",function(){

        if(window.innerWidth <= 768){

            sidebar.classList.toggle("show");

        }else{

            sidebar.classList.toggle("hide");

            main.classList.toggle("expand");

        }

    });

}
// ensure sidebar/main exist before attempting class changes
if (menuBtn && !sidebar) {
    console.warn('Menu button present but .sidebar not found');
}


// ===============================
// Profile Dropdown
// ===============================

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


// ===============================
// Auto Close Sidebar (Mobile)
// ===============================

document.querySelectorAll(".menu a").forEach(function(item){

    item.addEventListener("click",function(){

        if(window.innerWidth <= 768){

            if (sidebar) sidebar.classList.remove("show");

        }

    });

});


// ===============================
// Loader Animation
// ===============================

window.addEventListener("load",function(){

    const loader = document.querySelector(".loader");

    if(loader){

        loader.style.opacity="0";

        setTimeout(function(){

            loader.style.display="none";

        },500);

    }

});


// ===============================
// Active Menu
// ===============================

const current = window.location.pathname.split("/").pop();

document.querySelectorAll(".menu a").forEach(function(link){

    let file = link.getAttribute("href");

    if(file===current){

        link.parentElement.classList.add("active");

    }

});

// mark active menu more safely: compare only filenames and ignore querystring/hash
try {
    const currentFile = (window.location.pathname || '').split('/').pop().split('?')[0].split('#')[0];
    document.querySelectorAll('.menu a').forEach(link => {
        const href = (link.getAttribute('href') || '').split('?')[0].split('#')[0].split('/').pop();
        if (href && href === currentFile) {
            link.parentElement.classList.add('active');
        }
    });
} catch (e) {
    console.warn('Active menu highlight failed', e);
}


// ===============================
// Current Time
// ===============================

function updateClock(){

    const clock=document.getElementById("liveClock");

    if(clock){

        const now=new Date();

        clock.innerHTML=now.toLocaleTimeString();

    }

}

setInterval(updateClock,1000);


// ===============================
// Notification Badge Animation
// ===============================

const badge=document.querySelector(".badge");

if(badge){

    setInterval(function(){

        badge.classList.toggle("pulse");

    },1000);

}


// ===============================
// Counter Animation
// ===============================

document.querySelectorAll(".stat-card h2").forEach(function(counter){

    let target=parseInt(counter.innerText);

    let count=0;

    let speed=Math.ceil(target/40);

    counter.innerText=0;

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

// protect counter animation from non-numeric values
document.querySelectorAll('.stat-card h2').forEach(function(counter){
    const raw = String(counter.textContent || '').replace(/,/g,'').trim();
    const val = parseInt(raw, 10);
    if (isNaN(val) || val <= 0) return;
    let current = 0;
    const step = Math.max(1, Math.floor(val / 40));
    counter.textContent = '0';
    const run = () => {
        current += step;
        if (current >= val) counter.textContent = String(val);
        else { counter.textContent = String(current); requestAnimationFrame(run); }
    };
    run();
});


// ===============================
// Scroll Animation
// ===============================

const observer=new IntersectionObserver(entries=>{

entries.forEach(entry=>{

if(entry.isIntersecting){

entry.target.classList.add("fade-up");

}

});

});

document.querySelectorAll(".card").forEach(card=>{

observer.observe(card);

});


// ===============================
// Welcome Message
// ===============================

console.log("RENTRACK LGU Dashboard Loaded Successfully");